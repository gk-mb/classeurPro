<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrichissement par clé</title>
    <style>
        :root { --navy:#102a43; --blue:#1f6feb; --blue-soft:#eaf2ff; --ink:#172b4d; --muted:#61758a; --line:#dce5ef; --bg:#f5f8fc; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--bg); color:var(--ink); font:15px/1.5 Inter,"Segoe UI",Arial,sans-serif; }
        .app-header { position:fixed; z-index:12; top:0; right:0; left:0; display:flex; align-items:center; height:104px; padding:10px 24px; border-bottom:1px solid var(--line); background:#fff; box-shadow:0 2px 10px rgba(22,52,83,.05); }
        .app-header img { width:220px; height:80px; object-fit:contain; object-position:left center; }
        .app-title { position:absolute; left:50%; margin:0; transform:translateX(-50%); color:var(--navy); font-size:22px; font-weight:800; letter-spacing:.02em; }
        .sidebar { position:fixed; z-index:3; top:104px; right:auto; bottom:0; left:0; width:280px; padding:24px 16px; color:#d9e7f5; background:var(--navy); transition:transform .25s ease; }
        .brand { display:flex; align-items:center; height:58px; margin:64px 8px 28px; color:#fff; font-size:18px; font-weight:700; }
        .menu-label { margin:0 12px 8px; color:#90a8c1; font-size:11px; font-weight:700; letter-spacing:.1em; text-transform:uppercase; }
        .nav-parent { display:block; padding:11px 12px 7px; color:#fff; font-weight:700; }
        .submenu { margin:0 0 10px 12px; padding-left:12px; border-left:1px solid #41617f; }
        .nav-link { display:block; padding:9px 12px; border-radius:8px; color:inherit; text-decoration:none; }
        .nav-link.active,.nav-link:hover { background:#1c4268; color:#fff; }
        .sidebar-note { position:absolute; right:20px; bottom:22px; left:20px; padding-top:18px; border-top:1px solid #31506f; color:#a9bed1; font-size:12px; }
        .menu-toggle { position:fixed; z-index:13; top:120px; left:18px; display:grid; width:44px; height:42px; padding:0; place-content:center; gap:5px; border:0; border-radius:9px; background:var(--navy); color:#fff; cursor:pointer; }
        .menu-toggle span { display:block; width:21px; height:2px; border-radius:2px; background:currentColor; }
        .backdrop { display:none; position:fixed; z-index:2; inset:0; background:rgba(10,28,48,.45); }
        main { min-height:100vh; margin-left:280px; padding:142px clamp(24px,6vw,92px) 54px; transition:margin .25s ease; }
        body.menu-closed .sidebar { transform:translateX(-100%); }
        body.menu-closed main { margin-left:0; }
        .breadcrumb { display:flex; gap:8px; margin:0 0 38px; color:var(--muted); font-size:13px; }
        .breadcrumb a { color:var(--blue); text-decoration:none; }
        .breadcrumb .current { color:var(--ink); font-weight:700; }
        .hero { max-width:760px; }
        .eyebrow { margin:0 0 8px; color:var(--blue); font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        h1 { margin:0 0 12px; font-size:clamp(29px,4vw,42px); line-height:1.14; letter-spacing:-.03em; }
        .intro { margin:0; color:var(--muted); font-size:16px; }
        .card { max-width:880px; margin-top:34px; padding:clamp(24px,4vw,38px); border:1px solid var(--line); border-radius:16px; background:#fff; box-shadow:0 12px 35px rgba(22,52,83,.07); }
        .card-head { display:flex; gap:14px; align-items:flex-start; margin-bottom:28px; }
        .step { display:grid; flex:0 0 auto; width:31px; height:31px; place-items:center; border-radius:50%; background:var(--blue-soft); color:var(--blue); font-size:13px; font-weight:800; }
        h2 { margin:1px 0 3px; font-size:19px; }
        .card-head p,.field-help { margin:0; color:var(--muted); font-size:13px; }
        .file-field { position:relative; min-height:158px; padding:20px; border:1.5px dashed #b7c8da; border-radius:12px; background:#fbfdff; cursor:pointer; transition:.18s ease; }
        .file-field:hover { border-color:var(--blue); background:var(--blue-soft); }
        .file-field input { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .file-icon { color:var(--blue); font-size:24px; }
        .file-field strong,.file-field small { display:block; }
        .file-field strong { margin-top:8px; }
        .file-field small,.file-name { color:var(--muted); }
        .file-name { overflow:hidden; margin-top:12px; color:#35628e; font-size:12px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        .submit-row { display:flex; align-items:center; justify-content:space-between; gap:20px; margin-top:28px; }
        .hint { margin:0; color:var(--muted); font-size:12px; }
        button[type=submit] { padding:13px 19px; border:0; border-radius:8px; background:var(--blue); color:#fff; font:700 14px inherit; cursor:pointer; box-shadow:0 5px 12px rgba(31,111,235,.22); }
        @media (max-width:768px) { .app-header { height:92px; padding-left:18px; }.app-header img { width:140px; height:68px; }.app-title { right:18px; left:auto; transform:none; font-size:15px; }.sidebar { top:92px; transform:translateX(-100%); }.sidebar.open { transform:translateX(0)!important; }.menu-toggle { top:108px; }.backdrop.visible { display:block; } main { margin-left:0; padding:124px 20px 36px; }.breadcrumb { margin-bottom:28px; }.submit-row { align-items:stretch; flex-direction:column; } button[type=submit] { width:100%; } }
    </style>
<link rel="stylesheet" href="assets/ui.css?v=7"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
        <nav class="breadcrumb"><a href="index.php">Accueil</a><span>/</span><span class="current">Enrichissement par clé</span></nav>
        <section class="hero"><h1>Enrichissement par clé</h1><p class="intro">Ajoutez les colonnes d’un autre fichier grâce à une clé commune.</p></section>
        <ol hidden class="workflow" aria-label="Étapes de l’enrichissement"><li id="step-files"><b>01</b> Importer les fichiers</li><li id="step-columns"><b>02</b> Relier les colonnes</li><li id="step-export"><b>03</b> Télécharger le résultat</li></ol>
        <section class="card">
            <form id="enrich-form" action="enrichissement_action.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="card-head"><span class="step">1</span><div><h2>Vos deux sources de données</h2><p>Choisissez le fichier à compléter et celui qui contient les informations à récupérer.</p></div></div>
                <div class="enrich-grid">
                <?php foreach (['origin' => 'Origine', 'extra' => 'Complément'] as $id => $label): ?>
                <fieldset class="enrich-panel">
                    <legend><?= $id === 'origin' ? 'A' : 'B' ?> · <?= $label ?></legend>
                    <p class="panel-intro"><?= $id === 'origin' ? 'Le fichier à compléter, dont la structure sera conservée.' : 'Le fichier qui fournit les colonnes à ajouter.' ?></p>
                    <div class="upload-box"><label for="<?= $id ?>">Choisir le fichier <?= $label ?></label>
                    <input type="file" id="<?= $id ?>" name="<?= $id ?>" accept=".xlsx,.xls" required>
                    <p class="help-copy">Excel .xlsx ou .xls · 25 Mo maximum</p><p class="file-name" id="<?= $id ?>_filename" hidden></p></div>
                    <p id="<?= $id ?>_status" class="status-box" role="status" aria-live="polite">En attente du fichier <?= $label ?>.</p>
                    <label class="field-label" for="<?= $id ?>_key">Colonne clé (Noms, Matricule…)</label>
                    <select id="<?= $id ?>_key" name="<?= $id ?>_key" required disabled><option value="">Les colonnes apparaîtront ici</option></select>
                    <p class="help-copy">Choisissez l’identifiant commun aux deux fichiers.</p>
                    <details class="help-example"><summary>Plus d’options</summary>
                    <div class="header-config"><label for="<?= $id ?>_header">Numéro de la ligne d’en-têtes</label>
                    <input type="number" id="<?= $id ?>_header" name="<?= $id ?>_header" value="1" min="1" max="1048576" aria-describedby="<?= $id ?>_header_help" required></div>
                    <p id="<?= $id ?>_header_help" class="help-copy">La ligne contenant « Noms », « Matricule », etc. Gardez <strong>1</strong> si les noms des colonnes sont tout en haut.</p>

                    <label class="field-label" for="<?= $id ?>_sheet">Feuille à utiliser</label>
                    <select id="<?= $id ?>_sheet" name="<?= $id ?>_sheet" required disabled><option value="">Importez d’abord le fichier</option></select>

                    <?php if ($id === 'origin'): ?>
                    <label class="field-label" for="insert_before">Où placer les colonnes ajoutées ?</label>
                    <select id="insert_before" name="insert_before" disabled><option value="">Après toutes les colonnes (par défaut)</option></select>
                    <p class="help-copy">Ou choisissez « Avant » une colonne existante. Celle-ci et les suivantes seront décalées vers la droite.</p>
                    <?php endif; ?>
                    <?php if ($id === 'extra'): ?>
                    <label class="field-label" for="add_column">Colonnes à ajouter à l’origine</label>
                    <select id="add_column" name="add_column" multiple size="6" required disabled></select><p class="help-copy">Toutes sont sélectionnées. Cochez les colonnes à ajouter, décochez les autres. Elles seront ajoutées ensemble dans leur ordre d’origine.</p><button type="button" class="secondary-button" id="add-all">Tout sélectionner</button>
                    <?php endif; ?>
                    </details>
                </fieldset>
                <?php endforeach; ?>
                </div>
                <?php require __DIR__ . '/comparison_options.php'; ?>
<?php require __DIR__ . '/duplicates_panel.php'; ?>
                <details class="help-example"><summary>Comment trouver le numéro de la ligne d’en-têtes ?</summary><p>Repérez les noms des colonnes dans Excel. S’ils sont sur la ligne 2, saisissez 2. Les données commencent sur la ligne suivante. Ce réglage est indépendant pour chaque fichier.</p></details>
<details class="help-example"><summary>Voir le récapitulatif</summary>                <section class="mapping-summary" aria-labelledby="mapping-title"><h2 id="mapping-title">2 · Votre rapprochement</h2><p id="mapping-summary" aria-live="polite">Choisissez les deux colonnes clés et les colonnes à ajouter pour voir le récapitulatif.</p></section></details>
                <div class="outcome-grid"><div><strong>Clé correspondante</strong>Les valeurs choisies sont ajoutées à la position demandée, ou à droite par défaut. Les autres feuilles restent conservées.</div><div class="pink-outcome"><strong>Clé non trouvée</strong>La ligne reste à sa place, en rose clair, et est recopiée dans la feuille « Non trouvés ».</div></div>
                <details class="help-example"><summary>Règles de correspondance et cas particuliers</summary><p>La comparaison ignore la casse et les espaces superflus, sans rapprochement approximatif. Une clé vide est considérée comme non trouvée. Les lignes entièrement vides restent intactes.</p><p>Les clés répétées demandent votre choix avant l’export : supprimer les répétitions ou les garder en couleur, puis choisir la ligne à utiliser. Les formules de chaque colonne ajoutée deviennent des valeurs. Si une feuille « Non trouvés » existe déjà, renommez-la avant l’import.</p></details>
                <p id="enrich-status" class="status-box" role="status" aria-live="polite">Pour commencer, importez les fichiers Origine et Complément.</p>
                <div class="enrich-actions"><p class="hint">3 · Résultat au format Excel (.xlsx)<br>Vos fichiers sources ne sont pas modifiés.</p><button type="submit" id="enrich-submit" disabled>Enrichir et télécharger</button></div>
                <noscript>Activez JavaScript pour choisir les colonnes et générer le résultat.</noscript>
            </form>
        </section>
    </main>
    
    <script src="assets/duplicates.js" defer></script>
    <script src="assets/enrichissement.js" defer></script>
    <script src="assets/app.js"></script>
    
</body>
</html>
