<?php
declare(strict_types=1);

function uiRequestSizeError(): ?string
{
    $limit = trim(ini_get('post_max_size'));
    $bytes = (float) $limit;
    $unit = strtolower(substr($limit, -1));
    $bytes *= match ($unit) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    if ($bytes > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $bytes) {
        return 'Vos fichiers dépassent la limite totale autorisée. Choisissez des fichiers plus petits, puis réessayez. Si nécessaire, demandez de l’aide à la personne responsable de l’application.';
    }
    return null;
}

function uiUploadError(array $file, string $label): ?string
{
    return match ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
        UPLOAD_ERR_OK => null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' : fichier trop volumineux. Choisissez une copie plus petite. La taille acceptée peut être inférieure aux 25 Mo indiqués selon les réglages de l’application.',
        UPLOAD_ERR_PARTIAL => $label . ' : le transfert a été interrompu. Sélectionnez à nouveau le fichier et réessayez.',
        UPLOAD_ERR_NO_FILE => $label . ' : aucun fichier reçu. Choisissez un fichier avant de continuer.',
        default => $label . ' : le fichier n’a pas pu être reçu. Réessayez ; si le problème persiste, demandez de l’aide à la personne responsable de l’application.',
    };
}

function uiErrorPage(string $message, string $back, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    $text = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $link = htmlspecialchars($back, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Traitement interrompu — Classeur Pro</title><link rel="stylesheet" href="assets/ui.css"></head><body><section class="card error-card"><p class="error-code">TRAITEMENT INTERROMPU</p><h1>Un point à vérifier.</h1><p data-user-error>' . $text . '</p><p>Vérifiez ce point, puis relancez le traitement. Si le problème persiste, transmettez ce message à votre administrateur.</p><div class="error-actions"><a class="primary-link" href="' . $link . '">Retour au module</a><a class="secondary-link" href="index.php">Accueil</a></div></section></body></html>';
    exit;
}
