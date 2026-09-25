<?php
/**
 * Produit un rapport HTML telechargeable depuis un Excel, un DOCX ou un PDF.
 * L'analyse est factuelle : inventaire, texte extrait et statistiques de tableaux.
 */
declare(strict_types=1);

session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ui_errors.php';
if ($sizeError = uiRequestSizeError()) uiErrorPage($sizeError, 'rapport.php', 413);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordWriterFactory;
use PhpOffice\PhpWord\PhpWord;
use Dompdf\Dompdf;
use Smalot\PdfParser\Parser;

const REPORT_MAX_FILE_SIZE = 25 * 1024 * 1024;

function reportFail(string $message, int $status = 400): never
{
    uiErrorPage($message, 'rapport.php', $status);
}

/** Echapement unique pour tout texte injecte dans le rapport HTML. */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Verifie l'envoi et retourne les informations minimales utiles au traitement. */
function getReportUpload(): array
{
    if (!isset($_FILES['document']) || !is_array($_FILES['document'])) reportFail('Le fichier est obligatoire.');
    $file = $_FILES['document'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) reportFail('Le fichier n a pas pu etre envoye.');
    if (($file['size'] ?? 0) < 1 || $file['size'] > REPORT_MAX_FILE_SIZE) reportFail('Le fichier doit peser entre 1 octet et 25 Mo.');
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xlsx', 'xls', 'docx', 'pdf'], true)) reportFail('Ce format de fichier n est pas pris en charge.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $allowedMimes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/zip', 'application/x-ole-storage', 'application/CDFV2'];
    if (!in_array($mime, $allowedMimes, true)) reportFail('Le contenu du fichier ne correspond pas a un format autorise.');

    return ['name' => (string) $file['name'], 'extension' => $extension, 'path' => (string) $file['tmp_name']];
}

/** Construit les indicateurs descriptifs de chaque colonne numerique Excel. */
function excelStatistics(array $rows, array $headers): array
{
    $statistics = [];
    foreach (array_keys($headers) as $column) {
        $numbers = [];
        foreach ($rows as $row) {
            $value = $row[$column] ?? null;
            if (is_numeric($value)) $numbers[] = (float) $value;
        }
        if ($numbers !== []) {
            $statistics[] = ['column' => (string) ($headers[$column] ?: $column), 'count' => count($numbers), 'min' => min($numbers), 'max' => max($numbers), 'sum' => array_sum($numbers), 'average' => array_sum($numbers) / count($numbers)];
        }
    }
    return $statistics;
}

/** Cree des graphiques a barres pour les categories (Sexe, Province, etc.). */
function categoricalCharts(array $rows, array $headers): string
{
    $charts = '';

    foreach ($headers as $column => $header) {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '' && !is_numeric($value)) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        arsort($counts);
        // Evite les champs texte libres : un graphique ne reste lisible qu'avec 2 a 10 categories.
        if (count($counts) < 2 || count($counts) > 10) continue;

        $maximum = max($counts);
        $charts .= '<h3>Repartition par ' . e((string) $header) . '</h3><div class="chart">';
        foreach ($counts as $label => $count) {
            $width = max(3, round(($count / $maximum) * 100));
            $charts .= '<div class="bar-row"><span>' . e($label) . '</span><div class="bar"><i style="width:' . $width . '%"></i></div><b>' . $count . '</b></div>';
        }
        $charts .= '</div>';
    }

    return $charts;
}

/** Analyse un classeur et retourne des sections HTML et un apercu textuel. */
function analyseExcel(string $path): array
{
    $workbook = IOFactory::load($path);
    $html = '<h2>Inventaire des tableaux Excel</h2>';
    $excerpt = '';
    $statisticsRows = '';
    $charts = '';
    foreach ($workbook->getWorksheetIterator() as $sheet) {
        $data = $sheet->toArray(null, true, true, true);
        $headers = $data[1] ?? [];
        $rows = array_slice($data, 1, null, true);
        $html .= '<h3>' . e($sheet->getTitle()) . '</h3><p>' . count($rows) . ' ligne(s) de donnees et ' . count($headers) . ' colonne(s).</p>';
        if ($headers !== []) $html .= '<p><strong>Entetes :</strong> ' . e(implode(', ', array_map('strval', $headers))) . '</p>';
        foreach (excelStatistics($rows, $headers) as $stat) {
            $statisticsRows .= '<tr><td>' . e($sheet->getTitle() . ' - ' . $stat['column']) . '</td><td>' . $stat['count'] . '</td><td>' . number_format($stat['sum'], 2, ',', ' ') . '</td><td>' . number_format($stat['average'], 2, ',', ' ') . '</td><td>' . number_format($stat['min'], 2, ',', ' ') . '</td><td>' . number_format($stat['max'], 2, ',', ' ') . '</td></tr>';
        }
        $charts .= categoricalCharts($rows, $headers);
        $excerpt .= implode(' | ', array_map('strval', $headers)) . "\n";
    }
    if ($statisticsRows !== '') {
        $html .= '<h2>Statistiques numeriques</h2><table><thead><tr><th>Colonne</th><th>Valeurs</th><th>Total</th><th>Moyenne</th><th>Minimum</th><th>Maximum</th></tr></thead><tbody>' . $statisticsRows . '</tbody></table>';
    }
    if ($charts !== '') $html .= '<h2>Graphiques de repartition</h2>' . $charts;
    return ['html' => $html, 'excerpt' => $excerpt];
}

