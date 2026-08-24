<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';

function academicDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
function ah(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function ago(string $url): never { header('Location: ' . $url); exit; }
function aflash(string $message, string $type='ok'): void { $_SESSION['academic_flash']=['message'=>$message,'type'=>$type]; }

$pdo = academicDb();
ensureAcademicModel($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_student') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $state = trim((string)($_POST['state'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome do aluno.');
            $stmt=$pdo->prepare('INSERT INTO students(name,email,phone,city,state) VALUES(?,?,?,?,?)');
            $stmt->execute([$name,$email?:null,$phone?:null,$city?:null,$state?:null]);
            aflash('Aluno cadastrado.');
        } elseif ($action === 'create_organization') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome do órgão/entidade.');
            $stmt=$pdo->prepare('INSERT INTO organizations(name,organization_type,city,state,contact_name,contact_email,contract_reference) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$name,(string)($_POST['organization_type'] ?? 'orgao_publico'),trim((string)($_POST['city'] ?? ''))?:null,trim((string)($_POST['state'] ?? ''))?:null,trim((string)($_POST['contact_name'] ?? ''))?:null,trim((string)($_POST['contact_email'] ?? ''))?:null,trim((string)($_POST['contract_reference'] ?? ''))?:null]);
            aflash('Órgão/entidade cadastrado.');
        } elseif ($action === 'create_cohort') {
            $courseId=(int)($_POST['course_id'] ?? 0);
            $name=trim((string)($_POST['name'] ?? ''));
            if ($courseId<1 || $name==='') throw new RuntimeException('Informe curso e nome da turma.');
            $modality=(string)($_POST['modality'] ?? 'online');
            if (!in_array($modality,['online','hibrido'],true)) throw new RuntimeException('Turmas presenciais devem ser criadas pela área Turmas Presenciais.');
            $stmt=$pdo->prepare('INSERT INTO cohorts(course_id,organization_id,name,modality,planned_hours,start_date,end_date,location,status) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId,(int)($_POST['organization_id'] ?? 0) ?: null,$name,$modality,(float)($_POST['planned_hours'] ?? 0),($_POST['start_date'] ?? '') ?: null,($_POST['end_date'] ?? '') ?: null,trim((string)($_POST['location'] ?? ''))?:null,'planejada']);
            aflash('Turma criada.');
        } elseif ($action === 'enroll_student') {
            $studentId=(int)($_POST['student_id'] ?? 0); $courseId=(int)($_POST['course_id'] ?? 0); $cohortId=(int)($_POST['cohort_id'] ?? 0);
            if ($studentId<1 || $courseId<1) throw new RuntimeException('Selecione aluno e curso.');
            $type=$cohortId>0?'institucional':'individual';
            $paymentStatus=(string)($_POST['payment_status'] ?? ($type==='institucional'?'contrato_institucional':'pendente'));
            $stmt=$pdo->prepare('INSERT INTO enrollments(student_id,course_id,cohort_id,enrollment_type,status,payment_status,amount,payment_method,paid_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $paidAt=$paymentStatus==='pago'?date('Y-m-d H:i:s'):null;
            $stmt->execute([$studentId,$courseId,$cohortId?:null,$type,'matriculado',$paymentStatus,(float)($_POST['amount'] ?? 0),trim((string)($_POST['payment_method'] ?? ''))?:null,$paidAt]);
            aflash('Matrícula criada.');
        } elseif ($action === 'update_enrollment') {
            $id=(int)($_POST['enrollment_id'] ?? 0);
            $status=(string)($_POST['status'] ?? 'matriculado');
            $payment=(string)($_POST['payment_status'] ?? 'pendente');
            $paidAt=$payment==='pago'?date('Y-m-d H:i:s'):null;
            $stmt=$pdo->prepare('UPDATE enrollments SET status=?,payment_status=?,paid_at=COALESCE(paid_at,?) WHERE id=?');
            $stmt->execute([$status,$payment,$paidAt,$id]);
            aflash('Situação da matrícula atualizada.');
        } elseif ($action === 'create_session') {
            $cohortId=(int)($_POST['cohort_id'] ?? 0); $title=trim((string)($_POST['title'] ?? ''));
            if ($cohortId<1 || $title==='') throw new RuntimeException('Selecione a turma e informe a aula/encontro.');
            $stmt=$pdo->prepare('INSERT INTO attendance_sessions(cohort_id,title,session_date,start_time,end_time,planned_minutes,location,notes) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$cohortId,$title,(string)($_POST['session_date'] ?? date('Y-m-d')),($_POST['start_time'] ?? '')?:null,($_POST['end_time'] ?? '')?:null,(int)($_POST['planned_minutes'] ?? 0),trim((string)($_POST['location'] ?? ''))?:null,trim((string)($_POST['notes'] ?? ''))?:null]);
            aflash('Encontro presencial cadastrado.');
        }
        ago('academic.php');
    } catch (Throwable $e) {
        aflash($e->getMessage(),'error');
        ago('academic.php');
    }
}

$flash=$_SESSION['academic_flash']??null; unset($_SESSION['academic_flash']);
$students=$pdo->query('SELECT * FROM students WHERE active=1 ORDER BY name')->fetchAll();
$courses=$pdo->query('SELECT id,title,status FROM courses ORDER BY title')->fetchAll();
$organizations=$pdo->query('SELECT * FROM organizations WHERE active=1 ORDER BY name')->fetchAll();
$cohorts=$pdo->query("SELECT c.*,co.title AS course_title,o.name AS organization_name FROM cohorts c INNER JOIN courses co ON co.id=c.course_id LEFT JOIN organizations o ON o.id=c.organization_id ORDER BY c.id DESC")->fetchAll();
$enrollments=$pdo->query("SELECT e.*,s.name AS student_name,s.email,c.title AS course_title,ch.name AS cohort_name,ch.modality,o.name AS organization_name,
(SELECT COUNT(*) FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=e.course_id) AS total_lessons,
(SELECT COUNT(*) FROM lesson_progress lp WHERE lp.enrollment_id=e.id AND (lp.status='concluida' OR lp.percent_complete>=100)) AS completed_lessons,
(SELECT COALESCE(SUM(lp.watched_seconds),0) FROM lesson_progress lp WHERE lp.enrollment_id=e.id) AS watched_seconds,
(SELECT COUNT(*) FROM attendance a WHERE a.enrollment_id=e.id AND a.status='presente') AS present_sessions,
(SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.cohort_id=e.cohort_id) AS total_sessions,
(SELECT status FROM certificates ce WHERE ce.enrollment_id=e.id LIMIT 1) AS certificate_status
FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id ORDER BY e.id DESC")->fetchAll();

$metrics=[
    'students'=>(int)$pdo->query('SELECT COUNT(*) FROM students WHERE active=1')->fetchColumn(),
    'enrollments'=>(int)$pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'active'=>(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status IN ('matriculado','em_andamento')")->fetchColumn(),
    'completed'=>(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status='concluido'")->fetchColumn(),
    'payment_pending'=>(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE payment_status IN ('pendente','atrasado')")->fetchColumn(),
    'cohorts'=>(int)$pdo->query('SELECT COUNT(*) FROM cohorts')->fetchColumn(),
];
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cursos IA — Controle Acadêmico</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}.wrap{max-width:1400px;margin:auto;padding:24px}.metrics{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:18px}.metric,.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:18px}.metric b{font-size:28px;display:block}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:9px 13px;font-weight:700;text-decoration:none;cursor:pointer}.btn.secondary{background:#eef2f8;color:#182a52}input,select{width:100%;padding:10px;border:1px solid #cfd6e4;border-radius:8px;margin:5px 0 10px}.flash{padding:12px 14px;border-radius:9px;background:#eaf7ee;color:#185f34;margin-bottom:16px}.flash.error{background:#fff0f0;color:#9b1c1c}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:9px;border-bottom:1px solid #edf0f4;vertical-align:top}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#edf2ff;font-size:11px}.ok{background:#e8f7ed;color:#176b35}.warn{background:#fff4df;color:#8a5a00}.muted{color:#667085;font-size:12px}@media(max-width:1000px){.metrics{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}.wrap{padding:14px}}</style></head><body>
<div class="top"><strong>Cursos IA MVP · Controle Acadêmico v0.6</strong><a class="btn secondary" href="index.php">Criador de Cursos</a></div><div class="wrap">
<?php if($flash):?><div class="flash <?=ah($flash['type'])?>"><?=ah($flash['message'])?></div><?php endif;?>
<div class="metrics"><div class="metric"><b><?=$metrics['students']?></b><span>Alunos</span></div><div class="metric"><b><?=$metrics['enrollments']?></b><span>Matrículas</span></div><div class="metric"><b><?=$metrics['active']?></b><span>Em andamento</span></div><div class="metric"><b><?=$metrics['completed']?></b><span>Concluídos</span></div><div class="metric"><b><?=$metrics['payment_pending']?></b><span>Pagamentos pendentes</span></div><div class="metric"><b><?=$metrics['cohorts']?></b><span>Turmas</span></div></div>
<div class="grid">
<div class="card"><h2>Novo aluno</h2><form method="post"><input type="hidden" name="action" value="create_student"><input name="name" placeholder="Nome completo" required><input name="email" type="email" placeholder="E-mail"><input name="phone" placeholder="Telefone"><div class="grid"><input name="city" placeholder="Cidade"><input name="state" placeholder="UF"></div><button class="btn">Cadastrar aluno</button></form></div>
<div class="card"><h2>Órgão / entidade contratante</h2><form method="post"><input type="hidden" name="action" value="create_organization"><input name="name" placeholder="Prefeitura, Câmara, Secretaria..." required><select name="organization_type"><option value="orgao_publico">Órgão público</option><option value="empresa">Empresa</option><option value="instituicao">Instituição</option></select><div class="grid"><input name="city" placeholder="Cidade"><input name="state" placeholder="UF"></div><input name="contact_name" placeholder="Responsável"><input name="contact_email" type="email" placeholder="E-mail do responsável"><input name="contract_reference" placeholder="Contrato / empenho / processo"><button class="btn">Cadastrar entidade</button></form></div>
<div class="card"><h2>Nova turma</h2><form method="post"><input type="hidden" name="action" value="create_cohort"><select name="course_id" required><option value="">Curso</option><?php foreach($courses as $c):?><option value="<?=$c['id']?>"><?=ah($c['title'])?></option><?php endforeach;?></select><select name="organization_id"><option value="">Turma aberta / sem órgão</option><?php foreach($organizations as $o):?><option value="<?=$o['id']?>"><?=ah($o['name'])?></option><?php endforeach;?></select><input name="name" placeholder="Ex.: Cidades Inclusivas — Prefeitura X — Turma 01" required><select name="modality"><option value="online">Online</option><option value="hibrido">Híbrido</option></select><span class="muted">Cursos presenciais são criados na área Turmas Presenciais.</span><input name="planned_hours" type="number" step="0.5" placeholder="Carga horária"><div class="grid"><input name="start_date" type="date"><input name="end_date" type="date"></div><input name="location" placeholder="Local das aulas presenciais"><button class="btn">Criar turma</button></form></div>
<div class="card"><h2>Nova matrícula</h2><form method="post"><input type="hidden" name="action" value="enroll_student"><select name="student_id" required><option value="">Aluno</option><?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=ah($s['name'])?></option><?php endforeach;?></select><select name="course_id" required><option value="">Curso</option><?php foreach($courses as $c):?><option value="<?=$c['id']?>"><?=ah($c['title'])?></option><?php endforeach;?></select><select name="cohort_id"><option value="">Individual / curso aberto</option><?php foreach($cohorts as $ch):?><option value="<?=$ch['id']?>"><?=ah($ch['name'])?> · <?=ah($ch['modality'])?></option><?php endforeach;?></select><select name="payment_status"><option value="pendente">Pagamento pendente</option><option value="pago">Pago</option><option value="isento">Isento</option><option value="contrato_institucional">Coberto por contrato institucional</option></select><input name="amount" type="number" step="0.01" placeholder="Valor individual, se houver"><input name="payment_method" placeholder="Pix, cartão, boleto, contrato..."><button class="btn">Matricular</button></form></div>
<div class="card"><h2>Novo encontro presencial</h2><form method="post"><input type="hidden" name="action" value="create_session"><select name="cohort_id" required><option value="">Turma</option><?php foreach($cohorts as $ch):?><option value="<?=$ch['id']?>"><?=ah($ch['name'])?></option><?php endforeach;?></select><input name="title" placeholder="Ex.: Encontro presencial 1" required><input name="session_date" type="date" required><div class="grid"><input name="start_time" type="time"><input name="end_time" type="time"></div><input name="planned_minutes" type="number" placeholder="Duração em minutos"><input name="location" placeholder="Local"><input name="notes" placeholder="Observações"><button class="btn">Cadastrar encontro</button></form></div>
<div class="card"><h2>Modelo de acompanhamento</h2><p>O histórico do aluno passa a reunir quatro trilhas:</p><p><span class="pill">Financeiro</span> pagamento/contrato</p><p><span class="pill">Online</span> aulas iniciadas, concluídas e tempo assistido</p><p><span class="pill">Presencial</span> encontros, presença e minutos frequentados</p><p><span class="pill">Conclusão</span> situação acadêmica e certificado/diploma</p><p class="muted">Integração automática com gateway de pagamento e player de vídeo entra como conector desta mesma estrutura, sem necessidade de redesenhar o banco.</p></div>
</div>
<div class="card"><h2>Acompanhamento dos alunos</h2><table><thead><tr><th>Aluno</th><th>Curso / turma</th><th>Pagamento</th><th>Online</th><th>Presencial</th><th>Situação</th><th>Certificado</th><th></th></tr></thead><tbody>
<?php foreach($enrollments as $e): $total=(int)$e['total_lessons'];$done=(int)$e['completed_lessons'];$pct=$total?round(($done/$total)*100):0;$hours=round(((int)$e['watched_seconds'])/3600,1); ?>
<tr><td><strong><?=ah($e['student_name'])?></strong><br><span class="muted"><?=ah($e['email'])?></span></td><td><?=ah($e['course_title'])?><br><span class="muted"><?=ah($e['cohort_name']?:'Individual')?><?= $e['organization_name']?' · '.ah($e['organization_name']):'' ?></span></td><td><span class="pill <?=$e['payment_status']==='pago'||$e['payment_status']==='contrato_institucional'?'ok':'warn'?>"><?=ah($e['payment_status'])?></span></td><td><?=$done?>/<?=$total?> aulas · <?=$pct?>%<br><span class="muted"><?=$hours?> h assistidas</span></td><td><?=(int)$e['present_sessions']?>/<?=(int)$e['total_sessions']?> presenças<br><span class="muted"><?=ah($e['modality']?:'online')?></span></td><td><span class="pill"><?=ah($e['status'])?></span></td><td><span class="pill"><?=ah($e['certificate_status']?:'não emitido')?></span></td><td><a class="btn" href="student_360.php?enrollment=<?=$e['id']?>">Aluno 360°</a> <a class="btn secondary" href="student_progress.php?enrollment=<?=$e['id']?>">Progresso</a> <a class="btn secondary" href="enrollment_finance.php?enrollment=<?=$e['id']?>">Financeiro</a></td></tr>
<?php endforeach; if(!$enrollments):?><tr><td colspan="8" class="muted">Nenhuma matrícula cadastrada ainda.</td></tr><?php endif;?></tbody></table></div>
</div></body></html>