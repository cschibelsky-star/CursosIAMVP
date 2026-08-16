<?php
declare(strict_types=1);

function courseEngineTrim(string $text, int $maxChars): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $maxChars) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $maxChars)) . '…';
}

function courseEngineSourceContext(PDO $pdo, int $courseId, int $maxChars = 12000): string
{
    $stmt = $pdo->prepare('SELECT name, source_type, content FROM sources WHERE course_id=? ORDER BY id');
    $stmt->execute([$courseId]);
    $parts = [];
    foreach ($stmt->fetchAll() as $source) {
        $parts[] = '[' . $source['name'] . ' · ' . $source['source_type'] . '] ' . (string)$source['content'];
    }
    return courseEngineTrim(implode("\n\n", $parts), $maxChars);
}

function courseEngineLessonContext(PDO $pdo, int $courseId, int $maxChars = 18000): string
{
    $stmt = $pdo->prepare('SELECT m.position AS module_position,m.title AS module_title,l.position AS lesson_position,l.title AS lesson_title,l.objective,l.script,l.review_status FROM modules m INNER JOIN lessons l ON l.module_id=m.id WHERE m.course_id=? ORDER BY m.position,l.position');
    $stmt->execute([$courseId]);
    $parts = [];
    foreach ($stmt->fetchAll() as $lesson) {
        $parts[] = "Módulo {$lesson['module_position']} — {$lesson['module_title']}\nAula {$lesson['lesson_position']} — {$lesson['lesson_title']}\nObjetivo: {$lesson['objective']}\nStatus: {$lesson['review_status']}\nRoteiro: {$lesson['script']}";
    }
    return courseEngineTrim(implode("\n\n", $parts), $maxChars);
}

function courseEngineBuildMaterial(array $course, string $outline, string $sources, string $lessons, string $type): array
{
    $title = (string)$course['title'];
    $audience = (string)$course['audience'];
    $objective = (string)$course['objective'];
    $grounding = "BASE DE CONHECIMENTO DO CURSO\n{$sources}\n\nCONTEÚDO PEDAGÓGICO JÁ ESTRUTURADO\n{$lessons}";

    return match ($type) {
        'slides' => [
            'Slides do curso',
            "SLIDES — {$title}\n\nPúblico-alvo: {$audience}\nObjetivo geral: {$objective}\n\nESTRUTURA\n{$outline}\n\nPADRÃO DE SLIDES POR AULA\n1. Título e objetivo da aula\n2. Por que este tema importa\n3. Conceitos-chave extraídos das fontes\n4. Explicação visual do processo\n5. Exemplo aplicado ao público-alvo\n6. Erros e cuidados\n7. Checklist prático\n8. Resumo e próxima ação\n\nCONTEÚDO DE REFERÊNCIA PARA REDAÇÃO DOS SLIDES\n{$grounding}\n\n[Motor editorial fundamentado v0.4 — nenhum conteúdo deve contradizer as fontes carregadas.]",
        ],
        'apostila' => [
            'Apostila do curso',
            "APOSTILA — {$title}\n\nAPRESENTAÇÃO\nMaterial de apoio do curso destinado a {$audience}.\n\nOBJETIVO GERAL\n{$objective}\n\nSUMÁRIO\n{$outline}\n\nMODELO EDITORIAL POR AULA\n• Contexto e objetivo\n• Conceitos essenciais\n• Explicação detalhada\n• Passo a passo\n• Exemplo prático\n• Erros comuns\n• Checklist de aplicação\n• Resumo\n• Atividade sugerida\n• Espaço para anotações\n\nBASE PARA DESENVOLVIMENTO DA APOSTILA\n{$grounding}\n\n[Motor editorial fundamentado v0.4 — pronto para futura geração DOCX/PDF.]",
        ],
        'exercicios' => [
            'Exercícios e atividades',
            "EXERCÍCIOS — {$title}\n\nPúblico: {$audience}\nObjetivo: {$objective}\n\nESTRUTURA DO CURSO\n{$outline}\n\nBANCO DE ATIVIDADES A PRODUZIR POR AULA\n1. Pergunta de compreensão do conceito central\n2. Exercício de aplicação prática\n3. Checklist de execução\n4. Identificação de erro comum\n5. Situação-problema\n6. Tarefa prática ligada ao objetivo da aula\n\nBASE OBRIGATÓRIA PARA AS ATIVIDADES\n{$grounding}\n\n[Motor editorial fundamentado v0.4 — atividades devem ser respondíveis a partir do conteúdo do curso.]",
        ],
        'avaliacao' => [
            'Avaliação do curso',
            "AVALIAÇÃO — {$title}\n\nOBJETIVO AVALIADO\n{$objective}\n\nCONTEÚDO PROGRAMÁTICO\n{$outline}\n\nMODELO DE AVALIAÇÃO\n• 10 questões objetivas com quatro alternativas\n• 2 situações práticas\n• 1 atividade final de aplicação\n• gabarito comentado\n• critério de aprovação configurável\n\nREGRAS\n• cobrir todos os módulos;\n• não avaliar assunto ausente das fontes;\n• usar linguagem compatível com {$audience};\n• justificar respostas no gabarito.\n\nBASE PARA CRIAÇÃO DAS QUESTÕES\n{$grounding}\n\n[Motor editorial fundamentado v0.4.]",
        ],
        'pagina_venda' => [
            'Página de apresentação e venda',
            "PÁGINA DO CURSO — {$title}\n\nPARA QUEM É\n{$audience}\n\nTRANSFORMAÇÃO PRINCIPAL\n{$objective}\n\nCONTEÚDO PROGRAMÁTICO\n{$outline}\n\nESTRUTURA COMERCIAL\n• headline baseada no resultado esperado\n• problema que o curso resolve\n• benefícios concretos\n• módulos e aulas\n• materiais incluídos\n• para quem é / para quem não é\n• metodologia\n• certificação\n• perguntas frequentes\n• chamada para matrícula\n\nBASE DE CONTEÚDO PARA A COPY\n{$grounding}\n\n[Motor editorial fundamentado v0.4 — sem promessas não sustentadas pelo conteúdo do curso.]",
        ],
        'certificado' => [
            'Modelo de certificado',
            "CERTIFICADO — {$title}\n\nCertificamos que [NOME DO ALUNO] concluiu o curso “{$title}”, destinado a {$audience}, cumprindo os requisitos de conclusão definidos pela plataforma.\n\nOBJETIVO DO CURSO\n{$objective}\n\nCONTEÚDO PROGRAMÁTICO\n{$outline}\n\nCAMPOS DO DOCUMENTO\n• nome do aluno\n• nome do curso\n• carga horária\n• data de conclusão\n• código único de validação\n• emissor/responsável\n• QR Code de validação futura\n\n[Motor editorial fundamentado v0.4 — geração PDF e validação pública permanecem na etapa de entrega.]",
        ],
        default => throw new RuntimeException('Tipo de material não reconhecido.'),
    };
}

function courseEngineStatus(): array
{
    return [
        'mode' => 'grounded_editorial_v0.4',
        'external_ai' => false,
        'provider' => null,
        'grounded_in_sources' => true,
        'grounded_in_lessons' => true,
    ];
}