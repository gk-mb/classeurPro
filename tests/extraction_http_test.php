<?php
declare(strict_types=1);
require __DIR__ . '/../extraction_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
$base = 'http://localhost/extraction_excel/';
$cookie = tempnam(sys_get_temp_dir(), 'extract_cookie_'); $gfile = tempnam(sys_get_temp_dir(), 'extract_g_'); $pfile = tempnam(sys_get_temp_dir(), 'extract_p_'); $result = tempnam(sys_get_temp_dir(), 'extract_out_');
function extractionRequest(string $url, string $cookie, ?array $data = null): array {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, $body];
}
try {
    [$code, $page] = extractionRequest($base . 'index.php', $cookie);
    if ($code !== 200 || !preg_match('/name="csrf_token" value="([^"]+)"/', $page, $match)) throw new RuntimeException('Page inaccessible');
    $g = new Spreadsheet(); $g->getActiveSheet()->fromArray([['Code', 'Salaire'], ['007', 500]]); IOFactory::createWriter($g, 'Xlsx')->save($gfile);
    $p = new Spreadsheet(); $p->getActiveSheet()->fromArray([['Nom', 'Matricule'], ['Marie', '007']]); IOFactory::createWriter($p, 'Xlsx')->save($pfile);
    $gupload = new CURLFile($gfile, 'application/octet-stream', 'global.xlsx'); $pupload = new CURLFile($pfile, 'application/octet-stream', 'partiel.xlsx');
    $catalogs = [];
    foreach (['global' => $gupload, 'partiel' => $pupload] as $name => $upload) {
        [$code, $body] = extractionRequest($base . 'extraction_inspect.php', $cookie, ['csrf_token' => $match[1], 'excel' => $upload, 'header' => 1, 'module' => 'mono']);
        if ($code !== 200) throw new RuntimeException('Inspection : ' . $body); $catalogs[$name] = json_decode($body, true);
    }
    $data = ['csrf_token' => $match[1], 'global' => $gupload, 'partiel' => $pupload, 'extraction_module' => 'mono', 'output_mode' => 'mono', 'global_key' => $catalogs['global']['columns'][0]['id'], 'partiel_key' => $catalogs['partiel']['columns'][1]['id'], 'global_columns' => json_encode([$catalogs['global']['columns'][1]['id']]), 'insert_before' => $catalogs['partiel']['columns'][0]['id']];
    [$code, $body] = extractionRequest($base . 'extraire.php', $cookie, $data);
    if ($code !== 200 || substr($body, 0, 2) !== 'PK') throw new RuntimeException('Export : ' . $body);
    file_put_contents($result, $body); $book = IOFactory::load($result); $s = $book->getSheetByName('Resultat');
    if ($s->rangeToArray('A1:C2', null, true, false) !== [['Salaire', 'Nom', 'Matricule'], [500, 'Marie', '007']]) throw new RuntimeException('Résultat téléchargé incorrect');
    $data['global_columns'] = '["unknown"]';
    [$code, $body] = extractionRequest($base . 'extraire.php', $cookie, $data);
    if ($code !== 400 || !isset(json_decode($body, true)['message'])) throw new RuntimeException('Sélection invalide acceptée');
    $data['csrf_token'] = 'invalid';
    [$code] = extractionRequest($base . 'extraire.php', $cookie, $data); if ($code !== 400) throw new RuntimeException('CSRF non refusé');
    echo "OK HTTP : inspection distincte, sélection, placement, export XLSX et refus des demandes invalides.\n";
} finally { foreach ([$cookie, $gfile, $pfile, $result] as $file) unlink($file); }
