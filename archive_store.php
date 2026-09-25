<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ui_errors.php';
if ($sizeError = uiRequestSizeError()) uiErrorPage($sizeError, 'archive.php', 413);
require __DIR__ . '/archive_lib.php';

function archiveFail(string $message): never
{
    uiErrorPage($message, 'archive.php', 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') archiveFail('Methode non autorisee.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) archiveFail('Session expiree.');
if (!isset($_FILES['document']) || !is_array($_FILES['document'])) archiveFail('Le document est obligatoire.');

$file = $_FILES['document'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) archiveFail('Le document n a pas pu etre importe.');
if (($file['size'] ?? 0) < 1 || $file['size'] > 25 * 1024 * 1024) archiveFail('Le document doit peser entre 1 octet et 25 Mo.');

$extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
$allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'xls'];
if (!in_array($extension, $allowedExtensions, true)) archiveFail('Formats acceptes : PDF, image, DOCX, XLSX et XLS.');

$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/zip', 'application/x-ole-storage', 'application/CDFV2'];
if (!in_array($mime, $allowedMimes, true)) archiveFail('Le contenu du document ne correspond pas a un format autorise.');

$indexedText = archiveExtractText((string) $file['tmp_name'], $extension);
$title = trim((string) ($_POST['title'] ?? '')) ?: pathinfo((string) $file['name'], PATHINFO_FILENAME);
$category = trim((string) ($_POST['category'] ?? '')) ?: archiveSuggestCategory((string) $file['name'], $indexedText);
$tags = trim((string) ($_POST['tags'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$documentDate = trim((string) ($_POST['document_date'] ?? '')) ?: null;

if (mb_strlen($title, 'UTF-8') > 200 || mb_strlen($category, 'UTF-8') > 100 || mb_strlen($tags, 'UTF-8') > 500 || mb_strlen($description, 'UTF-8') > 2000) archiveFail('Une information depasse la longueur autorisee.');

$storedName = bin2hex(random_bytes(16)) . '.' . $extension;
$target = ARCHIVE_FILES_DIRECTORY . DIRECTORY_SEPARATOR . $storedName;
archiveDatabase();
if (!move_uploaded_file((string) $file['tmp_name'], $target)) archiveFail('Le document n a pas pu etre archive.');

try {
    $statement = archiveDatabase()->prepare('INSERT INTO documents (original_name, stored_name, mime_type, title, category, tags, description, document_date, indexed_text, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([(string) $file['name'], $storedName, $mime, $title, $category, $tags, $description, $documentDate, $indexedText, date('c')]);
} catch (Throwable) {
    @unlink($target);
    archiveFail('Les metadonnees du document n ont pas pu etre enregistrees.');
}

header('Location: archive.php?success=1');
exit;
