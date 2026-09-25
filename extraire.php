<?php
/** Extraction avec clés indépendantes et colonnes GLOBAL sélectionnées. */
declare(strict_types=1);
session_start();
require __DIR__ . '/extraction_lib.php';
$temporary = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Méthode non autorisée.');
    if ($error = uiRequestSizeError()) throw new RuntimeException($error);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) throw new RuntimeException('Session expirée. Rechargez la page puis sélectionnez les fichiers à nouveau.');
    session_write_close();
    $global = extractionUpload('global'); $partial = extractionUpload('partiel');
    // JSON évite que la limite du nombre de champs PHP tronque de larges sélections.
    $selected = null;
    if (isset($_POST['global_columns'])) {
        if (!is_string($_POST['global_columns'])) throw new RuntimeException('Sélection des colonnes invalide.');
        $selected = json_decode($_POST['global_columns'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($selected) || !array_is_list($selected)) throw new RuntimeException('Sélection des colonnes invalide.');
    }
    $options = [
        'module' => $_POST['extraction_module'] ?? 'multi', 'mode' => $_POST['output_mode'] ?? 'mono',
        'global_header' => $_POST['global_header'] ?? 1, 'partiel_header' => $_POST['partiel_header'] ?? 1,
        'global_key' => $_POST['global_key'] ?? '', 'partiel_key' => $_POST['partiel_key'] ?? '',
        'insert_before' => $_POST['insert_before'] ?? '',
        'append_missing' => ($_POST['append_missing'] ?? '0') === '1',
    ];
    $options['key_ignore'] = $_POST['key_ignore'] ?? '';
    if ($selected !== null) $options['columns'] = $selected;
    if ($options['module'] === 'hybrid') {
        foreach (['global_sheets', 'partiel_sheets'] as $key) {
            if (!is_string($_POST[$key] ?? null)) throw new RuntimeException('Choisissez les feuilles dans les deux fichiers.');
            $options[$key] = json_decode($_POST[$key], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($options[$key])) throw new RuntimeException('Choisissez au moins une feuille dans chaque fichier.');
        }
        $options['match_sheets'] = ($_POST['match_sheets'] ?? '0') === '1';
    }
    $result = extractionBuild($global, $partial, $options);
    $temporary = tempnam(sys_get_temp_dir(), 'extraction_');
    if ($temporary === false) throw new RuntimeException('Impossible de préparer le téléchargement. Réessayez.');
    fitSpreadsheetColumns($result['book']);
    $writer = PhpOffice\PhpSpreadsheet\IOFactory::createWriter($result['book'], 'Xlsx');
    $writer->setPreCalculateFormulas(false); $writer->save($temporary);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="extraction_' . date('Y-m-d_H-i-s') . '.xlsx"');
    header('X-Extraction-Matched: ' . $result['matched']); header('X-Extraction-Missing: ' . $result['missing']);
    header('Content-Length: ' . filesize($temporary)); header('Cache-Control: no-store');
    readfile($temporary);
} catch (Throwable $e) {
    http_response_code(400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $e instanceof RuntimeException ? $e->getMessage() : 'L’extraction n’a pas pu être terminée. Vérifiez vos fichiers, les colonnes et les lignes d’en-têtes choisies.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} finally { if (is_string($temporary) && is_file($temporary)) unlink($temporary); }
