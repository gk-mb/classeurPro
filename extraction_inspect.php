<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/extraction_lib.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Méthode non autorisée.');
    if ($error = uiRequestSizeError()) throw new RuntimeException($error);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('Session expirée. Rechargez la page.');
    session_write_close();
    $book = extractionUpload('excel');
    if (($_POST['module'] ?? '') === 'mono' && $book->getSheetCount() !== 1) throw new RuntimeException('Ce fichier contient plusieurs feuilles. Choisissez la version multifeuilles de cet outil.');
    $catalog = extractionCatalog($book, extractionHeaderRow($_POST['header'] ?? 1));
    if (!$catalog['columns']) throw new RuntimeException('Aucune colonne détectée. Vérifiez la ligne d’en-têtes et le contenu du fichier.');
    echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['message' => $e instanceof RuntimeException ? $e->getMessage() : 'Impossible de lire les colonnes. Ouvrez le fichier dans Excel et enregistrez une nouvelle copie.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
