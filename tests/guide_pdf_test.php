<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
$url = 'http://localhost/extraction_excel/guide_pdf.php';
$request = curl_init($url);
curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 90]);
$response = curl_exec($request);
$code = curl_getinfo($request, CURLINFO_RESPONSE_CODE); $size = curl_getinfo($request, CURLINFO_HEADER_SIZE);
if ($response === false || $code !== 200) throw new RuntimeException('Téléchargement PDF impossible : ' . (string) $response);
$headers = substr($response, 0, $size); $body = substr($response, $size);
if (!str_contains(strtolower($headers), 'content-type: application/pdf') || !str_contains($headers, 'attachment;') || !str_starts_with($body, '%PDF-')) throw new RuntimeException('Téléchargement incorrect');
$pdf = (new Smalot\PdfParser\Parser())->parseContent($body); $text = $pdf->getText();
foreach (['Bien commencer', 'Fusionner', 'Retrouver', 'Archives', 'Plus d’options', 'non trouvés', 'Salaire', 'Service'] as $term) {
    if (!str_contains(mb_strtolower($text), mb_strtolower($term))) throw new RuntimeException('Texte absent du guide : ' . $term);
}
if (count($pdf->getPages()) < 9) throw new RuntimeException('Guide incomplet');
echo 'OK : téléchargement PDF, texte lisible, ' . count($pdf->getPages()) . " pages et rubriques présentes.\n";
