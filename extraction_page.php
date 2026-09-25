<?php
if (!isset($extractionModule)) { http_response_code(404); exit; }
$isMono = $extractionModule === 'mono';
$isHybrid = $extractionModule === 'hybrid';
$title = $isHybrid ? 'Extraction hybride' : ($isMono ? 'Extraction mono / mono' : 'Extraction mono / multi');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $title ?> — Classeur Pro</title><link rel="stylesheet" href="assets/ui.css?v=7"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
    <nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="index.php">Extraction</a><span>/</span><span class="current"><?= $isHybrid ? 'Hybride' : ($isMono ? 'Mono / mono' : 'Mono / multi') ?></span></nav>
    <section class="hero extraction-hero"><h1><?= $title ?></h1><p class="intro"><?= $isHybrid ? 'Choisissez les feuilles et leur façon de se correspondre.' : ($isMono ? 'Une seule feuille dans chacun des deux fichiers.' : 'Une ou plusieurs feuilles par fichier : mono → multi, multi → mono ou multi → multi.') ?></p></section>
    <section class="card extraction-card">
        <?php if ($isMono): ?><p class="help-copy">Un fichier contient plusieurs feuilles ? <a href="index.php">Utiliser l’extraction mono / multi</a>.</p><?php endif; ?>
        <form id="extraction-form" action="extraire.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="extraction_module" value="<?= $extractionModule ?>">
            <div class="enrich-grid">
            <?php foreach (['global' => 'GLOBAL', 'partiel' => 'PARTIEL'] as $id => $label): ?>
                <fieldset class="enrich-panel"><legend><?= $label ?></legend>
                    <p class="panel-intro"><?= $id === 'global' ? 'La base complète de référence.' : 'La liste à retrouver dans GLOBAL.' ?></p>
                    <div class="upload-box"><label for="<?= $id ?>">Choisir le fichier <?= $label ?></label><input id="<?= $id ?>" name="<?= $id ?>" type="file" accept=".xlsx,.xls" required><p class="help-copy">Excel .xlsx ou .xls · 25 Mo maximum</p><p id="<?= $id ?>_filename" class="file-name" hidden></p></div>
                    <p class="status-box" id="<?= $id ?>_status" role="status" aria-live="polite">En attente du fichier.</p>
                    <label class="field-label" for="<?= $id ?>_key">Clé à utiliser dans <?= $label ?></label>
                    <select id="<?= $id ?>_key" name="<?= $id ?>_key" required disabled><option value="">Importez d’abord le fichier</option></select>
                    <details class="help-example"><summary>Plus d’options</summary>
                    <div class="header-config"><label for="<?= $id ?>_header">Ligne des noms de colonnes</label><input id="<?= $id ?>_header" name="<?= $id ?>_header" type="number" value="1" min="1" max="1048576" required aria-describedby="<?= $id ?>_help"></div>
                    <p class="help-copy" id="<?= $id ?>_help">1 par défaut : la ligne contenant « Noms », « Matricule », etc.</p>
                    <?php if ($isHybrid): ?><label class="field-label" for="<?= $id ?>_sheets">Feuilles à utiliser</label><select id="<?= $id ?>_sheets" multiple size="5" disabled required></select><p class="help-copy">Toutes sont choisies au départ. Cochez les éléments à garder, décochez les autres.</p><?php endif; ?>
                    </details>
                </fieldset>
            <?php endforeach; ?>
            </div>
            <details class="extraction-advanced" id="extraction-advanced"><summary>Plus d’options <span>Résultat, colonnes et emplacement</span></summary>
            <?php if ($isMono): ?><input type="hidden" name="output_mode" value="mono"><?php else: ?>
            <div class="extraction-output"><label class="field-label" for="output_mode">Organisation du résultat</label><select id="output_mode" name="output_mode"><option value="multi" selected><?= $isHybrid ? 'Une feuille de résultats par feuille PARTIEL' : 'Une feuille de résultats par feuille GLOBAL correspondante' ?></option><option value="mono">Une seule feuille de résultats</option></select></div>
            <?php endif; ?>
            <?php if ($isHybrid): ?><label class="field-label" for="match_sheets">Associer les feuilles par leur nom ?</label><select id="match_sheets" name="match_sheets"><option value="0">Non : chercher dans toutes les feuilles GLOBAL choisies</option><option value="1">Oui : chercher uniquement entre feuilles de même nom</option></select><p class="help-copy">En cas d’association, les feuilles sans correspondante sont ignorées. En sortie multiple, chaque feuille PARTIEL a son résultat.</p><?php endif; ?>
            <?php require __DIR__ . '/comparison_options.php'; ?>
            <label class="field-label" for="append_missing">Lignes non trouvées</label><select id="append_missing" name="append_missing"><option value="0">Les garder uniquement dans « Non trouves »</option><option value="1">Les ajouter aussi sous les lignes trouvées de chaque feuille</option></select>
            <p class="help-copy"><?= $isHybrid ? 'Chaque résultat reçoit seulement les non trouvés de sa feuille PARTIEL, après une ligne noire et les en-têtes. La sortie unique les réunit. Les feuilles ignorées par l’association ne sont pas traitées.' : 'Chaque feuille reçoit uniquement les non trouvés de la feuille PARTIEL de même nom, après une ligne noire et les en-têtes. Avec une seule feuille de référence ou une sortie unique, ils sont réunis dans ce résultat. Sans feuille correspondante, ils restent uniquement dans « Non trouves ».' ?></p>
            <div class="extraction-options" aria-labelledby="columns-title"><div class="card-head"><div><h2 id="columns-title">Colonnes à ajouter au résultat</h2><p>Par défaut, toutes les colonnes GLOBAL sont ajoutées à la fin. Les colonnes PARTIEL sont conservées.</p></div></div>
                <div class="column-toolbar"><span id="columns-count">En attente de GLOBAL</span><div><button type="button" class="secondary-button" id="select-all" disabled>Tout sélectionner</button><button type="button" class="secondary-button" id="select-none" disabled>Tout désélectionner</button></div></div>
                <div id="global-columns" class="column-picker" role="group" aria-labelledby="columns-title"><p class="help-copy">Les colonnes du fichier GLOBAL apparaîtront ici.</p></div>
                <p class="help-copy">Une colonne présente dans les deux fichiers est conservée des deux côtés : l’ajout porte la mention « GLOBAL ». Sans colonne cochée, le résultat contient uniquement les colonnes de PARTIEL pour les lignes retrouvées.</p>
                <label class="field-label" for="insert_before">Où placer la ou les colonnes ajoutées ?</label>
                <select id="insert_before" name="insert_before" disabled><option value="">Après toutes les colonnes PARTIEL (par défaut)</option></select>
                <p class="help-copy">Les colonnes cochées sont placées ensemble, dans l’ordre de la liste ci-dessus.</p>
            </div>
            <section class="mapping-summary"><h2>Votre extraction en un coup d’œil</h2><p id="extraction-summary" aria-live="polite">Choisissez les fichiers et leurs clés pour voir le récapitulatif.</p><div id="column-preview" class="column-preview" aria-label="Ordre des colonnes dans le résultat"></div></section>
            <details class="help-example"><summary>Correspondances, feuilles incomplètes et lignes non trouvées</summary><p>Les majuscules et les espaces sont ignorés. Quand les deux clés s’appellent « Noms », un nom incomplet peut correspondre à un nom plus complet s’il n’y a qu’un seul candidat.</p><p>Si une clé se répète dans GLOBAL, sa première occurrence est utilisée et le compte rendu le signale. Les lignes PARTIEL non trouvées, les clés vides et les lignes d’une feuille sans la clé choisie sont reprises dans « Non trouves ».</p><p>En multifeuilles, les colonnes sont réunies par leur nom même si leur position varie. Une colonne absente d’une feuille donne une case vide. Les noms de colonnes répétés sont distingués par leur occurrence. La feuille « Rapport » récapitule ces situations.</p></details>
            </details>
            <p id="extraction-status" class="status-box" role="status" aria-live="polite">Importez les deux fichiers pour commencer.</p>
            <div class="enrich-actions"><p class="hint">Résultat Excel (.xlsx) · Vos fichiers sources restent inchangés.<br><a href="guide.php#extraire">Consulter le guide de l’extraction</a></p><button type="submit" id="extraction-submit" disabled>Générer et télécharger</button></div>
            <noscript>Activez JavaScript pour charger les colonnes et choisir les clés.</noscript>
        </form>
    </section>
</main><script src="assets/app.js"></script><script src="assets/extraction.js"></script></body></html>
