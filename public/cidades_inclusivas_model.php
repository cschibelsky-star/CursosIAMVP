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
            ['Marco legal da inclusão e acessibilidade', 'Estudar a Lei 10.098/2000, a Lei 13.146/2015, o Decreto 5.296/2004, o Estatuto da Cidade, a Convenção sobre os Direitos das Pessoas com Deficiência e o PL 366/2024 dentro do contexto de cidades inclusivas.'],
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
        ['Lei nº 10.098/2000 — Acessibilidade','https://www.planalto.gov.br/ccivil_03/leis/l10098.htm','Presidência da República — Planalto','Estabelece normas gerais e critérios básicos para a promoção da acessibilidade e trabalha com a eliminação de barreiras em vias e espaços públicos, edificações, transportes, comunicação e informação.'],
        ['Lei nº 13.146/2015 — Lei Brasileira de Inclusão','https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2015/lei/l13146.htm','Presidência da República — Planalto','Institui a Lei Brasileira de Inclusão da Pessoa com Deficiência e orienta a garantia de direitos, igualdade de condições, inclusão social, cidadania, acessibilidade e eliminação de barreiras.'],
        ['Lei nº 10.257/2001 — Estatuto da Cidade','https://www.planalto.gov.br/ccivil_03/leis/leis_2001/l10257.htm','Presidência da República — Planalto','Regulamenta os arts. 182 e 183 da Constituição e estabelece diretrizes gerais da política urbana, orientadas ao desenvolvimento das funções sociais da cidade, ao bem coletivo, ao bem-estar dos cidadãos e ao equilíbrio ambiental.'],
        ['Decreto nº 5.296/2004 — Regulamentação de acessibilidade','https://www.planalto.gov.br/ccivil_03/_ato2004-2006/2004/decreto/d5296.htm','Presidência da República — Planalto','Regulamenta as Leis 10.048/2000 e 10.098/2000 e alcança projetos arquitetônicos e urbanísticos, comunicação e informação, transporte coletivo e obras destinadas ao uso público ou coletivo.'],
        ['Decreto nº 6.949/2009 — Convenção sobre os Direitos das Pessoas com Deficiência','https://www.planalto.gov.br/ccivil_03/_ato2007-2010/2009/decreto/d6949.htm','Presidência da República — Planalto','Promulga a Convenção sobre os Direitos das Pessoas com Deficiência. Para o curso, fundamenta dignidade, não discriminação, participação plena e efetiva na sociedade, igualdade de oportunidades e acessibilidade, considerando a interação entre impedimentos e barreiras.'],
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

    // Módulo 1: substitui apenas roteiros ainda não revisados. Conteúdo humano já aprovado nunca é sobrescrito.
    $stmt = $pdo->prepare('SELECT id FROM modules WHERE course_id=? AND position=1 LIMIT 1');
    $stmt->execute([$courseId]);
    $module1Id = (int)($stmt->fetchColumn() ?: 0);

    if ($module1Id > 0) {
        $module1Lessons = [
            1 => [
                'title' => 'Inclusão, acessibilidade e direito à cidade',
                'objective' => 'Compreender a diferença entre inclusão e acessibilidade, reconhecer o papel das barreiras na exclusão social e relacionar esses conceitos ao direito à cidade e à responsabilidade do poder público.',
                'script' => <<<'AULA1'
CURSO: Cidades Inclusivas
MÓDULO 1 — Conceitos de inclusão e direito à cidade
AULA 1 — Inclusão, acessibilidade e direito à cidade
Duração sugerida: 80 minutos

OBJETIVO DA AULA
Ao final desta aula, o aluno deverá ser capaz de explicar o que torna uma cidade inclusiva, diferenciar inclusão de acessibilidade, identificar como as barreiras limitam a participação social e relacionar essas ideias ao planejamento e à atuação do poder público.

1. ABERTURA — A CIDADE É PARA QUEM?
Imagine duas pessoas chegando ao mesmo equipamento público. A primeira entra, localiza o setor desejado, entende as informações disponíveis e utiliza o serviço sem ajuda. A segunda encontra degraus, sinalização inadequada, comunicação inacessível ou um atendimento que não considera suas necessidades. Formalmente, o serviço existe para ambas. Na prática, apenas uma conseguiu exercer o direito em condições adequadas.

Esse exemplo introduz a ideia central da aula: inclusão não significa apenas permitir presença. Inclusão significa criar condições reais para que diferentes pessoas possam circular, compreender, participar, acessar serviços, tomar decisões e exercer direitos com autonomia, segurança e dignidade.

2. INCLUSÃO NÃO É FAVOR: É CONDIÇÃO DE CIDADANIA
Uma cidade inclusiva parte do reconhecimento de que a população é diversa. Pessoas com deficiência, idosos, gestantes, crianças, pessoas com mobilidade reduzida, pessoas com diferentes formas de comunicação e cidadãos em contextos sociais distintos utilizam os mesmos espaços e serviços públicos.

A Lei Brasileira de Inclusão orienta a garantia de direitos e liberdades fundamentais em condições de igualdade, com foco em inclusão social e cidadania. A Convenção sobre os Direitos das Pessoas com Deficiência reforça princípios como dignidade, não discriminação, participação plena e efetiva, igualdade de oportunidades e acessibilidade.

Para a gestão pública, a consequência é prática: a pergunta não deve ser apenas “o serviço existe?”, mas também “as pessoas conseguem utilizá-lo em igualdade de condições?”.

3. ACESSIBILIDADE É UMA DAS BASES DA INCLUSÃO
Acessibilidade pode ser entendida como a condição que permite alcançar e utilizar, com segurança e autonomia, espaços, equipamentos, transportes, informação, comunicação, serviços e instalações.

A Lei nº 10.098/2000 trabalha diretamente com a eliminação de obstáculos e barreiras. O Decreto nº 5.296/2004 leva essa obrigação para projetos arquitetônicos e urbanísticos, comunicação, informação, transporte coletivo e obras de uso público ou coletivo.

Portanto, acessibilidade não se resume a rampa. Uma cidade pode ter uma rampa e ainda ser excludente. Há barreiras urbanísticas, arquitetônicas, nos transportes, na comunicação e informação, além de atitudes e comportamentos que podem dificultar ou impedir participação social.

4. A DEFICIÊNCIA NÃO PODE SER ANALISADA ISOLADAMENTE DA BARREIRA
A legislação brasileira atual e a Convenção trabalham com uma visão que considera a interação entre impedimentos e barreiras. Isso muda o modo de pensar políticas públicas.

Exemplo: uma pessoa usuária de cadeira de rodas diante de um prédio com acesso nivelado e circulação adequada encontra uma condição muito diferente daquela encontrada diante de uma escadaria sem alternativa acessível. O impedimento pessoal não mudou; o ambiente mudou.

Esse raciocínio desloca parte importante da responsabilidade para o planejamento da cidade, dos serviços e das políticas públicas. O gestor passa a observar não somente características individuais, mas também barreiras produzidas ou mantidas pelo ambiente.

5. DIREITO À CIDADE: PARTICIPAR DA VIDA URBANA
O Estatuto da Cidade estabelece diretrizes gerais para a política urbana e relaciona o desenvolvimento urbano às funções sociais da cidade, ao bem coletivo e ao bem-estar dos cidadãos.

No contexto deste curso, direito à cidade significa olhar para a possibilidade concreta de viver e participar da cidade: deslocar-se, utilizar serviços, acessar espaços públicos, estudar, trabalhar, comunicar-se, participar de atividades culturais, exercer cidadania e influenciar decisões que afetam o território.

Uma política urbana pode ser tecnicamente bem estruturada e ainda produzir exclusão se não considerar quem consegue — e quem não consegue — acessar seus benefícios.

6. O QUE CARACTERIZA UMA CIDADE INCLUSIVA
Para fins do curso Cidades Inclusivas, adotaremos cinco dimensões práticas:

1. Acesso físico — vias, prédios, equipamentos, mobiliário e transporte utilizáveis com segurança e autonomia.
2. Acesso à informação e comunicação — informações compreensíveis e disponíveis em formatos adequados às diferentes necessidades.
3. Acesso aos serviços — atendimento público organizado para acolher a diversidade da população.
4. Participação — pessoas afetadas pelas políticas têm condições de contribuir para decisões e avaliações.
5. Igualdade de oportunidades — o planejamento evita que barreiras excluam grupos do exercício de direitos.

7. PAPEL DO AGENTE PÚBLICO E DO ASSESSOR
Uma cidade inclusiva não depende exclusivamente de uma secretaria ou de uma obra específica. Inclusão atravessa planejamento urbano, transporte, saúde, educação, assistência social, cultura, turismo, comunicação, tecnologia, atendimento ao cidadão, legislação e orçamento.

Por isso, gestores e assessores devem aprender a fazer perguntas de controle:
- Quem pode ficar de fora desta política?
- Existe alguma barreira física, comunicacional, tecnológica ou atitudinal?
- O público diretamente afetado foi ouvido?
- A solução proposta aumenta autonomia ou cria dependência desnecessária?
- O serviço pode ser utilizado em igualdade de condições?
- Há indicador para verificar se a inclusão ocorreu de fato?

8. CASO APLICADO
Um município reforma uma praça central. O projeto melhora iluminação, paisagismo e segurança. Entretanto, mantém desníveis sem rota acessível contínua, instala placas sem contraste adequado e cria um palco permanente sem acesso para artistas com mobilidade reduzida.

Pergunta ao aluno: a praça foi modernizada, mas pode ser considerada plenamente inclusiva?

Resposta esperada: não. A melhoria estética e funcional não substitui a necessidade de considerar acessibilidade, autonomia, participação e igualdade de uso. O diagnóstico deve identificar quais pessoas encontram barreiras e quais correções precisam ser incorporadas ao projeto.

9. ATIVIDADE PRÁTICA — LEITURA INCLUSIVA DO TERRITÓRIO
Escolha um equipamento público, praça, terminal, unidade de saúde, escola, centro cultural ou serviço digital do seu município. Registre:
- quem utiliza o local ou serviço;
- quais barreiras podem existir;
- quais grupos podem ter dificuldade de acesso ou participação;
- uma mudança simples de curto prazo;
- uma mudança estrutural de médio prazo;
- qual órgão ou setor deve participar da solução.

10. SÍNTESE
Cidade inclusiva não é apenas cidade adaptada. É cidade planejada para que a diversidade humana seja considerada desde a formulação das políticas até a execução dos serviços.

Acessibilidade é uma condição essencial para a inclusão. Barreiras podem ser produzidas pelo espaço físico, pelo transporte, pela comunicação, pela tecnologia e pelas atitudes. O direito à cidade exige que o desenvolvimento urbano alcance a população de maneira efetiva e não apenas formal.

PRÓXIMA AULA
Na Aula 2, vamos identificar barreiras urbanas e analisar como elas afetam participação social, autonomia e acesso aos serviços públicos.

FONTES-BASE DA AULA
- Lei nº 10.098/2000 — Acessibilidade.
- Lei nº 13.146/2015 — Lei Brasileira de Inclusão.
- Lei nº 10.257/2001 — Estatuto da Cidade.
- Decreto nº 5.296/2004 — Regulamentação de acessibilidade.
- Decreto nº 6.949/2009 — Convenção sobre os Direitos das Pessoas com Deficiência.

[Conteúdo pedagógico v0.5 — revisão humana obrigatória antes da aprovação e geração dos materiais derivados.]
AULA1
            ],
            2 => [
                'title' => 'Barreiras urbanas e participação social',
                'objective' => 'Identificar barreiras urbanísticas, arquitetônicas, de transporte, comunicação, informação e atitude, avaliando seus efeitos sobre autonomia e participação social.',
                'script' => "CURSO: Cidades Inclusivas\nMÓDULO 1 — Conceitos de inclusão e direito à cidade\nAULA 2 — Barreiras urbanas e participação social\nDuração sugerida: 80 minutos\n\nObjetivo\nIdentificar diferentes tipos de barreiras presentes no território e nos serviços públicos, relacionando-as à participação social e ao exercício de direitos.\n\nEstrutura prevista\n1. Revisão do conceito de barreira.\n2. Barreiras urbanísticas e arquitetônicas.\n3. Barreiras no transporte.\n4. Barreiras na comunicação e informação.\n5. Barreiras tecnológicas e atitudinais.\n6. Caso aplicado em equipamento público.\n7. Mapeamento de barreiras no município.\n8. Síntese e preparação para o diagnóstico inclusivo.\n\n[Conteúdo específico do Módulo 1 — próxima aula a ser desenvolvida integralmente após homologação da Aula 1.]",
            ],
            3 => [
                'title' => 'Diagnóstico inclusivo do território',
                'objective' => 'Aplicar uma leitura prática do território para reconhecer públicos afetados, barreiras, responsáveis institucionais e prioridades de intervenção inclusiva.',
                'script' => "CURSO: Cidades Inclusivas\nMÓDULO 1 — Conceitos de inclusão e direito à cidade\nAULA 3 — Diagnóstico inclusivo do território\nDuração sugerida: 80 minutos\n\nObjetivo\nTransformar os conceitos de inclusão, acessibilidade e barreiras em um diagnóstico simples de território ou serviço público.\n\nEstrutura prevista\n1. Definição do território ou serviço analisado.\n2. Identificação dos públicos usuários.\n3. Mapeamento de barreiras.\n4. Priorização por impacto e urgência.\n5. Identificação do órgão responsável.\n6. Proposta de ação rápida e ação estrutural.\n7. Indicador mínimo de acompanhamento.\n8. Atividade final do Módulo 1.\n\n[Conteúdo específico do Módulo 1 — desenvolvimento integral após homologação da Aula 1.]",
            ],
        ];

        foreach ($module1Lessons as $position => $lessonData) {
            $stmt = $pdo->prepare('SELECT id,script,review_status FROM lessons WHERE module_id=? AND position=? LIMIT 1');
            $stmt->execute([$module1Id, $position]);
            $lesson = $stmt->fetch();
            if (!$lesson) continue;

            $pdo->prepare('UPDATE lessons SET title=?,objective=? WHERE id=?')->execute([$lessonData['title'], $lessonData['objective'], $lesson['id']]);

            $isTemplate = str_contains((string)$lesson['script'], 'Estrutura de homologação') || str_contains((string)$lesson['script'], '[Modelo oficial Cidades Inclusivas');
            if ($lesson['review_status'] === 'pendente' && $isTemplate) {
                $pdo->prepare('UPDATE lessons SET script=? WHERE id=?')->execute([$lessonData['script'], $lesson['id']]);
            }
        }
    }

    return $courseId;
}
