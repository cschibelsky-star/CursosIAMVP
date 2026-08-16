<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';

function spDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
function sh(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function sgo(int $id): never { header('Location: student_progress.php?enrollment='.$id); exit; }
function sflash(string $message,string $type='ok'): void { $_SESSION['student_flash']=['message'=>$message,'type'=>$type]; }

$pdo=spDb(); ensureAcademicModel($pdo);
$enrollmentId=(int)($_GET['enrollment'] ?? $_POST['enrollment_id'] ?? 0);
if($enrollmentId<1){http_response_code(400);echo 'Matrícula inválida.';exit;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='update_progress'){
            $lessonId=(int)($_POST['lesson_id']??0);
            $watchedMinutes=max(0,(int)($_POST['watched_minutes']??0));
            $totalMinutes=max(0,(int)($_POST['total_minutes']??0));
            $percent=$totalMinutes>0?min(100,round(($watchedMinutes/$totalMinutes)*100,2)):(float)($_POST['percent_complete']??0);
            $status=$percent>=100?'concluida':($percent>0?'em_andamento':'nao_iniciada');
            $started=$percent>0?date('Y-m-d H:i:s'):null; $completed=$percent>=100?date('Y-m-d H:i:s'):null;
            $stmt=$pdo->prepare("INSERT INTO lesson_progress(enrollment_id,lesson_id,status,watched_seconds,total_seconds,percent_complete,last_position_seconds,started_at,completed_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status),watched_seconds=VALUES(watched_seconds),total_seconds=VALUES(total_seconds),percent_complete=VALUES(percent_complete),last_position_seconds=VALUES(last_position_seconds),started_at=COALESCE(started_at,VALUES(started_at)),completed_at=VALUES(completed_at),last_seen_at=NOW()");
            $stmt->execute([$enrollmentId,$lessonId,$status,$watchedMinutes*60,$totalMinutes*60,$percent,$watchedMinutes*60,$started,$completed]);
            $pdo->prepare("UPDATE enrollments SET status=CASE WHEN status='matriculado' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW() WHERE id=?")->execute([$enrollmentId]);
            sflash('Progresso da aula atualizado.');
        } elseif($action==='mark_attendance'){
            $sessionId=(int)($_POST['session_id']??0); $status=(string)($_POST['attendance_status']??'presente'); $minutes=max(0,(int)($_POST['minutes_attended']??0));
            $stmt=$pdo->prepare("INSERT INTO attendance(session_id,enrollment_id,status,minutes_attended,check_in_at,check_out_at,notes) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),minutes_attended=VALUES(minutes_attended),check_in_at=VALUES(check_in_at),check_out_at=VALUES(check_out_at),notes=VALUES(notes),updated_at=CURRENT_TIMESTAMP");
            $checkIn=$status==='presente'?date('Y-m-d H:i:s'):null; $checkOut=$status==='presente'?date('Y-m-d H:i:s'):null;
            $stmt->execute([$sessionId,$enrollmentId,$status,$minutes,$checkIn,$checkOut,trim((string)($_POST['notes']??''))?:null]);
            sflash('Presença registrada.');
        } elseif($action==='update_enrollment'){
            $status=(string)($_POST['status']??'em_andamento'); $payment=(string)($_POST['payment_status']??'pendente');
            $completed=$status==='concluido'?date('Y-m-d H:i:s'):null;
            $paid=$payment==='pago'?date('Y-m-d H:i:s'):null;
            $stmt=$pdo->prepare('UPDATE enrollments SET status=?,payment_status=?,paid_at=COALESCE(paid_at,?),completed_at=COALESCE(completed_at,?) WHERE id=?');
            $stmt->execute([$status,$payment,$paid,$completed,$enrollmentId]);
            sflash('Situação acadêmica atualizada.');
        } elseif($action==='issue_certificate'){
            $stmt=$pdo->prepare('SELECT status,cohort_id FROM enrollments WHERE id=?');
            $stmt->execute([$enrollmentId]);
            $eligibilityEnrollment=$stmt->fetch();
            if(!$eligibilityEnrollment) throw new RuntimeException('Matrícula não encontrada.');

            if(($eligibilityEnrollment['status']??'')!=='concluido'){
                throw new RuntimeException('Conclua academicamente a matrícula antes de emitir certificado ou diploma.');
            }

            $eligibilityMetrics=academicEnrollmentMetrics($pdo,$enrollmentId);
            $totalLessons=(int)($eligibilityMetrics['total_lessons']??0);
            $completedLessons=(int)($eligibilityMetrics['completed_lessons']??0);

            if($totalLessons>0 && $completedLessons<$totalLessons){
                throw new RuntimeException('Existem aulas online ainda não concluídas.');
            }

            $cohortId=(int)($eligibilityEnrollment['cohort_id']??0);
            if($cohortId>0){
                $stmt=$pdo->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE cohort_id=?');
                $stmt->execute([$cohortId]);
                $totalSessions=(int)$stmt->fetchColumn();

                $stmt=$pdo->prepare('SELECT COUNT(*) FROM attendance a INNER JOIN attendance_sessions s ON s.id=a.session_id WHERE a.enrollment_id=? AND s.cohort_id=?');
                $stmt->execute([$enrollmentId,$cohortId]);
                $registeredSessions=(int)$stmt->fetchColumn();

                if($registeredSessions<$totalSessions){
                    throw new RuntimeException('Existem encontros presenciais sem lançamento de presença.');
                }
            }

            $type=(string)($_POST['certificate_type']??'certificado');
            if(!in_array($type,['certificado','diploma'],true))$type='certificado';
            $code='CURSO-'.date('Ymd').'-'.str_pad((string)$enrollmentId,6,'0',STR_PAD_LEFT).'-'.strtoupper(substr(hash('sha256',$enrollmentId.'|'.microtime(true)),0,8));
            $hash=hash('sha256',$code.'|'.$enrollmentId);
            $stmt=$pdo->prepare("INSERT INTO certificates(enrollment_id,certificate_type,certificate_code,status,issued_at,validation_hash) VALUES(?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE certificate_type=VALUES(certificate_type),status='emitido',issued_at=NOW(),validation_hash=VALUES(validation_hash)");
            $stmt->execute([$enrollmentId,$type,$code,'emitido',$hash]);
            sflash(ucfirst($type).' emitido no controle acadêmico. O PDF/QR Code será conectado na etapa de documentos.');
        }
        sgo($enrollmentId);
    }catch(Throwable $e){sflash($e->getMessage(),'error');sgo($enrollmentId);}
}

