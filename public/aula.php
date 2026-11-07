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

function getModuleCoverImage(string $title, ?string $customCover = null): string
{
    if(!empty($customCover)) return $customCover;
    $t = mb_strtolower($title, 'UTF-8');
    if(str_contains($t, 'política') || str_contains($t, 'inclusiv') || str_contains($t, 'pública') || str_contains($t, 'social')){
        return 'https://images.unsplash.com/photo-1541872703-74c5e44368f9?auto=format&fit=crop&w=400&q=80';
    } elseif(str_contains($t, 'ia') || str_contains($t, 'inteligência') || str_contains($t, 'dados') || str_contains($t, 'prompt')){
        return 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=400&q=80';
    } elseif(str_contains($t, 'fundamento') || str_contains($t, 'introdução') || str_contains($t, 'conceito')){
        return 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=400&q=80';
    } elseif(str_contains($t, 'automação') || str_contains($t, 'workflow') || str_contains($t, 'prática')){
        return 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=400&q=80';
    }
    return 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=400&q=80';
}

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

// Validação da Regra de Liberação Sequencial
$courseLessons = portalCourseLessons($pdo, $enrollmentId, (int)$lesson['course_id']);
$currentAccess = null;
foreach($courseLessons as $cl){
    if((int)$cl['lesson_id'] === $lessonId){
        $currentAccess = $cl;
        break;
    }
}
if($currentAccess && !empty($currentAccess['is_locked'])){
    header('Location: aluno.php?locked=1');
    exit;
}

$pdo->prepare("INSERT INTO lesson_progress(enrollment_id,lesson_id,status,started_at,last_seen_at) VALUES(?,?,'em_andamento',NOW(),NOW()) ON DUPLICATE KEY UPDATE status=CASE WHEN status='nao_iniciada' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW()")->execute([$enrollmentId,$lessonId]);
$pdo->prepare("UPDATE enrollments SET status=CASE WHEN status='matriculado' THEN 'em_andamento' ELSE status END,started_at=COALESCE(started_at,NOW()),last_seen_at=NOW() WHERE id=?")->execute([$enrollmentId]);

// Busca todas as aulas para navegação da trilha e cálculo de anterior/próxima
$allLessons=portalCourseLessons($pdo,$enrollmentId,(int)$lesson['course_id']);
$modules=[];
$prevLessonId = null;
$nextLessonId = null;
$foundCurrent = false;

