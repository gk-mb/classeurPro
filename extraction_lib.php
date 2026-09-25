<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/comparison_keys.php';
require_once __DIR__ . '/spreadsheet_styles.php';
require_once __DIR__ . '/ui_errors.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

function extractionNormalize(string $value): string
{
    return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
}

function extractionUpload(string $field, bool $includeCharts = false): Spreadsheet
{
    $file = $_FILES[$field] ?? [];
    $label = strtoupper($field === 'excel' ? 'Fichier' : $field);
    if ($error = uiUploadError($file, $label)) throw new RuntimeException($error);
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException($label . ' : import invalide. Sélectionnez à nouveau le fichier.');
    if ($file['size'] < 1 || $file['size'] > 25 * 1024 * 1024) throw new RuntimeException($label . ' : choisissez un fichier non vide de 25 Mo maximum.');
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) throw new RuntimeException($label . ' : seuls les formats XLS et XLSX sont acceptés.');
    try {
        if (IOFactory::identify($file['tmp_name']) !== ($extension === 'xls' ? 'Xls' : 'Xlsx')) throw new RuntimeException();
        $reader = IOFactory::createReaderForFile($file['tmp_name']);
        $reader->setIncludeCharts($includeCharts);
        return $reader->load($file['tmp_name']);
    } catch (Throwable) { throw new RuntimeException($label . ' : fichier illisible. Vérifiez son format et retirez tout mot de passe avant de réessayer.'); }
}

function extractionHeaderRow(mixed $value): int
{
    $row = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1048576]]);
    if ($row === false) throw new RuntimeException('Le numéro de la ligne d’en-têtes doit être un entier compris entre 1 et 1 048 576.');
    return $row;
}

/** Identité par nom et rang d'apparition, indépendante de l'ordre des colonnes entre feuilles. */
function extractionCatalog(Spreadsheet $book, int $headerRow): array
{
    $columns = []; $sheets = [];
    foreach ($book->getWorksheetIterator() as $sheet) {
        $map = []; $counts = [];
        if ($headerRow <= $sheet->getHighestDataRow()) {
            $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            for ($c = 1; $c <= $width; $c++) {
                $letter = Coordinate::stringFromColumnIndex($c);
                $name = trim($sheet->getCell($letter . $headerRow)->getFormattedValue());
                $normalized = extractionNormalize($name);
                $occurrence = ($counts[$normalized] ?? 0) + 1; $counts[$normalized] = $occurrence;
                $identity = $name === '' ? ['empty', $letter] : ['header', $normalized, $occurrence];
                $id = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $label = $name === '' ? 'Colonne ' . $letter . ' (sans en-tête)' : $name . ($occurrence > 1 ? ' — occurrence ' . $occurrence : '');
                $columns[$id] ??= ['id' => $id, 'name' => $name, 'label' => $label, 'sheets' => []];
                $columns[$id]['sheets'][] = $sheet->getTitle(); $map[$id] = $letter;
            }
        }
        $sheets[] = ['name' => $sheet->getTitle(), 'columns' => $map];
    }
    return ['columns' => array_values($columns), 'sheets' => $sheets, 'sheetCount' => $book->getSheetCount(), 'headerRow' => $headerRow];
}

function extractionSelectSheets(array $catalog, mixed $names): array
{
    $all = array_column($catalog['sheets'], 'name');
    if ($names === null) $names = $all;
    if (!is_array($names) || !$names) throw new RuntimeException('Choisissez au moins une feuille dans chaque fichier.');
    foreach ($names as $name) if (!is_string($name) || !in_array($name, $all, true)) throw new RuntimeException('Une feuille choisie n’existe plus. Sélectionnez à nouveau le fichier.');
    $catalog['sheets'] = array_values(array_filter($catalog['sheets'], static fn ($s) => in_array($s['name'], $names, true)));
    $catalog['columns'] = array_values(array_filter($catalog['columns'], static fn ($c) => (bool) array_intersect($c['sheets'], $names)));
    $catalog['sheetCount'] = count($catalog['sheets']);
    return $catalog;
}

