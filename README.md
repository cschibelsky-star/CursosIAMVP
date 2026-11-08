# 🎓 Cursos IA MVP — Plataforma EAD & Portal do Aluno

Ambiente virtual de aprendizagem (LMS/EAD) moderno e imersivo, com foco na experiência do aluno, player de vídeo estilo cinema, trilha interativa, liberação sequencial de aulas condicional (100% vídeo + ≥ 70% teste), controle acadêmico 360° e certificação automatizada.

---

## 🚀 Principais Módulos

### 1. 👨‍🎓 Portal do Aluno Premium
- **Dashboard EAD:** Visão de cursos matriculados, percentual de progresso e atalhos "Continuar de onde parou".
- **Player de Aula Imersivo:** Layout com barra lateral de módulos/aulas, alternância de status, roteiros interativos e modo foco.
- **Avaliações e Certificação:** Emissão automatizada de certificados com autenticação via código e validação pública.

### 2. ⚡ Estúdio de IA & Fábrica de Conteúdo
- **Processamento de Fontes:** Upload e ingestão de PDFs, transcrições e textos brutos.
- **Course Engine:** Geração automatizada de ementas pedagógicas, módulos e roteiros de aula via IA.
- **Fábrica de Recursos:** Geração de quiz, resumos executivos, prompts de vídeo e flashcards.

### 3. 📊 Painel Administrativo & Gestão 360°
- **Visão 360° de Alunos:** Histórico acadêmico, presencialidade, notas e status financeiro.
- **Financeiro & Matrículas:** Controle de mensalidades, faturamento e inadimplência.
- **Gestão de Turmas e Cidades Inclusivas:** Suporte a turmas presenciais e híbridas.

---

## 🛠️ Como Executar Localmente

### Pré-requisitos
- Docker & Docker Compose
- Git

### 1. Clonar o repositório
```bash
git clone https://github.com/cschibelsky-star/CursosIAMVP.git
cd CursosIAMVP
```

### 2. Configurar variáveis de ambiente
```bash
cp .env.example .env
```
*Edite o arquivo `.env` para ajustar o token de IA ou credenciais do banco se necessário.*

### 3. Subir os contêineres
```bash
docker compose -f docker-compose.hml.yml up -d --build
```

A aplicação estará disponível em:
- **Painel Administrativo:** [http://localhost:8894/index.php](http://localhost:8894/index.php)
- **Portal do Aluno:** [http://localhost:8894/aluno.php](http://localhost:8894/aluno.php)
- **Login do Aluno:** [http://localhost:8894/aluno_login.php](http://localhost:8894/aluno_login.php)
- **Verificação de Saúde:** [http://localhost:8894/health.php](http://localhost:8894/health.php)

---

## 📂 Estrutura de Arquivos

```
├── .github/workflows/       # Pipelines de CI/CD (lint PHP e contêineres)
├── docker/                  # Configurações Nginx/Apache
├── public/                  # Arquivos web servidos
│   ├── assets/              # Design System CSS e recursos visuais
│   │   └── app.css          # Estilos EAD Premium (Dark/Light moderno)
│   ├── aluno*.php           # Telas do Portal do Aluno e Player
│   ├── index.php            # Entrada principal do Estúdio
│   ├── dashboard.php        # Dashboard de Métricas
│   ├── *model.php           # Camada de dados e persistência
│   └── health.php           # Healthcheck de monitoramento
├── Dockerfile               # Imagem PHP 8.3 Apache
├── docker-compose.hml.yml   # Orquestração do ambiente de homologação
└── .env.example             # Modelo de variáveis de ambiente
```

---

## 🛡️ Qualidade e CI

O repositório possui validação automática via GitHub Actions:
- **PHP Lint:** Checagem de sintaxe em todos os arquivos modificados.
- **Container Build:** Validação de build da imagem Dockerfile e compose.
