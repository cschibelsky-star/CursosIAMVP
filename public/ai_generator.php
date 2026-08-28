<?php
declare(strict_types=1);

function aiConfig(): array
{
    return [
        'mode' => trim((string)(getenv('AI_MODE') ?: 'broker')),
        'enabled' => (getenv('AI_ENABLED') ?: '0') === '1',
        'broker_url' => trim((string)(getenv('AI_BROKER_URL') ?: '')),
        'broker_token' => trim((string)(getenv('AI_BROKER_TOKEN') ?: '')),
        'project_id' => trim((string)(getenv('AI_PROJECT_ID') ?: 'cursos-ia-mvp')),
        'url' => trim((string)(getenv('AI_API_URL') ?: '')),
        'key' => trim((string)(getenv('AI_API_KEY') ?: '')),
        'model' => trim((string)(getenv('AI_MODEL') ?: '')),
    ];
}

function aiIsReady(): bool
{
    $cfg = aiConfig();
    if (!$cfg['enabled']) return false;

    if ($cfg['mode'] === 'broker') {
        return $cfg['broker_url'] !== '' && $cfg['broker_token'] !== '' && $cfg['project_id'] !== '';
    }

    return $cfg['url'] !== '' && $cfg['key'] !== '' && $cfg['model'] !== '';
}

function aiProviderLabel(): string
{
    $cfg = aiConfig();
    if (!$cfg['enabled']) return 'desativado';
    if ($cfg['mode'] === 'broker') return aiIsReady() ? 'Centro IA / broker compartilhado' : 'Centro IA / broker não configurado';
    return aiIsReady() ? 'provedor direto' : 'provedor direto não configurado';
}

function extractJsonObject(string $text): array
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    }

    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('A IA não retornou JSON válido.');
    }

    $data = json_decode(substr($text, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Resposta estruturada da IA inválida.');
    return $data;
}

function validateCourseStructure(array $data): array
{
    if (!isset($data['modules']) || !is_array($data['modules']) || count($data['modules']) < 2) {
        throw new RuntimeException('A IA não retornou módulos suficientes.');
    }

    $modules = [];
    foreach (array_slice($data['modules'], 0, 8) as $moduleIndex => $module) {
        if (!is_array($module)) continue;
        $title = trim((string)($module['title'] ?? ''));
        $objective = trim((string)($module['objective'] ?? ''));
        $lessonsRaw = $module['lessons'] ?? [];
        if ($title === '' || $objective === '' || !is_array($lessonsRaw) || !$lessonsRaw) continue;

        $lessons = [];
        foreach (array_slice($lessonsRaw, 0, 12) as $lessonIndex => $lesson) {
            if (!is_array($lesson)) continue;
            $lessonTitle = trim((string)($lesson['title'] ?? ''));
            $lessonObjective = trim((string)($lesson['objective'] ?? ''));
            $script = trim((string)($lesson['script'] ?? ''));
            if ($lessonTitle === '' || $lessonObjective === '' || $script === '') continue;
            $lessons[] = [
                'position' => $lessonIndex + 1,
                'title' => mb_substr($lessonTitle, 0, 180),
                'objective' => mb_substr($lessonObjective, 0, 4000),
                'script' => mb_substr($script, 0, 30000),
            ];
        }

        if ($lessons) {
            $modules[] = [
                'position' => $moduleIndex + 1,
                'title' => mb_substr($title, 0, 180),
                'objective' => mb_substr($objective, 0, 4000),
                'lessons' => $lessons,
            ];
        }
    }

    if (count($modules) < 2) throw new RuntimeException('A estrutura retornada pela IA ficou incompleta após validação.');
    return ['modules' => $modules];
}

function aiPrompt(array $course, array $sources): array
{
    $sourceBlocks = [];
    $budget = 50000;
    foreach ($sources as $source) {
        $name = (string)($source['name'] ?? 'Fonte');
        $content = trim((string)($source['content'] ?? ''));
        if ($content === '') continue;
        $slice = mb_substr($content, 0, min(12000, $budget));
        $budget -= mb_strlen($slice);
        $sourceBlocks[] = "### {$name}\n{$slice}";
        if ($budget <= 0) break;
    }

    return [
        'system' => 'Você é um arquiteto pedagógico. Crie uma estrutura de curso estritamente baseada nas fontes fornecidas. Não invente fatos, leis, números ou referências que não estejam nas fontes. Responda somente JSON válido, sem markdown.',
        'user' => "Crie um curso em português brasileiro.\nTítulo: {$course['title']}\nPúblico-alvo: {$course['audience']}\nObjetivo geral: {$course['objective']}\nNível: " . (($course['course_level'] ?? '') ?: 'não definido') . "\nDuração desejada: " . (($course['desired_hours'] ?? '') ?: 'não definida') . " horas\nModalidade: " . (($course['modality'] ?? '') ?: 'não definida') . "\nLinguagem: " . (($course['language_style'] ?? '') ?: 'padrão didático') . "\nResultado esperado: " . (($course['expected_outcome'] ?? '') ?: 'não informado') . "\n\nFontes:\n" . implode("\n\n", $sourceBlocks) . "\n\nRetorne exatamente este formato JSON: {\"modules\":[{\"title\":\"...\",\"objective\":\"...\",\"lessons\":[{\"title\":\"...\",\"objective\":\"...\",\"script\":\"roteiro completo com abertura, desenvolvimento, exemplo, síntese e atividade/reflexão final\"}]}]}. Gere entre 3 e 6 módulos e entre 2 e 6 aulas por módulo. Ajuste profundidade, exemplos, extensão e ritmo ao nível, duração, modalidade, linguagem e resultado esperado do briefing. A primeira aula deve ser particularmente completa para revisão humana.",
    ];
}

function aiHttpJson(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Falha ao iniciar cliente HTTP da IA.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        $detail = '';
        if (is_string($response) && trim($response) !== '') {
            try {
                $errorBody = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($errorBody)) {
                    $detail = trim((string)($errorBody['message'] ?? $errorBody['error'] ?? ''));
                }
            } catch (Throwable $ignored) {
                $detail = trim(mb_substr(strip_tags((string)$response), 0, 500));
            }
        }
        $suffix = $error !== '' ? ': '.$error : ' (HTTP '.$status.')';
        if ($detail !== '') $suffix .= ' — '.$detail;
        throw new RuntimeException('Falha no serviço de IA'.$suffix);
    }

    $body = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new RuntimeException('Resposta HTTP da IA inválida.');
    return $body;
}

