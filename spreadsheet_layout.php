<?php
declare(strict_types=1);

/** Ajuste les cellules présentes uniquement, sans recalculer les formules du classeur. */
function fitSpreadsheetColumns(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, float $limit = 48): void
{
    foreach ($book->getWorksheetIterator() as $sheet) fitWorksheetColumns($sheet, $limit);
}

function fitWorksheetColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, float $limit = 48): void
{
    $merged = [];
    foreach ($sheet->getMergeCells() as $range) {
        [$start, $end] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
        if ($end[0] > $start[0]) $merged[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($start[0]).$start[1]] = true;
    }
    $widths = []; $long = [];
    foreach ($sheet->getCellCollection()->getCoordinates() as $address) {
        if (isset($merged[$address])) continue; // Un titre sur plusieurs colonnes ne doit pas élargir la première.
        $cell = $sheet->getCell($address); $value = $cell->getValue();
        if ($cell->getDataType() === 'f') $value = $cell->getOldCalculatedValue();
        if ($value === null || $value === '') continue;
        if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $value = $value->getPlainText();
        $style = $cell->getStyle();
        $format = $style->getNumberFormat()->getFormatCode();
        $text = is_float($value) && strcasecmp($format, 'General') === 0
            ? sprintf('%.15g', $value)
            : (string) \PhpOffice\PhpSpreadsheet\Style\NumberFormat::toFormattedString($value, $format);
        $lines = preg_split('/\R/u', $text) ?: [''];
        $length = max(array_map(static fn($line) => mb_strwidth($line, 'UTF-8'), $lines));
        $factor = max(0.7, $style->getFont()->getSize() / 11) * ($style->getFont()->getBold() ? 1.08 : 1);
        $needed = $length * $factor + 1.5 + 2 * $style->getAlignment()->getIndent();
        $col = $cell->getColumn();
        $widths[$col] = max($widths[$col] ?? 3, min($limit, $needed));
        if ($needed > $limit || count($lines) > 1) $long[] = [$address, $lines, $factor];
    }
    foreach ($widths as $col => $width) $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth(round($width, 1));
    foreach ($long as [$address, $lines, $factor]) {
        $cell = $sheet->getCell($address);
        $style = $cell->getStyle(); $style->getAlignment()->setWrapText(true);
        $count = 0;
        foreach ($lines as $line) $count += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') * $factor / max(1, ($widths[$cell->getColumn()] ?? $limit) - 1.5)));
        $row = $sheet->getRowDimension($cell->getRow());
        $row->setRowHeight(max($row->getRowHeight(), min(409, $count * ($style->getFont()->getSize() + 4) + 3)));
    }
}
