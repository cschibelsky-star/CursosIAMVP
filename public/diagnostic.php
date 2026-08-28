<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_generator.php';
header('Content-Type: application/json; charset=utf-8');

$cfg = aiConfig();
$checks = [
    'php' => PHP_VERSION,
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'mbstring' => extension_loaded('mbstring'),
    'zip' => extension_loaded('zip'),
    'curl' => extension_loaded('curl'),
    'pdftotext' => trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '',
    'db' => false,
    'ai_enabled' => $cfg['enabled'],
    'ai_mode' => $cfg['mode'],
    'ai_ready' => aiIsReady(),
    'ai_provider' => aiProviderLabel(),
    'ai_project_id' => $cfg['project_id'],
    'ai_model' => $cfg['model'] ?: null,
    'ai_broker_configured' => $cfg['broker_url'] !== '' && $cfg['broker_token'] !== '',
];

try {
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $checks['db'] = (bool)$pdo->query('SELECT 1')->fetchColumn();
} catch (Throwable $e) {
    $checks['db_error'] = $e->getMessage();
}

$checks['ok'] = $checks['pdo_mysql'] && $checks['mbstring'] && $checks['zip'] && $checks['curl'] && $checks['pdftotext'] && $checks['db'];
http_response_code($checks['ok'] ? 200 : 503);
echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
