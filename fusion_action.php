<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/fusion_lib.php';
require_once __DIR__ . '/ui_errors.php';
$temporary = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Méthode non autorisée.');
    if ($sizeError = uiRequestSizeError()) throw new RuntimeException($sizeError);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('Session expirée. Rechargez la page.');
    session_write_close();
    $documents = [];
    foreach (['x', 'y'] as $key) {
        $file = $_FILES[$key] ?? [];
        if ($uploadError = uiUploadError($file, 'Fichier ' . strtoupper($key))) throw new RuntimeException($uploadError);
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Importez les deux fichiers (limites PHP comprises).');
        if ($file['size'] < 1 || $file['size'] > 25 * 1024 * 1024) throw new RuntimeException('Chaque fichier doit peser entre 1 octet et 25 Mo.');
        try { $documents[$key] = fusionRead($file['tmp_name'], strtolower(pathinfo($file['name'], PATHINFO_EXTENSION))); }
        catch (RuntimeException $e) { throw new RuntimeException('Fichier ' . strtoupper($key) . ' : ' . $e->getMessage()); }
        catch (Throwable) { throw new RuntimeException('Fichier ' . strtoupper($key) . ' illisible. Vérifiez qu’il est valide, non protégé par mot de passe, et enregistrez-le à nouveau avant de réessayer.'); }
    }
    $check = fusionCheck($documents['x'], $documents['y']);
    if (($_POST['action'] ?? '') === 'check') {
        $check['columns'] = ['x' => fusionColumns($documents['x']), 'y' => fusionColumns($documents['y'])];
        if ($documents['x']['kind'] === 'excel') {
            foreach (['x' => 0, 'y' => 1] as $side => $index) $check['columns'][$side] = array_values(array_filter($check['columns'][$side], static fn ($table) => in_array($table['id'], array_column($check['pairs'], $index), true)));
        }
        header('Content-Type: application/json; charset=utf-8'); echo json_encode($check, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    if (($_POST['action'] ?? '') !== 'merge') throw new RuntimeException('Action inconnue.');
    $format = $_POST['format'] ?? '';
    if (!in_array($format, $check['formats'], true)) throw new RuntimeException('Format de sortie incompatible.');
    $options = [];
    foreach (['x_columns', 'y_columns'] as $key) if (isset($_POST[$key])) {
        if (!is_string($_POST[$key])) throw new RuntimeException('La sélection des colonnes est invalide.');
        $options[$key] = json_decode($_POST[$key], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($options[$key])) throw new RuntimeException('La sélection des colonnes est invalide.');
    }
    foreach (['separator', 'second_header'] as $key) {
        if (isset($_POST[$key]) && !in_array($_POST[$key], ['0', '1'], true)) throw new RuntimeException('Option de tableau invalide.');
        $options[$key] = ($_POST[$key] ?? '1') === '1';
    }
    $temporary = tempnam(sys_get_temp_dir(), 'fusion_');
    if ($temporary === false) throw new RuntimeException('Impossible de créer le résultat.');
    if ($format === 'xlsx') fusionExcel($documents['x'], $documents['y'], $check, $temporary, $options);
    else fusionDocument($documents['x'], $documents['y'], $format, $temporary, $options);
    header('Content-Type: ' . ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'pdf' => 'application/pdf'][$format]);
    header('Content-Disposition: attachment; filename="fusion_' . date('Y-m-d_H-i-s') . '.' . $format . '"');
    header('Cache-Control: no-store'); header('Content-Length: ' . filesize($temporary)); readfile($temporary);
} catch (Throwable $e) {
    http_response_code(400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['compatible' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Lecture ou fusion impossible. Vérifiez que les fichiers sont valides et non protégés.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
