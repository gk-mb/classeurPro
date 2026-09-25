<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/comparison_keys.php';
require_once __DIR__ . '/spreadsheet_styles.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function enrichKey(string $value): string
{
    return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
}

function enrichLoad(string $path, string $name): Spreadsheet
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) throw new RuntimeException('Formats acceptés : XLS et XLSX.');
    if (IOFactory::identify($path) !== ($extension === 'xls' ? 'Xls' : 'Xlsx')) throw new RuntimeException('Le contenu ne correspond pas au format Excel annoncé.');
    return IOFactory::load($path);
}

function enrichHeaders(Worksheet $sheet, int $row): array
{
    if ($row < 1 || $row > $sheet->getHighestDataRow()) throw new RuntimeException('Ligne d’en-têtes hors des données de la feuille.');
    $headers = [];
    $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    for ($col = 1; $col <= $width; $col++) {
        $letter = Coordinate::stringFromColumnIndex($col);
        $label = trim($sheet->getCell($letter . $row)->getFormattedValue());
        if ($label !== '') $headers[$letter] = $label;
    }
    return $headers;
}

function enrichValue(Worksheet $sheet, string $address): array
{
    $cell = $sheet->getCell($address);
    $value = $cell->getValue(); $type = $cell->getDataType();
    if ($type === DataType::TYPE_FORMULA) {
        $value = $cell->getCalculatedValue();
        if (is_array($value)) throw new RuntimeException('Une formule matricielle ne peut pas être ajoutée comme valeur unique.');
        $type = is_bool($value) ? DataType::TYPE_BOOL : (is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
    }
    return [is_object($value) ? clone $value : $value, $type];
}

/** Conserve les lignes ; décale les colonnes uniquement si une insertion est demandée. */
function enrichWorkbook(Spreadsheet $origin, Spreadsheet $extra, array $options): array
{
    $target = $origin->getSheetByName($options['origin_sheet']);
    $source = $extra->getSheetByName($options['extra_sheet']);
    if (!$target || !$source) throw new RuntimeException('Sélectionnez une feuille existante dans chaque fichier.');
    $originRow = $options['origin_header']; $extraRow = $options['extra_header'];
    $oh = enrichHeaders($target, $originRow); $eh = enrichHeaders($source, $extraRow);
    $ok = $options['origin_key']; $ek = $options['extra_key'];
    $adds = $options['add_columns'] ?? (isset($options['add_column']) ? [$options['add_column']] : array_keys($eh));
    if (!is_array($adds) || !$adds) throw new RuntimeException('Choisissez au moins une colonne à ajouter.');
    foreach ($adds as $add) if (!is_string($add) || !isset($eh[$add])) throw new RuntimeException('Une colonne à ajouter est invalide.');
    $adds = array_values(array_intersect(array_keys($eh), $adds));
    $count = count($adds);
    foreach ([[$target, $ok], [$source, $ek]] as [$keySheet, $keyColumn]) if (!is_string($keyColumn) || !preg_match('/^[A-Z]{1,3}$/D', $keyColumn) || Coordinate::columnIndexFromString($keyColumn) > Coordinate::columnIndexFromString($keySheet->getHighestDataColumn())) throw new RuntimeException('Choisissez une colonne clé présente dans le fichier.');
    foreach ($origin->getSheetNames() as $name) {
        if (enrichKey($name) === enrichKey('Non trouvés')) throw new RuntimeException('Le fichier origine contient déjà une feuille « Non trouvés ». Renommez-la avant de continuer pour préserver son contenu.');
    }
    $ignored = comparisonIgnoredItems($options['key_ignore'] ?? '');
    $index = [];
    for ($row = $extraRow + 1; $row <= $source->getHighestDataRow(); $row++) {
        $key = comparisonKey(comparisonCellValue($source->getCell($ek . $row)), $ignored);
        if ($key === '') continue;
        $value = array_map(static fn ($add) => enrichValue($source, $add . $row), $adds);
        if (isset($index[$key])) {
            if (isset($options['duplicate_occurrence'])) {
                if ($options['duplicate_occurrence'] !== 'complete' || duplicateScore($source, $row) <= duplicateScore($source, $index[$key]['row'])) continue;
            } elseif ($index[$key]['value'] != $value) throw new RuntimeException('La clé « ' . $key . ' » apparaît aux lignes ' . $index[$key]['row'] . ' et ' . $row . ' avec des informations différentes. Choisissez la ligne à utiliser dans le choix des doublons.');
        }
        $index[$key] = ['value' => $value, 'row' => $row];
    }
    $lastRow = $target->getHighestDataRow();
    // Inclut les colonnes déjà mises en forme pour ne pas les écraser.
    $width = Coordinate::columnIndexFromString($target->getHighestColumn());
    if ($width + $count > 16384) throw new RuntimeException('Impossible d’ajouter ces colonnes : limite Excel atteinte.');
    $before = $options['insert_before'] ?? '';
    if (!is_string($before) || ($before !== '' && (!preg_match('/^[A-Z]{1,3}$/D', $before) || Coordinate::columnIndexFromString($before) > $width))) {
        throw new RuntimeException('La position choisie n’existe pas dans la feuille origine. Choisissez une colonne ou « Après toutes les colonnes ».');
    }
    $newColumn = $before !== '' ? $before : Coordinate::stringFromColumnIndex($width + 1);
    if ($before !== '') {
        $target->insertNewColumnBefore($before, $count);
        if (Coordinate::columnIndexFromString($ok) >= Coordinate::columnIndexFromString($before)) $ok = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($ok) + $count);
    }
    $firstColumn = $newColumn;
    $newColumns = [];
    $lastColumn = Coordinate::stringFromColumnIndex($width + $count);
    foreach ($adds as $offset => $add) {
    $newColumn = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($firstColumn) + $offset);
    $newColumns[] = $newColumn;
    $heading = $eh[$add];
    // Un tableau Excel impose des en-têtes non vides et uniques dans son périmètre.
    foreach ($target->getTableCollection() as $table) {
        [$start, $end] = Coordinate::rangeBoundaries($table->getRange());
        $newIndex = Coordinate::columnIndexFromString($newColumn);
        if (!$table->getShowHeaderRow() || $originRow !== $start[1] || $newIndex < $start[0] || $newIndex > $end[0]) continue;
        $names = [];
        for ($c = $start[0]; $c <= $end[0]; $c++) if ($c !== $newIndex) $names[] = enrichKey($target->getCell([$c, $originRow])->getFormattedValue());
        for ($suffix = 2; in_array(enrichKey($heading), $names, true); $suffix++) $heading = $eh[$add] . ' (' . $suffix . ')';
    }
    $target->setCellValueExplicit($newColumn . $originRow, $heading, DataType::TYPE_STRING);
    copySpreadsheetStyle($source, $add . $extraRow, $target, $newColumn . $originRow);
    $target->getColumnDimension($newColumn)->setWidth(max(15, $source->getColumnDimension($add)->getWidth()));
    }
    $report = $origin->createSheet()->setTitle('Non trouvés');
    for ($col = 1; $col <= $width + $count; $col++) {
        $letter = Coordinate::stringFromColumnIndex($col);
        [$value, $type] = enrichValue($target, $letter . $originRow);
        $report->setCellValueExplicit($letter . '1', $value, $type);
        copySpreadsheetStyle($target, $letter . $originRow, $report, $letter . '1');
        $report->getColumnDimension($letter)->setWidth($target->getColumnDimension($letter)->getWidth());
    }
    $matched = 0; $missing = 0;
    for ($row = $originRow + 1; $row <= $lastRow; $row++) {
        $hasData = false;
        for ($col = 1; $col <= $width + $count; $col++) {
            if (in_array(Coordinate::stringFromColumnIndex($col), $newColumns, true)) continue;
            $value = $target->getCell([$col, $row])->getValue();
            if ($value !== null && $value !== '') { $hasData = true; break; }
        }
        if (!$hasData) continue; // Les lignes d'espacement restent intactes.
        $key = comparisonKey(comparisonCellValue($target->getCell($ok . $row)), $ignored);
        if ($key !== '' && isset($index[$key])) {
            foreach ($adds as $offset => $add) {
            $newColumn = $newColumns[$offset];
            [$value, $type] = $index[$key]['value'][$offset];
            $target->setCellValueExplicit($newColumn . $row, is_object($value) ? clone $value : $value, $type);
            copySpreadsheetStyle($source, $add . $index[$key]['row'], $target, $newColumn . $row);
            }
            $matched++;
        } else {
            $missing++;
            $target->getStyle('A' . $row . ':' . $lastColumn . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFCE4EC');
            for ($col = 1; $col <= $width + $count; $col++) {
                $letter = Coordinate::stringFromColumnIndex($col);
                [$value, $type] = enrichValue($target, $letter . $row);
                $report->setCellValueExplicit($letter . ($missing + 1), $value, $type);
                copySpreadsheetStyle($target, $letter . $row, $report, $letter . ($missing + 1));
            }
            $report->getRowDimension($missing + 1)->setRowHeight($target->getRowDimension($row)->getRowHeight());
        }
    }
    return ['matched' => $matched, 'missing' => $missing, 'column' => $firstColumn, 'columns' => $newColumns];
}
