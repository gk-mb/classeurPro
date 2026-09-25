<?php
declare(strict_types=1);

use Smalot\PdfParser\Parser;

const ARCHIVE_DIRECTORY = __DIR__ . DIRECTORY_SEPARATOR . 'archives';
const ARCHIVE_FILES_DIRECTORY = ARCHIVE_DIRECTORY . DIRECTORY_SEPARATOR . 'files';
const ARCHIVE_DATABASE = ARCHIVE_DIRECTORY . DIRECTORY_SEPARATOR . 'archive.sqlite';

function archiveDatabase(): PDO
{
    if (!is_dir(ARCHIVE_FILES_DIRECTORY)) {
        mkdir(ARCHIVE_FILES_DIRECTORY, 0750, true);
    }

    $database = new PDO('sqlite:' . ARCHIVE_DATABASE);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('CREATE TABLE IF NOT EXISTS documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL UNIQUE,
        mime_type TEXT NOT NULL,
        title TEXT NOT NULL,
        category TEXT NOT NULL,
        tags TEXT NOT NULL DEFAULT "",
        description TEXT NOT NULL DEFAULT "",
        document_date TEXT NULL,
        indexed_text TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL
    )');
    $database->exec('CREATE INDEX IF NOT EXISTS documents_search ON documents(title, category, tags, original_name)');

    return $database;
}

function archiveExtractText(string $path, string $extension): string
{
    try {
        if ($extension === 'pdf') {
            return trim((new Parser())->parseFile($path)->getText());
        }

        if ($extension === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) return '';
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            return $xml === false ? '' : trim(strip_tags($xml));
        }
    } catch (Throwable) {
        return '';
    }

    return '';
}

function archiveSuggestCategory(string $name, string $text): string
{
    $content = mb_strtolower($name . ' ' . mb_substr($text, 0, 3000, 'UTF-8'), 'UTF-8');
    $keywords = [
        'Finance' => ['facture', 'paiement', 'budget', 'finance', 'devis'],
        'Ressources humaines' => ['cv', 'contrat de travail', 'personnel', 'recrutement'],
        'Juridique' => ['contrat', 'convention', 'juridique', 'decision'],
        'Administratif' => ['note', 'courrier', 'rapport', 'administratif'],
    ];
    foreach ($keywords as $category => $terms) {
        foreach ($terms as $term) if (str_contains($content, $term)) return $category;
    }
    return 'Non classe';
}
