<?php
declare(strict_types=1);

const STORAGE_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'storage';

function storage_directory(): string
{
    $configured = getenv('CHAMO_STORAGE');
    return $configured !== false && trim($configured) !== ''
        ? rtrim($configured, DIRECTORY_SEPARATOR)
        : STORAGE_DIR;
}

function database_file(): string
{
    return storage_directory() . DIRECTORY_SEPARATOR . 'chamo.sqlite';
}

function database_driver(): string
{
    $driver = strtolower(trim((string) (getenv('CHAMO_DB_DRIVER') ?: 'sqlite')));
    if (!in_array($driver, ['sqlite', 'mysql'], true)) {
        throw new RuntimeException('CHAMO_DB_DRIVER deve ser sqlite ou mysql.');
    }
    return $driver;
}

function upload_directory(): string
{
    return storage_directory() . DIRECTORY_SEPARATOR . 'uploads';
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status): never
{
    respond(['error' => $message], $status);
}

function is_unique_constraint_violation(PDOException $error): bool
{
    return str_contains(strtolower($error->getMessage()), 'unique')
        || ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062);
}

function database(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    foreach ([storage_directory(), upload_directory()] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento do backend.');
        }
    }
    $storageRules = storage_directory() . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($storageRules) && file_put_contents(
        $storageRules,
        "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
    ) === false) {
        throw new RuntimeException('Não foi possível proteger o diretório privado do backend.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (database_driver() === 'mysql') {
        $host = trim((string) (getenv('CHAMO_DB_HOST') ?: '127.0.0.1'));
        $port = filter_var(getenv('CHAMO_DB_PORT') ?: '3306', FILTER_VALIDATE_INT);
        $name = trim((string) (getenv('CHAMO_DB_NAME') ?: ''));
        $username = trim((string) (getenv('CHAMO_DB_USER') ?: ''));
        $password = getenv('CHAMO_DB_PASSWORD');
        $password = is_string($password) ? $password : '';
        if (!preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)
            || $port === false || $port < 1 || $port > 65535
            || !preg_match('/\A[a-zA-Z0-9_]+\z/', $name) || $username === '') {
            throw new RuntimeException('Configure CHAMO_DB_HOST, CHAMO_DB_PORT, CHAMO_DB_NAME e CHAMO_DB_USER para MySQL.');
        }
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $username,
            $password,
            $options
        );
        $expectedTables = [
            'users', 'tickets', 'ticket_messages', 'ticket_attachments', 'ticket_ratings',
            'login_attempts', 'password_reset_tokens', 'audit_log', 'mail_queue',
            'app_settings', 'ticket_drafts',
        ];
        $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($expectedTables, $existingTables) !== []) {
            $schemaPath = __DIR__ . DIRECTORY_SEPARATOR . 'banco_de_dados'
                . DIRECTORY_SEPARATOR . 'chamo_mysql.sql';
            $schema = file_get_contents($schemaPath);
            if ($schema === false) {
                throw new RuntimeException('Não foi possível ler o esquema MySQL do portal.');
            }
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
                $pdo->exec($statement);
            }
        }
        foreach (['very_high' => '2', 'high' => '4', 'medium' => '8', 'low' => '24'] as $priority => $hours) {
            $setting = $pdo->prepare('INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
            $setting->execute(['sla_first_response_' . $priority, $hours]);
        }
        seed_database($pdo);
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . database_file(), options: $options);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ("user", "attendant", "admin")),
            password_hash TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT "",
            photo_data_url TEXT NOT NULL DEFAULT "",
            is_active INTEGER NOT NULL DEFAULT 1,
            session_version INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_no TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL REFERENCES users(id),
            title TEXT NOT NULL,
            error_type TEXT NOT NULL,
            department TEXT NOT NULL,
            category TEXT NOT NULL,
            classification TEXT NOT NULL,
            severity TEXT NOT NULL,
            priority TEXT NOT NULL CHECK (priority IN ("low", "medium", "high")),
            description TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ("unassigned", "progress", "waiting", "resolved")),
            assigned_to INTEGER REFERENCES users(id),
            resolved_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            first_response_at TEXT,
            resolved_at TEXT
        );
        CREATE TABLE IF NOT EXISTS ticket_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
            sender_id INTEGER NOT NULL REFERENCES users(id),
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ticket_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
            file_name TEXT NOT NULL,
            stored_name TEXT NOT NULL UNIQUE,
            mime_type TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ticket_ratings (
            ticket_id INTEGER PRIMARY KEY REFERENCES tickets(id) ON DELETE CASCADE,
            requester_id INTEGER NOT NULL REFERENCES users(id),
            attendant_id INTEGER NOT NULL REFERENCES users(id),
            rating REAL NOT NULL CHECK (rating >= 1 AND rating <= 5 AND rating * 2 = CAST(rating * 2 AS INTEGER)),
            comment TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            succeeded INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_login_attempts_window ON login_attempts(email, ip_hash, created_at);
        CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id TEXT NOT NULL,
            details TEXT NOT NULL DEFAULT "{}",
            ip_hash TEXT NOT NULL DEFAULT "",
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at DESC);
        CREATE TABLE IF NOT EXISTS mail_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient TEXT NOT NULL,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            sent_at TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT ""
        );
        CREATE INDEX IF NOT EXISTS idx_mail_queue_pending ON mail_queue(sent_at, attempts, id);
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS ticket_drafts (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            values_json TEXT NOT NULL,
            saved_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ticket_drafts_user ON ticket_drafts(user_id, updated_at DESC);
        CREATE INDEX IF NOT EXISTS idx_tickets_user_created ON tickets(user_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_tickets_status_assignee ON tickets(status, assigned_to);
        CREATE INDEX IF NOT EXISTS idx_messages_ticket_created ON ticket_messages(ticket_id, created_at)');

    $userColumns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_active', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('session_version', $userColumns, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
    }
    $ticketColumns = $pdo->query('PRAGMA table_info(tickets)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('first_response_at', $ticketColumns, true)) {
        $pdo->exec('ALTER TABLE tickets ADD COLUMN first_response_at TEXT');
    }
    foreach (['very_high' => '2', 'high' => '4', 'medium' => '8', 'low' => '24'] as $priority => $hours) {
        $setting = $pdo->prepare('INSERT OR IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        $setting->execute(['sla_first_response_' . $priority, $hours]);
    }
    seed_database($pdo);
    return $pdo;
}

function is_absolute_path(string $path): bool
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1;
    }
    return str_starts_with($path, DIRECTORY_SEPARATOR);
}

