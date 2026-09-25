<?php
declare(strict_types=1);
session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$extractionModule = 'mono';
require __DIR__ . '/extraction_page.php';