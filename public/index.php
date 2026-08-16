<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/source_processor.php';

function db(): PDO {
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

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,audience VARCHAR(180) NOT NULL,objective TEXT NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'rascunho',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,source_type VARCHAR(30) NOT NULL,name VARCHAR(255) NOT NULL,content LONGTEXT NULL,processing_status VARCHAR(40) NOT NULL DEFAULT 'processado',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_sources_course(course_id),CONSTRAINT fk_sources_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS modules (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_module_position(course_id,position),CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,script LONGTEXT NOT NULL,review_status VARCHAR(40) NOT NULL DEFAULT 'pendente',reviewer_notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_lesson_position(module_id,position),CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function go(string $url): never { header('Location: ' . $url); exit; }
function flash(string $message, string $type = 'ok'): void { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; }
function cleanText(string $text): string { return mb_substr(sourceCleanText($text), 0, 250000); }
function excerpt(string $text, int $max = 650): string { $text = sourceCleanText($text); return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max)) . '…'; }
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

$pdo = db();
migrate($pdo);
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_course') {
            $title = trim((string)($_POST['title'] ?? ''));
            $audience = trim((string)($_POST['audience'] ?? ''));
            $objective = trim((string)($_POST['objective'] ?? ''));
            if ($title === '' || $audience === '' || $objective === '') throw new RuntimeException('Preencha título, público-alvo e objetivo do curso.');
            $stmt = $pdo->prepare('INSERT INTO courses(title,audience,objective) VALUES(?,?,?)');
            $stmt->execute([$title, $audience, $objective]);
            $id = (int)$pdo->lastInsertId();
            flash('Curso criado. Agora adicione as fontes de conteúdo.');
            go('?course=' . $id);
        }

        if ($action === 'add_text_source') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? 'Fonte textual'));
            $content = cleanText((string)($_POST['content'] ?? ''));
            if ($courseId < 1 || $content === '') throw new RuntimeException('Informe o conteúdo da fonte.');
            $stmt = $pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status) VALUES(?,?,?,?,?)');
            $stmt->execute([$courseId, 'texto', $name ?: 'Fonte textual', $content, 'processado']);
            flash('Fonte textual processada e vinculada ao curso.');
            go('?course=' . $courseId);
        }

        if ($action === 'upload_source') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            if ($courseId < 1 || !isset($_FILES['source_file'])) throw new RuntimeException('Selecione um arquivo válido.');
            $processed = processUploadedSource($_FILES['source_file']);
            $stmt = $pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status) VALUES(?,?,?,?,?)');
            $stmt->execute([$courseId, 'arquivo_' . $processed['extension'], $processed['name'], $processed['content'], 'processado']);
            flash('Fonte ' . strtoupper($processed['extension']) . ' processada: ' . number_format((int)$processed['characters'], 0, ',', '.') . ' caracteres extraídos.');
            go('?course=' . $courseId);
        }

        if ($action === 'generate_structure') {
            $courseId = (int)($_POST['course_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch();
            if (!$course) throw new RuntimeException('Curso não encontrado.');
            $stmt = $pdo->prepare('SELECT * FROM sources WHERE course_id=? ORDER BY id'); $stmt->execute([$courseId]); $sources = $stmt->fetchAll();
            if (!$sources) throw new RuntimeException('Adicione ao menos uma fonte antes de gerar a estrutura.');
            $sourceText = implode("\n", array_map(fn(array $source): string => (string)$source['content'], $sources));
            $baseExcerpt = excerpt($sourceText, 1400);

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM modules WHERE course_id=?')->execute([$courseId]);
            $defs = [
                ['Fundamentos e contexto', 'Apresentar conceitos essenciais, contexto e vocabulário necessários para iniciar o tema.'],
                ['Aplicação prática', 'Transformar os conceitos das fontes em um processo aplicável pelo aluno.'],
                ['Consolidação e próximos passos', 'Consolidar o aprendizado, revisar pontos críticos e orientar a continuidade prática.'],
            ];
            foreach ($defs as $moduleIndex => $def) {
                $moduleStmt = $pdo->prepare('INSERT INTO modules(course_id,position,title,objective) VALUES(?,?,?,?)');
                $moduleStmt->execute([$courseId, $moduleIndex + 1, $def[0], $def[1]]);
                $moduleId = (int)$pdo->lastInsertId();
                for ($lessonPosition = 1; $lessonPosition <= 2; $lessonPosition++) {
                    $lessonTitle = $lessonPosition === 1 ? 'Conceitos essenciais' : 'Exemplo guiado e aplicação';
                    if ($moduleIndex === 1) $lessonTitle = $lessonPosition === 1 ? 'Método passo a passo' : 'Caso prático';
                    if ($moduleIndex === 2) $lessonTitle = $lessonPosition === 1 ? 'Revisão dos pontos críticos' : 'Plano de ação do aluno';
                    $objective = "Ao final desta aula, o aluno deverá compreender e aplicar {$lessonTitle} dentro do objetivo geral: {$course['objective']}";
                    $script = "Aula {$lessonPosition} — {$lessonTitle}\n\nObjetivo\n{$objective}\n\nAbertura\nNesta aula vamos avançar no curso “{$course['title']}”, preparado para {$course['audience']}.\n\nBase consolidada das fontes\n{$baseExcerpt}\n\nDesenvolvimento\n1. Contextualize o ponto central com linguagem direta.\n2. Explique os conceitos extraídos das fontes.\n3. Mostre um exemplo aplicado ao público-alvo.\n4. Destaque erros comuns e cuidados práticos.\n\nFechamento\nRetome o objetivo da aula e indique a próxima ação que o aluno deve executar.\n\n[Motor de homologação v0.2 — fontes TXT/MD/CSV/PDF/DOCX; revisão humana obrigatória antes de vídeo.]";
                    $lessonStmt = $pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script) VALUES(?,?,?,?,?)');
                    $lessonStmt->execute([$moduleId, $lessonPosition, $lessonTitle, $objective, $script]);
                }
            }
            $pdo->prepare("UPDATE courses SET status='estrutura_gerada' WHERE id=?")->execute([$courseId]);
            $pdo->commit();
            flash('Estrutura gerada: 3 módulos e 6 aulas. Revise a primeira aula antes de avançar.');
            go('?course=' . $courseId . '#estrutura');
        }

        if ($action === 'review_lesson') {
            $lessonId = (int)($_POST['lesson_id'] ?? 0);
            $courseId = (int)($_POST['course_id'] ?? 0);
            $script = trim((string)($_POST['script'] ?? ''));
            $notes = trim((string)($_POST['reviewer_notes'] ?? ''));
            $status = (string)($_POST['review_status'] ?? 'pendente');
            if (!in_array($status, ['pendente', 'ajustes', 'aprovada'], true)) $status = 'pendente';
            if ($script === '') throw new RuntimeException('O roteiro da aula não pode ficar vazio.');
            $stmt = $pdo->prepare('UPDATE lessons SET script=?,reviewer_notes=?,review_status=? WHERE id=?');
            $stmt->execute([$script, $notes, $status, $lessonId]);
            if ($status === 'aprovada') $pdo->prepare("UPDATE courses SET status='primeira_aula_revisada' WHERE id=?")->execute([$courseId]);
            flash('Revisão da aula salva com status: ' . $status . '.');
            go('?course=' . $courseId . '#aula1');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'error');
        $courseId = (int)($_POST['course_id'] ?? 0);
        go($courseId ? '?course=' . $courseId : './');
    }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$courses = $pdo->query('SELECT * FROM courses ORDER BY id DESC')->fetchAll();
