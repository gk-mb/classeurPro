<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\ReferenceHelper;

function enrichShiftFormula(string $formula, string $before, string $targetSheet, string $formulaSheet, int $count = 1): string
{
    return enrichShiftReferences($formula, $before . '1', $targetSheet, $formulaSheet, $count, 0);
}

function enrichShiftReferences(string $formula, string $beforeCell, string $targetSheet, string $formulaSheet, int $columns, int $rows): string
{
    $helper = ReferenceHelper::getInstance();
    if ($targetSheet === $formulaSheet) return $helper->updateFormulaReferences($formula, $beforeCell, $columns, $rows, $targetSheet, true);
    // Sur les autres feuilles, seules les références explicitement qualifiées se décalent.
    $blocks = explode('"', $formula);
    $sheet = "(?:'" . preg_quote(str_replace("'", "''", $targetSheet), '~') . "'|" . preg_quote($targetSheet, '~') . ')!';
    $cell = '\\$?[A-Z]{1,3}\\$?[1-9][0-9]*';
    $range = '(?:' . $cell . '(?::' . $cell . ')?|\\$?[A-Z]{1,3}:\\$?[A-Z]{1,3}|\\$?[1-9][0-9]*:\\$?[1-9][0-9]*)';
    foreach ($blocks as $i => $block) if ($i % 2 === 0) $blocks[$i] = preg_replace_callback('~(?<![A-Z0-9_\]\\\'])' . $sheet . $range . '(?![A-Z0-9_])~iu', static fn ($m) => $helper->updateFormulaReferences($m[0], $beforeCell, $columns, $rows, $targetSheet, true), $block);
    return implode('"', $blocks);
}

/** Préserve les formules OOXML d'origine, notamment partagées et liées à un autre classeur.
 * Le passage par le lecteur/rédacteur peut perdre leurs attributs ou leurs liens.
 * Les valeurs ajoutées, styles et autres changements restent ceux du fichier généré.
 */
