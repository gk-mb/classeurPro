<?php declare(strict_types=1); ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Guide utilisateur — Classeur Pro</title><link rel="stylesheet" href="assets/ui.css?v=7"></head>
<body><?php require __DIR__ . '/ui_nav.php'; ?><main id="main-content" tabindex="-1">
<nav class="breadcrumb"><a href="index.php">Accueil</a><span>/</span><span>Guide utilisateur</span></nav>
<section class="hero"><h1>Guide utilisateur</h1><p class="intro">Les étapes et des exemples pour chaque outil.</p></section><div class="guide-actions"><a class="primary-link" href="guide_pdf.php" download>Télécharger le guide en PDF</a><button type="button" class="secondary-button print-guide" id="print-guide">Imprimer</button></div>
<?php require __DIR__ . '/guide_content.php'; ?>
</main><script src="assets/app.js"></script><script>document.getElementById('print-guide').addEventListener('click', () => window.print());</script></body></html>
