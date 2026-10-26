# Hospedar o Chamô no IBM x3650 com Windows Server

## É possível?

Sim. Os dois Xeon, 12 GB de RAM ECC e discos SAS informados são suficientes para uma instalação pequena do portal, usando IIS, PHP FastCGI e MySQL ou SQLite. O hardware, porém, não resolve sozinho domínio, conexão pública, energia, refrigeração, atualizações e cópias externas de segurança.

### Atenção importante: suporte do Windows Server 2016

Em 19 de outubro de 2026, o Windows Server 2016 ainda está dentro do suporte estendido, mas a Microsoft lista **13 de janeiro de 2027** como o fim desse suporte. Como essa data está próxima, planeje migrar para uma versão do Windows Server ainda suportada e compatível com a geração do x3650 **antes de expor o servidor à Internet**. Depois do fim do suporte, não é recomendável manter um servidor web público nesse sistema sem uma cobertura de segurança apropriada.

Confirme também a geração exata do x3650, firmware, controladora SAS/RAID e compatibilidade com o sistema escolhido. Antes de alterar RAID, particionar ou formatar, confirme o backup dos dados: essas operações podem apagar discos. RAID não substitui backup.

Este guia adapta a hospedagem à instalação existente de **Windows Server 2016 + IIS**. Os passos assumem uma edição x64, PHP NTS x64 e um site pequeno. Se for possível atualizar o sistema operacional, faça isso primeiro e depois aplique o mesmo desenho de IIS/PHP.

## O que você precisa

- Acesso de administrador ao Windows Server e ao IIS.
- Um domínio que você controle.
- Um IP público, de preferência fixo. Se a operadora usar CGNAT ou bloquear portas de entrada, solicite um IP público ou contrate uma solução de túnel/proxy.
- Acesso administrativo ao roteador/firewall.
- Um certificado HTTPS válido para o domínio.
- Um provedor SMTP para e-mails de recuperação e avisos de tickets.
- Um local de backup fora da pasta pública e, idealmente, uma segunda cópia fora do próprio servidor.

O modelo “x3650” cobre várias gerações. Os 558 GB SAS informados podem ser capacidade bruta ou capacidade depois do RAID; confira o espaço útil e livre no Windows.

## Arquitetura

```text
Internet
  └── domínio e DNS público
      └── roteador/firewall: TCP 80 e 443
          └── IBM x3650 — Windows Server + IIS
              ├── IIS servindo os arquivos estáticos
              ├── PHP NTS x64 via FastCGI, executando apenas api.php
              ├── MySQL (banco de dados; fora da raiz pública)
              └── D:\ChamoData (anexos privados, fora da raiz pública)
```

Não exponha a Internet as portas de administração do Windows, RDP, SMB, MySQL ou qualquer porta do PHP. Restrinja RDP a uma VPN ou IP confiável e não deixe RDP aberto para qualquer origem.

## 1. Verificar rede e DNS antes de publicar

1. No roteador, verifique se o endereço WAN é realmente público. Compare-o com o endereço observado por um serviço público de consulta de IP. Se forem diferentes, pode haver CGNAT.
2. Pergunte à operadora se o IP é fixo e se aceita conexões de entrada nas portas TCP 80 e 443. Se houver CGNAT, peça um IP público ou use uma solução de túnel/reverse proxy.
3. Reserve um endereço **LAN** fixo para o servidor no roteador. Isso não é o mesmo que o IP público.
4. No painel do domínio, crie um registro `A` para `suporte.seudominio.com` apontando ao IP público. Só crie um registro `AAAA` se o IPv6 estiver configurado e testado de ponta a ponta.
5. No roteador, encaminhe somente TCP 80 e 443 para o endereço LAN do x3650. Faça a configuração com o administrador da rede; não compartilhe a senha do roteador.
6. Confirme que o relógio do servidor está correto e sincronizado. TLS e tokens de redefinição dependem de horário correto.

Se não houver domínio/IP público/portas acessíveis, não avance para exposição direta do IIS: o site poderá funcionar apenas dentro da rede local até que a conectividade seja resolvida.

## 2. Preparar Windows Server e IIS

