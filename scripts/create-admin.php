<?php
declare(strict_types=1);

require __DIR__ . '/../api.php';

if (getenv('APP_ENV') !== 'production' || getenv('CHAMO_DEMO_ACCOUNTS') !== '0') {
    fwrite(STDERR, "Defina APP_ENV=production e CHAMO_DEMO_ACCOUNTS=0 antes de criar o administrador.\n");
    exit(2);
}

$name = trim((string) getenv('CHAMO_ADMIN_NAME'));
$email = strtolower(trim((string) getenv('CHAMO_ADMIN_EMAIL')));
$password = (string) getenv('CHAMO_ADMIN_PASSWORD');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_password($password)) {
    fwrite(STDERR, "Defina CHAMO_ADMIN_NAME, CHAMO_ADMIN_EMAIL e CHAMO_ADMIN_PASSWORD (mínimo 12 caracteres).\n");
    exit(2);
}

$pdo = database();
$exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
$exists->execute([$email]);
if ($exists->fetchColumn()) {
    fwrite(STDERR, "Já existe uma conta com esse e-mail. Nenhuma alteração foi feita.\n");
    exit(1);
}
$insert = $pdo->prepare(
    'INSERT INTO users (email, name, role, password_hash, created_at) VALUES (?, ?, ?, ?, ?)'
);
$insert->execute([$email, $name, 'admin', password_hash($password, PASSWORD_DEFAULT), now()]);
audit_event($pdo, (int) $pdo->lastInsertId(), 'production_admin_bootstrapped', 'user', (string) $pdo->lastInsertId());
fwrite(STDOUT, "Administrador criado. Remova CHAMO_ADMIN_PASSWORD do ambiente após a execução.\n");