function generateViaBroker(array $course, array $sources): array
{
    $cfg = aiConfig();
    $prompt = aiPrompt($course, $sources);
    $payload = [
        'project_id' => $cfg['project_id'],
        'capability' => 'course_generation',
        'input' => [
            'system' => $prompt['system'],
            'user' => $prompt['user'],
            'response_format' => 'json',
            'temperature' => 0.2,
        ],
    ];

    $body = aiHttpJson($cfg['broker_url'], [
        'Authorization: Bearer ' . $cfg['broker_token'],
        'Content-Type: application/json',
        'X-Vitrine-Project: ' . $cfg['project_id'],
    ], $payload);

    $structured = $body['output_json'] ?? null;
    if (is_array($structured)) return validateCourseStructure($structured);

    $content = $body['output_text'] ?? $body['content'] ?? null;
    if (!is_string($content) || trim($content) === '') throw new RuntimeException('Broker do Centro IA retornou resposta sem conteúdo utilizável.');
    return validateCourseStructure(extractJsonObject($content));
}

function generateViaDirectProvider(array $course, array $sources): array
{
    $cfg = aiConfig();
    $prompt = aiPrompt($course, $sources);
    $payload = [
        'model' => $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user', 'content' => $prompt['user']],
        ],
        'temperature' => 0.2,
    ];

    $body = aiHttpJson($cfg['url'], [
        'Authorization: Bearer ' . $cfg['key'],
        'Content-Type: application/json',
    ], $payload);

    $content = $body['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') throw new RuntimeException('Resposta do provedor de IA sem conteúdo utilizável.');
    return validateCourseStructure(extractJsonObject($content));
}

