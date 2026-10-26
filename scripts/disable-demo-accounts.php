<?php
declare(strict_types=1);

require __DIR__ . '/../api.php';

if (getenv('APP_ENV') !== 'production' || getenv('CHAMO_DEMO_ACCOUNTS') !== '0'
    || getenv('CHAMO_DISABLE_DEMO_ACCOUNTS') !== '1') {
    fwrite(STDERR, "Defina APP_ENV=production, CHAMO_DEMO_ACCOUNTS=0 e CHAMO_DISABLE_DEMO_ACCOUNTS=1.\n");
    exit(2);
}

$pdo = database();
$demoEmails = [
    'demo@chamo.com',
    'atendente@chamo.sup.com',
    'admin@chamo.adm.com',
    'atendente@chamo.com',
    'admin@chamo.com',
];
$emailPlaceholders = implode(', ', array_fill(0, count($demoEmails), '?'));
$adminQuery = $pdo->prepare(
    "SELECT id FROM users
     WHERE role = 'admin' AND is_active = 1
       AND email NOT IN ({$emailPlaceholders})
     ORDER BY id LIMIT 1"
);
$adminQuery->execute($demoEmails);
$admin = $adminQuery->fetchColumn();
if ($admin === false) {
    fwrite(STDERR, "Crie e valide um administrador próprio antes de desativar as contas de demonstração.\n");
    exit(1);
}

$existingDemos = $pdo->prepare("SELECT email FROM users WHERE email IN ({$emailPlaceholders})");
$existingDemos->execute($demoEmails);
$existingDemoEmails = $existingDemos->fetchAll(PDO::FETCH_COLUMN);
$disable = $pdo->prepare("UPDATE users SET is_active = 0 WHERE email IN ({$emailPlaceholders})");
$disable->execute($demoEmails);
foreach ($existingDemoEmails as $email) {
    audit_event($pdo, (int) $admin, 'demo_account_disabled', 'account', $email);
}
printf("Contas de demonstração desativadas: %d\n", $disable->rowCount());
