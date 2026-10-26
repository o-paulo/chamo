# Backend Chamô

Backend em PHP 8.1+ com SQLite ou MySQL (selecionável por configuração) e sessões PHP. O projeto inclui autenticação, perfis, tickets, anexos privados, gestão de usuários, rascunhos sincronizados, auditoria e fila de e-mails.

## Desenvolvimento local

Requisitos: PHP com `PDO_SQLite` e `SQLite3` para o padrão local, ou `PDO_MySQL` quando `CHAMO_DB_DRIVER=mysql`.

```powershell
php -d upload_max_filesize=8M -d post_max_size=10M -S localhost:8080 router.php
```

Abra `http://localhost:8080/`. Os dados locais ficam em `storage/`, que não deve ser publicada nem versionada.

Para usar MySQL, crie um banco vazio e importe `banco_de_dados/chamo_mysql.sql`. Configure `CHAMO_DB_DRIVER=mysql`, `CHAMO_DB_HOST`, `CHAMO_DB_PORT`, `CHAMO_DB_NAME`, `CHAMO_DB_USER` e `CHAMO_DB_PASSWORD` no ambiente PHP. A API também cria as tabelas na primeira conexão se a tabela `users` ainda não existir e o usuário MySQL tiver permissão `CREATE`. O `CHAMO_STORAGE` continua sendo usado para anexos mesmo com MySQL.

`banco_de_dados/bd_chamo.sql` é o esquema de referência recebido e não deve ser importado pelo portal: além de não corresponder ao modelo de dados do backend, contém `DROP DATABASE IF EXISTS`. O esquema implantável e compatível está em `banco_de_dados/chamo_mysql.sql`.

As contas e tickets de demonstração só são semeados fora de `APP_ENV=production` e quando `CHAMO_DEMO_ACCOUNTS=1` (ou não definido). Credenciais locais: `demo@chamo.com` / `Chamo123!`, `atendente@chamo.sup.com` / `Atendente123!`, `admin@chamo.adm.com` / `Admin123!`. Não reutilize essas contas/senhas em produção.

Solicitantes podem avaliar uma única vez cada ticket concluído, com nota de 1 a 5 estrelas em intervalos de meia estrela e comentário opcional de até 1000 caracteres. O SLA de primeira resposta é configurável em horas úteis por gravidade: muito alta (padrão 2h), alta (4h), média (8h) e baixa (24h).

## Funcionalidades implementadas

- Login, logout e sessão `HttpOnly`/`SameSite`, token CSRF, limite de tentativas (5 por IP ou conta em 15 minutos) e desativação de contas.
- Cadastro público: `@chamo.com` cria solicitantes ativos; `@chamo.sup.com` e `@chamo.adm.com` criam solicitações inativas que um administrador precisa autorizar na lista de usuários.
- Política de senha do cadastro público: 8 a 20 caracteres com letra maiúscula, minúscula, número e caractere especial. Alteração, redefinição e criação administrativa continuam exigindo pelo menos 12 caracteres.
- Recuperação de senha com token aleatório de uso único e validade de uma hora; a API não revela se o e-mail existe.
- Administração de contas (criar, alterar papel, ativar/desativar) e proteção contra desativar o último administrador.
- Auditoria de autenticação e ações sensíveis, sem armazenar senhas ou tokens de redefinição na trilha.
- Tickets, mensagens, atribuição, conclusão, anexos privados e e-mails de evento guardados em fila persistente.
- Rascunhos antigos do navegador são migrados para a conta autenticada na próxima visita a Meus Tickets ou ao formulário de novo ticket. Preferências de aparência continuam locais ao dispositivo.
- SLA de primeira resposta configurável por prioridade. Padrão inicial confirmado: alta 4h úteis, média 8h, baixa 24h. O calendário padrão é segunda a sexta, 09h–18h em `America/Sao_Paulo`; ajuste por `CHAMO_TIMEZONE`, `CHAMO_BUSINESS_START_HOUR` e `CHAMO_BUSINESS_END_HOUR`.
- Endpoint de saúde: `GET /api.php?action=health`.

## Preparar a VPS

O exemplo em `deploy/nginx-chamo.conf` assume Linux/Ubuntu, Nginx, PHP-FPM 8.3 e raiz do site em `/var/www/chamo`. Ajuste domínio, versão/socket do PHP e caminhos ao seu provedor. Não execute o servidor embutido do PHP em produção.

1. Aponte o DNS do domínio para a VPS, instale Nginx, PHP-FPM com `pdo_mysql` (ou `pdo_sqlite` e `sqlite3` para SQLite), `fileinfo`, `mbstring`, `openssl` e Composer. Publique os arquivos em `/var/www/chamo`.
2. Instale a dependência SMTP e gere um lockfile na VPS:

   ```sh
   cd /var/www/chamo
   composer install --no-dev --prefer-dist --classmap-authoritative
   ```

3. Crie diretórios privados fora da raiz web e conceda acesso apenas à conta do PHP:

   ```sh
   sudo install -d -o www-data -g www-data -m 700 /var/lib/chamo /var/backups/chamo
   sudo install -d -o root -g www-data -m 750 /etc/chamo
   ```

