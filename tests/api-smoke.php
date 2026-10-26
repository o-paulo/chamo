<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chamo-test-' . bin2hex(random_bytes(8));
$storage = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage';
if (!mkdir($temporaryRoot, 0700, true)) {
    throw new RuntimeException('Não foi possível criar o diretório temporário.');
}

putenv('CHAMO_STORAGE=' . $storage);
putenv('CHAMO_DB_DRIVER=sqlite');
putenv('CHAMO_DEMO_ACCOUNTS=1');
putenv('APP_ENV=testing');
putenv('CHAMO_TIMEZONE=America/Sao_Paulo');

$socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!$socket) {
    throw new RuntimeException("Não foi possível reservar uma porta local: {$errorMessage}");
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr((string) $address, ':'), 1);
$baseUrl = "http://127.0.0.1:{$port}";
$serverLogPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'server.log';
$pipes = [];
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", 'router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLogPath, 'ab'], 2 => ['file', $serverLogPath, 'ab']],
    $pipes,
    $projectRoot
);
if (!is_resource($process)) {
    throw new RuntimeException('Não foi possível iniciar o servidor PHP de teste.');
}
fclose($pipes[0]);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function request_api(string $baseUrl, string $action, string $method = 'GET', ?array $body = null, array &$session = [], ?string $id = null): array
{
    global $pipes, $process, $serverLogPath;

    $query = ['action' => $action];
    if ($id !== null) {
        $query['id'] = $id;
    }
    $headers = ['Accept: application/json'];
    if (isset($session['cookie'])) {
        $headers[] = 'Cookie: ' . $session['cookie'];
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if (!in_array($method, ['GET', 'HEAD'], true) && isset($session['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $session['csrf'];
    }
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ];
    error_clear_last();
    $response = @file_get_contents($baseUrl . '/api.php?' . http_build_query($query), false, stream_context_create($options));
    if (!is_string($response)) {
        $serverError = is_file($serverLogPath) ? file_get_contents($serverLogPath) : '';
        $serverStatus = is_resource($process ?? null) ? proc_get_status($process) : [];
        throw new RuntimeException(
            'Falha de transporte HTTP: ' . (error_get_last()['message'] ?? 'desconhecida')
            . '; processo do servidor: ' . json_encode($serverStatus)
            . '; stderr: ' . trim((string) $serverError)
        );
    }
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
        $status = (int) $matches[1];
    }
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Set-Cookie: PHPSESSID=') === 0
            && preg_match('/PHPSESSID=[^;]+/', $header, $matches)) {
            $session['cookie'] = $matches[0];
        }
    }
    $data = json_decode($response, true);
    if (isset($data['csrfToken'])) {
        $session['csrf'] = $data['csrfToken'];
    }
    return [$status, is_array($data) ? $data : [], $responseHeaders];
}

