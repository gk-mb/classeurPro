<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/enrichissement_lib.php';
require __DIR__ . '/enrichissement_xlsx.php';
require __DIR__ . '/duplicates_lib.php';
require_once __DIR__ . '/ui_errors.php';
$temporary = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ouvrez le formulaire et choisissez vos fichiers pour commencer.');
    if ($sizeError = uiRequestSizeError()) throw new RuntimeException($sizeError);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('Session expirée. Rechargez la page.');
    session_write_close();
    $load = static function (string $field) {
        $file = $_FILES[$field] ?? [];
        $label = ['origin' => 'Origine', 'extra' => 'Complément', 'excel' => 'Fichier sélectionné'][$field];
        if ($uploadError = uiUploadError($file, $label)) throw new RuntimeException($uploadError);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Le fichier n’a pas été reçu en entier. Choisissez-le à nouveau et réessayez.');
        if ($file['size'] < 1 || $file['size'] > 25 * 1024 * 1024) throw new RuntimeException('Chaque fichier doit peser entre 1 octet et 25 Mo.');
        try { return enrichLoad($file['tmp_name'], $file['name']); }
        catch (RuntimeException $e) { throw new RuntimeException($label . ' : ' . $e->getMessage()); }
        catch (Throwable) { throw new RuntimeException($label . ' : fichier Excel illisible. Ouvrez-le dans Excel, vérifiez qu’il n’est pas protégé par mot de passe, puis enregistrez-le à nouveau au format .xlsx.'); }
    };
    $integer = static function (string $name): int {
        $value = filter_var($_POST[$name] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
        if ($value === false) throw new RuntimeException('Indiquez le numéro de la ligne contenant les noms des colonnes, par exemple 1 ou 2.');
        return $value;
    };
    if (($_POST['action'] ?? '') === 'inspect') {
        $book = $load('excel'); $row = $integer('header'); $sheets = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheets[] = ['name' => $sheet->getTitle(), 'headers' => $row <= $sheet->getHighestDataRow() ? enrichHeaders($sheet, $row) : new stdClass(), 'lastColumn' => PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn())];
        }
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(['sheets' => $sheets], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    if (!in_array($_POST['action'] ?? '', ['merge', 'duplicates'], true)) throw new RuntimeException('Cette demande n’est pas disponible. Rechargez la page.');
    $origin = $load('origin'); $extra = $load('extra'); $options = [];
    foreach (['origin_sheet', 'extra_sheet', 'origin_key', 'extra_key'] as $key) {
        if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Choisissez une feuille et une clé dans chaque fichier.');
        $options[$key] = $_POST[$key];
    }
    $options['origin_header'] = $integer('origin_header'); $options['extra_header'] = $integer('extra_header');
    if (isset($_POST['add_columns'])) {
        if (!is_string($_POST['add_columns'])) throw new RuntimeException('La sélection des colonnes est invalide.');
        $options['add_columns'] = json_decode($_POST['add_columns'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($options['add_columns']) || !array_is_list($options['add_columns'])) throw new RuntimeException('La sélection des colonnes est invalide.');
    }
    elseif (isset($_POST['add_column'])) $options['add_column'] = $_POST['add_column'];
    $options['insert_before'] = $_POST['insert_before'] ?? '';
    $options['key_ignore'] = $_POST['key_ignore'] ?? '';
    $ignored = comparisonIgnoredItems($options['key_ignore']);
    $originDuplicates = duplicateScan($origin, [['name' => $options['origin_sheet'], 'header' => $options['origin_header'], 'key' => $options['origin_key']]], true, $ignored);
    $extraDuplicates = duplicateScan($extra, [['name' => $options['extra_sheet'], 'header' => $options['extra_header'], 'key' => $options['extra_key']]], false, $ignored);
    if ($_POST['action'] === 'duplicates') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(duplicateSummary($originDuplicates, $extraDuplicates), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    $choice = duplicateChoice($_POST, (bool) ($originDuplicates || $extraDuplicates));
    $removed = $choice['mode'] === 'delete' ? duplicateDelete($origin, $originDuplicates) : [];
    $options['duplicate_occurrence'] = $choice['occurrence'];
    $stats = enrichWorkbook($origin, $extra, $options);
    $insertions = [$options['origin_sheet'] => ['before' => $options['insert_before'], 'count' => count($stats['columns'])]];
    if ($choice['mode'] === 'color') duplicateColorOrigin($origin, $originDuplicates, $insertions);
    duplicateReport($origin, $originDuplicates, $extraDuplicates, $choice);
    $temporary = tempnam(sys_get_temp_dir(), 'enrich_');
    if ($temporary === false) throw new RuntimeException('Impossible de créer le résultat.');
    fitSpreadsheetColumns($origin);
    $writer = PhpOffice\PhpSpreadsheet\IOFactory::createWriter($origin, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($temporary);
    if (strtolower(pathinfo($_FILES['origin']['name'], PATHINFO_EXTENSION)) === 'xlsx') {
        enrichPreserveFormulas($_FILES['origin']['tmp_name'], $temporary, $options['origin_sheet'], $options['insert_before'], count($stats['columns']), $insertions, $removed);
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="origine_enrichie_' . date('Y-m-d_H-i-s') . '.xlsx"');
    header('X-Enrich-Matched: ' . $stats['matched']); header('X-Enrich-Missing: ' . $stats['missing']);
    header('Cache-Control: no-store'); header('Content-Length: ' . filesize($temporary)); readfile($temporary);
} catch (Throwable $e) {
    http_response_code(400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $e instanceof RuntimeException ? $e->getMessage() : 'Lecture ou enrichissement impossible. Vérifiez les fichiers et les colonnes sélectionnées.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
