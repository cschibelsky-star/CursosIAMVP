<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/source_processor.php';
require_once __DIR__ . '/ai_generator.php';

function leDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function leh(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function lego(int $courseId,int $lessonId): never { header('Location: lesson_editor.php?course='.$courseId.'&lesson='.$lessonId); exit; }
function syncCoursePedagogyStatus(PDO $pdo,int $courseId): array
{
    $stmt=$pdo->prepare("SELECT COUNT(*) total,
        SUM(CASE WHEN l.review_status='aprovada' THEN 1 ELSE 0 END) approved,
        SUM(CASE WHEN l.review_status='ajustes' THEN 1 ELSE 0 END) adjustments,
        SUM(CASE WHEN l.review_status='pendente' THEN 1 ELSE 0 END) pending
        FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=?");
    $stmt->execute([$courseId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
    $total=(int)($row['total']??0); $approved=(int)($row['approved']??0);
    $status=$total>0&&$approved===$total?'homologado_pedagogico':'estrutura_gerada';
    $pdo->prepare('UPDATE courses SET status=? WHERE id=?')->execute([$status,$courseId]);
    return ['total'=>$total,'approved'=>$approved,'adjustments'=>(int)($row['adjustments']??0),'pending'=>(int)($row['pending']??0),'homologated'=>$status==='homologado_pedagogico'];
}

$pdo=leDb();
$courseId=(int)($_GET['course']??$_POST['course_id']??0);
$lessonId=(int)($_GET['lesson']??$_POST['lesson_id']??0);
if($courseId<1||$lessonId<1){http_response_code(400);echo 'Curso ou aula inválidos.';exit;}

$stmt=$pdo->prepare("SELECT l.*,m.id module_id,m.title module_title,m.objective module_objective,m.position module_position,c.title course_title,c.audience,c.objective course_objective,c.status course_status
FROM lessons l INNER JOIN modules m ON m.id=l.module_id INNER JOIN courses c ON c.id=m.course_id WHERE l.id=? AND c.id=?");
$stmt->execute([$lessonId,$courseId]);
$lesson=$stmt->fetch();
if(!$lesson){http_response_code(404);echo 'Aula não encontrada.';exit;}

$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_lesson'){
            $title=trim((string)($_POST['title']??''));
            $objective=trim((string)($_POST['objective']??''));
            $script=trim((string)($_POST['script']??''));
            $notes=trim((string)($_POST['reviewer_notes']??''));
            $status=(string)($_POST['review_status']??'pendente');
            if($title===''||$objective===''||$script==='') throw new RuntimeException('Título, objetivo e roteiro são obrigatórios.');
            if(!in_array($status,['pendente','ajustes','aprovada'],true))$status='pendente';
            $stmt=$pdo->prepare('UPDATE lessons SET title=?,objective=?,script=?,reviewer_notes=?,review_status=? WHERE id=?');
            $stmt->execute([$title,$objective,$script,$notes?:null,$status,$lessonId]);
            $pedagogy=syncCoursePedagogyStatus($pdo,$courseId);
            $_SESSION['lesson_editor_flash']=$pedagogy['homologated']?'Aula salva. Curso homologado pedagogicamente: todas as aulas estão aprovadas.':'Aula salva. Homologação: '.$pedagogy['approved'].'/'.$pedagogy['total'].' aulas aprovadas.';
            lego($courseId,$lessonId);
        }
        if($action==='regenerate_lesson'){
            if(!aiIsReady()) throw new RuntimeException('Motor de IA não está pronto para regeneração seletiva.');
            $stmt=$pdo->prepare('SELECT * FROM sources WHERE course_id=? AND active_for_generation=1 ORDER BY id');
            $stmt->execute([$courseId]);$sources=$stmt->fetchAll();
            if(!$sources) throw new RuntimeException('Ative ao menos uma fonte antes de regenerar a aula.');
            $stmt=$pdo->prepare('SELECT title FROM lessons WHERE module_id=? ORDER BY position');
            $stmt->execute([(int)$lesson['module_id']]);$siblings=array_map(fn(array $r): string => (string)$r['title'],$stmt->fetchAll());
            $generated=generateLessonWithAI(
                ['title'=>$lesson['course_title'],'audience'=>$lesson['audience'],'objective'=>$lesson['course_objective']],
                $sources,
                ['title'=>$lesson['module_title'],'objective'=>$lesson['module_objective']],
                ['title'=>$lesson['title'],'objective'=>$lesson['objective'],'script'=>$lesson['script']],
                $siblings
            );
            $stmt=$pdo->prepare("UPDATE lessons SET title=?,objective=?,script=?,review_status='pendente',reviewer_notes=NULL WHERE id=?");
            $stmt->execute([$generated['title'],$generated['objective'],$generated['script'],$lessonId]);
            $pedagogy=syncCoursePedagogyStatus($pdo,$courseId);
            $_SESSION['lesson_editor_flash']='Aula regenerada pelo Centro IA. Revise antes de aprovar. Homologação atual: '.$pedagogy['approved'].'/'.$pedagogy['total'].' aulas aprovadas.';
            lego($courseId,$lessonId);
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$flash=$_SESSION['lesson_editor_flash']??null;unset($_SESSION['lesson_editor_flash']);
$stmt=$pdo->prepare("SELECT l.*,m.id module_id,m.title module_title,m.objective module_objective,m.position module_position,c.title course_title,c.audience,c.objective course_objective,c.status course_status
FROM lessons l INNER JOIN modules m ON m.id=l.module_id INNER JOIN courses c ON c.id=m.course_id WHERE l.id=? AND c.id=?");
$stmt->execute([$lessonId,$courseId]);$lesson=$stmt->fetch();
$aiReady=aiIsReady();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Produção da Aula — <?=leh($lesson['title'])?></title><link rel="stylesheet" href="assets/app.css"><style>.editor-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.script-box{min-height:560px}.sticky{position:sticky;top:18px}.stack>*+*{margin-top:10px}@media(max-width:960px){.editor-grid{grid-template-columns:1fr}.sticky{position:static}}</style></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Produção de aula com IA</small></div><nav><div class="nav-title">Curso</div><a class="nav-link" href="index.php?course=<?=$courseId?>#estrutura"><span class="dot"></span>Arquitetura pedagógica</a><a class="nav-link active" href="lesson_editor.php?course=<?=$courseId?>&lesson=<?=$lessonId?>"><span class="dot"></span>Produção da aula</a><a class="nav-link" href="factory.php?course=<?=$courseId?>"><span class="dot"></span>Materiais IA</a></nav></aside>
<div class="main"><header class="topbar"><strong>Produção Individual da Aula</strong><span class="env">HML · Centro IA</span></header><main class="content">
<?php if($flash):?><div class="flash"><?=leh($flash)?></div><?php endif;?><?php if($error):?><div class="flash error"><?=leh($error)?></div><?php endif;?>
<div class="page-title"><div><h1><?=leh($lesson['title'])?></h1><p><?=leh($lesson['course_title'])?> · Módulo <?=(int)$lesson['module_position']?> — <?=leh($lesson['module_title'])?></p></div><div class="actions"><a class="btn secondary" href="index.php?course=<?=$courseId?>#estrutura">Voltar à arquitetura</a></div></div>
<div class="editor-grid"><section class="card"><form method="post"><input type="hidden" name="action" value="save_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=$lessonId?>"><label>Título da aula</label><input class="input" name="title" value="<?=leh($lesson['title'])?>" required><label>Objetivo da aula</label><textarea class="input" name="objective" rows="4" required><?=leh($lesson['objective'])?></textarea><label>Roteiro completo</label><textarea class="input script-box" name="script" required><?=leh($lesson['script'])?></textarea><label>Observações do revisor</label><textarea class="input" name="reviewer_notes" rows="4"><?=leh($lesson['reviewer_notes'])?></textarea><label>Status da revisão</label><select class="input" name="review_status"><option value="pendente" <?=$lesson['review_status']==='pendente'?'selected':''?>>Pendente</option><option value="ajustes" <?=$lesson['review_status']==='ajustes'?'selected':''?>>Solicitar ajustes</option><option value="aprovada" <?=$lesson['review_status']==='aprovada'?'selected':''?>>Aprovada</option></select><div class="actions" style="margin-top:14px"><button class="btn" type="submit">Salvar aula</button></div></form></section>
<aside class="card sticky"><div class="section-title"><h2>Assistente IA</h2><span class="pill <?=$aiReady?'ok':'warn'?>"><?=$aiReady?'Pronto':'Indisponível'?></span></div><p class="muted">A regeneração usa somente as fontes ativas do curso e mantém o contexto do módulo.</p><form method="post" onsubmit="return confirm('Regenerar somente esta aula? O roteiro atual será substituído e a revisão voltará para pendente.');"><input type="hidden" name="action" value="regenerate_lesson"><input type="hidden" name="course_id" value="<?=$courseId?>"><input type="hidden" name="lesson_id" value="<?=$lessonId?>"><button class="btn" type="submit" <?=$aiReady?'':'disabled'?>>Regenerar esta aula com IA</button></form><hr><div class="stack"><div><strong>Status atual</strong><br><span class="pill"><?=leh($lesson['review_status'])?></span></div><div><strong>Módulo</strong><br><span class="muted"><?=leh($lesson['module_title'])?></span></div><div><strong>Próxima etapa</strong><br><span class="muted">Após aprovação da Aula 1, gerar materiais e depois vídeo/HeyGen.</span></div></div></aside></div>
</main></div></div></body></html>