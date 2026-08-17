<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/academic_eligibility.php';

function acDb(): PDO
{
    return new PDO(
        'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
        getenv('DB_USERNAME')?:'cursos_ia',
        getenv('DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function ach(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); }
function acgo(int $id): never { header('Location: academic_completion.php?enrollment='.$id); exit; }

$pdo=acDb();
ensureAcademicModel($pdo);
$enrollmentId=(int)($_GET['enrollment']??$_POST['enrollment_id']??0);
if($enrollmentId<1){http_response_code(400);echo 'Matrícula inválida.';exit;}

$flash=$_SESSION['academic_completion_flash']??null;
unset($_SESSION['academic_completion_flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='sync_completion'){
            $state=academicSyncCompletion($pdo,$enrollmentId);
            $_SESSION['academic_completion_flash']=[
                'type'=>$state['eligible']?'ok':'error',
                'message'=>$state['eligible']?'Matrícula concluída pelos critérios acadêmicos.':'Ainda existem pendências: '.implode(' ',$state['pending'])
            ];
        } elseif($action==='issue_certificate'){
            $type=(string)($_POST['certificate_type']??'certificado');
            $certificate=academicIssueCertificate($pdo,$enrollmentId,$type);
            $_SESSION['academic_completion_flash']=['type'=>'ok','message'=>ucfirst((string)$certificate['certificate_type']).' emitido após validação central dos critérios acadêmicos.'];
        }
        acgo($enrollmentId);
    }catch(Throwable $e){
        $_SESSION['academic_completion_flash']=['type'=>'error','message'=>$e->getMessage()];
        acgo($enrollmentId);
    }
}

$stmt=$pdo->prepare("SELECT e.id,e.status,e.completed_at,s.name student_name,c.id course_id,c.title course_title,ch.name cohort_name FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id WHERE e.id=?");
$stmt->execute([$enrollmentId]);
$enrollment=$stmt->fetch();
if(!$enrollment){http_response_code(404);echo 'Matrícula não encontrada.';exit;}
$state=academicEligibilityState($pdo,$enrollmentId);
$stmt=$pdo->prepare('SELECT * FROM certificates WHERE enrollment_id=?');$stmt->execute([$enrollmentId]);$certificate=$stmt->fetch();
$rules=$state['rules'];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Conclusão Acadêmica — Cursos IA</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Motor acadêmico central</small></div><nav><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="assessments.php?course=<?=(int)$enrollment['course_id']?>"><span class="dot"></span>Avaliações</a><a class="nav-link" href="academic_rules.php?course=<?=(int)$enrollment['course_id']?>"><span class="dot"></span>Critérios</a></nav></aside>
<div class="main"><header class="topbar"><strong>Conclusão Acadêmica</strong><span class="env">Motor central</span></header><main class="content">
<?php if($flash):?><div class="flash <?=ach($flash['type'])?>"><?=ach($flash['message'])?></div><?php endif;?>
<div class="page-title"><div><h1><?=ach($enrollment['student_name'])?></h1><p><?=ach($enrollment['course_title'])?><?= $enrollment['cohort_name']?' · '.ach($enrollment['cohort_name']):'' ?></p></div><div class="actions"><a class="btn secondary" href="student_progress.php?enrollment=<?=$enrollmentId?>">Progresso detalhado</a></div></div>
<div class="grid grid-4" style="margin-bottom:18px"><div class="metric"><span class="value"><?=$state['completed_lessons']?>/<?=$state['total_lessons']?></span><span class="label">Aulas</span></div><div class="metric"><span class="value"><?=number_format((float)$state['attendance_percent'],0,',','.')?>%</span><span class="label">Frequência</span></div><div class="metric"><span class="value"><?=$state['final_grade']===null?'—':number_format((float)$state['final_grade'],2,',','.')?></span><span class="label">Nota final</span></div><div class="metric"><span class="value"><?=ach($enrollment['status'])?></span><span class="label">Situação</span></div></div>
<section class="card" style="margin-bottom:18px"><div class="section-title"><h2>Elegibilidade</h2><span class="pill <?=$state['eligible']?'ok':'warn'?>"><?=$state['eligible']?'Elegível':'Pendente'?></span></div><?php if($state['eligible']):?><p>Todos os critérios configurados para o curso foram cumpridos.</p><?php else:?><ul><?php foreach($state['pending'] as $item):?><li><?=ach($item)?></li><?php endforeach;?></ul><?php endif;?><div class="info-strip"><span class="item">Frequência mínima: <?=($rules['minimum_attendance_percent']===null||$rules['minimum_attendance_percent']==='')?'não definida':academicRuleNumber((float)$rules['minimum_attendance_percent']).'%'?></span><span class="item">Nota mínima: <?=($rules['minimum_grade']===null||$rules['minimum_grade']==='')?'não definida':academicRuleNumber((float)$rules['minimum_grade'])?></span></div></section>
<section class="card"><div class="section-title"><h2>Conclusão e certificado</h2><span class="pill <?=($certificate&&$certificate['status']==='emitido')?'ok':'warn'?>"><?=ach($certificate['status']??'não emitido')?></span></div><div class="actions"><form method="post"><input type="hidden" name="action" value="sync_completion"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><button class="btn" type="submit">Validar e concluir</button></form><?php if(!$certificate):?><form method="post"><input type="hidden" name="action" value="issue_certificate"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><select name="certificate_type"><option value="certificado">Certificado</option><option value="diploma">Diploma</option></select><button class="btn" type="submit">Emitir após validação</button></form><?php else:?><span class="code"><?=ach($certificate['certificate_code'])?></span><?php endif;?></div></section>
</main></div></div></body></html>
