<?php
declare(strict_types=1);
require_once __DIR__ . '/academic_model.php';

function cpDb(): PDO
{
    return new PDO(
        'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
        getenv('DB_USERNAME')?:'cursos_ia',
        getenv('DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function ch(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); }

$pdo=cpDb();
ensureAcademicModel($pdo);
$term=trim((string)($_GET['hash']??$_GET['code']??''));
if($term===''){http_response_code(400);echo 'Código de certificado não informado.';exit;}

$stmt=$pdo->prepare("SELECT cert.*,e.completed_at,s.name student_name,c.title course_title,ch.name cohort_name,o.name organization_name
    FROM certificates cert
    INNER JOIN enrollments e ON e.id=cert.enrollment_id
    INNER JOIN students s ON s.id=e.student_id
    INNER JOIN courses c ON c.id=e.course_id
    LEFT JOIN cohorts ch ON ch.id=e.cohort_id
    LEFT JOIN organizations o ON o.id=ch.organization_id
    WHERE (cert.validation_hash=? OR cert.certificate_code=?) AND cert.status='emitido'
    LIMIT 1");
$stmt->execute([$term,$term]);
$certificate=$stmt->fetch();
if(!$certificate){http_response_code(404);echo 'Certificado não encontrado.';exit;}
$validationQuery='validar_certificado.php?hash='.rawurlencode((string)$certificate['validation_hash']);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=ch(ucfirst((string)$certificate['certificate_type']))?> — <?=ch($certificate['student_name'])?></title>
<style>:root{font-family:Georgia,"Times New Roman",serif;color:#172033;background:#eef1f5}*{box-sizing:border-box}body{margin:0}.toolbar{max-width:1120px;margin:20px auto 0;padding:0 20px;display:flex;gap:10px;justify-content:flex-end}.btn{font-family:Inter,system-ui,sans-serif;background:#182a52;color:#fff;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;font-weight:700;cursor:pointer}.secondary{background:#fff;color:#182a52;border:1px solid #cfd6e4}.page{width:1120px;min-height:790px;margin:18px auto 40px;background:#fff;padding:58px;border:18px solid #172033;box-shadow:0 20px 60px rgba(16,24,40,.12)}.inner{border:2px solid #9da7b8;min-height:638px;padding:55px 70px;text-align:center}.brand{font-family:Inter,system-ui,sans-serif;font-weight:800;letter-spacing:.08em;text-transform:uppercase;font-size:15px}.title{font-size:52px;margin:48px 0 30px}.lead{font-size:22px;line-height:1.7}.name{font-size:38px;font-weight:700;margin:20px 0}.course{font-size:28px;font-weight:700}.meta{margin-top:38px;font-family:Inter,system-ui,sans-serif;font-size:13px;color:#475467;line-height:1.8}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;word-break:break-all}.seal{margin:34px auto 0;width:110px;height:110px;border-radius:50%;border:5px double #172033;display:flex;align-items:center;justify-content:center;font-family:Inter,system-ui,sans-serif;font-weight:800;font-size:12px;text-transform:uppercase}@media(max-width:1160px){.page{width:calc(100% - 24px);min-height:auto;padding:28px}.inner{min-height:auto;padding:36px 24px}.title{font-size:38px}.name{font-size:30px}.course{font-size:22px}}@media print{body{background:#fff}.toolbar{display:none}.page{width:auto;min-height:100vh;margin:0;box-shadow:none;page-break-after:avoid}}</style></head><body>
<div class="toolbar"><a class="btn secondary" href="<?=ch($validationQuery)?>">Validar documento</a><button class="btn" onclick="window.print()">Imprimir / Salvar como PDF</button></div>
<main class="page"><section class="inner"><div class="brand">Cursos IA</div><h1 class="title"><?=ch(ucfirst((string)$certificate['certificate_type']))?></h1><p class="lead">Certificamos que</p><div class="name"><?=ch($certificate['student_name'])?></div><p class="lead">concluiu o curso</p><div class="course"><?=ch($certificate['course_title'])?></div><?php if($certificate['cohort_name']):?><p class="lead">Turma <?=ch($certificate['cohort_name'])?></p><?php endif;?><?php if($certificate['organization_name']):?><p class="lead"><?=ch($certificate['organization_name'])?></p><?php endif;?><div class="seal">Documento<br>válido</div><div class="meta">Emitido em <?=ch((string)$certificate['issued_at'])?><br>Código: <span class="code"><?=ch($certificate['certificate_code'])?></span><br>Validação: <span class="code"><?=ch($certificate['validation_hash'])?></span></div></section></main>
</body></html>
