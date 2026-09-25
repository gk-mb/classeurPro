<?php
declare(strict_types=1);
require __DIR__ . '/../enrichissement_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
$directory = $argv[1];
foreach (['simple', 'table', 'formules'] as $case) {
    $a = new Spreadsheet(); $s = $a->getActiveSheet()->setTitle('Origine');
    $s->fromArray([['Matricule', 'Nom', 'Calcul'], ['001', 'Marie', '=1+2'], ['002', 'Jean', '=2+4']]);
    if ($case === 'table') $s->addTable(new Table('A1:C3', 'Personnel'));
    if ($case === 'formules') { $s->setCellValue('D1', 'Titre'); $s->setCellValue('D2', '=IF(A2="001","Oui","")'); $s->getStyle('B2')->getFont()->getColor()->setTheme(4); }
    $b = new Spreadsheet(); $b->getActiveSheet()->setTitle('Source')->fromArray([['Clé', 'Salaire'], ['001', 125]]);
    $b->getActiveSheet()->getStyle('B2')->getNumberFormat()->setFormatCode('#,##0.00');
    enrichWorkbook($a, $b, ['origin_sheet' => 'Origine', 'extra_sheet' => 'Source', 'origin_header' => 1, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A', 'add_column' => 'B']);
    $writer = IOFactory::createWriter($a, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($directory . '/' . $case . '.xlsx');
}
echo "Fixtures créées.\n";
