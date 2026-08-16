<?php
declare(strict_types=1);
session_start();

function factoryDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . (getenv('DB_DATABASE') ?: 'cursos_ia_mvp') . ';charset=utf8mb4',
        getenv('DB_USERNAME') ?: 'cursos_ia',
        getenv('DB_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function factoryMigrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_assets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        course_id INT UNSIGNED NOT NULL,
        asset_type VARCHAR(40) NOT NULL,
        title VARCHAR(180) NOT NULL,
        content LONGTEXT NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'rascunho',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_course_asset(course_id, asset_type),
        CONSTRAINT fk_assets_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function buildCourseOutline(PDO $pdo, int $courseId): string
{
    $stmt = $pdo->prepare('SELECT m.position AS module_position, m.title AS module_title, l.position AS lesson_position, l.title AS lesson_title, l.objective, l.script FROM modules m LEFT JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? ORDER BY m.position,l.position');
    $stmt->execute([$courseId]);
    $rows = $stmt->fetchAll();

    $lines = [];
    $lastModule = null;
    foreach ($rows as $row) {
        $moduleKey = (string)$row['module_position'];
        if ($moduleKey !== $lastModule) {
            $lines[] = 'Módulo ' . $row['module_position'] . ' — ' . $row['module_title'];
            $lastModule = $moduleKey;
        }
        if ($row['lesson_position'] !== null) {
            $lines[] = '  Aula ' . $row['lesson_position'] . ' — ' . $row['lesson_title'];
        }
    }

    return implode("\n", $lines);
}

function firstLessonApproved(PDO $pdo, int $courseId): bool
{
    $stmt = $pdo->prepare('SELECT l.review_status FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? ORDER BY m.position,l.position LIMIT 1');
    $stmt->execute([$courseId]);
    return $stmt->fetchColumn() === 'aprovada';
}

function generateAssetContent(array $course, string $outline, string $type): array
{
    $title = (string)$course['title'];
    $audience = (string)$course['audience'];
    $objective = (string)$course['objective'];

    return match ($type) {
        'slides' => [
            'Slides do curso',
            "SLIDES — {$title}\n\nPúblico-alvo: {$audience}\nObjetivo: {$objective}\n\nEstrutura sugerida:\n{$outline}\n\nModelo de cada aula:\n1. Abertura e objetivo\n2. Conceito central\n3. Exemplo prático\n4. Passo a passo\n5. Erros e cuidados\n6. Resumo\n7. Próxima ação do aluno\n\n[Base v0.3 — conteúdo final dos slides será refinado pelo motor de IA.]",
        ],
        'apostila' => [
            'Apostila do curso',
            "APOSTILA — {$title}\n\nApresentação\nEste material acompanha o curso destinado a {$audience}.\n\nObjetivo geral\n{$objective}\n\nSumário\n{$outline}\n\nEstrutura editorial por aula:\n• Contexto\n• Conceitos essenciais\n• Explicação detalhada\n• Exemplo aplicado\n• Checklist de execução\n• Resumo da aula\n• Espaço para anotações\n\n[Base v0.3 — pronta para expansão e diagramação.]",
        ],
        'exercicios' => [
            'Exercícios e atividades',
            "EXERCÍCIOS — {$title}\n\nObjetivo: transformar o conteúdo em aplicação prática para {$audience}.\n\nPara cada aula, gerar:\n1. Uma pergunta de compreensão\n2. Um exercício aplicado\n3. Um checklist de execução\n4. Uma reflexão sobre erro comum\n5. Uma tarefa prática relacionada ao objetivo do curso\n\nEstrutura do curso:\n{$outline}\n\n[Base v0.3 — banco inicial de atividades.]",
        ],
        'avaliacao' => [
            'Avaliação do curso',
            "AVALIAÇÃO — {$title}\n\nCritério de homologação:\n• questões alinhadas ao objetivo do curso;\n• cobertura dos módulos;\n• respostas justificadas;\n• resultado registrável por aluno.\n\nEstrutura avaliada:\n{$outline}\n\nModelo inicial:\n- 10 questões objetivas\n- 2 situações práticas\n- 1 atividade final de aplicação\n\n[Base v0.3 — questões específicas serão produzidas a partir do conteúdo homologado.]",
        ],
        'pagina_venda' => [
            'Página de apresentação e venda',
            "PÁGINA DO CURSO — {$title}\n\nPara quem é\n{$audience}\n\nO que o aluno aprenderá\n{$objective}\n\nConteúdo programático\n{$outline}\n\nBlocos previstos:\n• chamada principal\n• problema que o curso resolve\n• transformação esperada\n• módulos e aulas\n• para quem é / para quem não é\n• materiais incluídos\n• certificação\n• chamada para matrícula\n\n[Base v0.3 — copy comercial será refinada após homologação pedagógica.]",
        ],
        'certificado' => [
            'Modelo de certificado',
            "CERTIFICADO — {$title}\n\nCertificamos que [NOME DO ALUNO] concluiu o curso “{$title}”, destinado a {$audience}, cumprindo os critérios de conclusão definidos pela plataforma.\n\nConteúdo programático:\n{$outline}\n\nCampos obrigatórios:\n• nome do aluno\n• nome do curso\n• carga horária\n• data de conclusão\n• código de validação\n• responsável/emissor\n\n[Base v0.3 — geração PDF e validação pública entram na etapa de entrega ao aluno.]",
        ],
        default => throw new RuntimeException('Tipo de material não reconhecido.'),
    };
}

$pdo = factoryDb();
factoryMigrate($pdo);
$courseId = (int)($_GET['course'] ?? $_POST['course_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM courses WHERE id=?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    echo 'Curso não encontrado.';
    exit;
}

$approved = firstLessonApproved($pdo, $courseId);
$outline = buildCourseOutline($pdo, $courseId);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$approved) {
            throw new RuntimeException('A primeira aula precisa estar aprovada antes de gerar o pacote completo do curso.');
        }

        $types = ['slides', 'apostila', 'exercicios', 'avaliacao', 'pagina_venda', 'certificado'];
        $requested = (string)($_POST['asset_type'] ?? 'all');
        $selected = $requested === 'all' ? $types : [$requested];

        foreach ($selected as $type) {
            if (!in_array($type, $types, true)) {
                throw new RuntimeException('Material inválido.');
            }
            [$assetTitle, $content] = generateAssetContent($course, $outline, $type);
            $stmt = $pdo->prepare("INSERT INTO course_assets(course_id,asset_type,title,content,status) VALUES(?,?,?,?, 'gerado') ON DUPLICATE KEY UPDATE title=VALUES(title),content=VALUES(content),status='gerado',updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$courseId, $type, $assetTitle, $content]);
        }

        $pdo->prepare("UPDATE courses SET status='pacote_didatico_gerado' WHERE id=?")->execute([$courseId]);
        $message = $requested === 'all' ? 'Pacote didático completo gerado.' : 'Material gerado com sucesso.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare('SELECT * FROM course_assets WHERE course_id=? ORDER BY id');
$stmt->execute([$courseId]);
$assets = $stmt->fetchAll();
$assetsByType = [];
foreach ($assets as $asset) {
    $assetsByType[$asset['asset_type']] = $asset;
}

$catalog = [
    'slides' => 'Slides',
    'apostila' => 'Apostila',
    'exercicios' => 'Exercícios',
    'avaliacao' => 'Avaliação',
    'pagina_venda' => 'Página de venda',
    'certificado' => 'Certificado',
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cursos IA MVP — Fábrica do Curso</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f4f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#111a2e;color:#fff;padding:18px 28px;display:flex;justify-content:space-between;align-items:center}.wrap{max-width:1180px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid #e2e7f0;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 3px 14px rgba(16,24,40,.04)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.btn{display:inline-block;background:#182a52;color:#fff;border:0;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.btn.secondary{background:#eef2f8;color:#182a52}.btn:disabled{opacity:.45;cursor:not-allowed}.pill{display:inline-block;font-size:12px;padding:7px 10px;border-radius:999px;background:#edf0f5}.pill.ok{background:#e8f7ed;color:#176b35}.muted{color:#667085}.message{padding:12px 14px;border-radius:9px;background:#eaf7ee;color:#185f34;margin-bottom:16px}.error{padding:12px 14px;border-radius:9px;background:#fff0f0;color:#9b1c1c;margin-bottom:16px}pre{white-space:pre-wrap;background:#f8f9fc;border-radius:10px;padding:14px;max-height:360px;overflow:auto}@media(max-width:800px){.grid{grid-template-columns:1fr}.wrap{padding:16px}}
</style>
</head>
<body>
<div class="top"><strong>Cursos IA MVP</strong><span>Fábrica de Curso · v0.3</span></div>
<div class="wrap">
    <p><a class="btn secondary" href="?course=<?= $courseId ?>" onclick="history.back();return false;">← Voltar ao Criador</a></p>
    <div class="card">
        <h1><?= h($course['title']) ?></h1>
        <p><?= h($course['objective']) ?></p>
        <p><span class="pill <?= $approved ? 'ok' : '' ?>">Aula 1: <?= $approved ? 'aprovada' : 'aguardando aprovação' ?></span> <span class="pill">Status: <?= h($course['status']) ?></span></p>
        <p class="muted">Modelo planejado: curso → fontes → módulos/aulas → revisão → pacote didático → entrega ao aluno.</p>
    </div>

    <?php if ($message): ?><div class="message"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>Pacote didático completo</h2>
        <p class="muted">Gera os seis materiais previstos no modelo do produto. O gate continua sendo a aprovação humana da primeira aula.</p>
        <form method="post">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="asset_type" value="all">
            <button class="btn" <?= $approved ? '' : 'disabled' ?>>Gerar pacote completo</button>
        </form>
    </div>

    <div class="grid">
        <?php foreach ($catalog as $type => $label): $asset = $assetsByType[$type] ?? null; ?>
        <div class="card">
            <h2><?= h($label) ?></h2>
            <p><span class="pill <?= $asset ? 'ok' : '' ?>"><?= $asset ? 'gerado' : 'pendente' ?></span></p>
            <?php if ($asset): ?><pre><?= h($asset['content']) ?></pre><?php endif; ?>
            <form method="post">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="asset_type" value="<?= h($type) ?>">
                <button class="btn" <?= $approved ? '' : 'disabled' ?>><?= $asset ? 'Gerar novamente' : 'Gerar' ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
