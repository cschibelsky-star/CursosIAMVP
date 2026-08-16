<?php
declare(strict_types=1);
require_once __DIR__ . '/academic_model.php';
require_once __DIR__ . '/student_portal_model.php';

function padb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
}
function pah(?string $v): string { return htmlspecialchars($v ?? '',ENT_QUOTES,'UTF-8'); }
$pdo=padb();ensureAcademicModel($pdo);ensureStudentPortal($pdo);
$rows=$pdo->query("SELECT e.id,e.portal_access_code,e.last_portal_login_at,e.status,e.payment_status,s.name student_name,s.email,c.title course_title,ch.name cohort_name,o.name organization_name FROM enrollments e INNER JOIN students s ON s.id=e.student_id INNER JOIN courses c ON c.id=e.course_id LEFT JOIN cohorts ch ON ch.id=e.cohort_id LEFT JOIN organizations o ON o.id=ch.organization_id ORDER BY e.id DESC")->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acessos do Portal do Aluno</title>
<style>:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 26px;display:flex;justify-content:space-between}.top a{color:#fff}.wrap{max-width:1250px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:18px}.warn{background:#fff8e6;border:1px solid #f1dfaa;border-radius:10px;padding:12px;margin-bottom:16px}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:10px;border-bottom:1px solid #edf0f4}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:800;letter-spacing:.08em;background:#edf2ff;padding:7px 9px;border-radius:8px;display:inline-block}.muted{color:#667085;font-size:12px}.btn{display:inline-block;background:#182a52;color:#fff;border-radius:8px;padding:8px 11px;text-decoration:none;font-weight:700}@media(max-width:800px){.wrap{padding:12px}.card{overflow:auto}}</style></head><body>
<div class="top"><strong>Portal do Aluno · Controle de acessos</strong><a href="academic.php">← Controle Acadêmico</a></div><main class="wrap"><div class="warn"><strong>Homologação:</strong> os códigos abaixo servem para testar o Portal do Aluno. Em produção serão substituídos por autenticação individual segura, recuperação de senha e políticas de acesso.</div><div class="card"><table><thead><tr><th>Aluno</th><th>Curso / turma</th><th>Código HML</th><th>Último acesso</th><th>Situação</th><th>Portal</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><strong><?=pah($r['student_name'])?></strong><br><span class="muted"><?=pah($r['email'])?></span></td><td><?=pah($r['course_title'])?><br><span class="muted"><?=pah($r['cohort_name']?:'Individual')?><?= $r['organization_name']?' · '.pah($r['organization_name']):'' ?></span></td><td><span class="code"><?=pah($r['portal_access_code'])?></span></td><td><?=pah($r['last_portal_login_at']?:'Nunca acessou')?></td><td><?=pah($r['status'])?> · <?=pah($r['payment_status'])?></td><td><a class="btn" href="aluno_login.php">Abrir login</a></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="muted">Nenhuma matrícula cadastrada.</td></tr><?php endif;?></tbody></table></div></main></body></html>