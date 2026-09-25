<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../spreadsheet_layout.php';
$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->fromArray([['ID','Nom','Description','Montant'],['12','Alexandre',str_repeat('Un texte long ',15),123.5],['3','Alain',"Deux\nlignes",'=D2*2']]);
$sheet->getCell('D3')->setCalculatedValue(247);
$sheet->getStyle('D2:D3')->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->setCellValue('E2', 222.444);
$sheet->setCellValue('A5',str_repeat('Grand titre ',10))->mergeCells('A5:D5');
foreach (range('A','D') as $col) $sheet->getColumnDimension($col)->setWidth(90);
fitSpreadsheetColumns($book,36);
if ($sheet->getColumnDimension('A')->getWidth()>=5) throw new RuntimeException('Identifiants ou titre fusionné trop larges');
if ($sheet->getColumnDimension('B')->getWidth()<10 || $sheet->getColumnDimension('B')->getWidth()>14) throw new RuntimeException('Nom mal ajusté');
if ($sheet->getColumnDimension('E')->getWidth()>10) throw new RuntimeException('Décimales artificielles dans la largeur');
if ($sheet->getColumnDimension('C')->getWidth()>36 || !$sheet->getStyle('C2')->getAlignment()->getWrapText() || $sheet->getRowDimension(2)->getRowHeight()<30) throw new RuntimeException('Texte long tronqué');
if ($sheet->getCell('D3')->getValue()!=='=D2*2' || $sheet->getCell('D3')->getOldCalculatedValue()!==247) throw new RuntimeException('Formule modifiée');
$path=tempnam(sys_get_temp_dir(),'layout_');
try {
    $writer=\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx');$writer->setPreCalculateFormulas(false);$writer->save($path);
    $loaded=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    if ($loaded->getActiveSheet()->getColumnDimension('A')->getWidth()>=5) throw new RuntimeException('Largeur perdue après export');
} finally {unlink($path);}
echo "OK : colonnes compactes, titre fusionné, texte long, nombre formaté et formule préservée après export.\n";
