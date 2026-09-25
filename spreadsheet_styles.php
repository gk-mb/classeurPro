<?php
declare(strict_types=1);
require_once __DIR__ . '/spreadsheet_layout.php';

/** Réutilise les styles du classeur au lieu de les reconstruire pour chaque cellule. */
function copySpreadsheetStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $source, string $from, \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $target, string $to): void
{
    static $cache;
    $cache ??= new WeakMap();
    $sourceBook = $source->getParent(); $targetBook = $target->getParent();
    $styleIndex = $source->getCell($from)->getXfIndex();
    if ($sourceBook === $targetBook) { $target->getCell($to)->setXfIndex($styleIndex); return; }
    $sources = $cache[$targetBook] ??= new WeakMap();
    $styles = $sources[$sourceBook] ?? [];
    if (!isset($styles[$styleIndex])) {
        $style = $sourceBook->getCellXfByIndex($styleIndex);
        $existing = $targetBook->getCellXfByHashCode($style->getHashCode());
        if (!$existing) { $existing = clone $style; $targetBook->addCellXf($existing); }
        $styles[$styleIndex] = $existing->getIndex();
        $sources[$sourceBook] = $styles;
    }
    $target->getCell($to)->setXfIndex($styles[$styleIndex]);
}
