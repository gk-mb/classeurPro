<?php
declare(strict_types=1);
require __DIR__ . '/../enrich_multi_lib.php';
require __DIR__ . '/../enrichissement_xlsx.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
function assertMulti(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function idMulti(Spreadsheet $book, string $name): string { foreach (extractionCatalog($book, 1)['columns'] as $c) if ($c['label'] === $name) return $c['id']; throw new RuntimeException($name); }
function bookMulti(array $sheets): Spreadsheet { $b=new Spreadsheet(); $b->removeSheetByIndex(0); foreach($sheets as $name=>$rows) $b->createSheet()->setTitle($name)->fromArray($rows); return $b; }
$g=bookMulti(['Est'=>[['ID','Montant'],[1,10]],'Ouest'=>[['ID','Montant'],[1,20],[2,30]],'Ignorée'=>[['ID','Montant'],[3,40]]]);
$p=bookMulti(['Est'=>[['Clé'],[1],[2],[9]],'Ouest'=>[['Clé'],[1]],'Sans paire'=>[['Clé'],[3]]]);
$options=['module'=>'hybrid','mode'=>'multi','global_key'=>idMulti($g,'ID'),'partiel_key'=>idMulti($p,'Clé'),'columns'=>[idMulti($g,'Montant')],'global_sheets'=>['Est','Ouest'],'partiel_sheets'=>['Est','Ouest','Sans paire'],'match_sheets'=>true,'append_missing'=>true];
$r=extractionBuild($g,$p,$options);
assertMulti($r['matched']===2 && $r['missing']===2,'Hybride matching : recherche limitée et feuille sans paire ignorée');
assertMulti($r['book']->getSheetByName('Ouest')->getCell('B2')->getValue()===20,'Même clé séparée par feuille');
$options['match_sheets']=false; $r=extractionBuild($g,$p,$options);
assertMulti($r['matched']===3 && $r['missing']===2,'Hybride : cherche dans les feuilles GLOBAL choisies uniquement');
assertMulti($r['book']->getSheetByName('Est')->getCell('B3')->getValue()===30,'Retrouve dans une autre feuille');
assertMulti($r['book']->getSheetByName('Sans paire')->getCell('A4')->getValue()===3,'Non trouvé placé dans sa feuille PARTIEL');
try { $bad=$options; $bad['global_sheets']=[]; extractionBuild($g,$p,$bad); throw new LogicException('Sélection vide acceptée'); } catch(RuntimeException $e) { assertMulti(str_contains($e->getMessage(),'au moins'),'Erreur sélection vide'); }
$o=bookMulti(['Est'=>[['ID','Nom','Calcul'],[1,'Alice','=A2+1'],[1,'Répétition','=A3+1'],[9,'Absente','=A4+1']],'Ouest'=>[['ID','Nom','Calcul'],[2,'Bob','=A2+1']],'Conserver'=>[['Note'],['Intacte']]]);
$e=bookMulti(['Est'=>[['Clé','Salaire','Service'],[1,null,null],[1,100,'RH']],'Ouest'=>[['Clé','Salaire','Service'],[2,200,'IT'],[1,500,'Direction']]]);
$options=['origin_sheets'=>['Est','Ouest'],'extra_sheets'=>['Est','Ouest'],'origin_header'=>1,'extra_header'=>1,'origin_key'=>idMulti($o,'ID'),'extra_key'=>idMulti($e,'Clé'),'add_columns'=>[idMulti($e,'Salaire'),idMulti($e,'Service')],'insert_before'=>idMulti($o,'ID'),'match_sheets'=>false,'output_mode'=>'multi'];
$prep=multiPrepare($o,$e,$options); $d=multiDuplicates($o,$e,$prep,false);
assertMulti(duplicateSummary($d['origin'],$d['extra'])['extra']['repeated']===2,'Doublons du complément entre feuilles');
try { multiEnrich(clone $o,$e,$options); throw new LogicException('Choix obligatoire absent'); } catch(RuntimeException $err) { assertMulti(str_contains($err->getMessage(),'répétées'),'Choix des doublons requis'); }
$options['duplicate_mode']='color'; $options['duplicate_occurrence']='complete';
$r=multiEnrich(clone $o,$e,$options);
assertMulti($r['matched']===3 && $r['missing']===1,'Comptage ajout multi');
$s=$r['book']->getSheetByName('Est');
assertMulti($s->getCell('A2')->getValue()===100 && $s->getCell('A3')->getValue()===100,'Plus remplie, égalité départagée dans ordre source');
assertMulti($s->getStyle('C2')->getFill()->getStartColor()->getARGB()==='FFFFE6A1','Clé répétée colorée après insertion');
assertMulti($r['book']->getSheetByName('Conserver')->getCell('A2')->getValue()==='Intacte','Feuille non sélectionnée intacte');
$one=multiSingleOutput($r); assertMulti($one->getSheetByName('Résultat')->getHighestDataRow()===5,'Sortie unique sans feuille non traitée');
$options['match_sheets']=true; $options['duplicate_occurrence']='first';
$r=multiEnrich(clone $o,$e,$options); assertMulti($r['book']->getSheetByName('Est')->getCell('A2')->getValue()===null,'Matching : première occurrence limitée à la feuille');
$options['duplicate_mode']='delete';
$source=tempnam(sys_get_temp_dir(),'multi_source_'); $out=tempnam(sys_get_temp_dir(),'multi_out_');
try {
    $w=IOFactory::createWriter($o,'Xlsx'); $w->setPreCalculateFormulas(false); $w->save($source);
    $r=multiEnrich(IOFactory::load($source),$e,$options);
    $w=IOFactory::createWriter($r['book'],'Xlsx'); $w->setPreCalculateFormulas(false); $w->save($out);
    enrichPreserveFormulas($source,$out,'','',1,$r['insertions'],$r['removed']);
    $read=IOFactory::load($out); $s=$read->getSheetByName('Est');
    assertMulti($s->getHighestDataRow()===3 && $s->getCell('D3')->getValue()==='Absente','Suppression des répétitions origine');
    assertMulti($s->getCell('E3')->getValue()==='=C3+1','Formule adaptée à suppression et insertion');
    assertMulti($read->getSheetByName('Ouest')->getCell('E2')->getValue()==='=C2+1','Formule adaptée sur deuxième feuille');
} finally { unlink($source); unlink($out); }
$differentOrigin=bookMulti(['Est'=>[['Matricule','Nom'],['001','Alice']],'Ouest'=>[['Nom','Référence'],['Bob','002']]]);
$differentExtra=bookMulti(['Est'=>[['Numéro agent','Salaire'],['001',100]],'Ouest'=>[['Salaire','Code interne'],[200,'002']]]);
$differentOptions=['origin_key'=>idMulti($differentOrigin,'Matricule'),'extra_key'=>idMulti($differentExtra,'Numéro agent'),'origin_keys'=>['Est'=>'A','Ouest'=>'B'],'extra_keys'=>['Est'=>'A','Ouest'=>'B'],'add_columns'=>[idMulti($differentExtra,'Salaire')]];
foreach ([false,true] as $matching) {
    $differentOptions['match_sheets']=$matching;
    $result=multiEnrich(clone $differentOrigin,$differentExtra,$differentOptions);
    assertMulti($result['matched']===2 && $result['book']->getSheetByName('Ouest')->getCell('C2')->getValue()===200,'Clés de noms et positions différents : seules les valeurs correspondent');
}
echo "OK : hybride, choix des feuilles, associations, doublons, ajout multi, clés indépendantes et formules après suppression.\n";
