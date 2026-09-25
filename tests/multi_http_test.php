<?php
declare(strict_types=1);
require __DIR__ . '/../enrich_multi_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
$base='http://localhost/extraction_excel/'; $paths=[];
foreach(['cookie','origin','extra','result'] as $name) $paths[$name]=tempnam(sys_get_temp_dir(),'multi_http_');
function multiHttp(string $url,string $cookie,?array $data=null): array { $c=curl_init($url); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>60]); if($data!==null) curl_setopt($c,CURLOPT_POSTFIELDS,$data); $body=curl_exec($c); $code=curl_getinfo($c,CURLINFO_RESPONSE_CODE); curl_close($c); return [$code,$body]; }
function httpCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
try {
    [$code,$page]=multiHttp($base.'ajout_multi.php',$paths['cookie']); preg_match('/name="csrf_token" value="([^"]+)"/',$page,$m); httpCheck($code===200 && isset($m[1]),'Page ajout multi');
    $o=new Spreadsheet(); $o->getActiveSheet()->setTitle('Est')->fromArray([['ID','Nom'],['001','Alice'],['001','Autre Alice']]);
    $e=new Spreadsheet(); $e->getActiveSheet()->setTitle('Est')->fromArray([['Clé','Salaire'],['001',null],['001',250]]);
    IOFactory::createWriter($o,'Xlsx')->save($paths['origin']); IOFactory::createWriter($e,'Xlsx')->save($paths['extra']);
    $uploads=[]; foreach(['origin','extra'] as $side) $uploads[$side]=new CURLFile($paths[$side],'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$side.'.xlsx');
    $oc=extractionCatalog($o,1); $ec=extractionCatalog($e,1);
    $data=$uploads+['csrf_token'=>$m[1],'action'=>'duplicates','origin_sheets'=>'["Est"]','extra_sheets'=>'["Est"]','origin_header'=>'1','extra_header'=>'1','origin_key'=>$oc['columns'][0]['id'],'extra_key'=>$ec['columns'][0]['id'],'add_columns'=>json_encode([$ec['columns'][1]['id']]),'match_sheets'=>'1','output_mode'=>'multi','insert_before'=>''];
    [$code,$body]=multiHttp($base.'ajout_multi_action.php',$paths['cookie'],$data); $d=json_decode($body,true);
    httpCheck($code===200 && $d['origin']['repeated']===1 && $d['extra']['repeated']===1,'Détection avant traitement : '.$body);
    $data['action']='merge'; [$code,$body]=multiHttp($base.'ajout_multi_action.php',$paths['cookie'],$data); httpCheck($code===400,'Choix doublons obligatoire');
    $data['duplicate_mode']='color'; $data['duplicate_occurrence']='complete';
    [$code,$body]=multiHttp($base.'ajout_multi_action.php',$paths['cookie'],$data); httpCheck($code===200 && str_starts_with($body,'PK'),'Export multi : '.$body); file_put_contents($paths['result'],$body);
    $out=IOFactory::load($paths['result']); httpCheck($out->getSheetByName('Est')->getCell('C2')->getValue()===250 && $out->getSheetByName('Doublons')!==null,'Valeurs et rapport');
    $data['output_mode']='mono'; [$code,$body]=multiHttp($base.'ajout_multi_action.php',$paths['cookie'],$data); httpCheck($code===200,'Export unique'); file_put_contents($paths['result'],$body); httpCheck(IOFactory::load($paths['result'])->getSheetByName('Résultat')!==null,'Feuille unique');
    $legacy=$uploads+['csrf_token'=>$m[1],'action'=>'duplicates','origin_sheet'=>'Est','extra_sheet'=>'Est','origin_header'=>'1','extra_header'=>'1','origin_key'=>'A','extra_key'=>'A','add_columns'=>'["B"]','insert_before'=>'A'];
    [$code,$body]=multiHttp($base.'enrichissement_action.php',$paths['cookie'],$legacy); httpCheck($code===200 && json_decode($body,true)['hasDuplicates'],'Détection dans ajouter colonne');
    $legacy['action']='merge'; $legacy['duplicate_mode']='delete';
    [$code,$body]=multiHttp($base.'enrichissement_action.php',$paths['cookie'],$legacy); httpCheck($code===200,'Suppression doublons ancien module : '.$body); file_put_contents($paths['result'],$body); httpCheck(IOFactory::load($paths['result'])->getSheetByName('Est')->getHighestDataRow()===2,'Suppression appliquée');
    $hybrid=['csrf_token'=>$m[1],'extraction_module'=>'hybrid','output_mode'=>'multi','global'=>$uploads['extra'],'partiel'=>$uploads['origin'],'global_header'=>'1','partiel_header'=>'1','global_key'=>$ec['columns'][0]['id'],'partiel_key'=>$oc['columns'][0]['id'],'global_sheets'=>'["Est"]','partiel_sheets'=>'["Est"]','match_sheets'=>'1'];
    [$code,$body]=multiHttp($base.'extraire.php',$paths['cookie'],$hybrid); httpCheck($code===200 && str_starts_with($body,'PK'),'Export hybride : '.$body);
    $data['csrf_token']='incorrect'; [$code]=multiHttp($base.'ajout_multi_action.php',$paths['cookie'],$data); httpCheck($code===400,'Session protégée');
    echo "OK HTTP : nouveaux modules, détection, choix obligatoire, couleur, suppression et exports.\n";
} finally { foreach($paths as $path) unlink($path); }