function seed_database(PDO $pdo): void
{
    $demoAccountsEnabled = getenv('CHAMO_DEMO_ACCOUNTS');
    if ($demoAccountsEnabled === false) {
        $demoAccountsEnabled = getenv('APP_ENV') === 'production' ? '0' : '1';
    }
    if ($demoAccountsEnabled === '1' && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $accounts = [
            ['demo@chamo.com', 'Arthur Holanda', 'user', 'Chamo123!', '5511990000001'],
            ['atendente@chamo.sup.com', 'Alex Silva', 'attendant', 'Atendente123!', '5511990000002'],
            ['admin@chamo.adm.com', 'Equipe Administrativa', 'admin', 'Admin123!', '5511990000003'],
        ];
        $insert = $pdo->prepare(
            'INSERT INTO users (email, name, role, password_hash, phone, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($accounts as [$email, $name, $role, $password, $phone]) {
            $insert->execute([$email, $name, $role, password_hash($password, PASSWORD_DEFAULT), $phone, now()]);
        }
    }

    if ($demoAccountsEnabled !== '1'
        || (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn() !== 0
        || (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'demo@chamo.com'")->fetchColumn() === 0) {
        return;
    }

    $userId = (int) $pdo->query("SELECT id FROM users WHERE email = 'demo@chamo.com'")->fetchColumn();
    $attendantId = (int) $pdo->query("SELECT id FROM users WHERE email = 'atendente@chamo.sup.com'")->fetchColumn();
    $examples = [
        ['CH-5101', 'Falha ao acessar o sistema de estoque', 'Software', 'Operações', 'Sistemas e Aplicações', 'Incidente', 'Alto', 'high', 'progress', 'O sistema exibe uma mensagem de erro ao entrar na área de estoque. Já tentei novamente e o problema continua.', 1],
        ['CH-5102', 'Acesso ao painel de relatórios', 'Acesso / Conta', 'Financeiro', 'Acesso e Permissões', 'Solicitação de serviço', 'Moderado', 'medium', 'waiting', 'Preciso consultar os relatórios mensais, mas meu perfil não mostra essa opção.', 2],
        ['CH-5103', 'Impressora do setor sem conexão', 'Hardware', 'Recursos Humanos', 'Equipamentos', 'Incidente', 'Alto', 'high', 'unassigned', 'A impressora compartilhada aparece como desconectada para toda a equipe do setor.', 0],
        ['CH-5104', 'Instalação de aplicativo de videoconferência', 'Software', 'Comercial', 'Solicitações', 'Solicitação de serviço', 'Baixo', 'low', 'unassigned', 'Solicito a instalação do aplicativo aprovado para reuniões remotas neste computador.', 3],
        ['CH-5105', 'Dúvida sobre o sistema de ponto', 'Software', 'Recursos Humanos', 'Sistemas e Aplicações', 'Dúvida', 'Moderado', 'medium', 'resolved', 'Preciso de orientação para consultar o espelho de ponto mensal.', 4],
    ];
    $insert = $pdo->prepare(
        'INSERT INTO tickets
         (ticket_no, user_id, title, error_type, department, category, classification, severity, priority, description, status, assigned_to, resolved_by, created_at, updated_at, resolved_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($examples as $example) {
        [$ticketNo, $title, $errorType, $department, $category, $classification, $severity, $priority, $status, $description, $daysAgo] = $example;
        $createdAt = (new DateTimeImmutable("-{$daysAgo} days"))->setTime(9, 0)->format(DATE_ATOM);
        $resolvedAt = $status === 'resolved' ? $createdAt : null;
        $insert->execute([
            $ticketNo, $userId, $title, $errorType, $department, $category, $classification,
            $severity, $priority, $description, $status,
            in_array($status, ['progress', 'waiting'], true) ? $attendantId : null,
            $status === 'resolved' ? $attendantId : null,
            $createdAt, $createdAt, $resolvedAt,
        ]);
    }
}

function now(): string
{
    return (new DateTimeImmutable())->format(DATE_ATOM);
}

function request_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash_hmac('sha256', $ip, (string) (getenv('CHAMO_APP_KEY') ?: session_name()));
}

function audit_event(PDO $pdo, ?int $actorId, string $action, string $targetType, string $targetId, array $details = []): void
{
    $statement = $pdo->prepare(
        'INSERT INTO audit_log (actor_id, action, target_type, target_id, details, ip_hash, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $actorId, $action, $targetType, $targetId,
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        request_ip_hash(), now(),
    ]);
}

function queue_email(PDO $pdo, string $recipient, string $subject, string $body): void
{
    $statement = $pdo->prepare(
        'INSERT INTO mail_queue (recipient, subject, body, created_at) VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$recipient, $subject, $body, now()]);
}

function queue_staff_notification(PDO $pdo, string $subject, string $body): void
{
    $recipients = $pdo->query(
        "SELECT email FROM users WHERE role IN ('attendant', 'admin') AND is_active = 1"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($recipients as $recipient) {
        queue_email($pdo, (string) $recipient, $subject, $body);
    }
}

function valid_password(string $password): bool
{
    return mb_strlen($password) >= 12 && strlen($password) <= 200;
}

function valid_signup_password(string $password): bool
{
    return preg_match('/\A(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9\s])[\x20-\x7E]{8,20}\z/D', $password) === 1;
}

function signup_role(string $email): ?string
{
    return match (true) {
        str_ends_with($email, '@chamo.com') => 'user',
        str_ends_with($email, '@chamo.sup.com') => 'attendant',
        str_ends_with($email, '@chamo.adm.com') => 'admin',
        default => null,
    };
}

function sla_target_hours(PDO $pdo, string $priority, string $severity): int
{
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $slaPriority = $severity === 'Muito alto' ? 'very_high' : $priority;
    $statement->execute(['sla_first_response_' . $slaPriority]);
    $hours = $statement->fetchColumn();
    if ($hours === false || (int) $hours < 1) {
        return match ($slaPriority) {
            'very_high' => 2,
            'high' => 4,
            'medium' => 8,
            default => 24,
        };
    }
    return (int) $hours;
}

function sla_due_at(string $createdAt, int $targetHours): string
{
    $timezoneName = getenv('CHAMO_TIMEZONE') ?: 'America/Sao_Paulo';
    $timezone = new DateTimeZone($timezoneName);
    $startHour = filter_var(getenv('CHAMO_BUSINESS_START_HOUR') ?: '9', FILTER_VALIDATE_INT);
    $endHour = filter_var(getenv('CHAMO_BUSINESS_END_HOUR') ?: '18', FILTER_VALIDATE_INT);
    if ($startHour === false || $endHour === false || $startHour < 0 || $endHour > 23 || $startHour >= $endHour) {
        throw new RuntimeException('Configure CHAMO_BUSINESS_START_HOUR e CHAMO_BUSINESS_END_HOUR corretamente.');
    }
    $cursor = (new DateTimeImmutable($createdAt))->setTimezone($timezone);
    $remaining = $targetHours * 3600;
    while ($remaining > 0) {
        if ((int) $cursor->format('N') >= 6) {
            $cursor = $cursor->modify('next monday')->setTime($startHour, 0);
            continue;
        }
        $dayStart = $cursor->setTime($startHour, 0);
        $dayEnd = $cursor->setTime($endHour, 0);
        if ($cursor < $dayStart) {
            $cursor = $dayStart;
        } elseif ($cursor >= $dayEnd) {
            $cursor = $cursor->modify('+1 day')->setTime($startHour, 0);
            continue;
        }
        $available = $dayEnd->getTimestamp() - $cursor->getTimestamp();
        $used = min($remaining, $available);
        $cursor = $cursor->modify('+' . $used . ' seconds');
        $remaining -= $used;
        if ($remaining > 0) {
            $cursor = $cursor->modify('+1 day')->setTime($startHour, 0);
        }
    }
    return $cursor->format(DATE_ATOM);
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $statement = database()->prepare(
        'SELECT id, email, name, role, phone, COALESCE(photo_data_url, \'\') AS photoDataUrl,
                is_active AS isActive, session_version AS sessionVersion
         FROM users WHERE id = ?'
    );
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch();
    if (!$user || !(int) $user['isActive']
        || !isset($_SESSION['session_version'])
        || (int) $_SESSION['session_version'] !== (int) $user['sessionVersion']) {
        $_SESSION = [];
        return null;
    }
    $_SESSION['last_activity'] = time();
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if (!$user) {
        fail('Sua sessão expirou. Entre novamente.', 401);
    }
    return $user;
}

function require_staff(array $user): void
{
    if (!in_array($user['role'], ['attendant', 'admin'], true)) {
        fail('Você não tem permissão para esta operação.', 403);
    }
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        if (!is_array($decoded)) {
            fail('O corpo da requisição não contém JSON válido.', 400);
        }
        return $decoded;
    }
    return $_POST;
}

function require_method(string $expected): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
        header('Allow: ' . $expected);
        fail('Método não permitido.', 405);
    }
}

function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        fail('A solicitação expirou. Atualize a página e tente novamente.', 403);
    }
}

