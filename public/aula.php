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
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ah2($lesson['title'])?> — Cursos IA</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 26px;display:flex;justify-content:space-between}.top a{color:#fff;text-decoration:none}.wrap{max-width:1050px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:16px;padding:20px;margin-bottom:16px}.video{background:#0e1527;border-radius:14px;min-height:420px;display:grid;place-items:center;overflow:hidden;color:#fff}.video video{width:100%;max-height:620px;background:#000}.placeholder{text-align:center;padding:40px}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#edf2ff;font-size:11px}.ok{background:#e8f7ed;color:#176b35}.muted{color:#667085}.progress{height:10px;background:#e9edf4;border-radius:999px;overflow:hidden}.progress span{display:block;height:100%;background:#182a52}pre{white-space:pre-wrap;font:inherit;line-height:1.65;margin:0}@media(max-width:760px){.wrap{padding:12px}.video{min-height:240px}}</style></head><body>
<div class="top"><strong><?=ah2($lesson['course_title'])?></strong><a href="aluno.php">← Voltar ao curso</a></div><main class="wrap">
<div class="card"><span class="pill">Módulo <?=$lesson['module_position']?> · Aula <?=$lesson['position']?></span><h1><?=ah2($lesson['title'])?></h1><p><?=ah2($lesson['objective'])?></p><strong>Progresso atual: <?=round((float)$lesson['percent_complete'])?>%</strong><div class="progress"><span id="progressBar" style="width:<?=min(100,(float)$lesson['percent_complete'])?>%"></span></div><p class="muted" id="progressText">Posição registrada: <?=round(((int)$lesson['last_position_seconds'])/60)?> min.</p></div>
<div class="card"><h2>Aula em vídeo</h2><div class="video"><?php if(!empty($lesson['video_url'])):?><video id="lessonVideo" controls preload="metadata" src="<?=ah2($lesson['video_url'])?>"></video><?php else:?><div class="placeholder"><h3>Vídeo ainda não publicado</h3><p>O roteiro e o conteúdo pedagógico já estão disponíveis. Quando o vídeo for conectado, o tempo assistido será registrado automaticamente.</p></div><?php endif;?></div></div>
<div class="card"><h2>Conteúdo da aula</h2><pre><?=ah2($lesson['script'])?></pre></div>
</main>
<script>
const csrf = <?=json_encode($csrf,JSON_UNESCAPED_SLASHES)?>;
const lessonId = <?=$lessonId?>;
const video = document.getElementById('lessonVideo');
let lastSent = 0;
async function reportProgress(completed=false){
  if(!video) return;
  const payload = new URLSearchParams();
  payload.set('csrf', csrf);
  payload.set('lesson_id', String(lessonId));
  payload.set('position_seconds', String(Math.floor(video.currentTime || 0)));
  payload.set('total_seconds', String(Math.floor(video.duration || 0)));
  payload.set('completed', completed ? '1' : '0');
  try {
    const response = await fetch('progress_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:payload.toString(),credentials:'same-origin'});
    if(!response.ok) return;
    const data = await response.json();
    if(data.ok){
      const bar=document.getElementById('progressBar');
      const txt=document.getElementById('progressText');
      bar.style.width=Math.min(100,data.percent_complete)+'%';
      txt.textContent='Progresso salvo: '+Math.round(data.percent_complete)+'% · '+Math.round(data.watched_seconds/60)+' min assistidos.';
    }
  } catch(e) {}
}
if(video){
  video.addEventListener('loadedmetadata',()=>{if(<?=$lesson['last_position_seconds']?> > 0 && <?=$lesson['last_position_seconds']?> < video.duration){video.currentTime=<?=$lesson['last_position_seconds']?>;}});
  video.addEventListener('timeupdate',()=>{if(video.currentTime-lastSent>=30){lastSent=video.currentTime;reportProgress(false);}});
  video.addEventListener('pause',()=>reportProgress(false));
  video.addEventListener('ended',()=>reportProgress(true));
  window.addEventListener('beforeunload',()=>{if(navigator.sendBeacon){const p=new URLSearchParams();p.set('csrf',csrf);p.set('lesson_id',String(lessonId));p.set('position_seconds',String(Math.floor(video.currentTime||0)));p.set('total_seconds',String(Math.floor(video.duration||0)));p.set('completed',video.ended?'1':'0');navigator.sendBeacon('progress_api.php',p);}});
}
</script></body></html>