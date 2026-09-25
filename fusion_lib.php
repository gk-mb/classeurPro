<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/spreadsheet_styles.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function fusionRead(string $path, string $extension): array
{
    if (in_array($extension, ['xlsx', 'xls'], true)) {
        $type = IOFactory::identify($path);
        if ($type !== ($extension === 'xlsx' ? 'Xlsx' : 'Xls')) throw new RuntimeException('Le contenu ne correspond pas au format Excel annoncé.');
        $book = IOFactory::load($path);
        return ['kind' => 'excel', 'book' => $book, 'sheets' => $book->getSheetNames()];
    }
    $blocks = [];
    if ($extension === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Document Word invalide.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $dom = new DOMDocument();
        if (!$xml || str_contains($xml, '<!DOCTYPE') || !@$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('Structure DOCX invalide.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $text = static function (DOMNode $node) use ($xp): string {
            $result = '';
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br | .//w:cr', $node) as $part) {
                $result .= $part->localName === 't' ? $part->textContent : ($part->localName === 'tab' ? "\t" : "\n");
            }
            return $result;
        };
        foreach ($xp->query('/w:document/w:body/*') as $node) {
            if ($node->localName === 'p') $blocks[] = ['text' => $text($node)];
            if ($node->localName === 'tbl') {
                $rows = [];
                foreach ($xp->query('./w:tr', $node) as $row) {
                    $cells = [];
                    foreach ($xp->query('./w:tc', $row) as $cell) {
                        $paragraphs = [];
                        foreach ($xp->query('./w:p', $cell) as $p) $paragraphs[] = $text($p);
                        $cells[] = implode("\n", $paragraphs);
                    }
                    $rows[] = $cells;
                }
                $blocks[] = ['table' => $rows];
            }
        }
    } elseif ($extension === 'pdf') {
        if (file_get_contents($path, false, null, 0, 5) !== '%PDF-') throw new RuntimeException('Document PDF invalide.');
        $pdf = (new Smalot\PdfParser\Parser())->parseFile($path);
        foreach ($pdf->getPages() as $page) {
            foreach (preg_split('/\R/u', $page->getText()) as $line) {
                // Seules les colonnes explicitement séparées par tabulations sont fiables.
                $cells = explode("\t", trim($line));
                if (count($cells) > 1) {
                    $last = count($blocks) - 1;
                    if ($last >= 0 && isset($blocks[$last]['table'])) $blocks[$last]['table'][] = $cells;
                    else $blocks[] = ['table' => [$cells]];
                } else $blocks[] = ['text' => $line];
            }
        }
    } else throw new RuntimeException('Formats acceptés : XLSX, XLS, DOCX et PDF. Convertissez les anciens fichiers DOC en DOCX.');
    if (trim(implode('', array_map(static fn ($b) => $b['text'] ?? implode('', array_map(static fn ($r) => implode('', $r), $b['table'])), $blocks))) === '') {
        throw new RuntimeException('Aucune donnée textuelle extractible. Un PDF scanné nécessite une reconnaissance de texte (OCR).');
    }
    return ['kind' => 'document', 'blocks' => $blocks, 'extension' => $extension];
}

function fusionCheck(array $x, array $y): array
{
    if ($x['kind'] !== $y['kind']) throw new RuntimeException('Associez deux fichiers Excel, ou deux documents Word/PDF.');
    if ($x['kind'] === 'document') return ['compatible' => true, 'details' => ['Documents lisibles : le contenu de Y sera ajouté après X.', 'Extraction des textes et tableaux ; images, en-têtes et mise en page originale non conservés.', 'PDF : seules les colonnes séparées par tabulations sont reconnues comme tableaux. Les autres restent en texte.'], 'formats' => ['docx', 'pdf']];
    $a = $x['sheets']; $b = $y['sheets'];
    if (count($a) === 1 && count($b) === 1) {
        $pairs = [[$a[0], $b[0]]];
        $details = ['Deux fichiers à une seule feuille : les noms peuvent être différents.'];
    } else {
        $missingY = array_values(array_diff($a, $b));
        $missingX = array_values(array_diff($b, $a));
        $pairs = array_map(static fn ($name) => [$name, $name], array_values(array_intersect($a, $b)));
        $details = ['Seules les feuilles ayant le même nom exact sont fusionnées, indépendamment de leur ordre.'];
        if ($missingY) $details[] = 'Feuilles de X sans correspondante, conservées sans modification : ' . implode(', ', $missingY) . '.';
        if ($missingX) $details[] = 'Feuilles de Y sans correspondante, ignorées : ' . implode(', ', $missingX) . '.';
        if (!$pairs) $details[] = 'Aucune feuille commune : aucune donnée ne sera ajoutée au classeur X.';
    }
    foreach ($pairs as [$left, $right]) {
        $xs = $x['book']->getSheetByName($left); $ys = $y['book']->getSheetByName($right);
        if ($xs->getHighestDataRow() + $ys->getHighestDataRow() + 1 > 1048576) throw new RuntimeException('La fusion dépasse la limite de lignes Excel.');
        $details[] = 'X « ' . $left . ' » ← Y « ' . $right . ' » : ' . $xs->getHighestDataRow() . ' + ' . $ys->getHighestDataRow() . ' ligne(s) disponibles.';
    }
    $details[] = 'Les colonnes choisies sont alignées de gauche à droite, dans leur ordre d’origine. Les cases restantes sont vides si les nombres de colonnes diffèrent. La première ligne sert d’en-tête.';
    return ['compatible' => true, 'pairs' => $pairs, 'details' => $details, 'formats' => ['xlsx']];
}

