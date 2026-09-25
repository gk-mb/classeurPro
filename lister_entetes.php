<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ui_errors.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=UTF-8');

function jsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Methode non autorisee.', 405);
if ($sizeError = uiRequestSizeError()) jsonError($sizeError, 413);
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) jsonError('Session expiree.', 403);
if (!isset($_FILES['excel']) || !is_array($_FILES['excel'])) jsonError('Le fichier Excel est obligatoire.');

$file = $_FILES['excel'];
if ($uploadError = uiUploadError($file, 'Fichier Excel')) jsonError($uploadError);
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) jsonError('Le fichier n a pas pu etre lu.');
if (($file['size'] ?? 0) < 1 || $file['size'] > 25 * 1024 * 1024) jsonError('Le fichier doit peser entre 1 octet et 25 Mo.');
if (!in_array(strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)), ['xlsx', 'xls'], true)) jsonError('Seuls les fichiers XLSX et XLS sont acceptes.');

try {
    $workbook = IOFactory::load((string) $file['tmp_name']);
} catch (\Throwable) {
    jsonError('Le fichier Excel est invalide ou illisible.');
}

$headers = [];
$known = [];
foreach ($workbook->getWorksheetIterator() as $sheet) {
    $firstRow = $sheet->toArray(null, true, true, true)[1] ?? [];
    foreach ($firstRow as $header) {
        $label = trim((string) $header);
        $key = mb_strtoupper((string) preg_replace('/\s+/', ' ', $label), 'UTF-8');
        if ($label !== '' && !isset($known[$key])) {
            $headers[] = $label;
            $known[$key] = true;
        }
    }
}

echo json_encode(['ok' => true, 'headers' => $headers], JSON_UNESCAPED_UNICODE);
