<?php
declare(strict_types=1);
session_start();
$dbHost = '127.0.0.1';
$dbName = 'campus_lost_found';
$dbUser = 'root';
$dbPass = '';
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    http_response_code(503);
    $databaseUnavailable = true;
}
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfField(): string {
    $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf_token" value="' . h($_SESSION['csrf_token']) . '">';
}
function verifyCsrf(): void {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Refresh the page and try again.');
    }
}
function flash(string $kind, string $message): void { $_SESSION['flash'] = [$kind, $message]; }
function redirectTo(string $view): never { header('Location: index.php?view=' . rawurlencode($view)); exit; }
