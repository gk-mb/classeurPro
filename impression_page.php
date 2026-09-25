<?php
if (!isset($printModule)) { http_response_code(404); exit; }
$mono=$printModule==='mono';
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Préparer l’impression — Classeur Pro</title><link rel="stylesheet" href="assets/ui.css?v=7"></head>
<body><?php require __DIR__.'/ui_nav.php'; ?><main id="main-content" tabindex="-1">
<nav class="breadcrumb"><a href="index.php">Accueil</a><span>/</span><span>Impression <?= $mono ? 'monofeuille' : 'multifeuilles' ?></span></nav>
<section class="hero"><h1>Préparer l’impression</h1><p class="intro"><?= $mono ? 'Mettez votre tableau en forme pour l’imprimer.' : 'Choisissez les feuilles et les colonnes à mettre en forme.' ?></p></section>
<section class="card"><form id="print-form" action="impression_action.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'],ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="module" value="<?= $printModule ?>">
<div class="upload-box"><label for="excel">Fichier Excel à préparer</label><input id="excel" name="excel" type="file" accept=".xls,.xlsx" required><p class="help-copy">Excel .xls ou .xlsx · 25 Mo maximum</p></div>
<details class="help-example" id="print-options"><summary>Plus d’options</summary><p class="help-copy">Toutes les feuilles et colonnes sont préparées par défaut, au format A4.</p>
<label class="field-label" for="header">Ligne des noms de colonnes</label><input id="header" name="header" type="number" value="1" min="1" max="1048576" required><p class="help-copy">La première ligne est proposée par défaut.</p>

<div <?= $mono ? 'hidden' : '' ?>><label class="field-label" for="print-sheets">Feuilles à mettre en forme</label><select id="print-sheets" multiple size="5" disabled></select><p class="help-copy">Toutes sont choisies au départ. Cochez les éléments à garder, décochez les autres. Les autres gardent leur présentation ; toutes les feuilles du résultat reçoivent une zone d’impression complète.</p></div>
<h2>Colonnes à mettre en forme</h2><p class="help-copy">Le choix concerne les bordures, la largeur des colonnes et la hauteur des lignes. Toutes les données restent visibles et incluses dans la zone d’impression ; aucune colonne n’est supprimée.</p>
<div id="print-columns"></div>

<label class="field-label" for="repeat_header">Répéter les en-têtes au début de chaque page ?</label><select id="repeat_header" name="repeat_header"><option value="1">Oui (par défaut)</option><option value="0">Non</option></select>
<label class="field-label" for="paper">Format du papier</label><select id="paper" name="paper"><option value="A4">A4</option><option value="A3">A3</option></select>
<label class="field-label" for="orientation">Sens des pages</label><select id="orientation" name="orientation"><option value="auto">Automatique selon le nombre de colonnes</option><option value="portrait">Portrait (vertical)</option><option value="landscape">Paysage (horizontal)</option></select>
<p class="help-copy">Le tableau tient sur une page en largeur et sur autant de pages que nécessaire en hauteur.</p></details>
<p id="file-status" class="status-box" role="status" aria-live="polite">Choisissez le fichier pour afficher ses colonnes.</p>
<p id="print-status" class="status-box" role="status" aria-live="polite">Le résultat sera un nouveau fichier Excel prêt à imprimer.</p>
<div class="enrich-actions"><a href="guide.php#impression">Consulter le guide</a><button id="print-submit" type="submit" disabled>Préparer et télécharger</button></div>
<noscript>Activez JavaScript pour choisir les colonnes à préparer.</noscript>
</form></section></main><script src="assets/app.js"></script><script src="assets/impression.js"></script></body></html>
