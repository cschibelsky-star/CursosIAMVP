<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/ai_generator.php';

$resultFile = '/var/run/cursos-ia/factory_smoke_result.json';
if (is_file($resultFile)) {
    $saved = json_decode((string)file_get_contents($resultFile), true);
    if (is_array($saved) && isset($saved['ok'])) {
        fwrite(STDOUT, json_encode($saved, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit($saved['ok'] ? 0 : 1);
    }
}

$result = [
    'ok' => false,
    'checked_at' => date(DATE_ATOM),
    'stage' => 'bootstrap',
    'course_id' => null,
    'course_title' => null,
    'active_sources' => 0,
    'modules_returned' => 0,
    'lessons_returned' => 0,
    'error' => null,
];

try {
    if (!aiIsReady()) {
        throw new RuntimeException('Centro IA não está configurado como pronto neste container.');
    }

    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $result['stage'] = 'select_course';
    $hasActiveColumn = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sources LIKE 'active_for_generation'")->fetch();
        $hasActiveColumn = (bool)$col;
    } catch (Throwable $ignored) {}
    $sourceFilter = $hasActiveColumn ? ' AND s.active_for_generation=1' : '';
    $course = $pdo->query("SELECT c.* FROM courses c WHERE EXISTS (SELECT 1 FROM sources s WHERE s.course_id=c.id{$sourceFilter} AND s.content IS NOT NULL AND LENGTH(s.content)>0) ORDER BY c.id DESC LIMIT 1")->fetch();
    if (!$course) {
        throw new RuntimeException('Nenhum curso com fonte utilizável foi encontrado para o smoke test.');
    }

    $sql = 'SELECT * FROM sources WHERE course_id=?' . ($hasActiveColumn ? ' AND active_for_generation=1' : '') . ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$course['id']]);
    $sources = $stmt->fetchAll();
    if (!$sources) {
        throw new RuntimeException('O curso selecionado não possui fontes ativas.');
    }

    $result['course_id'] = (int)$course['id'];
    $result['course_title'] = (string)$course['title'];
    $result['active_sources'] = count($sources);
    $result['stage'] = 'course_generation';

    $structure = generateCourseWithAI($course, $sources);
    $modules = $structure['modules'] ?? [];
    $lessonCount = 0;
    foreach ($modules as $module) {
        $lessonCount += count($module['lessons'] ?? []);
    }

    if (count($modules) < 2 || $lessonCount < 2) {
        throw new RuntimeException('Centro IA respondeu, mas a estrutura validada ficou abaixo do mínimo esperado.');
    }

    $result['modules_returned'] = count($modules);
    $result['lessons_returned'] = $lessonCount;
    $result['stage'] = 'completed';
    $result['ok'] = true;
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
}

file_put_contents($resultFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit($result['ok'] ? 0 : 1);