function extractionHasData(Worksheet $sheet, int $row, array $map): bool
{
    foreach ($map as $letter) {
        $value = $sheet->getCell($letter . $row)->getValue();
        if ($value !== null && $value !== '') return true;
    }
    return false;
}

/** Les textes commençant par '=' restent des textes ; les formules deviennent des valeurs. */
function extractionCopyValue(Worksheet $source, string $from, Worksheet $target, string $to): void
{
    $cell = $source->getCell($from); $type = $cell->getDataType(); $value = $cell->getValue();
    if ($type === DataType::TYPE_FORMULA) {
        $value = $cell->getCalculatedValue();
        if (is_array($value)) throw new RuntimeException('Une formule renvoie plusieurs valeurs dans « ' . $source->getTitle() . ' », cellule ' . $from . '. Remplacez-la par ses résultats avant l’extraction.');
        $type = is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : (is_bool($value) ? DataType::TYPE_BOOL : DataType::TYPE_STRING);
    }
    $target->setCellValueExplicit($to, is_object($value) ? clone $value : $value, $type);
    copySpreadsheetStyle($source, $from, $target, $to);
}

function extractionBuild(Spreadsheet $global, Spreadsheet $partial, array $options): array
{
    $module = $options['module'] ?? 'multi'; $mode = $options['mode'] ?? 'mono';
    if (!in_array($module, ['mono', 'multi', 'hybrid'], true) || !in_array($mode, ['mono', 'multi'], true) || ($module === 'mono' && $mode !== 'mono')) throw new RuntimeException('Choisissez une sortie en une ou plusieurs feuilles.');
    if ($module === 'mono' && ($global->getSheetCount() !== 1 || $partial->getSheetCount() !== 1)) throw new RuntimeException('Le module mono-feuille exige une seule feuille dans chaque fichier. Utilisez l’extraction multifeuilles.');
    $gh = extractionHeaderRow($options['global_header'] ?? 1); $ph = extractionHeaderRow($options['partiel_header'] ?? 1);
    $gc = extractionCatalog($global, $gh); $pc = extractionCatalog($partial, $ph);
    $pairSheets = $module === 'hybrid' && ($options['match_sheets'] ?? false);
    if ($module === 'hybrid') {
        $gc = extractionSelectSheets($gc, $options['global_sheets'] ?? null);
        $pc = extractionSelectSheets($pc, $options['partiel_sheets'] ?? null);
        if ($pairSheets) {
            $common = array_values(array_intersect(array_column($gc['sheets'], 'name'), array_column($pc['sheets'], 'name')));
            if (!$common) throw new RuntimeException('Aucune feuille sélectionnée ne porte le même nom dans les deux fichiers. Modifiez les feuilles choisies ou désactivez leur association.');
            $gc = extractionSelectSheets($gc, $common); $pc = extractionSelectSheets($pc, $common);
        }
    }
    $gcols = array_column($gc['columns'], null, 'id'); $pcols = array_column($pc['columns'], null, 'id');
    if (count($pcols) > 16381) throw new RuntimeException('Le fichier PARTIEL contient trop de colonnes pour préparer la feuille des lignes non trouvées. Réduisez ses colonnes.');
    $gkey = $options['global_key'] ?? ''; $pkey = $options['partiel_key'] ?? '';
    if (!is_string($gkey) || !is_string($pkey) || !isset($gcols[$gkey], $pcols[$pkey])) throw new RuntimeException('Choisissez une colonne clé valide pour chaque fichier. Rechargez les fichiers si leur contenu a changé.');
    $selected = $options['columns'] ?? array_keys($gcols);
    if (!is_array($selected) || count($selected) > count($gcols)) throw new RuntimeException('La sélection des colonnes à ajouter est invalide.');
    foreach ($selected as $id) if (!is_string($id) || !isset($gcols[$id])) throw new RuntimeException('Une colonne à ajouter n’existe plus dans GLOBAL. Chargez à nouveau le fichier.');
    $selected = array_values(array_filter(array_keys($gcols), static fn ($id) => in_array($id, $selected, true)));
    $before = $options['insert_before'] ?? '';
    if (!is_string($before) || ($before !== '' && !isset($pcols[$before]))) throw new RuntimeException('La colonne de placement n’existe plus dans PARTIEL. Choisissez à nouveau sa position.');
    $layout = [];
    foreach ($pcols as $id => $col) {
        if ($id === $before) foreach ($selected as $g) $layout[] = ['source' => 'global', 'id' => $g, 'label' => $gcols[$g]['label']];
        $layout[] = ['source' => 'partiel', 'id' => $id, 'label' => $col['label']];
    }
    if ($before === '') foreach ($selected as $id) $layout[] = ['source' => 'global', 'id' => $id, 'label' => $gcols[$id]['label']];
    if (count($layout) > 16384) throw new RuntimeException('La sélection dépasse le nombre de colonnes autorisé par Excel. Réduisez les colonnes à ajouter.');
    // Même nom dans les deux fichiers : les deux valeurs restent disponibles.
    $used = [];
    foreach ($pcols as $c) $used[extractionNormalize($c['label'])] = true;
    foreach ($layout as &$col) if ($col['source'] === 'global') {
        $base = $col['label'];
        if (isset($used[extractionNormalize($base)])) $col['label'] = $base . ' (GLOBAL)';
        for ($n = 2; isset($used[extractionNormalize($col['label'])]); $n++) $col['label'] = $base . ' (GLOBAL ' . $n . ')';
        $used[extractionNormalize($col['label'])] = true;
    }
    unset($col);
    $ignored = comparisonIgnoredItems($options['key_ignore'] ?? '');
    $index = []; $scopedIndexes = []; $warnings = []; $duplicates = 0;
    foreach ($gc['sheets'] as $info) {
        if ($pairSheets) $index = [];
        if (!isset($info['columns'][$gkey])) { $warnings[] = 'GLOBAL « ' . $info['name'] . ' » : clé absente, feuille ignorée.'; continue; }
        $sheet = $global->getSheetByName($info['name']);
        foreach ($selected as $id) if (!isset($info['columns'][$id])) $warnings[] = 'GLOBAL « ' . $info['name'] . ' » : colonne « ' . $gcols[$id]['label'] . ' » absente, cases laissées vides.';
        for ($row = $gh + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $rawKey = comparisonCellValue($sheet->getCell($info['columns'][$gkey] . $row));
            $key = comparisonKey($rawKey, $ignored);
            if ($key === '') continue;
            if (isset($index[$key])) { $duplicates++; continue; }
            $index[$key] = ['sheet' => $sheet, 'map' => $info['columns'], 'row' => $row, 'tokens' => preg_split('/ +/', comparisonKey($rawKey, $ignored, true), -1, PREG_SPLIT_NO_EMPTY)];
        }
        if ($pairSheets) $scopedIndexes[$info['name']] = $index;
    }
    if ($duplicates) $warnings[] = $duplicates . ' clé(s) répétée(s) dans GLOBAL : première occurrence retenue.';
    if (!$index) $warnings[] = 'GLOBAL ne contient aucune clé renseignée sous la ligne d’en-têtes choisie.';
    $book = new Spreadsheet(); $book->removeSheetByIndex(0);
    $makeSheet = static function (string $name, array $headers) use ($book): Worksheet {
        $base = mb_substr($name, 0, 31, 'UTF-8'); $candidate = $base;
        for ($n = 2; $book->getSheetByName($candidate) !== null || in_array(mb_strtolower($candidate), ['non trouves', 'rapport'], true); $n++) $candidate = mb_substr($base, 0, 26, 'UTF-8') . ' (' . $n . ')';
        $sheet = $book->createSheet()->setTitle($candidate);
        foreach ($headers as $i => $text) $sheet->setCellValueExplicit([$i + 1, 1], $text, DataType::TYPE_STRING);
        return $sheet;
    };
    $results = []; $rows = []; $matched = 0; $missingCount = 0; $missingEntries = [];
    if ($mode === 'mono') { $results['Resultat'] = $makeSheet('Resultat', array_column($layout, 'label')); $rows['Resultat'] = 2; }
    $missing = $book->createSheet()->setTitle('Non trouves');
    $missingHeaders = array_merge(['Feuille PARTIEL', 'Ligne PARTIEL', 'Motif'], array_column($pc['columns'], 'label'));
    foreach ($missingHeaders as $i => $h) $missing->setCellValueExplicit([$i + 1, 1], $h, DataType::TYPE_STRING);
    $nameMatching = extractionNormalize($gcols[$gkey]['name']) === 'NOMS' && extractionNormalize($pcols[$pkey]['name']) === 'NOMS';
    foreach ($pc['sheets'] as $info) {
        if ($pairSheets) $index = $scopedIndexes[$info['name']] ?? [];
        $sheet = $partial->getSheetByName($info['name']); $keyLetter = $info['columns'][$pkey] ?? null;
        if ($keyLetter === null) $warnings[] = 'PARTIEL « ' . $info['name'] . ' » : clé absente ; les lignes sont reprises dans Non trouves.';
        for ($row = $ph + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            if (!extractionHasData($sheet, $row, $info['columns'])) continue;
            $rawKey = $keyLetter === null ? '' : comparisonCellValue($sheet->getCell($keyLetter . $row));
            $key = comparisonKey($rawKey, $ignored);
            $entry = $key === '' ? null : ($index[$key] ?? null);
            if ($entry === null && $key !== '' && $nameMatching) {
                $tokens = preg_split('/ +/', comparisonKey($rawKey, $ignored, true), -1, PREG_SPLIT_NO_EMPTY); $candidates = [];
                foreach ($index as $candidate => $item) {
                    $parts = $item['tokens'];
                    if (count($parts) > count($tokens) && !array_diff($tokens, $parts)) $candidates[] = $item;
                    if (count($candidates) > 1) break;
                }
                if (count($candidates) === 1) $entry = $candidates[0];
            }
            if ($entry === null) {
                if ($options['append_missing'] ?? false) {
                    $group = $mode === 'mono' ? 'Resultat' : ($module === 'hybrid' ? $info['name'] : ($global->getSheetByName($info['name']) ? $info['name'] : ($global->getSheetCount() === 1 ? $global->getSheet(0)->getTitle() : null)));
                    if ($group !== null) $missingEntries[$group][] = ['sheet' => $sheet, 'map' => $info['columns'], 'row' => $row];
                }
                if (++$missingCount >= 1048576) throw new RuntimeException('Trop de lignes non trouvées pour une seule feuille Excel.');
                $destRow = $missingCount + 1;
                $missing->setCellValueExplicit('A' . $destRow, $info['name'], DataType::TYPE_STRING);
                $missing->setCellValue('B' . $destRow, $row);
                $missing->setCellValue('C' . $destRow, $keyLetter === null ? 'Colonne clé absente' : ($key === '' ? 'Clé vide' : 'Aucune correspondance'));
                foreach (array_keys($pcols) as $i => $id) if (isset($info['columns'][$id])) extractionCopyValue($sheet, $info['columns'][$id] . $row, $missing, Coordinate::stringFromColumnIndex($i + 4) . $destRow);
                continue;
            }
            $group = $mode === 'mono' ? 'Resultat' : ($module === 'hybrid' ? $info['name'] : $entry['sheet']->getTitle());
            if (!isset($results[$group])) { $results[$group] = $makeSheet($group, array_column($layout, 'label')); $rows[$group] = 2; }
            $destRow = $rows[$group]++;
            if ($destRow > 1048576) throw new RuntimeException('Le résultat dépasse la limite de lignes Excel. Choisissez une sortie multifeuilles.');
            foreach ($layout as $i => $column) {
                $from = $column['source'] === 'partiel' ? ['sheet' => $sheet, 'map' => $info['columns'], 'row' => $row] : $entry;
                if (isset($from['map'][$column['id']])) extractionCopyValue($from['sheet'], $from['map'][$column['id']] . $from['row'], $results[$group], Coordinate::stringFromColumnIndex($i + 1) . $destRow);
            }
            $matched++;
        }
    }
    $filterEnds = $rows;
    if ($missingEntries) {
        foreach ($missingEntries as $group => $entries) {
            if (!isset($results[$group])) { $results[$group] = $makeSheet($group, array_column($layout, 'label')); $rows[$group] = 2; $filterEnds[$group] = 2; }
            $target = $results[$group];
            $destRow = $rows[$group];
            if ($destRow + count($entries) + 1 > 1048576) throw new RuntimeException('Trop de lignes pour ajouter les non trouvés en bas de cette feuille. Désactivez cette option.');
            $last = Coordinate::stringFromColumnIndex(count($layout));
            $target->getStyle('A' . $destRow . ':' . $last . $destRow)->getFill()->setFillType('solid')->getStartColor()->setARGB('FF000000');
            $destRow++;
            foreach ($layout as $i => $column) $target->setCellValueExplicit([$i + 1, $destRow], $column['label'], DataType::TYPE_STRING);
            $target->getStyle('A' . $destRow . ':' . $last . $destRow)->applyFromArray(['font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1A093E']]]);
            foreach ($entries as $entry) {
                $destRow++;
                foreach ($layout as $i => $column) if ($column['source'] === 'partiel' && isset($entry['map'][$column['id']])) extractionCopyValue($entry['sheet'], $entry['map'][$column['id']] . $entry['row'], $target, Coordinate::stringFromColumnIndex($i + 1) . $destRow);
            }
        }
        $warnings[] = $module === 'hybrid' ? 'Chaque résultat reprend uniquement les non trouvés de sa feuille PARTIEL ; la sortie unique les réunit.' : 'Les lignes non trouvées sont ajoutées uniquement à la feuille correspondant au nom de leur feuille PARTIEL (ou à la feuille unique). Les autres restent dans Non trouves.';
    }
    $report = $book->createSheet()->setTitle('Rapport');
    $lines = array_merge(['Clé GLOBAL : ' . $gcols[$gkey]['label'], 'Clé PARTIEL : ' . $pcols[$pkey]['label'], count($selected) . ' colonne(s) GLOBAL ajoutée(s).', $matched . ' ligne(s) trouvée(s), ' . $missingCount . ' ligne(s) non trouvée(s).', $before === '' ? 'Ajout après les colonnes PARTIEL.' : 'Ajout avant « ' . $pcols[$before]['label'] . ' ».'], $warnings);
    $report->setCellValue('A1', 'Compte rendu de l’extraction');
    foreach ($lines as $i => $line) $report->setCellValueExplicit('A' . ($i + 2), $line, DataType::TYPE_STRING);
    foreach ($book->getWorksheetIterator() as $sheet) {
        $last = $sheet->getHighestDataColumn();
        $sheet->getStyle('A1:' . $last . '1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:' . $last . '1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1A093E');
        $sheet->freezePane('A2');
        if ($sheet !== $report) $sheet->setAutoFilter('A1:' . $last . $sheet->getHighestDataRow());
        for ($i = 1; $i <= Coordinate::columnIndexFromString($last); $i++) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }
    $position = 0;
    foreach ($results as $group => $sheet) {
        if ($missingEntries) $sheet->setAutoFilter('A1:' . Coordinate::stringFromColumnIndex(count($layout)) . max(1, $filterEnds[$group] - 1));
        $book->setIndexByName($sheet->getTitle(), $position++);
    }
    $book->setActiveSheetIndex(0);
    return ['book' => $book, 'matched' => $matched, 'missing' => $missingCount, 'warnings' => $warnings];
}
