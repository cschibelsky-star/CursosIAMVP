<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';

function avDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function avh(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function avgo(int $courseId): never { header('Location: assessments.php?course='.$courseId); exit; }

$pdo=avDb();ensureAcademicModel($pdo);
$courses=$pdo->query('SELECT id,title,status FROM courses ORDER BY title')->fetchAll();
$courseId=(int)($_GET['course'] ?? $_POST['course_id'] ?? ($courses[0]['id'] ?? 0));
$flash=$_SESSION['assessment_flash']??null;unset($_SESSION['assessment_flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($courseId<1) throw new RuntimeException('Selecione um curso.');
        if($action==='create_assessment'){
            $title=trim((string)($_POST['title']??''));
            $type=(string)($_POST['assessment_type']??'avaliacao_final');
            $maxScore=(float)($_POST['max_score']??100);
            $weight=(float)($_POST['weight']??1);
            if($title==='') throw new RuntimeException('Informe o título da avaliação.');
            if($maxScore<=0) throw new RuntimeException('A nota máxima deve ser maior que zero.');
            if($weight<=0) throw new RuntimeException('O peso deve ser maior que zero.');
            $stmt=$pdo->prepare('INSERT INTO assessments(course_id,title,assessment_type,max_score,weight,required,active) VALUES(?,?,?,?,?,?,1)');
            $stmt->execute([$courseId,$title,$type,$maxScore,$weight,isset($_POST['required'])?1:0]);
            $_SESSION['assessment_flash']=['message'=>'Avaliação cadastrada.','type'=>'ok'];
        } elseif($action==='save_result'){
            $assessmentId=(int)($_POST['assessment_id']??0);
            $enrollmentId=(int)($_POST['enrollment_id']??0);
            $rawScore=trim((string)($_POST['score']??''));
            if($assessmentId<1 || $enrollmentId<1 || $rawScore==='') throw new RuntimeException('Informe avaliação, matrícula e nota.');
            $stmt=$pdo->prepare('SELECT max_score FROM assessments WHERE id=? AND course_id=? AND active=1');
            $stmt->execute([$assessmentId,$courseId]);
            $maxScore=$stmt->fetchColumn();
            if($maxScore===false) throw new RuntimeException('Avaliação inválida.');
            $score=(float)$rawScore;
            if($score<0 || $score>(float)$maxScore) throw new RuntimeException('A nota deve ficar entre 0 e '.rtrim(rtrim(number_format((float)$maxScore,2,'.',''),'0'),'.').'.');
            $stmt=$pdo->prepare('SELECT id FROM enrollments WHERE id=? AND course_id=?');
            $stmt->execute([$enrollmentId,$courseId]);
            if(!$stmt->fetchColumn()) throw new RuntimeException('A matrícula não pertence a este curso.');
            $stmt=$pdo->prepare("INSERT INTO assessment_results(assessment_id,enrollment_id,score,status,evaluated_at,notes) VALUES(?,?,?,'avaliado',NOW(),?) ON DUPLICATE KEY UPDATE score=VALUES(score),status='avaliado',evaluated_at=NOW(),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$assessmentId,$enrollmentId,$score,trim((string)($_POST['notes']??''))?:null]);
            $_SESSION['assessment_flash']=['message'=>'Resultado registrado.','type'=>'ok'];
        }
        avgo($courseId);
    }catch(Throwable $e){$_SESSION['assessment_flash']=['message'=>$e->getMessage(),'type'=>'error'];avgo($courseId);}
}

$assessments=[];$enrollments=[];$results=[];
if($courseId>0){
    $stmt=$pdo->prepare('SELECT * FROM assessments WHERE course_id=? AND active=1 ORDER BY id');$stmt->execute([$courseId]);$assessments=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT e.id,e.status,s.name student_name,ch.name cohort_name FROM enrollments e INNER JOIN students s ON s.id=e.student_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id WHERE e.course_id=? ORDER BY s.name");$stmt->execute([$courseId]);$enrollments=$stmt->fetchAll();
    $stmt=$pdo->prepare("SELECT ar.*,a.title assessment_title,a.max_score,a.weight,s.name student_name FROM assessment_results ar INNER JOIN assessments a ON a.id=ar.assessment_id INNER JOIN enrollments e ON e.id=ar.enrollment_id INNER JOIN students s ON s.id=e.student_id WHERE a.course_id=? ORDER BY ar.updated_at DESC");$stmt->execute([$courseId]);$results=$stmt->fetchAll();
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cursos IA — Avaliações</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div><nav><div class="nav-title">Operação</div><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="academic_rules.php<?= $courseId?'?course='.$courseId:'' ?>"><span class="dot"></span>Critérios Acadêmicos</a><a class="nav-link active" href="assessments.php"><span class="dot"></span>Avaliações</a></nav></aside>
<div class="main"><header class="topbar"><strong>Centro Acadêmico · Avaliações</strong><span class="env">HML · v1.0</span></header><main class="content">
<?php if($flash):?><div class="flash <?=avh($flash['type'])?>"><?=avh($flash['message'])?></div><?php endif;?>
<div class="page-title"><div><h1>Avaliações e notas</h1><p>Cadastre avaliações do curso e registre o resultado de cada matrícula.</p></div><div class="actions"><a class="btn secondary" href="academic_rules.php<?= $courseId?'?course='.$courseId:'' ?>">Critérios</a></div></div>
<section class="card" style="margin-bottom:18px"><form method="get"><label class="form-label">Curso</label><select name="course" onchange="this.form.submit()"><?php foreach($courses as $c):?><option value="<?=$c['id']?>" <?=$courseId===(int)$c['id']?'selected':''?>><?=avh($c['title'])?></option><?php endforeach;?></select></form></section>
<?php if($courseId>0):?>
<div class="grid grid-2" style="margin-bottom:18px"><section class="card form-card"><div class="section-title"><h2>Nova avaliação</h2><span class="pill">Curso <?=$courseId?></span></div><form method="post"><input type="hidden" name="action" value="create_assessment"><input type="hidden" name="course_id" value="<?=$courseId?>"><div class="form-group"><label class="form-label">Título</label><input name="title" required placeholder="Ex.: Avaliação final"></div><div class="form-row"><div class="form-group"><label class="form-label">Tipo</label><select name="assessment_type"><option value="avaliacao_final">Avaliação final</option><option value="atividade">Atividade</option><option value="prova">Prova</option><option value="trabalho">Trabalho</option></select></div><div class="form-group"><label class="form-label">Nota máxima</label><input type="number" name="max_score" min="0.01" step="0.01" value="100"></div></div><div class="form-group"><label class="form-label">Peso</label><input type="number" name="weight" min="0.01" step="0.01" value="1"></div><label><input type="checkbox" name="required" value="1" checked> Avaliação obrigatória</label><button class="btn" type="submit">Cadastrar avaliação</button></form></section>
<section class="card form-card"><div class="section-title"><h2>Lançar resultado</h2><span class="pill <?=count($assessments)?'ok':'warn'?>"><?=count($assessments)?> avaliação(ões)</span></div><?php if($assessments && $enrollments):?><form method="post"><input type="hidden" name="action" value="save_result"><input type="hidden" name="course_id" value="<?=$courseId?>"><div class="form-group"><label class="form-label">Avaliação</label><select name="assessment_id" required><?php foreach($assessments as $a):?><option value="<?=$a['id']?>"><?=avh($a['title'])?> · máx. <?=number_format((float)$a['max_score'],2,',','.')?> · peso <?=number_format((float)$a['weight'],2,',','.')?></option><?php endforeach;?></select></div><div class="form-group"><label class="form-label">Aluno / matrícula</label><select name="enrollment_id" required><?php foreach($enrollments as $e):?><option value="<?=$e['id']?>"><?=avh($e['student_name'])?> · matrícula <?=$e['id']?><?= $e['cohort_name']?' · '.avh($e['cohort_name']):'' ?></option><?php endforeach;?></select></div><div class="form-group"><label class="form-label">Nota obtida</label><input type="number" name="score" min="0" step="0.01" required></div><div class="form-group"><label class="form-label">Observações</label><textarea name="notes"></textarea></div><button class="btn">Salvar resultado</button></form><?php else:?><div class="empty">Cadastre uma avaliação e tenha ao menos uma matrícula neste curso.</div><?php endif;?></section></div>
<section class="card" style="margin-bottom:18px"><div class="section-title"><h2>Avaliações do curso</h2><span class="pill"><?=count($assessments)?></span></div><div class="table-wrap"><table class="table"><thead><tr><th>Avaliação</th><th>Tipo</th><th>Máxima</th><th>Peso</th><th>Obrigatória</th></tr></thead><tbody><?php foreach($assessments as $a):?><tr><td><strong><?=avh($a['title'])?></strong></td><td><?=avh($a['assessment_type'])?></td><td><?=number_format((float)$a['max_score'],2,',','.')?></td><td><?=number_format((float)$a['weight'],2,',','.')?></td><td><?=$a['required']?'Sim':'Não'?></td></tr><?php endforeach;if(!$assessments):?><tr><td colspan="5" class="empty">Nenhuma avaliação cadastrada.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="section-title"><h2>Resultados lançados</h2><span class="pill"><?=count($results)?></span></div><div class="table-wrap"><table class="table"><thead><tr><th>Aluno</th><th>Avaliação</th><th>Nota</th><th>Normalizada</th><th>Data</th></tr></thead><tbody><?php foreach($results as $r):$normalized=(float)$r['max_score']>0?round(((float)$r['score']/(float)$r['max_score'])*100,2):0;?><tr><td><?=avh($r['student_name'])?></td><td><?=avh($r['assessment_title'])?></td><td><?=number_format((float)$r['score'],2,',','.')?> / <?=number_format((float)$r['max_score'],2,',','.')?></td><td><?=number_format($normalized,2,',','.')?>%</td><td><?=avh($r['evaluated_at'])?></td></tr><?php endforeach;if(!$results):?><tr><td colspan="5" class="empty">Nenhum resultado lançado.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
</main></div></div></body></html>
