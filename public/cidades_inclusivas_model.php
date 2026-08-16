<?php
declare(strict_types=1);

function ensureCidadesInclusivas(PDO $pdo): int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL,audience VARCHAR(180) NOT NULL,objective TEXT NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'rascunho',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,source_type VARCHAR(30) NOT NULL,name VARCHAR(255) NOT NULL,content LONGTEXT NULL,processing_status VARCHAR(40) NOT NULL DEFAULT 'processado',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_sources_course(course_id),CONSTRAINT fk_sources_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS modules (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,course_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_module_position(course_id,position),CONSTRAINT fk_modules_course FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lessons (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_id INT UNSIGNED NOT NULL,position INT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,objective TEXT NOT NULL,script LONGTEXT NOT NULL,review_status VARCHAR(40) NOT NULL DEFAULT 'pendente',reviewer_notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_lesson_position(module_id,position),CONSTRAINT fk_lessons_module FOREIGN KEY(module_id) REFERENCES modules(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try { $pdo->exec("ALTER TABLE sources ADD COLUMN source_url VARCHAR(500) NULL AFTER name"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE sources ADD COLUMN authority VARCHAR(160) NULL AFTER source_url"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE sources ADD COLUMN verified_at DATE NULL AFTER processing_status"); } catch (Throwable $e) {}

    $title = 'Cidades Inclusivas';
    $audience = 'Assessores legislativos e políticos, gestores municipais e estaduais, assistentes sociais e agentes comunitários';
    $objective = 'Capacitar agentes públicos e profissionais de atendimento à população a planejar e implementar políticas inclusivas, com base no direito à cidade, no marco legal brasileiro e em práticas de acessibilidade e inclusão.';

    $stmt = $pdo->prepare('SELECT id FROM courses WHERE title=? ORDER BY id LIMIT 1');
    $stmt->execute([$title]);
    $courseId = (int)($stmt->fetchColumn() ?: 0);

    if ($courseId < 1) {
        $stmt = $pdo->prepare('INSERT INTO courses(title,audience,objective,status) VALUES(?,?,?,?)');
        $stmt->execute([$title, $audience, $objective, 'modelo_oficial']);
        $courseId = (int)$pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare('UPDATE courses SET audience=?,objective=? WHERE id=?');
        $stmt->execute([$audience, $objective, $courseId]);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM modules WHERE course_id=?');
    $stmt->execute([$courseId]);
    $moduleCount = (int)$stmt->fetchColumn();

    if ($moduleCount === 0) {
        $modules = [
            ['Conceitos de inclusão e direito à cidade', 'Compreender inclusão, acessibilidade, participação social e direito à cidade como fundamentos para políticas públicas inclusivas.'],
            ['Marco legal da inclusão e acessibilidade', 'Estudar a Lei 10.098/2000, a Lei 13.146/2015, o Decreto 5.296/2004 e o PL 366/2024 dentro do contexto de cidades inclusivas.'],
            ['Políticas públicas e planejamento inclusivo', 'Relacionar diagnóstico territorial, planejamento público e formulação de políticas inclusivas voltadas à população.'],
            ['Implementação: desenho universal, barreiras e adaptações', 'Aplicar princípios de desenho universal, identificar barreiras e estruturar adaptações e soluções inclusivas na gestão pública.'],
            ['Avaliação, monitoramento e certificação', 'Definir critérios para acompanhar, avaliar e certificar ações, serviços e políticas voltadas à construção de cidades inclusivas.'],
        ];
        $lessonTypes = [
            ['Fundamentos teóricos', 'Compreender os conceitos, fundamentos e referências essenciais do módulo.'],
            ['Caso aplicado', 'Analisar uma situação prática relacionada ao conteúdo do módulo.'],
            ['Prática orientada', 'Transformar o conteúdo do módulo em ação, diagnóstico, planejamento ou intervenção aplicável.'],
        ];
        foreach ($modules as $moduleIndex => $module) {
            $stmt = $pdo->prepare('INSERT INTO modules(course_id,position,title,objective) VALUES(?,?,?,?)');
            $stmt->execute([$courseId, $moduleIndex + 1, $module[0], $module[1]]);
            $moduleId = (int)$pdo->lastInsertId();
            foreach ($lessonTypes as $lessonIndex => $lessonType) {
                $lessonTitle = $lessonType[0];
                $lessonObjective = $lessonType[1] . ' Tema do módulo: ' . $module[0] . '.';
                $script = "Curso: Cidades Inclusivas\nMódulo " . ($moduleIndex + 1) . " — {$module[0]}\nAula " . ($lessonIndex + 1) . " — {$lessonTitle}\n\nObjetivo\n{$lessonObjective}\n\nEstrutura de homologação\n1. Abertura e contextualização\n2. Desenvolvimento fundamentado nas fontes oficiais do curso\n3. Relação com a atuação de agentes públicos e políticos\n4. Caso ou situação aplicada, quando pertinente\n5. Atividade ou encaminhamento prático\n6. Síntese e próximos passos\n\n[Modelo oficial Cidades Inclusivas — conteúdo final deve ser fundamentado nas fontes carregadas e revisado antes da publicação.]";
                $stmtLesson = $pdo->prepare('INSERT INTO lessons(module_id,position,title,objective,script) VALUES(?,?,?,?,?)');
                $stmtLesson->execute([$moduleId, $lessonIndex + 1, $lessonTitle, $lessonObjective, $script]);
            }
        }
    }

    $references = [
        ['Lei nº 10.098/2000 — Acessibilidade','https://www.planalto.gov.br/ccivil_03/leis/l10098.htm','Presidência da República — Planalto','Lei que estabelece normas gerais e critérios básicos para a promoção da acessibilidade das pessoas com deficiência ou mobilidade reduzida. Para o curso, é referência central em acessibilidade, barreiras urbanísticas, arquitetônicas, transportes, comunicação e informação.'],
        ['Lei nº 13.146/2015 — Lei Brasileira de Inclusão','https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13146.htm','Presidência da República — Planalto','Institui a Lei Brasileira de Inclusão da Pessoa com Deficiência. Para o curso, fundamenta inclusão social, cidadania, igualdade de condições, conceito de pessoa com deficiência, acessibilidade e eliminação de barreiras.'],
        ['Decreto nº 5.296/2004 — Regulamentação de acessibilidade','https://www.planalto.gov.br/ccivil_03/_ato2004-2006/2004/decreto/d5296.htm','Presidência da República — Planalto','Regulamenta as Leis 10.048/2000 e 10.098/2000. Para o curso, apoia os conteúdos sobre projetos arquitetônicos e urbanísticos, comunicação e informação, transporte coletivo e execução de obras destinadas ao uso público ou coletivo.'],
        ['PL 366/2024 — Programa de Fomento às Cidades Inclusivas','https://www.camara.leg.br/proposicoesWeb/fichadetramitacao?idProposicao=2418356','Câmara dos Deputados','Projeto de Lei que dispõe sobre o Programa de Fomento às Cidades Inclusivas. Situação verificada em 16/08/2026: aguardando designação de relator(a) na Comissão de Finanças e Tributação. Deve ser tratado no curso como proposição em tramitação, não como lei vigente.'],
    ];

    foreach ($references as $ref) {
        $stmt = $pdo->prepare('SELECT id FROM sources WHERE course_id=? AND name=? LIMIT 1');
        $stmt->execute([$courseId, $ref[0]]);
        $sourceId = (int)($stmt->fetchColumn() ?: 0);
        if ($sourceId < 1) {
            $stmt = $pdo->prepare('INSERT INTO sources(course_id,source_type,name,source_url,authority,content,processing_status,verified_at) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([$courseId, 'referencia_oficial', $ref[0], $ref[1], $ref[2], $ref[3], 'verificado', '2026-08-16']);
        } else {
            $stmt = $pdo->prepare('UPDATE sources SET source_url=?,authority=?,content=?,processing_status=?,verified_at=? WHERE id=?');
            $stmt->execute([$ref[1], $ref[2], $ref[3], 'verificado', '2026-08-16', $sourceId]);
        }
    }

    return $courseId;
}
