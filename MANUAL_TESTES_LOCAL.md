# Guia Rápido — Como Rodar e Testar o Chamô Localmente (MySQL)

Este manual explica o passo a passo para qualquer integrante da equipe baixar o projeto **Chamô**, conectar ao banco de dados **MySQL** local e testar todos os perfis do sistema.

---

## 1. Pré-requisitos

Certifique-se de ter instalado na máquina:
- **PHP 8.1 ou superior** com as extensões `pdo_mysql`, `mbstring`, `fileinfo` e `openssl` habilitadas.
  - *Windows:* XAMPP, WAMP, Laragon ou PHP oficial.
  - *macOS:* `brew install php`
- **MySQL 8+ (ou MariaDB)** rodando localmente na porta **`3306`**.
  - Pode ser via Docker, XAMPP, MySQL Server ou Laragon.
- **Git** instalado.

---

## 2. Baixar ou Atualizar o Projeto

Abra o terminal (PowerShell no Windows ou Terminal no macOS/Linux):

```bash
# Se ainda não tiver o projeto na máquina:
git clone https://github.com/o-paulo/chamo.git
cd chamo

# Se já tiver a pasta do projeto, apenas atualize:
cd chamo
git pull origin main
```

---

## 3. Configurar o Acesso ao MySQL

O projeto já vem pré-configurado no arquivo [`config.php`](./config.php) com os seguintes padrões:

- **Driver:** `mysql`
- **Host:** `127.0.0.1`
- **Porta:** `3306`
- **Banco de Dados:** `chamo`
- **Usuário:** `root`
- **Senha:** `""` *(em branco)*

> **Atenção:** Se o usuário `root` do seu MySQL local possuir uma senha configurada, abra o arquivo `config.php` (ou crie um arquivo `.env` na raiz baseando-se no `.env.example`) e preencha o campo `CHAMO_DB_PASSWORD`:
> ```php
> 'CHAMO_DB_PASSWORD' => 'sua_senha_aqui',
> ```

---

## 4. Inicializar o Banco de Dados e Iniciar o Site

Você não precisa importar arquivos `.sql` manualmente: na primeira conexão, o próprio sistema cria o banco `chamo`, as **11 tabelas** e semeia os **usuários e chamados de demonstração** automaticamente.

1. **(Opcional) Testar a conexão com o MySQL pelo terminal:**
   ```bash
   php scripts/init-mysql.php
   ```
   *Deve exibir a mensagem `=== Integração MySQL do Chamô concluída com sucesso ===` com a lista das 11 tabelas e contas criadas.*

2. **Subir o servidor local do site:**
   Execute dentro da pasta do projeto:
   ```bash
   php -d opcache.enable=0 -d upload_max_filesize=8M -d post_max_size=10M -S 127.0.0.1:8080 router.php
   ```

3. **Acessar no navegador:**
   Abra **[http://127.0.0.1:8080/TelaLogin.html](http://127.0.0.1:8080/TelaLogin.html)**

---

## 5. Contas de Demonstração para Testes

Use as credenciais abaixo na tela de login para testar cada visão do sistema:

| Perfil | E-mail de Acesso | Senha | O que pode testar |
| :--- | :--- | :--- | :--- |
| **Solicitante (Usuário)** | `demo@chamo.com` | `Chamo123!` | Abrir novos tickets (com anexo opcional), salvar rascunhos, acompanhar status, responder mensagens e avaliar chamados concluídos (1 a 5 estrelas). |
| **Atendente (Suporte)** | `atendente@chamo.com`<br>*(ou `atendente@chamo.sup.com`)* | `Atendente123!` | Visualizar fila de chamados sem atribuição, assumir tickets, responder solicitantes e concluir atendimentos. |
| **Administrador** | `admin@chamo.com`<br>*(ou `admin@chamo.adm.com`)* | `Admin123!` | Gerenciar todos os tickets, aprovar/desativar contas de usuários, criar novos usuários, configurar metas de SLA por gravidade e consultar a trilha de auditoria. |

> **Dica sobre criação de novas contas ("Criar conta" na tela de login):**
> O domínio do e-mail informado define automaticamente o perfil:
> - `@chamo.com` → **Solicitante** (acesso liberado imediatamente).
> - `@chamo.sup.com` → **Atendente** (fica pendente até um Administrador autorizar na aba *Cadastro/criação de usuários*).
> - `@chamo.adm.com` → **Administrador** (fica pendente até um Administrador autorizar).

---

## 6. Como Visualizar os Dados no Banco (DataGrip / MySQL Workbench / DBeaver)

1. Crie uma nova conexão **MySQL** na sua ferramenta de banco de dados:
   - **Host:** `127.0.0.1`
   - **Port:** `3306`
   - **User:** `root` (e a senha do seu MySQL local, se houver)
   - **Database / Schema:** `chamo`
2. *No DataGrip:* vá na aba **Schemas** das propriedades da conexão, marque a caixa **`chamo`** e pressione `Cmd + R` (macOS) ou `Ctrl + F5` (Windows) para atualizar e visualizar as 11 tabelas (`users`, `tickets`, `ticket_messages`, `ticket_attachments`, `ticket_ratings`, `ticket_drafts`, `app_settings`, `audit_log`, `login_attempts`, `mail_queue`, `password_reset_tokens`).