1. Faça e teste uma cópia de segurança dos arquivos/dados existentes antes de mudar funções do servidor.
2. Instale todas as atualizações aplicáveis e reinicie. Como o suporte do Server 2016 termina em janeiro de 2027, planeje a atualização para uma versão suportada.
3. No **Server Manager > Add Roles and Features**, instale:
   - **Web Server (IIS)**;
   - **CGI** (necessário para FastCGI);
   - **Static Content**;
   - **Default Document**;
   - **Request Filtering**;
   - **IIS Management Console**.
4. No IIS Manager, crie um Application Pool chamado `ChamoPool`, com:
   - **.NET CLR Version: No Managed Code**;
   - **Managed pipeline mode: Integrated**;
   - identidade dedicada, se a administração do servidor permitir.
5. Crie a pasta pública `C:\Sites\Chamo` e a pasta privada `D:\ChamoData`. A pasta de dados não pode ficar dentro de `C:\Sites\Chamo`.
6. Em **IIS Manager > Sites > Add Website**, crie o site `Chamo`, aponte o **Physical path** para `C:\Sites\Chamo`, associe `ChamoPool` e configure binding HTTP temporário para o domínio.

## 3. Instalar PHP para IIS

1. Instale o **Microsoft Visual C++ Redistributable x64** exigido pela versão de PHP.
2. Baixe do site oficial [PHP for Windows](https://windows.php.net/download/) uma compilação **x64, NTS (Non Thread Safe)**. NTS é a variante apropriada para PHP via FastCGI/IIS. Não use o servidor de desenvolvimento embutido do PHP em produção.
3. Extraia o PHP, por exemplo, em `C:\PHP`. Copie `php.ini-production` para `php.ini`.
4. Edite `C:\PHP\php.ini` e configure, conferindo os nomes de DLL incluídos naquela compilação:

   ```ini
   extension_dir = "C:\PHP\ext"
   extension=curl
   extension=fileinfo
   extension=gd
   extension=mbstring
   extension=openssl
   extension=pdo_mysql
   date.timezone = America/Sao_Paulo
   cgi.force_redirect = 0
   fastcgi.impersonate = 1
   fastcgi.logging = 0
   session.use_strict_mode = 1
   session.use_only_cookies = 1
   upload_max_filesize = 8M
   post_max_size = 10M
   ```

   Se uma extensão estiver compilada no próprio PHP e não houver DLL correspondente em `ext`, use a configuração documentada para essa versão. Não habilite extensões que não estejam presentes.

5. Em PowerShell **como Administrador**, registre PHP FastCGI no IIS e confira a versão/extensões:

   ```powershell
   C:\Windows\System32\inetsrv\appcmd.exe set config /section:system.webServer/fastCgi /+"[fullPath='C:\PHP\php-cgi.exe']" /commit:apphost
   C:\PHP\php.exe -v
   C:\PHP\php.exe -m
   ```

   A lista precisa incluir `PDO`, `pdo_mysql`, `mbstring`, `fileinfo` e `openssl`. O nome da extensão pode aparecer com maiúsculas/minúsculas diferentes.

6. Se o handler FastCGI não aparecer no IIS, abra **IIS Manager > site Chamo > Handler Mappings > Add Module Mapping** e configure:
   - Request path: `api.php`;
   - Module: `FastCgiModule`;
   - Executable: `C:\PHP\php-cgi.exe`;
   - Name: `PHP API only`.

   **Não crie um mapeamento amplo para `*.php`.** O único arquivo PHP acessível pelo site deve ser `api.php`. O `web.config` do projeto restringe os handlers a essa API.

## 4. Copiar os arquivos e proteger o armazenamento

1. Copie os arquivos do projeto para `C:\Sites\Chamo`, usando uma conta administrativa/SFTP confiável. Não copie `storage\` nem dados de demonstração do computador de desenvolvimento.
2. Não coloque senhas, `.env`, banco de dados, anexos, backups ou chaves privadas dentro da pasta pública.
3. Crie pastas de dados e backup, por exemplo:

   ```powershell
   New-Item -ItemType Directory -Force -Path 'D:\ChamoData\uploads'
   New-Item -ItemType Directory -Force -Path 'E:\ChamoBackups'
   ```

   Se houver apenas um volume, use uma pasta separada, mas faça também uma cópia externa. Disco diferente no mesmo servidor não protege contra furto, incêndio ou falha do equipamento.

4. Identifique a identidade do `ChamoPool` no IIS. Dê a essa identidade permissão **Modify** apenas em `D:\ChamoData` e permissão **Read & Execute** em `C:\Sites\Chamo`. Conceda acesso de leitura ao `php-cgi.exe` e a `php.ini` conforme necessário. Não dê acesso de escrita da identidade do site a `C:\Sites\Chamo`.
5. Aplique o arquivo `web.config` do projeto em `C:\Sites\Chamo\web.config`. Ele configura `TelaLogin.html` como documento inicial, desabilita listagem de diretórios, bloqueia diretórios internos e configura somente o handler `api.php`. Se um módulo IIS necessário estiver ausente, instale-o pelo Server Manager e não remova filtros de segurança para “fazer funcionar”.

## 5. Configurar variáveis da aplicação

O arquivo `.env.example` é apenas uma lista de referência; o PHP do projeto **não** carrega arquivos `.env` automaticamente. Configure as variáveis no ambiente do `ChamoPool`/FastCGI por IIS, de forma que `getenv()` consiga lê-las, e mantenha-as fora da raiz pública.

Configure pelo menos:

```text
APP_ENV=production
CHAMO_DEMO_ACCOUNTS=0
CHAMO_FORCE_HTTPS=1
CHAMO_BASE_URL=https://suporte.seudominio.com
CHAMO_TIMEZONE=America/Sao_Paulo
CHAMO_BUSINESS_START_HOUR=9
CHAMO_BUSINESS_END_HOUR=18
CHAMO_DB_DRIVER=mysql
CHAMO_DB_HOST=127.0.0.1
CHAMO_DB_PORT=3306
CHAMO_DB_NAME=chamo
CHAMO_DB_USER=chamo_app
CHAMO_DB_PASSWORD=<senha privada do usuário MySQL>
CHAMO_STORAGE=D:\ChamoData
CHAMO_BACKUP_DIR=E:\ChamoBackups
CHAMO_BACKUP_RETENTION_DAYS=30
CHAMO_APP_KEY=<chave aleatória de 64 caracteres hexadecimais>
```

Gere uma chave no próprio servidor:

```powershell
$bytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $rng.GetBytes($bytes)
    [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
} finally {
    $rng.Dispose()
}
```

Copie o resultado diretamente para a configuração privada do App Pool. Não use o valor de exemplo, não o salve em arquivo na raiz do site e não o compartilhe por chat. Restrinja as permissões para que apenas Administradores, SYSTEM e os serviços que precisam dela possam ler a configuração.

Estas variáveis controlam os recursos principais do backend:

| Variável | Para que serve |
| --- | --- |
| `APP_ENV` | Define o ambiente; use `production` no servidor para desabilitar padrões de desenvolvimento. |
| `CHAMO_DEMO_ACCOUNTS` | Controla a criação das contas/tickets de demonstração; use `0` em produção. |
| `CHAMO_FORCE_HTTPS` | Marca o cookie de sessão como seguro; use `1` somente depois que HTTPS estiver funcionando. |
| `CHAMO_BASE_URL` | URL pública usada nos links de recuperação de senha e mensagens de e-mail. |
| `CHAMO_TIMEZONE` | Fuso horário usado para datas e cálculo de SLA. |
| `CHAMO_BUSINESS_START_HOUR` / `CHAMO_BUSINESS_END_HOUR` | Início e fim do expediente usado no cálculo de SLA, em horas inteiras de 0 a 23. |
| `CHAMO_DB_DRIVER` | Driver do banco: `sqlite` (padrão) ou `mysql`. |
| `CHAMO_DB_HOST`, `CHAMO_DB_PORT`, `CHAMO_DB_NAME`, `CHAMO_DB_USER`, `CHAMO_DB_PASSWORD` | Conexão privada MySQL; defina a senha apenas no ambiente protegido do App Pool/tarefas. |
| `CHAMO_STORAGE` | Diretório privado dos anexos e armazenamento local; nunca deve ficar dentro do site público. |
| `CHAMO_BACKUP_DIR` | Diretório absoluto, privado e separado para backups. |
| `CHAMO_BACKUP_RETENTION_DAYS` | Quantos dias manter os backups locais antes da limpeza automática. |
| `CHAMO_APP_KEY` | Segredo aleatório usado para proteger hashes de IP; mantenha estável e privado. |
| `CHAMO_SMTP_HOST`, `CHAMO_SMTP_PORT`, `CHAMO_SMTP_ENCRYPTION` | Servidor, porta e proteção TLS/SSL da entrega de e-mails. |
| `CHAMO_SMTP_USERNAME`, `CHAMO_SMTP_PASSWORD` | Credenciais SMTP, quando o provedor exigir autenticação. |
| `CHAMO_MAIL_FROM`, `CHAMO_MAIL_FROM_NAME` | Endereço e nome autorizados que aparecem como remetente. |

Depois de alterar o ambiente do App Pool, recicle `ChamoPool` ou reinicie o site no IIS. Em uma instalação limpa com `APP_ENV=production` e `CHAMO_DEMO_ACCOUNTS=0`, não serão criadas contas de demonstração.

### Preparar o banco MySQL

Crie um banco vazio `chamo` em MySQL 8 ou MariaDB compatível, com `utf8mb4`, e um usuário dedicado ao portal. Importe `banco_de_dados\chamo_mysql.sql` nesse banco, ou conceda temporariamente permissão `CREATE` ao usuário da aplicação para que a API crie as tabelas na primeira conexão. Depois da criação, o usuário precisa apenas de leitura e escrita nas tabelas. Configure `CHAMO_DB_DRIVER=mysql` e as demais variáveis de conexão acima.

Exemplo no cliente MySQL, substituindo o marcador por uma senha forte e exclusiva:

```sql
CREATE DATABASE IF NOT EXISTS chamo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chamo_app'@'localhost' IDENTIFIED BY '<senha-forte>';
GRANT SELECT, INSERT, UPDATE, DELETE ON chamo.* TO 'chamo_app'@'localhost';
```

Importe o arquivo de esquema com uma conta administrativa do MySQL para manter a conta da aplicação sem permissão de DDL:

```powershell
cmd.exe /c 'mysql -u root -p chamo < "C:\Sites\Chamo\banco_de_dados\chamo_mysql.sql"'
```

O arquivo recebido `banco_de_dados\bd_chamo.sql` foi mantido como referência, mas **não deve ser executado**: ele contém `DROP DATABASE IF EXISTS` e seu esquema não corresponde às funcionalidades atuais do portal. O esquema compatível está em `banco_de_dados\chamo_mysql.sql`; não apague nem substitua um banco existente para aplicá-lo.

## 6. Criar a primeira conta de administrador

Em um PowerShell elevado, defina as variáveis no processo, execute o script como a identidade de serviço do site (ou com a identidade administrativa temporária controlada) e remova a senha do ambiente logo depois. Exemplo para execução como administrador local:

```powershell
$env:APP_ENV = 'production'
$env:CHAMO_DEMO_ACCOUNTS = '0'
$env:CHAMO_STORAGE = 'D:\ChamoData'
$env:CHAMO_APP_KEY = '<a mesma chave configurada no App Pool>'
$env:CHAMO_ADMIN_NAME = 'Nome do administrador'
$env:CHAMO_ADMIN_EMAIL = 'admin@seudominio.com'
$securePassword = Read-Host 'Senha inicial (mínimo 12 caracteres)' -AsSecureString
$env:CHAMO_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $securePassword).Password
Set-Location 'C:\Sites\Chamo'
& 'C:\PHP\php.exe' 'C:\Sites\Chamo\scripts\create-admin.php'
Remove-Item Env:CHAMO_ADMIN_PASSWORD, Env:CHAMO_ADMIN_EMAIL, Env:CHAMO_ADMIN_NAME, Env:CHAMO_APP_KEY
```

Use uma senha longa, exclusiva, e guarde-a em um gerenciador de senhas. A conta que executa o script precisa ter acesso ao `D:\ChamoData`. Se importar um banco que já contenha contas de demonstração, crie primeiro uma conta administrativa própria, confirme o login e só então desative as contas de demonstração com `scripts\disable-demo-accounts.php`.

## 7. Configurar HTTPS

1. Primeiro, valide o acesso HTTP ao domínio e confirme que a porta TCP 80 chega ao IIS.
2. Instale uma ferramenta confiável para certificados ACME no Windows, como [win-acme](https://www.win-acme.com/), ou importe para o IIS um certificado TLS válido emitido para o seu domínio.
3. Em **IIS Manager > Sites > Chamo > Bindings**, adicione um binding HTTPS na porta 443 com o certificado correto e SNI habilitado se outros sites compartilharem o mesmo IP.
4. Teste renovação automática e monitore a data de expiração do certificado.
5. Configure o redirecionamento HTTP→HTTPS usando uma regra IIS/URL Rewrite administrada, se esse módulo estiver instalado. Se preferir não instalar URL Rewrite, remova o binding HTTP após emitir/validar o certificado e deixe somente HTTPS.
6. Só depois de HTTPS funcionar mantenha `CHAMO_FORCE_HTTPS=1`, pois essa opção marca o cookie de sessão como `Secure`.

## 8. Firewall, roteador e segurança de acesso

- No Windows Defender Firewall e no roteador, permita ao público apenas TCP 80/443 (ou apenas 443 depois de completar o certificado/renovação, se sua configuração permitir).
- Restrinja RDP a uma VPN ou IP administrativo confiável; nunca encaminhe RDP para a Internet inteira.
- Não exponha SMB, WinRM, portas do SQL/MySQL, painel de gerenciamento IBM, PHP FastCGI ou banco.
- Ative atualizações de segurança, proteção antimalware e sincronização de horário.
- Habilite logs do IIS e revise falhas de login, respostas 4xx/5xx e espaço em disco.
- Não use a conta padrão `Administrator` para a operação diária nem compartilhe uma senha única entre pessoas.

## 9. Configurar SMTP e tarefas periódicas

O backend põe e-mails de recuperação e de atualização de tickets em uma fila do banco configurado. Para enviá-los, configure no ambiente do App Pool e das tarefas:

```text
CHAMO_SMTP_HOST=<host do provedor>
CHAMO_SMTP_PORT=587
CHAMO_SMTP_ENCRYPTION=tls
CHAMO_SMTP_USERNAME=<usuário SMTP>
CHAMO_SMTP_PASSWORD=<senha SMTP>
CHAMO_MAIL_FROM=nao-responda@seudominio.com
CHAMO_MAIL_FROM_NAME=Chamô
```

Use TLS/STARTTLS e um remetente autorizado pelo domínio. Ainda é necessário escolher/contratar o serviço SMTP.

Para instalar a biblioteca PHPMailer, copie o projeto para o servidor, instale o Composer para Windows e execute em `C:\Sites\Chamo`:

```powershell
composer install --no-dev --prefer-dist --classmap-authoritative
```

Mantenha o `composer.lock` gerado junto ao código e impeça seu download pelo site. Configure o **Windows Task Scheduler** para executar a cada minuto:

```text
Program: C:\PHP\php.exe
Arguments: C:\Sites\Chamo\scripts\mail-worker.php
Start in: C:\Sites\Chamo
```

Configure as variáveis SMTP para a tarefa sem colocar a senha nos argumentos do processo ou em uma pasta pública. Use uma conta de tarefa dedicada, com leitura no código e escrita apenas em `D:\ChamoData`. Ative “Run whether user is logged on or not”, habilite histórico da tarefa e configure tentativas/resultado em caso de falha. Teste com uma redefinição de senha; o link enviado deve usar o domínio HTTPS correto.

## 10. Backups e monitoramento no Windows

O script `scripts\backup.php` cria uma cópia consistente do banco configurado (arquivo `.sqlite` para SQLite ou `.sql` para MySQL) e um arquivo compactado dos anexos. Configure uma tarefa diária no Windows Task Scheduler:

```text
Program: C:\PHP\php.exe
Arguments: C:\Sites\Chamo\scripts\backup.php
Start in: C:\Sites\Chamo
```

O processo precisa ler `D:\ChamoData` e escrever em `E:\ChamoBackups`. Configure `CHAMO_BACKUP_RETENTION_DAYS=30`, valide que os arquivos gerados incluem banco e anexos e confira o histórico/log de saída da tarefa. Se o script reportar que `CHAMO_BACKUP_DIR` precisa ser absoluto, use um caminho absoluto Windows como `E:\ChamoBackups`.

Mantenha pelo menos uma cópia criptografada fora do servidor (por exemplo, em outro equipamento/localidade) e teste regularmente a restauração em um ambiente isolado e vazio. Para MySQL, restaure o `.sql` no banco de teste antes de extrair os anexos. RAID, fonte redundante e uma pasta em outro volume do mesmo x3650 não substituem backup externo.

Configure um monitor externo para consultar:

```text
https://suporte.seudominio.com/api.php?action=health
```

Deve responder `{"ok":true,"service":"chamo"}`. Monitore também certificado TLS, serviços IIS, espaço em disco, saúde/temperatura do hardware, execuções das tarefas de e-mail/backup e logs do Windows.

## 11. Validar antes de anunciar o site

Faça os testes de uma rede externa, como um celular com Wi-Fi desligado:

1. `https://suporte.seudominio.com/` abre a tela de login com certificado válido.
2. `https://suporte.seudominio.com/api.php?action=health` responde corretamente.
3. `https://suporte.seudominio.com/storage/`, `/scripts/`, `/vendor/`, `/tests/`, `/deploy/`, `/banco_de_dados/chamo_mysql.sql`, `/.env.example`, `composer.json`, `README.md` e `api.php` sem `action` não mostram arquivos nem código PHP.
4. Um solicitante não abre a área administrativa nem acessa ticket de outra conta.
5. Criação de ticket, anexos, mensagem, conclusão, gestão de contas e SLA funcionam.
6. SMTP envia recuperação de senha; tokens expiram e só podem ser usados uma vez.
7. A tarefa de backup produz os arquivos esperados e uma cópia pode ser restaurada.
8. Tente entrar depois de desativar uma conta de teste; o acesso deve ser recusado.

