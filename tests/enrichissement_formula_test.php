<?php
declare(strict_types=1);
require __DIR__ . '/../enrichissement_lib.php';
require __DIR__ . '/../enrichissement_xlsx.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
function formulaCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
formulaCheck(enrichShiftFormula('=Origine!C2+A2+"Origine!D3"', 'B', 'Origine', 'Autre') === '=Origine!D2+A2+"Origine!D3"', 'Références sur une autre feuille');
formulaCheck(enrichShiftFormula('=$C$2+B2', 'B', 'Origine', 'Origine') === '=$D$2+C2', 'Références absolues et relatives');
$directory = $argv[1] ?? sys_get_temp_dir();
$sourcePath = tempnam(sys_get_temp_dir(), 'source_formula_');
$outputPath = $directory . '/formules_preservees.xlsx';
$insertPath = $directory . '/formules_inserees.xlsx';
try {
    $book = new Spreadsheet(); $sheet = $book->getActiveSheet()->setTitle('Origine');
    $sheet->fromArray([['Matricule', 'Calcul', 'Lien'], ['001', '=A2+1', "='[1]Source'!A1"], ['002', '=A3+1', null]]);
    $book->createSheet()->setTitle('Autre')->setCellValue('A1', '=Origine!B2+B2')->setCellValue('B2', 3);
    $writer = IOFactory::createWriter($book, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($sourcePath);
    $zip = new ZipArchive(); $zip->open($sourcePath);
    $dom = new DOMDocument(); $dom->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $xp = new DOMXPath($dom); $xp->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $master = $xp->query('//s:c[@r="B2"]/s:f')->item(0); $master->setAttribute('t', 'shared'); $master->setAttribute('si', '0'); $master->setAttribute('ref', 'B2:B3');
    $child = $xp->query('//s:c[@r="B3"]/s:f')->item(0); $child->textContent = ''; $child->setAttribute('t', 'shared'); $child->setAttribute('si', '0');
    $zip->addFromString('xl/worksheets/sheet1.xml', $dom->saveXML());
    $dom = new DOMDocument(); $dom->loadXML($zip->getFromName('xl/workbook.xml'));
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $refs = $dom->createElementNS($ns, 'externalReferences'); $ref = $dom->createElementNS($ns, 'externalReference');
    $ref->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'rIdExternalTest'); $refs->appendChild($ref);
    $dom->documentElement->insertBefore($refs, $dom->getElementsByTagNameNS($ns, 'definedNames')->item(0) ?? $dom->getElementsByTagNameNS($ns, 'calcPr')->item(0));
    $zip->addFromString('xl/workbook.xml', $dom->saveXML());
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $rels = str_replace('</Relationships>', '<Relationship Id="rIdExternalTest" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="externalLinks/externalLink1.xml"/></Relationships>', $rels);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/externalLinks/externalLink1.xml', '<?xml version="1.0" encoding="UTF-8"?><externalLink xmlns="' . $ns . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><externalBook r:id="rId1"><sheetNames><sheetName val="Source"/></sheetNames><sheetDataSet><sheetData sheetId="0"><row r="1"><cell r="A1" t="n"><v>125</v></cell></row></sheetData></sheetDataSet></externalBook></externalLink>');
    $zip->addFromString('xl/externalLinks/_rels/externalLink1.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLinkPath" Target="file:///C:/classeur-test-source.xlsx" TargetMode="External"/></Relationships>');
    $types = str_replace('</Types>', '<Override PartName="/xl/externalLinks/externalLink1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.externalLink+xml"/></Types>', $zip->getFromName('[Content_Types].xml'));
    $zip->addFromString('[Content_Types].xml', $types); $zip->close();
    foreach (['' => $outputPath, 'A' => $insertPath] as $before => $path) {
        $origin = IOFactory::load($sourcePath); $extra = new Spreadsheet(); $extra->getActiveSheet()->setTitle('Source')->fromArray([['Clé', 'Ajout'], ['001', 50]]);
        enrichWorkbook($origin, $extra, ['origin_sheet' => 'Origine', 'extra_sheet' => 'Source', 'origin_header' => 1, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A', 'add_column' => 'B', 'insert_before' => $before]);
        $writer = IOFactory::createWriter($origin, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($path);
        enrichPreserveFormulas($sourcePath, $path, 'Origine', $before);
        $z = new ZipArchive(); $z->open($path);
        for ($i = 0; $i < $z->numFiles; $i++) if (preg_match('/\.(xml|rels)$/', $z->getNameIndex($i))) { $xml = new DOMDocument(); formulaCheck($xml->loadXML($z->getFromIndex($i), LIBXML_NONET), 'XML valide'); }
        formulaCheck($z->locateName('xl/externalLinks/externalLink1.xml') !== false, 'Classeur lié préservé');
        formulaCheck(str_contains($z->getFromName('xl/workbook.xml'), 'externalReferences'), 'Déclaration du lien préservée');
        $xml = new DOMDocument(); $xml->loadXML($z->getFromName('xl/worksheets/sheet1.xml')); $xp = new DOMXPath($xml); $xp->registerNamespace('s', $ns);
        $masterAddress = $before === '' ? 'B2' : 'C2';
        $f = $xp->query('//s:c[@r="' . $masterAddress . '"]/s:f')->item(0);
        formulaCheck($before === '' ? $f->getAttribute('t') === 'shared' && $f->getAttribute('si') === '0' : !$f->hasAttribute('t') && !$f->hasAttribute('si'), 'Formule partagée conservée ou développée à l’insertion');
        formulaCheck($f->textContent === ($before === '' ? 'A2+1' : 'B2+1'), 'Formule adaptée à la position');
        formulaCheck($f->getAttribute('ref') === ($before === '' ? 'B2:B3' : ''), 'Plage partagée cohérente');
        if ($before !== '') formulaCheck($xp->query('//s:c[@r="C3"]/s:f')->item(0)->textContent === 'B3+1', 'Formule enfant développée et décalée');
        $z->close();
    }
    // Insertion au milieu d'un groupe horizontal : les anciennes formules ne
    // suivent plus un motif partagé uniforme après le décalage.
    $horizontal = new Spreadsheet(); $horizontal->getActiveSheet()->setTitle('Origine')->fromArray([['Clé', 'B', 'C', 'D'], ['001', '=A2', '=B2', '=C2']]);
    $writer = IOFactory::createWriter($horizontal, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($sourcePath);
    $z = new ZipArchive(); $z->open($sourcePath); $d = new DOMDocument(); $d->loadXML($z->getFromName('xl/worksheets/sheet1.xml'));
    $xp = new DOMXPath($d); $xp->registerNamespace('s', $ns);
    foreach (['B2', 'C2', 'D2'] as $address) {
        $f = $xp->query('//s:c[@r="' . $address . '"]/s:f')->item(0); $f->setAttribute('t', 'shared'); $f->setAttribute('si', '0');
        if ($address === 'B2') $f->setAttribute('ref', 'B2:D2'); else $f->textContent = '';
    }
    $z->addFromString('xl/worksheets/sheet1.xml', $d->saveXML()); $z->close();
    $horizontal = IOFactory::load($sourcePath);
    enrichWorkbook($horizontal, $extra, ['origin_sheet' => 'Origine', 'extra_sheet' => 'Source', 'origin_header' => 1, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A', 'add_column' => 'B', 'insert_before' => 'C']);
    $horizontalPath = tempnam(sys_get_temp_dir(), 'horizontal_formula_');
    try {
        $writer = IOFactory::createWriter($horizontal, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($horizontalPath);
        enrichPreserveFormulas($sourcePath, $horizontalPath, 'Origine', 'C');
        $loaded = IOFactory::load($horizontalPath)->getActiveSheet();
        formulaCheck($loaded->getCell('B2')->getValue() === '=A2' && $loaded->getCell('D2')->getValue() === '=B2' && $loaded->getCell('E2')->getValue() === '=D2', 'Insertion au milieu de formules partagées horizontales');
    } finally { unlink($horizontalPath); }
    echo "OK : formules partagées, liens externes, références croisées, insertion et XML.\n";
} finally { unlink($sourcePath); if (!isset($argv[1])) { @unlink($outputPath); @unlink($insertPath); } }