function enrichPreserveFormulas(string $sourcePath, string $outputPath, string $sheetName, string $before = '', int $count = 1, array $insertions = [], array $deleted = []): void
{
    if (!$insertions && $before !== '') $insertions[$sheetName] = ['before' => $before, 'count' => $count];
    $changed = (bool) ($insertions || $deleted);
    $transform = static function (string $formula, string $name) use ($insertions, $deleted): string {
        foreach ($deleted as $target => $rows) foreach ($rows as $row) $formula = enrichShiftReferences($formula, 'A' . ($row + 1), $target, $name, 0, -1);
        foreach ($insertions as $target => $insertion) if ($insertion['before'] !== '') $formula = enrichShiftFormula($formula, $insertion['before'], $target, $name, $insertion['count']);
        return $formula;
    };
    $source = new ZipArchive(); $output = new ZipArchive();
    if ($source->open($sourcePath) !== true) throw new RuntimeException('Impossible de vérifier les formules du fichier origine.');
    if ($output->open($outputPath) !== true) { $source->close(); throw new RuntimeException('Impossible de vérifier le fichier généré.'); }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $parse = static function (string|false $xml): DOMDocument {
        $dom = new DOMDocument();
        if ($xml === false || str_contains($xml, '<!DOCTYPE') || !@$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('La structure du classeur est illisible. Enregistrez le fichier origine à nouveau dans Excel.');
        return $dom;
    };
    $sheets = static function (ZipArchive $zip) use ($parse, $ns, $relNs): array {
        $rels = $parse($zip->getFromName('xl/_rels/workbook.xml.rels')); $paths = [];
        foreach ($rels->documentElement->childNodes as $r) if ($r instanceof DOMElement) $paths[$r->getAttribute('Id')] = $r->getAttribute('Target');
        $book = $parse($zip->getFromName('xl/workbook.xml')); $result = [];
        foreach ($book->getElementsByTagNameNS($ns, 'sheet') as $s) {
            $path = $paths[$s->getAttributeNS($relNs, 'id')] ?? '';
            $result[$s->getAttribute('name')] = str_starts_with($path, '/') ? ltrim($path, '/') : 'xl/' . $path;
        }
        return $result;
    };
    try {
        $oldSheets = $sheets($source); $newSheets = $sheets($output);
        $helper = ReferenceHelper::getInstance();
        foreach ($oldSheets as $name => $oldPath) {
            if (!isset($newSheets[$name])) throw new RuntimeException('Une feuille d’origine manque dans le résultat.');
            $oldDom = $parse($source->getFromName($oldPath));
            $newDom = $parse($output->getFromName($newSheets[$name]));
            $cells = [];
            foreach ($newDom->getElementsByTagNameNS($ns, 'c') as $cell) $cells[$cell->getAttribute('r')] = $cell;
            $shared = [];
            if ($changed) foreach ($oldDom->getElementsByTagNameNS($ns, 'c') as $cell) {
                $f = $cell->getElementsByTagNameNS($ns, 'f')->item(0);
                if ($f && $f->getAttribute('t') === 'shared' && $f->textContent !== '') $shared[$f->getAttribute('si')] = ['address' => $cell->getAttribute('r'), 'text' => $f->textContent];
            }
            foreach ($oldDom->getElementsByTagNameNS($ns, 'c') as $oldCell) {
                $oldFormula = $oldCell->getElementsByTagNameNS($ns, 'f')->item(0);
                if (!$oldFormula && !$oldCell->hasAttribute('cm') && !$oldCell->hasAttribute('vm')) continue;
                $address = $oldCell->getAttribute('r');
                [$col, $row] = Coordinate::coordinateFromString($address); $row = (int) $row;
                if (in_array($row, $deleted[$name] ?? [], true)) continue;
                $row -= count(array_filter($deleted[$name] ?? [], static fn ($r) => $r < $row));
                $insertion = $insertions[$name] ?? null;
                if ($insertion && $insertion['before'] !== '' && Coordinate::columnIndexFromString($col) >= Coordinate::columnIndexFromString($insertion['before'])) $col = Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($col) + $insertion['count']);
                $address = $col . $row;
                if (!isset($cells[$address])) throw new RuntimeException('Une cellule contenant une formule manque dans le résultat.');
                $cell = $cells[$address];
                foreach (['cm', 'vm'] as $attribute) {
                    $cell->removeAttribute($attribute);
                    if ($oldCell->hasAttribute($attribute)) $cell->setAttribute($attribute, $oldCell->getAttribute($attribute));
                }
                if (!$oldFormula) continue;
                foreach (iterator_to_array($cell->childNodes) as $child) $cell->removeChild($child);
                $formula = $newDom->importNode($oldFormula, true);
                if ($changed) {
                    // Une insertion peut casser le motif relatif d'une plage partagée.
                    // Chaque cellule reçoit alors sa propre formule, calculée avant décalage.
                    if ($formula->getAttribute('t') === 'shared') {
                        $master = $shared[$formula->getAttribute('si')] ?? null;
                        if (!$master) throw new RuntimeException('Une formule partagée est incomplète dans l’origine. Enregistrez le fichier à nouveau dans Excel.');
                        [$mc, $mr] = Coordinate::indexesFromString($master['address']);
                        [$cc, $cr] = Coordinate::indexesFromString($oldCell->getAttribute('r'));
                        $blocks = explode('"', $master['text']);
                        foreach ($blocks as $i => $block) if ($i % 2 === 0) $blocks[$i] = $helper->updateFormulaReferencesAnyWorksheet($block, $cc - $mc, $cr - $mr);
                        $formula->textContent = implode('"', $blocks);
                        foreach (['t', 'si', 'ref'] as $attribute) $formula->removeAttribute($attribute);
                    }
                    // Les références non qualifiées appartiennent à la feuille de la formule.
                    $text = $formula->textContent;
                    if ($text !== '') {
                        $text = $transform('=' . $text, $name);
                        $formula->textContent = substr($text, 1);
                    }
                    foreach (['ref', 'r1', 'r2'] as $attribute) {
                        if ($formula->hasAttribute($attribute)) {
                            $range = $formula->getAttribute($attribute);
                            $formula->setAttribute($attribute, substr($transform('=' . $range, $name), 1));
                        }
                    }
                }
                // Le type de résultat appartient à la formule d'origine, pas au texte de la formule.
                $cell->removeAttribute('t');
                if ($oldCell->hasAttribute('t')) $cell->setAttribute('t', $oldCell->getAttribute('t'));
                $cell->appendChild($formula);
                if (!$changed) {
                    $cached = $oldCell->getElementsByTagNameNS($ns, 'v')->item(0);
                    if ($cached) $cell->appendChild($newDom->importNode($cached, true));
                }
            }
            if (!$output->addFromString($newSheets[$name], $newDom->saveXML())) throw new RuntimeException('Impossible de conserver les formules.');
        }
        // Les formules [1]Feuille!A1 doivent garder la déclaration du classeur lié.
        $oldBook = $parse($source->getFromName('xl/workbook.xml'));
        $newBook = $parse($output->getFromName('xl/workbook.xml'));
        $oldRels = $parse($source->getFromName('xl/_rels/workbook.xml.rels'));
        $newRels = $parse($output->getFromName('xl/_rels/workbook.xml.rels'));
        $ids = []; $remap = [];
        $hasSourceMetadata = false;
        foreach ($oldRels->documentElement->childNodes as $r) if ($r instanceof DOMElement && str_ends_with($r->getAttribute('Type'), '/sheetMetadata')) $hasSourceMetadata = true;
        foreach (iterator_to_array($newRels->documentElement->childNodes) as $r) if ($r instanceof DOMElement && (str_ends_with($r->getAttribute('Type'), '/externalLink') || ($hasSourceMetadata && str_ends_with($r->getAttribute('Type'), '/sheetMetadata')))) $newRels->documentElement->removeChild($r);
        foreach ($newRels->documentElement->childNodes as $r) if ($r instanceof DOMElement) $ids[] = $r->getAttribute('Id');
        foreach ($oldRels->documentElement->childNodes as $r) {
            if (!$r instanceof DOMElement || !preg_match('~/(externalLink|sheetMetadata)$~', $r->getAttribute('Type'))) continue;
            $id = 'rIdPreserved' . (count($remap) + 1); while (in_array($id, $ids, true)) $id .= 'x'; $ids[] = $id;
            $remap[$r->getAttribute('Id')] = $id;
            $copy = $newRels->importNode($r, true); $copy->setAttribute('Id', $id); $newRels->documentElement->appendChild($copy);
        }
        foreach (iterator_to_array($newBook->getElementsByTagNameNS($ns, 'externalReferences')) as $node) $node->parentNode->removeChild($node);
        $oldReferences = $oldBook->getElementsByTagNameNS($ns, 'externalReferences')->item(0);
        if ($oldReferences) {
            $copy = $newBook->importNode($oldReferences, true);
            foreach ($copy->childNodes as $r) if ($r instanceof DOMElement) {
                $oldId = $r->getAttributeNS($relNs, 'id');
                if (!isset($remap[$oldId])) throw new RuntimeException('Un lien de formule est incomplet dans l’origine. Enregistrez le fichier à nouveau dans Excel.');
                $r->setAttributeNS($relNs, 'r:id', $remap[$oldId]);
            }
            $anchor = null;
            foreach ($newBook->documentElement->childNodes as $node) if (in_array($node->localName, ['definedNames', 'calcPr', 'oleSize', 'customWorkbookViews', 'pivotCaches', 'smartTagPr', 'smartTagTypes', 'webPublishing', 'fileRecoveryPr', 'webPublishObjects', 'extLst'], true)) { $anchor = $node; break; }
            $newBook->documentElement->insertBefore($copy, $anchor);
        }
        $parts = [];
        for ($i = 0; $i < $source->numFiles; $i++) {
            $path = $source->getNameIndex($i);
            if (str_starts_with($path, 'xl/externalLinks/') || $path === 'xl/metadata.xml') {
                $output->addFromString($path, $source->getFromIndex($i)); $parts[] = '/' . $path;
            }
        }
        $oldTypes = $parse($source->getFromName('[Content_Types].xml')); $newTypes = $parse($output->getFromName('[Content_Types].xml'));
        foreach ($oldTypes->documentElement->childNodes as $type) if ($type instanceof DOMElement && in_array($type->getAttribute('PartName'), $parts, true)) {
            foreach (iterator_to_array($newTypes->documentElement->childNodes) as $existing) if ($existing instanceof DOMElement && $existing->getAttribute('PartName') === $type->getAttribute('PartName')) $newTypes->documentElement->removeChild($existing);
            $newTypes->documentElement->appendChild($newTypes->importNode($type, true));
        }
        $output->addFromString('[Content_Types].xml', $newTypes->saveXML());
        $output->addFromString('xl/_rels/workbook.xml.rels', $newRels->saveXML());
        $output->addFromString('xl/workbook.xml', $newBook->saveXML());
    } finally { $source->close(); $output->close(); }
}
