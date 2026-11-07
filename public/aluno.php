<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/academic_eligibility.php';
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

function getModuleCoverImage(string $title, ?string $customCover = null): string
{
    if(!empty($customCover)) return $customCover;
    $t = mb_strtolower($title, 'UTF-8');
    if(str_contains($t, 'política') || str_contains($t, 'inclusiv') || str_contains($t, 'pública') || str_contains($t, 'social')){
        return 'https://images.unsplash.com/photo-1541872703-74c5e44368f9?auto=format&fit=crop&w=800&q=80'; // Planejamento / Cidades
    } elseif(str_contains($t, 'ia') || str_contains($t, 'inteligência') || str_contains($t, 'dados') || str_contains($t, 'prompt')){
        return 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=800&q=80'; // IA / Tecnologia
    } elseif(str_contains($t, 'fundamento') || str_contains($t, 'introdução') || str_contains($t, 'conceito')){
        return 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=800&q=80'; // Conceitos / Fundamentos
    } elseif(str_contains($t, 'automação') || str_contains($t, 'workflow') || str_contains($t, 'prática')){
        return 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=800&q=80'; // Chips / Automação
    }
    return 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=800&q=80'; // Genérico Educação Digital
}

$pdo=alunoDb();
ensureAcademicModel($pdo);
ensureStudentPortal($pdo);
$enrollmentId=(int)($_SESSION['student_enrollment_id']??0);
if($enrollmentId<1){header('Location: aluno_login.php');exit;}