/** Colonnes de chaque feuille ou tableau, dans l'ordre du fichier. */
function fusionColumns(array $document): array
{
    $tables = [];
    if (($document['kind'] ?? 'document') === 'excel') {
        foreach ($document['book']->getWorksheetIterator() as $sheet) {
            $columns = [];
            for ($c = 1; $c <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $columns[] = ['id' => $letter, 'label' => $letter . ' — ' . ($sheet->getCell($letter . '1')->getFormattedValue() ?: 'sans en-tête')];
            }
            $tables[] = ['id' => $sheet->getTitle(), 'label' => $sheet->getTitle(), 'columns' => $columns];
        }
    } else foreach ($document['blocks'] as $index => $block) if (isset($block['table'])) {
        $columns = []; $width = max(array_map('count', $block['table']));
        for ($c = 0; $c < $width; $c++) $columns[] = ['id' => (string) $c, 'label' => ($c + 1) . ' — ' . ($block['table'][0][$c] ?? 'sans en-tête')];
        $tables[] = ['id' => (string) $index, 'label' => 'Tableau ' . (count($tables) + 1), 'columns' => $columns];
    }
    return $tables;
}

function fusionSelection(array $document, mixed $selection): array
{
    if (!is_array($selection)) throw new RuntimeException('La sélection des colonnes est invalide.');
    $result = [];
    foreach (fusionColumns($document) as $table) {
        $all = array_column($table['columns'], 'id'); $chosen = $selection[$table['id']] ?? $all;
        if (!is_array($chosen) || !$chosen) throw new RuntimeException('Choisissez au moins une colonne pour « ' . $table['label'] . ' ».');
        foreach ($chosen as $id) if (!is_string($id) || !in_array($id, $all, true)) throw new RuntimeException('Une colonne sélectionnée n’existe plus. Importez à nouveau les fichiers.');
        $result[$table['id']] = array_values(array_intersect($all, $chosen));
    }
    foreach ($selection as $id => $_) if (!array_key_exists($id, $result)) throw new RuntimeException('Une feuille ou un tableau sélectionné n’existe plus.');
    return $result;
}

/** Projette les colonnes choisies côte à côte ; les formules deviennent des valeurs. */
function fusionProject(PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $source, array $columns): PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
    $book = new PhpOffice\PhpSpreadsheet\Spreadsheet(); $target = $book->getActiveSheet(); $target->setTitle($source->getTitle());
    foreach ($columns as $i => $col) {
        $letter = Coordinate::stringFromColumnIndex($i + 1);
        $target->getColumnDimension($letter)->setWidth($source->getColumnDimension($col)->getWidth());
        for ($row = 1; $row <= $source->getHighestDataRow(); $row++) {
            $cell = $source->getCell($col . $row); $value = $cell->getValue(); $type = $cell->getDataType();
            if ($type === DataType::TYPE_FORMULA) {
                $value = $cell->getCalculatedValue();
                $type = is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : (is_bool($value) ? DataType::TYPE_BOOL : DataType::TYPE_STRING);
            }
            $target->setCellValueExplicit($letter . $row, is_object($value) ? clone $value : $value, $type);
            copySpreadsheetStyle($source, $col . $row, $target, $letter . $row);
        }
    }
    foreach ($source->getRowDimensions() as $row => $dimension) $target->getRowDimension($row)->setRowHeight($dimension->getRowHeight());
    return $target;
}

