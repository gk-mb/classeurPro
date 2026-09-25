<?php
/**
 * Module de traitement de classeurs Excel.
 * - eclatement : decoupe un classeur selon une colonne cle en plusieurs feuilles.
 * - regroupement : consolide toutes les feuilles d'un classeur en une seule.
 */
declare(strict_types=1);

session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ui_errors.php';
require_once __DIR__ . '/comparison_keys.php';
require_once __DIR__ . '/spreadsheet_layout.php';
if ($sizeError = uiRequestSizeError()) uiErrorPage($sizeError, 'regroupement.php', 413);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function fail(string $message, int $status = 400): never
{
    uiErrorPage($message, 'regroupement.php', $status);
}

function normaliseValue(string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value);
    $value = (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
    $value = mb_strtoupper($value, 'UTF-8');
    return $value;
}

function canonicalHeaderKey(string $value): string
{
    $value = trim((string) $value);
    $value = mb_strtolower((string) iconv('UTF-8', 'UTF-8//TRANSLIT', $value), 'UTF-8');
    $value = preg_replace('/[^a-z0-9]+/i', '', $value);
    return (string) $value;
}

function matchHeaderName(string $expected, string $actual): bool
{
    $expectedKey = canonicalHeaderKey($expected);
    $actualKey = canonicalHeaderKey($actual);

    if ($expectedKey === '' || $actualKey === '') {
        return false;
    }

    if ($actualKey === $expectedKey) {
        return true;
    }

    // Synonymes metier explicites. On evite toute recherche partielle :
    // "Province" ne doit jamais correspondre a la colonne "N°".
    $aliases = [
        'province' => ['province', 'origine', 'provenance'],
        'origine' => ['province', 'origine', 'provenance'],
        'genre' => ['genre', 'sexe'],
        'sexe' => ['genre', 'sexe'],
    ];

    return in_array($actualKey, $aliases[$expectedKey] ?? [], true);
}

function findColumnByHeader(array $headers, string $key): ?int
{
    $needle = trim((string) $key);
    if ($needle === '') {
        return null;
    }

    foreach ($headers as $index => $header) {
        if (matchHeaderName($needle, (string) $header)) {
            return $index;
        }
    }

    if (preg_match('/^\d+$/', $needle)) {
        $index = (int) $needle;
        if (isset($headers[$index])) {
            return $index;
        }
    }

    $lowerHeaders = [];
    foreach ($headers as $index => $header) {
        $lowerHeaders[normaliseValue((string) $header)] = $index;
    }

    $normalizedNeedle = normaliseValue($needle);
    return $lowerHeaders[$normalizedNeedle] ?? null;
}

function makeSafeSheetName($value): string
{
    $value = (string) $value;
    $clean = preg_replace('/[^A-Za-z0-9_\- ]/u', ' ', $value ?? '');
    $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean));
    $clean = $clean === '' ? 'Groupe' : $clean;
    if (mb_strlen($clean, 'UTF-8') > 31) {
        $clean = mb_substr($clean, 0, 31, 'UTF-8');
    }
    return $clean;
}

function ensureUniqueSheetName(Spreadsheet $workbook, $candidate): string
{
    $candidate = (string) $candidate;
    $used = [];
    foreach ($workbook->getWorksheetIterator() as $sheet) {
        $used[normaliseValue($sheet->getTitle())] = true;
    }

    $base = $candidate;
    $suffix = 1;
    while (isset($used[normaliseValue($base)])) {
        $base = $candidate . ' ' . $suffix;
        $suffix++;
    }

    return $base;
}

function findHeaderRow(array $rows): array
{
    foreach ($rows as $index => $row) {
        $hasValue = false;
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                $hasValue = true;
                break;
            }
        }

        if ($hasValue) {
            return [$index, array_values($row)];
        }
    }

    return [0, []];
}

function appendReportSheet(Spreadsheet $workbook, array $reportRows): void
{
    if ($workbook->sheetNameExists('Rapport')) {
        $reportSheet = $workbook->getSheetByName('Rapport');
        $reportSheet->clear();
    } else {
        $reportSheet = $workbook->createSheet();
        $reportSheet->setTitle('Rapport');
    }

    $reportSheet->fromArray([['Type', 'Detail']], null, 'A1');
    $line = 2;
    foreach ($reportRows as $row) {
        $reportSheet->fromArray([$row], null, 'A' . $line);
        $line++;
    }
}

function mergeHeaders(array $primaryHeaders, array $additionalHeaders): array
{
    $result = $primaryHeaders;
    $known = [];
    foreach ($primaryHeaders as $header) {
        $known[normaliseValue((string) $header)] = true;
    }
    foreach ($additionalHeaders as $header) {
        $key = normaliseValue((string) $header);
        if (!isset($known[$key])) {
            $result[] = $header;
            $known[$key] = true;
        }
    }
    return $result;
}

