<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/video_model.php';

function vpDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia', getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function vph(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
function vpm(float $v): string { return number_format($v,2,',','.').' min'; }

$pdo=vpDb();ensureVideoModel($pdo);
$courseId=(int)($_GET['course']??$_POST['course_id']??0);
if($courseId<1){http_response_code(400);echo 'Curso inválido.';exit;}
$stmt=$pdo->prepare('SELECT * FROM courses WHERE id=?');$stmt->execute([$courseId]);$course=$stmt->fetch();
if(!$course){http_response_code(404);echo 'Curso não encontrado.';exit;}
$pdo->prepare("INSERT IGNORE INTO course_video_settings(course_id,provider,account_mode) VALUES(?, 'heygen','vitrine_managed')")->execute([$courseId]);

$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=(string)($_POST['action']??'');
        if($action==='save_settings'){
            $quota=max(0,(float)($_POST['quota_minutes']??0));
            $mode=(string)($_POST['account_mode']??'vitrine_managed');
            if(!in_array($mode,['vitrine_managed','client_managed'],true))$mode='vitrine_managed';
            $visual=(int)($_POST['visual_approved']??0)===1?1:0;
            $stmt=$pdo->prepare("UPDATE course_video_settings SET account_mode=?,quota_minutes=?,visual_approved=?,visual_approved_at=CASE WHEN ?=1 THEN COALESCE(visual_approved_at,NOW()) ELSE NULL END WHERE course_id=?");
            $stmt->execute([$mode,$quota,$visual,$visual,$courseId]);
            $message='Configuração de vídeo salva. Nenhum crédito foi consumido.';
        } elseif($action==='sync_queue'){
            $state=videoCourseState($pdo,$courseId);
            if(!$state['pedagogical_ok']) throw new RuntimeException('Todas as aulas precisam estar homologadas pedagogicamente antes da fila de vídeo.');
            if(!$state['package_ok']) throw new RuntimeException('O pacote didático precisa estar completo antes da fila de vídeo.');
            $count=videoSyncQueue($pdo,$courseId);
            $message=$count.' aula(s) homologada(s) sincronizada(s) na fila de vídeo.';
        } elseif($action==='prepare_queue'){
            $state=videoCourseState($pdo,$courseId);
            if(!$state['pedagogical_ok']||!$state['package_ok']||!$state['visual_approved']) throw new RuntimeException('Homologação pedagógica, pacote completo e aprovação visual específica são obrigatórios.');
            $stmt=$pdo->prepare("SELECT COALESCE(SUM(estimated_minutes),0) FROM lesson_video_jobs WHERE course_id=? AND status='pronto_para_fila'");$stmt->execute([$courseId]);$needed=(float)$stmt->fetchColumn();
            $available=max(0,$state['quota_minutes']-$state['used_minutes']);
            if($state['quota_minutes']>0 && $needed>$available) throw new RuntimeException('Quota insuficiente. Necessário: '.vpm($needed).' · disponível: '.vpm($available).'.');
            $pdo->prepare("UPDATE lesson_video_jobs SET status='fila_preparada',queued_at=COALESCE(queued_at,NOW()) WHERE course_id=? AND status='pronto_para_fila'")->execute([$courseId]);
            $message='Fila preparada. O envio real ao HeyGen continua bloqueado nesta HML.';
        } elseif($action==='submit_real'){
            throw new RuntimeException('Envio real ao HeyGen está deliberadamente desabilitado nesta etapa da MVP para evitar consumo de créditos antes da homologação final.');
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$state=videoCourseState($pdo,$courseId);
$stmt=$pdo->prepare("SELECT j.*,l.title lesson_title,l.position lesson_position,m.title module_title,m.position module_position,l.review_status FROM lesson_video_jobs j INNER JOIN lessons l ON l.id=j.lesson_id INNER JOIN modules m ON m.id=l.module_id WHERE j.course_id=? ORDER BY m.position,l.position");
$stmt->execute([$courseId]);$jobs=$stmt->fetchAll();
$totalEstimated=array_sum(array_map(fn(array $j): float => (float)$j['estimated_minutes'],$jobs));
$available=max(0,$state['quota_minutes']-$state['used_minutes']);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pipeline de Vídeo — <?=vph($course['title'])?></title><link rel="stylesheet" href="assets/app.css"></head><body>
<div class="app-shell"><aside class="sidebar"><div class="brand">Cursos IA <small>Pipeline de vídeo</small></div><nav><div class="nav-title">Curso</div><a class="nav-link" href="index.php?course=<?=$courseId?>#estrutura"><span class="dot"></span>Arquitetura</a><a class="nav-link" href="factory.php?course=<?=$courseId?>"><span class="dot"></span>Materiais IA</a><a class="nav-link active" href="video_pipeline.php?course=<?=$courseId?>"><span class="dot"></span>Vídeo / HeyGen</a></nav></aside>
<div class="main"><header class="topbar"><strong>Pipeline de Vídeo · Cursos IA</strong><span class="env">HML · sem consumo real</span></header><main class="content">
<div class="page-title"><div><h1><?=vph($course['title'])?></h1><p>Preparação operacional das aulas para vídeo. O envio real ao provedor permanece bloqueado.</p></div><div class="actions"><a class="btn secondary" href="factory.php?course=<?=$courseId?>">Voltar à Fábrica</a></div></div>
<?php if($message):?><div class="flash"><?=vph($message)?></div><?php endif;?><?php if($error):?><div class="flash error"><?=vph($error)?></div><?php endif;?>
<div class="grid grid-6" style="margin-bottom:18px"><div class="metric"><span class="value"><?=$state['approved_lessons']?>/<?=$state['total_lessons']?></span><span class="label">Aulas homologadas</span><span class="hint"><?=$state['pedagogical_ok']?'OK':'pendente'?></span></div><div class="metric"><span class="value"><?=$state['assets_count']?>/6</span><span class="label">Pacote didático</span><span class="hint"><?=$state['package_ok']?'OK':'pendente'?></span></div><div class="metric"><span class="value"><?=$state['visual_approved']?'sim':'não'?></span><span class="label">Aprovação visual</span><span class="hint">Específica do curso</span></div><div class="metric"><span class="value"><?=vpm($totalEstimated)?></span><span class="label">Estimativa</span><span class="hint">145 palavras/min</span></div><div class="metric"><span class="value"><?=vpm($available)?></span><span class="label">Quota disponível</span><span class="hint">Configurada para o curso</span></div><div class="metric"><span class="value">OFF</span><span class="label">Envio real</span><span class="hint">Bloqueado na HML</span></div></div>
<div class="grid grid-2"><section class="card"><div class="section-title"><h2>Configuração</h2><span class="pill">HeyGen</span></div><form method="post"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="course_id" value="<?=$courseId?>"><label>Modelo de conta</label><select class="input" name="account_mode"><option value="vitrine_managed" <?=$state['account_mode']==='vitrine_managed'?'selected':''?>>Gerenciada pela Vitrine</option><option value="client_managed" <?=$state['account_mode']==='client_managed'?'selected':''?>>Conta/API do cliente</option></select><label>Quota do curso em minutos</label><input class="input" name="quota_minutes" type="number" min="0" step="0.1" value="<?=vph((string)$state['quota_minutes'])?>"><label style="display:flex;gap:8px;align-items:center;margin-top:12px"><input type="checkbox" name="visual_approved" value="1" <?=$state['visual_approved']?'checked':''?> style="width:auto"> Aprovação visual específica do curso concluída</label><div class="actions" style="margin-top:12px"><button class="btn">Salvar configuração</button></div></form></section>
<section class="card"><div class="section-title"><h2>Gates do pipeline</h2><span class="pill <?=($state['pedagogical_ok']&&$state['package_ok']&&$state['visual_approved'])?'ok':'warn'?>"><?=($state['pedagogical_ok']&&$state['package_ok']&&$state['visual_approved'])?'pronto':'bloqueado'?></span></div><p>1. Homologação pedagógica: <strong><?=$state['pedagogical_ok']?'OK':'pendente'?></strong></p><p>2. Pacote didático completo: <strong><?=$state['package_ok']?'OK':'pendente'?></strong></p><p>3. Aprovação visual específica: <strong><?=$state['visual_approved']?'OK':'pendente'?></strong></p><p>4. Quota: <strong><?=vpm($available)?></strong> disponível.</p><form method="post" style="display:inline"><input type="hidden" name="action" value="sync_queue"><input type="hidden" name="course_id" value="<?=$courseId?>"><button class="btn secondary">Sincronizar aulas homologadas</button></form> <form method="post" style="display:inline"><input type="hidden" name="action" value="prepare_queue"><input type="hidden" name="course_id" value="<?=$courseId?>"><button class="btn">Preparar fila</button></form></section></div>
<section class="card" style="margin-top:18px"><div class="section-title"><h2>Fila por aula</h2><span class="pill"><?=count($jobs)?> aula(s)</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Aula</th><th>Revisão</th><th>Estimativa</th><th>Provider</th><th>Status</th></tr></thead><tbody><?php foreach($jobs as $j):?><tr><td><strong>M<?=$j['module_position']?> · A<?=$j['lesson_position']?> — <?=vph($j['lesson_title'])?></strong><br><span class="muted"><?=vph($j['module_title'])?></span></td><td><span class="pill"><?=vph($j['review_status'])?></span></td><td><?=vpm((float)$j['estimated_minutes'])?></td><td><?=vph($j['provider'])?></td><td><span class="pill <?=in_array($j['status'],['fila_preparada','concluido'],true)?'ok':'warn'?>"><?=vph($j['status'])?></span></td></tr><?php endforeach;if(!$jobs):?><tr><td colspan="5" class="empty">A fila ainda não foi sincronizada.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card" style="margin-top:18px;border-color:#f0c7c7;background:#fff8f8"><div class="section-title"><h2>Envio real ao HeyGen</h2><span class="pill warn">DESABILITADO</span></div><p>Nenhuma chamada externa ou consumo de crédito é executado nesta etapa. A ativação será feita somente após homologação final, configuração segura de credenciais e teste controlado de uma única aula.</p><form method="post"><input type="hidden" name="action" value="submit_real"><input type="hidden" name="course_id" value="<?=$courseId?>"><button class="btn" type="submit" disabled>Enviar ao HeyGen</button></form></section>
</main></div></div></body></html>