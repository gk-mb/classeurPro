<?php
declare(strict_types=1);
require __DIR__ . '/../fusion_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function book(array $names): array {
    $b = new Spreadsheet(); $b->getActiveSheet()->setTitle($names[0]);
    foreach (array_slice($names, 1) as $name) $b->createSheet()->setTitle($name);
    return ['kind' => 'excel', 'book' => $b, 'sheets' => $names];
}
$tmp = tempnam(sys_get_temp_dir(), 'fusion_test_');
try {
    $x = book(['X']); $y = book(['Y']);
    $x['book']->getActiveSheet()->fromArray([['Nom', 'Valeur'], ['Premier', 12]]);
    $y['book']->getActiveSheet()->fromArray([['Nom', 'Valeur'], ['Second', '=2+3']]);
    $y['book']->getActiveSheet()->getStyle('B2')->getNumberFormat()->setFormatCode('0.00');
    $check = fusionCheck($x, $y);
    verify($check['pairs'] === [['X', 'Y']], 'Mono avec noms différents');
    fusionExcel($x, $y, $check, $tmp);
    $output = IOFactory::load($tmp)->getActiveSheet();
    verify($output->getCell('A2')->getValue() === 'Premier' && $output->getCell('A5')->getValue() === 'Second', 'Ordre et conservation des en-têtes');
    verify($output->getCell('B5')->getValue() === 5, 'Valeur calculée');
    verify($output->getStyle('B5')->getNumberFormat()->getFormatCode() === '0.00', 'Format numérique');
    verify($output->getCell('A3')->getValue() === null && $output->getStyle('B3')->getFill()->getStartColor()->getARGB() === 'FF000000', 'Séparateur vide noir');
    $x = book(['A', 'B']); $y = book(['B', 'A']);
    verify(fusionCheck($x, $y)['pairs'] === [['A', 'A'], ['B', 'B']], 'Multi dans un ordre différent');
    foreach ([book(['A']), book(['A', 'b']), book(['A', 'C'])] as $partial) {
        verify(fusionCheck($x, $partial)['pairs'] === [['A', 'A']], 'Seules les feuilles communes sont appariées');
    }
    verify(fusionCheck(book(['A']), book(['A', 'B']))['pairs'] === [['A', 'A']], 'X mono et Y multi');
    $x['book']->getSheetByName('A')->setCellValue('A1', 'X commun');
    $x['book']->getSheetByName('B')->setCellValue('A1', 'X seul');
    $y = book(['A', 'C']);
    $y['book']->getSheetByName('A')->setCellValue('A1', 'Y commun');
    $y['book']->getSheetByName('C')->setCellValue('A1', 'Y seul');
    $check = fusionCheck($x, $y);
    verify(str_contains(implode(' ', $check['details']), 'conservées sans modification : B') && str_contains(implode(' ', $check['details']), 'ignorées : C'), 'Situation des feuilles sans paire affichée');
    fusionExcel($x, $y, $check, $tmp);
    $output = IOFactory::load($tmp);
    verify($output->getSheetNames() === ['A', 'B'], 'Feuille seule de Y non ajoutée');
    verify($output->getSheetByName('A')->getCell('A3')->getValue() === 'Y commun', 'Feuille commune fusionnée');
    verify($output->getSheetByName('B')->getHighestRow() === 1 && $output->getSheetByName('B')->getCell('A1')->getValue() === 'X seul', 'Feuille seule de X inchangée sans séparateur');
    $x = book(['A', 'B']); $y = book(['C', 'D']);
    $x['book']->getSheetByName('A')->setCellValue('A1', 'Sans paire');
    $check = fusionCheck($x, $y);
    verify($check['pairs'] === [] && str_contains(implode(' ', $check['details']), 'Aucune feuille commune'), 'Absence de paire signalée');
    fusionExcel($x, $y, $check, $tmp);
    $output = IOFactory::load($tmp);
    verify($output->getSheetNames() === ['A', 'B'] && $output->getSheetByName('A')->getHighestRow() === 1 && $output->getSheetByName('A')->getCell('A1')->getValue() === 'Sans paire', 'Aucun ajout sans paire');
    $x = ['kind' => 'document', 'blocks' => [['text' => 'Premier & <texte>'], ['table' => [['Nom', 'Valeur'], ['Alice', '10']]]]];
    $y = ['kind' => 'document', 'blocks' => [['text' => 'Second document'], ['table' => [['Bob', '20']]]]];
    fusionDocument($x, $y, 'docx', $tmp);
    $doc = fusionRead($tmp, 'docx');
    verify($doc['blocks'][0]['text'] === 'Premier & <texte>', 'Échappement Word');
    $zip = new ZipArchive(); $zip->open($tmp); $xml = $zip->getFromName('word/document.xml'); $zip->close();
    verify(str_contains($xml, 'w:fill="000000"') && strpos($xml, 'Alice') < strpos($xml, 'Second document'), 'Tableau noir et ordre Word');
    fusionDocument($x, $y, 'pdf', $tmp);
    $pdf = fusionRead($tmp, 'pdf');
    $text = implode(' ', array_map(static fn ($b) => $b['text'] ?? implode(' ', array_map(static fn ($r) => implode(' ', $r), $b['table'])), $pdf['blocks']));
    verify(str_contains($text, 'Alice') && str_contains($text, 'Bob') && strpos($text, 'Premier') < strpos($text, 'Second'), 'Contenu et ordre PDF');
    echo "OK : Excel mono/multi, feuilles sans paire, valeurs, styles, séparateur, DOCX et PDF.\n";
} finally { unlink($tmp); }