$stmt=$pdo->prepare("SELECT e.*,s.name student_name,s.email,s.phone,c.title course_title,ch.name cohort_name,ch.modality,ch.location,ch.organization_id,o.name organization_name FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id WHERE e.id=?");
$stmt->execute([$enrollmentId]); $enrollment=$stmt->fetch(); if(!$enrollment){http_response_code(404);echo 'Matrícula não encontrada.';exit;}
$stmt=$pdo->prepare("SELECT m.position module_position,m.title module_title,l.id lesson_id,l.position lesson_position,l.title lesson_title,lp.status progress_status,lp.watched_seconds,lp.total_seconds,lp.percent_complete,lp.last_seen_at FROM modules m INNER JOIN lessons l ON l.module_id=m.id LEFT JOIN lesson_progress lp ON lp.lesson_id=l.id AND lp.enrollment_id=? WHERE m.course_id=? ORDER BY m.position,l.position");
$stmt->execute([$enrollmentId,$enrollment['course_id']]); $lessons=$stmt->fetchAll();
$sessions=[]; if($enrollment['cohort_id']){$stmt=$pdo->prepare("SELECT s.*,a.status attendance_status,a.minutes_attended,a.notes attendance_notes FROM attendance_sessions s LEFT JOIN attendance a ON a.session_id=s.id AND a.enrollment_id=? WHERE s.cohort_id=? ORDER BY s.session_date,s.start_time");$stmt->execute([$enrollmentId,$enrollment['cohort_id']]);$sessions=$stmt->fetchAll();}
$stmt=$pdo->prepare('SELECT * FROM certificates WHERE enrollment_id=?');$stmt->execute([$enrollmentId]);$certificate=$stmt->fetch();
$metrics=academicEnrollmentMetrics($pdo,$enrollmentId);
$total=(int)($metrics['total_lessons']??0);$done=(int)($metrics['completed_lessons']??0);$onlinePct=$total?round(($done/$total)*100):0;$watchedHours=round(((int)($metrics['watched_seconds']??0))/3600,1);
$planned=(int)($metrics['planned_minutes']??0);$attended=(int)($metrics['attended_minutes']??0);$attendancePct=$planned?round(($attended/$planned)*100):0;
$flash=$_SESSION['student_flash']??null;unset($_SESSION['student_flash']);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=sh($enrollment['student_name'])?> — Progresso</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}.wrap{max-width:1250px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:18px;margin-bottom:16px}.metrics{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}.metric{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:16px}.metric b{font-size:26px;display:block}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:8px;padding:8px 12px;text-decoration:none;font-weight:700;cursor:pointer}.secondary{background:#eef2f8;color:#182a52}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:8px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:top}input,select{padding:8px;border:1px solid #cfd6e4;border-radius:7px;max-width:170px}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#edf2ff;font-size:11px}.ok{background:#e8f7ed;color:#176b35}.warn{background:#fff4df;color:#8a5a00}.flash{padding:12px 14px;border-radius:9px;background:#eaf7ee;color:#185f34;margin-bottom:16px}.flash.error{background:#fff0f0;color:#9b1c1c}.muted{color:#667085;font-size:12px}@media(max-width:900px){.metrics{grid-template-columns:1fr 1fr}.wrap{padding:12px}}</style></head><body>
<div class="top"><strong>Controle individual do aluno</strong><a class="btn secondary" href="academic.php">← Controle Acadêmico</a></div><div class="wrap">
<?php if($flash):?><div class="flash <?=sh($flash['type'])?>"><?=sh($flash['message'])?></div><?php endif;?>
<div class="card"><h1><?=sh($enrollment['student_name'])?></h1><p><strong>Curso:</strong> <?=sh($enrollment['course_title'])?><?php if($enrollment['cohort_name']):?> · <strong>Turma:</strong> <?=sh($enrollment['cohort_name'])?><?php endif;?></p><p class="muted"><?=sh($enrollment['organization_name']?:'Matrícula individual')?> · <?=sh($enrollment['modality']?:'online')?> · <?=sh($enrollment['email'])?></p></div>
<div class="metrics"><div class="metric"><b><?=$onlinePct?>%</b><span>Conclusão online</span></div><div class="metric"><b><?=$done?>/<?=$total?></b><span>Aulas concluídas</span></div><div class="metric"><b><?=$watchedHours?>h</b><span>Tempo assistido</span></div><div class="metric"><b><?=$attendancePct?>%</b><span>Carga presencial registrada</span></div><div class="metric"><b><?=sh($certificate['status']??'—')?></b><span>Certificado/diploma</span></div></div>
<div class="card"><h2>Situação geral</h2><form method="post"><input type="hidden" name="action" value="update_enrollment"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><label>Status acadêmico</label> <select name="status"><option value="matriculado" <?=$enrollment['status']==='matriculado'?'selected':''?>>Matriculado</option><option value="em_andamento" <?=$enrollment['status']==='em_andamento'?'selected':''?>>Em andamento</option><option value="concluido" <?=$enrollment['status']==='concluido'?'selected':''?>>Concluído</option><option value="trancado" <?=$enrollment['status']==='trancado'?'selected':''?>>Trancado</option><option value="cancelado" <?=$enrollment['status']==='cancelado'?'selected':''?>>Cancelado</option></select> <label>Pagamento</label> <select name="payment_status"><option value="pendente" <?=$enrollment['payment_status']==='pendente'?'selected':''?>>Pendente</option><option value="pago" <?=$enrollment['payment_status']==='pago'?'selected':''?>>Pago</option><option value="isento" <?=$enrollment['payment_status']==='isento'?'selected':''?>>Isento</option><option value="contrato_institucional" <?=$enrollment['payment_status']==='contrato_institucional'?'selected':''?>>Contrato institucional</option><option value="atrasado" <?=$enrollment['payment_status']==='atrasado'?'selected':''?>>Atrasado</option></select> <button class="btn">Salvar</button></form></div>
<div class="card"><h2>Aulas online</h2><p class="muted">Por enquanto o apontamento pode ser manual em HML. Quando o player de vídeo entrar, estes campos serão atualizados automaticamente por eventos de reprodução.</p><table><thead><tr><th>Aula</th><th>Status</th><th>Assistido</th><th>Total</th><th>%</th><th>Atualizar</th></tr></thead><tbody><?php foreach($lessons as $l):?><tr><td><strong>M<?=$l['module_position']?> · A<?=$l['lesson_position']?></strong><br><?=sh($l['lesson_title'])?></td><td><span class="pill <?=($l['progress_status']??'')==='concluida'?'ok':''?>"><?=sh($l['progress_status']??'não iniciada')?></span></td><td><?=round(((int)($l['watched_seconds']??0))/60)?> min</td><td><?=round(((int)($l['total_seconds']??0))/60)?> min</td><td><?=round((float)($l['percent_complete']??0))?>%</td><td><form method="post"><input type="hidden" name="action" value="update_progress"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><input type="hidden" name="lesson_id" value="<?=$l['lesson_id']?>"><input name="watched_minutes" type="number" min="0" value="<?=round(((int)($l['watched_seconds']??0))/60)?>" placeholder="assistidos"><input name="total_minutes" type="number" min="0" value="<?=round(((int)($l['total_seconds']??0))/60)?>" placeholder="duração"><button class="btn">Salvar</button></form></td></tr><?php endforeach;?></tbody></table></div>
<?php if($enrollment['cohort_id']):?><div class="card"><h2>Aulas / encontros presenciais</h2><table><thead><tr><th>Encontro</th><th>Data/local</th><th>Registro atual</th><th>Presença</th></tr></thead><tbody><?php foreach($sessions as $s):?><tr><td><strong><?=sh($s['title'])?></strong><br><span class="muted"><?=$s['planned_minutes']?> min previstos</span></td><td><?=sh($s['session_date'])?><br><span class="muted"><?=sh($s['location'])?></span></td><td><span class="pill <?=($s['attendance_status']??'')==='presente'?'ok':''?>"><?=sh($s['attendance_status']??'não lançada')?></span><br><span class="muted"><?=(int)($s['minutes_attended']??0)?> min</span></td><td><form method="post"><input type="hidden" name="action" value="mark_attendance"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><input type="hidden" name="session_id" value="<?=$s['id']?>"><select name="attendance_status"><option value="presente">Presente</option><option value="ausente">Ausente</option><option value="justificada">Falta justificada</option></select><input name="minutes_attended" type="number" min="0" value="<?=(int)($s['minutes_attended']??0)?>" placeholder="minutos"><input name="notes" value="<?=sh($s['attendance_notes']??'')?>" placeholder="observação"><button class="btn">Registrar</button></form></td></tr><?php endforeach;if(!$sessions):?><tr><td colspan="4" class="muted">Nenhum encontro presencial cadastrado para esta turma.</td></tr><?php endif;?></tbody></table></div><?php endif;?>
<div class="card"><h2>Conclusão e documento</h2><p>Online: <strong><?=$onlinePct?>%</strong> · Presencial registrado: <strong><?=$attendancePct?>%</strong> · Situação acadêmica: <strong><?=sh($enrollment['status'])?></strong>.</p><p class="muted">A emissão permanece uma decisão controlada. A regra automática de aprovação (frequência mínima, avaliação, carga horária e demais requisitos) será configurada por curso/turma antes de produção.</p><?php if($certificate):?><p><span class="pill ok"><?=sh($certificate['certificate_type'])?> emitido</span> Código: <strong><?=sh($certificate['certificate_code'])?></strong></p><?php else:?><form method="post"><input type="hidden" name="action" value="issue_certificate"><input type="hidden" name="enrollment_id" value="<?=$enrollmentId?>"><select name="certificate_type"><option value="certificado">Certificado</option><option value="diploma">Diploma</option></select> <button class="btn">Emitir registro acadêmico</button></form><?php endif;?></div>
</div></body></html>