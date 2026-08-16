<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/cidades_inclusivas_model.php';
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

try {
    foreach (['pdo_mysql', 'mbstring', 'zip'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('missing_extension:' . $extension);
        }
    }

    if (trim((string)shell_exec('command -v pdftotext 2>/dev/null')) === '') {
        throw new RuntimeException('missing_pdftotext');
    }

    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (!(bool)$pdo->query('SELECT 1')->fetchColumn()) {
        throw new RuntimeException('db_check_failed');
    }

    ensureCidadesInclusivas($pdo);
    ensureAcademicModel($pdo);
    ensureStudentPortal($pdo);
    echo "OK\n";
} catch (Throwable $e) {
    http_response_code(503);
    echo "ERROR:" . $e->getMessage() . "\n";
}
