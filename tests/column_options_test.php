<?php
declare(strict_types=1);
require __DIR__ . '/../enrichissement_lib.php';
require __DIR__ . '/../enrichissement_xlsx.php';
require __DIR__ . '/../fusion_lib.php';
require __DIR__ . '/../extraction_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
function expectOption(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fixtureOption(string $name, array $rows): Spreadsheet { $b = new Spreadsheet(); $b->getActiveSheet()->setTitle($name)->fromArray($rows); return $b; }
$tmp = tempnam(sys_get_temp_dir(), 'options_'); $sourcePath = tempnam(sys_get_temp_dir(), 'options_source_');
try {
    $origin = fixtureOption('Origine', [['ID', 'Calcul'], ['001', '=A2+1'], ['absent', 5]]);
    $origin->createSheet()->setTitle('Autre')->setCellValue('A1', '=Origine!B2');
    $writer = IOFactory::createWriter($origin, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($sourcePath);
    $extra = fixtureOption('Complément', [['Clé', 'Salaire', 'Service'], ['001', 120, 'RH']]);
    $config = ['origin_sheet' => 'Origine', 'extra_sheet' => 'Complément', 'origin_header' => 1, 'extra_header' => 1, 'origin_key' => 'A', 'extra_key' => 'A', 'insert_before' => 'A', 'add_columns' => ['B', 'C']];
    $stats = enrichWorkbook($origin, $extra, $config);
    expectOption($stats['matched'] === 1 && $stats['columns'] === ['A', 'B'], 'Plusieurs ajouts, clé décalée');
    expectOption($origin->getSheetByName('Origine')->getCell('A2')->getValue() === 120 && $origin->getSheetByName('Origine')->getCell('B2')->getValue() === 'RH', 'Valeurs et ordre ajoutés');
    $writer = IOFactory::createWriter($origin, 'Xlsx'); $writer->setPreCalculateFormulas(false); $writer->save($tmp);
    enrichPreserveFormulas($sourcePath, $tmp, 'Origine', 'A', 2);
    $result = IOFactory::load($tmp);
    expectOption($result->getSheetByName('Origine')->getCell('D2')->getValue() === '=C2+1', 'Formule déplacée de deux colonnes');
    expectOption($result->getSheetByName('Autre')->getCell('A1')->getValue() === '=Origine!D2', 'Référence croisée déplacée');
    expectOption($result->getSheetByName('Non trouvés')->getCell('C2')->getValue() === 'absent', 'Non trouvé conservé');
    $origin = IOFactory::load($sourcePath); unset($config['add_columns']); $config['insert_before'] = '';
    expectOption(count(enrichWorkbook($origin, $extra, $config)['columns']) === 3, 'Toutes les colonnes par défaut');
    $origin = IOFactory::load($sourcePath); $config['add_columns'] = [];
    try { enrichWorkbook($origin, $extra, $config); throw new LogicException('Sélection vide acceptée'); } catch (RuntimeException $e) { expectOption(str_contains($e->getMessage(), 'au moins'), 'Erreur utile sélection vide'); }
    foreach ([false, true] as $black) foreach ([false, true] as $header) {
        $x = ['kind' => 'excel', 'book' => fixtureOption('X', [['Nom', 'Score'], ['Alice', 10]]), 'sheets' => ['X']];
        $y = ['kind' => 'excel', 'book' => fixtureOption('Y', [['Valeur', 'Autre'], [20, 'Bob']]), 'sheets' => ['Y']];
        fusionExcel($x, $y, fusionCheck($x, $y), $tmp, ['x_columns' => ['X' => ['B']], 'y_columns' => ['Y' => ['A']], 'separator' => $black, 'second_header' => $header]);
        $s = IOFactory::load($tmp)->getActiveSheet(); $row = 3 + (int) $black + (int) $header;
        expectOption($s->getHighestDataColumn() === 'A' && $s->getCell('A2')->getValue() === 10 && $s->getCell('A' . $row)->getValue() === 20, 'Une colonne de chaque côté et options : ' . json_encode([$black, $header, $s->toArray()]));
        if ($black) expectOption($s->getStyle('A3')->getFill()->getStartColor()->getARGB() === 'FF000000', 'Séparation noire');
        if ($header) expectOption($s->getCell('A' . ($row - 1))->getValue() === 'Valeur', 'En-tête Y');
    }
    $doc = ['kind' => 'document', 'blocks' => [['text' => 'Introduction'], ['table' => [['Nom', 'Score'], ['Alice', '10']]]]];
    fusionDocument($doc, $doc, 'docx', $tmp, ['x_columns' => ['1' => ['1']], 'y_columns' => ['1' => ['0']], 'separator' => false, 'second_header' => false]);
    $read = fusionRead($tmp, 'docx'); $tables = array_values(array_filter($read['blocks'], static fn ($b) => isset($b['table'])));
    expectOption(count($tables) === 2 && $tables[0]['table'] === [['Score'], ['10']] && $tables[1]['table'] === [['Alice']], 'Colonnes et en-tête des tableaux Word');
    $g = fixtureOption('Est', [['ID', 'Valeur'], ['001', 10]]); $g->createSheet()->setTitle('Ouest')->fromArray([['ID', 'Valeur'], ['002', 20]]);
    $p = fixtureOption('Est', [['Clé', 'Nom'], ['001', 'Alice'], ['003', 'Absent Est']]);
    $p->createSheet()->setTitle('Ouest')->fromArray([['Clé', 'Nom'], ['002', 'Bob'], ['004', 'Absent Ouest']]);
    $p->createSheet()->setTitle('Centre')->fromArray([['Clé', 'Nom'], ['005', 'Sans feuille']]);
    $gc = extractionCatalog($g, 1); $pc = extractionCatalog($p, 1);
    $config = ['mode' => 'multi', 'global_key' => $gc['columns'][0]['id'], 'partiel_key' => $pc['columns'][0]['id'], 'append_missing' => true];
    $result = extractionBuild($g, $p, $config);
    foreach (['Est', 'Ouest'] as $name) {
        $s = $result['book']->getSheetByName($name);
        expectOption($s->getCell('B5')->getValue() === 'Absent ' . $name && $s->getCell('C5')->getValue() === null && $s->getHighestDataRow() === 5, 'Seulement les non trouvés de cette feuille');
        expectOption($s->getCell('A4')->getValue() === 'Clé' && $s->getStyle('A3')->getFill()->getStartColor()->getARGB() === 'FF000000', 'En-tête et séparation des non trouvés');
        expectOption($s->getAutoFilter()->getRange() === 'A1:D2', 'Filtre limité aux trouvés');
    }
    expectOption($result['missing'] === 3 && $result['book']->getSheetByName('Non trouves')->getHighestDataRow() === 4, 'Tous les non trouvés restent dans le suivi, y compris sans feuille');
    $onlyMissing = fixtureOption('Est', [['Clé', 'Nom'], ['absent', 'Personne absente']]);
    $emptyResult = extractionBuild($g, $onlyMissing, $config)['book'];
    expectOption($emptyResult->getSheetByName('Est')->getCell('B4')->getValue() === 'Personne absente', 'Feuille créée même sans trouvé');
    $single = $config; $single['mode'] = 'mono';
    expectOption(extractionBuild($g, $p, $single)['book']->getSheetByName('Resultat')->getHighestDataRow() === 8, 'Sortie unique sans répétition des non trouvés');
    $config['append_missing'] = false; $result = extractionBuild($g, $p, $config);
    expectOption($result['book']->getSheetByName('Est')->getHighestDataRow() === 2, 'Option non trouvés désactivée');
    echo "OK : enrichissement multiple, formules, fusion sélective Excel/Word, séparations et non trouvés multifeuilles.\n";
} finally { unlink($tmp); unlink($sourcePath); }
