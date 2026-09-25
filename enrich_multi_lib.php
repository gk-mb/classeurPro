<?php
declare(strict_types=1);
require_once __DIR__ . '/extraction_lib.php';
require_once __DIR__ . '/duplicates_lib.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

function multiPrepare(Spreadsheet $origin, Spreadsheet $extra, array $options): array
{
    $ignored = comparisonIgnoredItems($options['key_ignore'] ?? '');
    $oh = extractionHeaderRow($options['origin_header'] ?? 1); $eh = extractionHeaderRow($options['extra_header'] ?? 1);
    $oc = extractionSelectSheets(extractionCatalog($origin, $oh), $options['origin_sheets'] ?? null);
    $ec = extractionSelectSheets(extractionCatalog($extra, $eh), $options['extra_sheets'] ?? null);
    if ($options['match_sheets'] ?? false) {
        $names = array_values(array_intersect(array_column($oc['sheets'], 'name'), array_column($ec['sheets'], 'name')));
        if (!$names) throw new RuntimeException('Les feuilles choisies n’ont aucun nom en commun. Modifiez votre sélection ou choisissez de chercher dans toutes les feuilles.');
        $oc = extractionSelectSheets($oc, $names); $ec = extractionSelectSheets($ec, $names);
    }
    $specs = [];
    foreach (['origin', 'extra'] as $side) {
        if ($side === 'origin') { $catalog = &$oc; $header = $oh; } else { $catalog = &$ec; $header = $eh; }
        $key = $options[$side . '_key'] ?? '';
        if (!is_string($key)) throw new RuntimeException('Choisissez la clé de chaque fichier.');
        $default = null;
        foreach ($catalog['sheets'] as $sheet) if (isset($sheet['columns'][$key])) { $default = $sheet['columns'][$key]; break; }
        $keys = $options[$side . '_keys'] ?? [];
        if (!is_array($keys)) throw new RuntimeException('Choisissez la colonne clé de chaque feuille.');
        foreach ($catalog['sheets'] as &$sheet) {
            $letter = $keys[$sheet['name']] ?? $default;
            if (!is_string($letter) || !in_array($letter, array_values($sheet['columns']), true)) throw new RuntimeException('Choisissez la colonne clé dans « ' . $sheet['name'] . ' ». Son nom peut être différent de celui des autres feuilles.');
            $sheet['keyColumn'] = $letter;
            $specs[$side][] = ['name' => $sheet['name'], 'header' => $header, 'key' => $letter];
        }
        unset($sheet, $catalog);
    }
    $ids = array_column($ec['columns'], 'id'); $selected = $options['add_columns'] ?? $ids;
    if (!is_array($selected) || !$selected) throw new RuntimeException('Choisissez au moins une colonne à ajouter.');
    foreach ($selected as $id) if (!is_string($id) || !in_array($id, $ids, true)) throw new RuntimeException('Une colonne à ajouter ne figure pas dans les feuilles choisies. Revoyez votre sélection.');
    $selected = array_values(array_intersect($ids, $selected));
    $before = $options['insert_before'] ?? '';
    if (!is_string($before)) throw new RuntimeException('Choisissez où placer les colonnes.');
    if ($before !== '') foreach ($oc['sheets'] as $sheet) if (!isset($sheet['columns'][$before])) throw new RuntimeException('La colonne de placement manque dans « ' . $sheet['name'] . ' ». Choisissez l’ajout à la fin ou une colonne présente partout.');
    if (!in_array($options['output_mode'] ?? 'multi', ['mono', 'multi'], true)) throw new RuntimeException('Choisissez une sortie en une ou plusieurs feuilles.');
    return ['ignored' => $ignored, 'origin' => $oc, 'extra' => $ec, 'specs' => $specs, 'selected' => $selected, 'origin_header' => $oh, 'extra_header' => $eh];
}

function multiDuplicates(Spreadsheet $origin, Spreadsheet $extra, array $prep, bool $matching): array
{
    return ['origin' => duplicateScan($origin, $prep['specs']['origin'], true, $prep['ignored']), 'extra' => duplicateScan($extra, $prep['specs']['extra'], $matching, $prep['ignored'])];
}