$courseId = (int)($_GET['course'] ?? ($courses[0]['id'] ?? 0));
$course = null; $sources = []; $modules = []; $firstLesson = null;
if ($courseId) {
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch();
    $stmt = $pdo->prepare('SELECT * FROM sources WHERE course_id=? ORDER BY id DESC'); $stmt->execute([$courseId]); $sources = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT * FROM modules WHERE course_id=? ORDER BY position'); $stmt->execute([$courseId]); $modules = $stmt->fetchAll();
    foreach ($modules as &$module) {
        $lessonStmt = $pdo->prepare('SELECT * FROM lessons WHERE module_id=? ORDER BY position'); $lessonStmt->execute([$module['id']]); $module['lessons'] = $lessonStmt->fetchAll();
        if (!$firstLesson && !empty($module['lessons'])) $firstLesson = $module['lessons'][0];
    }
    unset($module);
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cursos IA MVP — Criador de Curso</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}.badge{font-size:12px;background:#263a68;padding:7px 10px;border-radius:999px}.layout{display:grid;grid-template-columns:270px 1fr;min-height:calc(100vh - 64px)}aside{background:#fff;border-right:1px solid #e2e7f0;padding:20px}.content{padding:26px;max-width:1180px;width:100%}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 3px 14px rgba(16,24,40,.04)}h1,h2,h3{margin-top:0}label{font-size:13px;font-weight:700;display:block;margin:12px 0 6px}input,textarea,select{width:100%;padding:11px;border:1px solid #cfd6e4;border-radius:9px;font:inherit}textarea{min-height:110px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.btn.secondary{background:#eef2f8;color:#182a52}.muted{color:#667085;font-size:13px}.course-link{display:block;padding:10px;border-radius:8px;text-decoration:none;color:#27324a;margin-bottom:6px}.course-link.active{background:#edf2ff;font-weight:700}.steps{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 20px}.step{font-size:12px;padding:7px 10px;border-radius:999px;background:#edf0f5}.step.done{background:#e8f7ed;color:#176b35}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.source{padding:12px;border:1px solid #e3e7ef;border-radius:10px;margin-bottom:8px}.module{border-left:4px solid #334f8f;padding-left:14px;margin:18px 0}.lesson{margin:8px 0;padding:10px;background:#f8f9fc;border-radius:8px}.flash{padding:12px 14px;border-radius:9px;margin-bottom:16px;background:#eaf7ee;color:#185f34}.flash.error{background:#fff0f0;color:#9b1c1c}.status{font-weight:700;text-transform:uppercase;font-size:11px;letter-spacing:.04em}@media(max-width:800px){.layout{grid-template-columns:1fr}aside{border-right:0;border-bottom:1px solid #e2e7f0}.grid{grid-template-columns:1fr}.content{padding:16px}}</style></head>
<body><div class="top"><strong>Cursos IA MVP</strong><span class="badge">HML · Criador de Curso v0.2</span></div><div class="layout"><aside><h3>Cursos</h3>
<?php foreach ($courses as $item): ?><a class="course-link <?= $courseId === (int)$item['id'] ? 'active' : '' ?>" href="?course=<?= (int)$item['id'] ?>"><?= h($item['title']) ?><br><span class="muted"><?= h($item['status']) ?></span></a><?php endforeach; ?>
<hr style="border:0;border-top:1px solid #eee;margin:18px 0"><a class="btn secondary" href="./">+ Novo curso</a><p class="muted" style="margin-top:18px"><a href="diagnostic.php">Diagnóstico técnico</a></p></aside><main class="content">
<?php if ($flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if (!$course): ?><div class="card"><h1>Criar novo curso</h1><p class="muted">Cadastre o objetivo pedagógico antes das fontes.</p><form method="post"><input type="hidden" name="action" value="create_course"><label>Título do curso</label><input name="title" required><label>Público-alvo</label><input name="audience" required><label>Objetivo principal</label><textarea name="objective" required></textarea><br><button class="btn">Criar curso e continuar</button></form></div>
<?php else: ?><h1><?= h($course['title']) ?></h1><p><?= h($course['objective']) ?></p><div class="steps"><span class="step done">1. Curso criado</span><span class="step <?= $sources ? 'done' : '' ?>">2. Fontes</span><span class="step <?= $modules ? 'done' : '' ?>">3. Estrutura</span><span class="step <?= ($firstLesson && $firstLesson['review_status'] === 'aprovada') ? 'done' : '' ?>">4. Revisão Aula 1</span><span class="step">5. HeyGen depois</span></div>
<div class="grid"><div class="card"><h2>Adicionar fonte textual</h2><form method="post"><input type="hidden" name="action" value="add_text_source"><input type="hidden" name="course_id" value="<?= $courseId ?>"><label>Nome da fonte</label><input name="name" placeholder="Ex.: Manual interno"><label>Conteúdo</label><textarea name="content" required></textarea><br><button class="btn">Processar texto</button></form></div>
<div class="card"><h2>Upload de fonte</h2><p class="muted">TXT, MD, CSV, PDF e DOCX até 10 MB. PDF precisa conter texto pesquisável; PDF digitalizado com OCR entra depois.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_source"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="file" name="source_file" accept=".txt,.md,.csv,.pdf,.docx" required><br><br><button class="btn">Enviar e processar</button></form></div></div>
<div class="card"><h2>Fontes processadas</h2><?php if (!$sources): ?><p class="muted">Nenhuma fonte adicionada.</p><?php else: foreach ($sources as $source): ?><div class="source"><strong><?= h($source['name']) ?></strong> <span class="status">· <?= h($source['source_type']) ?> · <?= h($source['processing_status']) ?></span><p class="muted"><?= h(excerpt((string)$source['content'], 220)) ?></p></div><?php endforeach; endif; ?><form method="post"><input type="hidden" name="action" value="generate_structure"><input type="hidden" name="course_id" value="<?= $courseId ?>"><button class="btn" <?= $sources ? '' : 'disabled' ?>>Gerar módulos e aulas</button></form></div>
<?php if ($modules): ?><div class="card" id="estrutura"><h2>Estrutura do curso</h2><p class="muted">Motor de homologação v0.2: 3 módulos / 6 aulas estruturados a partir das fontes carregadas.</p><?php foreach ($modules as $module): ?><div class="module"><h3>Módulo <?= (int)$module['position'] ?> — <?= h($module['title']) ?></h3><p><?= h($module['objective']) ?></p><?php foreach ($module['lessons'] as $lesson): ?><div class="lesson"><strong>Aula <?= (int)$lesson['position'] ?> — <?= h($lesson['title']) ?></strong><br><span class="muted">Revisão: <?= h($lesson['review_status']) ?></span></div><?php endforeach; ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($firstLesson): ?><div class="card" id="aula1"><h2>Revisão da primeira aula</h2><p class="muted">A aprovação desta aula é o gate antes da integração de vídeo.</p><form method="post"><input type="hidden" name="action" value="review_lesson"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="lesson_id" value="<?= (int)$firstLesson['id'] ?>"><label>Roteiro</label><textarea name="script" style="min-height:340px" required><?= h($firstLesson['script']) ?></textarea><label>Observações do revisor</label><textarea name="reviewer_notes"><?= h($firstLesson['reviewer_notes']) ?></textarea><label>Status</label><select name="review_status"><option value="pendente" <?= $firstLesson['review_status'] === 'pendente' ? 'selected' : '' ?>>Pendente</option><option value="ajustes" <?= $firstLesson['review_status'] === 'ajustes' ? 'selected' : '' ?>>Solicitar ajustes</option><option value="aprovada" <?= $firstLesson['review_status'] === 'aprovada' ? 'selected' : '' ?>>Aprovada para próxima etapa</option></select><br><br><button class="btn">Salvar revisão</button></form></div><?php endif; ?>
<?php endif; ?></main></div></body></html>
