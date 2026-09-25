<?php
declare(strict_types=1);
session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ajout multi — Classeur Pro</title><link rel="stylesheet" href="assets/ui.css?v=7"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
<nav class="breadcrumb"><a href="index.php">Accueil</a><span>/</span><span>Ajout multi</span></nav>
<section class="hero"><h1>Ajout multi</h1><p class="intro">Complétez plusieurs feuilles avec les informations du complément.</p></section>
<section class="card extraction-card"><form id="multi-form" action="ajout_multi_action.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
<div class="enrich-grid">
<?php foreach (['origin' => 'Origine', 'extra' => 'Complément'] as $id => $label): ?>
<fieldset class="enrich-panel"><legend><?= $label ?></legend>
<div class="upload-box"><label for="<?= $id ?>">Fichier <?= $label ?></label><input id="<?= $id ?>" name="<?= $id ?>" type="file" accept=".xls,.xlsx" required><p class="help-copy">Excel .xls ou .xlsx · 25 Mo maximum</p></div>
<p id="<?= $id ?>_status" class="status-box" role="status" aria-live="polite">En attente du fichier.</p>
<label class="field-label" for="<?= $id ?>_key">Clé à utiliser</label><select id="<?= $id ?>_key" name="<?= $id ?>_key" disabled required><option value="">Importez le fichier</option></select>
</fieldset><?php endforeach; ?></div>
<?php require __DIR__ . '/duplicates_panel.php'; ?>
<details class="help-example" id="multi-options"><summary>Plus d’options</summary>
<p class="help-copy">Par défaut : toutes les feuilles et colonnes, recherche dans tout le complément, ajout à la fin et sortie en plusieurs feuilles.</p>
<div class="enrich-grid"><?php foreach (['origin'=>'Origine','extra'=>'Complément'] as $id=>$label): ?><fieldset class="enrich-panel"><legend><?= $label ?></legend>
<label class="field-label" for="<?= $id ?>_header">Ligne des noms de colonnes</label><input id="<?= $id ?>_header" name="<?= $id ?>_header" type="number" min="1" max="1048576" value="1" required>
<label class="field-label" for="<?= $id ?>_sheets">Feuilles à utiliser</label><select id="<?= $id ?>_sheets" multiple size="5" disabled required></select><p class="help-copy">Toutes sont choisies. Cochez les éléments à garder, décochez les autres.</p>
<h3>Colonne clé par feuille</h3><p class="help-copy">La position choisie est proposée partout. Ajustez-la si les colonnes changent de place. Leurs noms n’ont pas besoin d’être identiques.</p><div id="<?= $id ?>_keys"></div>
</fieldset><?php endforeach; ?></div>
<label class="field-label" for="add_columns">Colonnes à ajouter</label><select id="add_columns" multiple size="6" disabled required></select><button id="multi-all" class="secondary-button" type="button">Tout sélectionner</button>
<label class="field-label" for="match_sheets">Associer les feuilles par leur nom ?</label><select id="match_sheets" name="match_sheets"><option value="0">Non : chercher dans toutes les feuilles du complément choisies</option><option value="1">Oui : associer uniquement les feuilles de même nom</option></select>
<p class="help-copy">En cas d’association, les feuilles sans correspondante ne sont pas traitées. Les feuilles d’origine non traitées restent inchangées dans la sortie multiple.</p>


<?php require __DIR__ . '/comparison_options.php'; ?>
<label class="field-label" for="output_mode">Organisation du résultat</label><select id="output_mode" name="output_mode"><option value="multi">Plusieurs feuilles (structure d’origine)</option><option value="mono">Une seule feuille de résultats</option></select>
<p class="help-copy">La sortie unique réunit les feuilles traitées, associe les colonnes par leur nom et reprend les résultats des formules. Les feuilles de suivi restent séparées.</p>
<label class="field-label" for="insert_before">Où placer les colonnes ajoutées ?</label><select id="insert_before" name="insert_before"><option value="">Après toutes les colonnes</option></select></details>
<p id="multi-status" class="status-box" role="status" aria-live="polite">Importez les fichiers, choisissez les clés puis vérifiez les doublons.</p>
<div class="enrich-actions"><a href="guide.php#ajout-multi">Consulter le guide</a><button id="multi-submit" type="submit" disabled>Compléter et télécharger</button></div>
<noscript>Activez JavaScript pour choisir les feuilles et vérifier les doublons.</noscript>
</form></section></main><script src="assets/app.js"></script><script src="assets/duplicates.js"></script><script src="assets/ajout_multi.js"></script></body></html>