function multiEnrich(Spreadsheet $origin, Spreadsheet $extra, array $options): array
{
    $prep = multiPrepare($origin, $extra, $options);
    $duplicates = multiDuplicates($origin, $extra, $prep, (bool) ($options['match_sheets'] ?? false));
    $choice = duplicateChoice($options, (bool) ($duplicates['origin'] || $duplicates['extra']));
    $removed = $choice['mode'] === 'delete' ? duplicateDelete($origin, $duplicates['origin']) : [];
    $skip = [];
    foreach ($duplicates['extra'] as $entries) {
        $winner = duplicateWinner($entries, $choice['occurrence']);
        foreach ($entries as $entry) if ($entry['sheet'] !== $winner['sheet'] || $entry['row'] !== $winner['row']) $skip[$entry['sheet']][$entry['row']] = true;
    }
    $insertions = []; $reports = []; $matched = 0; $missing = 0;
    $sourceBooks = [];
    foreach ($prep['origin']['sheets'] as $info) {
        $scope = ($options['match_sheets'] ?? false) ? $info['name'] : '*';
        if (!isset($sourceBooks[$scope])) {
            $sourceBook = new Spreadsheet(); $source = $sourceBook->getActiveSheet()->setTitle('Complément');
            $ids = array_values(array_unique(array_merge([$options['extra_key']], $prep['selected']))); $letters = [];
            $labels = array_column($prep['extra']['columns'], 'label', 'id');
            foreach ($ids as $i => $id) { $letters[$id] = Coordinate::stringFromColumnIndex($i + 1); $source->setCellValueExplicit([$i + 1, 1], $labels[$id] ?? 'Clé', DataType::TYPE_STRING); }
            $r = 2;
            foreach ($prep['extra']['sheets'] as $sheetInfo) {
                if ($scope !== '*' && $scope !== $sheetInfo['name']) continue;
                $sheet = $extra->getSheetByName($sheetInfo['name']);
                for ($row = $prep['extra_header'] + 1, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
                    if (isset($skip[$sheetInfo['name']][$row])) continue;
                    if ($r > 1048576) throw new RuntimeException('Les feuilles choisies contiennent trop de lignes. Traitez-les en plusieurs fois.');
                    foreach ($ids as $id) {
                        $column = $id === $options['extra_key'] ? $sheetInfo['keyColumn'] : ($sheetInfo['columns'][$id] ?? null);
                        if ($column !== null) extractionCopyValue($sheet, $column . $row, $source, $letters[$id] . $r);
                    }
                    $r++;
                }
            }
            $sourceBooks[$scope] = [$sourceBook, $letters];
        }
        [$sourceBook, $letters] = $sourceBooks[$scope];
        $before = ($options['insert_before'] ?? '') === '' ? '' : $info['columns'][$options['insert_before']];
        $stats = enrichWorkbook($origin, $sourceBook, ['origin_sheet' => $info['name'], 'extra_sheet' => 'Complément', 'origin_header' => $prep['origin_header'], 'extra_header' => 1, 'origin_key' => $info['keyColumn'], 'extra_key' => $letters[$options['extra_key']], 'add_columns' => array_map(static fn ($id) => $letters[$id], $prep['selected']), 'insert_before' => $before, 'duplicate_occurrence' => 'first', 'key_ignore' => $options['key_ignore'] ?? '']);
        $insertions[$info['name']] = ['before' => $before, 'count' => count($stats['columns'])];
        $matched += $stats['matched']; $missing += $stats['missing'];
        $name = 'Non trouvés ' . (count($reports) + 1);
        while ($origin->getSheetByName($name)) $name .= 'x';
        $origin->getSheetByName('Non trouvés')->setTitle($name); $reports[] = $name;
    }
    if ($choice['mode'] === 'color') duplicateColorOrigin($origin, $duplicates['origin'], $insertions);
    duplicateReport($origin, $duplicates['origin'], $duplicates['extra'], $choice);
    return ['book' => $origin, 'matched' => $matched, 'missing' => $missing, 'insertions' => $insertions, 'removed' => $removed, 'processed' => array_column($prep['origin']['sheets'], 'name'), 'reports' => $reports, 'header' => $prep['origin_header']];
}

function multiSingleOutput(array $result): Spreadsheet
{
    $source = $result['book'];
    $catalog = extractionSelectSheets(extractionCatalog($source, $result['header']), $result['processed']);
    $book = new Spreadsheet(); $target = $book->getActiveSheet()->setTitle('Résultat');
    $ids = array_column($catalog['columns'], 'id');
    if (count($ids) >= 16384) throw new RuntimeException('Trop de colonnes pour une sortie unique. Choisissez plusieurs feuilles.');
    $target->setCellValue('A1', 'Feuille origine');
    foreach ($catalog['columns'] as $i => $col) $target->setCellValueExplicit([$i + 2, 1], $col['label'], DataType::TYPE_STRING);
    $r = 2;
    foreach ($catalog['sheets'] as $info) {
        $sheet = $source->getSheetByName($info['name']);
        for ($row = $result['header'] + 1, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
            if (!extractionHasData($sheet, $row, $info['columns'])) continue;
            if ($r > 1048576) throw new RuntimeException('Trop de lignes pour une sortie unique. Choisissez plusieurs feuilles.');
            $target->setCellValueExplicit('A' . $r, $info['name'], DataType::TYPE_STRING);
            foreach ($ids as $i => $id) if (isset($info['columns'][$id])) extractionCopyValue($sheet, $info['columns'][$id] . $row, $target, Coordinate::stringFromColumnIndex($i + 2) . $r);
            $r++;
        }
    }
    foreach ($source->getWorksheetIterator() as $sheet) if (in_array($sheet->getTitle(), $result['reports'], true) || str_starts_with($sheet->getTitle(), 'Doublons')) {
        $copy = $book->createSheet()->setTitle($sheet->getTitle());
        foreach ($sheet->getCellCollection()->getCoordinates() as $address) extractionCopyValue($sheet, $address, $copy, $address);
    }
    $target->freezePane('B2'); $target->getStyle('A1:' . $target->getHighestDataColumn() . '1')->getFont()->setBold(true);
    foreach ($book->getWorksheetIterator() as $sheet) for ($c = 1; $c <= Coordinate::columnIndexFromString($sheet->getHighestDataColumn()); $c++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(22);
    return $book;
}