$stmt=$pdo->prepare("SELECT e.*,s.name AS student_name,s.email,s.phone,c.id AS course_id,c.title AS course_title,c.objective AS course_objective,ch.name AS cohort_name,ch.modality,ch.planned_hours,ch.start_date,ch.end_date,ch.location,o.name AS organization_name
FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id WHERE e.id=? AND s.active=1 LIMIT 1");
$stmt->execute([$enrollmentId]);
$enrollment=$stmt->fetch();
if(!$enrollment){$_SESSION=[];session_destroy();header('Location: aluno_login.php');exit;}

$pdo->prepare('UPDATE enrollments SET last_seen_at=NOW() WHERE id=?')->execute([$enrollmentId]);
$lessons=portalCourseLessons($pdo,$enrollmentId,(int)$enrollment['course_id']);
$attendance=portalAttendance($pdo,$enrollmentId,$enrollment['cohort_id']?(int)$enrollment['cohort_id']:null);
$certificate=portalCertificate($pdo,$enrollmentId);
$metrics=academicEnrollmentMetrics($pdo,$enrollmentId);
$academicState=academicEligibilityState($pdo,$enrollmentId);

// Busca Avaliações e Notas do Aluno
$stmtAss = $pdo->prepare("SELECT a.id, a.title, a.assessment_type, a.max_score, a.weight, a.required, ar.score, ar.status, ar.evaluated_at, ar.notes
FROM assessments a
LEFT JOIN assessment_results ar ON ar.assessment_id=a.id AND ar.enrollment_id=?
WHERE a.course_id=? AND a.active=1
ORDER BY a.id ASC");
$stmtAss->execute([$enrollmentId, (int)$enrollment['course_id']]);
$assessments = $stmtAss->fetchAll();

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
$finalGrade=$academicState['final_grade'];
$attendancePercent=(float)$academicState['attendance_percent'];

// Agrupamento por módulo
$modules=[];
$firstPendingLessonId = null;
foreach($lessons as $lesson){
    $key=(int)$lesson['module_id'];
    if(!isset($modules[$key]))$modules[$key]=['position'=>$lesson['module_position'],'title'=>$lesson['module_title'],'lessons'=>[]];
    $modules[$key]['lessons'][]=$lesson;
    if($firstPendingLessonId === null && (float)$lesson['percent_complete'] < 100){
        $firstPendingLessonId = (int)$lesson['lesson_id'];
    }
}
if($firstPendingLessonId === null && !empty($lessons)){
    $firstPendingLessonId = (int)$lessons[0]['lesson_id'];
}

// Gerar iniciais para o Avatar
$nameParts = explode(' ', trim($enrollment['student_name']));
$initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Portal EAD — <?=ph($enrollment['student_name'])?></title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .student-container {
      max-width: 1320px;
      margin: 28px auto;
      padding: 0 24px;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .student-header-grid {
      display: grid;
      grid-template-columns: 340px 1fr;
      gap: 24px;
    }
    .profile-card {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: var(--shadow-md);
    }
    .profile-avatar-wrap {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .avatar-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--brand-gradient);
      color: #fff;
      font-weight: 800;
      font-size: 1.4rem;
      display: grid;
      place-items: center;
      box-shadow: var(--shadow-glow);
    }
    .profile-details {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .profile-meta-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
      border-top: 1px solid var(--border-light);
      padding-top: 14px;
      font-size: 0.85rem;
    }
    .meta-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .hero-course {
      background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(17, 24, 39, 0.98));
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 28px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      box-shadow: var(--shadow-md);
    }
    .academic-overview-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
    }
    .card-kpi {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 6px;
      position: relative;
      overflow: hidden;
    }
    .card-kpi::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 4px;
      height: 100%;
      background: var(--brand-primary);
    }
    .card-kpi.success::before { background: var(--success); }
    .card-kpi.warning::before { background: var(--warning); }
    .kpi-num {
      font-size: 1.85rem;
      font-weight: 800;
      color: #fff;
      line-height: 1;
    }
    .kpi-label {
      font-size: 0.825rem;
      color: var(--text-secondary);
      font-weight: 500;
    }
    .kpi-sub {
      font-size: 0.75rem;
      color: var(--text-muted);
    }
    .content-columns {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 24px;
    }
    .grades-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
      margin-top: 12px;
    }
    .grades-table th {
      padding: 10px 14px;
      background: var(--bg-surface);
      color: var(--text-muted);
      text-align: left;
      font-size: 0.75rem;
      text-transform: uppercase;
      border-bottom: 1px solid var(--border-light);
    }
    .grades-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--border-light);
      color: var(--text-secondary);
    }
    .grades-table td.strong {
      color: #fff;
      font-weight: 600;
    }
    @media (max-width: 1024px) {
      .student-header-grid, .content-columns {
        grid-template-columns: 1fr;
      }
      .academic-overview-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }
    @media (max-width: 600px) {
      .academic-overview-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <header class="student-navbar">
    <div class="brand-logo">🎓 Vitrine IA Pro <span style="font-size:0.85rem;color:var(--text-muted);font-weight:400;">| Portal EAD</span></div>
    <div style="display:flex;align-items:center;gap:16px;">
      <span class="pill ok">● Matrícula Ativa</span>
      <a href="aluno_login.php?logout=1" class="btn btn-secondary" style="padding:6px 14px;font-size:0.8rem;">Sair</a>
    </div>
  </header>

  <main class="student-container">
    <?php if(isset($_GET['locked'])):?>
      <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:16px 20px;border-radius:var(--radius-md);display:flex;align-items:center;gap:12px;font-size:0.9rem;">
        <span style="font-size:1.4rem;">🔒</span>
        <div>
          <strong style="color:#fff;">Aula Bloqueada:</strong> Esta aula só será liberada após você concluir 100% do vídeo e obter aprovação com pelo menos 70% de acerto no teste da aula anterior.
        </div>
      </div>
    <?php endif;?>

    <!-- Topo: Perfil do Aluno + Curso em Destaque -->
    <section class="student-header-grid">
      <!-- Card de Perfil do Aluno -->
      <div class="profile-card">
        <div class="profile-avatar-wrap">
          <div class="avatar-circle"><?=$initials?></div>
          <div class="profile-details">
            <h2 style="font-size:1.15rem;margin:0;color:#fff;"><?=ph($enrollment['student_name'])?></h2>
            <span style="font-size:0.8rem;color:var(--text-muted);"><?=ph($enrollment['email'])?></span>
          </div>
        </div>
        <div class="profile-meta-list">
          <div class="meta-row">
            <span style="color:var(--text-muted);">Matrícula ID:</span>
            <strong style="color:#fff;">#<?=$enrollmentId?></strong>
          </div>
          <div class="meta-row">
            <span style="color:var(--text-muted);">Telefone:</span>
            <span style="color:var(--text-secondary);"><?=ph($enrollment['phone'] ?: 'Não informado')?></span>
          </div>
          <div class="meta-row">
            <span style="color:var(--text-muted);">Turma:</span>
            <span style="color:var(--text-secondary);"><?=ph($enrollment['cohort_name'] ?: 'Turma Regular')?></span>
          </div>
          <div class="meta-row">
            <span style="color:var(--text-muted);">Financeiro:</span>
            <span class="pill ok"><?=ph(strtoupper($enrollment['payment_status'] ?: 'Regular'))?></span>
          </div>
        </div>
      </div>

      <!-- Banner do Curso -->
      <div class="hero-course">
        <div>
          <div style="display:flex;gap:8px;margin-bottom:10px;">
            <span class="pill">Modalidade <?=ph(strtoupper($modality))?></span>
            <span class="pill ok">Em Andamento</span>
          </div>
          <h1 style="font-size:1.75rem;margin-bottom:8px;color:#fff;"><?=ph($enrollment['course_title'])?></h1>
          <p style="font-size:0.9rem;max-width:680px;"><?=ph($enrollment['course_objective'])?></p>
        </div>

        <div style="margin-top:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
          <div style="flex:1;min-width:240px;max-width:420px;">
            <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:6px;">
              <span style="color:var(--text-secondary);">Progresso Geral do Curso</span>
              <strong style="color:#fff;font-size:1rem;"><?=$courseProgress?>%</strong>
            </div>
            <div class="progress-bar" style="height:10px;">
              <div class="progress-bar-fill" style="width:<?=$courseProgress?>%;"></div>
            </div>
          </div>
          <?php if($firstPendingLessonId):
            $btnLabel = $courseProgress > 0 ? 'Continuar de Onde Parou ▶' : 'Iniciar Curso ▶';
          ?>
            <a href="aula.php?id=<?=$firstPendingLessonId?>" class="btn" style="padding:12px 24px;font-size:0.95rem;font-weight:700;box-shadow:var(--shadow-glow);">
              <?=$btnLabel?>
            </a>
          <?php endif;?>
        </div>
      </div>
    </section>

    <!-- Painel de KPIs e Métricas Acadêmicas -->
    <section class="academic-overview-grid">
      <div class="card-kpi">
        <span class="kpi-num"><?=$done?> / <?=$total?></span>
        <span class="kpi-label">Aulas Concluídas</span>
        <span class="kpi-sub"><?=$total - $done?> aulas pendentes</span>
      </div>

      <div class="card-kpi">
        <span class="kpi-num"><?=fmtMinutes($watched)?></span>
        <span class="kpi-label">Tempo Dedicado</span>
        <span class="kpi-sub">Em aulas e materiais assistidos</span>
      </div>

      <div class="card-kpi <?=$finalGrade !== null && (float)$finalGrade >= 70 ? 'success' : ''?>">
        <span class="kpi-num"><?=$finalGrade === null ? '—' : number_format((float)$finalGrade, 1, ',', '.')?></span>
        <span class="kpi-label">Média Final / Nota</span>
        <span class="kpi-sub"><?=$finalGrade === null ? 'Sem avaliações finalizadas' : 'Nota ponderada oficial'?></span>
      </div>

      <div class="card-kpi <?=$academicState['eligible'] ? 'success' : 'warning'?>">
        <span class="kpi-num"><?=$academicState['eligible'] ? 'Apto' : 'Pendente'?></span>
        <span class="kpi-label">Elegibilidade Certificado</span>
        <span class="kpi-sub"><?=$academicState['eligible'] ? 'Critérios cumpridos' : 'Requisitos em aberto'?></span>
      </div>
    </section>

    <!-- Duas Colunas: Trilha de Aulas à esquerda + Quadro de Notas/Critérios à direita -->
    <div class="content-columns">
      <!-- Coluna Esquerda: Trilha Pedagógica EAD -->
      <section class="curriculum-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
          <h2 style="margin:0;">Trilha de Aulas e Módulos</h2>
          <span class="pill"><?=count($lessons)?> Aulas no total</span>
        </div>
        <p style="font-size:0.875rem;color:var(--text-secondary);">Acompanhe e acesse individualmente cada aula da formação.</p>

        <?php foreach($modules as $module):
          $modCover = getModuleCoverImage($module['title'], $module['cover_image'] ?? null);
          $modTotal = count($module['lessons']);
          $modDone = count(array_filter($module['lessons'], fn($l) => (float)$l['percent_complete'] >= 100));
          $modPct = $modTotal > 0 ? round(($modDone / $modTotal) * 100) : 0;
        ?>
          <div class="module-group" style="margin-top:24px;border-radius:var(--radius-md);overflow:hidden;box-shadow:var(--shadow-md);border:1px solid var(--border-light);background:var(--bg-secondary);">
            <!-- Banner com Imagem de Capa do Módulo -->
            <div style="position:relative;height:140px;background:url('<?=ph($modCover)?>') center/cover no-repeat;display:flex;align-items:flex-end;padding:20px;overflow:hidden;">
              <div style="position:absolute;inset:0;background:linear-gradient(to top, rgba(11, 15, 25, 0.95) 10%, rgba(11, 15, 25, 0.4) 60%, rgba(0,0,0,0.2) 100%);"></div>
              <div style="position:relative;z-index:2;display:flex;justify-content:space-between;align-items:flex-end;width:100%;flex-wrap:wrap;gap:12px;">
                <div>
                  <div style="margin-bottom:6px;">
                    <span class="pill" style="background:rgba(0,0,0,0.6);color:#fff;border-color:rgba(255,255,255,0.2);">
                      MÓDULO <?=$module['position']?>
                    </span>
                    <?php if($modPct >= 100):?>
                      <span class="pill ok">✓ 100% Concluído</span>
                    <?php elseif($modPct > 0):?>
                      <span class="pill warn"><?=$modPct?>% em andamento</span>
                    <?php endif;?>
                  </div>
                  <h3 style="font-size:1.25rem;color:#fff;margin:0;text-shadow:0 2px 4px rgba(0,0,0,0.8);">
                    <?=ph($module['title'])?>
                  </h3>
                </div>
                <div style="text-align:right;background:rgba(11,15,25,0.7);padding:6px 12px;border-radius:var(--radius-sm);border:1px solid rgba(255,255,255,0.1);">
                  <span style="font-size:0.8rem;color:var(--text-secondary);"><?=$modDone?> de <?=$modTotal?> aulas concluídas</span>
                </div>
              </div>
            </div>

            <!-- Lista de Aulas do Módulo -->
            <div style="background:var(--bg-surface);">
              <?php foreach($module['lessons'] as $lesson):
                $pct = (float)$lesson['percent_complete'];
                $isCompleted = $pct >= 100;
                $isLocked = !empty($lesson['is_locked']);
              ?>
                <div class="lesson-item <?=$isLocked ? 'locked-item' : ''?>" style="<?=$isLocked ? 'opacity:0.65;background:rgba(0,0,0,0.15);' : ''?>">
                  <div class="lesson-info">
                    <div style="display:flex;align-items:center;gap:10px;">
                      <span style="width:24px;height:24px;border-radius:50%;background:<?=$isCompleted ? 'var(--success-bg)' : ($isLocked ? 'rgba(239,68,68,0.15)' : 'var(--bg-hover)')?>;color:<?=$isCompleted ? 'var(--success)' : ($isLocked ? '#f87171' : 'var(--text-secondary)')?>;display:grid;place-items:center;font-size:0.75rem;font-weight:700;border:1px solid <?=$isCompleted ? 'rgba(16,185,129,0.3)' : ($isLocked ? 'rgba(239,68,68,0.3)' : 'var(--border-light)')?>;">
                        <?=$isCompleted ? '✔' : ($isLocked ? '🔒' : $lesson['lesson_position'])?>
                      </span>
                      <span class="lesson-title" style="<?=$isLocked ? 'color:var(--text-muted);' : ''?>">
                        <?=ph($lesson['lesson_title'])?>
                      </span>
                    </div>
                    <span style="font-size:0.8rem;color:var(--text-muted);margin-left:34px;">
                      <?php if($isLocked):?>
                        🔒 Requer 100% de vídeo e nota mínima de 70% no teste da aula anterior.
                      <?php else:?>
                        <?=ph($lesson['objective'])?>
                      <?php endif;?>
                    </span>
                  </div>
                  <div style="display:flex;align-items:center;gap:12px;">
                    <?php if($isLocked):?>
                      <span class="pill" style="background:rgba(239,68,68,0.1);color:#fca5a5;border-color:rgba(239,68,68,0.3);font-size:0.75rem;">
                        🔒 Bloqueada
                      </span>
                      <button disabled class="btn btn-secondary" style="padding:8px 16px;font-size:0.8rem;opacity:0.4;cursor:not-allowed;">
                        Bloqueada
                      </button>
                    <?php else:?>
                      <span class="pill <?=$isCompleted ? 'ok' : ($pct > 0 ? 'warn' : '')?>">
                        <?=$isCompleted ? 'Concluída' : ($pct > 0 ? round($pct).'% assistida' : 'Disponível')?>
                      </span>
                      <a href="aula.php?id=<?=$lesson['lesson_id']?>" class="btn <?=$isCompleted ? 'btn-secondary' : ''?>" style="padding:8px 16px;font-size:0.8rem;">
                        <?=$isCompleted ? 'Rever Aula' : ($pct > 0 ? 'Continuar' : 'Acessar')?>
                      </a>
                    <?php endif;?>
                  </div>
                </div>
              <?php endforeach;?>
            </div>
          </div>
        <?php endforeach;?>
      </section>

      <!-- Coluna Direita: Boletim de Notas e Critérios -->
      <div style="display:flex;flex-direction:column;gap:24px;">
        <!-- Boletim de Notas -->
        <div class="card">
          <h3 style="font-size:1.1rem;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
            📊 Desempenho & Avaliações
          </h3>
          <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:12px;">Notas parciais e resultado das provas registradas no curso.</p>

          <?php if(!empty($assessments)):?>
            <table class="grades-table">
              <thead>
                <tr>
                  <th>Avaliação</th>
                  <th>Nota</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($assessments as $ass):
                  $hasScore = $ass['score'] !== null;
                ?>
                  <tr>
                    <td class="strong"><?=ph($ass['title'])?></td>
                    <td><?=$hasScore ? number_format((float)$ass['score'], 1, ',', '.') : '—'?> / <?=number_format((float)$ass['max_score'], 0)?></td>
                    <td>
                      <span class="pill <?=$hasScore ? 'ok' : 'warn'?>" style="font-size:0.65rem;">
                        <?=$hasScore ? 'Avaliado' : 'Pendente'?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach;?>
              </tbody>
            </table>
          <?php else:?>
            <div style="background:var(--bg-surface);padding:14px;border-radius:var(--radius-sm);border:1px solid var(--border-light);font-size:0.85rem;color:var(--text-secondary);text-align:center;">
              Nenhuma avaliação formal cadastrada neste curso até o momento.
            </div>
          <?php endif;?>
        </div>

        <!-- Quadro de Conclusão e Certificação -->
        <div class="card">
          <h3 style="font-size:1.1rem;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
            📜 Status do Certificado
          </h3>
          <?php if($certificate && $certificate['status']==='emitido'):?>
            <div style="background:rgba(16, 185, 129, 0.1);border:1px solid rgba(16, 185, 129, 0.3);border-radius:var(--radius-sm);padding:16px;margin:12px 0;">
              <span class="pill ok" style="margin-bottom:8px;">✓ Certificado Emitido</span>
              <p style="font-size:0.85rem;color:#fff;margin:0 0 12px 0;">Código de validação:<br><strong style="font-family:monospace;letter-spacing:0.05em;color:#a7f3d0;"><?=ph($certificate['certificate_code'])?></strong></p>
              <a class="btn btn-success" href="certificado.php?hash=<?=rawurlencode((string)$certificate['validation_hash'])?>" target="_blank" style="width:100%;font-size:0.85rem;">
                Imprimir Certificado
              </a>
            </div>
          <?php else:?>
            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:12px;">
              Conclua todas as aulas da trilha e atinja os critérios mínimos de frequência e nota para liberação automática do documento.
            </p>
            <?php if(!empty($academicState['pending'])):?>
              <div style="background:var(--bg-surface);border:1px solid var(--border-light);border-radius:var(--radius-sm);padding:12px;font-size:0.8rem;">
                <strong style="color:var(--warning);">Pendências para aprovação:</strong>
                <ul style="padding-left:18px;margin-top:6px;color:var(--text-muted);">
                  <?php foreach($academicState['pending'] as $p):?>
                    <li><?=ph($p)?></li>
                  <?php endforeach;?>
                </ul>
              </div>
            <?php endif;?>
          <?php endif;?>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