function validateSingleModule(array $data): array
{
    $module = $data['module'] ?? ($data['modules'][0] ?? null);
    if (!is_array($module)) throw new RuntimeException('A IA não retornou um módulo válido.');
    $title = trim((string)($module['title'] ?? ''));
    $objective = trim((string)($module['objective'] ?? ''));
    $lessonsRaw = $module['lessons'] ?? [];
    if ($title === '' || $objective === '' || !is_array($lessonsRaw) || count($lessonsRaw) < 1) {
        throw new RuntimeException('O módulo retornado pela IA está incompleto.');
    }
    $lessons = [];
    foreach (array_slice($lessonsRaw, 0, 12) as $lesson) {
        if (!is_array($lesson)) continue;
        $lessonTitle = trim((string)($lesson['title'] ?? ''));
        $lessonObjective = trim((string)($lesson['objective'] ?? ''));
        $script = trim((string)($lesson['script'] ?? ''));
        if ($lessonTitle === '' || $lessonObjective === '' || $script === '') continue;
        $lessons[] = [
            'title'=>mb_substr($lessonTitle,0,180),
            'objective'=>mb_substr($lessonObjective,0,4000),
            'script'=>mb_substr($script,0,30000),
        ];
    }
    if (!$lessons) throw new RuntimeException('A IA não retornou aulas válidas para o módulo.');
    return ['title'=>mb_substr($title,0,180),'objective'=>mb_substr($objective,0,4000),'lessons'=>$lessons];
}

function validateSingleLesson(array $data): array
{
    $lesson = $data['lesson'] ?? null;
    if (!is_array($lesson)) throw new RuntimeException('A IA não retornou uma aula válida.');
    $title = trim((string)($lesson['title'] ?? ''));
    $objective = trim((string)($lesson['objective'] ?? ''));
    $script = trim((string)($lesson['script'] ?? ''));
    if ($title === '' || $objective === '' || $script === '') {
        throw new RuntimeException('A aula retornada pela IA está incompleta.');
    }
    return [
        'title'=>mb_substr($title,0,180),
        'objective'=>mb_substr($objective,0,4000),
        'script'=>mb_substr($script,0,30000),
    ];
}

function generateLessonWithAI(array $course, array $sources, array $module, array $lesson, array $siblingTitles = []): array
{
    if (!aiIsReady()) throw new RuntimeException('Motor de IA ainda não configurado.');
    $cfg = aiConfig();
    $sourceBlocks = [];
    $budget = 36000;
    foreach ($sources as $source) {
        $content = trim((string)($source['content'] ?? ''));
        if ($content === '') continue;
        $slice = mb_substr($content,0,min(9000,$budget));
        $budget -= mb_strlen($slice);
        $sourceBlocks[] = '### '.(string)($source['name'] ?? 'Fonte')."\n".$slice;
        if ($budget <= 0) break;
    }

    $system = 'Você é um designer instrucional. Regere apenas uma aula específica, estritamente com base nas fontes fornecidas. Preserve coerência com o módulo e com as aulas vizinhas. Não invente fatos externos. Responda somente JSON válido.';
    $user = "Curso: {$course['title']}\nPúblico: {$course['audience']}\nObjetivo geral: {$course['objective']}\nMódulo: {$module['title']}\nObjetivo do módulo: {$module['objective']}\nAula atual: {$lesson['title']}\nObjetivo atual da aula: {$lesson['objective']}\nAulas do mesmo módulo: ".implode(' | ',$siblingTitles)."\n\nFontes:\n".implode("\n\n",$sourceBlocks)."\n\nRetorne exatamente: {\"lesson\":{\"title\":\"...\",\"objective\":\"...\",\"script\":\"roteiro completo com abertura, desenvolvimento, exemplo aplicado, pontos de atenção, síntese e atividade/reflexão final\"}}.";

    if ($cfg['mode'] === 'broker') {
        $body = aiHttpJson($cfg['broker_url'], [
            'Authorization: Bearer '.$cfg['broker_token'],
            'Content-Type: application/json',
            'X-Vitrine-Project: '.$cfg['project_id'],
        ], [
            'project_id'=>$cfg['project_id'],
            'capability'=>'course_generation',
            'input'=>['system'=>$system,'user'=>$user,'response_format'=>'json','temperature'=>0.2],
        ]);
        $structured=$body['output_json']??null;
        if (is_array($structured)) return validateSingleLesson($structured);
        $content=$body['output_text']??$body['content']??null;
        if (!is_string($content)||trim($content)==='') throw new RuntimeException('Centro IA retornou aula sem conteúdo utilizável.');
        return validateSingleLesson(extractJsonObject($content));
    }

    $body = aiHttpJson($cfg['url'], [
        'Authorization: Bearer '.$cfg['key'],
        'Content-Type: application/json',
    ], [
        'model'=>$cfg['model'],
        'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
        'temperature'=>0.2,
    ]);
    $content=$body['choices'][0]['message']['content']??null;
    if (!is_string($content)||trim($content)==='') throw new RuntimeException('Provedor de IA retornou aula sem conteúdo utilizável.');
    return validateSingleLesson(extractJsonObject($content));
}

