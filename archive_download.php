<?php
declare(strict_types=1);

require __DIR__ . '/archive_lib.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Document introuvable.'); }

$statement = archiveDatabase()->prepare('SELECT original_name, stored_name, mime_type FROM documents WHERE id = ?');
$statement->execute([$id]);
$document = $statement->fetch(PDO::FETCH_ASSOC);
$path = $document ? ARCHIVE_FILES_DIRECTORY . DIRECTORY_SEPARATOR . $document['stored_name'] : '';
if (!$document || !is_file($path)) { http_response_code(404); exit('Document introuvable.'); }

header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: inline; filename="' . str_replace('"', '', $document['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