function normaliseRowValues(array $row): array
{
    $values = [];
    foreach ($row as $value) {
        $values[] = $value;
    }
    return $values;
}

function buildMergedRowValues(array $outputHeaders, array $sheetHeaders, array $row): array
{
    $rowValues = normaliseRowValues($row);
    $map = [];
    foreach ($sheetHeaders as $index => $header) {
        $map[normaliseValue((string) $header)] = $index;
    }

    $values = [];
    foreach ($outputHeaders as $header) {
        $headerKey = normaliseValue((string) $header);
        $columnIndex = $map[$headerKey] ?? null;
        $values[] = $columnIndex !== null ? ($rowValues[$columnIndex] ?? '') : '';
    }
    return $values;
}

function validateUpload(string $inputName): array
{
    if (!isset($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
        fail('Le fichier Excel est obligatoire.');
    }

    $file = $_FILES[$inputName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail('Le fichier n a pas pu etre envoye.');
    }

    if (!is_uploaded_file((string) $file['tmp_name'])) {
        fail('Le fichier envoye n est pas valide.');
    }

    if (($file['size'] ?? 0) < 1 || $file['size'] > 25 * 1024 * 1024) {
        fail('Le fichier doit peser entre 1 octet et 25 Mo.');
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls'], true)) {
        fail('Seuls les fichiers XLSX et XLS sont acceptes.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowedMimes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/zip', 'application/x-ole-storage', 'application/CDFV2'];
    if (!in_array($mime, $allowedMimes, true)) {
        fail('Le contenu du fichier ne correspond pas a un classeur Excel autorise.');
    }

    return ['tmp_name' => (string) $file['tmp_name']];
}

/** Place les feuilles de donnees dans l ordre alphabetique de gauche a droite. */
function sortSheetsAlphabetically(Spreadsheet $workbook): void
{
    $sheetNames = $workbook->getSheetNames();
    usort($sheetNames, static fn (string $left, string $right): int => strnatcasecmp($left, $right));

    foreach ($sheetNames as $index => $sheetName) {
        $workbook->setIndexByName($sheetName, $index);
    }
}

function cleanupExpiredExports(string $directory): void
{
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - (7 * 24 * 60 * 60)) @unlink($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Methode non autorisee.', 405);
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) {
    fail('Session expiree. Rechargez la page et recommencez.', 403);
}

$operation = trim((string) ($_POST['operation'] ?? ''));
if (!in_array($operation, ['split', 'merge'], true)) {
    fail('Type de traitement non reconnu.');
}

$upload = validateUpload('excel');

try {
    $sourceWorkbook = IOFactory::load($upload['tmp_name']);
} catch (\Throwable) {
    fail('Le fichier doit etre un classeur Excel valide et lisible.');
}

$resultWorkbook = new Spreadsheet();
$resultWorkbook->removeSheetByIndex(0);
$reportRows = [];

if ($operation === 'split') {
    try { $ignored = comparisonIgnoredItems($_POST['key_ignore'] ?? ''); }
    catch (RuntimeException $error) { fail($error->getMessage()); }
    $splitKey = trim((string) ($_POST['split_key'] ?? ''));
    $splitKey = str_replace(["\xEF\xBB\xBF", "\0"], '', $splitKey);
    if ($splitKey === '') {
        fail('La colonne de repartition est obligatoire.');
    }

    $foundGroup = false;
    // Une meme province peut apparaitre dans plusieurs feuilles source :
    // elle doit alimenter une seule feuille de sortie.
    $groupSheets = [];
    foreach ($sourceWorkbook->getWorksheetIterator() as $sourceSheet) {
        $rows = $sourceSheet->toArray(null, true, true, true);
        if ($rows === []) {
            $reportRows[] = ['Avertissement', 'Feuille vide ignoree : ' . $sourceSheet->getTitle()];
            continue;
        }

        [$headerIndex, $headers] = findHeaderRow($rows);
        if ($headers === []) {
            $reportRows[] = ['Avertissement', 'Aucune ligne d en-tete exploitable dans la feuille : ' . $sourceSheet->getTitle()];
            continue;
        }

        $keyColumnIndex = findColumnByHeader($headers, $splitKey);
        if ($keyColumnIndex === null) {
            $availableHeaders = [];
            foreach ($headers as $header) {
                $availableHeaders[] = (string) $header;
            }
            $reportRows[] = ['Erreur', 'Colonne cle introuvable dans la feuille ' . $sourceSheet->getTitle() . ' : ' . $splitKey . '. En-tetes trouves : ' . implode(' | ', $availableHeaders)];
            continue;
        }

        $groupedRows = []; $groupNames = [];
        foreach (array_slice($rows, $headerIndex + 1, null, true) as $rowNumber => $row) {
            $rowValues = normaliseRowValues($row);
            $cellValue = $rowValues[$keyColumnIndex] ?? '';
            $groupValue = trim((string) $cellValue);
            $keyCell = $sourceSheet->getCell([ $keyColumnIndex + 1, $rowNumber ]);
            $groupKey = comparisonKey(comparisonCellValue($keyCell), $ignored);
            if ($groupKey === '') {
                continue;
            }
            $groupNames[$groupKey] ??= (string) makeSafeSheetName($groupValue);
            $groupedRows[$groupKey][] = array_map(static fn ($value) => (string) $value, $rowValues);
        }

        if ($groupedRows === []) {
            $reportRows[] = ['Avertissement', 'Aucune valeur de colonne trouvee pour ' . $splitKey . ' dans la feuille ' . $sourceSheet->getTitle()];
            continue;
        }

        foreach ($groupedRows as $groupKey => $rowsForGroup) {
            $groupName = $groupNames[$groupKey];
            if (!isset($groupSheets[$groupKey])) {
                $sheetName = ensureUniqueSheetName($resultWorkbook, $groupName);
                $groupSheet = $resultWorkbook->createSheet();
                $groupSheet->setTitle($sheetName);
                $groupSheet->fromArray($headers, null, 'A1');
                $groupSheets[$groupKey] = $groupSheet;
            }

            $groupSheet = $groupSheets[$groupKey];
            $firstDataRow = $groupSheet->getHighestRow() + 1;

            foreach ($rowsForGroup as $index => $row) {
                $groupSheet->fromArray($row, null, 'A' . ($firstDataRow + $index));
            }
            $foundGroup = true;
        }
    }

    if (!$foundGroup) {
        $reportRows[] = ['Erreur', 'Aucune feuille de sortie n a ete creee. Verifiez la colonne cle choisie et les donnees.'];
    }

    // Le Rapport est ajoute apres le tri pour rester a droite des feuilles de provinces.
    sortSheetsAlphabetically($resultWorkbook);
    appendReportSheet($resultWorkbook, $reportRows);
    $exportName = 'eclatement_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
} else {
    $consolidatedSheet = $resultWorkbook->createSheet();
    $consolidatedSheet->setTitle('Consolidee');

    $allHeaders = [];
    foreach ($sourceWorkbook->getWorksheetIterator() as $sourceSheet) {
        $rows = $sourceSheet->toArray(null, true, true, true);
        if ($rows === []) {
            $reportRows[] = ['Avertissement', 'Feuille vide ignoree pendant le regroupement : ' . $sourceSheet->getTitle()];
            continue;
        }
        [$headerIndex, $headers] = findHeaderRow($rows);
        if ($headers === []) {
            $reportRows[] = ['Avertissement', 'Aucune entete exploitable dans la feuille ' . $sourceSheet->getTitle()];
            continue;
        }
        $allHeaders = mergeHeaders($allHeaders, $headers);
    }

    $consolidatedSheet->fromArray($allHeaders, null, 'A1');
    $lineNumber = 2;

    foreach ($sourceWorkbook->getWorksheetIterator() as $sourceSheet) {
        $rows = $sourceSheet->toArray(null, true, true, true);
        if ($rows === []) {
            continue;
        }

        [$headerIndex, $sheetHeaders] = findHeaderRow($rows);
        if ($sheetHeaders === []) {
            continue;
        }

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $row) {
            $values = buildMergedRowValues($allHeaders, $sheetHeaders, $row);
            $consolidatedSheet->fromArray($values, null, 'A' . $lineNumber++);
        }
    }

    appendReportSheet($resultWorkbook, $reportRows);
    $exportName = 'regroupement_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(4)) . '.xlsx';
}

$exportDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'exports';
if (!is_dir($exportDirectory) && !mkdir($exportDirectory, 0750, true) && !is_dir($exportDirectory)) {
    fail('Le dossier des exports ne peut pas etre cree.', 500);
}
cleanupExpiredExports($exportDirectory);

$filePath = $exportDirectory . DIRECTORY_SEPARATOR . $exportName;
try {
    fitSpreadsheetColumns($resultWorkbook);
    (new Xlsx($resultWorkbook))->save($filePath);
} catch (\Throwable) {
    fail('Le fichier de resultat n a pas pu etre cree.', 500);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument/spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $exportName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store, max-age=0');
readfile($filePath);
exit;
