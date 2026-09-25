<?php
declare(strict_types=1);

/** Les éléments sont littéraux : aucun caractère n'est interprété comme une expression. */
function comparisonIgnoredItems(mixed $input): array
{
    if (!is_string($input) || mb_strlen($input) > 2000) throw new RuntimeException('Saisissez les éléments à ignorer, séparés par des virgules (2 000 caractères maximum).');
    $items = [];
    foreach (explode(',', $input) as $item) {
        $item = comparisonKey($item);
        if ($item !== '') $items[$item] = '';
    }
    return $items;
}

function comparisonKey(string $value, array $ignored = [], bool $keepSpaces = false): string
{
    $value = mb_strtoupper($value, 'UTF-8');
    $value = (string) preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', $keepSpaces ? ' ' : '', $value);
    return trim($ignored ? strtr($value, $ignored) : $value);
}

/** Évite les décimales parasites des nombres Excel tout en gardant les formats d'identifiants. */
function comparisonCellValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): string
{
    $value = $cell->getDataType() === 'f' ? $cell->getCalculatedValue() : $cell->getValue();
    if (is_float($value) && strcasecmp($cell->getStyle()->getNumberFormat()->getFormatCode(), 'General') === 0) return sprintf('%.15g', $value);
    return $cell->getFormattedValue();
}
