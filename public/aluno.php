<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function alunoDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function ph(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function fmtMinutes(int $seconds): string
{
    $minutes=(int)floor($seconds/60);
    if($minutes<60)return $minutes.' min';
    $hours=(int)floor($minutes/60);
    $rest=$minutes%60;
    return $hours.'h'.($rest?' '.$rest.'min':'');
}
function fmtPlannedMinutes(int $minutes): string
{
    if($minutes<60)return $minutes.' min';
    $hours=(int)floor($minutes/60);
    $rest=$minutes%60;
    return $hours.'h'.($rest?' '.$rest.'min':'');
}

$pdo=alunoDb();
ensureAcademicModel($pdo);
ensureStudentPortal($pdo);
$enrollmentId=(int)($_SESSION['student_enrollment_id']??0);
if($enrollmentId<1){header('Location: aluno_login.php');exit;}
$stmt=$pdo->prepare("SELECT e.*,s.name AS student_name,s.email,s.phone,c.title AS course_title,c.objective AS course_objective,ch.name AS cohort_name,ch.modality,ch.planned_hours,ch.start_date,ch.end_date,ch.location,o.name AS organization_name
FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id WHERE e.id=? AND s.active=1 LIMIT 1");
$stmt->execute([$enrollmentId]);
$enrollment=$stmt->fetch();
if(!$enrollment){$_SESSION=[];session_destroy();header('Location: aluno_login.php');exit;}
$pdo->prepare('UPDATE enrollments SET last_seen_at=NOW() WHERE id=?')->execute([$enrollmentId]);
$lessons=portalCourseLessons($pdo,$enrollmentId,(int)$enrollment['course_id']);
$attendance=portalAttendance($pdo,$enrollmentId,$enrollment['cohort_id']?(int)$enrollment['cohort_id']:null);
$certificate=portalCertificate($pdo,$enrollmentId);
$metrics=academicEnrollmentMetrics($pdo,$enrollmentId);

$modality=(string)($enrollment['modality']?:'online');
$isOnline=in_array($modality,['online','hibrido'],true);
$isPresential=in_array($modality,['presencial','hibrido'],true);
$total=(int)($metrics['total_lessons']??0);
$done=(int)($metrics['completed_lessons']??0);
$onlineProgress=$total?round(($done/$total)*100):0;
$watched=(int)($metrics['watched_seconds']??0);
$totalSessions=count($attendance);
$presentSessions=count(array_filter($attendance,fn(array $a):bool=>$a['attendance_status']==='presente'));
$plannedMinutes=array_sum(array_map(fn(array $a):int=>(int)($a['planned_minutes']??0),$attendance));
$attendedMinutes=array_sum(array_map(fn(array $a):int=>(int)($a['minutes_attended']??0),$attendance));
$presentialProgress=$plannedMinutes>0?min(100,round(($attendedMinutes/$plannedMinutes)*100)):($totalSessions>0?round(($presentSessions/$totalSessions)*100):0);
$courseProgress=$modality==='presencial'?$presentialProgress:($modality==='hibrido'?round(($onlineProgress+$presentialProgress)/2):$onlineProgress);

$modules=[];
foreach($lessons as $lesson){
    $key=(int)$lesson['module_id'];
    if(!isset($modules[$key]))$modules[$key]=['position'=>$lesson['module_position'],'title'=>$lesson['module_title'],'lessons'=>[]];
    $modules[$key]['lessons'][]=$lesson;
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Meu Curso — <?=ph($enrollment['course_title'])?></title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 26px;display:flex;justify-content:space-between;align-items:center}.top a{color:#fff;text-decoration:none}.wrap{max-width:1180px;margin:auto;padding:24px}.hero,.card{background:#fff;border:1px solid #e2e7f0;border-radius:16px;padding:20px;margin-bottom:16px}.hero{display:grid;grid-template-columns:1fr 260px;gap:24px}.progress{height:12px;background:#e9edf4;border-radius:999px;overflow:hidden}.progress span{display:block;height:100%;background:#182a52}.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}.metric{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:16px}.metric b{display:block;font-size:26px}.module{border-top:1px solid #edf0f4;padding-top:16px;margin-top:16px}.lesson{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:center;padding:12px 0;border-top:1px dashed #e6eaf0}.lesson:first-of-type{border-top:0}.btn{display:inline-block;background:#182a52;color:#fff;border-radius:9px;padding:9px 13px;text-decoration:none;font-weight:700}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#edf2ff;font-size:11px}.ok{background:#e8f7ed;color:#176b35}.warn{background:#fff4df;color:#8a5a00}.muted{color:#667085;font-size:13px}.agenda{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.agenda-item{padding:14px;border:1px solid #e4e8ef;border-radius:11px}@media(max-width:850px){.hero{grid-template-columns:1fr}.metrics{grid-template-columns:1fr 1fr}.agenda{grid-template-columns:1fr}.wrap{padding:14px}}</style></head><body>
<div class="top"><strong>Portal do Aluno</strong><div><?=ph($enrollment['student_name'])?> · <a href="aluno_login.php?logout=1">Sair</a></div></div><main class="wrap">
<div class="hero"><div><span class="pill">Curso <?=ph($modality)?></span><h1><?=ph($enrollment['course_title'])?></h1><p><?=ph($enrollment['course_objective'])?></p><?php if($enrollment['cohort_name']):?><p class="muted"><strong>Turma:</strong> <?=ph($enrollment['cohort_name'])?><?php if($enrollment['organization_name']):?> · <?=ph($enrollment['organization_name'])?><?php endif;?><?php if($enrollment['location']):?> · <?=ph($enrollment['location'])?><?php endif;?></p><?php endif;?></div><div><strong><?=$courseProgress?>% de andamento</strong><div class="progress" aria-label="Andamento do curso"><span style="width:<?=$courseProgress?>%"></span></div><p class="muted"><?php if($modality==='presencial'):?><?=$presentSessions?> de <?=$totalSessions?> encontros com presença<br><?=fmtPlannedMinutes($attendedMinutes)?> de carga registrada<?php elseif($modality==='hibrido'):?>Online: <?=$onlineProgress?>% · Presencial: <?=$presentialProgress?>%<?php else:?><?=$done?> de <?=$total?> aulas concluídas<br><?=fmtMinutes($watched)?> assistidos<?php endif;?></p><span class="pill <?=in_array($enrollment['payment_status'],['pago','contrato_institucional','isento'],true)?'ok':'warn'?>">Financeiro: <?=ph($enrollment['payment_status'])?></span></div></div>
<div class="metrics"><?php if($isOnline):?><div class="metric"><b><?=$done?>/<?=$total?></b><span>Aulas online concluídas</span></div><div class="metric"><b><?=fmtMinutes($watched)?></b><span>Tempo online assistido</span></div><?php endif;?><?php if($isPresential):?><div class="metric"><b><?=$presentSessions?>/<?=$totalSessions?></b><span>Presenças na turma</span></div><div class="metric"><b><?=fmtPlannedMinutes($attendedMinutes)?></b><span>Carga presencial registrada</span></div><?php endif;?><div class="metric"><b><?=ph($certificate['status']??'—')?></b><span>Certificação</span></div></div>

<div class="card"><h2>Programa do curso</h2><p class="muted">Esta é a estrutura pedagógica oficial do curso: módulos, aulas/temas e objetivos. <?php if($isOnline):?>Nas modalidades online e híbrida, o programa também funciona como navegação para acessar cada aula e acompanhar o progresso individual.<?php else:?>Na modalidade presencial, o programa orienta os conteúdos ministrados pela turma e o andamento é registrado pela frequência e carga horária.<?php endif;?></p>
<?php foreach($modules as $module):?><section class="module"><h3>Módulo <?=$module['position']?> — <?=ph($module['title'])?></h3><?php foreach($module['lessons'] as $lesson): $pct=(float)$lesson['percent_complete'];?><div class="lesson"><div><strong>Aula <?=$lesson['lesson_position']?> — <?=ph($lesson['lesson_title'])?></strong><br><span class="muted"><?=ph($lesson['objective'])?></span><?php if($isOnline):?><br><span class="pill <?=$pct>=100?'ok':''?>"><?=$pct>=100?'Concluída':($pct>0?round($pct).'% assistida':'Não iniciada')?></span><?php endif;?></div><?php if($isOnline):?><a class="btn" href="aula.php?id=<?=$lesson['lesson_id']?>"><?=$pct>0&&$pct<100?'Continuar':'Abrir aula'?></a><?php endif;?></div><?php endforeach;?></section><?php endforeach;?></div>

<?php if($isPresential):?><div class="card"><h2>Calendário da turma presencial</h2><p class="muted">Cada registro abaixo representa um encontro/dia letivo pertencente ao curso da sua turma.</p><div class="agenda"><?php foreach($attendance as $item):?><div class="agenda-item"><strong><?=ph($item['title'])?></strong><p><?=ph($item['session_date'])?><?php if($item['start_time']):?> · <?=substr((string)$item['start_time'],0,5)?><?php endif;?></p><p class="muted"><?=ph($item['location'])?> · <?=$item['planned_minutes']?> min previstos</p><span class="pill <?=$item['attendance_status']==='presente'?'ok':''?>"><?=ph($item['attendance_status'])?><?php if((int)$item['minutes_attended']>0):?> · <?=$item['minutes_attended']?> min<?php endif;?></span></div><?php endforeach;if(!$attendance):?><p class="muted">O calendário desta turma ainda não foi cadastrado.</p><?php endif;?></div></div><?php endif;?>
<div class="card"><h2>Certificação</h2><?php if($certificate && $certificate['status']==='emitido'):?><p><span class="pill ok"><?=ph($certificate['certificate_type'])?> emitido</span></p><p>Código de validação: <strong><?=ph($certificate['certificate_code'])?></strong></p><p class="muted">O download PDF e a validação pública por QR Code entram no próximo bloco documental.</p><?php else:?><p class="muted">Seu documento final ainda não foi emitido. A emissão depende das regras acadêmicas definidas para a modalidade e a turma.</p><?php endif;?></div>
</main></body></html>