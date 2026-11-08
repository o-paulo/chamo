<?php
declare(strict_types=1);

require __DIR__ . '/../api.php';

if (database_driver() !== 'mysql') {
    putenv('CHAMO_DB_DRIVER=mysql');
}

try {
    $pdo = database();
    $host = getenv('CHAMO_DB_HOST') ?: '127.0.0.1';
    $port = getenv('CHAMO_DB_PORT') ?: '3306';
    $dbName = getenv('CHAMO_DB_NAME') ?: 'chamo';
    $dbUser = getenv('CHAMO_DB_USER') ?: 'root';

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $users = $pdo->query('SELECT email, name, role, is_active FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ticketCount = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();

    fwrite(STDOUT, "=== Integração MySQL do Chamô concluída com sucesso ===\n");
    fwrite(STDOUT, "Servidor: {$host}:{$port} | Banco: {$dbName} | Usuário: {$dbUser}\n");
    fwrite(STDOUT, "Tabelas ativas (" . count($tables) . "): " . implode(', ', $tables) . "\n");
    fwrite(STDOUT, "Tickets cadastrados: {$ticketCount}\n\n");
    fwrite(STDOUT, "Contas disponíveis:\n");
    foreach ($users as $u) {
        $status = (int) $u['is_active'] === 1 ? 'ativo' : 'inativo';
        fwrite(STDOUT, sprintf(" - %s (%s) [%s - %s]\n", $u['email'], $u['name'], $u['role'], $status));
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Erro ao conectar/inicializar o MySQL: " . $error->getMessage() . "\n");
    fwrite(STDERR, "Verifique se o serviço MySQL está em execução em 127.0.0.1:3306 e confira CHAMO_DB_PASSWORD em config.php.\n");
    exit(1);
}
