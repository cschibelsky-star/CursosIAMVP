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
$academicState=academicEligibilityState($pdo,$enrollmentId);

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

$modules=[];
foreach($lessons as $lesson){
    $key=(int)$lesson['module_id'];
    if(!isset($modules[$key]))$modules[$key]=['position'=>$lesson['module_position'],'title'=>$lesson['module_title'],'lessons'=>[]];
    $modules[$key]['lessons'][]=$lesson;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Painel do Aluno — <?=ph($enrollment['course_title'])?></title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .student-wrap {
      max-width: 1200px;
      margin: 32px auto;
      padding: 0 24px;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .hero-banner {
      background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95));
      border: 1px solid var(--border-light);
      border-radius: var(--radius-lg);
      padding: 32px;
      display: grid;
      grid-template-columns: 1fr 300px;
      gap: 32px;
      align-items: center;
      box-shadow: var(--shadow-lg);
    }
    .hero-banner h1 {
      font-size: 2rem;
      font-weight: 800;
      color: #fff;
      margin-bottom: 8px;
    }
    .stats-card-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
    }
    .stat-box {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .stat-box .val {
      font-size: 2rem;
      font-weight: 800;
      color: #fff;
      line-height: 1;
    }
    .stat-box .lbl {
      color: var(--text-secondary);
      font-size: 0.85rem;
      font-weight: 500;
    }
    .curriculum-card {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: 24px;
    }
    .module-group {
      margin-top: 20px;
      border: 1px solid var(--border-light);
      border-radius: var(--radius-sm);
      overflow: hidden;
      background: var(--bg-surface);
    }
    .module-header {
      padding: 14px 20px;
      background: rgba(255, 255, 255, 0.03);
      border-bottom: 1px solid var(--border-light);
      font-weight: 700;
      color: #fff;
    }
    .lesson-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-bottom: 1px solid var(--border-light);
      gap: 16px;
      transition: background 0.15s ease;
    }
    .lesson-item:last-child {
      border-bottom: none;
    }
    .lesson-item:hover {
      background: var(--bg-hover);
    }
    .lesson-info {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .lesson-title {
      font-weight: 600;
      color: #fff;
      font-size: 0.95rem;
    }
    @media (max-width: 860px) {
      .hero-banner {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <header class="student-navbar">
    <div class="brand-logo">🎓 Cursos IA MVP</div>
    <div style="display:flex;align-items:center;gap:16px;">
      <span style="font-weight:600;color:var(--text-secondary);font-size:0.9rem;">
        <?=ph($enrollment['student_name'])?>
      </span>
      <a href="aluno_login.php?logout=1" class="btn btn-secondary" style="padding:6px 14px;font-size:0.8rem;">Sair</a>
    </div>
  </header>

  <main class="student-wrap">
    <section class="hero-banner">
      <div>
        <div style="margin-bottom:12px;">
          <span class="pill ok">Modalidade <?=ph(strtoupper($modality))?></span>
          <span class="pill">Turma: <?=ph($enrollment['cohort_name'] ?: 'Turma Padrão')?></span>
        </div>
        <h1><?=ph($enrollment['course_title'])?></h1>
        <p><?=ph($enrollment['course_objective'])?></p>
      </div>

      <div style="background:rgba(0,0,0,0.3);padding:20px;border-radius:var(--radius-md);border:1px solid var(--border-light);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <span style="font-size:0.85rem;font-weight:600;color:var(--text-secondary);">Progresso Geral</span>
          <span style="font-size:1.1rem;font-weight:800;color:#fff;"><?=$courseProgress?>%</span>
        </div>
        <div class="progress-bar">
          <div class="progress-bar-fill" style="width:<?=$courseProgress?>%;"></div>
        </div>
        <p style="font-size:0.8rem;margin:8px 0 0 0;color:var(--text-muted);">
          <?=$done?> de <?=$total?> aulas concluídas
        </p>
      </div>
    </section>

    <!-- Cards de Métricas EAD -->
    <section class="stats-card-grid">
      <?php if($isOnline):?>
        <div class="stat-box">
          <span class="val"><?=$done?> / <?=$total?></span>
          <span class="lbl">Aulas Concluídas</span>
        </div>
        <div class="stat-box">
          <span class="val"><?=fmtMinutes($watched)?></span>
          <span class="lbl">Tempo de Estudo Assistido</span>
        </div>
      <?php endif;?>

      <?php if($isPresential):?>
        <div class="stat-box">
          <span class="val"><?=$presentSessions?> / <?=$totalSessions?></span>
          <span class="lbl">Encontros com Presença</span>
        </div>
        <div class="stat-box">
          <span class="val"><?=fmtPlannedMinutes($attendedMinutes)?></span>
          <span class="lbl">Carga Horária Presencial</span>
        </div>
      <?php endif;?>

      <div class="stat-box">
        <span class="val"><?=ph($certificate['status'] ?? 'Em curso')?></span>
        <span class="lbl">Status da Certificação</span>
      </div>
    </section>

    <!-- Trilha de Aulas do Curso -->
    <section class="curriculum-card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <h2>Trilha de Aprendizado</h2>
        <span class="pill"><?=count($lessons)?> Conteúdos Totais</span>
      </div>
      <p style="font-size:0.9rem;color:var(--text-secondary);">
        Acesse os módulos pedagógicos estruturados para o seu aprendizado.
      </p>

      <?php foreach($modules as $module):?>
        <div class="module-group">
          <div class="module-header">
            Módulo <?=$module['position']?>: <?=ph($module['title'])?>
          </div>
          <div>
            <?php foreach($module['lessons'] as $lesson): $pct=(float)$lesson['percent_complete'];?>
              <div class="lesson-item">
                <div class="lesson-info">
                  <span class="lesson-title">Aula <?=$lesson['lesson_position']?> — <?=ph($lesson['lesson_title'])?></span>
                  <span style="font-size:0.8rem;color:var(--text-muted);"><?=ph($lesson['objective'])?></span>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                  <?php if($isOnline):?>
                    <span class="pill <?=$pct>=100?'ok':($pct>0?'warn':'')?>">
                      <?=$pct>=100?'Concluída':($pct>0?round($pct).'% assistida':'Não iniciada')?>
                    </span>
                    <a href="aula.php?id=<?=$lesson['lesson_id']?>" class="btn <?=$pct>=100?'btn-secondary':''?>">
                      <?=$pct>0 && $pct<100 ? 'Continuar ▶' : 'Assistir Aula'?>
                    </a>
                  <?php endif;?>
                </div>
              </div>
            <?php endforeach;?>
          </div>
        </div>
      <?php endforeach;?>
    </section>

    <!-- Certificado -->
    <?php if($certificate && $certificate['status']==='emitido'):?>
      <section class="card" style="border-left:4px solid var(--success);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;">
        <div>
          <h2 style="color:var(--success);margin-bottom:4px;">🎉 Certificado Disponível!</h2>
          <p style="margin:0;">Seu certificado oficial de conclusão foi emitido com sucesso.</p>
        </div>
        <div style="display:flex;gap:10px;">
          <a class="btn btn-success" href="certificado.php?hash=<?=rawurlencode((string)$certificate['validation_hash'])?>" target="_blank">
            Visualizar / Imprimir Certificado
          </a>
          <a class="btn btn-secondary" href="validar_certificado.php?hash=<?=rawurlencode((string)$certificate['validation_hash'])?>" target="_blank">
            Validar Autenticidade
          </a>
        </div>
      </section>
    <?php endif;?>
  </main>
</body>
</html>