function ticket_by_number(PDO $pdo, string $ticketNo): ?array
{
    $statement = $pdo->prepare(
        'SELECT t.*, u.name AS requester, u.email AS requester_email,
                a.id AS assigned_to_id, a.email AS assigned_to, a.name AS assigned_name,
                r.id AS resolved_by_id, r.email AS resolved_by
         FROM tickets t
         JOIN users u ON u.id = t.user_id
         LEFT JOIN users a ON a.id = t.assigned_to
         LEFT JOIN users r ON r.id = t.resolved_by
         WHERE t.ticket_no = ?'
    );
    $statement->execute([$ticketNo]);
    $ticket = $statement->fetch();
    return $ticket ?: null;
}

function can_access_ticket(array $user, array $ticket): bool
{
    return in_array($user['role'], ['attendant', 'admin'], true)
        || (int) $ticket['user_id'] === (int) $user['id'];
}

function ticket_details(PDO $pdo, array $ticket): array
{
    $messages = $pdo->prepare(
        'SELECT m.message AS text, m.created_at AS sentAt, u.name AS sender, u.role AS senderRole
         FROM ticket_messages m JOIN users u ON u.id = m.sender_id
         WHERE m.ticket_id = ? ORDER BY m.id'
    );
    $messages->execute([(int) $ticket['id']]);
    $attachments = $pdo->prepare(
        'SELECT id, file_name AS name, mime_type AS mimeType
         FROM ticket_attachments WHERE ticket_id = ? ORDER BY id'
    );
    $attachments->execute([(int) $ticket['id']]);
    $targetHours = sla_target_hours($pdo, (string) $ticket['priority'], (string) $ticket['severity']);
    $dueAt = sla_due_at((string) $ticket['created_at'], $targetHours);
    $ratingQuery = $pdo->prepare(
        'SELECT tr.rating AS stars, tr.comment, tr.created_at AS createdAt, u.name AS attendant
         FROM ticket_ratings tr JOIN users u ON u.id = tr.attendant_id
         WHERE tr.ticket_id = ?'
    );
    $ratingQuery->execute([(int) $ticket['id']]);
    $rating = $ratingQuery->fetch() ?: null;
    if ($rating !== null) {
        $rating['stars'] = (float) $rating['stars'];
    }

    return [
        'id' => $ticket['ticket_no'],
        'title' => $ticket['title'],
        'errorType' => $ticket['error_type'],
        'department' => $ticket['department'],
        'category' => $ticket['category'],
        'classification' => $ticket['classification'],
        'severity' => $ticket['severity'],
        'priority' => $ticket['severity'] === 'Muito alto' ? 'very_high' : $ticket['priority'],
        'description' => $ticket['description'],
        'status' => $ticket['status'],
        'requester' => $ticket['requester'],
        'requesterEmail' => $ticket['requester_email'],
        'assignedTo' => $ticket['assigned_to'] ?? '',
        'assignedName' => $ticket['assigned_name'] ?? '',
        'resolvedBy' => $ticket['resolved_by'] ?? '',
        'rating' => $rating,
        'createdAt' => $ticket['created_at'],
        'updatedAt' => $ticket['updated_at'],
        'resolvedAt' => $ticket['resolved_at'],
        'firstResponseAt' => $ticket['first_response_at'] ?? null,
        'sla' => [
            'firstResponseTargetHours' => $targetHours,
            'firstResponseDueAt' => $dueAt,
            'firstResponseBreached' => !empty($ticket['first_response_at'])
                ? (new DateTimeImmutable((string) $ticket['first_response_at'])) > new DateTimeImmutable($dueAt)
                : new DateTimeImmutable() > new DateTimeImmutable($dueAt),
        ],
        'messages' => $messages->fetchAll(),
        'attachments' => array_map(
            static fn(array $file): array => $file + [
                'url' => 'api.php?action=attachment&id=' . $file['id'],
            ],
            $attachments->fetchAll()
        ),
    ];
}

