<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function aulaDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function ah2(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }

$pdo=aulaDb();ensureAcademicModel($pdo);ensureStudentPortal($pdo);
$enrollmentId=(int)($_SESSION['student_enrollment_id']??0);
$csrf=(string)($_SESSION['student_csrf']??'');
if($enrollmentId<1||$csrf===''){header('Location: aluno_login.php');exit;}
$lessonId=(int)($_GET['id']??0);
$stmt=$pdo->prepare("SELECT e.course_id,e.status AS enrollment_status,c.title AS course_title,m.position AS module_position,m.title AS module_title,l.id,l.position,l.title,l.objective,l.script,l.video_url,l.estimated_minutes,
COALESCE(lp.status,'nao_iniciada') AS progress_status,COALESCE(lp.watched_seconds,0) AS watched_seconds,COALESCE(lp.total_seconds,0) AS total_seconds,COALESCE(lp.percent_complete,0) AS percent_complete,COALESCE(lp.last_position_seconds,0) AS last_position_seconds
FROM enrollments e INNER JOIN courses c ON c.id=e.course_id INNER JOIN modules m ON m.course_id=e.course_id INNER JOIN lessons l ON l.module_id=m.id LEFT JOIN lesson_progress lp ON lp.enrollment_id=e.id AND lp.lesson_id=l.id WHERE e.id=? AND l.id=? LIMIT 1");
$stmt->execute([$enrollmentId,$lessonId]);$lesson=$stmt->fetch();
if(!$lesson){http_response_code(404);echo 'Aula não encontrada para esta matrícula.';exit;}
$pdo->prepare("INSERT INTO lesson_progress(enrollment_id,lesson_id,status,started_at,last_seen_at) VALUES(?,?,'em_andamento',NOW(),NOW()) ON DUPLICATE KEY UPDATE status=CASE WHEN status='nao_iniciada' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW()")->execute([$enrollmentId,$lessonId]);
$pdo->prepare("UPDATE enrollments SET status=CASE WHEN status='matriculado' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW() WHERE id=?")->execute([$enrollmentId]);

// Busca todas as aulas do curso para montar a grade/trilha lateral do player EAD
$allLessons=portalCourseLessons($pdo,$enrollmentId,(int)$lesson['course_id']);
$modules=[];
foreach($allLessons as $lItem){
    $mId=(int)$lItem['module_id'];
    if(!isset($modules[$mId])) $modules[$mId]=['title'=>$lItem['module_title'],'position'=>$lItem['module_position'],'lessons'=>[]];
    $modules[$mId]['lessons'][]=$lItem;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=ah2($lesson['title'])?> — <?=ah2($lesson['course_title'])?></title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .player-header {
      height: 64px;
      background: var(--bg-secondary);
      border-bottom: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
    }
    .player-content-area {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 24px;
      padding: 24px;
      max-width: 1600px;
      margin: 0 auto;
    }
    .lesson-meta-card {
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      padding: 24px;
      margin-top: 20px;
    }
    .notes-tabs {
      border-bottom: 1px solid var(--border-light);
      display: flex;
      gap: 20px;
      margin-bottom: 20px;
      padding-bottom: 10px;
    }
    .notes-tab {
      color: var(--text-secondary);
      font-weight: 600;
      font-size: 0.95rem;
      cursor: pointer;
    }
    .notes-tab.active {
      color: var(--brand-primary);
      border-bottom: 2px solid var(--brand-primary);
      padding-bottom: 10px;
    }
    .lesson-script-body {
      color: #cbd5e1;
      font-size: 0.95rem;
      line-height: 1.7;
      white-space: pre-wrap;
      font-family: inherit;
      background: var(--bg-surface);
      padding: 20px;
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-light);
    }
    @media (max-width: 1024px) {
      .player-content-area {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <header class="player-header">
    <div style="display:flex;align-items:center;gap:16px;">
      <a href="aluno.php" class="btn btn-secondary" style="padding:6px 12px;font-size:0.8rem;">← Voltar ao Painel</a>
      <strong style="color:#fff;font-size:0.95rem;"><?=ah2($lesson['course_title'])?></strong>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <span class="pill" id="liveSaveBadge" style="display:none;background:var(--success-bg);color:var(--success);">✓ Progresso Salvo</span>
      <span class="pill">Módulo <?=$lesson['module_position']?> · Aula <?=$lesson['position']?></span>
    </div>
  </header>

  <main class="player-content-area">
    <!-- Coluna Principal (Vídeo + Roteiro) -->
    <div class="player-left">
      <div class="video-wrapper">
        <?php if(!empty($lesson['video_url'])):?>
          <video id="lessonVideo" controls preload="metadata" src="<?=ah2($lesson['video_url'])?>"></video>
        <?php else:?>
          <div class="video-placeholder">
            <div style="font-size:2.5rem;margin-bottom:12px;">🎬</div>
            <h3>Conteúdo Teórico / Vídeo em Produção</h3>
            <p>O roteiro e as atividades práticas desta aula já estão disponíveis abaixo.</p>
          </div>
        <?php endif;?>
      </div>

      <div class="lesson-meta-card">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:16px;">
          <div>
            <h1 style="font-size:1.5rem;margin-bottom:6px;"><?=ah2($lesson['title'])?></h1>
            <p style="margin:0;font-size:0.9rem;"><?=ah2($lesson['objective'])?></p>
          </div>
          <button id="btnComplete" onclick="reportProgress(true)" class="btn btn-success" style="font-size:0.85rem;">
            ✓ Concluir Aula
          </button>
        </div>

        <div style="margin:20px 0;">
          <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:6px;">
            <span style="color:var(--text-secondary);">Progresso na Aula</span>
            <span id="percentText" style="color:#fff;font-weight:700;"><?=round((float)$lesson['percent_complete'])?>%</span>
          </div>
          <div class="progress-bar">
            <div id="progressBar" class="progress-bar-fill" style="width:<?=min(100,(float)$lesson['percent_complete'])?>%;"></div>
          </div>
        </div>

        <div class="notes-tabs">
          <div class="notes-tab active">📖 Roteiro Pedagógico & Material</div>
        </div>
        <div class="lesson-script-body"><?=ah2($lesson['script'])?></div>
      </div>
    </div>

    <!-- Coluna Lateral (Trilha EAD) -->
    <div class="player-sidebar">
      <div class="course-curriculum">
        <div class="curriculum-header">
          <h3 style="margin:0;font-size:0.95rem;color:#fff;">Conteúdo do Curso</h3>
          <p style="margin:4px 0 0 0;font-size:0.8rem;color:var(--text-muted);">Selecione uma aula para navegar</p>
        </div>
        <div class="curriculum-body">
          <?php foreach($modules as $mod):?>
            <div class="curriculum-module">
              <div class="curriculum-module-title">
                Módulo <?=$mod['position']?>: <?=ah2($mod['title'])?>
              </div>
              <div>
                <?php foreach($mod['lessons'] as $les):
                  $isCurrent = (int)$les['lesson_id'] === $lessonId;
                  $isDone = (float)$les['percent_complete'] >= 100;
                ?>
                  <a href="aula.php?id=<?=$les['lesson_id']?>" class="curriculum-lesson <?=$isCurrent ? 'active' : ''?>">
                    <span class="lesson-title">
                      <?=$isDone ? '✓' : '▶'?> Aula <?=$les['lesson_position']?>: <?=ah2($les['lesson_title'])?>
                    </span>
                    <?php if($isDone):?>
                      <span class="pill ok" style="font-size:0.65rem;">Feito</span>
                    <?php endif;?>
                  </a>
                <?php endforeach;?>
              </div>
            </div>
          <?php endforeach;?>
        </div>
      </div>
    </div>
  </main>

  <script>
    const csrf = <?=json_encode($csrf,JSON_UNESCAPED_SLASHES)?>;
    const lessonId = <?=$lessonId?>;
    const video = document.getElementById('lessonVideo');
    let lastSent = 0;

    async function reportProgress(completed=false){
      const pos = video ? Math.floor(video.currentTime || 0) : 0;
      const tot = video ? Math.floor(video.duration || 0) : 0;
      const payload = new URLSearchParams();
      payload.set('csrf', csrf);
      payload.set('lesson_id', String(lessonId));
      payload.set('position_seconds', String(pos));
      payload.set('total_seconds', String(tot));
      payload.set('completed', completed ? '1' : '0');

      try {
        const response = await fetch('progress_api.php', {
          method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:payload.toString(),
          credentials:'same-origin'
        });
        if(!response.ok) return;
        const data = await response.json();
        if(data.ok){
          const bar = document.getElementById('progressBar');
          const pTxt = document.getElementById('percentText');
          const badge = document.getElementById('liveSaveBadge');
          if(bar) bar.style.width = Math.min(100, data.percent_complete) + '%';
          if(pTxt) pTxt.textContent = Math.round(data.percent_complete) + '%';
          if(badge) {
            badge.style.display = 'inline-flex';
            setTimeout(() => { badge.style.display = 'none'; }, 2500);
          }
          if(completed) {
            alert('Parabéns! Aula concluída com sucesso.');
          }
        }
      } catch(e) {}
    }

    if(video){
      video.addEventListener('loadedmetadata', () => {
        if(<?=$lesson['last_position_seconds']?> > 0 && <?=$lesson['last_position_seconds']?> < video.duration){
          video.currentTime = <?=$lesson['last_position_seconds']?>;
        }
      });
      video.addEventListener('timeupdate', () => {
        if(video.currentTime - lastSent >= 25){
          lastSent = video.currentTime;
          reportProgress(false);
        }
      });
      video.addEventListener('pause', () => reportProgress(false));
      video.addEventListener('ended', () => reportProgress(true));
      window.addEventListener('beforeunload', () => {
        if(navigator.sendBeacon){
          const p = new URLSearchParams();
          p.set('csrf', csrf);
          p.set('lesson_id', String(lessonId));
          p.set('position_seconds', String(Math.floor(video.currentTime || 0)));
          p.set('total_seconds', String(Math.floor(video.duration || 0)));
          p.set('completed', video.ended ? '1' : '0');
          navigator.sendBeacon('progress_api.php', p);
        }
      });
    }
  </script>
</body>
</html>
