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
            aflash('Aluno cadastrado com sucesso.');
        } elseif ($action === 'create_organization') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Informe o nome do órgão/entidade.');
            $stmt=$pdo->prepare('INSERT INTO organizations(name,organization_type,city,state,contact_name,contact_email,contract_reference) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$name,(string)($_POST['organization_type'] ?? 'orgao_publico'),trim((string)($_POST['city'] ?? ''))?:null,trim((string)($_POST['state'] ?? ''))?:null,trim((string)($_POST['contact_name'] ?? ''))?:null,trim((string)($_POST['contact_email'] ?? ''))?:null,trim((string)($_POST['contract_reference'] ?? ''))?:null]);
            aflash('Órgão/entidade cadastrado com sucesso.');
        } elseif ($action === 'create_cohort') {
            $courseId=(int)($_POST['course_id'] ?? 0);
            $name=trim((string)($_POST['name'] ?? ''));
            if ($courseId<1 || $name==='') throw new RuntimeException('Informe curso e nome da turma.');
            $modality=(string)($_POST['modality'] ?? 'online');
            if (!in_array($modality,['online','hibrido'],true)) throw new RuntimeException('Turmas presenciais devem ser criadas pela área Turmas Presenciais.');
            $stmt=$pdo->prepare('INSERT INTO cohorts(course_id,organization_id,name,modality,planned_hours,start_date,end_date,location,status) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId,(int)($_POST['organization_id'] ?? 0) ?: null,$name,$modality,(float)($_POST['planned_hours'] ?? 0),($_POST['start_date'] ?? '') ?: null,($_POST['end_date'] ?? '') ?: null,trim((string)($_POST['location'] ?? ''))?:null,'planejada']);
            aflash('Turma criada com sucesso.');
        } elseif ($action === 'enroll_student') {
            $studentId=(int)($_POST['student_id'] ?? 0); $courseId=(int)($_POST['course_id'] ?? 0); $cohortId=(int)($_POST['cohort_id'] ?? 0);
            if ($studentId<1 || $courseId<1) throw new RuntimeException('Selecione aluno e curso.');
            $type=$cohortId>0?'institucional':'individual';
            $paymentStatus=(string)($_POST['payment_status'] ?? ($type==='institucional'?'contrato_institucional':'pendente'));
            $stmt=$pdo->prepare('INSERT INTO enrollments(student_id,course_id,cohort_id,enrollment_type,status,payment_status,amount,payment_method,paid_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $paidAt=$paymentStatus==='pago'?date('Y-m-d H:i:s'):null;
            $stmt->execute([$studentId,$courseId,$cohortId?:null,$type,'matriculado',$paymentStatus,(float)($_POST['amount'] ?? 0),trim((string)($_POST['payment_method'] ?? ''))?:null,$paidAt]);
            aflash('Matrícula realizada com sucesso.');
        } elseif ($action === 'update_enrollment') {
            $id=(int)($_POST['enrollment_id'] ?? 0);
            $status=(string)($_POST['status'] ?? 'matriculado');
            $payment=(string)($_POST['payment_status'] ?? 'pendente');
            $paidAt=$payment==='pago'?date('Y-m-d H:i:s'):null;
            $stmt=$pdo->prepare('UPDATE enrollments SET status=?,payment_status=?,paid_at=COALESCE(paid_at,?) WHERE id=?');
            $stmt->execute([$status,$payment,$paidAt,$id]);
            aflash('Situação da matrícula atualizada.');
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
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Controle Acadêmico — Cursos IA MVP</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .modal-drawer {
      display: none;
      background: var(--bg-surface);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: 24px;
      margin-bottom: 24px;
      animation: slideDown 0.2s ease;
    }
    .modal-drawer.active {
      display: block;
    }
    @keyframes slideDown {
      from { opacity: 0; transform: translateY(-8px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .search-bar-wrap {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }
    .search-input {
      max-width: 380px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      padding: 10px 16px;
      color: #fff;
      font-size: 0.875rem;
      outline: none;
    }
    .search-input:focus {
      border-color: var(--brand-primary);
    }
    .student-badge {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--brand-light);
      color: var(--brand-primary);
      font-weight: 700;
      display: grid;
      place-items: center;
      font-size: 0.85rem;
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <!-- Sidebar Administrativa -->
    <aside class="sidebar">
      <div class="brand" style="padding-bottom:14px;">
        <img src="assets/logo.svg" alt="Cursos IA MVP" style="height:42px;display:block;margin-bottom:6px;">
        <small>Gestão Acadêmica & Estúdio IA</small>
      </div>
      <nav>
        <div class="nav-title">Operação Principal</div>
        <a class="nav-link" href="dashboard.php"><span class="dot"></span>Dashboard Geral</a>
        <a class="nav-link active" href="academic.php"><span class="dot"></span>Controle Acadêmico</a>
        <a class="nav-link" href="academic_rules.php"><span class="dot"></span>Regras e Critérios</a>
        <a class="nav-link" href="assessments.php"><span class="dot"></span>Avaliações e Notas</a>
        <a class="nav-link" href="turmas_presenciais.php"><span class="dot"></span>Turmas Presenciais</a>
        <a class="nav-link" href="financial.php"><span class="dot"></span>Financeiro</a>

        <div class="nav-title">Estúdio de Conteúdo</div>
        <a class="nav-link" href="index.php"><span class="dot"></span>Criador de Cursos IA</a>
        <a class="nav-link" href="factory.php"><span class="dot"></span>Fábrica Pedagógica</a>

        <div class="nav-title">Ambiente do Aluno</div>
        <a class="nav-link" href="portal_access_admin.php"><span class="dot"></span>Códigos de Acesso</a>
        <a class="nav-link" href="aluno_login.php" target="_blank"><span class="dot"></span>Portal EAD (Login) ↗</a>
      </nav>
    </aside>

    <!-- Área Central -->
    <div class="main">
      <header class="topbar">
        <strong>Central de Gestão Acadêmica 360°</strong>
        <span class="env">HML v1.2 · Produção Ativa</span>
      </header>

      <main class="content">
        <?php if($flash):?>
          <div class="flash <?=ah($flash['type'])?>"><?=ah($flash['message'])?></div>
        <?php endif;?>

        <!-- Título e Ações de Topo -->
        <div class="page-title">
          <div>
            <h1>Controle de Alunos e Matrículas</h1>
            <p>Gerencie o ciclo acadêmico completo: cadastros, turmas, status financeiro e emissão de certificados.</p>
          </div>
          <div class="actions">
            <button class="btn" onclick="toggleDrawer('drawerEnroll')">+ Nova Matrícula</button>
            <button class="btn btn-secondary" onclick="toggleDrawer('drawerStudent')">+ Cadastrar Aluno</button>
            <button class="btn btn-secondary" onclick="toggleDrawer('drawerCohort')">+ Nova Turma</button>
            <button class="btn btn-secondary" onclick="toggleDrawer('drawerOrg')">+ Órgão / Entidade</button>
          </div>
        </div>

        <!-- Painel de KPIs Acadêmicos -->
        <div class="grid grid-6" style="margin-bottom:24px;">
          <div class="metric">
            <span class="value"><?=$metrics['students']?></span>
            <span class="label">Total de Alunos</span>
            <span class="hint">Cadastros ativos no sistema</span>
          </div>
          <div class="metric">
            <span class="value"><?=$metrics['enrollments']?></span>
            <span class="label">Matrículas</span>
            <span class="hint">Em todos os cursos</span>
          </div>
          <div class="metric">
            <span class="value"><?=$metrics['active']?></span>
            <span class="label">Cursando Agora</span>
            <span class="hint">Com progresso em andamento</span>
          </div>
          <div class="metric">
            <span class="value"><?=$metrics['completed']?></span>
            <span class="label">Concluídos</span>
            <span class="hint">Critérios cumpridos</span>
          </div>
          <div class="metric">
            <span class="value" style="color:var(--warning);"><?=$metrics['payment_pending']?></span>
            <span class="label">Pagamento Pendente</span>
            <span class="hint">Aguardando liquidação</span>
          </div>
          <div class="metric">
            <span class="value"><?=$metrics['cohorts']?></span>
            <span class="label">Turmas Ofertadas</span>
            <span class="hint">Presenciais e híbridas</span>
          </div>
        </div>

        <!-- Gavetas Colapsáveis para Ações Rápidas -->
        <div id="drawerEnroll" class="modal-drawer">
          <h3>Nova Matrícula</h3>
          <form method="post" class="form-card">
            <input type="hidden" name="action" value="enroll_student">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Aluno</label>
                <select name="student_id" required>
                  <option value="">Selecione o aluno...</option>
                  <?php foreach($students as $s):?><option value="<?=$s['id']?>"><?=ah($s['name'])?> (<?=ah($s['email'])?>)</option><?php endforeach;?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Curso</label>
                <select name="course_id" required>
                  <option value="">Selecione o curso...</option>
                  <?php foreach($courses as $c):?><option value="<?=$c['id']?>"><?=ah($c['title'])?></option><?php endforeach;?>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Turma</label>
                <select name="cohort_id">
                  <option value="">Matrícula Individual (Sem Turma)</option>
                  <?php foreach($cohorts as $ch):?><option value="<?=$ch['id']?>"><?=ah($ch['name'])?> · <?=ah($ch['modality'])?></option><?php endforeach;?>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Status do Pagamento</label>
                <select name="payment_status">
                  <option value="pago">Pago / Liquidado</option>
                  <option value="pendente">Pagamento Pendente</option>
                  <option value="isento">Isento</option>
                  <option value="contrato_institucional">Contrato Institucional</option>
                </select>
              </div>
            </div>
            <div style="margin-top:14px;display:flex;gap:10px;">
              <button type="submit" class="btn">Efetivar Matrícula</button>
              <button type="button" class="btn btn-secondary" onclick="toggleDrawer('drawerEnroll')">Cancelar</button>
            </div>
          </form>
        </div>

        <div id="drawerStudent" class="modal-drawer">
          <h3>Cadastrar Novo Aluno</h3>
          <form method="post" class="form-card">
            <input type="hidden" name="action" value="create_student">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Nome Completo</label>
                <input name="name" required placeholder="Ex: Maria da Silva">
              </div>
              <div class="form-group">
                <label class="form-label">E-mail</label>
                <input name="email" type="email" placeholder="maria@email.com">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Telefone / WhatsApp</label>
                <input name="phone" placeholder="(11) 98765-4321">
              </div>
              <div class="form-group">
                <label class="form-label">Cidade / UF</label>
                <div style="display:grid;grid-template-columns:3fr 1fr;gap:8px;">
                  <input name="city" placeholder="Cidade">
                  <input name="state" placeholder="UF" maxlength="2">
                </div>
              </div>
            </div>
            <div style="margin-top:14px;display:flex;gap:10px;">
              <button type="submit" class="btn">Salvar Aluno</button>
              <button type="button" class="btn btn-secondary" onclick="toggleDrawer('drawerStudent')">Cancelar</button>
            </div>
          </form>
        </div>

        <!-- Tabela Principal de Alunos -->
        <section class="card">
          <div class="search-bar-wrap">
            <div>
              <h2 style="margin:0 0 4px 0;font-size:1.25rem;">Matrículas Ativas</h2>
              <p style="margin:0;font-size:0.85rem;color:var(--text-muted);">Acompanhamento em tempo real de notas, aulas assistidas e certificação.</p>
            </div>
            <input type="search" id="filterInput" class="search-input" placeholder="🔍 Buscar por aluno, e-mail ou curso..." onkeyup="filterTable()">
          </div>

          <div class="table-wrap">
            <table class="table" id="studentsTable">
              <thead>
                <tr>
                  <th>Aluno</th>
                  <th>Curso / Turma</th>
                  <th>Progresso Online</th>
                  <th>Financeiro</th>
                  <th>Situação</th>
                  <th>Certificado</th>
                  <th style="text-align:right;">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($enrollments as $e):
                  $tot = (int)$e['total_lessons'];
                  $comp = (int)$e['completed_lessons'];
                  $pct = $tot ? round(($comp / $tot) * 100) : 0;
                  $initial = strtoupper(substr(trim($e['student_name']), 0, 1));
                ?>
                  <tr>
                    <td>
                      <div style="display:flex;align-items:center;gap:12px;">
                        <div class="student-badge"><?=$initial?></div>
                        <div>
                          <strong style="color:#fff;font-size:0.9rem;"><?=ah($e['student_name'])?></strong>
                          <div style="font-size:0.75rem;color:var(--text-muted);"><?=ah($e['email'])?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div style="color:#fff;font-weight:600;"><?=ah($e['course_title'])?></div>
                      <div style="font-size:0.75rem;color:var(--text-muted);">
                        <?=ah($e['cohort_name'] ?: 'Individual')?>
                        <?= $e['organization_name'] ? ' · ' . ah($e['organization_name']) : '' ?>
                      </div>
                    </td>
                    <td style="min-width:180px;">
                      <div style="display:flex;justify-content:space-between;font-size:0.75rem;margin-bottom:4px;">
                        <span style="color:var(--text-secondary);"><?=$comp?>/<?=$tot?> aulas</span>
                        <strong style="color:#fff;"><?=$pct?>%</strong>
                      </div>
                      <div class="progress-bar" style="height:6px;margin:0;">
                        <div class="progress-bar-fill" style="width:<?=$pct?>%;"></div>
                      </div>
                    </td>
                    <td>
                      <span class="pill <?=in_array($e['payment_status'], ['pago','contrato_institucional','isento'], true) ? 'ok' : 'warn'?>">
                        <?=ah(strtoupper($e['payment_status']))?>
                      </span>
                    </td>
                    <td>
                      <span class="pill <?=$e['status']==='concluido' ? 'ok' : ''?>">
                        <?=ah(strtoupper($e['status']))?>
                      </span>
                    </td>
                    <td>
                      <span class="pill <?=$e['certificate_status']==='emitido' ? 'ok' : ''?>">
                        <?=$e['certificate_status']==='emitido' ? '✓ Emitido' : 'Pendente'?>
                      </span>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                      <a class="btn btn-secondary" style="padding:6px 10px;font-size:0.75rem;" href="student_360.php?enrollment=<?=$e['id']?>">Visão 360°</a>
                      <a class="btn btn-secondary" style="padding:6px 10px;font-size:0.75rem;" href="student_progress.php?enrollment=<?=$e['id']?>">Progresso</a>
                      <a class="btn btn-secondary" style="padding:6px 10px;font-size:0.75rem;" href="academic_completion.php?enrollment=<?=$e['id']?>">Conclusão</a>
                    </td>
                  </tr>
                <?php endforeach;?>
                <?php if(!$enrollments):?>
                  <tr>
                    <td colspan="7" class="empty">Nenhuma matrícula encontrada no momento.</td>
                  </tr>
                <?php endif;?>
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  </div>

  <script>
    function toggleDrawer(id) {
      const el = document.getElementById(id);
      if(el) {
        el.classList.toggle('active');
      }
    }
    function filterTable() {
      const q = document.getElementById('filterInput').value.toLowerCase();
      const rows = document.querySelectorAll('#studentsTable tbody tr');
      rows.forEach(r => {
        const text = r.textContent.toLowerCase();
        r.style.display = text.includes(q) ? '' : 'none';
      });
    }
  </script>
</body>
</html>