function login_api(string $baseUrl, string $email, string $password): array
{
    $session = [];
    [$status, $data] = request_api($baseUrl, 'login', 'POST', ['email' => $email, 'password' => $password], $session);
    return [$status, $data, $session];
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? remove_tree($child) : unlink($child);
    }
    rmdir($path);
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $health = @file_get_contents($baseUrl . '/api.php?action=health');
        if (is_string($health) && str_contains($health, '"ok":true')) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'O endpoint de saúde não iniciou.');

    $schemaResponse = file_get_contents(
        $baseUrl . '/banco_de_dados/chamo_mysql.sql',
        false,
        stream_context_create(['http' => ['ignore_errors' => true]])
    );
    check(
        is_string($schemaResponse)
            && isset($http_response_header[0])
            && str_contains($http_response_header[0], '404'),
        'O esquema SQL não deve ser servido publicamente.'
    );

    [$status, $requester, $requesterSession] = login_api($baseUrl, 'demo@chamo.com', 'Chamo123!');
    check($status === 200 && ($requester['user']['role'] ?? '') === 'user', 'Login de solicitante falhou.');
    [$status] = request_api($baseUrl, 'users', session: $requesterSession);
    check($status === 403, 'Solicitante não foi bloqueado da administração.');
    [$status] = request_api(
        $baseUrl,
        'profile',
        'PUT',
        [
            'name' => $requester['user']['name'],
            'email' => $requester['user']['email'],
            'phone' => $requester['user']['phone'],
            'photoDataUrl' => '',
        ],
        $requesterSession
    );
    check($status === 200, 'Salvar perfil no servidor falhou.');

    [$status, $created] = request_api(
        $baseUrl,
        'tickets',
        'POST',
        [
            'title' => 'Teste automatizado de SLA',
            'errorType' => 'Software',
            'department' => 'Operações',
            'category' => 'Sistemas e Aplicações',
            'classification' => 'Incidente',
            'severity' => 'Alto',
            'description' => 'Ticket de teste da API.',
        ],
        $requesterSession
    );
    $ticketNo = $created['ticket']['id'] ?? '';
    check($status === 201 && $ticketNo !== '', 'Criação do ticket falhou.');
    check(($created['ticket']['sla']['firstResponseTargetHours'] ?? 0) === 4, 'Meta inicial do SLA não foi aplicada.');
    [$status, $veryHighTicket] = request_api(
        $baseUrl,
        'tickets',
        'POST',
        [
            'title' => 'Teste automatizado de prioridade muito alta',
            'errorType' => 'Software',
            'department' => 'Operações',
            'category' => 'Sistemas e Aplicações',
            'classification' => 'Incidente',
            'severity' => 'Muito alto',
            'description' => 'Ticket de teste da prioridade muito alta.',
        ],
        $requesterSession
    );
    check(
        $status === 201
            && ($veryHighTicket['ticket']['priority'] ?? '') === 'very_high'
            && ($veryHighTicket['ticket']['sla']['firstResponseTargetHours'] ?? 0) === 2,
        'Prioridade ou SLA padrão muito alto não foi aplicado ao ticket.'
    );

    $draftId = 'draft-' . bin2hex(random_bytes(6));
    [$status] = request_api(
        $baseUrl,
        'drafts',
        'POST',
        ['id' => $draftId, 'values' => ['title' => 'Rascunho de teste', 'description' => 'migração']],
        $requesterSession
    );
    check($status === 201, 'Salvar rascunho no servidor falhou.');
    [$status, $draftResult] = request_api($baseUrl, 'drafts', session: $requesterSession);
    check($status === 200 && count($draftResult['drafts'] ?? []) === 1, 'Listar rascunhos falhou.');

    [$status, $admin, $adminSession] = login_api($baseUrl, 'admin@chamo.adm.com', 'Admin123!');
    check($status === 200 && ($admin['user']['role'] ?? '') === 'admin', 'Login administrativo falhou.');
    [$status, $adminData] = request_api($baseUrl, 'users', session: $adminSession);
    check($status === 200 && count($adminData['users'] ?? []) === 3, 'Listagem administrativa de usuários falhou.');
    check(($adminData['sla']['high'] ?? 0) === 4, 'Configuração de SLA não foi inicializada.');
    check(($adminData['sla']['very_high'] ?? 0) === 2, 'Configuração padrão de prioridade muito alta não foi inicializada.');
    [$status] = request_api(
        $baseUrl,
        'sla',
        'PUT',
        ['very_high' => 3, 'high' => 4, 'medium' => 8, 'low' => 24],
        $adminSession
    );
    check($status === 200, 'Salvar SLA de prioridade muito alta falhou.');
    [$status, $adminData] = request_api($baseUrl, 'users', session: $adminSession);
    check($status === 200 && ($adminData['sla']['very_high'] ?? 0) === 3, 'SLA de prioridade muito alta não foi persistido.');
    [$status, $updatedVeryHighTicket] = request_api(
        $baseUrl,
        'tickets',
        'POST',
        [
            'title' => 'Teste automatizado de SLA muito alto configurado',
            'errorType' => 'Software',
            'department' => 'Operações',
            'category' => 'Sistemas e Aplicações',
            'classification' => 'Incidente',
            'severity' => 'Muito alto',
            'description' => 'Verifica o SLA administrativo configurado.',
        ],
        $requesterSession
    );
    check(
        $status === 201 && ($updatedVeryHighTicket['ticket']['sla']['firstResponseTargetHours'] ?? 0) === 3,
        'SLA configurado para prioridade muito alta não foi aplicado aos novos tickets.'
    );

    $signupBase = [
        'firstName' => 'Maria',
        'lastName' => 'Silva',
        'phone' => '5511999999999',
        'password' => 'Chamo123!',
    ];
    [$status] = request_api(
        $baseUrl,
        'register',
        'POST',
        $signupBase + ['email' => 'pessoa@example.test']
    );
    check($status === 422, 'Cadastro aceitou um domínio de e-mail sem perfil válido.');
    [$status] = request_api(
        $baseUrl,
        'register',
        'POST',
        array_replace($signupBase, ['email' => 'celular@chamo.com', 'phone' => '(11) 99999-9999'])
    );
    check($status === 422, 'Cadastro aceitou celular com caracteres não numéricos.');
    [$status] = request_api(
        $baseUrl,
        'register',
        'POST',
        array_replace($signupBase, ['email' => 'senha@chamo.com', 'password' => 'senha-fraca'])
    );
    check($status === 422, 'Cadastro aceitou senha fora da política.');

    $userSignup = $signupBase + [
        'email' => 'novo-' . bin2hex(random_bytes(4)) . '@chamo.com',
        'role' => 'admin',
    ];
    [$status, $registration] = request_api($baseUrl, 'register', 'POST', $userSignup);
    check($status === 201 && empty($registration['requiresApproval']), 'Cadastro de solicitante não foi ativado automaticamente.');
    [$status, $registeredUser] = login_api($baseUrl, $userSignup['email'], $userSignup['password']);
    check(
        $status === 200
            && ($registeredUser['user']['role'] ?? '') === 'user'
            && ($registeredUser['user']['name'] ?? '') === 'Maria Silva'
            && ($registeredUser['user']['phone'] ?? '') === $userSignup['phone'],
        'Cadastro de solicitante não salvou os dados ou não permitiu login.'
    );

    $attendantSignup = $signupBase + ['email' => 'novo-' . bin2hex(random_bytes(4)) . '@chamo.sup.com'];
    [$status, $attendantRegistration] = request_api($baseUrl, 'register', 'POST', $attendantSignup);
    check($status === 201 && !empty($attendantRegistration['requiresApproval']), 'Cadastro de atendente não solicitou autorização.');
    [$status] = login_api($baseUrl, $attendantSignup['email'], $attendantSignup['password']);
    check($status === 401, 'Atendente aguardando autorização conseguiu entrar.');
    [$status, $pendingUsers] = request_api($baseUrl, 'users', session: $adminSession);
    $pendingAttendant = array_values(array_filter(
        $pendingUsers['users'] ?? [],
        static fn(array $account): bool => $account['email'] === $attendantSignup['email']
    ))[0] ?? null;
    check($status === 200 && $pendingAttendant !== null, 'Solicitação de atendente não apareceu na lista administrativa.');
    [$status] = request_api(
        $baseUrl,
        'user',
        'PUT',
        ['role' => 'attendant', 'isActive' => true],
        $adminSession,
        (string) $pendingAttendant['id']
    );
    check($status === 200, 'Administrador não conseguiu autorizar o atendente.');
    [$status, $approvedAttendant] = login_api($baseUrl, $attendantSignup['email'], $attendantSignup['password']);
    check($status === 200 && ($approvedAttendant['user']['role'] ?? '') === 'attendant', 'Atendente autorizado não conseguiu entrar.');

    $adminSignup = $signupBase + ['email' => 'novo-' . bin2hex(random_bytes(4)) . '@chamo.adm.com'];
    [$status, $adminRegistration] = request_api($baseUrl, 'register', 'POST', $adminSignup);
    check($status === 201 && !empty($adminRegistration['requiresApproval']), 'Cadastro de administrador não solicitou autorização.');
    [$status] = login_api($baseUrl, $adminSignup['email'], $adminSignup['password']);
    check($status === 401, 'Administrador aguardando autorização conseguiu entrar.');
    [$status, $pendingUsers] = request_api($baseUrl, 'users', session: $adminSession);
    $pendingAdmin = array_values(array_filter(
        $pendingUsers['users'] ?? [],
        static fn(array $account): bool => $account['email'] === $adminSignup['email']
    ))[0] ?? null;
    check($status === 200 && $pendingAdmin !== null, 'Solicitação administrativa não apareceu na lista de autorização.');
    [$status] = request_api(
        $baseUrl,
        'user',
        'PUT',
        ['role' => 'admin', 'isActive' => true],
        $adminSession,
        (string) $pendingAdmin['id']
    );
    check($status === 200, 'Administrador não conseguiu autorizar a nova conta administrativa.');
    [$status, $approvedAdmin] = login_api($baseUrl, $adminSignup['email'], $adminSignup['password']);
    check($status === 200 && ($approvedAdmin['user']['role'] ?? '') === 'admin', 'Administrador autorizado não conseguiu entrar.');

    [$status] = request_api(
        $baseUrl,
        'claim',
        'POST',
        [],
        $adminSession,
        $ticketNo
    );
    check($status === 200, 'Atribuição do ticket falhou.');
    [$status] = request_api(
        $baseUrl,
        'message',
        'POST',
        ['message' => 'Primeira resposta automatizada.'],
        $adminSession,
        $ticketNo
    );
    check($status === 200, 'Mensagem do atendente falhou.');
    [$status, $ticketList] = request_api($baseUrl, 'tickets', session: $adminSession);
    $testedTicket = array_values(array_filter($ticketList['tickets'] ?? [], static fn(array $ticket): bool => $ticket['id'] === $ticketNo))[0] ?? null;
    check($status === 200 && !empty($testedTicket['firstResponseAt']), 'Primeira resposta não foi registrada.');
    [$status] = request_api($baseUrl, 'rate', 'POST', ['rating' => 4.5], $requesterSession, $ticketNo);
    check($status === 409, 'Ticket em andamento aceitou avaliação.');
    [$status] = request_api($baseUrl, 'resolve', 'POST', [], $adminSession, $ticketNo);
    check($status === 200, 'Conclusão do ticket de teste falhou.');
    [$status] = request_api($baseUrl, 'rate', 'POST', ['rating' => 4.5], $adminSession, $ticketNo);
    check($status === 403, 'Usuário que não é o solicitante conseguiu avaliar o ticket.');
    [$status] = request_api($baseUrl, 'rate', 'POST', ['rating' => 4.25], $requesterSession, $ticketNo);
    check($status === 422, 'API aceitou nota que não está em intervalos de meia estrela.');
    [$status, $ratedTicket] = request_api(
        $baseUrl,
        'rate',
        'POST',
        ['rating' => 4.5, 'comment' => 'Atendimento atencioso.'],
        $requesterSession,
        $ticketNo
    );
    check(
        $status === 200
            && ($ratedTicket['ticket']['rating']['stars'] ?? 0) === 4.5
            && ($ratedTicket['ticket']['rating']['comment'] ?? '') === 'Atendimento atencioso.',
        'Avaliação do ticket não foi salva corretamente.'
    );
    [$status] = request_api(
        $baseUrl,
        'rate',
        'POST',
        ['rating' => 5],
        $requesterSession,
        $ticketNo
    );
    check($status === 409, 'A API permitiu avaliar o mesmo ticket mais de uma vez.');
    [$status] = request_api($baseUrl, 'resolve', 'POST', [], $adminSession, $veryHighTicket['ticket']['id']);
    check($status === 200, 'Conclusão do ticket muito alto de teste falhou.');
    [$status, $optionalCommentRating] = request_api(
        $baseUrl,
        'rate',
        'POST',
        ['rating' => 3.5],
        $requesterSession,
        $veryHighTicket['ticket']['id']
    );
    check(
        $status === 200
            && ($optionalCommentRating['ticket']['rating']['stars'] ?? 0) === 3.5
            && ($optionalCommentRating['ticket']['rating']['comment'] ?? null) === '',
        'A avaliação sem comentário opcional não foi aceita.'
    );

    $newEmail = 'teste-' . bin2hex(random_bytes(4)) . '@example.test';
    $newPassword = 'Inicial-Segura123!';
    [$status] = request_api(
        $baseUrl,
        'users',
        'POST',
        ['email' => $newEmail, 'name' => 'Conta de Teste', 'role' => 'user', 'password' => $newPassword],
        $adminSession
    );
    check($status === 201, 'Criação administrativa de usuário falhou.');
    [$status, $initialUser, $initialUserSession] = login_api($baseUrl, $newEmail, $newPassword);
    check($status === 200, 'Login com senha inicial da conta criada falhou.');
    [$status] = request_api($baseUrl, 'password-reset-request', 'POST', ['email' => $newEmail]);
    check($status === 200, 'Solicitação de redefinição falhou.');
    $pdo = new PDO('sqlite:' . $storage . DIRECTORY_SEPARATOR . 'chamo.sqlite');
    $mail = $pdo->prepare('SELECT body FROM mail_queue WHERE recipient = ? ORDER BY id DESC LIMIT 1');
    $mail->execute([$newEmail]);
    $mailBody = (string) $mail->fetchColumn();
    $mail->closeCursor();
    $mail = null;
    $pdo = null;
    check(preg_match('/token=([a-f0-9]{64})/', $mailBody, $tokenMatch) === 1, 'E-mail de redefinição não foi enfileirado com token.');
    $newPassword = 'NovaSenha123!';
    [$status, $resetResult] = request_api($baseUrl, 'password-reset', 'POST', ['token' => $tokenMatch[1], 'password' => $newPassword]);
    check($status === 200, 'Redefinição de senha falhou: HTTP ' . $status . ' ' . json_encode($resetResult));
    [$status] = request_api($baseUrl, 'tickets', session: $initialUserSession);
    check($status === 401, 'A redefinição não encerrou as sessões antigas.');
    [$status, $newUser, $newUserSession] = login_api($baseUrl, $newEmail, $newPassword);
    check($status === 200 && ($newUser['user']['email'] ?? '') === $newEmail, 'Login com a nova senha falhou.');

    [$status] = request_api(
        $baseUrl,
        'user',
        'PUT',
        ['role' => 'user', 'isActive' => false],
        $adminSession,
        (string) $newUser['user']['id']
    );
    check($status === 200, 'Desativar conta administrativa falhou.');
    [$status] = login_api($baseUrl, $newEmail, $newPassword);
    check($status === 401, 'Conta desativada ainda conseguiu entrar.');

    $rateLimited = false;
    $throttleSession = [];
    for ($attempt = 0; $attempt < 7; $attempt++) {
        [$status, $loginError] = request_api(
            $baseUrl,
            'login',
            'POST',
            ['email' => 'inexistente@example.test', 'password' => 'senha-incorreta'],
            $throttleSession
        );
        if ($status === 429) {
            $rateLimited = true;
            break;
        }
        check($status === 401, 'Resposta inesperada durante teste de limite de login: HTTP ' . $status . ' ' . json_encode($loginError));
    }
    check($rateLimited, 'O limite de tentativas de login não foi aplicado.');
    echo "API smoke tests: OK (auth, cadastro/aprovação, permissões, tickets/SLA/avaliações, rascunhos, gestão de usuários, recuperação de senha, bloqueio e rate limit)\n";
} finally {
    proc_terminate($process);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo = null;
    }
    remove_tree($temporaryRoot);
    putenv('CHAMO_STORAGE');
    putenv('CHAMO_DEMO_ACCOUNTS');
    putenv('APP_ENV');
}
