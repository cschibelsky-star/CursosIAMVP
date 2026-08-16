<?php
declare(strict_types=1);
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function dashDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function dh(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }

$pdo=dashDb();ensureAcademicModel($pdo);ensureStudentPortal($pdo);
$metrics=[
    'courses'=>(int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'students'=>(int)$pdo->query('SELECT COUNT(*) FROM students WHERE active=1')->fetchColumn(),
    'enrollments'=>(int)$pdo->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'active'=>(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status IN ('matriculado','em_andamento')")->fetchColumn(),
    'presential'=>(int)$pdo->query("SELECT COUNT(*) FROM cohorts WHERE modality='presencial'")->fetchColumn(),
    'pending_payment'=>(int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE payment_status IN ('pendente','atrasado')")->fetchColumn(),
    'certificates'=>(int)$pdo->query("SELECT COUNT(*) FROM certificates WHERE status='emitido'")->fetchColumn(),
];
$recent=$pdo->query("SELECT e.id,e.status,e.payment_status,s.name student_name,c.title course_title,ch.name cohort_name,ch.modality,o.name organization_name,e.last_seen_at
FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id ORDER BY e.id DESC LIMIT 8")->fetchAll();
$cohorts=$pdo->query("SELECT ch.id,ch.name,ch.modality,ch.status,ch.planned_hours,c.title course_title,o.name organization_name,(SELECT COUNT(*) FROM enrollments e WHERE e.cohort_id=ch.id) student_count FROM cohorts ch INNER JOIN courses c ON c.id=ch.course_id LEFT JOIN organizations o ON o.id=ch.organization_id ORDER BY ch.id DESC LIMIT 6")->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard — Cursos IA</title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell">
<aside class="sidebar"><div class="brand">Cursos IA <small>Gestão acadêmica e fábrica de cursos</small></div><nav>
<div class="nav-title">Operação</div>
<a class="nav-link active" href="dashboard.php"><span class="dot"></span>Dashboard</a>
<a class="nav-link" href="index.php"><span class="dot"></span>Criador de Cursos</a>
<a class="nav-link" href="academic.php"><span class="dot"></span>Controle Acadêmico</a>
<a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a>
<div class="nav-title">Aluno</div>
<a class="nav-link" href="portal_access_admin.php"><span class="dot"></span>Acessos do Portal</a>
<a class="nav-link" href="aluno_login.php"><span class="dot"></span>Portal do Aluno</a>
<div class="nav-title">Produção</div>
<a class="nav-link" href="cidades_inclusivas.php"><span class="dot"></span>Cidades Inclusivas</a>
<a class="nav-link" href="diagnostic.php"><span class="dot"></span>Diagnóstico</a>
</nav></aside>
<div class="main"><header class="topbar"><strong>Centro Acadêmico · Cursos IA</strong><span class="env">HML · v0.8</span></header><main class="content">
<div class="page-title"><div><h1>Dashboard</h1><p>Visão central da produção dos cursos, turmas e andamento dos alunos.</p></div><div class="actions"><a class="btn" href="index.php">Criar curso</a><a class="btn secondary" href="academic.php">Cadastrar aluno</a></div></div>
<div class="grid grid-6" style="margin-bottom:18px">
<div class="metric"><span class="value"><?=$metrics['courses']?></span><span class="label">Cursos</span><span class="hint">Catálogo pedagógico</span></div>
<div class="metric"><span class="value"><?=$metrics['students']?></span><span class="label">Alunos</span><span class="hint">Cadastros ativos</span></div>
<div class="metric"><span class="value"><?=$metrics['enrollments']?></span><span class="label">Matrículas</span><span class="hint">Todas as modalidades</span></div>
<div class="metric"><span class="value"><?=$metrics['active']?></span><span class="label">Em andamento</span><span class="hint">Matriculados e ativos</span></div>
<div class="metric"><span class="value"><?=$metrics['presential']?></span><span class="label">Turmas presenciais</span><span class="hint">Ofertas presenciais</span></div>
<div class="metric"><span class="value"><?=$metrics['certificates']?></span><span class="label">Certificações</span><span class="hint">Documentos emitidos</span></div>
</div>
<div class="grid grid-4" style="margin-bottom:18px">
<a class="card quick-card" href="index.php"><strong>Fábrica de Curso</strong><span>Fontes, estrutura, módulos, aulas e materiais.</span></a>
<a class="card quick-card" href="academic.php"><strong>Gestão de Alunos</strong><span>Matrícula, financeiro, progresso e conclusão.</span></a>
<a class="card quick-card" href="turmas_presenciais.php"><strong>Cursos Presenciais</strong><span>Turmas, contratantes, calendário, carga horária e frequência.</span></a>
<a class="card quick-card" href="portal_access_admin.php"><strong>Portal do Aluno</strong><span>Acessos individuais, programa do curso e acompanhamento.</span></a>
</div>
<div class="grid grid-2">
<section class="card"><div class="section-title"><h2>Alunos recentes</h2><a class="btn ghost" href="academic.php">Ver todos</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Aluno</th><th>Curso</th><th>Modalidade</th><th>Status</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><strong><?=dh($r['student_name'])?></strong><br><span class="muted"><?=dh($r['organization_name']?:'Matrícula individual')?></span></td><td><?=dh($r['course_title'])?><br><span class="muted"><?=dh($r['cohort_name']?:'Sem turma')?></span></td><td><span class="pill"><?=dh($r['modality']?:'online')?></span></td><td><span class="pill <?=in_array($r['status'],['em_andamento','concluido'],true)?'ok':''?>"><?=dh($r['status'])?></span><?php if(in_array($r['payment_status'],['pendente','atrasado'],true)):?><br><span class="pill warn" style="margin-top:5px"><?=dh($r['payment_status'])?></span><?php endif;?></td></tr><?php endforeach;if(!$recent):?><tr><td colspan="4" class="empty">Nenhuma matrícula cadastrada.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><div class="section-title"><h2>Turmas recentes</h2><a class="btn ghost" href="turmas_presenciais.php">Gerenciar</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Turma</th><th>Modalidade</th><th>Alunos</th><th>Carga</th></tr></thead><tbody><?php foreach($cohorts as $c):?><tr><td><strong><?=dh($c['course_title'])?></strong><br><span class="muted"><?=dh($c['name'])?><?= $c['organization_name']?' · '.dh($c['organization_name']):'' ?></span></td><td><span class="pill"><?=dh($c['modality'])?></span></td><td><?=(int)$c['student_count']?></td><td><?=number_format((float)$c['planned_hours'],1,',','.')?> h</td></tr><?php endforeach;if(!$cohorts):?><tr><td colspan="4" class="empty">Nenhuma turma cadastrada.</td></tr><?php endif;?></tbody></table></div></section>
</div>
<section class="card" style="margin-top:16px"><div class="section-title"><h2>Homologação visual</h2><span class="pill warn">Em andamento</span></div><div class="flow"><span class="step done">Dashboard central</span><span class="step done">Programa em todas as modalidades</span><span class="step">Padronizar Controle Acadêmico</span><span class="step">Padronizar Turmas Presenciais</span><span class="step">Padronizar Portal do Aluno</span><span class="step">Responsividade</span><span class="step">Homologação final</span></div><p class="muted" style="margin-bottom:0;margin-top:14px">A validação visual final deve ser feita com dados reais de homologação: um aluno online, uma turma presencial institucional e uma matrícula híbrida.</p></section>
</main></div></div></body></html>