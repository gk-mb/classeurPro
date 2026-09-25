<?php
declare(strict_types=1);
require_once __DIR__ . '/extraction_lib.php';
require_once __DIR__ . '/spreadsheet_layout.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Shared\Drawing;

function printBounds(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
{
    $lastRow=$sheet->getHighestDataRow(); $lastColumn=Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    $include=static function(string $address) use (&$lastColumn,&$lastRow): void { if($address==='') return; [$c,$r]=Coordinate::coordinateFromString($address); $lastColumn=max($lastColumn,Coordinate::columnIndexFromString($c)); $lastRow=max($lastRow,(int)$r); };
    foreach($sheet->getMergeCells() as $range) { [, $end]=Coordinate::rangeBoundaries($range); $lastColumn=max($lastColumn,$end[0]); $lastRow=max($lastRow,$end[1]); }
    foreach($sheet->getDrawingCollection() as $drawing) {
        $include($drawing->getCoordinates());
        if($drawing->getCoordinates2()!=='') { $include($drawing->getCoordinates2()); continue; }
        [$c,$r]=Coordinate::coordinateFromString($drawing->getCoordinates()); $c=Coordinate::columnIndexFromString($c); $r=(int)$r;
        $width=$drawing->getWidth()+$drawing->getOffsetX(); $height=$drawing->getHeight()+$drawing->getOffsetY();
        while($width>0 && $c<=16384) { $letter=Coordinate::stringFromColumnIndex($c); $w=$sheet->getColumnDimension($letter)->getWidth(); if($w<0) $w=9; $width-=max(1,Drawing::cellDimensionToPixels($w,$sheet->getParent()->getDefaultStyle()->getFont())); $lastColumn=max($lastColumn,$c++); }
        while($height>0 && $r<=1048576) { $h=$sheet->getRowDimension($r)->getRowHeight(); if($h<0) $h=15; $height-=max(1,Drawing::pointsToPixels($h)); $lastRow=max($lastRow,$r++); }
    }
    foreach($sheet->getChartCollection() as $chart) { $position=$chart->getBottomRightPosition(); $include($position['cell'] ?? ''); }
    return [$lastColumn,$lastRow];
}

/** Le choix concerne la mise en forme ; toutes les données de la feuille restent imprimables. */
function printPrepare(Spreadsheet $book,array $options): array
{
    $module=$options['module'] ?? 'multi';
    if(!in_array($module,['mono','multi'],true)) throw new RuntimeException('Choisissez la préparation monofeuille ou multifeuilles.');
    if($module==='mono' && $book->getSheetCount()!==1) throw new RuntimeException('Ce fichier contient plusieurs feuilles. Utilisez la préparation multifeuilles.');
    $names=$options['sheets'] ?? $book->getSheetNames();
    if(!is_array($names) || !$names) throw new RuntimeException('Choisissez au moins une feuille à préparer.');
    foreach($names as $name) if(!is_string($name) || !in_array($name,$book->getSheetNames(),true)) throw new RuntimeException('Une feuille choisie n’existe pas dans ce fichier.');
    $names=array_values(array_intersect($book->getSheetNames(),$names));
    $header=extractionHeaderRow($options['header'] ?? 1);
    $orientation=$options['orientation'] ?? 'auto'; $paper=$options['paper'] ?? 'A4';
    $repeat=$options['repeat_header'] ?? '1';
    if(!in_array($repeat,['0','1'],true)) throw new RuntimeException('Choisissez si les en-têtes doivent être répétés sur chaque page.');
    if(!in_array($orientation,['auto','portrait','landscape'],true) || !in_array($paper,['A4','A3'],true)) throw new RuntimeException('Choisissez le format et le sens des pages dans les listes proposées.');
    $selections=$options['columns'] ?? [];
    if(!is_array($selections)) throw new RuntimeException('Choisissez les colonnes à mettre en forme.');
    foreach($names as $name) {
        $sheet=$book->getSheetByName($name); $width=Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $last=$sheet->getHighestDataRow();
        if($header>$last) throw new RuntimeException('La ligne des noms de colonnes dépasse les données de « '.$name.' ». Choisissez une ligne plus petite.');
        $columns=$selections[$name] ?? array_map(static fn($c)=>Coordinate::stringFromColumnIndex($c),range(1,$width));
        if(!is_array($columns) || !$columns) throw new RuntimeException('Choisissez au moins une colonne pour « '.$name.' ».');
        foreach($columns as $col) if(!is_string($col) || !preg_match('/^[A-Z]{1,3}$/D',$col) || Coordinate::columnIndexFromString($col)>$width) throw new RuntimeException('Une colonne choisie n’existe pas dans « '.$name.' ».');
        $heights=[];
        foreach(array_unique($columns) as $col) {
            $max=10; $texts=[];
            for($r=1;$r<=$last;$r++) {
                $cell=$sheet->getCell($col.$r);
                // Pas de recalcul global : les valeurs déjà calculées suffisent à estimer la largeur.
                $value=$cell->getDataType()==='f' ? ($cell->getOldCalculatedValue() ?? '') : $cell->getFormattedValue();
                $text=(string)$value; $texts[$r]=$text;
                foreach(explode("\n",$text) as $line) $max=max($max,mb_strlen($line)+2);
            }
            $columnWidth=min(42,$max); $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth($columnWidth)->setVisible(true);
            $sheet->getStyle($col.$header.':'.$col.$last)->applyFromArray(['borders'=>['allBorders'=>['borderStyle'=>'thin','color'=>['argb'=>'FF4A4058']]],'alignment'=>['vertical'=>'top','wrapText'=>true]]);
            $sheet->getStyle($col.$header)->applyFromArray(['font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>'solid','startColor'=>['argb'=>'FF1A093E']]]);
            foreach($texts as $r=>$text) { $lines=0; foreach(explode("\n",$text) as $line) $lines+=max(1,(int)ceil(mb_strlen($line)/max(1,$columnWidth-2))); $font=max(11,$sheet->getStyle($col.$r)->getFont()->getSize()); $heights[$r]=max($heights[$r] ?? 0,min(409,max(20,$lines*($font+4)+5))); }
        }
        foreach($heights as $row=>$height) $sheet->getRowDimension($row)->setVisible(true)->setRowHeight(max($height,$sheet->getRowDimension($row)->getRowHeight()));
        // Les colonnes non sélectionnées gardent leur mise en forme, mais restent visibles à l'impression.
        for($c=1;$c<=$width;$c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setVisible(true);
        fitWorksheetColumns($sheet, 36);
        [$printWidth,$printLast]=printBounds($sheet);
        $setup=$sheet->getPageSetup();
        $setup->setPaperSize($paper==='A3' ? PageSetup::PAPERSIZE_A3 : PageSetup::PAPERSIZE_A4);
        $setup->setOrientation($orientation==='auto' ? ($printWidth>5 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT) : $orientation);
        $setup->setFitToWidth(1)->setFitToHeight(0)->setFitToPage(true);
        $setup->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($printWidth).$printLast);
        $setup->setRowsToRepeatAtTop($repeat==='1' ? [$header,$header] : []);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);
        $sheet->setPrintGridlines(false); $sheet->freezePane('A'.($header+1));
        $sheet->getHeaderFooter()->setOddFooter('&L'.$name.'&RPage &P / &N');
        $sheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE);
    }
    // Les choix de feuilles limitent la mise en forme, pas le contenu imprimable du résultat.
    foreach($book->getWorksheetIterator() as $sheet) {
        if (!in_array($sheet->getTitle(), $names, true)) fitWorksheetColumns($sheet, 36);
        foreach($sheet->getRowDimensions() as $dimension) $dimension->setVisible(true);
        foreach($sheet->getColumnDimensions() as $dimension) $dimension->setVisible(true);
        [$printWidth,$printLast]=printBounds($sheet);
        $sheet->getPageSetup()->setPrintArea('A1:'.Coordinate::stringFromColumnIndex($printWidth).$printLast);
        $sheet->getPageSetup()->setRowsToRepeatAtTop($repeat==='1' && $header<=$sheet->getHighestDataRow() ? [$header,$header] : []);
        $sheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE);
    }
    $book->setActiveSheetIndex($book->getIndex($book->getSheetByName($names[0])));
    return ['book'=>$book,'prepared'=>count($names)];
}
