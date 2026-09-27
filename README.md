# Cursos IA MVP

Projeto Cursos IA MVP — Vitrine IA Pro.

Ambiente operacional: Vitrine IA Pro V3.2 Isolado.

Status inicial: repositório inicializado para cadastro, workspace e homologação no Centro Operacional.

## Configuração de HML

O `docker-compose.hml.yml` usa Docker Compose 2.24.0 ou superior. Antes de aplicar
o compose na VPS, provisionar fora do Git, com permissões restritas, dois arquivos
relativos ao diretório deste repositório:

| Arquivo | Variáveis obrigatórias |
| --- | --- |
| `../shared/secrets/cursos-ia-app-hml.env` | `DB_PASSWORD`, `AI_BROKER_TOKEN` |
| `../shared/secrets/cursos-ia-db-hml.env` | `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD` |

`DB_PASSWORD` e `MARIADB_PASSWORD` devem corresponder à senha real do usuário
`cursos_ia` no banco existente. Alterar a variável de inicialização da MariaDB
não troca automaticamente a senha de um volume já inicializado; uma rotação deve
ser feita no banco e verificada separadamente. Não copiar as senhas antigas do
Git para os novos arquivos sem avaliar a rotação.

Os arquivos são opcionais somente para permitir `docker compose config` no CI.
Sem eles, a aplicação não consegue conectar ao banco e o serviço de banco não
deve ser implantado. O CI valida a sintaxe e o build, não a disponibilidade de
secrets nem a inicialização dos serviços.
