<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/course_engine.php';

function factoryDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function factoryMigrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_assets (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,asset_type VARCHAR(40) NOT NULL,title VARCHAR(180) NOT NULL,content LONGTEXT NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'rascunho',engine_mode VARCHAR(60) NOT NULL DEFAULT 'grounded_editorial_v0.4',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_course_asset(course_id, asset_type),CONSTRAINT fk_assets_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->exec("ALTER TABLE course_assets ADD COLUMN engine_mode VARCHAR(60) NOT NULL DEFAULT 'grounded_editorial_v0.4' AFTER status"); } catch (Throwable $e) {}
}
function buildCourseOutline(PDO $pdo, int $courseId): string
{
    $stmt = $pdo->prepare('SELECT m.position AS module_position,m.title AS module_title,l.position AS lesson_position,l.title AS lesson_title FROM modules m LEFT JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? ORDER BY m.position,l.position'); $stmt->execute([$courseId]);
    $lines = []; $lastModule = null; foreach ($stmt->fetchAll() as $row) { $moduleKey = (string)$row['module_position']; if ($moduleKey !== $lastModule) { $lines[] = 'Módulo ' . $row['module_position'] . ' — ' . $row['module_title']; $lastModule = $moduleKey; } if ($row['lesson_position'] !== null) $lines[] = '  Aula ' . $row['lesson_position'] . ' — ' . $row['lesson_title']; }
    return implode("\n", $lines);
}
function firstLessonApproved(PDO $pdo, int $courseId): bool { $stmt = $pdo->prepare('SELECT l.review_status FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? ORDER BY m.position,l.position LIMIT 1'); $stmt->execute([$courseId]); return $stmt->fetchColumn() === 'aprovada'; }
function updatePackageStatus(PDO $pdo, int $courseId, array $types): bool
{
    $placeholders = implode(',', array_fill(0, count($types), '?')); $params = array_merge([$courseId], $types); $stmt = $pdo->prepare("SELECT COUNT(DISTINCT asset_type) FROM course_assets WHERE course_id=? AND status='gerado' AND asset_type IN ($placeholders)"); $stmt->execute($params); $complete = (int)$stmt->fetchColumn() === count($types); $pdo->prepare("UPDATE courses SET status=? WHERE id=?")->execute([$complete ? 'pacote_didatico_gerado' : 'primeira_aula_revisada', $courseId]); return $complete;
}

$pdo = factoryDb(); factoryMigrate($pdo); $courseId = (int)($_GET['course'] ?? $_POST['course_id'] ?? 0); $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch(); if (!$course) { http_response_code(404); echo 'Curso não encontrado.'; exit; }
$approved = firstLessonApproved($pdo, $courseId); $outline = buildCourseOutline($pdo, $courseId); $sourceContext = courseEngineSourceContext($pdo, $courseId); $lessonContext = courseEngineLessonContext($pdo, $courseId); $engineStatus = courseEngineStatus(); $message = ''; $error = ''; $types = ['slides','apostila','exercicios','avaliacao','pagina_venda','certificado'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$approved) throw new RuntimeException('A primeira aula precisa estar aprovada antes de gerar materiais.'); if ($sourceContext === '') throw new RuntimeException('O curso precisa ter fontes processadas antes da geração dos materiais.'); if ($lessonContext === '') throw new RuntimeException('A estrutura de módulos e aulas precisa existir antes da geração dos materiais.');
        $requested = (string)($_POST['asset_type'] ?? 'all'); $selected = $requested === 'all' ? $types : [$requested];
        foreach ($selected as $type) { if (!in_array($type, $types, true)) throw new RuntimeException('Material inválido.'); [$assetTitle, $content] = courseEngineBuildMaterial($course, $outline, $sourceContext, $lessonContext, $type); $stmt = $pdo->prepare("INSERT INTO course_assets(course_id,asset_type,title,content,status,engine_mode) VALUES(?,?,?,?, 'gerado', ?) ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),status='gerado',engine_mode=VALUES(engine_mode),updated_at=CURRENT_TIMESTAMP"); $stmt->execute([$courseId, $type, $assetTitle, $content, $engineStatus['mode']]); }
        $complete = updatePackageStatus($pdo, $courseId, $types); $course['status'] = $complete ? 'pacote_didatico_gerado' : 'primeira_aula_revisada'; $message = $requested === 'all' ? 'Pacote didático completo gerado a partir das fontes e aulas do curso.' : 'Material gerado. O pacote será considerado completo somente quando os 6 materiais estiverem prontos.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$stmt = $pdo->prepare('SELECT * FROM course_assets WHERE course_id=? ORDER BY id'); $stmt->execute([$courseId]); $assets = $stmt->fetchAll(); $assetsByType = []; foreach ($assets as $asset) $assetsByType[$asset['asset_type']] = $asset;
