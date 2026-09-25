<?php
declare(strict_types=1);
require __DIR__ . '/../enrichissement_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
function checkEnrich(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fixtures(): array {
    $origin = new Spreadsheet(); $sheet = $origin->getActiveSheet()->setTitle('Personnel');
    $sheet->setCellValue('A1', 'Titre')->mergeCells('A1:C1');
    $sheet->fromArray([['Matricule', 'Nom', 'Total'], [' ab  01 ', 'Alice', '=1+2'], ['ABSENT', 'Bob', 4], [null, null, null], [null, 'Sans clé', 5], ['AB 01', 'Doublon origine', 6]], null, 'A2');
    $sheet->getColumnDimension('B')->setWidth(32); $sheet->getRowDimension(4)->setRowHeight(28); $sheet->freezePane('B3');
    $sheet->getStyle('B3')->getFont()->setBold(true); $sheet->setAutoFilter('A2:C7');
    $origin->createSheet()->setTitle('Autre')->setCellValue('A1', 'Conserver'); $origin->setActiveSheetIndex(0);
    $extra = new Spreadsheet(); $extra->getActiveSheet()->setTitle('Source')->fromArray([['Identifiant', 'Salaire'], ['AB 01', '=100+25'], ['AB 01', 125]]);
    $extra->getActiveSheet()->getStyle('B2:B3')->getNumberFormat()->setFormatCode('0.00');
    $options = ['origin_sheet' => 'Personnel', 'extra_sheet' => 'Source', 'origin_header' => 2, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A', 'add_column' => 'B'];
    return [$origin, $extra, $options];
}
$tmp = tempnam(sys_get_temp_dir(), 'enrich_test_');
try {
    [$origin, $extra, $options] = fixtures();
    $stats = enrichWorkbook($origin, $extra, $options);
    checkEnrich($stats === ['matched' => 2, 'missing' => 2, 'column' => 'D', 'columns' => ['D']], 'Comptage correspondances');
    $writer = IOFactory::createWriter($origin, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($tmp);
    $output = IOFactory::load($tmp); $s = $output->getSheetByName('Personnel');
    checkEnrich($output->getSheetNames() === ['Personnel', 'Autre', 'Non trouvés'], 'Feuilles conservées');
    checkEnrich($output->getSheetByName('Autre')->getCell('A1')->getValue() === 'Conserver', 'Autre feuille intacte');
    checkEnrich($s->getCell('C3')->getValue() === '=1+2' && $s->getCell('B4')->getValue() === 'Bob' && $s->getCell('B7')->getValue() === 'Doublon origine', 'Formules et emplacements intacts');
    checkEnrich(isset($s->getMergeCells()['A1:C1']) && $s->getFreezePane() === 'B3' && $s->getAutoFilter()->getRange() === 'A2:C7', 'Structure conservée');
    checkEnrich($s->getColumnDimension('B')->getWidth() === 32.0 && $s->getRowDimension(4)->getRowHeight() === 28.0 && $s->getStyle('B3')->getFont()->getBold(), 'Dimensions et styles conservés');
    checkEnrich($s->getCell('D3')->getValue() === 125 && $s->getCell('D7')->getValue() === 125 && $s->getStyle('D3')->getNumberFormat()->getFormatCode() === '0.00', 'Valeurs et format ajoutés');
    checkEnrich($s->getCell('D4')->getValue() === null && $s->getStyle('A4')->getFill()->getStartColor()->getARGB() === 'FFFCE4EC', 'Non trouvé en rose');
    checkEnrich($s->getStyle('A5')->getFill()->getFillType() === 'none', 'Ligne vide intacte');
    $report = $output->getSheetByName('Non trouvés');
    checkEnrich($report->getHighestDataRow() === 3 && $report->getCell('B2')->getValue() === 'Bob' && $report->getCell('B3')->getValue() === 'Sans clé', 'Rapport dans ordre origine');
    [$origin, $extra, $options] = fixtures(); $extra->getActiveSheet()->setCellValue('B3', 999);
    $blocked = false; try { enrichWorkbook($origin, $extra, $options); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), 'informations différentes'); }
    checkEnrich($blocked, 'Doublon contradictoire bloqué');
    [$origin, $extra, $options] = fixtures(); $origin->createSheet()->setTitle('Non trouvés');
    $blocked = false; try { enrichWorkbook($origin, $extra, $options); } catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), 'déjà'); }
    checkEnrich($blocked, 'Rapport existant protégé');
    [$origin, $extra, $options] = fixtures();
    $extra->getActiveSheet()->setCellValueExplicit('B2', '=texte littéral', DataType::TYPE_STRING)->setCellValueExplicit('B3', '=texte littéral', DataType::TYPE_STRING);
    enrichWorkbook($origin, $extra, $options);
    checkEnrich($origin->getSheetByName('Personnel')->getCell('D3')->getDataType() === DataType::TYPE_STRING, 'Texte non converti en formule');
    foreach (['A', 'B', 'C'] as $before) {
        [$origin, $extra, $options] = fixtures(); $options['insert_before'] = $before;
        $stats = enrichWorkbook($origin, $extra, $options);
        $s = $origin->getSheetByName('Personnel'); $report = $origin->getSheetByName('Non trouvés');
        checkEnrich($stats['column'] === $before && $stats['matched'] === 2 && $stats['missing'] === 2, 'Insertion et clés décalées : ' . $before);
        checkEnrich($s->getCell($before . '3')->getValue() === 125, 'Valeur à la position choisie');
        checkEnrich($s->getCell('D3')->getValue() === '=1+2', 'Formule origine décalée');
        checkEnrich($s->getStyle('D4')->getFill()->getStartColor()->getARGB() === 'FFFCE4EC', 'Rose sur toute la ligne après insertion');
        checkEnrich($report->getCell($before . '1')->getValue() === 'Salaire' && $report->getCell('D2')->getValue() === 4, 'Ordre du rapport après insertion');
    }
    [$origin, $extra, $options] = fixtures(); $options['insert_before'] = 'Z';
    $blocked = false; try { enrichWorkbook($origin, $extra, $options); } catch (RuntimeException) { $blocked = true; }
    checkEnrich($blocked, 'Position inexistante refusée');
    [$origin, $extra, $options] = fixtures();
    $origin->getSheetByName('Personnel')->addTable(new PhpOffice\PhpSpreadsheet\Worksheet\Table('A2:C7', 'PersonnelTable'));
    $extra->getActiveSheet()->setCellValue('B1', 'Nom'); $options['insert_before'] = 'B';
    enrichWorkbook($origin, $extra, $options);
    checkEnrich($origin->getSheetByName('Personnel')->getCell('B2')->getValue() === 'Nom (2)', 'En-tête unique dans un tableau Excel');
    echo "OK : ordre, structure, styles, formules, correspondances, non trouvés, lignes vides, doublons et protection du rapport.\n";
} finally { unlink($tmp); }
