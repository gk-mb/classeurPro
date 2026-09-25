<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/enrich_multi_lib.php';
require __DIR__ . '/enrichissement_xlsx.php';
$temporary = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ouvrez le formulaire pour choisir vos fichiers.');
    if ($error = uiRequestSizeError()) throw new RuntimeException($error);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('La page est restée ouverte trop longtemps. Rechargez-la et choisissez à nouveau vos fichiers.');
    session_write_close();
    $origin = extractionUpload('origin'); $extra = extractionUpload('extra');
    $options = [];
    foreach (['origin_sheets', 'extra_sheets', 'add_columns'] as $key) {
        if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Choisissez les feuilles, les clés et les colonnes à ajouter.');
        $options[$key] = json_decode($_POST[$key], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($options[$key]) || !array_is_list($options[$key])) throw new RuntimeException('Votre sélection n’a pas pu être lue. Choisissez à nouveau les feuilles et les colonnes.');
    }
    foreach (['origin_key', 'extra_key', 'origin_header', 'extra_header', 'insert_before', 'output_mode', 'duplicate_mode', 'duplicate_occurrence'] as $key) $options[$key] = $_POST[$key] ?? ($key === 'output_mode' ? 'multi' : '');
    foreach (['origin_keys', 'extra_keys'] as $key) if (isset($_POST[$key])) {
        if (!is_string($_POST[$key])) throw new RuntimeException('Choisissez les colonnes clés des feuilles.');
        $options[$key] = json_decode($_POST[$key], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($options[$key])) throw new RuntimeException('Choisissez les colonnes clés des feuilles.');
    }
    $options['key_ignore'] = $_POST['key_ignore'] ?? '';
    $options['match_sheets'] = ($_POST['match_sheets'] ?? '0') === '1';
    if (($_POST['action'] ?? '') === 'duplicates') {
        $prep = multiPrepare($origin, $extra, $options); $duplicates = multiDuplicates($origin, $extra, $prep, $options['match_sheets']);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(duplicateSummary($duplicates['origin'], $duplicates['extra']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
    }
    if (($_POST['action'] ?? '') !== 'merge') throw new RuntimeException('Rechargez la page pour recommencer.');
    $result = multiEnrich($origin, $extra, $options);
    $book = $options['output_mode'] === 'mono' ? multiSingleOutput($result) : $result['book'];
    $temporary = tempnam(sys_get_temp_dir(), 'multi_');
    if ($temporary === false) throw new RuntimeException('Le fichier ne peut pas être préparé maintenant. Réessayez dans un instant.');
    fitSpreadsheetColumns($book);
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($temporary);
    if ($options['output_mode'] === 'multi' && strtolower(pathinfo($_FILES['origin']['name'], PATHINFO_EXTENSION)) === 'xlsx') enrichPreserveFormulas($_FILES['origin']['tmp_name'], $temporary, '', '', 1, $result['insertions'], $result['removed']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ajout_multi_' . date('Y-m-d_H-i-s') . '.xlsx"');
    header('X-Enrich-Matched: ' . $result['matched']); header('X-Enrich-Missing: ' . $result['missing']);
    header('Content-Length: ' . filesize($temporary)); header('Cache-Control: no-store'); readfile($temporary);
} catch (Throwable $error) {
    http_response_code(400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $error instanceof RuntimeException ? $error->getMessage() : 'Le fichier n’a pas pu être préparé. Vérifiez vos fichiers, les feuilles et les clés choisies.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