function generateModuleWithAI(array $course, array $sources, array $module, array $outline = []): array
{
    if (!aiIsReady()) throw new RuntimeException('Motor de IA ainda não configurado.');
    $cfg = aiConfig();
    $sourceBlocks = [];
    $budget = 42000;
    foreach ($sources as $source) {
        $content = trim((string)($source['content'] ?? ''));
        if ($content === '') continue;
        $slice = mb_substr($content,0,min(10000,$budget));
        $budget -= mb_strlen($slice);
        $sourceBlocks[] = '### '.(string)($source['name'] ?? 'Fonte')."\n".$slice;
        if ($budget <= 0) break;
    }
    $system = 'Você é um arquiteto pedagógico. Regere apenas um módulo de um curso existente, estritamente com base nas fontes fornecidas. Preserve coerência com o restante do curso e não invente fatos externos. Responda somente JSON válido.';
    $user = "Curso: {$course['title']}\nPúblico: {$course['audience']}\nObjetivo geral: {$course['objective']}\nMódulo atual: {$module['title']}\nObjetivo atual: {$module['objective']}\nEstrutura geral: ".implode(' | ',$outline)."\n\nFontes:\n".implode("\n\n",$sourceBlocks)."\n\nRetorne exatamente: {\"module\":{\"title\":\"...\",\"objective\":\"...\",\"lessons\":[{\"title\":\"...\",\"objective\":\"...\",\"script\":\"roteiro completo\"}]}}. Gere de 2 a 6 aulas.";

    if ($cfg['mode'] === 'broker') {
        $body = aiHttpJson($cfg['broker_url'], [
            'Authorization: Bearer '.$cfg['broker_token'],
            'Content-Type: application/json',
            'X-Vitrine-Project: '.$cfg['project_id'],
        ], [
            'project_id'=>$cfg['project_id'],
            'capability'=>'course_generation',
            'input'=>['system'=>$system,'user'=>$user,'response_format'=>'json','temperature'=>0.2],
        ]);
        $structured=$body['output_json']??null;
        if (is_array($structured)) return validateSingleModule($structured);
        $content=$body['output_text']??$body['content']??null;
        if (!is_string($content)||trim($content)==='') throw new RuntimeException('Centro IA retornou módulo sem conteúdo utilizável.');
        return validateSingleModule(extractJsonObject($content));
    }

    $body = aiHttpJson($cfg['url'], [
        'Authorization: Bearer '.$cfg['key'],
        'Content-Type: application/json',
    ], [
        'model'=>$cfg['model'],
        'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$user]],
        'temperature'=>0.2,
    ]);
    $content=$body['choices'][0]['message']['content']??null;
    if (!is_string($content)||trim($content)==='') throw new RuntimeException('Provedor de IA retornou módulo sem conteúdo utilizável.');
    return validateSingleModule(extractJsonObject($content));
}

function generateCourseWithAI(array $course, array $sources): array
{
    if (!aiIsReady()) throw new RuntimeException('Motor de IA ainda não configurado.');
    $cfg = aiConfig();
    return $cfg['mode'] === 'broker' ? generateViaBroker($course, $sources) : generateViaDirectProvider($course, $sources);
}
