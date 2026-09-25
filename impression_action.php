<?php
declare(strict_types=1);
session_start();
require __DIR__.'/impression_lib.php';
require __DIR__.'/enrichissement_xlsx.php';
$temporary=null;
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Ouvrez le formulaire pour choisir votre fichier.');
    if($error=uiRequestSizeError()) throw new RuntimeException($error);
    if(empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new RuntimeException('Rechargez la page, puis choisissez à nouveau votre fichier.');
    session_write_close();
    $book=extractionUpload('excel',true); $options=[];
    foreach(['sheets','columns'] as $key) { if(!is_string($_POST[$key] ?? null)) throw new RuntimeException('Choisissez les feuilles et les colonnes à préparer.'); $options[$key]=json_decode($_POST[$key],true,8,JSON_THROW_ON_ERROR); if(!is_array($options[$key])) throw new RuntimeException('Choisissez les feuilles et les colonnes à préparer.'); }
    foreach(['module','header','orientation','paper'] as $key) $options[$key]=$_POST[$key] ?? '';
    $options['repeat_header']=$_POST['repeat_header'] ?? '1';
    $result=printPrepare($book,$options); $temporary=tempnam(sys_get_temp_dir(),'print_');
    if($temporary===false) throw new RuntimeException('Le fichier ne peut pas être préparé maintenant. Réessayez dans un instant.');
    $writer=\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx'); $writer->setIncludeCharts(true); $writer->setPreCalculateFormulas(false); $writer->save($temporary);
    if(strtolower(pathinfo($_FILES['excel']['name'],PATHINFO_EXTENSION))==='xlsx') enrichPreserveFormulas($_FILES['excel']['tmp_name'],$temporary,'');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="pret_a_imprimer_'.date('Y-m-d_H-i-s').'.xlsx"');
    header('X-Print-Sheets: '.$result['prepared']); header('Content-Length: '.filesize($temporary)); header('Cache-Control: no-store'); readfile($temporary);
} catch(Throwable $error) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['message'=>$error instanceof RuntimeException ? $error->getMessage() : 'La préparation n’a pas abouti. Ouvrez le fichier dans Excel, enregistrez une nouvelle copie et réessayez.'],JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
finally { if(is_string($temporary) && is_file($temporary)) unlink($temporary); }
