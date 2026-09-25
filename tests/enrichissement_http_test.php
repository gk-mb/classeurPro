<?php
declare(strict_types=1);
require __DIR__ . '/../enrichissement_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
$base = 'http://localhost/extraction_excel/';
$cookie = tempnam(sys_get_temp_dir(), 'enrich_cookie_');
$file = tempnam(sys_get_temp_dir(), 'enrich_input_');
$result = tempnam(sys_get_temp_dir(), 'enrich_output_');
function enrichRequest(string $url, string $cookie, ?array $data = null): array {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, $body];
}
try {
    [$code, $page] = enrichRequest($base . 'enrichissement.php', $cookie);
    if ($code !== 200 || !preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m)) throw new RuntimeException('Page inaccessible');
    $book = new Spreadsheet(); $book->getActiveSheet()->setTitle('Données')->fromArray([['Clé', 'Valeur'], ['001', 'Ajout']]);
    IOFactory::createWriter($book, 'Xlsx')->save($file);
    $upload = new CURLFile($file, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'test.xlsx');
    [$code, $body] = enrichRequest($base . 'enrichissement_action.php', $cookie, ['action' => 'inspect', 'csrf_token' => $m[1], 'excel' => $upload, 'header' => '1']);
    if ($code !== 200 || (json_decode($body, true)['sheets'][0]['headers']['B'] ?? '') !== 'Valeur') throw new RuntimeException('Inspection : ' . $body);
    $data = ['action' => 'merge', 'csrf_token' => $m[1], 'origin' => $upload, 'extra' => $upload, 'origin_sheet' => 'Données', 'extra_sheet' => 'Données', 'origin_header' => '1', 'extra_header' => '1', 'origin_key' => 'A', 'extra_key' => 'A', 'add_column' => 'B'];
    [$code, $body] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 200 || substr($body, 0, 2) !== 'PK') throw new RuntimeException('Export : ' . $body);
    file_put_contents($result, $body); $output = IOFactory::load($result);
    if ($output->getSheetByName('Données')->getCell('C2')->getValue() !== 'Ajout' || !$output->getSheetByName('Non trouvés')) throw new RuntimeException('Résultat incorrect');
    $data['insert_before'] = 'A';
    [$code, $body] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 200) throw new RuntimeException('Insertion : ' . $body);
    file_put_contents($result, $body); $output = IOFactory::load($result);
    if ($output->getSheetByName('Données')->getCell('A2')->getValue() !== 'Ajout' || $output->getSheetByName('Données')->getCell('B1')->getValue() !== 'Clé') throw new RuntimeException('Position incorrecte');
    $data['add_columns'] = '["A","B"]';
    [$code, $body] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 200) throw new RuntimeException('Ajout multiple : ' . $body);
    file_put_contents($result, $body); $output = IOFactory::load($result);
    if ($output->getSheetByName('Données')->getCell('B2')->getValue() !== 'Ajout' || $output->getSheetByName('Données')->getCell('C1')->getValue() !== 'Clé') throw new RuntimeException('Position multiple incorrecte');
    foreach (['[]', 'null', '["Z"]'] as $invalid) {
        $data['add_columns'] = $invalid;
        [$code] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
        if ($code !== 400) throw new RuntimeException('Sélection multiple invalide acceptée');
    }
    unset($data['add_columns']);
    $data['insert_before'] = 'XFD';
    [$code] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 400) throw new RuntimeException('Position inexistante acceptée');
    $data['insert_before'] = '';
    $data['origin_key'] = 'Z';
    [$code] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 400) throw new RuntimeException('Colonne invalide acceptée');
    $data['csrf_token'] = 'invalide';
    [$code] = enrichRequest($base . 'enrichissement_action.php', $cookie, $data);
    if ($code !== 400) throw new RuntimeException('CSRF invalide accepté');
    echo "OK : page, inspection, export XLSX, validation des colonnes et CSRF.\n";
} finally { unlink($cookie); unlink($file); unlink($result); }
