<?php
declare(strict_types=1);
require __DIR__ . '/../extraction_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
function extractionCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function extractionId(Spreadsheet $book, string $name, int $row = 1): string {
    foreach (extractionCatalog($book, $row)['columns'] as $column) if ($column['label'] === $name) return $column['id'];
    throw new RuntimeException('Colonne de test absente : ' . $name);
}
$g = new Spreadsheet(); $g->getActiveSheet()->setTitle('Est')->fromArray([['Code', 'Nom', 'Salaire'], ['001', 'Marie GLOBAL', 100], ['002', 'Jean', 0], ['002', 'Doublon', 999]], null, 'A1', true);
$g->createSheet()->setTitle('Ouest')->fromArray([['Service', 'Salaire', 'Code', 'Nom'], ['RH', 200, '003', 'Sarah']]);
$g->createSheet()->setTitle('Sans clé')->fromArray([['Info'], ['Ignorer']]);
$p = new Spreadsheet(); $p->getActiveSheet()->setTitle('Demandes')->fromArray([['Matricule', 'Nom'], ['003', 'Sarah PARTIEL'], ['001', 'Marie PARTIEL'], ['absent', 'Inconnu'], [null, 'Sans matricule'], [null, null], ['002', 'Jean PARTIEL']]);
$p->createSheet()->setTitle('Suite')->fromArray([['Nom', 'Matricule'], ['Marie bis', '001']]);
$p->createSheet()->setTitle('Sans clé')->fromArray([['Nom'], ['À vérifier']]);
$options = ['global_key' => extractionId($g, 'Code'), 'partiel_key' => extractionId($p, 'Matricule'), 'columns' => [extractionId($g, 'Salaire'), extractionId($g, 'Service')], 'insert_before' => extractionId($p, 'Nom')];
$result = extractionBuild($g, $p, $options); $s = $result['book']->getSheetByName('Resultat');
extractionCheck($result['matched'] === 4 && $result['missing'] === 3, 'Correspondances, clés vides, clé absente et lignes vides');
extractionCheck($s->rangeToArray('A1:D1')[0] === ['Matricule', 'Salaire', 'Service', 'Nom'], 'Colonnes sélectionnées et position');
extractionCheck($s->getCell('B2')->getValue() === 200 && $s->getCell('C2')->getValue() === 'RH' && $s->getCell('D2')->getValue() === 'Sarah PARTIEL', 'Alignement multifeuilles');
extractionCheck($s->getCell('B4')->getValue() === 0 && $s->getCell('D5')->getValue() === 'Marie bis', 'Zéro conservé, premier doublon, ordre PARTIEL');
extractionCheck($s->getCell('C3')->getValue() === null, 'Colonne absente laissée vide');
$missing = $result['book']->getSheetByName('Non trouves');
extractionCheck($missing->getCell('C3')->getValue() === 'Clé vide' && $missing->getCell('C4')->getValue() === 'Colonne clé absente', 'Motifs des non trouvés');
extractionCheck(count($result['warnings']) >= 3, 'Compte rendu des feuilles et doublons');
$all = $options; unset($all['columns']); $all['insert_before'] = '';
$out = extractionBuild($g, $p, $all)['book']->getSheetByName('Resultat');
extractionCheck($out->getCell('D1')->getValue() === 'Nom (GLOBAL)' && $out->getCell('D2')->getValue() === 'Sarah', 'Toutes par défaut et homonymes préservés');
$none = $options; $none['columns'] = [];
extractionCheck(extractionBuild($g, $p, $none)['book']->getSheetByName('Resultat')->getHighestDataColumn() === 'B', 'Aucune colonne ajoutée');
$multi = $options; $multi['mode'] = 'multi';
$out = extractionBuild($g, $p, $multi)['book'];
extractionCheck($out->getSheetNames() === ['Ouest', 'Est', 'Non trouves', 'Rapport'], 'Sortie multifeuilles, ordre et feuilles de suivi');
foreach ([['global_key' => 'incorrect'], ['columns' => ['incorrect']], ['insert_before' => 'incorrect'], ['module' => 'mono']] as $change) {
    $rejected = false; try { extractionBuild($g, $p, array_replace($options, $change)); } catch (RuntimeException) { $rejected = true; }
    extractionCheck($rejected, 'Configuration invalide refusée');
}
$one = new Spreadsheet(); $one->getActiveSheet()->fromArray([['Titre'], ['Clé', 'Valeur', 'Valeur'], ['A', 10, 20]], null, 'A1');
$same = new Spreadsheet(); $same->getActiveSheet()->fromArray([['Titre'], ['Identifiant'], ['A']], null, 'A1');
$config = ['module' => 'mono', 'global_header' => 2, 'partiel_header' => 2, 'global_key' => extractionId($one, 'Clé', 2), 'partiel_key' => extractionId($same, 'Identifiant', 2), 'columns' => [extractionId($one, 'Valeur — occurrence 2', 2)]];
extractionCheck(extractionBuild($one, $same, $config)['book']->getSheetByName('Resultat')->getCell('B2')->getValue() === 20, 'En-têtes décalés et noms répétés');
$one->getActiveSheet()->setCellValueExplicit('C3', '=texte', DataType::TYPE_STRING);
$out = extractionBuild($one, $same, $config)['book'];
$file = tempnam(sys_get_temp_dir(), 'extraction_test_');
try {
    IOFactory::createWriter($out, 'Xlsx')->save($file); $read = IOFactory::load($file);
    extractionCheck($read->getSheetByName('Resultat')->getCell('B2')->getDataType() === DataType::TYPE_STRING, 'Texte non converti en formule à l’export');
} finally { unlink($file); }
$names = new Spreadsheet(); $names->getActiveSheet()->fromArray([['Noms', 'Info'], ['MARIE DUPONT', 1], ['JEAN KASA', 2], ['JEAN KASA JOHN', 3]]);
$requests = new Spreadsheet(); $requests->getActiveSheet()->fromArray([['Noms'], ['marie'], ['jean'], ['sans match']]);
$result = extractionBuild($names, $requests, ['global_key' => extractionId($names, 'Noms'), 'partiel_key' => extractionId($requests, 'Noms')]);
extractionCheck($result['matched'] === 1 && $result['missing'] === 2, 'Rapprochement de noms uniquement si unique');
echo "OK : clés distinctes, choix et position, mono/multi, défaut toutes, aucune, doublons, non trouvés, format et noms incomplets.\n";
