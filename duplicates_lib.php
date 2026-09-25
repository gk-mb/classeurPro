<?php
declare(strict_types=1);
require_once __DIR__ . '/enrichissement_lib.php';

function duplicateScore(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row): int
{
    $score = 0;
    $width = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    for ($c = 1; $c <= $width; $c++) {
        $value = $sheet->getCell([$c, $row])->getValue();
        if ($value !== null && trim((string) $value) !== '') $score++;
    }
    return $score;
}

/** Les clés vides ne sont pas des doublons. L'ordre est celui du classeur. */
function duplicateScan(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $specs, bool $perSheet, array $ignored = []): array
{
    $groups = [];
    foreach ($specs as $spec) {
        $sheet = $book->getSheetByName($spec['name']);
        if (!$sheet || !is_string($spec['key']) || !preg_match('/^[A-Z]{1,3}$/D', $spec['key']) || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($spec['key']) > \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn())) throw new RuntimeException('Choisissez la colonne qui contient les clés de cette feuille.');
        $last = $sheet->getHighestDataRow();
        for ($row = $spec['header'] + 1; $row <= $last; $row++) {
            $key = comparisonKey(comparisonCellValue($sheet->getCell($spec['key'] . $row)), $ignored);
            if ($key === '') continue;
            $id = json_encode([$perSheet ? $spec['name'] : '', $key], JSON_UNESCAPED_UNICODE);
            $groups[$id][] = ['sheet' => $spec['name'], 'row' => $row, 'key' => $key, 'keyColumn' => $spec['key']];
        }
    }
    $groups = array_values(array_filter($groups, static fn ($entries) => count($entries) > 1));
    foreach ($groups as &$entries) foreach ($entries as &$entry) $entry['score'] = duplicateScore($book->getSheetByName($entry['sheet']), $entry['row']);
    unset($entries, $entry);
    return $groups;
}

function duplicateSummary(array $origin, array $extra): array
{
    $summarize = static fn ($groups) => ['groups' => count($groups), 'repeated' => array_sum(array_map(static fn ($g) => count($g) - 1, $groups)), 'examples' => array_map(static fn ($g) => ['key' => $g[0]['key'], 'places' => array_map(static fn ($e) => $e['sheet'] . ' — ligne ' . $e['row'], array_slice($g, 0, 8))], array_slice($groups, 0, 8))];
    return ['origin' => $summarize($origin), 'extra' => $summarize($extra), 'hasDuplicates' => (bool) ($origin || $extra)];
}

function duplicateChoice(array $options, bool $hasDuplicates): array
{
    $mode = $options['duplicate_mode'] ?? ''; $occurrence = $options['duplicate_occurrence'] ?? '';
    if (!$hasDuplicates) return ['mode' => 'none', 'occurrence' => 'first'];
    if (!in_array($mode, ['delete', 'color'], true)) throw new RuntimeException('Des clés sont répétées. Choisissez de supprimer les répétitions ou de les conserver en couleur avant de continuer.');
    if ($mode === 'delete') $occurrence = 'first';
    if (!in_array($occurrence, ['first', 'complete'], true)) throw new RuntimeException('Choisissez la ligne à utiliser : la première ou la plus remplie.');
    return ['mode' => $mode, 'occurrence' => $occurrence];
}

function duplicateWinner(array $entries, string $occurrence): array
{
    $winner = $entries[0];
    if ($occurrence === 'complete') foreach ($entries as $entry) if ($entry['score'] > $winner['score']) $winner = $entry;
    return $winner;
}

/** Supprime seulement dans la copie traitée ; les fichiers importés restent intacts. */
function duplicateDelete(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $groups): array
{
    $removed = [];
    foreach ($groups as $entries) foreach (array_slice($entries, 1) as $entry) $removed[$entry['sheet']][] = $entry['row'];
    foreach ($removed as $name => &$rows) {
        $rows = array_values(array_unique($rows)); rsort($rows);
        foreach ($rows as $row) $book->getSheetByName($name)->removeRow($row);
    }
    unset($rows);
    return $removed;
}

function duplicateReport(\PhpOffice\PhpSpreadsheet\Spreadsheet $result, array $origin, array $extra, array $choice): void
{
    if (!$origin && !$extra) return;
    $name = 'Doublons'; for ($i = 2; $result->getSheetByName($name); $i++) $name = 'Doublons ' . $i;
    $report = $result->createSheet()->setTitle($name);
    $report->fromArray(['Fichier', 'Feuille', 'Ligne avant traitement', 'Clé répétée', 'Décision', 'Ligne utilisée']); $r = 2;
    foreach (['Origine' => $origin, 'Complément' => $extra] as $side => $groups) foreach ($groups as $entries) {
        $winner = duplicateWinner($entries, $choice['occurrence']);
        foreach ($entries as $entry) {
            $used = $entry['sheet'] === $winner['sheet'] && $entry['row'] === $winner['row'];
            foreach ([$side, $entry['sheet'], $entry['row'], $entry['key'], $choice['mode'] === 'delete' ? ($used ? 'Conservée' : 'Répétition supprimée') : 'Conservée en couleur', $winner['sheet'] . ' — ligne ' . $winner['row']] as $c => $value) $report->setCellValueExplicit([$c + 1, $r], (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if ($choice['mode'] === 'color') $report->getStyle('A' . $r . ':F' . $r)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFE6A1');
            $r++;
        }
    }
    $report->getStyle('A1:F1')->getFont()->setBold(true);
    foreach (range('A', 'F') as $col) $report->getColumnDimension($col)->setWidth(26);
}

function duplicateColorOrigin(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $groups, array $insertions = []): void
{
    foreach ($groups as $entries) foreach ($entries as $entry) {
        $sheet = $book->getSheetByName($entry['sheet']); if (!$sheet) continue;
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($entry['keyColumn']);
        $change = $insertions[$entry['sheet']] ?? null;
        if ($change && $change['before'] !== '' && $col >= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($change['before'])) $col += $change['count'];
        $sheet->getStyle(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $entry['row'])->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFE6A1');
    }
}
