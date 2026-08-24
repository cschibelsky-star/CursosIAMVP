<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/source_processor.php';
require_once __DIR__ . '/ai_generator.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,audience VARCHAR(180) NOT NULL,objective TEXT NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'rascunho',generation_engine VARCHAR(40) NULL,generation_note TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS generation_engine VARCHAR(40) NULL AFTER status");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS generation_note TEXT NULL AFTER generation_engine");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS course_level VARCHAR(40) NULL AFTER objective");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS desired_hours DECIMAL(6,2) NULL AFTER course_level");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS modality VARCHAR(30) NULL AFTER desired_hours");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS language_style VARCHAR(80) NULL AFTER modality");
    $pdo->exec("ALTER TABLE courses ADD COLUMN IF NOT EXISTS expected_outcome TEXT NULL AFTER language_style");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,source_type VARCHAR(30) NOT NULL,name VARCHAR(255) NOT NULL,content LONGTEXT NULL,processing_status VARCHAR(40) NOT NULL DEFAULT 'processado',quality_status VARCHAR(20) NULL,quality_note VARCHAR(255) NULL,active_for_generation TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_sources_course(course_id),CONSTRAINT fk_sources_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS quality_status VARCHAR(20) NULL AFTER processing_status");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS quality_note VARCHAR(255) NULL AFTER quality_status");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS active_for_generation TINYINT(1) NOT NULL DEFAULT 1 AFTER quality_note");
    $pdo->exec("ALTER TABLE sources ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL AFTER active_for_generation");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sources_hash ON sources(content_hash)");
    $pdo->exec("UPDATE sources SET content_hash=SHA2(content,256) WHERE content_hash IS NULL AND content IS NOT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS modules (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_module_position(course_id,position),CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,script LONGTEXT NOT NULL,review_status VARCHAR(40) NOT NULL DEFAULT 'pendente',reviewer_notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_lesson_position(module_id,position),CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function go(string $url): never { header('Location: ' . $url); exit; }
function flash(string $message, string $type = 'ok'): void { $_SESSION['flash'] = ['message' => $message, 'type' => $type]; }
function excerpt(string $text, int $max = 650): string { $text = sourceCleanText($text); return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max)) . '…'; }
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

function fallbackStructure(array $course, array $sources): array {
    $base = excerpt(implode("\n", array_map(fn(array $s): string => (string)$s['content'], $sources)), 1800);
    $defs = [
        ['Fundamentos e contexto', 'Apresentar conceitos essenciais, contexto e vocabulário necessários para iniciar o tema.', ['Conceitos essenciais','Exemplo guiado e aplicação']],
        ['Aplicação prática', 'Transformar os conceitos das fontes em um processo aplicável pelo aluno.', ['Método passo a passo','Caso prático']],
        ['Consolidação e próximos passos', 'Consolidar o aprendizado, revisar pontos críticos e orientar a continuidade prática.', ['Revisão dos pontos críticos','Plano de ação do aluno']],
    ];
    $modules = [];
    foreach ($defs as $mi => $def) {
        $lessons = [];
        foreach ($def[2] as $li => $lessonTitle) {
            $objective = "Ao final desta aula, o aluno deverá compreender e aplicar {$lessonTitle} dentro do objetivo geral: {$course['objective']}";
            $script = "Aula " . ($li + 1) . " — {$lessonTitle}\n\nObjetivo\n{$objective}\n\nAbertura\nNesta aula vamos avançar no curso “{$course['title']}”, preparado para {$course['audience']}.\n\nBase consolidada das fontes\n{$base}\n\nDesenvolvimento\n1. Contextualize o ponto central.\n2. Explique os conceitos identificados nas fontes.\n3. Mostre um exemplo aplicado ao público-alvo.\n4. Destaque cuidados práticos.\n\nFechamento\nRetome o objetivo e proponha uma atividade ou reflexão final.\n\n[Fallback pedagógico v0.3 — revisão humana obrigatória.]";
            $lessons[] = ['position'=>$li+1,'title'=>$lessonTitle,'objective'=>$objective,'script'=>$script];
        }
        $modules[] = ['position'=>$mi+1,'title'=>$def[0],'objective'=>$def[1],'lessons'=>$lessons];
    }
    return ['modules'=>$modules];
}

