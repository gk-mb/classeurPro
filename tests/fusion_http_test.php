<?php
declare(strict_types=1);
require __DIR__ . '/../fusion_lib.php';
$base = 'http://localhost/extraction_excel/';
$cookie = tempnam(sys_get_temp_dir(), 'fusion_cookie_');
$file = tempnam(sys_get_temp_dir(), 'fusion_doc_');
function request(string $url, string $cookie, ?array $data = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $body];
}
try {
    [$code, $page] = request($base . 'fusion.php', $cookie);
    if ($code !== 200 || !preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m)) throw new RuntimeException('Page inaccessible');
    $document = ['blocks' => [['text' => 'Test fusion HTTP']]];
    fusionDocument($document, $document, 'docx', $file);
    $data = ['csrf_token' => $m[1], 'action' => 'check', 'x' => new CURLFile($file, 'application/octet-stream', 'x.docx'), 'y' => new CURLFile($file, 'application/octet-stream', 'y.docx')];
    [$code, $body] = request($base . 'fusion_action.php', $cookie, $data);
    if ($code !== 200 || !(json_decode($body, true)['compatible'] ?? false)) throw new RuntimeException('Contrôle HTTP : ' . $body);
    $data['action'] = 'merge'; $data['format'] = 'docx';
    [$code, $body] = request($base . 'fusion_action.php', $cookie, $data);
    if ($code !== 200 || substr($body, 0, 2) !== 'PK') throw new RuntimeException('Téléchargement HTTP invalide : ' . $body);
    $data['csrf_token'] = 'invalide';
    [$code] = request($base . 'fusion_action.php', $cookie, $data);
    if ($code !== 400) throw new RuntimeException('CSRF non refusé');
    echo "OK : page, import multipart, contrôle automatique, téléchargement DOCX, refus CSRF.\n";
} finally { unlink($cookie); unlink($file); }
