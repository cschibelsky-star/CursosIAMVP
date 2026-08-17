<?php
declare(strict_types=1);
require_once __DIR__ . '/academic_model.php';

function vcDb(): PDO
{
    return new PDO(
        'mysql:host='.(getenv('DB_HOST')?:'db').';port='.(getenv('DB_PORT')?:'3306').';dbname='.(getenv('DB_DATABASE')?:'cursos_ia_mvp').';charset=utf8mb4',
        getenv('DB_USERNAME')?:'cursos_ia',
        getenv('DB_PASSWORD')?:'',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function vh(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); }

$pdo=vcDb();
ensureAcademicModel($pdo);
$term=trim((string)($_GET['q']??$_GET['hash']??$_GET['code']??''));
$certificate=null;
$searched=$term!=='';

if($searched){
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
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Validar certificado — Cursos IA</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.wrap{max-width:760px;margin:50px auto;padding:20px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:16px;padding:24px;margin-bottom:16px}.brand{font-weight:800;font-size:24px}.muted{color:#667085}.ok{border-color:#b7dfc5;background:#f4fbf6}.error{border-color:#efc1c1;background:#fff7f7}.pill{display:inline-block;padding:6px 10px;border-radius:999px;background:#e8f7ed;color:#176b35;font-weight:700;font-size:12px}.row{padding:9px 0;border-bottom:1px solid #eef1f5}.row:last-child{border-bottom:0}input{width:100%;padding:12px;border:1px solid #cfd6e4;border-radius:9px;margin:10px 0}.btn{background:#182a52;color:#fff;border:0;border-radius:9px;padding:11px 16px;font-weight:700;cursor:pointer}</style></head><body><div class="wrap">
<div class="card"><div class="brand">Cursos IA · Validação de certificado</div><p class="muted">Informe o código do certificado ou o hash de validação.</p><form method="get"><input name="q" value="<?=vh($term)?>" placeholder="Ex.: CURSO-... ou hash de validação" required><button class="btn" type="submit">Validar</button></form></div>
<?php if($searched && $certificate):?><div class="card ok"><span class="pill">Documento válido</span><h2><?=vh(ucfirst((string)$certificate['certificate_type']))?> emitido</h2><div class="row"><strong>Aluno:</strong> <?=vh($certificate['student_name'])?></div><div class="row"><strong>Curso:</strong> <?=vh($certificate['course_title'])?></div><?php if($certificate['cohort_name']):?><div class="row"><strong>Turma:</strong> <?=vh($certificate['cohort_name'])?></div><?php endif;?><?php if($certificate['organization_name']):?><div class="row"><strong>Instituição:</strong> <?=vh($certificate['organization_name'])?></div><?php endif;?><div class="row"><strong>Código:</strong> <?=vh($certificate['certificate_code'])?></div><div class="row"><strong>Emitido em:</strong> <?=vh((string)$certificate['issued_at'])?></div><div class="row"><strong>Status:</strong> <?=vh((string)$certificate['status'])?></div></div>
<?php elseif($searched):?><div class="card error"><h2>Documento não localizado</h2><p>Não foi encontrado certificado emitido com o código ou hash informado.</p></div><?php endif;?>
</div></body></html>
