<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';

function tpDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function tph(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function tpgo(): never { header('Location: turmas_presenciais.php'); exit; }
function tpflash(string $message,string $type='ok'): void { $_SESSION['tp_flash']=['message'=>$message,'type'=>$type]; }

$pdo=tpDb();
ensureAcademicModel($pdo);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='create_presential_cohort'){
            $courseId=(int)($_POST['course_id']??0);
            $name=trim((string)($_POST['name']??''));
            if($courseId<1||$name==='') throw new RuntimeException('Informe curso e nome da turma presencial.');
            $stmt=$pdo->prepare('INSERT INTO cohorts(course_id,organization_id,name,modality,planned_hours,start_date,end_date,location,status) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId,(int)($_POST['organization_id']??0)?:null,$name,'presencial',(float)($_POST['planned_hours']??0),($_POST['start_date']??'')?:null,($_POST['end_date']??'')?:null,trim((string)($_POST['location']??''))?:null,'planejada']);
            tpflash('Turma presencial criada.');
        }elseif($action==='create_class_day'){
            $cohortId=(int)($_POST['cohort_id']??0);
            $title=trim((string)($_POST['title']??''));
            if($cohortId<1||$title==='') throw new RuntimeException('Selecione a turma e informe o encontro/dia letivo.');
            $check=$pdo->prepare("SELECT id FROM cohorts WHERE id=? AND modality IN ('presencial','hibrido')");
            $check->execute([$cohortId]);
            if(!$check->fetchColumn()) throw new RuntimeException('A turma selecionada não é presencial/híbrida.');
            $stmt=$pdo->prepare('INSERT INTO attendance_sessions(cohort_id,title,session_date,start_time,end_time,planned_minutes,location,notes) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$cohortId,$title,(string)($_POST['session_date']??date('Y-m-d')),($_POST['start_time']??'')?:null,($_POST['end_time']??'')?:null,(int)($_POST['planned_minutes']??0),trim((string)($_POST['location']??''))?:null,trim((string)($_POST['notes']??''))?:null]);
            tpflash('Encontro da turma presencial cadastrado.');
        }
        tpgo();
    }catch(Throwable $e){tpflash($e->getMessage(),'error');tpgo();}
}

