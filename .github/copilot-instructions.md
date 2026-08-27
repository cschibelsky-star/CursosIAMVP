# Copilot Instructions — Cursos IA MVP

Este é um projeto real da Vitrine IA Pro. Trabalhe sempre de forma conservadora e reversível.

## Regras obrigatórias
- Nunca fazer deploy em produção.
- Nunca modificar, criar ou expor `.env`, segredos, tokens ou credenciais.
- Nunca alterar Docker, Nginx, SSL ou infraestrutura salvo quando uma Issue exigir explicitamente.
- Não alterar schema/banco de dados sem requisito explícito e revisão humana.
- Preservar a arquitetura e os fluxos existentes; não recriar módulos já existentes.
- Fazer a menor alteração possível para cumprir a Issue.
- Toda alteração deve ser entregue em branch própria e Pull Request para revisão humana.
- Não fazer merge automático.
- Executar validações compatíveis com os arquivos alterados, incluindo `php -l` para PHP.
- Escapar dados exibidos em HTML e preservar os helpers de segurança existentes.
- No PR, informar arquivos alterados, testes executados, riscos e limitações.

## Ambientes
- Desenvolvimento/branch: permitido para código.
- HML: somente após revisão humana e ação explícita.
- Produção: proibida para o agente.
