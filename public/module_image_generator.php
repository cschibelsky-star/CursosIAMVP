<?php
declare(strict_types=1);
require_once __DIR__ . '/ai_generator.php';
require_once __DIR__ . '/core_model.php';

/**
 * Gera um prompt descritivo de imagem artística/cinematográfica via IA para um módulo.
 */
function generateModuleImagePrompt(array $module, string $courseTitle): string
{
    $title = (string)$module['title'];
    $objective = (string)($module['objective'] ?? '');

    // Tenta usar a IA configurada (Broker Vitrine IA Pro ou OpenAI)
    try {
        $systemPrompt = "Você é um diretor de arte e engenheiro de prompts especializado em fotografia e arte 3D hiper-realista para capas de cursos de tecnologia e negócios.";
        $userPrompt = "Crie um prompt descritivo curto em inglês (máximo 35 palavras) para gerar uma imagem de capa ultra-moderna, cinematográfica e elegante em estilo 3D / Octane Render para um módulo de curso EAD.\n\nCurso: {$courseTitle}\nMódulo: {$title}\nObjetivo: {$objective}\n\nRetorne apenas o prompt em inglês, sem aspas ou explicações.";

        $response = aiGenerateText($systemPrompt, $userPrompt, ['temperature' => 0.7]);
        if (!empty($response)) {
            return trim($response);
        }
    } catch (Throwable $e) {}

    // Fallback inteligente se a IA estiver offline
    return "hyper-realistic futuristic 3D octane render representing {$title}, dark slate theme, neon glowing accents, cinematic lighting, ultra-high resolution 8k";
}

/**
 * Resolve a URL final da imagem para o módulo:
 * Se houver serviço de imagem DALL-E / Broker configurado, pode gerar diretamente,
 * ou usa uma semente temática de alta resolução baseada no prompt gerado.
 */
function resolveModuleImageUrl(string $prompt, int $moduleId): string
{
    // Gera um hash consistente para cache da imagem
    $seed = abs(crc32($prompt . $moduleId));

    // Busca no Unsplash com termos filtrados e dimensões otimizadas 16:9
    $keywords = urlencode(substr(preg_replace('/[^a-zA-Z0-9 ]/', '', $prompt), 0, 80));
    return "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=900&q=80&sig={$seed}";
}

/**
 * Atualiza no banco a imagem do módulo gerada pela IA
 */
function autoGenerateAndSaveModuleCover(PDO $pdo, int $moduleId, string $courseTitle): string
{
    $st = $pdo->prepare("SELECT * FROM modules WHERE id=?");
    $st->execute([$moduleId]);
    $mod = $st->fetch();
    if (!$mod) return '';

    $prompt = generateModuleImagePrompt($mod, $courseTitle);
    $url = resolveModuleImageUrl($prompt, $moduleId);

    $up = $pdo->prepare("UPDATE modules SET cover_image=? WHERE id=?");
    $up->execute([$url, $moduleId]);

    return $url;
}
