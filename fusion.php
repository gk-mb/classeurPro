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
    <title>Fusion de deux fichiers</title>
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
<link rel="stylesheet" href="assets/ui.css?v=6"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
        <nav class="breadcrumb"><a href="index.php">Accueil</a><span>/</span><span class="current">Fusion de fichiers</span></nav>
        <section class="hero"><h1>Fusion de fichiers</h1><p class="intro">Choisissez les colonnes et ajoutez les données de Y à la suite de X.</p></section>
        <section class="card">
            <h2>Importer les fichiers dans l’ordre souhaité</h2>
            <p>Excel : pour deux classeurs à une seule feuille, les noms sont libres. Sinon, seules les feuilles de même nom sont fusionnées (casse et espaces compris), quel que soit leur ordre. Les feuilles sans correspondante restent inchangées dans X et sont ignorées dans Y.</p>
            <form id="fusion-form" action="fusion_action.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div style="display:grid;gap:18px">
                    <label class="file-field"><input type="file" name="x" accept=".xlsx,.xls,.docx,.pdf" required><strong>Fichier X — données en premier</strong><span class="file-name">Aucun fichier sélectionné</span></label>
                    <label class="file-field"><input type="file" name="y" accept=".xlsx,.xls,.docx,.pdf" required><strong>Fichier Y — données à ajouter en bas</strong><span class="file-name">Aucun fichier sélectionné</span></label>
                </div>
                <p class="hint">25 Mo maximum par fichier, sous réserve des limites d’import PHP. Word : DOCX uniquement. Deux fichiers Excel, ou deux documents Word/PDF, y compris Word + PDF.</p>
                <p class="help-copy">Pour les tableaux, la première ligne sert d’en-tête. Word/PDF : seuls les textes et tableaux reconnus sont repris.</p>
                <details class="help-example"><summary>Plus d’options</summary><p class="help-copy">Toutes les colonnes sont conservées par défaut.</p>
                <div id="fusion-columns" class="enrich-grid"></div>
                    <label class="field-label" for="fusion-separator">Séparation entre les tableaux</label><select id="fusion-separator" name="separator"><option value="1">Une ligne noire (par défaut)</option><option value="0">Aucune ligne de séparation</option></select>
                    <label class="field-label" for="fusion-header">En-tête du second tableau</label><select id="fusion-header" name="second_header"><option value="1">Reprendre l’en-tête de Y (par défaut)</option><option value="0">Ajouter uniquement les données de Y</option></select>
                </details>
                <div id="fusion-status" class="status-box" role="status" aria-live="polite">Sélectionnez les deux fichiers pour vérifier leur conformité.</div>
                <label for="fusion-format">Format du résultat</label>
                <select id="fusion-format" name="format" disabled><option value="xlsx">Excel (.xlsx)</option></select>
                <div class="submit-row"><button type="submit" id="fusion-submit" disabled>Fusionner et télécharger</button></div>
                <noscript>Activez JavaScript pour vérifier les fichiers et effectuer la fusion.</noscript>
            </form>
        </section>
    </main>
    
    <script src="assets/fusion.js" defer></script>
    <script src="assets/app.js"></script>
    <script>
        document.querySelector('input[type="file"]').addEventListener('change', (event) => {
            document.querySelector('.file-name').textContent = event.target.files[0]?.name || 'Aucun fichier selectionne';
        });
    </script>
</body>
</html>