$catalog = ['slides'=>'Slides','apostila'=>'Apostila','exercicios'=>'Exercícios','avaliacao'=>'Avaliação','pagina_venda'=>'Página de venda','certificado'=>'Certificado']; $generatedCount = count(array_filter($types, fn(string $type): bool => isset($assetsByType[$type]) && ($assetsByType[$type]['status']??'') === 'gerado')); $packageComplete = $generatedCount === count($types); if (($course['status'] ?? '') === 'pacote_didatico_gerado' && !$packageComplete) { updatePackageStatus($pdo, $courseId, $types); $course['status'] = 'primeira_aula_revisada'; }
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fábrica do Curso — Cursos IA</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div><nav><div class="nav-title">Operação</div><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link active" href="index.php?course=<?=$courseId?>"><span class="dot"></span>Criador de Cursos</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a><div class="nav-title">Curso atual</div><a class="course-item active" href="index.php?course=<?=$courseId?>"><strong><?=h($course['title'])?></strong><small><?=h($course['status'])?></small></a><div class="nav-title">Etapas</div><a class="nav-link" href="index.php?course=<?=$courseId?>#fontes"><span class="dot"></span>Fontes</a><a class="nav-link" href="index.php?course=<?=$courseId?>#estrutura"><span class="dot"></span>Programa</a><a class="nav-link active" href="factory.php?course=<?=$courseId?>"><span class="dot"></span>Fábrica de Materiais</a></nav></aside>
<div class="main"><header class="topbar"><strong>Fábrica Pedagógica · Materiais do Curso</strong><span class="env">HML · v0.9</span></header><main class="content">
<div class="page-title"><div><h1><?=h($course['title'])?></h1><p>Geração dos materiais derivados do programa e das fontes revisadas.</p></div><div class="actions"><a class="btn secondary" href="index.php?course=<?=$courseId?>">Voltar ao Criador</a></div></div>
<?php if($message):?><div class="flash"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="flash error"><?=h($error)?></div><?php endif;?>
<section class="card hero-status" style="margin-bottom:18px"><div><div class="info-strip"><span class="item">Aula 1: <?=$approved?'aprovada':'aguardando aprovação'?></span><span class="item">Fontes: <?=$sourceContext!==''?'OK':'pendentes'?></span><span class="item">Programa: <?=$lessonContext!==''?'OK':'pendente'?></span><span class="item">Motor: <?=h($engineStatus['mode'])?></span></div><p class="muted">O motor atual é editorial e fundamentado nas fontes e aulas. Ainda não há provedor externo de IA conectado; essa integração virá sem alterar este fluxo.</p></div><div style="min-width:210px"><span class="muted">Pacote didático</span><div style="font-size:32px;font-weight:850;margin:4px 0"><?=$generatedCount?>/<?=count($types)?></div><div class="progress"><span style="width:<?=round(($generatedCount/count($types))*100)?>%"></span></div><p class="muted" style="margin-bottom:0"><?=$packageComplete?'Pacote completo':'Materiais ainda pendentes'?></p></div></section>
<section class="card" style="margin-bottom:18px"><div class="section-title"><div><h2>Gerar pacote didático completo</h2><p class="muted" style="margin:4px 0 0">Slides, apostila, exercícios, avaliação, página de venda e certificado.</p></div><span class="pill <?=$packageComplete?'ok':'warn'?>"><?=$packageComplete?'Completo':'Em construção'?></span></div><form method="post"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="asset_type" value="all"><button class="btn" <?=$approved?'':'disabled'?>><?=$packageComplete?'Gerar pacote novamente':'Gerar pacote completo'?></button></form></section>
<div class="grid grid-2"><?php foreach($catalog as $type=>$label):$asset=$assetsByType[$type]??null;?><section class="card factory-card"><div class="section-title"><h2><?=h($label)?></h2><span class="pill <?=$asset?'ok':'warn'?>"><?=$asset?'gerado':'pendente'?></span></div><?php if($asset):?><div class="info-strip"><span class="item"><?=h($asset['engine_mode']??'grounded_editorial_v0.4')?></span><span class="item">Atualizado: <?=h($asset['updated_at']??'')?></span></div><pre class="asset-preview"><?=h($asset['content'])?></pre><?php else:?><div class="empty">Este material ainda não foi gerado.</div><?php endif;?><form method="post"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="asset_type" value="<?=h($type)?>"><button class="btn ghost" <?=$approved?'':'disabled'?>><?=$asset?'Gerar novamente':'Gerar material'?></button></form></section><?php endforeach;?></div>
<?php if($packageComplete):?><section class="card" style="margin-top:18px;border-color:#cde8d5;background:#f5fbf7"><div class="section-title"><h2>Pacote didático completo</h2><span class="pill ok">6/6</span></div><p>O curso já possui todos os materiais-base. A próxima etapa continua sendo vídeo/HeyGen, após a homologação pedagógica e visual.</p></section><?php endif;?></main></div></div></body></html>