4. Copie `.env.example` para `/etc/chamo/chamo.env`, substitua domínio e valores. Gere uma chave de aplicação com `openssl rand -hex 32`; configure-a como `CHAMO_APP_KEY`. O arquivo de ambiente deve pertencer a root e ter permissão `0600`. **Não envie segredos pelo chat nem coloque o arquivo na raiz pública.**
5. Configure o serviço systemd PHP-FPM com o override em `deploy/php-fpm-systemd.override` (ajuste o nome do serviço à versão instalada) e acrescente ao pool as variáveis permitidas em `deploy/php-fpm-env.conf`. Mantenha `clear_env` habilitado e passe somente as variáveis da aplicação. A fila de e-mails e o backup também recebem o arquivo pelo systemd.
6. Configure `CHAMO_DB_DRIVER=mysql`, `CHAMO_DB_HOST`, `CHAMO_DB_PORT=3306`, `CHAMO_DB_NAME`, `CHAMO_DB_USER` e `CHAMO_DB_PASSWORD` para seu serviço MySQL privado; também configure `CHAMO_STORAGE=/var/lib/chamo`, `CHAMO_BACKUP_DIR=/var/backups/chamo`, `APP_ENV=production`, `CHAMO_DEMO_ACCOUNTS=0` e `CHAMO_FORCE_HTTPS=1`. `CHAMO_BASE_URL` deve ser a URL HTTPS final. Mantenha a porta do banco acessível somente pelo servidor da aplicação.
7. Emita o certificado TLS do domínio (por exemplo, com Certbot), ajuste o socket PHP-FPM no arquivo Nginx e valide com `nginx -t` antes de recarregar o serviço. O exemplo redireciona HTTP para HTTPS e bloqueia acesso a scripts privados, dependências, arquivos ocultos e banco.
8. Em banco vazio, crie o primeiro administrador usando variáveis temporárias `CHAMO_ADMIN_NAME`, `CHAMO_ADMIN_EMAIL` e `CHAMO_ADMIN_PASSWORD` (mínimo 12 caracteres) e execute `php scripts/create-admin.php`. Remova a variável de senha imediatamente. Em banco trazido do protótipo, crie primeiro uma conta administrativa própria e depois execute `php scripts/disable-demo-accounts.php` com `CHAMO_DISABLE_DEMO_ACCOUNTS=1`. O script desativa as contas de exemplo sem apagar tickets.
9. Ajuste permissões, reinicie PHP-FPM/Nginx e confirme `https://seu-dominio/api.php?action=health`, login e acesso negado a `/storage`, `/scripts` e arquivos ocultos.

## E-mail e fila

Configure `CHAMO_SMTP_HOST`, `CHAMO_SMTP_PORT`, `CHAMO_SMTP_ENCRYPTION=tls`, `CHAMO_SMTP_USERNAME`, `CHAMO_SMTP_PASSWORD`, `CHAMO_MAIL_FROM` e `CHAMO_MAIL_FROM_NAME` no arquivo privado de ambiente. TLS/STARTTLS é recomendado; não use `none` em produção. O remetente deve ser autorizado pelo seu domínio/provedor.

Ative o processamento periódico como `www-data`:

```sh
sudo install -m 644 deploy/chamo-mail-worker.service /etc/systemd/system/
sudo install -m 644 deploy/chamo-mail-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now chamo-mail-worker.timer
sudo systemctl list-timers chamo-mail-worker.timer
```

Falhas ficam registradas no journal e a fila tenta novamente até cinco vezes. Verifique `journalctl -u chamo-mail-worker.service`; use o formulário de recuperação para validar a entrega após configurar o provedor.

## Backup e restauração

O script `scripts/backup.php` faz snapshot consistente do banco configurado (`.sqlite` no SQLite, `.sql` no MySQL) e arquiva os anexos. Mantém 30 dias por padrão; ajuste `CHAMO_BACKUP_RETENTION_DAYS`. Ative o timer diário:

```sh
sudo install -m 644 deploy/chamo-backup.service /etc/systemd/system/
sudo install -m 644 deploy/chamo-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now chamo-backup.timer
sudo systemctl start chamo-backup.service
sudo journalctl -u chamo-backup.service
```

Os arquivos locais em `/var/backups/chamo` não substituem cópia externa. Configure replicação criptografada para outro servidor/conta e teste periodicamente a restauração do `.sqlite` ou `.sql` e do `.tar.gz` em banco e armazenamento isolados. Nunca sobrescreva o armazenamento ativo durante um teste.

## Operação, política e dados

- Monitoramento: configure um monitor externo para consultar `/api.php?action=health` por HTTPS e alertar em falha; observe os serviços Nginx/PHP-FPM, espaço em disco e logs do worker/backup.
- Retenção de tickets e anexos: não há exclusão automática. Defina o prazo conforme a política da organização e requisitos legais antes de automatizar expurgo. A retenção de 30 dias acima vale somente para cópias locais de backup.
- Categorias e prioridades atuais são as opções do formulário; confirme-as com a equipe antes de ampliá-las.
- Rascunhos legados são importados automaticamente do navegador e associados à conta que estiver autenticada; mantenha o navegador antigo e não limpe os dados locais até conferir a migração. Preferências visuais permanecem por dispositivo. Tickets de demonstração não representam dados reais e não são migrados.
- SQLite segue disponível para desenvolvimento local. Em produção, mantenha MySQL em rede privada e não exponha sua porta à Internet.
- Não há verificação de e-mail, convite por link, papéis personalizados nem MFA. O cadastro público define o papel pelo domínio e exige autorização administrativa para atendentes e administradores.

## Verificações

```sh
php -l api.php
php -l router.php
php -l scripts/mail-worker.php
php tests/api-smoke.php
```

As verificações de integração usam diretório temporário e servidor local isolado; não alteram o banco persistente de desenvolvimento.