function ticket_list(PDO $pdo, array $user): array
{
    if (in_array($user['role'], ['attendant', 'admin'], true)) {
        $statement = $pdo->query('SELECT ticket_no FROM tickets ORDER BY created_at DESC');
    } else {
        $statement = $pdo->prepare('SELECT ticket_no FROM tickets WHERE user_id = ? ORDER BY created_at DESC');
        $statement->execute([(int) $user['id']]);
    }
    $tickets = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $ticketNo) {
        $ticket = ticket_by_number($pdo, (string) $ticketNo);
        if ($ticket) {
            $tickets[] = ticket_details($pdo, $ticket);
        }
    }
    return $tickets;
}

function send_ticket_attachment(PDO $pdo, array $user, int $attachmentId): never
{
    $statement = $pdo->prepare(
        'SELECT a.file_name, a.stored_name, a.mime_type, t.ticket_no
         FROM ticket_attachments a JOIN tickets t ON t.id = a.ticket_id WHERE a.id = ?'
    );
    $statement->execute([$attachmentId]);
    $file = $statement->fetch();
    $ticket = $file ? ticket_by_number($pdo, $file['ticket_no']) : null;
    if (!$file || !$ticket || !can_access_ticket($user, $ticket)) {
        fail('Anexo não encontrado.', 404);
    }
    $path = upload_directory() . DIRECTORY_SEPARATOR . basename($file['stored_name']);
    if (!is_file($path)) {
        fail('O arquivo anexado não está disponível.', 404);
    }
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($file['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function handle_request(): never
{
    $pdo = database();
    $action = (string) ($_GET['action'] ?? '');
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($action === 'health' && $method === 'GET') {
        respond(['ok' => true, 'service' => 'chamo']);
    }

    if ($action === 'register') {
        require_method('POST');
        $data = request_data();
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = signup_role($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || $role === null
            || $firstName === '' || mb_strlen($firstName) > 100
            || $lastName === '' || mb_strlen($lastName) > 100
            || preg_match('/\A[0-9]{8,15}\z/D', $phone) !== 1
            || !valid_signup_password($password)) {
            fail('Confira nome, sobrenome, e-mail (use @chamo.com, @chamo.sup.com ou @chamo.adm.com), celular e senha.', 422);
        }

        $isPending = $role !== 'user';
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO users (email, name, role, password_hash, phone, is_active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $email,
                $firstName . ' ' . $lastName,
                $role,
                password_hash($password, PASSWORD_DEFAULT),
                $phone,
                $isPending ? 0 : 1,
                now(),
            ]);
            $userId = (int) $pdo->lastInsertId();
            audit_event($pdo, $userId, $isPending ? 'signup_authorization_requested' : 'signup_completed', 'user', (string) $userId, ['role' => $role]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException && is_unique_constraint_violation($error)) {
                fail('Este e-mail já está cadastrado.', 409);
            }
            throw $error;
        }
        respond([
            'ok' => true,
            'requiresApproval' => $isPending,
            'message' => $isPending
                ? 'Solicitação enviada. Um administrador precisa autorizar o acesso antes do primeiro login.'
                : 'Cadastro concluído. Agora você já pode entrar com seu e-mail e senha.',
        ], 201);
    }

    if ($action === 'login') {
        require_method('POST');
        $data = request_data();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        if (strlen($email) > 254 || strlen($password) > 200) {
            fail('E-mail ou senha incorretos.', 401);
        }
        $ipHash = request_ip_hash();
        $attempts = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE created_at >= ? AND (email = ? OR ip_hash = ?)'
        );
        $attempts->execute([(new DateTimeImmutable('-15 minutes'))->format(DATE_ATOM), $email, $ipHash]);
        if ((int) $attempts->fetchColumn() >= 5) {
            fail('Muitas tentativas. Aguarde 15 minutos antes de tentar novamente.', 429);
        }
        $statement = $pdo->prepare('SELECT id, password_hash, is_active, session_version FROM users WHERE email = ?');
        $statement->execute([$email]);
        $account = $statement->fetch();
        if (!$account || !(int) $account['is_active'] || !password_verify($password, $account['password_hash'])) {
            $failed = $pdo->prepare(
                'INSERT INTO login_attempts (email, ip_hash, succeeded, created_at) VALUES (?, ?, 0, ?)'
            );
            $failed->execute([$email, $ipHash, now()]);
            audit_event($pdo, null, 'login_failed', 'account', hash('sha256', $email));
            fail('E-mail ou senha incorretos.', 401);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ? OR ip_hash = ?')->execute([$email, $ipHash]);
        $pdo->prepare("DELETE FROM login_attempts WHERE created_at < ?")->execute([(new DateTimeImmutable('-1 day'))->format(DATE_ATOM)]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['session_version'] = (int) $account['session_version'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        audit_event($pdo, (int) $account['id'], 'login_succeeded', 'user', (string) $account['id']);
        respond(['user' => current_user(), 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($action === 'password-reset-request') {
        require_method('POST');
        $data = request_data();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $ipHash = request_ip_hash();
        $attempts = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND created_at >= ?'
        );
        $attempts->execute([$ipHash, (new DateTimeImmutable('-15 minutes'))->format(DATE_ATOM)]);
        if ((int) $attempts->fetchColumn() >= 10) {
            fail('Muitas solicitações. Aguarde 15 minutos e tente novamente.', 429);
        }
        $pdo->prepare(
            'INSERT INTO login_attempts (email, ip_hash, succeeded, created_at) VALUES (?, ?, 0, ?)'
        )->execute(['reset:' . hash('sha256', $email), $ipHash, now()]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $statement = $pdo->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1');
            $statement->execute([$email]);
            $account = $statement->fetch();
            if ($account) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                    ->execute([now(), (int) $account['id']]);
                $pdo->prepare(
                    "UPDATE mail_queue SET sent_at = ?, body = '' WHERE recipient = ? AND subject = ? AND sent_at IS NULL"
                )->execute([now(), $email, 'Redefinição de senha do Chamô']);
                $pdo->prepare(
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                     VALUES (?, ?, ?, ?)'
                )->execute([
                    (int) $account['id'], hash('sha256', $token),
                    (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM), now(),
                ]);
                $url = '{{BASE_URL}}/RedefinirSenha.html?token=' . rawurlencode($token);
                queue_email(
                    $pdo,
                    $email,
                    'Redefinição de senha do Chamô',
                    "Olá {$account['name']},\n\nUse o link a seguir para definir uma nova senha (válido por 1 hora):\n{$url}\n\nSe você não solicitou a redefinição, ignore esta mensagem."
                );
                audit_event($pdo, null, 'password_reset_requested', 'user', (string) $account['id']);
            }
        }
        respond(['ok' => true, 'message' => 'Se o endereço estiver cadastrado, você receberá instruções para redefinir a senha.']);
    }

    if ($action === 'password-reset') {
        require_method('POST');
        $data = request_data();
        $token = (string) ($data['token'] ?? '');
        $password = (string) ($data['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || !valid_password($password)) {
            fail('O link ou a nova senha é inválido. Use uma senha com pelo menos 12 caracteres.', 422);
        }
        $statement = $pdo->prepare(
            'SELECT id, user_id FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'
        );
        $statement->execute([hash('sha256', $token), now()]);
        $reset = $statement->fetch();
        if (!$reset) {
            fail('Este link de redefinição expirou ou já foi utilizado.', 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
                ->execute([now(), (int) $reset['user_id']]);
            audit_event($pdo, (int) $reset['user_id'], 'password_reset_completed', 'user', (string) $reset['user_id']);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        respond(['ok' => true, 'message' => 'Senha alterada. Agora você pode entrar com a nova senha.']);
    }

    if ($action === 'session') {
        require_method('GET');
        $user = current_user();
        if (!$user) {
            fail('Não há uma sessão ativa.', 401);
        }
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        respond(['user' => $user, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($action === 'attachment') {
        require_method('GET');
        $user = require_user();
        send_ticket_attachment($pdo, $user, (int) ($_GET['id'] ?? 0));
    }

    if ($action === 'logout') {
        require_method('POST');
        $user = require_user();
        require_csrf();
        audit_event($pdo, (int) $user['id'], 'logout', 'user', (string) $user['id']);
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }

    $user = require_user();
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        require_csrf();
    }

    if ($action === 'drafts') {
        if ($method === 'GET') {
            $statement = $pdo->prepare(
                'SELECT id, values_json, saved_at AS savedAt FROM ticket_drafts
                 WHERE user_id = ? ORDER BY updated_at DESC'
            );
            $statement->execute([(int) $user['id']]);
            $drafts = array_map(static function (array $draft): array {
                return [
                    'id' => $draft['id'],
                    'values' => json_decode($draft['values_json'], true) ?: [],
                    'savedAt' => $draft['savedAt'],
                ];
            }, $statement->fetchAll());
            respond(['drafts' => $drafts]);
        }
        if ($method === 'POST') {
            $data = request_data();
            $id = (string) ($data['id'] ?? '');
            $values = $data['values'] ?? null;
            if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $id) || !is_array($values)
                || count($values) > 20) {
                fail('O rascunho está em formato inválido.', 422);
            }
            foreach ($values as $value) {
                if (!is_string($value) || mb_strlen($value) > 3000) {
                    fail('O rascunho contém campos inválidos ou muito grandes.', 422);
                }
            }
            $encoded = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false || strlen($encoded) > 20000) {
                fail('O rascunho excede o limite permitido.', 422);
            }
            $timestamp = now();
            if (database_driver() === 'mysql') {
                $pdo->beginTransaction();
                try {
                    $existingDraft = $pdo->prepare('SELECT user_id FROM ticket_drafts WHERE id = ? FOR UPDATE');
                    $existingDraft->execute([$id]);
                    $draftOwner = $existingDraft->fetchColumn();
                    if ($draftOwner !== false && (int) $draftOwner !== (int) $user['id']) {
                        $pdo->rollBack();
                        fail('Este rascunho não pode ser salvo nesta conta.', 409);
                    }
                    if ($draftOwner === false) {
                        $save = $pdo->prepare(
                            'INSERT INTO ticket_drafts (id, user_id, values_json, saved_at, updated_at)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                    } else {
                        $save = $pdo->prepare(
                            'UPDATE ticket_drafts SET values_json = ?, saved_at = ?, updated_at = ?
                             WHERE id = ? AND user_id = ?'
                        );
                    }
                    $save->execute($draftOwner === false
                        ? [$id, (int) $user['id'], $encoded, $timestamp, $timestamp]
                        : [$encoded, $timestamp, $timestamp, $id, (int) $user['id']]);
                    $pdo->commit();
                } catch (Throwable $error) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $error;
                }
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO ticket_drafts (id, user_id, values_json, saved_at, updated_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(id) DO UPDATE SET values_json = excluded.values_json,
                         saved_at = excluded.saved_at, updated_at = excluded.updated_at
                     WHERE ticket_drafts.user_id = excluded.user_id'
                );
                $save->execute([$id, (int) $user['id'], $encoded, $timestamp, $timestamp]);
                if ($save->rowCount() !== 1) {
                    fail('Este rascunho não pode ser salvo nesta conta.', 409);
                }
            }
            audit_event($pdo, (int) $user['id'], 'ticket_draft_saved', 'draft', $id);
            respond(['draft' => ['id' => $id, 'values' => $values, 'savedAt' => $timestamp]], 201);
        }
        if ($method === 'DELETE') {
            $id = (string) ($_GET['id'] ?? '');
            $delete = $pdo->prepare('DELETE FROM ticket_drafts WHERE id = ? AND user_id = ?');
            $delete->execute([$id, (int) $user['id']]);
            audit_event($pdo, (int) $user['id'], 'ticket_draft_deleted', 'draft', $id);
            respond(['ok' => true]);
        }
        header('Allow: GET, POST, DELETE');
        fail('Método não permitido.', 405);
    }

    if ($action === 'users') {
        if ($user['role'] !== 'admin') {
            fail('Você não tem permissão para administrar usuários.', 403);
        }
        if ($method === 'GET') {
            $users = $pdo->query(
                'SELECT id, email, name, role, phone, is_active AS isActive, created_at AS createdAt
                 FROM users ORDER BY name' . (database_driver() === 'sqlite' ? ' COLLATE NOCASE' : '')
            )->fetchAll();
            $audit = $pdo->query(
                'SELECT a.action, a.target_type AS targetType, a.target_id AS targetId,
                        a.details, a.created_at AS createdAt, u.name AS actor
                 FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
                 ORDER BY a.id DESC LIMIT 50'
            )->fetchAll();
            foreach ($audit as &$entry) {
                $entry['details'] = json_decode($entry['details'], true) ?: [];
            }
            unset($entry);
            $sla = [];
            foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings') as $setting) {
                if (str_starts_with($setting['setting_key'], 'sla_first_response_')) {
                    $sla[substr($setting['setting_key'], strlen('sla_first_response_'))] = (int) $setting['setting_value'];
                }
            }
            respond(['users' => $users, 'audit' => $audit, 'sla' => $sla]);
        }
        if ($method === 'POST') {
            $data = request_data();
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            $name = trim((string) ($data['name'] ?? ''));
            $role = (string) ($data['role'] ?? '');
            $password = (string) ($data['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
                || $name === '' || mb_strlen($name) > 100
                || !in_array($role, ['user', 'attendant', 'admin'], true)
                || !valid_password($password)) {
                fail('Confira nome, e-mail, perfil e senha (mínimo de 12 caracteres).', 422);
            }
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO users (email, name, role, password_hash, created_at)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $insert->execute([$email, $name, $role, password_hash($password, PASSWORD_DEFAULT), now()]);
            } catch (PDOException $error) {
                if (is_unique_constraint_violation($error)) {
                    fail('Este e-mail já está cadastrado.', 409);
                }
                throw $error;
            }
            $newId = (int) $pdo->lastInsertId();
            audit_event($pdo, (int) $user['id'], 'user_created', 'user', (string) $newId, ['role' => $role]);
            respond(['ok' => true], 201);
        }
        header('Allow: GET, POST');
        fail('Método não permitido.', 405);
    }

    if ($action === 'user' && $method === 'PUT') {
        if ($user['role'] !== 'admin') {
            fail('Você não tem permissão para administrar usuários.', 403);
        }
        $targetId = (int) ($_GET['id'] ?? 0);
        $data = request_data();
        $role = (string) ($data['role'] ?? '');
        $isActive = $data['isActive'] ?? null;
        if ($targetId < 1 || !in_array($role, ['user', 'attendant', 'admin'], true)
            || !in_array($isActive, [true, false, 0, 1, '0', '1'], true)) {
            fail('Os dados de usuário são inválidos.', 422);
        }
        if ($targetId === (int) $user['id'] && ($role !== 'admin' || !(bool) $isActive)) {
            fail('Você não pode remover ou desativar seu próprio acesso administrativo.', 409);
        }
        $existing = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
        $existing->execute([$targetId]);
        $target = $existing->fetch();
        if (!$target) {
            fail('Usuário não encontrado.', 404);
        }
        $active = (int) (bool) $isActive;
        if (($target['role'] === 'admin' && (int) $target['is_active'] === 1)
            && ($role !== 'admin' || $active === 0)) {
            $admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
            if ($admins <= 1) {
                fail('O sistema precisa manter pelo menos um administrador ativo.', 409);
            }
        }
        $pdo->prepare('UPDATE users SET role = ?, is_active = ? WHERE id = ?')
            ->execute([$role, $active, $targetId]);
        audit_event(
            $pdo,
            (int) $user['id'],
            'user_updated',
            'user',
            (string) $targetId,
            ['role' => $role, 'isActive' => (bool) $active]
        );
        respond(['ok' => true]);
    }

    if ($action === 'sla' && $method === 'PUT') {
        if ($user['role'] !== 'admin') {
            fail('Você não tem permissão para alterar o SLA.', 403);
        }
        $data = request_data();
        foreach (['very_high', 'high', 'medium', 'low'] as $priority) {
            $hours = filter_var($data[$priority] ?? null, FILTER_VALIDATE_INT);
            if ($hours === false || $hours < 1 || $hours > 240) {
                fail('O SLA deve ser um número inteiro entre 1 e 240 horas úteis.', 422);
            }
        }
        $statement = $pdo->prepare(database_driver() === 'mysql'
            ? 'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            : 'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value');
        foreach (['very_high', 'high', 'medium', 'low'] as $priority) {
            $statement->execute(['sla_first_response_' . $priority, (string) $data[$priority]]);
        }
        audit_event($pdo, (int) $user['id'], 'sla_updated', 'settings', 'sla', $data);
        respond(['ok' => true]);
    }

    if ($action === 'profile' && $method === 'GET') {
        respond(['profile' => $user]);
    }

    if ($action === 'profile' && $method === 'PUT') {
        $data = request_data();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $photo = (string) ($data['photoDataUrl'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            fail('Informe um endereço de e-mail válido.', 422);
        }
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($phone) > 40) {
            fail('Informe um nome válido e um telefone com até 40 caracteres.', 422);
        }
        if (strlen($photo) > 1400000 || ($photo !== '' && !preg_match('#^data:image/(png|jpeg|gif|webp);base64,#', $photo))) {
            fail('A foto do perfil não é válida ou excede 1 MB.', 422);
        }
        $photoBytes = '';
        if ($photo !== '') {
            $encoded = substr($photo, strpos($photo, ',') + 1);
            $photoBytes = base64_decode($encoded, true) ?: '';
            $image = $photoBytes !== '' ? @getimagesizefromstring($photoBytes) : false;
            $allowedMime = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
            if (!$image || !in_array($image['mime'], $allowedMime, true) || strlen($photoBytes) > 1024 * 1024) {
                fail('A foto precisa ser PNG, JPG, GIF ou WebP com no máximo 1 MB.', 422);
            }
        }

        $newSessionVersion = null;
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'UPDATE users SET email = ?, name = ?, phone = ?, photo_data_url = ? WHERE id = ?'
            );
            $statement->execute([$email, $name, $phone, $photo, (int) $user['id']]);
            if (!empty($data['newPassword'])) {
                $currentPassword = (string) ($data['currentPassword'] ?? '');
                $newPassword = (string) $data['newPassword'];
                $check = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $check->execute([(int) $user['id']]);
                $hash = (string) $check->fetchColumn();
                if (!password_verify($currentPassword, $hash)) {
                    throw new DomainException('A senha atual está incorreta.');
                }
                if (!valid_password($newPassword)) {
                    throw new DomainException('A nova senha deve ter pelo menos 12 caracteres.');
                }
                $change = $pdo->prepare('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?');
                $change->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);
                $version = $pdo->prepare('SELECT session_version FROM users WHERE id = ?');
                $version->execute([(int) $user['id']]);
                $newSessionVersion = (int) $version->fetchColumn();
                audit_event($pdo, (int) $user['id'], 'password_changed', 'user', (string) $user['id']);
            }
            $pdo->commit();
        } catch (DomainException $error) {
            $pdo->rollBack();
            fail($error->getMessage(), 422);
        } catch (PDOException $error) {
            $pdo->rollBack();
            if (is_unique_constraint_violation($error)) {
                fail('Este e-mail já está vinculado a outra conta.', 409);
            }
            throw $error;
        }
        if ($newSessionVersion !== null) {
            $_SESSION['session_version'] = $newSessionVersion;
        }
        audit_event($pdo, (int) $user['id'], 'profile_updated', 'user', (string) $user['id']);
        respond(['profile' => current_user()]);
    }

    if ($action === 'tickets' && $method === 'GET') {
        respond(['tickets' => ticket_list($pdo, $user)]);
    }

    if ($action === 'tickets' && $method === 'POST') {
        if ($user['role'] !== 'user') {
            fail('Somente solicitantes podem abrir tickets.', 403);
        }
        $data = request_data();
        $fields = [
            'title' => trim((string) ($data['title'] ?? '')),
            'errorType' => trim((string) ($data['errorType'] ?? '')),
            'department' => trim((string) ($data['department'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'classification' => trim((string) ($data['classification'] ?? '')),
            'severity' => trim((string) ($data['severity'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
        ];
        if ($fields['title'] === '' || mb_strlen($fields['title']) > 120
            || $fields['description'] === '' || mb_strlen($fields['description']) > 3000
            || in_array('', [$fields['errorType'], $fields['department'], $fields['category'], $fields['classification'], $fields['severity']], true)) {
            fail('Preencha todos os campos obrigatórios do ticket.', 422);
        }
        $allowedValues = [
            'errorType' => ['Hardware', 'Software', 'BIOS / Firmware', 'Rede / Internet', 'Acesso / Conta', 'Outro'],
            'department' => ['Financeiro', 'Operações', 'Comercial', 'Recursos Humanos'],
            'category' => ['Acesso e Permissões', 'Sistemas e Aplicações', 'Equipamentos', 'Solicitações', 'Outros'],
            'classification' => ['Incidente', 'Solicitação de serviço', 'Dúvida', 'Alteração'],
        ];
        foreach ($allowedValues as $field => $allowed) {
            if (!in_array($fields[$field], $allowed, true)) {
                fail('A classificação do ticket contém uma opção inválida.', 422);
            }
        }
        $priority = match ($fields['severity']) {
            'Baixo' => 'low',
            'Moderado' => 'medium',
            'Alto', 'Muito alto' => 'high',
            default => null,
        };
        if ($priority === null) {
            fail('O nível de gravidade informado não é válido.', 422);
        }

        $uploaded = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['attachment'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
                fail('O anexo não foi recebido corretamente ou excede 8 MB.', 422);
            }
            $image = @getimagesize($file['tmp_name']);
            $mime = $image['mime'] ?? '';
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                fail('O anexo deve ser uma imagem PNG, JPG, GIF ou WebP.', 422);
            }
            $uploaded = [
                'path' => $file['tmp_name'],
                'name' => basename((string) $file['name']),
                'mime' => $mime,
                'stored' => bin2hex(random_bytes(24)) . '.' . $allowed[$mime],
            ];
        }

        $ticketNo = 'CH-' . (string) random_int(100000, 999999);
        $createdAt = now();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO tickets
                 (ticket_no, user_id, title, error_type, department, category, classification, severity, priority, description, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "unassigned", ?, ?)'
            );
            $insert->execute([
                $ticketNo, (int) $user['id'], $fields['title'], $fields['errorType'],
                $fields['department'], $fields['category'], $fields['classification'],
                $fields['severity'], $priority, $fields['description'], $createdAt, $createdAt,
            ]);
            $ticketId = (int) $pdo->lastInsertId();
            if ($uploaded) {
                $target = upload_directory() . DIRECTORY_SEPARATOR . $uploaded['stored'];
                if (!move_uploaded_file($uploaded['path'], $target)) {
                    throw new RuntimeException('Não foi possível armazenar o anexo.');
                }
                $insertAttachment = $pdo->prepare(
                    'INSERT INTO ticket_attachments (ticket_id, file_name, stored_name, mime_type, created_at) VALUES (?, ?, ?, ?, ?)'
                );
                $insertAttachment->execute([$ticketId, $uploaded['name'], $uploaded['stored'], $uploaded['mime'], $createdAt]);
            }
            audit_event($pdo, (int) $user['id'], 'ticket_created', 'ticket', $ticketNo);
            queue_email(
                $pdo,
                (string) $user['email'],
                "Chamado {$ticketNo} recebido",
                "Olá {$user['name']},\n\nSeu chamado \"{$fields['title']}\" foi recebido e está registrado com o número {$ticketNo}."
            );
            queue_staff_notification(
                $pdo,
                "Novo chamado {$ticketNo}",
                "Um novo chamado foi aberto por {$user['name']} ({$user['email']}): {$fields['title']}."
            );
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($uploaded && isset($target) && is_file($target)) {
                unlink($target);
            }
            throw $error;
        }
        respond(['ticket' => ticket_details($pdo, ticket_by_number($pdo, $ticketNo)), 'ok' => true], 201);
    }

    $ticketNo = trim((string) ($_GET['id'] ?? ''));
    if (in_array($action, ['claim', 'message', 'resolve', 'rate'], true)) {
        if (!in_array($action, ['message', 'rate'], true)) {
            require_staff($user);
        }
        $ticket = ticket_by_number($pdo, $ticketNo);
        if (!$ticket) {
            fail('Ticket não encontrado.', 404);
        }
        if ($action === 'rate' && $method === 'POST') {
            if ($user['role'] !== 'user' || (int) $ticket['user_id'] !== (int) $user['id']) {
                fail('Você não tem acesso para avaliar este ticket.', 403);
            }
            if ($ticket['status'] !== 'resolved') {
                fail('Você só pode avaliar um ticket concluído.', 409);
            }
            $data = request_data();
            $rating = $data['rating'] ?? null;
            $comment = trim((string) ($data['comment'] ?? ''));
            if ((!is_int($rating) && !is_float($rating))
                || !is_finite((float) $rating)
                || $rating < 1 || $rating > 5
                || floor((float) $rating * 2) !== (float) $rating * 2
                || mb_strlen($comment) > 1000) {
                fail('Informe uma nota de 1 a 5 estrelas, em intervalos de meia estrela, e um comentário de até 1000 caracteres.', 422);
            }
            $attendantId = (int) ($ticket['assigned_to_id'] ?? $ticket['resolved_by_id'] ?? 0);
            if ($attendantId < 1) {
                fail('Não foi possível identificar o atendente deste ticket.', 409);
            }
            $insertRating = $pdo->prepare(database_driver() === 'mysql'
                ? 'INSERT IGNORE INTO ticket_ratings (ticket_id, requester_id, attendant_id, rating, comment, created_at)
                   VALUES (?, ?, ?, ?, ?, ?)'
                : 'INSERT INTO ticket_ratings (ticket_id, requester_id, attendant_id, rating, comment, created_at)
                   VALUES (?, ?, ?, ?, ?, ?)
                   ON CONFLICT(ticket_id) DO NOTHING');
            $insertRating->execute([
                (int) $ticket['id'], (int) $user['id'], $attendantId, (float) $rating, $comment, now(),
            ]);
            if ($insertRating->rowCount() !== 1) {
                fail('Este ticket já foi avaliado.', 409);
            }
            audit_event($pdo, (int) $user['id'], 'ticket_rated', 'ticket', $ticketNo, ['rating' => (float) $rating]);
            respond(['ticket' => ticket_details($pdo, ticket_by_number($pdo, $ticketNo))]);
        }
        if ($action === 'claim' && $method === 'POST') {
            $claim = $pdo->prepare(
                "UPDATE tickets SET assigned_to = ?, status = 'progress', updated_at = ?
                 WHERE ticket_no = ? AND status = 'unassigned' AND assigned_to IS NULL"
            );
            $claim->execute([(int) $user['id'], now(), $ticketNo]);
            if ($claim->rowCount() !== 1) {
                fail('Este ticket já foi atribuído. Atualize a lista.', 409);
            }
            audit_event($pdo, (int) $user['id'], 'ticket_claimed', 'ticket', $ticketNo);
            respond(['ticket' => ticket_details($pdo, ticket_by_number($pdo, $ticketNo))]);
        }
        if ($action === 'resolve' && $method === 'POST') {
            if ($ticket['status'] === 'resolved' || ($user['role'] !== 'admin' && (int) ($ticket['assigned_to_id'] ?? 0) !== (int) $user['id'])) {
                fail('Este ticket não está disponível para conclusão por esta conta.', 409);
            }
            $update = $pdo->prepare(
                "UPDATE tickets SET status = 'resolved', resolved_by = ?, resolved_at = ?, updated_at = ? WHERE ticket_no = ?"
            );
            $timestamp = now();
            $update->execute([(int) $user['id'], $timestamp, $timestamp, $ticketNo]);
            audit_event($pdo, (int) $user['id'], 'ticket_resolved', 'ticket', $ticketNo);
            queue_email(
                $pdo,
                (string) $ticket['requester_email'],
                "Chamado {$ticketNo} concluído",
                "Seu chamado \"{$ticket['title']}\" foi marcado como concluído. Entre no portal para consultar o histórico."
            );
            respond(['ticket' => ticket_details($pdo, ticket_by_number($pdo, $ticketNo))]);
        }
        if ($action === 'message' && $method === 'POST') {
            $data = request_data();
            $text = trim((string) ($data['message'] ?? ''));
            $isRequester = (int) $ticket['user_id'] === (int) $user['id'];
            if ($user['role'] === 'user' && !$isRequester) {
                fail('Você não tem acesso a este ticket.', 403);
            }
            if ($ticket['status'] === 'resolved' || $text === '' || mb_strlen($text) > 2000) {
                fail('O ticket está concluído ou a mensagem está vazia/inválida.', 422);
            }
            if ($user['role'] !== 'admin' && !$isRequester && (int) ($ticket['assigned_to_id'] ?? 0) !== (int) $user['id']) {
                fail('Este ticket não está atribuído à sua conta.', 403);
            }
            $pdo->beginTransaction();
            try {
                $message = $pdo->prepare('INSERT INTO ticket_messages (ticket_id, sender_id, message, created_at) VALUES (?, ?, ?, ?)');
                $message->execute([(int) $ticket['id'], (int) $user['id'], $text, now()]);
                $status = $isRequester
                    ? ($ticket['status'] === 'unassigned' ? 'unassigned' : 'progress')
                    : 'waiting';
                $messageAt = now();
                $update = $pdo->prepare(
                    'UPDATE tickets SET status = ?, updated_at = ?,
                     first_response_at = CASE WHEN ? = 1 THEN first_response_at ELSE COALESCE(first_response_at, ?) END
                     WHERE ticket_no = ?'
                );
                $update->execute([$status, $messageAt, $isRequester ? 1 : 0, $messageAt, $ticketNo]);
                audit_event($pdo, (int) $user['id'], 'ticket_message_added', 'ticket', $ticketNo);
                if ($isRequester) {
                    if (!empty($ticket['assigned_to'])) {
                        queue_email(
                            $pdo,
                            (string) $ticket['assigned_to'],
                            "Nova resposta em {$ticketNo}",
                            "O solicitante respondeu ao chamado \"{$ticket['title']}\". Acesse o portal para continuar o atendimento."
                        );
                    } else {
                        queue_staff_notification(
                            $pdo,
                            "Nova resposta em {$ticketNo}",
                            "O solicitante respondeu ao chamado \"{$ticket['title']}\" antes da atribuição."
                        );
                    }
                } else {
                    queue_email(
                        $pdo,
                        (string) $ticket['requester_email'],
                        "Nova mensagem em {$ticketNo}",
                        "A equipe de atendimento enviou uma mensagem no chamado \"{$ticket['title']}\". Entre no portal para consultar."
                    );
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            respond(['ticket' => ticket_details($pdo, ticket_by_number($pdo, $ticketNo))]);
        }
    }

    fail('Ação de API não encontrada.', 404);
}

if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => getenv('CHAMO_FORCE_HTTPS') === '1'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();

    try {
        handle_request();
    } catch (Throwable $error) {
        error_log('Chamô API: ' . $error->getMessage());
        fail('Ocorreu um erro interno. Consulte o log do servidor.', 500);
    }
}
