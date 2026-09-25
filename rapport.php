<?php
/** Page du module de rapport documentaire. */
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
    <title>Excel Extract - Rapport documentaire</title>
    <style>
        :root { --navy:#102a43; --blue:#1f6feb; --ink:#172b4d; --muted:#61758a; --line:#dce5ef; --bg:#f5f8fc; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; background:var(--bg); color:var(--ink); font:15px/1.5 Inter,"Segoe UI",Arial,sans-serif; }
        .sidebar { position:fixed; z-index:3; inset:0 auto 0 0; width:260px; padding:24px 16px; color:#d9e7f5; background:var(--navy); transition:transform .25s ease; }
        .brand { margin:0 8px 34px; color:#fff; font-size:18px; font-weight:700; }
        .nav-link { display:block; margin:4px 0; padding:11px 12px; border-radius:8px; color:inherit; text-decoration:none; }
        .nav-link.active,.nav-link:hover { background:#1c4268; color:#fff; }
        .sidebar-note { position:absolute; right:20px; bottom:22px; left:20px; padding-top:18px; border-top:1px solid #31506f; color:#a9bed1; font-size:12px; }
        .menu-toggle { position:fixed; z-index:10; top:18px; left:18px; display:grid; width:44px; height:42px; padding:0; place-content:center; gap:5px; border:0; border-radius:9px; background:var(--navy); color:#fff; cursor:pointer; }
        .menu-toggle span { display:block; width:21px; height:2px; border-radius:2px; background:currentColor; transition:transform .2s ease,opacity .2s ease; }
        .menu-toggle[aria-expanded="true"] span:nth-child(1) { transform:translateY(7px) rotate(45deg); }.menu-toggle[aria-expanded="true"] span:nth-child(2) { opacity:0; }.menu-toggle[aria-expanded="true"] span:nth-child(3) { transform:translateY(-7px) rotate(-45deg); }
        main { min-height:100vh; margin-left:260px; padding:54px clamp(24px,6vw,92px); transition:margin .25s ease; }
        body.menu-closed .sidebar { transform:translateX(-100%); }
        body.menu-closed main { margin-left:0; }
        .eyebrow { color:var(--blue); font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
        h1 { max-width:760px; margin:10px 0 12px; font-size:clamp(29px,4vw,42px); line-height:1.14; }
        .intro { max-width:730px; margin:0; color:var(--muted); font-size:16px; }
        .card { max-width:820px; margin-top:34px; padding:clamp(22px,4vw,38px); border:1px solid var(--line); border-radius:16px; background:#fff; box-shadow:0 12px 35px rgba(22,52,83,.07); }
        label { display:block; margin:22px 0 7px; font-weight:700; }
        input[type=file],textarea { width:100%; padding:12px; border:1px solid #b7c8da; border-radius:8px; color:var(--ink); font:inherit; }
        textarea { min-height:150px; resize:vertical; }
        small { display:block; margin-top:7px; color:var(--muted); }
        button[type=submit] { margin-top:26px; padding:13px 19px; border:0; border-radius:8px; background:var(--blue); color:#fff; font:700 14px inherit; cursor:pointer; }
        @media(max-width:768px) { .sidebar { transform:translateX(-100%); } .sidebar.open { transform:translateX(0) !important; } main { margin-left:0; padding:78px 20px 36px; } }
    </style>
<link rel="stylesheet" href="assets/ui.css?v=6"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
        <nav class="breadcrumb" aria-label="Fil d’Ariane"><a href="index.php">Accueil</a><span>/</span><span>Rapport documentaire</span></nav><section class="hero"><h1>Rapport documentaire</h1><p class="intro">Choisissez un document et les informations à examiner.</p></section>
        <section class="card">
            <form action="generer_rapport.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="document">Fichier a analyser</label>
                <input id="document" name="document" type="file" accept=".xlsx,.xls,.docx,.pdf" required>
                <small>Formats acceptes : Excel (XLSX, XLS), Word (DOCX) et PDF. Maximum 25 Mo.</small>
                <label for="instructions">Votre demande d'analyse</label>
                <textarea id="instructions" name="instructions" maxlength="3000" required placeholder="Exemple : relever les tendances des ventes, les valeurs inhabituelles et les informations importantes pour la direction."></textarea>
                <small>Votre demande est incluse dans le rapport afin de donner le contexte de lecture.</small>
                <label for="output_format">Format du rapport a generer</label>
                <select id="output_format" name="output_format">
                    <option value="pdf">PDF avec graphiques</option>
                    <option value="docx">Word (DOCX)</option>
                </select>
                <button type="submit">Generer le rapport</button>
            </form>
        </section>
    </main>
    <script src="assets/app.js"></script>
    
</body>
</html>
