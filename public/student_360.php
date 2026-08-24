<?php
declare(strict_types=1);

require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/finance_model.php';

function s360Db(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function s360h(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function s360money(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function s360date(?string $v): string { return $v ? date('d/m/Y',strtotime($v)) : '—'; }

$pdo=s360Db();
ensureAcademicModel($pdo);
ensureFinanceModel($pdo);

$enrollmentId=(int)($_GET['enrollment']??0);
if($enrollmentId<1){http_response_code(400);echo 'Matrícula inválida.';exit;}

$stmt=$pdo->prepare("SELECT e.*,s.name student_name,s.email,s.phone,s.document_ref,s.city student_city,s.state student_state,
    c.title course_title,ch.name cohort_name,ch.modality,ch.planned_hours,ch.start_date cohort_start,ch.end_date cohort_end,ch.location,
    o.name organization_name,o.contract_reference
    FROM enrollments e
    INNER JOIN students s ON s.id=e.student_id
    INNER JOIN courses c ON c.id=e.course_id
    LEFT JOIN cohorts ch ON ch.id=e.cohort_id
    LEFT JOIN organizations o ON o.id=ch.organization_id
    WHERE e.id=?");
$stmt->execute([$enrollmentId]);
$enrollment=$stmt->fetch();
if(!$enrollment){http_response_code(404);echo 'Matrícula não encontrada.';exit;}

$academic=academicEnrollmentMetrics($pdo,$enrollmentId);
$finance=enrollmentFinanceSummary($pdo,$enrollmentId);

$stmt=$pdo->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE cohort_id=?");
$stmt->execute([(int)($enrollment['cohort_id']??0)]);
$totalSessions=(int)$stmt->fetchColumn();

$stmt=$pdo->prepare("SELECT * FROM certificates WHERE enrollment_id=? LIMIT 1");
$stmt->execute([$enrollmentId]);
$certificate=$stmt->fetch() ?: null;

$stmt=$pdo->prepare("SELECT ec.id,ec.description,ec.amount,ec.due_date,ec.status,
    COALESCE((SELECT SUM(ep.amount) FROM enrollment_payments ep WHERE ep.charge_id=ec.id AND ep.status='confirmado'),0) paid_amount
    FROM enrollment_charges ec WHERE ec.enrollment_id=? ORDER BY ec.id DESC LIMIT 5");
$stmt->execute([$enrollmentId]);
$charges=$stmt->fetchAll();

$totalLessons=(int)($academic['total_lessons']??0);
$completedLessons=(int)($academic['completed_lessons']??0);
$onlinePct=$totalLessons>0?round(($completedLessons/$totalLessons)*100):0;
$watchedHours=round(((int)($academic['watched_seconds']??0))/3600,1);
$plannedMinutes=(int)($academic['planned_minutes']??0);
$attendedMinutes=(int)($academic['attended_minutes']??0);
$attendancePct=$plannedMinutes>0?round(($attendedMinutes/$plannedMinutes)*100):0;
$presentCount=(int)($academic['present_count']??0);

$pending=[];
if(!in_array($enrollment['payment_status'],['pago','isento','contrato_institucional'],true))$pending[]='Regularizar o financeiro da matrícula.';
if($totalLessons>0 && $completedLessons<$totalLessons)$pending[]='Concluir '.($totalLessons-$completedLessons).' aula(s) online.';
if($totalSessions>0 && (int)($academic['attendance_records']??0)<$totalSessions)$pending[]='Completar os lançamentos de presença da turma.';
if($enrollment['status']!=='concluido')$pending[]='Realizar o fechamento acadêmico da matrícula.';
if(!$certificate || $certificate['status']!=='emitido')$pending[]='Emitir certificado/diploma após a conclusão.';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aluno 360 — <?=s360h($enrollment['student_name'])?></title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Visão operacional do aluno</small></div><nav><div class="nav-title">Operação</div><a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard</a><a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a><a class="nav-link" href="financial.php"><span class="dot"></span>Financeiro</a><div class="nav-title">Aluno</div><a class="nav-link active" href="student_360.php?enrollment=<?=$enrollmentId?>"><span class="dot"></span>Ficha 360°</a><a class="nav-link" href="student_progress.php?enrollment=<?=$enrollmentId?>"><span class="dot"></span>Progresso detalhado</a><a class="nav-link" href="enrollment_finance.php?enrollment=<?=$enrollmentId?>"><span class="dot"></span>Extrato financeiro</a></nav></aside>
<div class="main"><header class="topbar"><strong>Aluno 360° · Cursos IA</strong><span class="env">HML · matrícula #<?=$enrollmentId?></span></header><main class="content">
<div class="page-title"><div><h1><?=s360h($enrollment['student_name'])?></h1><p><?=s360h($enrollment['course_title'])?><?= $enrollment['cohort_name']?' · '.s360h($enrollment['cohort_name']):'' ?></p></div><div class="actions"><a class="btn" href="student_progress.php?enrollment=<?=$enrollmentId?>">Progresso</a><a class="btn secondary" href="enrollment_finance.php?enrollment=<?=$enrollmentId?>">Financeiro</a></div></div>

<div class="grid grid-6" style="margin-bottom:18px">
<div class="metric"><span class="value"><?=s360h($enrollment['status'])?></span><span class="label">Matrícula</span><span class="hint">Situação acadêmica</span></div>
<div class="metric"><span class="value"><?=s360h($enrollment['payment_status'])?></span><span class="label">Financeiro</span><span class="hint"><?=s360money((float)$finance['balance'])?> em aberto</span></div>
<div class="metric"><span class="value"><?=$onlinePct?>%</span><span class="label">Online</span><span class="hint"><?=$completedLessons?>/<?=$totalLessons?> aulas</span></div>
<div class="metric"><span class="value"><?=$watchedHours?>h</span><span class="label">Assistido</span><span class="hint">Tempo acumulado</span></div>
<div class="metric"><span class="value"><?=$attendancePct?>%</span><span class="label">Presencial</span><span class="hint"><?=$presentCount?> presença(s)</span></div>
<div class="metric"><span class="value"><?=s360h($certificate['status']??'não emitido')?></span><span class="label">Documento</span><span class="hint"><?=s360h($certificate['certificate_type']??'certificado/diploma')?></span></div>
</div>

<div class="grid grid-2">
<section class="card"><div class="section-title"><h2>Cadastro e vínculo</h2><span class="pill"><?=s360h($enrollment['enrollment_type'])?></span></div>
<p><strong>E-mail:</strong> <?=s360h($enrollment['email']?:'—')?><br><strong>Telefone:</strong> <?=s360h($enrollment['phone']?:'—')?><br><strong>Documento:</strong> <?=s360h($enrollment['document_ref']?:'—')?><br><strong>Cidade:</strong> <?=s360h(trim(($enrollment['student_city']??'').' / '.($enrollment['student_state']??''),' /'))?></p>
<p><strong>Contratante:</strong> <?=s360h($enrollment['organization_name']?:'Matrícula individual')?><br><strong>Contrato/processo:</strong> <?=s360h($enrollment['contract_reference']?:'—')?></p>
</section>
<section class="card"><div class="section-title"><h2>Turma / oferta</h2><span class="pill"><?=s360h($enrollment['modality']?:'online')?></span></div>
<p><strong>Turma:</strong> <?=s360h($enrollment['cohort_name']?:'Sem turma')?><br><strong>Carga prevista:</strong> <?=number_format((float)($enrollment['planned_hours']??0),1,',','.')?> h<br><strong>Período:</strong> <?=s360date($enrollment['cohort_start'])?> a <?=s360date($enrollment['cohort_end'])?><br><strong>Local:</strong> <?=s360h($enrollment['location']?:'—')?></p>
<p class="muted">Matrícula em <?=s360date($enrollment['enrolled_at'])?><?= $enrollment['last_seen_at']?' · último acesso em '.s360date($enrollment['last_seen_at']):'' ?>.</p>
</section>
</div>

<div class="grid grid-2" style="margin-top:18px">
<section class="card"><div class="section-title"><h2>Financeiro da matrícula</h2><a class="btn ghost" href="enrollment_finance.php?enrollment=<?=$enrollmentId?>">Abrir extrato</a></div>
<div class="info-strip"><span class="item">Cobrado: <strong><?=s360money((float)$finance['total_charged'])?></strong></span><span class="item">Pago: <strong><?=s360money((float)$finance['total_paid'])?></strong></span><span class="item">Saldo: <strong><?=s360money((float)$finance['balance'])?></strong></span></div>
<div class="table-wrap" style="margin-top:12px"><table class="table"><thead><tr><th>Cobrança</th><th>Vencimento</th><th>Valor</th><th>Status</th></tr></thead><tbody><?php foreach($charges as $c):?><tr><td><?=s360h($c['description'])?></td><td><?=s360date($c['due_date'])?></td><td><?=s360money((float)$c['amount'])?><br><span class="muted">pago <?=s360money((float)$c['paid_amount'])?></span></td><td><span class="pill <?=in_array($c['status'],['paga'],true)?'ok':(in_array($c['status'],['atrasada'],true)?'warn':'')?>"><?=s360h($c['status'])?></span></td></tr><?php endforeach;if(!$charges):?><tr><td colspan="4" class="empty">Nenhuma cobrança detalhada registrada.</td></tr><?php endif;?></tbody></table></div>
</section>
<section class="card"><div class="section-title"><h2>Andamento acadêmico</h2><a class="btn ghost" href="student_progress.php?enrollment=<?=$enrollmentId?>">Detalhar</a></div>
<p><strong>Online:</strong> <?=$completedLessons?> de <?=$totalLessons?> aulas concluídas (<?=$onlinePct?>%).</p><p><strong>Tempo assistido:</strong> <?=$watchedHours?> hora(s).</p><p><strong>Presencial:</strong> <?=$attendedMinutes?> de <?=$plannedMinutes?> minutos registrados (<?=$attendancePct?>%).</p><p><strong>Presenças:</strong> <?=$presentCount?> de <?=$totalSessions?> encontro(s) previstos.</p>
</section>
</div>

<div class="grid grid-2" style="margin-top:18px">
<section class="card"><div class="section-title"><h2>Avaliações</h2><span class="pill warn">Ainda não parametrizado</span></div><p>Esta HML local ainda não possui o modelo de avaliações/notas integrado ao fechamento da matrícula.</p><p class="muted">A Ficha 360 não inventa uma nota. Esse bloco será ativado quando o motor de avaliações for reconciliado com o workspace local.</p></section>
<section class="card"><div class="section-title"><h2>Certificação</h2><span class="pill <?=($certificate&&$certificate['status']==='emitido')?'ok':'warn'?>"><?=s360h($certificate['status']??'pendente')?></span></div><?php if($certificate):?><p><strong><?=s360h(ucfirst($certificate['certificate_type']))?></strong><br>Código: <?=s360h($certificate['certificate_code'])?><br>Emitido em: <?=s360date($certificate['issued_at'])?></p><?php else:?><p>Nenhum certificado ou diploma emitido para esta matrícula.</p><?php endif;?></section>
</div>

<section class="card" style="margin-top:18px"><div class="section-title"><h2>Próximas ações</h2><span class="pill <?=empty($pending)?'ok':'warn'?>"><?=empty($pending)?'Fluxo completo':count($pending).' pendência(s)'?></span></div><?php if($pending):?><ul><?php foreach($pending as $item):?><li><?=s360h($item)?></li><?php endforeach;?></ul><?php else:?><p>Financeiro, execução acadêmica, fechamento e documento estão concluídos para esta matrícula.</p><?php endif;?></section>
</main></div></div></body></html>