$courses=$pdo->query('SELECT id,title FROM courses ORDER BY title')->fetchAll();
$organizations=$pdo->query('SELECT id,name FROM organizations WHERE active=1 ORDER BY name')->fetchAll();
$cohorts=$pdo->query("SELECT ch.*,c.title course_title,o.name organization_name,
(SELECT COUNT(*) FROM enrollments e WHERE e.cohort_id=ch.id) student_count,
(SELECT COUNT(*) FROM attendance_sessions s WHERE s.cohort_id=ch.id) session_count,
(SELECT COALESCE(SUM(s.planned_minutes),0) FROM attendance_sessions s WHERE s.cohort_id=ch.id) scheduled_minutes
FROM cohorts ch INNER JOIN courses c ON c.id=ch.course_id LEFT JOIN organizations o ON o.id=ch.organization_id
WHERE ch.modality IN ('presencial','hibrido') ORDER BY ch.id DESC")->fetchAll();
$flash=$_SESSION['tp_flash']??null;unset($_SESSION['tp_flash']);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Turmas Presenciais — Cursos IA</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 26px;display:flex;justify-content:space-between}.top a{color:#fff}.wrap{max-width:1350px;margin:auto;padding:24px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:18px;margin-bottom:16px}input,select{width:100%;padding:10px;border:1px solid #cfd6e4;border-radius:8px;margin:5px 0 10px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:8px;padding:9px 13px;text-decoration:none;font-weight:700;cursor:pointer}.flash{padding:12px;border-radius:9px;background:#eaf7ee;color:#185f34;margin-bottom:16px}.flash.error{background:#fff0f0;color:#9b1c1c}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:9px;border-bottom:1px solid #edf0f4;vertical-align:top}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#edf2ff;font-size:11px}.muted{color:#667085;font-size:12px}@media(max-width:900px){.grid{grid-template-columns:1fr}.wrap{padding:12px}.card{overflow:auto}}</style></head><body>
<div class="top"><strong>Cursos IA · Turmas Presenciais</strong><a href="academic.php">← Controle Acadêmico</a></div><main class="wrap">
<?php if($flash):?><div class="flash <?=tph($flash['type'])?>"><?=tph($flash['message'])?></div><?php endif;?>
<div class="grid">
<div class="card"><h2>Criar curso/turma presencial</h2><p class="muted">A turma presencial é a oferta acadêmica do curso para um grupo definido de participantes.</p><form method="post"><input type="hidden" name="action" value="create_presential_cohort"><select name="course_id" required><option value="">Selecione o curso</option><?php foreach($courses as $c):?><option value="<?=$c['id']?>"><?=tph($c['title'])?></option><?php endforeach;?></select><select name="organization_id"><option value="">Sem órgão contratante</option><?php foreach($organizations as $o):?><option value="<?=$o['id']?>"><?=tph($o['name'])?></option><?php endforeach;?></select><input name="name" placeholder="Ex.: Cidades Inclusivas — Prefeitura X — Turma 01" required><input name="planned_hours" type="number" step="0.5" min="0" placeholder="Carga horária total do curso"><div class="grid"><input name="start_date" type="date"><input name="end_date" type="date"></div><input name="location" placeholder="Local principal do curso"><button class="btn">Criar turma presencial</button></form></div>
<div class="card"><h2>Adicionar encontro ao calendário</h2><p class="muted">Os encontros/dias letivos pertencem a uma turma presencial já criada.</p><form method="post"><input type="hidden" name="action" value="create_class_day"><select name="cohort_id" required><option value="">Selecione a turma</option><?php foreach($cohorts as $ch):?><option value="<?=$ch['id']?>"><?=tph($ch['name'])?> · <?=tph($ch['course_title'])?></option><?php endforeach;?></select><input name="title" placeholder="Ex.: 1º encontro — Módulo 1" required><input name="session_date" type="date" required><div class="grid"><input name="start_time" type="time"><input name="end_time" type="time"></div><input name="planned_minutes" type="number" min="0" placeholder="Duração prevista em minutos"><input name="location" placeholder="Local do encontro"><input name="notes" placeholder="Observações"><button class="btn">Adicionar ao calendário</button></form></div>
</div>
<div class="card"><h2>Cursos / turmas presenciais</h2><table><thead><tr><th>Curso / turma</th><th>Contratante</th><th>Período</th><th>Carga horária</th><th>Participantes</th><th>Calendário</th><th>Status</th></tr></thead><tbody><?php foreach($cohorts as $ch): $scheduledHours=round(((int)$ch['scheduled_minutes'])/60,1);?><tr><td><strong><?=tph($ch['course_title'])?></strong><br><?=tph($ch['name'])?><br><span class="pill"><?=tph($ch['modality'])?></span></td><td><?=tph($ch['organization_name']?:'—')?><br><span class="muted"><?=tph($ch['location'])?></span></td><td><?=tph($ch['start_date']?:'—')?> até <?=tph($ch['end_date']?:'—')?></td><td><?=number_format((float)$ch['planned_hours'],1,',','.')?> h previstas<br><span class="muted"><?=$scheduledHours?> h já calendarizadas</span></td><td><?=(int)$ch['student_count']?> alunos</td><td><?=(int)$ch['session_count']?> encontros</td><td><span class="pill"><?=tph($ch['status'])?></span></td></tr><?php endforeach;if(!$cohorts):?><tr><td colspan="7" class="muted">Nenhuma turma presencial cadastrada.</td></tr><?php endif;?></tbody></table></div>
</main></body></html>