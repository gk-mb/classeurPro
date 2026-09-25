<?php
require __DIR__ . '/../extraction_lib.php';
require __DIR__ . '/../enrichissement_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
$n = (int) ($argv[1] ?? 1500);
$g = new Spreadsheet(); $p = new Spreadsheet();
$headers = ['ID']; for ($c = 1; $c < 12; $c++) $headers[] = 'Information ' . $c;
$g->getActiveSheet()->fromArray($headers); $p->getActiveSheet()->fromArray(['Clé', 'Nom']);
for ($r = 2; $r <= $n + 1; $r++) {
    $line = [$r]; for ($c = 1; $c < 12; $c++) $line[] = $r * $c;
    $g->getActiveSheet()->fromArray($line, null, 'A' . $r);
    $p->getActiveSheet()->fromArray([$r, 'Personne ' . $r], null, 'A' . $r);
}
$g->getActiveSheet()->getStyle('B2:L' . ($n + 1))->getNumberFormat()->setFormatCode('0.00');
$gc = extractionCatalog($g, 1); $pc = extractionCatalog($p, 1);
$start = microtime(true);
$out = extractionBuild($g, $p, ['global_key' => $gc['columns'][0]['id'], 'partiel_key' => $pc['columns'][0]['id']]);
$tmp = tempnam(sys_get_temp_dir(), 'benchmark_');
try {
    $writer = IOFactory::createWriter($out['book'], 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($tmp);
    echo 'Extraction ' . $n . ' lignes : ' . round(microtime(true) - $start, 3) . " s\n";
    $start = microtime(true);
    enrichWorkbook($p, $g, ['origin_sheet' => 'Worksheet', 'extra_sheet' => 'Worksheet', 'origin_header' => 1, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A']);
    $writer = IOFactory::createWriter($p, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($tmp);
    echo 'Enrichissement ' . $n . ' lignes : ' . round(microtime(true) - $start, 3) . " s\n";
} finally { unlink($tmp); }
