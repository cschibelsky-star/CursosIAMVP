<?php
declare(strict_types=1);
require_once __DIR__ . '/cidades_inclusivas_model.php';

function ciDb(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

$pdo = ciDb();
$courseId = ensureCidadesInclusivas($pdo);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?'); $stmt->execute([$courseId]); $course = $stmt->fetch();
$stmt = $pdo->prepare('SELECT * FROM modules WHERE course_id=? ORDER BY position'); $stmt->execute([$courseId]); $modules = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT * FROM sources WHERE course_id=? AND source_type=? ORDER BY id'); $stmt->execute([$courseId, 'referencia_oficial']); $sources = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cidades Inclusivas — Curso Oficial</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px}.wrap{max-width:1120px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:20px;margin-bottom:18px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:10px 14px;font-weight:700;text-decoration:none}.btn.secondary{background:#eef2f8;color:#182a52}.pill{display:inline-block;font-size:12px;padding:6px 9px;border-radius:999px;background:#edf2ff;margin:0 6px 6px 0}.module,.source{padding:14px 0;border-top:1px solid #eef1f5}.module:first-child,.source:first-child{border-top:0}.muted{color:#667085;font-size:13px}.verified{color:#176b35;font-weight:700}.warn{background:#fff8e6;border:1px solid #f1dfaa;padding:12px 14px;border-radius:9px}
</style>
</head>
<body>
<div class="top"><strong>Cursos IA MVP · Cidades Inclusivas</strong></div>
<div class="wrap">
<p><a class="btn secondary" href="index.php?course=<?= $courseId ?>">← Abrir no Criador de Curso</a></p>
<div class="card">
<h1><?= h($course['title']) ?></h1>
<p><span class="pill">20 horas</span><span class="pill">5 módulos × 4h</span><span class="pill">Modelo oficial de homologação</span></p>
<p><strong>Público-alvo:</strong> <?= h($course['audience']) ?></p>
<p><strong>Objetivo:</strong> <?= h($course['objective']) ?></p>
</div>
<div class="card">
<h2>Estrutura do curso</h2>
<?php foreach ($modules as $module): ?><div class="module"><h3>Módulo <?= (int)$module['position'] ?> — <?= h($module['title']) ?></h3><p><?= h($module['objective']) ?></p><p class="muted">4 horas · fundamentos teóricos · caso aplicado · prática orientada</p></div><?php endforeach; ?>
</div>
<div class="card">
<h2>Base oficial verificada</h2>
<p class="muted">Estas referências já estão vinculadas ao motor editorial do curso.</p>
<?php foreach ($sources as $source): ?><div class="source"><strong><?= h($source['name']) ?></strong><p class="muted"><?= h($source['authority'] ?? '') ?> · <span class="verified">verificado em <?= h($source['verified_at'] ?? '') ?></span></p><p><?= h($source['content']) ?></p><?php if (!empty($source['source_url'])): ?><p><a class="btn secondary" href="<?= h($source['source_url']) ?>" target="_blank" rel="noopener">Abrir fonte oficial</a></p><?php endif; ?></div><?php endforeach; ?>
<div class="warn"><strong>Atenção ao PL 366/2024:</strong> ele permanece tratado como proposição legislativa em tramitação, nunca como lei vigente. A situação precisa ser revalidada antes da publicação definitiva do curso.</div>
</div>
</div>
</body>
</html>