## Limites e decisões restantes

- O Windows Server 2016 chega ao fim do suporte estendido em **13/01/2027**. Planeje sua atualização antes de colocar o portal público ou, no mínimo, antes dessa data.
- Este README documenta a instalação; ele não consegue configurar seu roteador, DNS, domínio, certificado ou SMTP sem os acessos administrativos correspondentes.
- A hospedagem local depende da operadora, energia e refrigeração. Use nobreak e considere custos de energia/ruído e indisponibilidade, especialmente para um serviço crítico.
- SQLite segue disponível para desenvolvimento local. Em produção, MySQL permite usar um serviço de banco dedicado; mantenha-o em rede privada e não exponha a porta 3306 à Internet.
- Tickets/anexos não são apagados automaticamente. Defina a política de retenção conforme as regras da organização e a legislação aplicável.
- Os rascunhos legados do navegador são migrados quando o usuário entra e acessa Meus Tickets/novo ticket. Preferências visuais permanecem locais. Não copie tickets de demonstração como dados reais.
- Configurar criação de conta pública, convites, MFA, verificação de e-mail ou papéis personalizados ainda exige decisões adicionais.

Para detalhes da API e do backend, consulte [BACKEND.md](./BACKEND.md).

## Fontes oficiais e documentação

- [Ciclo de vida do Windows Server 2016 — Microsoft](https://learn.microsoft.com/en-us/lifecycle/products/windows-server-2016)
- [Instalar/configurar PHP no IIS com FastCGI — Microsoft IIS](https://learn.microsoft.com/en-us/iis/application-frameworks/install-and-configure-php-on-iis/)
- [FastCGI no IIS — Microsoft](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/fastcgi/)
- [Hidden Segments / Request Filtering — Microsoft](https://learn.microsoft.com/en-us/iis/configuration/system.webserver/security/requestfiltering/hiddensegments/)
- [Builds oficiais do PHP para Windows](https://windows.php.net/download/)
- [win-acme — certificados ACME para Windows/IIS](https://www.win-acme.com/)