/** Extrait texte et nombre de tableaux d'un DOCX (un fichier ZIP XML). */
function analyseDocx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('DOCX illisible.');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('Structure DOCX invalide.');
    $document = new DOMDocument();
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $text = trim(implode(' ', array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query('//w:t')))));
    $tables = $xpath->query('//w:tbl')->length;
    return ['html' => '<h2>Inventaire Word</h2><p>' . str_word_count($text) . ' mot(s) extraits et ' . $tables . ' tableau(x) detecte(s).</p>', 'excerpt' => $text];
}

/** Extrait le texte et le nombre de pages d'un PDF via PDFParser. */
function analysePdf(string $path): array
{
    $pdf = (new Parser())->parseFile($path);
    $pages = $pdf->getPages();
    $text = trim($pdf->getText());
    return ['html' => '<h2>Inventaire PDF</h2><p>' . count($pages) . ' page(s) et ' . str_word_count($text) . ' mot(s) extraits.</p>', 'excerpt' => $text];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reportFail('Methode non autorisee.', 405);
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) reportFail('Session expiree. Rechargez la page.', 403);

$instructions = trim((string) ($_POST['instructions'] ?? ''));
if ($instructions === '' || mb_strlen($instructions, 'UTF-8') > 3000) reportFail('Votre demande doit contenir entre 1 et 3 000 caracteres.');
$upload = getReportUpload();
$outputFormat = (string) ($_POST['output_format'] ?? 'pdf');
if (!in_array($outputFormat, ['pdf', 'docx'], true)) reportFail('Format de rapport non reconnu.');

try {
    $analysis = match ($upload['extension']) {
        'xlsx', 'xls' => analyseExcel($upload['path']),
        'docx' => analyseDocx($upload['path']),
        'pdf' => analysePdf($upload['path']),
    };
} catch (Throwable) {
    reportFail('Le contenu du fichier ne peut pas etre lu. Verifiez son format.');
}

$excerpt = mb_substr(preg_replace('/\s+/', ' ', $analysis['excerpt']), 0, 1000, 'UTF-8');
$sourceType = strtoupper($upload['extension']);
$professionalText = 'Le document analyse est un fichier ' . $sourceType . '. Le perimetre couvre les contenus exploitables automatiquement et les donnees tabulaires detectees. Les constats presentes ci-dessous repondent a la demande formulee, sur la base des informations effectivement disponibles.';
$conclusion = $upload['extension'] === 'xlsx' || $upload['extension'] === 'xls' ? 'Les statistiques et graphiques mettent en evidence les repartitions des categories presentes dans les tableaux. Les resultats doivent etre rapproches du contexte metier fourni avant toute decision.' : 'Les informations extraites constituent une base de lecture structuree. Une verification humaine reste recommandee pour les elements visuels ou les passages complexes.';
$report = '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Rapport professionnel</title><style>body{font:14px/1.55 DejaVu Sans,Arial,sans-serif;color:#172b4d;margin:36px}h1{color:#102a43;border-bottom:3px solid #1f6feb;padding-bottom:10px}h2{margin-top:28px;color:#102a43}h3{margin-top:22px}table{width:auto;max-width:100%;table-layout:auto;border-collapse:collapse;margin:14px 0}th,td{padding:7px;border:1px solid #dce5ef;text-align:left}th{background:#eaf2ff}.box{padding:14px;border-left:4px solid #1f6feb;background:#f5f8fc}.chart{margin:8px 0 18px}.bar-row{display:flex;align-items:center;gap:8px;margin:5px 0}.bar-row span{width:150px}.bar{width:260px;height:14px;background:#e4ebf4}.bar i{display:block;height:14px;background:#1f6feb}.bar-row b{font-size:12px}</style><h1>Rapport d analyse documentaire</h1><p><small>Fichier analyse : ' . e($upload['name']) . ' | Genere le ' . date('d/m/Y H:i') . '</small></p><h2>1. Contexte et objectif</h2><p>' . e($professionalText) . '</p><h2>2. Demande du commanditaire</h2><div class="box">' . nl2br(e($instructions)) . '</div><h2>3. Perimetre et methode</h2><p>Le systeme a extrait le texte disponible, inventorie la structure et calcule les indicateurs quantitatifs lorsque le fichier contient des tableaux.</p><h2>4. Resultats et observations</h2>' . $analysis['html'] . '<h2>5. Apercu du contenu source</h2><p>' . e($excerpt !== '' ? $excerpt : 'Aucun texte extractible.') . '</p><h2>6. Conclusion</h2><p>' . e($conclusion) . '</p></html>';

if ($outputFormat === 'pdf') {
    $pdf = new Dompdf();
    $pdf->loadHtml($report, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    $pdf->stream('rapport_' . date('Y-m-d_H-i-s') . '.pdf', ['Attachment' => true]);
    exit;
}

// Le format Word reprend le rapport en texte structure et les statistiques en tableaux lisibles.
$word = new PhpWord();
$section = $word->addSection();
$section->addTitle('Rapport d analyse documentaire', 1);
foreach (['Contexte et objectif' => $professionalText, 'Demande du commanditaire' => $instructions, 'Resultats et observations' => html_entity_decode(strip_tags($analysis['html']), ENT_QUOTES, 'UTF-8'), 'Conclusion' => $conclusion] as $title => $text) {
    $section->addTitle($title, 2);
    $section->addText($text);
    $section->addTextBreak();
}
$temporaryFile = tempnam(sys_get_temp_dir(), 'rapport_') . '.docx';
WordWriterFactory::createWriter($word, 'Word2007')->save($temporaryFile);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="rapport_' . date('Y-m-d_H-i-s') . '.docx"');
header('Content-Length: ' . filesize($temporaryFile));
readfile($temporaryFile);
unlink($temporaryFile);