function saveStructure(PDO $pdo, int $courseId, array $structure, string $engine, ?string $note = null): void {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM modules WHERE course_id=?')->execute([$courseId]);
        foreach ($structure['modules'] as $moduleIndex => $module) {
            $m = $pdo->prepare('INSERT INTO modules(course_id,position,title,objective) VALUES(?,?,?,?)');
            $m->execute([$courseId,$moduleIndex+1,$module['title'],$module['objective']]);
            $moduleId = (int)$pdo->lastInsertId();
            foreach ($module['lessons'] as $lessonIndex => $lesson) {
                $l = $pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script) VALUES(?,?,?,?,?)');
                $l->execute([$moduleId,$lessonIndex+1,$lesson['title'],$lesson['objective'],$lesson['script']]);
            }
        }
        $u = $pdo->prepare("UPDATE courses SET status='estrutura_gerada',generation_engine=?,generation_note=? WHERE id=?");
        $u->execute([$engine,$note,$courseId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$pdo = db(); migrate($pdo); $action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_course') {
            $title=trim((string)($_POST['title']??'')); $audience=trim((string)($_POST['audience']??'')); $objective=trim((string)($_POST['objective']??''));
            $level=trim((string)($_POST['course_level']??'')); $hours=(float)($_POST['desired_hours']??0); $modality=trim((string)($_POST['modality']??'')); $style=trim((string)($_POST['language_style']??'')); $outcome=trim((string)($_POST['expected_outcome']??''));
            if ($title===''||$audience===''||$objective==='') throw new RuntimeException('Preencha título, público-alvo e objetivo do curso.');
            $s=$pdo->prepare('INSERT INTO courses(title,audience,objective,course_level,desired_hours,modality,language_style,expected_outcome) VALUES(?,?,?,?,?,?,?,?)'); $s->execute([$title,$audience,$objective,$level?:null,$hours>0?$hours:null,$modality?:null,$style?:null,$outcome?:null]);
            $id=(int)$pdo->lastInsertId(); flash('Curso criado. Agora adicione as fontes de conteúdo.'); go('?course='.$id);
        }
        if ($action === 'add_text_source') {
            $cid=(int)($_POST['course_id']??0); $name=trim((string)($_POST['name']??'Fonte textual')); $content=mb_substr(sourceCleanText((string)($_POST['content']??'')),0,250000);
            if ($cid<1||$content==='') throw new RuntimeException('Informe o conteúdo da fonte.');
            $quality=sourceQuality($content); $hash=hash('sha256',$content);
            $d=$pdo->prepare('SELECT name FROM sources WHERE course_id=? AND content_hash=? LIMIT 1'); $d->execute([$cid,$hash]);
            if($dup=$d->fetchColumn()) throw new RuntimeException('Fonte duplicada: o mesmo conteúdo já existe em “'.$dup.'”.');
            $s=$pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status,quality_status,quality_note,active_for_generation,content_hash) VALUES(?,?,?,?,?,?,?,1,?)');
            $s->execute([$cid,'texto',$name?:'Fonte textual',$content,'processado',$quality['status'],$quality['note'],$hash]);
            flash('Fonte textual processada. Qualidade: '.$quality['status'].'.'); go('?course='.$cid);
        }
        if ($action === 'upload_source') {
            $cid=(int)($_POST['course_id']??0); if($cid<1||!isset($_FILES['source_file'])) throw new RuntimeException('Selecione um arquivo válido.');
            $p=processUploadedSource($_FILES['source_file']);
            $quality=sourceQuality((string)$p['content']); $hash=hash('sha256',(string)$p['content']);
            $d=$pdo->prepare('SELECT name FROM sources WHERE course_id=? AND content_hash=? LIMIT 1'); $d->execute([$cid,$hash]);
            if($dup=$d->fetchColumn()) throw new RuntimeException('Fonte duplicada: o mesmo conteúdo já existe em “'.$dup.'”.');
            $s=$pdo->prepare('INSERT INTO sources(course_id,source_type,name,content,processing_status,quality_status,quality_note,active_for_generation,content_hash) VALUES(?,?,?,?,?,?,?,1,?)');
            $s->execute([$cid,'arquivo_'.$p['extension'],$p['name'],$p['content'],'processado',$quality['status'],$quality['note'],$hash]);
            flash('Fonte '.strtoupper($p['extension']).' processada: '.number_format((int)$p['characters'],0,',','.').' caracteres. Qualidade: '.$quality['status'].'.'); go('?course='.$cid);
        }
        if ($action === 'toggle_source') {
            $cid=(int)($_POST['course_id']??0); $sourceId=(int)($_POST['source_id']??0); $active=(int)($_POST['active_for_generation']??0)===1?1:0;
            if($cid<1||$sourceId<1) throw new RuntimeException('Fonte inválida.');
            $s=$pdo->prepare('UPDATE sources SET active_for_generation=? WHERE id=? AND course_id=?');
            $s->execute([$active,$sourceId,$cid]);
            flash($active?'Fonte ativada para geração por IA.':'Fonte removida da geração por IA.'); go('?course='.$cid.'#fontes');
        }
        if ($action === 'generate_structure') {
            $cid=(int)($_POST['course_id']??0); $s=$pdo->prepare('SELECT * FROM courses WHERE id=?'); $s->execute([$cid]); $course=$s->fetch(); if(!$course) throw new RuntimeException('Curso não encontrado.');
            $s=$pdo->prepare('SELECT * FROM sources WHERE course_id=? AND active_for_generation=1 ORDER BY id'); $s->execute([$cid]); $sources=$s->fetchAll(); if(!$sources) throw new RuntimeException('Ative ao menos uma fonte válida antes de gerar a estrutura.');
            $engine='fallback'; $note=null;
            if(aiIsReady()) {
                try { $structure=generateCourseWithAI($course,$sources); $engine='ia'; }
                catch(Throwable $aiError) { $structure=fallbackStructure($course,$sources); $note='IA falhou e o fallback foi usado: '.$aiError->getMessage(); }
            } else { $structure=fallbackStructure($course,$sources); $note='Motor de IA ainda não configurado no ambiente HML.'; }
            saveStructure($pdo,$cid,$structure,$engine,$note);
            flash($engine==='ia'?'Estrutura gerada pela IA a partir das fontes. Revise a Aula 1.':'Estrutura gerada pelo fallback. A IA ainda precisa de configuração para ser usada.');
            go('?course='.$cid.'#estrutura');
        }
        if ($action === 'update_module') {
            $cid=(int)($_POST['course_id']??0); $moduleId=(int)($_POST['module_id']??0); $title=trim((string)($_POST['title']??'')); $objective=trim((string)($_POST['objective']??''));
            if($cid<1||$moduleId<1||$title===''||$objective==='') throw new RuntimeException('Informe título e objetivo do módulo.');
            $s=$pdo->prepare('UPDATE modules SET title=?,objective=? WHERE id=? AND course_id=?'); $s->execute([$title,$objective,$moduleId,$cid]);
            flash('Módulo atualizado.'); go('?course='.$cid.'#estrutura');
        }
        if ($action === 'update_lesson_meta') {
            $cid=(int)($_POST['course_id']??0); $lessonId=(int)($_POST['lesson_id']??0); $title=trim((string)($_POST['title']??'')); $objective=trim((string)($_POST['objective']??''));
            if($cid<1||$lessonId<1||$title===''||$objective==='') throw new RuntimeException('Informe título e objetivo da aula.');
            $s=$pdo->prepare('UPDATE lessons l INNER JOIN modules m ON m.id=l.module_id SET l.title=?,l.objective=? WHERE l.id=? AND m.course_id=?'); $s->execute([$title,$objective,$lessonId,$cid]);
            flash('Aula atualizada.'); go('?course='.$cid.'#estrutura');
        }
        if ($action === 'add_lesson') {
            $cid=(int)($_POST['course_id']??0); $moduleId=(int)($_POST['module_id']??0); $title=trim((string)($_POST['title']??'')); $objective=trim((string)($_POST['objective']??''));
            if($cid<1||$moduleId<1||$title===''||$objective==='') throw new RuntimeException('Informe título e objetivo da nova aula.');
            $s=$pdo->prepare('SELECT COUNT(*) FROM modules WHERE id=? AND course_id=?'); $s->execute([$moduleId,$cid]); if((int)$s->fetchColumn()!==1) throw new RuntimeException('Módulo inválido.');
            $s=$pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM lessons WHERE module_id=?'); $s->execute([$moduleId]); $position=(int)$s->fetchColumn();
            $script="Aula em elaboração.\n\nObjetivo\n{$objective}\n\n[Conteúdo a ser desenvolvido ou regenerado pela IA.]";
            $s=$pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script,review_status) VALUES(?,?,?,?,?,\'pendente\')'); $s->execute([$moduleId,$position,$title,$objective,$script]);
            flash('Nova aula adicionada ao módulo.'); go('?course='.$cid.'#estrutura');
        }
        if ($action === 'delete_lesson') {
            $cid=(int)($_POST['course_id']??0); $lessonId=(int)($_POST['lesson_id']??0);
            if($cid<1||$lessonId<1) throw new RuntimeException('Aula inválida.');
            $pdo->beginTransaction();
            $s=$pdo->prepare('SELECT l.module_id FROM lessons l INNER JOIN modules m ON m.id=l.module_id WHERE l.id=? AND m.course_id=?'); $s->execute([$lessonId,$cid]); $moduleId=(int)($s->fetchColumn()?:0); if($moduleId<1) throw new RuntimeException('Aula não pertence ao curso.');
            $pdo->prepare('DELETE FROM lessons WHERE id=?')->execute([$lessonId]);
            $s=$pdo->prepare('SELECT id FROM lessons WHERE module_id=? ORDER BY position,id'); $s->execute([$moduleId]); $pos=1; foreach($s->fetchAll() as $row){$pdo->prepare('UPDATE lessons SET position=? WHERE id=?')->execute([$pos++,(int)$row['id']]);}
            $pdo->commit(); flash('Aula removida e posições reorganizadas.'); go('?course='.$cid.'#estrutura');
        }
        if ($action === 'regenerate_module') {
            $cid=(int)($_POST['course_id']??0); $moduleId=(int)($_POST['module_id']??0);
            if($cid<1||$moduleId<1) throw new RuntimeException('Módulo inválido.');
            $s=$pdo->prepare('SELECT * FROM courses WHERE id=?'); $s->execute([$cid]); $course=$s->fetch(); if(!$course) throw new RuntimeException('Curso não encontrado.');
            $s=$pdo->prepare('SELECT * FROM modules WHERE id=? AND course_id=?'); $s->execute([$moduleId,$cid]); $module=$s->fetch(); if(!$module) throw new RuntimeException('Módulo não encontrado.');
            $s=$pdo->prepare('SELECT * FROM sources WHERE course_id=? AND active_for_generation=1 ORDER BY id'); $s->execute([$cid]); $activeSources=$s->fetchAll(); if(!$activeSources) throw new RuntimeException('Ative ao menos uma fonte antes de regenerar o módulo.');
            $s=$pdo->prepare('SELECT title FROM modules WHERE course_id=? ORDER BY position'); $s->execute([$cid]); $outline=array_map(fn(array $r): string => (string)$r['title'],$s->fetchAll());
            $generated=generateModuleWithAI($course,$activeSources,$module,$outline);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE modules SET title=?,objective=? WHERE id=?')->execute([$generated['title'],$generated['objective'],$moduleId]);
            $pdo->prepare('DELETE FROM lessons WHERE module_id=?')->execute([$moduleId]);
            foreach($generated['lessons'] as $i=>$lesson){$s=$pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script,review_status) VALUES(?,?,?,?,?,\'pendente\')');$s->execute([$moduleId,$i+1,$lesson['title'],$lesson['objective'],$lesson['script']]);}
            $pdo->commit();
            flash('Módulo regenerado pela IA sem alterar os demais módulos.'); go('?course='.$cid.'#estrutura');
        }
        if ($action === 'review_lesson') {
            $lid=(int)($_POST['lesson_id']??0); $cid=(int)($_POST['course_id']??0); $script=trim((string)($_POST['script']??'')); $notes=trim((string)($_POST['reviewer_notes']??'')); $status=(string)($_POST['review_status']??'pendente');
            if(!in_array($status,['pendente','ajustes','aprovada'],true))$status='pendente'; if($script==='')throw new RuntimeException('O roteiro da aula não pode ficar vazio.');
            $s=$pdo->prepare('UPDATE lessons SET script=?,reviewer_notes=?,review_status=? WHERE id=?'); $s->execute([$script,$notes,$status,$lid]);
            if($status==='aprovada')$pdo->prepare("UPDATE courses SET status='primeira_aula_revisada' WHERE id=?")->execute([$cid]);
            flash('Revisão salva: '.$status.'.'); go('?course='.$cid.'#aula1');
        }
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack(); flash($e->getMessage(),'error'); $cid=(int)($_POST['course_id']??0); go($cid?'?course='.$cid:'./');
    }
}

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']); $courses=$pdo->query('SELECT * FROM courses ORDER BY id DESC')->fetchAll();
$courseId=(int)($_GET['course']??($courses[0]['id']??0)); $course=null;$sources=[];$modules=[];$firstLesson=null;
if($courseId){$s=$pdo->prepare('SELECT * FROM courses WHERE id=?');$s->execute([$courseId]);$course=$s->fetch();$s=$pdo->prepare('SELECT * FROM sources WHERE course_id=? ORDER BY id DESC');$s->execute([$courseId]);$sources=$s->fetchAll();$s=$pdo->prepare('SELECT * FROM modules WHERE course_id=? ORDER BY position');$s->execute([$courseId]);$modules=$s->fetchAll();foreach($modules as &$m){$ls=$pdo->prepare('SELECT * FROM lessons WHERE module_id=? ORDER BY position');$ls->execute([$m['id']]);$m['lessons']=$ls->fetchAll();if(!$firstLesson&&!empty($m['lessons']))$firstLesson=$m['lessons'][0];}unset($m);}
$aiReady=aiIsReady();
$activeSourceCount=count(array_filter($sources,fn(array $s): bool => (int)($s['active_for_generation']??1)===1));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cursos IA MVP</title><style>:root{font-family:Inter,system-ui,sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}.badge{font-size:12px;background:#263a68;padding:7px 10px;border-radius:999px}.layout{display:grid;grid-template-columns:270px 1fr;min-height:calc(100vh - 64px)}aside{background:#fff;border-right:1px solid #e2e7f0;padding:20px}.content{padding:26px;max-width:1180px;width:100%}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:20px;margin-bottom:18px}.ai{padding:12px 14px;border-radius:10px;margin-bottom:16px;background:#eef4ff}.ai.ready{background:#eaf7ee;color:#185f34}.ai.warn{background:#fff7e7;color:#8a5b00}label{font-size:13px;font-weight:700;display:block;margin:12px 0 6px}input,textarea,select{width:100%;padding:11px;border:1px solid #cfd6e4;border-radius:9px;font:inherit}textarea{min-height:110px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.secondary{background:#eef2f8;color:#182a52}.muted{color:#667085;font-size:13px}.course-link{display:block;padding:10px;border-radius:8px;text-decoration:none;color:#27324a;margin-bottom:6px}.active{background:#edf2ff;font-weight:700}.steps{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 20px}.step{font-size:12px;padding:7px 10px;border-radius:999px;background:#edf0f5}.done{background:#e8f7ed;color:#176b35}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.source{padding:12px;border:1px solid #e3e7ef;border-radius:10px;margin-bottom:8px}.module{border-left:4px solid #334f8f;padding-left:14px;margin:18px 0}.lesson{margin:8px 0;padding:10px;background:#f8f9fc;border-radius:8px}.flash{padding:12px 14px;border-radius:9px;margin-bottom:16px;background:#eaf7ee;color:#185f34}.error{background:#fff0f0;color:#9b1c1c}@media(max-width:800px){.layout{grid-template-columns:1fr}.grid{grid-template-columns:1fr}.content{padding:16px}}</style></head><body>
<div class="top"><strong>Cursos IA MVP</strong><span class="badge">HML · Criador v0.3</span></div><div class="layout"><aside><h3>Cursos</h3><?php foreach($courses as $c):?><a class="course-link <?=$courseId===(int)$c['id']?'active':''?>" href="?course=<?=(int)$c['id']?>"><?=h($c['title'])?><br><span class="muted"><?=h($c['status'])?></span></a><?php endforeach;?><hr><a class="btn secondary" href="./">+ Novo curso</a><p class="muted"><a href="diagnostic.php">Diagnóstico técnico</a></p></aside><main class="content">
<?php if($flash):?><div class="flash <?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif;?>
<div class="ai <?=$aiReady?'ready':'warn'?>"><strong>Motor de IA:</strong> <?=$aiReady?'configurado e pronto para geração pelas fontes.':'não configurado na HML; o fallback pedagógico mantém o fluxo operacional.'?></div>
<?php if(!$course):?><div class="card"><h1>Briefing Inteligente do Curso</h1><p class="muted">Defina o contexto pedagógico antes de enviar as fontes. Estes dados serão usados pela IA na arquitetura do curso.</p><form method="post"><input type="hidden" name="action" value="create_course"><label>Título</label><input name="title" required><label>Público-alvo</label><input name="audience" required><label>Objetivo principal</label><textarea name="objective" required></textarea><div class="grid"><div><label>Nível</label><select name="course_level"><option value="">Não definido</option><option value="iniciante">Iniciante</option><option value="intermediario">Intermediário</option><option value="avancado">Avançado</option></select></div><div><label>Duração desejada (horas)</label><input name="desired_hours" type="number" min="0" step="0.5" placeholder="Ex.: 8"></div></div><div class="grid"><div><label>Modalidade</label><select name="modality"><option value="">Não definida</option><option value="online">Online</option><option value="hibrido">Híbrido</option><option value="presencial">Presencial</option></select></div><div><label>Linguagem</label><select name="language_style"><option value="">Padrão</option><option value="didatica">Didática</option><option value="tecnica">Técnica</option><option value="executiva">Executiva</option><option value="simples_pratica">Simples e prática</option></select></div></div><label>Resultado esperado do aluno</label><textarea name="expected_outcome" placeholder="O que o aluno deverá ser capaz de fazer ao final do curso?"></textarea><br><button class="btn">Criar curso e continuar para as fontes</button></form></div><?php else:?>
<h1><?=h($course['title'])?></h1><p><?=h($course['objective'])?></p><div class="card"><div class="grid"><div><strong>Nível</strong><br><span class="muted"><?=h($course['course_level']?:'não definido')?></span></div><div><strong>Duração desejada</strong><br><span class="muted"><?= $course['desired_hours']?number_format((float)$course['desired_hours'],1,',','.').' h':'não definida' ?></span></div><div><strong>Modalidade</strong><br><span class="muted"><?=h($course['modality']?:'não definida')?></span></div><div><strong>Linguagem</strong><br><span class="muted"><?=h($course['language_style']?:'padrão didático')?></span></div></div><?php if(!empty($course['expected_outcome'])):?><p style="margin-bottom:0"><strong>Resultado esperado:</strong> <?=h($course['expected_outcome'])?></p><?php endif;?></div><div class="steps"><span class="step done">1. Briefing</span><span class="step <?=$sources?'done':''?>">2. Fontes</span><span class="step <?=$modules?'done':''?>">3. Arquitetura IA</span><span class="step <?=($firstLesson&&$firstLesson['review_status']==='aprovada')?'done':''?>">4. Revisão Aula 1</span><span class="step">5. Materiais IA</span><span class="step">6. HeyGen depois</span></div>
<div class="grid"><div class="card"><h2>Fonte textual</h2><form method="post"><input type="hidden" name="action" value="add_text_source"><input type="hidden" name="course_id" value="<?=$courseId?>"><label>Nome</label><input name="name"><label>Conteúdo</label><textarea name="content" required></textarea><br><button class="btn">Processar texto</button></form></div><div class="card"><h2>Upload de fonte</h2><p class="muted">TXT, MD, CSV, PDF e DOCX até 10 MB.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_source"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="file" name="source_file" accept=".txt,.md,.csv,.pdf,.docx" required><br><br><button class="btn">Enviar e processar</button></form></div></div>
<div class="card" id="fontes"><h2>Central de Fontes</h2><p class="muted"><?=$activeSourceCount?> fonte(s) ativa(s) serão enviadas para a geração pedagógica.</p><?php if(!$sources):?><p class="muted">Nenhuma fonte adicionada.</p><?php else:foreach($sources as $s):$q=($s['quality_status']??'')!==''?['status'=>$s['quality_status'],'note'=>$s['quality_note']??'']:sourceQuality((string)$s['content']);$active=(int)($s['active_for_generation']??1)===1;?><div class="source"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start"><div><strong><?=h($s['name'])?></strong> · <span class="muted"><?=h($s['source_type'])?></span><br><span class="muted"><?=number_format(mb_strlen((string)$s['content']),0,',','.')?> caracteres · qualidade <?=h($q['status'])?></span></div><form method="post"><input type="hidden" name="action" value="toggle_source"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="source_id" value="<?=(int)$s['id']?>"><input type="hidden" name="active_for_generation" value="<?=$active?0:1?>"><button class="btn secondary" type="submit"><?=$active?'Desativar':'Ativar'?></button></form></div><p class="muted" style="margin:8px 0 4px"><?=h($q['note']??'')?></p><p class="muted"><?=h(excerpt((string)$s['content'],260))?></p><span class="step <?=$active?'done':''?>"><?=$active?'Usada pela IA':'Ignorada na geração'?></span></div><?php endforeach;endif;?><form method="post"><input type="hidden" name="action" value="generate_structure"><input type="hidden" name="course_id" value="<?=$courseId?>"><button class="btn" <?=$activeSourceCount>0?'':'disabled'?>><?=$aiReady?'Gerar curso com IA usando '.$activeSourceCount.' fonte(s)':'Gerar estrutura em fallback'?></button></form></div>
<?php if($modules):?><div class="card" id="estrutura"><h2>Arquitetura Pedagógica Editável</h2><p class="muted">Motor usado: <strong><?=h($course['generation_engine']?:'legado')?></strong><?php if(!empty($course['generation_note'])):?> — <?=h($course['generation_note'])?><?php endif;?></p><?php foreach($modules as $m):?><div class="module"><form method="post"><input type="hidden" name="action" value="update_module"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="module_id" value="<?=(int)$m['id']?>"><label>Módulo <?=(int)$m['position']?> — título</label><input name="title" value="<?=h($m['title'])?>" required><label>Objetivo do módulo</label><textarea name="objective" required><?=h($m['objective'])?></textarea><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn secondary" type="submit">Salvar módulo</button></form><form method="post"><input type="hidden" name="action" value="regenerate_module"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="module_id" value="<?=(int)$m['id']?>"><button class="btn" type="submit" <?=$aiReady?'':'disabled'?>>Regenerar este módulo com IA</button></form></div><?php foreach($m['lessons'] as $l):?><div class="lesson"><form method="post"><input type="hidden" name="action" value="update_lesson_meta"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=(int)$l['id']?>"><label>Aula <?=(int)$l['position']?> — título</label><input name="title" value="<?=h($l['title'])?>" required><label>Objetivo da aula</label><textarea name="objective" required><?=h($l['objective'])?></textarea><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn secondary" type="submit">Salvar aula</button></form><a class="btn secondary" href="lesson_editor.php?course=<?=$courseId?>&lesson=<?=(int)$l['id']?>">Produzir aula</a><form method="post" onsubmit="return confirm('Remover esta aula?');"><input type="hidden" name="action" value="delete_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=(int)$l['id']?>"><button class="btn secondary" type="submit">Remover aula</button></form><span class="step <?=($l['review_status']==='aprovada')?'done':''?>">Revisão: <?=h($l['review_status'])?></span></div></div><?php endforeach;?><div class="lesson"><strong>Adicionar aula neste módulo</strong><form method="post"><input type="hidden" name="action" value="add_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="module_id" value="<?=(int)$m['id']?>"><label>Título</label><input name="title" required><label>Objetivo</label><textarea name="objective" required></textarea><button class="btn secondary" type="submit">Adicionar aula</button></form></div></div><?php endforeach;?></div><?php endif;?>
<?php if($firstLesson):?><div class="card" id="aula1"><h2>Revisão da primeira aula</h2><form method="post"><input type="hidden" name="action" value="review_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=(int)$firstLesson['id']?>"><label>Roteiro</label><textarea name="script" style="min-height:360px" required><?=h($firstLesson['script'])?></textarea><label>Observações</label><textarea name="reviewer_notes"><?=h($firstLesson['reviewer_notes'])?></textarea><label>Status</label><select name="review_status"><option value="pendente" <?=$firstLesson['review_status']==='pendente'?'selected':''?>>Pendente</option><option value="ajustes" <?=$firstLesson['review_status']==='ajustes'?'selected':''?>>Solicitar ajustes</option><option value="aprovada" <?=$firstLesson['review_status']==='aprovada'?'selected':''?>>Aprovada</option></select><br><br><button class="btn">Salvar revisão</button></form></div><?php endif;?>
<?php endif;?></main></div></body></html>
