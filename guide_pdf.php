<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/ui_errors.php';
try {
    $content = file_get_contents(__DIR__ . '/guide_content.php');
    if ($content === false) throw new RuntimeException('Guide indisponible.');
    // Les liens vers les écrans restent des libellés ; les liens du sommaire restent actifs.
    $content = preg_replace('~<a href="(?!#)[^"]+">(.*?)</a>~s', '$1', $content);
    $pdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);
    $pdf->setPaper('A4');
    $pdf->loadHtml('<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
        @page{margin:42px 42px 52px}body{font:10pt/1.45 "DejaVu Sans",sans-serif;color:#211732}
        h1{font-size:25pt;color:#1a093e}h2{font-size:16pt;color:#1a093e;page-break-after:avoid}
        h3{font-size:11pt;margin-top:18px;page-break-after:avoid}p,li{orphans:3;widows:3}li{margin-bottom:6px}
        .guide-section{page-break-before:always}a{color:#165b80;text-decoration:none}
        .guide-toc a{display:block;margin:9px 0}table{border-collapse:collapse;width:100%;font-size:9pt}
        th,td{border:1px solid #c8bfd7;padding:7px;text-align:left}th{background:#eee8f5}
        tr{page-break-inside:avoid}.guide-note{border-left:3px solid #009fe3;padding-left:10px}
    </style></head><body id="main-content"><h1>Classeur Pro</h1><h2>Guide utilisateur</h2><p>Les étapes, les réglages et des exemples pour utiliser chaque outil.</p>' . $content . '</body></html>', 'UTF-8');
    $pdf->render();
    $pdf->getCanvas()->page_text(42, 812, 'Classeur Pro — Guide utilisateur | {PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.3, 0.3, 0.3]);
    $bytes = $pdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="guide_utilisateur_classeur_pro.pdf"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-cache');
    echo $bytes;
} catch (Throwable $error) {
    error_log('Guide PDF : ' . $error->getMessage());
    uiErrorPage('Le guide PDF n’a pas pu être préparé. Vous pouvez consulter le guide en ligne ou réessayer.', 'guide.php', 500);
}
