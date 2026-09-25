<?php
/** Navigation partagée par tous les modules. */
$uiPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$uiGroups = [
    'Extraire' => ['index.php' => 'Extraction mono / multi', 'extraction_mono.php' => 'Extraction mono / mono', 'extraction_hybride.php' => 'Hybride'],
    'Transformer' => ['enrichissement.php' => 'Ajouter colonne', 'ajout_multi.php' => 'Ajout multi', 'fusion.php' => 'Fusion de fichiers', 'eclatement.php' => 'Éclatement', 'regroupement.php' => 'Regroupement'],
    'Documents' => ['rapport.php' => 'Rapport documentaire', 'archive.php' => 'Archives documentaires'],
    'Préparer l’impression' => ['impression_mono.php' => 'Monofeuille', 'impression_multi.php' => 'Multifeuilles'],
];
?>
<a class="skip-link" href="#main-content">Aller au contenu</a>
<header class="app-header"><a href="index.php" class="logo-link"><img src="assets/logo.png" alt="Accueil Classeur Pro"></a><p class="app-title">Classeur <strong>Pro</strong><span>Votre espace de traitement documentaire</span></p><span class="header-badge">Espace de travail</span></header>
<button class="menu-toggle" type="button" aria-label="Ouvrir ou fermer la navigation" aria-controls="app-navigation" aria-expanded="true"><span></span><span></span><span></span></button>
<aside class="sidebar" id="app-navigation" aria-label="Navigation principale">
    <div class="brand"><span class="brand-symbol" aria-hidden="true">▦</span><div>Mes outils<small>Classeur Pro</small></div></div>
    <?php foreach ($uiGroups as $group => $links): ?>
        <details class="nav-section"<?= isset($links[$uiPage]) ? ' open' : '' ?>>
        <summary class="menu-label"><?= $group ?><span aria-hidden="true">›</span></summary>
        <div class="nav-group">
        <?php foreach ($links as $href => $label): ?>
            <a class="nav-link<?= $uiPage === $href ? ' active' : '' ?>" href="<?= $href ?>"<?= $uiPage === $href ? ' aria-current="page"' : '' ?>><span><?= $label ?></span><span class="nav-arrow" aria-hidden="true">›</span></a>
        <?php endforeach; ?>
        </div>
        </details>
    <?php endforeach; ?>
    <a class="nav-link guide-link<?= $uiPage === 'guide.php' ? ' active' : '' ?>" href="guide.php"<?= $uiPage === 'guide.php' ? ' aria-current="page"' : '' ?>><span>Guide utilisateur</span><span aria-hidden="true">?</span></a>
    <p class="sidebar-note">Vos fichiers, simplement.<br>Importez, configurez, téléchargez.</p>
</aside>
<div class="backdrop" aria-hidden="true"></div>
<footer class="app-footer" aria-label="Signature du développeur">GKATMB</footer>
