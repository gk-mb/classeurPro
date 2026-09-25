<?php
declare(strict_types=1);
require __DIR__.'/../impression_lib.php';
require __DIR__.'/../enrichissement_xlsx.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
function printCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
$b=new Spreadsheet(); $s=$b->getActiveSheet()->setTitle('À imprimer');
$s->fromArray([['Titre'],['Nom','Description','Montant'],['Alice',str_repeat('Description longue ',12),125],['Bob','Texte','=C3+5']]);
$s->mergeCells('A1:D1'); $s->getColumnDimension('C')->setVisible(false); $s->getRowDimension(4)->setVisible(false);
$s->getStyle('C3:C4')->getNumberFormat()->setFormatCode('0.00');
$image=new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing(); $image->setPath(__DIR__.'/../assets/logo.png'); $image->setCoordinates('E6'); $image->setHeight(80); $image->setWorksheet($s);
$b->createSheet()->setTitle('Intacte')->fromArray([['Titre'],['Conserver']]);
$source=tempnam(sys_get_temp_dir(),'print_source_'); $output=tempnam(sys_get_temp_dir(),'print_result_');
try {
    $writer=IOFactory::createWriter($b,'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($source);
    $result=printPrepare($b,['module'=>'multi','sheets'=>['À imprimer'],'columns'=>['À imprimer'=>['A','B']],'header'=>2,'orientation'=>'landscape','paper'=>'A4']);
    printCheck($result['prepared']===1,'Une feuille choisie');
    printCheck($s->getStyle('B3')->getBorders()->getBottom()->getBorderStyle()==='thin','Bordures visibles');
    printCheck($s->getStyle('C3')->getBorders()->getBottom()->getBorderStyle()==='none','Colonnes non choisies gardent leur style');
    printCheck($s->getStyle('C3')->getNumberFormat()->getFormatCode()==='0.00','Format numérique conservé');
    printCheck($s->getRowDimension(3)->getRowHeight()>20 && $s->getStyle('B3')->getAlignment()->getWrapText(),'Texte long et hauteur');
    printCheck($s->getColumnDimension('C')->getVisible() && $s->getRowDimension(4)->getVisible(),'Contenu masqué rendu imprimable');
    [$start,$end]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($s->getPageSetup()->getPrintArea());
    printCheck($end[0]>=5 && $end[1]>=6,'Zone inclut image et cellules fusionnées');
    printCheck($s->getPageSetup()->getFitToWidth()===1 && $s->getPageSetup()->getFitToHeight()===0,'Pages en largeur et hauteur');
    printCheck($s->getPageSetup()->getRowsToRepeatAtTop()===[2,2],'En-têtes répétés');
    printCheck($b->getSheetByName('Intacte')->getPageSetup()->getPrintArea()==='A1:A2','Tout le résultat couvert, y compris une feuille non mise en forme');
    printCheck($b->getSheetByName('Intacte')->getStyle('A2')->getBorders()->getBottom()->getBorderStyle()==='none','Présentation de la feuille non choisie conservée');
    $writer=IOFactory::createWriter($b,'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($output);
    enrichPreserveFormulas($source,$output,''); $loaded=IOFactory::load($output);
    printCheck($loaded->getSheetByName('À imprimer')->getCell('C4')->getValue()==='=C3+5','Formule conservée');
    printCheck(count($loaded->getSheetByName('À imprimer')->getDrawingCollection())===1,'Image conservée');
    printPrepare($loaded,['sheets'=>['À imprimer'],'header'=>2,'repeat_header'=>'0']);
    foreach($loaded->getWorksheetIterator() as $sheet) printCheck($sheet->getPageSetup()->getRowsToRepeatAtTop()===[],'Répétition désactivée sur tout le résultat');
    foreach([['module'=>'mono'],['sheets'=>[]],['sheets'=>['À imprimer'],'columns'=>['À imprimer'=>[]]]] as $invalid) {
        try { printPrepare($b,$invalid); throw new LogicException('Configuration incorrecte acceptée'); } catch(RuntimeException) {}
    }
    echo "OK : impression, bordures, textes longs, zone complète, images, formules et feuilles non choisies.\n";
} finally { unlink($source); unlink($output); }
