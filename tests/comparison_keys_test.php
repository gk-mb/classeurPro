<?php
declare(strict_types=1);
require __DIR__ . '/../enrich_multi_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
function keyCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function keyBook(array $rows): Spreadsheet { $b=new Spreadsheet(); $b->getActiveSheet()->setTitle('Données')->fromArray($rows); return $b; }
$ignored=comparisonIgnoredItems('., -, /, REF');
keyCheck(comparisonKey("222 \t444\u{00A0}")===comparisonKey('222444'),'Espaces et espace insécable ignorés');
keyCheck(comparisonKey('REF222.-/444',$ignored)==='222444','Caractères et textes ignorés des deux côtés');
keyCheck(comparisonKey('222.444')!==comparisonKey('222444'),'Le point reste significatif sans option');
keyCheck(comparisonKey('a[b].c',comparisonIgnoredItems('['))==='AB].C','Traitement littéral sans expression');
keyCheck(comparisonKey(' . ',comparisonIgnoredItems('.'))==='','Clé vide après retrait');
$g=keyBook([['ID','Valeur'],['222.444',12],['333444',34],['.',99]]);
$p=keyBook([['Clé'],['222 444'],['333 444'],['.']]);
$gc=extractionCatalog($g,1); $pc=extractionCatalog($p,1);
foreach(['mono','multi','hybrid'] as $module) {
    $result=extractionBuild($g,$p,['module'=>$module,'mode'=>'mono','global_key'=>$gc['columns'][0]['id'],'partiel_key'=>$pc['columns'][0]['id'],'key_ignore'=>'.']);
    keyCheck($result['matched']===2 && $result['missing']===1,'Extraction '.$module.' compare après retrait : '.json_encode([$result['matched'],$result['missing'],$g->getActiveSheet()->toArray(),$p->getActiveSheet()->toArray()]));
    keyCheck($result['book']->getSheetByName('Resultat')->getCell('A2')->getValue()==='222 444','Valeur source préservée');
}
$o=keyBook([['ID','Nom'],['222 444','Alice'],['222444','Autre'],['.','Vide']]);
$e=keyBook([['Identifiant','Valeur'],['222.444',10],['222444',20],['.',30]]);
$options=['origin_sheet'=>'Données','extra_sheet'=>'Données','origin_header'=>1,'extra_header'=>1,'origin_key'=>'A','extra_key'=>'A','add_columns'=>['B'],'key_ignore'=>'.','duplicate_occurrence'=>'first'];
$stats=enrichWorkbook(clone $o,$e,$options); keyCheck($stats['matched']===2 && $stats['missing']===1,'Enrichissement cohérent avec clés vidées');
$scan=duplicateScan($e,[['name'=>'Données','key'=>'A','header'=>1]],false,comparisonIgnoredItems('.'));
keyCheck(count($scan)===1 && count($scan[0])===2,'Doublons après normalisation');
$oc=extractionCatalog($o,1); $ec=extractionCatalog($e,1);
$multi=['origin_key'=>$oc['columns'][0]['id'],'extra_key'=>$ec['columns'][0]['id'],'add_columns'=>[$ec['columns'][1]['id']],'key_ignore'=>'.'];
$prep=multiPrepare($o,$e,$multi); $dup=multiDuplicates($o,$e,$prep,false);
keyCheck(count($dup['origin'])===1 && count($dup['extra'])===1,'Contrôle avant traitement cohérent');
$multi['duplicate_mode']='delete'; $result=multiEnrich(clone $o,$e,$multi);
keyCheck($result['matched']===1 && $result['missing']===1 && $result['book']->getSheetByName('Données')->getCell('C2')->getValue()===10,'Ajout multi supprime les répétitions équivalentes');
echo "OK : espaces Unicode, éléments littéraux, extractions, ajouts, doublons et clés devenues vides.\n";
