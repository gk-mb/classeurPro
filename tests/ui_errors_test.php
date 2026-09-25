<?php
declare(strict_types=1);
require __DIR__ . '/../ui_errors.php';
function uiCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$_SERVER['CONTENT_LENGTH'] = 1;
uiCheck(uiRequestSizeError() === null, 'Requête normale acceptée');
$_SERVER['CONTENT_LENGTH'] = PHP_INT_MAX;
uiCheck(str_contains(uiRequestSizeError() ?? '', 'limite totale'), 'Dépassement de taille distingué de la session');
uiCheck(uiUploadError(['error' => UPLOAD_ERR_OK], 'Origine') === null, 'Import valide accepté');
uiCheck(str_contains(uiUploadError(['error' => UPLOAD_ERR_INI_SIZE], 'Origine'), 'volumineux'), 'Taille indiquée');
uiCheck(str_contains(uiUploadError(['error' => UPLOAD_ERR_PARTIAL], 'Complément'), 'interrompu'), 'Transfert interrompu expliqué');
uiCheck(str_contains(uiUploadError([], 'Origine'), 'aucun fichier'), 'Fichier manquant expliqué');
echo "OK : messages de taille, transfert interrompu et fichier manquant.\n";