function fusionExcel(array $x, array $y, array $check, string $path, array $options = []): void
{
    $xc = fusionSelection($x, $options['x_columns'] ?? []); $yc = fusionSelection($y, $options['y_columns'] ?? []);
    $black = $options['separator'] ?? true; $header = $options['second_header'] ?? true;
    foreach ($check['pairs'] as [$left, $right]) {
        $target = $x['book']->getSheetByName($left); $source = $y['book']->getSheetByName($right);
        if (count($xc[$left]) !== Coordinate::columnIndexFromString($target->getHighestDataColumn())) {
            $position = $x['book']->getIndex($target);
            $projected = fusionProject($target, $xc[$left]);
            $x['book']->removeSheetByIndex($position);
            $target = $x['book']->addExternalSheet($projected, $position);
        }
        if (count($yc[$right]) !== Coordinate::columnIndexFromString($source->getHighestDataColumn())) $source = fusionProject($source, $yc[$right]);
        $separator = $target->getHighestDataRow() + ($black ? 1 : 0);
        $offset = $separator - ($header ? 0 : 1);
        if ($offset + $source->getHighestDataRow() > 1048576) throw new RuntimeException('La fusion dépasse la limite de lignes Excel.');
        $width = max(Coordinate::columnIndexFromString($target->getHighestDataColumn()), Coordinate::columnIndexFromString($source->getHighestDataColumn()));
        if ($black) $target->getStyle('A' . $separator . ':' . Coordinate::stringFromColumnIndex($width) . $separator)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF000000');
        foreach ($source->getCellCollection()->getCoordinates() as $address) {
            $cell = $source->getCell($address);
            [$col, $row] = Coordinate::coordinateFromString($address);
            if (!$header && (int) $row === 1) continue;
            $dest = $col . ($row + $offset);
            $value = $cell->getValue(); $type = $cell->getDataType();
            if ($type === DataType::TYPE_FORMULA) {
                $value = $cell->getCalculatedValue();
                $type = is_numeric($value) && !is_string($value) ? DataType::TYPE_NUMERIC : (is_bool($value) ? DataType::TYPE_BOOL : DataType::TYPE_STRING);
            }
            $target->setCellValueExplicit($dest, is_object($value) ? clone $value : $value, $type);
            copySpreadsheetStyle($source, $address, $target, $dest);
        }
        foreach ($source->getRowDimensions() as $row => $dimension) if ($header || $row > 1) $target->getRowDimension($row + $offset)->setRowHeight($dimension->getRowHeight());
        foreach ($source->getMergeCells() as $range) {
            if (!$header && Coordinate::rangeBoundaries($range)[0][1] === 1) continue;
            $target->mergeCells(preg_replace_callback('/([A-Z]+)(\d+)/', static fn ($m) => $m[1] . ((int) $m[2] + $offset), $range));
        }
    }
    fitSpreadsheetColumns($x['book']);
    $writer = IOFactory::createWriter($x['book'], 'Xlsx');
    $writer->setPreCalculateFormulas(false);
    $writer->save($path);
}

function fusionDocument(array $x, array $y, string $format, string $path, array $options = []): void
{
    foreach (['x', 'y'] as $side) {
        $document = $side === 'x' ? $x : $y;
        $selection = fusionSelection($document, $options[$side . '_columns'] ?? []);
        foreach ($document['blocks'] as $index => &$block) if (isset($block['table'])) {
            $block['table'] = array_map(static fn ($row) => array_map(static fn ($col) => $row[(int) $col] ?? '', $selection[$index]), $block['table']);
            if ($side === 'y' && !($options['second_header'] ?? true)) array_shift($block['table']);
        }
        unset($block);
        if ($side === 'x') $x = $document; else $y = $document;
    }
    $blocks = array_merge($x['blocks'], [['separator' => true]], $y['blocks']);
    $hasTables = count(array_filter(array_merge($x['blocks'], $y['blocks']), static fn ($b) => isset($b['table']))) > 0;
    if ($format === 'docx') {
        PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $word = new PhpOffice\PhpWord\PhpWord(); $section = $word->addSection();
        foreach ($blocks as $block) {
            if (isset($block['separator'])) {
                if ($hasTables && ($options['separator'] ?? true)) { $table = $section->addTable(['width' => 100 * 50, 'unit' => 'pct']); $table->addRow(180); $table->addCell(9000, ['bgColor' => '000000'])->addText(''); }
                elseif (!$hasTables) $section->addTextBreak();
            } elseif (isset($block['table'])) {
                $table = $section->addTable(['borderSize' => 6, 'borderColor' => '808080']);
                foreach ($block['table'] as $row) { $table->addRow(); foreach ($row as $cell) $table->addCell()->addText($cell); }
            } else $section->addText($block['text']);
        }
        PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);
    } else {
        $escape = static fn ($s) => nl2br(htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $html = '<html><head><meta charset="utf-8"><style>body{font:11px DejaVu Sans}p{white-space:pre-wrap}table{border-collapse:collapse;width:auto;max-width:100%;table-layout:auto;margin:8px 0}td{border:1px solid #888;padding:5px;word-wrap:break-word}.separator{height:12px;background:#000}</style></head><body>';
        foreach ($blocks as $block) {
            if (isset($block['separator'])) $html .= $hasTables ? (($options['separator'] ?? true) ? '<table><tr><td class="separator"></td></tr></table>' : '') : '<p>&nbsp;</p>';
            elseif (isset($block['table'])) { $html .= '<table>'; foreach ($block['table'] as $row) { $html .= '<tr>'; foreach ($row as $cell) $html .= '<td>' . $escape($cell) . '</td>'; $html .= '</tr>'; } $html .= '</table>'; }
            else $html .= '<p>' . $escape($block['text']) . '</p>';
        }
        $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]); $pdf->loadHtml($html . '</body></html>', 'UTF-8'); $pdf->render(); file_put_contents($path, $pdf->output());
    }
}