foreach($allLessons as $idx => $lItem){
    $mId=(int)$lItem['module_id'];
    if(!isset($modules[$mId])) $modules[$mId]=['title'=>$lItem['module_title'],'position'=>$lItem['module_position'],'lessons'=>[]];
    $modules[$mId]['lessons'][]=$lItem;

    if((int)$lItem['lesson_id'] === $lessonId){
        $foundCurrent = true;
        if($idx > 0){
            $prevLessonId = (int)$allLessons[$idx - 1]['lesson_id'];
        }
        if(isset($allLessons[$idx + 1])){
            $nextLessonId = (int)$allLessons[$idx + 1]['lesson_id'];
        }
    }
}
$isCompleted = (float)$lesson['percent_complete'] >= 100;
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
      max-width: 1680px;
      margin: 0 auto;
    }
    .cinema-player {
      background: #000;
      border-radius: var(--radius-md);
      overflow: hidden;
      position: relative;
      box-shadow: 0 20px 40px rgba(0,0,0,0.6);
      aspect-ratio: 16 / 9;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .cinema-player video {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
    .player-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 16px 20px;
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: var(--radius-md);
      margin-top: 16px;
      flex-wrap: wrap;
      gap: 12px;
    }
    .tab-nav {
      display: flex;
      gap: 8px;
      border-bottom: 1px solid var(--border-light);
      margin-top: 20px;
      padding-bottom: 4px;
    }
    .tab-btn {
      background: transparent;
      border: none;
      padding: 10px 18px;
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--text-secondary);
      cursor: pointer;
      border-radius: var(--radius-sm);
      transition: all 0.2s ease;
    }
    .tab-btn:hover {
      color: #fff;
      background: var(--bg-hover);
    }
    .tab-btn.active {
      color: #fff;
      background: var(--brand-light);
      border-bottom: 2px solid var(--brand-primary);
    }
    .tab-pane {
      display: none;
      background: var(--bg-secondary);
      border: 1px solid var(--border-light);
      border-radius: 0 0 var(--radius-md) var(--radius-md);
      padding: 24px;
      border-top: none;
    }
    .tab-pane.active {
      display: block;
    }
    .script-text {
      color: #cbd5e1;
      font-size: 0.95rem;
      line-height: 1.75;
      white-space: pre-wrap;
      font-family: inherit;
    }
    .notes-textarea {
      width: 100%;
      height: 220px;
      background: var(--bg-surface);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-sm);
      padding: 14px;
      color: #fff;
      font-family: inherit;
      font-size: 0.9rem;
      resize: vertical;
      outline: none;
    }
    .notes-textarea:focus {
      border-color: var(--brand-primary);
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
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
      <a href="aluno.php" style="display:flex;align-items:center;">
        <img src="assets/logo.svg" alt="Cursos IA MVP" style="height:36px;">
      </a>
      <span style="color:var(--border-subtle);">|</span>
      <a href="aluno.php" class="btn btn-secondary" style="padding:6px 14px;font-size:0.8rem;">
        ← Painel de Aulas
      </a>
      <strong style="color:#fff;font-size:0.95rem;"><?=ah2($lesson['course_title'])?></strong>
    </div>
    <div style="display:flex;align-items:center;gap:12px;">
      <span class="pill" id="liveSaveBadge" style="display:none;background:var(--success-bg);color:var(--success);">
        ✓ Progresso Salvo
      </span>
      <span class="pill">Módulo <?=$lesson['module_position']?> · Aula <?=$lesson['position']?></span>
    </div>
  </header>

  <main class="player-content-area">
    <!-- Coluna Esquerda: Player de Vídeo e Abas de Conteúdo -->
    <div class="player-left">
      <!-- Player de Vídeo Cinema -->
      <div class="cinema-player">
        <?php if(!empty($lesson['video_url'])):?>
          <video id="lessonVideo" controls preload="metadata" src="<?=ah2($lesson['video_url'])?>"></video>
        <?php else:?>
          <div style="text-align:center;padding:40px 20px;color:var(--text-muted);">
            <div style="font-size:3rem;margin-bottom:12px;">🎬</div>
            <h3 style="color:#fff;margin-bottom:6px;">Área de Vídeo Aula</h3>
            <p style="font-size:0.85rem;max-width:440px;margin:auto;">
              O roteiro e as atividades práticas desta aula já estão disponíveis logo abaixo. Quando um vídeo for conectado, o progresso será sincronizado automaticamente.
            </p>
          </div>
        <?php endif;?>
      </div>

      <!-- Barra de Ações Rápidas da Aula -->
      <div class="player-toolbar">
        <div>
          <h1 style="font-size:1.35rem;margin:0 0 4px 0;color:#fff;"><?=ah2($lesson['title'])?></h1>
          <p style="margin:0;font-size:0.85rem;color:var(--text-muted);"><?=ah2($lesson['objective'])?></p>
        </div>

        <div style="display:flex;align-items:center;gap:10px;">
          <?php if($prevLessonId):?>
            <a href="aula.php?id=<?=$prevLessonId?>" class="btn btn-secondary" style="font-size:0.85rem;">
              ← Anterior
            </a>
          <?php endif;?>

          <button id="btnComplete" onclick="reportProgress(true)" class="btn <?=$isCompleted ? 'btn-secondary' : 'btn-success'?>" style="font-size:0.85rem;">
            <?=$isCompleted ? '✓ Aula Concluída' : 'Marcar como Concluída ✔'?>
          </button>

          <?php if($nextLessonId):?>
            <a href="aula.php?id=<?=$nextLessonId?>" class="btn" style="font-size:0.85rem;">
              Próxima Aula →
            </a>
          <?php endif;?>
        </div>
      </div>

      <!-- Abas: Roteiro, Anotações, Materiais -->
      <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab('tab-script')">📖 Roteiro Pedagógico</button>
        <button class="tab-btn" onclick="switchTab('tab-notes')">📝 Minhas Anotações</button>
        <button class="tab-btn" onclick="switchTab('tab-materials')">📂 Materiais Complementares</button>
      </div>

      <div id="tab-script" class="tab-pane active">
        <div class="script-text"><?=ah2($lesson['script'])?></div>
      </div>

      <div id="tab-notes" class="tab-pane">
        <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:10px;">
          Faça suas anotações pessoais durante a aula. Suas anotações ficam salvas no seu navegador.
        </p>
        <textarea id="studentNotes" class="notes-textarea" placeholder="Digite aqui suas observações sobre a aula..."></textarea>
      </div>

      <div id="tab-materials" class="tab-pane">
        <div style="background:var(--bg-surface);padding:20px;border-radius:var(--radius-sm);border:1px solid var(--border-light);">
          <h4 style="color:#fff;margin-bottom:8px;">Recursos Didáticos da Aula</h4>
          <p style="font-size:0.85rem;color:var(--text-secondary);margin:0;">
            Os materiais complementares (slides, PDFs e exercícios práticos gerados na Fábrica de Conteúdo com IA) estarão listados aqui para download direto.
          </p>
        </div>
      </div>
    </div>

    <!-- Coluna Direita: Trilha Lateral EAD de Módulos -->
    <div class="player-sidebar">
      <div class="course-curriculum">
        <div class="curriculum-header">
          <h3 style="margin:0;font-size:0.95rem;color:#fff;">Trilha do Curso</h3>
          <p style="margin:4px 0 0 0;font-size:0.8rem;color:var(--text-muted);">
            Selecione uma aula para navegar diretamente
          </p>
        </div>
        <div class="curriculum-body">
          <?php foreach($modules as $mod):
            $mCover = getModuleCoverImage($mod['title'], $mod['cover_image'] ?? null);
          ?>
            <div class="curriculum-module">
              <div class="curriculum-module-title" style="display:flex;align-items:center;gap:10px;padding:10px 14px;">
                <div style="width:32px;height:32px;border-radius:6px;background:url('<?=ph($mCover)?>') center/cover no-repeat;flex-shrink:0;border:1px solid rgba(255,255,255,0.1);"></div>
                <span style="font-size:0.85rem;line-height:1.3;">Módulo <?=$mod['position']?>: <?=ah2($mod['title'])?></span>
              </div>
              <div>
                <?php foreach($mod['lessons'] as $les):
                  $isCurrent = (int)$les['lesson_id'] === $lessonId;
                  $isDone = (float)$les['percent_complete'] >= 100;
                  $lesLocked = !empty($les['is_locked']);
                ?>
                  <?php if($lesLocked):?>
                    <div class="curriculum-lesson" style="opacity:0.45;cursor:not-allowed;">
                      <span class="lesson-title" style="color:var(--text-muted);">
                        🔒 Aula <?=$les['lesson_position']?>: <?=ah2($les['lesson_title'])?>
                      </span>
                      <span class="pill" style="font-size:0.6rem;background:rgba(239,68,68,0.1);color:#fca5a5;">Bloqueada</span>
                    </div>
                  <?php else:?>
                    <a href="aula.php?id=<?=$les['lesson_id']?>" class="curriculum-lesson <?=$isCurrent ? 'active' : ''?>">
                      <span class="lesson-title">
                        <?=$isDone ? '✔' : '▶'?> Aula <?=$les['lesson_position']?>: <?=ah2($les['lesson_title'])?>
                      </span>
                      <?php if($isDone):?>
                        <span class="pill ok" style="font-size:0.65rem;">Concluído</span>
                      <?php endif;?>
                    </a>
                  <?php endif;?>
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

    // Gerenciador de Abas
    function switchTab(tabId) {
      document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
      event.target.classList.add('active');
      const targetPane = document.getElementById(tabId);
      if(targetPane) targetPane.classList.add('active');
    }

    // Bloco de Notas Local do Aluno
    const notesKey = 'notes_lesson_' + lessonId;
    const notesInput = document.getElementById('studentNotes');
    if(notesInput) {
      notesInput.value = localStorage.getItem(notesKey) || '';
      notesInput.addEventListener('input', () => {
        localStorage.setItem(notesKey, notesInput.value);
      });
    }

    // Persistência de Progresso EAD
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
          const badge = document.getElementById('liveSaveBadge');
          const btnComp = document.getElementById('btnComplete');
          if(badge) {
            badge.style.display = 'inline-flex';
            setTimeout(() => { badge.style.display = 'none'; }, 2500);
          }
          if(completed && btnComp) {
            btnComp.textContent = '✓ Aula Concluída';
            btnComp.className = 'btn btn-secondary';
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
