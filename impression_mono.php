<?php
declare(strict_types=1);
session_start(); $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$printModule = 'mono'; require __DIR__ . '/impression_page.php';
