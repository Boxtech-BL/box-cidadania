# Box Cidadania

Aplicação web em que o cidadão acompanha as proposições em votação na Câmara dos Deputados e no Senado Federal, registra a sua opinião (a favor, contra ou abstenção) numa **consulta informal, sem valor oficial**, e depois compara com a votação real e com os parlamentares do seu estado.

É independente do App Box Suas Contas: não compartilha usuários, banco nem dados.

PHP 8.1+ e PostgreSQL, sem Composer, sem Node e sem Docker no servidor, no mesmo modelo do SGI Boxtech: roda direto na hospedagem compartilhada da Locaweb e é publicado pelo GitHub Actions por FTP.

## Situação

| Etapa | Situação |
| --- | --- |
| 1. Banco e cadastro | pronta |
| 2. Coleta da Câmara | a fazer |
| 3. Coleta do Senado | a fazer |
| 4. Votação e resultados | a fazer |
| 5. Painel administrativo | a fazer |
| 6. Painel público | a fazer |
| 7. Publicação | workflow pronto; falta configurar a hospedagem |

### O que a etapa 1 entrega

- Banco com as 8 tabelas do escopo (`usuarios`, `proposicoes`, `pauta_itens`, `pauta_historico`, `votacoes_oficiais`, `votos_parlamentares`, `parlamentares`, `votos_cidadania`) e as de apoio (`ufs`, `municipios`, `consentimentos`, `tokens_usuario`, `eventos_limite`, `auditoria_admin`), com 27 UFs e 5.571 municípios com código IBGE.
- Cadastro com e-mail e senha (Argon2id), estado e município opcional. Não pede CPF, endereço nem localização.
- Confirmação do e-mail por link (48 h), obrigatória para votar; reenvio limitado a 3 por hora.
- Login com bloqueio após 10 falhas em 15 minutos (por IP e e-mail) e mensagem igual para e-mail inexistente e senha errada.
- Recuperação de senha por link (1 h, uso único; um pedido novo invalida o anterior), sem revelar se o e-mail existe.
- Termos de uso e política de privacidade no cadastro e, em destaque e separado, o **consentimento para tratamento de opinião política** (LGPD, art. 5º, II), versionado em `consentimentos`. Ele pode ser dado no cadastro ou depois, e é exigido antes do primeiro voto.
- Minha conta: trocar estado e município, apagar os votos, retirar o consentimento (o que apaga os votos) e excluir a conta com a senha.
- Limite de contas por IP (padrão 5 por dia). O IP só é guardado como HMAC e apagado depois de 30 dias.
- Votos em `votos_cidadania` sem o id do usuário: a ligação é por `pseudonimo_usuario` = HMAC-SHA256(`PSEUDONYM_KEY`, id).
- PWA: manifest, ícone e service worker que só guarda CSS e JS (páginas e dados sempre vêm da rede); tema claro e escuro.

Os textos de termos, privacidade e consentimento são **rascunhos** e precisam da revisão do advogado antes da publicação.

## Estrutura

```
public/       index.php (único ponto de entrada), assets, manifest, service worker
src/          Auth (cadastro, login, consentimento), Http (rotas, sessão, páginas), Mail, Support, Views
config/       bootstrap.php (autoload e .env)
migrations/   SQL numerado; aplicado sozinho no primeiro acesso depois de cada publicação
cron/         tarefas agendadas (limpeza diária)
bin/          migrar.php
tests/        rodar.php e casos/
storage/      logs, e-mails em modo log e cache (fora do Git)
```

Só `public/` é servido pelo navegador. O `.htaccess` da raiz manda tudo para `public/` e bloqueia `src/`, `config/`, `migrations/`, `cron/`, `bin/`, `tests/`, `storage/` e arquivos começados por ponto (como o `.env`); cada uma dessas pastas tem também o seu `.htaccess` com `Require all denied`.

## Rodar localmente

Requisitos: PHP 8.1+ com `pdo_pgsql` e `mbstring`, e PostgreSQL 12+.

```bash
createuser -P cidadania            # senha: cidadania
createdb -O cidadania cidadania
createdb -O cidadania cidadania_test

cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # cole em PSEUDONYM_KEY no .env
php bin/migrar.php
php -S 127.0.0.1:8000 -t public public/index.php
```

Com `MAIL_DRIVER=log`, os e-mails (confirmação e recuperação) são gravados em `storage/mail/`.

## Testes

```bash
CIDADANIA_TESTE_DB="pgsql://cidadania:cidadania@127.0.0.1:5432/cidadania_test" php tests/rodar.php
php tests/rodar.php cadastro     # só os testes com "cadastro" no nome
```

O schema `public` do banco de teste é apagado e recriado a cada execução. Com `CIDADANIA_TESTE_SCHEMA=cidadania`, os testes rodam nesse schema, como em produção no banco do SGI. No GitHub, os testes rodam em PHP 8.1 e 8.5 com PostgreSQL 16 a cada pull request.

## Publicar na Locaweb

1. **PHP.** No painel da hospedagem, confira se o site usa PHP 8.1 ou mais novo.
2. **Banco.** Crie um banco PostgreSQL no painel e anote host, porta, nome, usuário e senha.
3. **Pasta.** A publicação vai para `public_html/cidadania/` (o site fica em `https://www.boxtech.com.br/cidadania`). Para um subdomínio, crie-o no painel e aponte `LOCAWEB_PASTA` para a pasta dele.
4. **Publicação automática.** Em **Settings > Secrets and variables > Actions** do repositório, crie os secrets `LOCAWEB_FTP_SERVIDOR`, `LOCAWEB_FTP_USUARIO` e `LOCAWEB_FTP_SENHA` (e, se precisar, as variables `LOCAWEB_PASTA` e `LOCAWEB_FTP_PROTOCOLO`). A cada push no `main`, o workflow `publicar-locaweb.yml` roda os testes e, se passarem, envia os arquivos por FTP. Também dá para rodar à mão em **Actions > Publicar Box Cidadania na Locaweb > Run workflow**.
5. **Configuração.** Na pasta publicada, crie o `.env` a partir do `.env.example` (pelo gerenciador de arquivos ou FTP) com:
   - `APP_ENV=production` e `APP_URL` com o endereço do passo 3, sem barra no final;
   - os dados do banco do passo 2. Para usar o mesmo banco do SGI, repita host, porta, nome, usuário e senha dele e ponha `DB_SCHEMA=cidadania`: as tabelas do Box Cidadania ficam nesse schema, separadas das do SGI (que ficam no `public`);
   - `PSEUDONYM_KEY` com 64 caracteres aleatórios. **Guarde uma cópia segura e nunca troque**: sem ela os votos deixam de ser ligados às contas;
   - `MAIL_DRIVER=smtp` e os dados da conta de e-mail da Locaweb (`email-ssl.com.br`, porta 465, SSL).
   O `.env` nunca vai no repositório nem no FTP, e a publicação não o apaga.
6. **Primeiro acesso.** Abra o endereço: as tabelas e os dados de UFs e municípios são criados sozinhos. Depois de cada publicação, as migrations novas também são aplicadas no primeiro acesso.
7. **Cron.** Em **Tarefas agendadas** do painel, crie uma tarefa diária às 03:00 com `php /home/SEU_LOGIN/public_html/cidadania/cron/limpeza_diaria.php` (troque pelo caminho real que o FTP mostra). As coletas da pauta e dos resultados entram nas etapas 2 e 3.

O HTTPS já é forçado pelo nginx da Locaweb. Não ponha redirecionamento por `%{HTTPS}` no `.htaccess`: o Apache recebe a conexão já sem criptografia e a regra entra em laço.
