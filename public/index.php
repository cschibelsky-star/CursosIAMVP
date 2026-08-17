<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/source_processor.php';
require_once __DIR__ . '/core_model.php';

function db(): PDO
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
function migrate(PDO $pdo): void { ensureCoreCourseModel($pdo); }
function go(string $url): never { header('Location: ' . $url); exit; }
function flash(string $message, string $type = 'ok'): void { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; }
function cleanText(string $text): string { return mb_substr(sourceCleanText($text), 0, 250000); }
function excerpt(string $text, int $max = 650): string { $text = sourceCleanText($text); return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max)) . '…'; }
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

$pdo = db();
migrate($pdo);
$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_course') {
            $title = trim((string)($_POST['title'] ?? '')); $audience = trim((string)($_POST['audience'] ?? '')); $objective = trim((string)($_POST['objective'] ?? ''));
            if ($title === '' || $audience === '' || $objective === '') throw new RuntimeException('Preencha título, público-alvo e objetivo do curso.');
            $stmt = $pdo->prepare('INSERT INTO courses(title,audience,objective) VALUES(?,?,?)'); $stmt->execute([$title, $audience, $objective]); $id = (int)$pdo->lastInsertId();
            flash('Curso criado. Agora adicione as fontes de conteúdo.'); go('?course=' . $id);
        }
        if ($action === 'add_text_source') {
            $courseId = (int)($_POST['course_id'] ?? 0); $name = trim((string)($_POST['name'] ?? 'Fonte textual')); $content = cleanText((string)($_POST['content'] ?? ''));
            if ($courseId < 1 || $content === '') throw new RuntimeException('Informe o conteúdo da fonte.');
            $stmt = $pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status) VALUES(?,?,?,?,?)'); $stmt->execute([$courseId, 'texto', $name ?: 'Fonte textual', $content, 'processado']);
            flash('Fonte textual processada e vinculada ao curso.'); go('?course=' . $courseId . '#fontes');
        }
        if ($action === 'upload_source') {
            $courseId = (int)($_POST['course_id'] ?? 0); if ($courseId < 1 || !isset($_FILES['source_file'])) throw new RuntimeException('Selecione um arquivo válido.');
            $processed = processUploadedSource($_FILES['source_file']);
            $stmt = $pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status) VALUES(?,?,?,?,?)'); $stmt->execute([$courseId, 'arquivo_' . $processed['extension'], $processed['name'], $processed['content'], 'processado']);
            flash('Fonte ' . strtoupper($processed['extension']) . ' processada: ' . number_format((int)$processed['characters'], 0, ',', '.') . ' caracteres extraídos.'); go('?course=' . $courseId . '#fontes');
        }
        if ($action === 'generate_structure') {
            $courseId = (int)($_POST['course_id'] ?? 0); $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch();
            if (!$course) throw new RuntimeException('Curso não encontrado.');
            if (($course['title'] ?? '') === 'Cidades Inclusivas') throw new RuntimeException('Cidades Inclusivas usa estrutura oficial de 5 módulos. O gerador genérico foi bloqueado para preservar o modelo homologado.');
            $stmt = $pdo->prepare('SELECT * FROM sources WHERE course_id=? ORDER BY id'); $stmt->execute([$courseId]); $sources = $stmt->fetchAll();
            if (!$sources) throw new RuntimeException('Adicione ao menos uma fonte antes de gerar a estrutura.');
            $sourceText = implode("\n", array_map(fn(array $source): string => (string)$source['content'], $sources)); $baseExcerpt = excerpt($sourceText, 1400);
            $pdo->beginTransaction(); $pdo->prepare('DELETE FROM modules WHERE course_id=?')->execute([$courseId]);
            $defs = [['Fundamentos e contexto','Apresentar conceitos essenciais, contexto e vocabulário necessários para iniciar o tema.'],['Aplicação prática','Transformar os conceitos das fontes em um processo aplicável pelo aluno.'],['Consolidação e próximos passos','Consolidar o aprendizado, revisar pontos críticos e orientar a continuidade prática.']];
            foreach ($defs as $moduleIndex => $def) {
                $moduleStmt = $pdo->prepare('INSERT INTO modules(course_id,position,title,objective) VALUES(?,?,?,?)'); $moduleStmt->execute([$courseId, $moduleIndex + 1, $def[0], $def[1]]); $moduleId = (int)$pdo->lastInsertId();
                for ($lessonPosition = 1; $lessonPosition <= 2; $lessonPosition++) {
                    $lessonTitle = $lessonPosition === 1 ? 'Conceitos essenciais' : 'Exemplo guiado e aplicação'; if ($moduleIndex === 1) $lessonTitle = $lessonPosition === 1 ? 'Método passo a passo' : 'Caso prático'; if ($moduleIndex === 2) $lessonTitle = $lessonPosition === 1 ? 'Revisão dos pontos críticos' : 'Plano de ação do aluno';
                    $lessonObjective = "Ao final desta aula, o aluno deverá compreender e aplicar {$lessonTitle} dentro do objetivo geral: {$course['objective']}";
                    $script = "Aula {$lessonPosition} — {$lessonTitle}\n\nObjetivo\n{$lessonObjective}\n\nAbertura\nNesta aula vamos avançar no curso “{$course['title']}”, preparado para {$course['audience']}.\n\nBase consolidada das fontes\n{$baseExcerpt}\n\nDesenvolvimento\n1. Contextualize o ponto central com linguagem direta.\n2. Explique os conceitos extraídos das fontes.\n3. Mostre um exemplo aplicado ao público-alvo.\n4. Destaque erros comuns e cuidados práticos.\n\nFechamento\nRetome o objetivo da aula e indique a próxima ação que o aluno deve executar.\n\n[Motor de homologação — revisão humana obrigatória antes da Fábrica do Curso.]";
                    $lessonStmt = $pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script) VALUES(?,?,?,?,?)'); $lessonStmt->execute([$moduleId, $lessonPosition, $lessonTitle, $lessonObjective, $script]);
                }
            }
            $pdo->prepare("UPDATE courses SET status='estrutura_gerada' WHERE id=?")->execute([$courseId]); $pdo->commit(); flash('Estrutura gerada: 3 módulos e 6 aulas. Revise a primeira aula para liberar a Fábrica do Curso.'); go('?course=' . $courseId . '#estrutura');
        }
        if ($action === 'review_lesson') {
            $lessonId = (int)($_POST['lesson_id'] ?? 0); $courseId = (int)($_POST['course_id'] ?? 0); $script = trim((string)($_POST['script'] ?? '')); $notes = trim((string)($_POST['reviewer_notes'] ?? '')); $status = (string)($_POST['review_status'] ?? 'pendente');
            if (!in_array($status, ['pendente','ajustes','aprovada'], true)) $status = 'pendente'; if ($script === '') throw new RuntimeException('O roteiro da aula não pode ficar vazio.');
            $check = $pdo->prepare('SELECT l.id FROM lessons l INNER JOIN modules m ON m.id=l.module_id WHERE l.id=? AND m.course_id=? LIMIT 1'); $check->execute([$lessonId, $courseId]); if (!$check->fetchColumn()) throw new RuntimeException('A aula informada não pertence a este curso.');
            $stmt = $pdo->prepare('UPDATE lessons SET script=?,reviewer_notes=?,review_status=? WHERE id=?'); $stmt->execute([$script, $notes, $status, $lessonId]);
            if ($status === 'aprovada') { $pdo->prepare("UPDATE courses SET status='primeira_aula_revisada' WHERE id=?")->execute([$courseId]); flash('Primeira aula aprovada. Fábrica do Curso liberada.'); go('factory.php?course=' . $courseId); }
            flash('Revisão salva com status: ' . $status . '.'); go('?course=' . $courseId . '#aula1');
        }
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'error'); $courseId = (int)($_POST['course_id'] ?? 0); go($courseId ? '?course=' . $courseId : './'); }
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$courses = $pdo->query('SELECT * FROM courses ORDER BY id DESC')->fetchAll(); $courseId = (int)($_GET['course'] ?? ($courses[0]['id'] ?? 0)); $course = null; $sources = []; $modules = []; $firstLesson = null;
if ($courseId) { $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch(); $stmt = $pdo->prepare('SELECT * FROM sources WHERE course_id=? ORDER BY id DESC'); $stmt->execute([$courseId]); $sources = $stmt->fetchAll(); $stmt = $pdo->prepare('SELECT * FROM modules WHERE course_id=? ORDER BY position'); $stmt->execute([$courseId]); $modules = $stmt->fetchAll(); foreach ($modules as &$module) { $lessonStmt = $pdo->prepare('SELECT * FROM lessons WHERE module_id=? ORDER BY position'); $lessonStmt->execute([$module['id']]); $module['lessons'] = $lessonStmt->fetchAll(); if (!$firstLesson && !empty($module['lessons'])) $firstLesson = $module['lessons'][0]; } unset($module); }
$firstApproved = $firstLesson && $firstLesson['review_status'] === 'aprovada'; $packageDone = $course && $course['status'] === 'pacote_didatico_gerado'; $isCidadesInclusivas = $course && ($course['title'] ?? '') === 'Cidades Inclusivas';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Criador de Cursos — Cursos IA</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell">
<aside class="sidebar"><div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div><nav>
<div class="nav-title">Operação</div><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link active" href="index.php"><span class="dot"></span>Criador de Cursos</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a>
<div class="nav-title">Cursos</div><div class="course-list"><?php foreach($courses as $item):?><a class="course-item <?=$courseId===(int)$item['id']?'active':''?>" href="?course=<?=(int)$item['id']?>"><strong><?=h($item['title'])?></strong><small><?=h($item['status'])?></small></a><?php endforeach;if(!$courses):?><span class="muted">Nenhum curso criado.</span><?php endif;?></div>
<hr class="side-divider"><a class="btn secondary" style="width:100%" href="./">+ Novo curso</a><div class="nav-title">Referência</div><a class="nav-link" href="cidades_inclusivas.php"><span class="dot"></span>Cidades Inclusivas</a><a class="nav-link" href="diagnostic.php"><span class="dot"></span>Diagnóstico</a></nav></aside>
<div class="main"><header class="topbar"><strong>Fábrica Pedagógica · Criador de Cursos</strong><span class="env">HML · v0.9</span></header><main class="content">
<?php if($flash):?><div class="flash <?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif;?>
<?php if(!$course):?><div class="page-title"><div><h1>Novo curso</h1><p>Comece pelo objetivo pedagógico; depois o curso avança por fontes, estrutura, revisão e materiais.</p></div><div class="actions"><a class="btn secondary" href="cidades_inclusivas.php">Ver modelo Cidades Inclusivas</a></div></div><section class="card form-card" style="max-width:820px"><div class="section-title"><h2>Identificação pedagógica</h2><span class="pill">Etapa 1</span></div><form method="post"><input type="hidden" name="action" value="create_course"><div class="form-group"><label class="form-label">Título do curso</label><input name="title" required placeholder="Ex.: Cidades Inclusivas"></div><div class="form-group"><label class="form-label">Público-alvo</label><input name="audience" required placeholder="Quem será capacitado?"></div><div class="form-group"><label class="form-label">Objetivo principal</label><textarea name="objective" required placeholder="O que o aluno deverá ser capaz de compreender ou realizar ao final?"></textarea></div><button class="btn">Criar curso e continuar</button></form></section>
<?php else:?><div class="page-title"><div><h1><?=h($course['title'])?></h1><p><?=h($course['objective'])?></p></div><div class="actions"><?php if($isCidadesInclusivas):?><a class="btn secondary" href="cidades_inclusivas.php">Modelo oficial</a><?php endif;?><?php if($firstApproved):?><a class="btn" href="factory.php?course=<?=$courseId?>">Abrir Fábrica</a><?php endif;?></div></div>
<div class="info-strip"><span class="item">Público: <?=h($course['audience'])?></span><span class="item">Status: <?=h($course['status'])?></span><span class="item"><?=count($sources)?> fontes</span><span class="item"><?=count($modules)?> módulos</span></div>
<div class="pipeline"><span class="stage done">1 Curso</span><span class="stage <?=$sources?'done':''?>">2 Fontes</span><span class="stage <?=$modules?'done':''?>">3 Estrutura</span><span class="stage <?=$firstApproved?'done':''?>">4 Revisão Aula 1</span><span class="stage <?=$packageDone?'done':''?>">5 Fábrica</span><span class="stage">6 Vídeo/HeyGen</span><span class="stage">7 Entrega</span></div>
<section class="grid grid-2" id="fontes" style="margin-bottom:18px"><div class="card form-card"><div class="section-title"><h2>Adicionar fonte textual</h2><span class="pill">Base de conteúdo</span></div><form method="post"><input type="hidden" name="action" value="add_text_source"><input type="hidden" name="course_id" value="<?=$courseId?>"><div class="form-group"><label class="form-label">Nome da fonte</label><input name="name" placeholder="Ex.: Manual interno"></div><div class="form-group"><label class="form-label">Conteúdo</label><textarea name="content" required placeholder="Cole aqui o conteúdo de referência."></textarea></div><button class="btn">Processar texto</button></form></div><div class="card form-card"><div class="section-title"><h2>Upload de fonte</h2><span class="pill">Arquivo</span></div><p class="muted">TXT, MD, CSV, PDF e DOCX até 10 MB. PDF digitalizado com OCR permanece para etapa posterior.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_source"><input type="hidden" name="course_id" value="<?=$courseId?>"><div class="form-group"><label class="form-label">Arquivo</label><input type="file" name="source_file" accept=".txt,.md,.csv,.pdf,.docx" required></div><button class="btn">Enviar e processar</button></form></div></section>
<section class="card" style="margin-bottom:18px"><div class="section-title"><div><h2>Fontes processadas</h2><p class="muted" style="margin:4px 0 0">A estrutura e os materiais devem permanecer ancorados nestas referências.</p></div><span class="pill"><?=count($sources)?> fontes</span></div><div class="grid grid-2"><?php foreach($sources as $source):?><div class="source-card"><strong><?=h($source['name'])?></strong><div class="info-strip" style="margin:6px 0"><span class="pill"><?=h($source['source_type'])?></span><span class="pill ok"><?=h($source['processing_status'])?></span></div><p class="muted" style="margin-bottom:0"><?=h(excerpt((string)$source['content'],220))?></p></div><?php endforeach;if(!$sources):?><div class="empty">Nenhuma fonte adicionada ainda.</div><?php endif;?></div><div style="margin-top:14px"><?php if($isCidadesInclusivas):?><div class="notice"><strong>Estrutura oficial protegida:</strong> Cidades Inclusivas utiliza os 5 módulos homologados. O gerador genérico está desativado para preservar esse modelo.</div><?php else:?><form method="post"><input type="hidden" name="action" value="generate_structure"><input type="hidden" name="course_id" value="<?=$courseId?>"><button class="btn" <?=$sources?'':'disabled'?>>Gerar módulos e aulas</button></form><?php endif;?></div></section>
<?php if($modules):?><section class="card" id="estrutura" style="margin-bottom:18px"><div class="section-title"><div><h2>Programa do curso</h2><p class="muted" style="margin:4px 0 0">Estrutura pedagógica que será usada tanto no online quanto nas turmas presenciais/híbridas.</p></div><span class="pill"><?=count($modules)?> módulos</span></div><?php foreach($modules as $module):?><div class="module-block"><h3>Módulo <?=(int)$module['position']?> — <?=h($module['title'])?></h3><p class="muted"><?=h($module['objective'])?></p><?php foreach($module['lessons'] as $lesson):?><div class="lesson-row"><strong>Aula <?=(int)$lesson['position']?> — <?=h($lesson['title'])?></strong><br><span class="muted"><?=h($lesson['objective'])?></span><br><span class="pill <?=($lesson['review_status']??'')==='aprovada'?'ok':''?>" style="margin-top:6px">Revisão: <?=h($lesson['review_status'])?></span></div><?php endforeach;?></div><?php endforeach;?></section><?php endif;?>
<?php if($firstLesson):?><section class="card form-card" id="aula1" style="margin-bottom:18px"><div class="section-title"><div><h2>Revisão da primeira aula</h2><p class="muted" style="margin:4px 0 0">A aprovação desta aula libera a geração dos materiais do curso.</p></div><span class="pill <?=($firstLesson['review_status']??'')==='aprovada'?'ok':'warn'?>"><?=h($firstLesson['review_status'])?></span></div><form method="post"><input type="hidden" name="action" value="review_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=(int)$firstLesson['id']?>"><div class="form-group"><label class="form-label">Roteiro</label><textarea name="script" style="min-height:360px" required><?=h($firstLesson['script'])?></textarea></div><div class="form-group"><label class="form-label">Observações do revisor</label><textarea name="reviewer_notes"><?=h($firstLesson['reviewer_notes'])?></textarea></div><div class="form-group"><label class="form-label">Status</label><select name="review_status"><option value="pendente" <?=$firstLesson['review_status']==='pendente'?'selected':''?>>Pendente</option><option value="ajustes" <?=$firstLesson['review_status']==='ajustes'?'selected':''?>>Solicitar ajustes</option><option value="aprovada" <?=$firstLesson['review_status']==='aprovada'?'selected':''?>>Aprovada — liberar Fábrica</option></select></div><button class="btn">Salvar revisão</button></form></section><?php endif;?>
<?php if($firstApproved):?><section class="card" style="border-color:#cde8d5;background:#f5fbf7"><div class="section-title"><h2>Fábrica do Curso liberada</h2><span class="pill ok">Etapa 5</span></div><p>Slides, apostila, exercícios, avaliação, página de venda e certificado podem ser gerados a partir das fontes e aulas revisadas.</p><a class="btn" href="factory.php?course=<?=$courseId?>">Abrir Fábrica do Curso</a></section><?php endif;?><?php endif;?></main></div></div></body></html>