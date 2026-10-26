<?php
declare(strict_types=1);

require __DIR__ . '/../api.php';

$pdo = database();
$backupDirectory = trim((string) getenv('CHAMO_BACKUP_DIR'));
$storage = realpath(storage_directory());
$projectRoot = realpath(__DIR__ . '/..');
if ($backupDirectory === '' || !is_absolute_path($backupDirectory)) {
    fwrite(STDERR, "Defina CHAMO_BACKUP_DIR como caminho absoluto privado (por exemplo, E:\\ChamoBackups).\n");
    exit(2);
}
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Não foi possível criar o diretório de backup.');
}
$backupRoot = realpath($backupDirectory);
if ($backupRoot === false || $projectRoot === false || $storage === false
    || paths_overlap($backupRoot, $projectRoot) || paths_overlap($backupRoot, $storage)) {
    fwrite(STDERR, "O backup deve ficar fora da raiz pública e do armazenamento ativo.\n");
    exit(2);
}

function paths_overlap(string $first, string $second): bool
{
    if (DIRECTORY_SEPARATOR === '\\') {
        $first = strtolower(rtrim($first, '\\/'));
        $second = strtolower(rtrim($second, '\\/'));
    } else {
        $first = rtrim($first, DIRECTORY_SEPARATOR);
        $second = rtrim($second, DIRECTORY_SEPARATOR);
    }
    return $first === $second
        || str_starts_with($first, $second . DIRECTORY_SEPARATOR)
        || str_starts_with($second, $first . DIRECTORY_SEPARATOR);
}

function write_backup_contents($handle, string $contents): void
{
    $offset = 0;
    $length = strlen($contents);
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Não foi possível gravar o arquivo de backup.');
        }
        $offset += $written;
    }
}

$stamp = date('Ymd-His');
$databaseExtension = database_driver() === 'mysql' ? 'sql' : 'sqlite';
$databaseBackup = $backupRoot . DIRECTORY_SEPARATOR . "chamo-{$stamp}.{$databaseExtension}";
$uploadsArchive = $backupRoot . DIRECTORY_SEPARATOR . "chamo-{$stamp}.tar";
$compressedUploadsArchive = $uploadsArchive . '.gz';
if (database_driver() === 'mysql') {
    $schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'banco_de_dados'
        . DIRECTORY_SEPARATOR . 'chamo_mysql.sql';
    $schema = file_get_contents($schemaPath);
    if ($schema === false) {
        throw new RuntimeException('Não foi possível ler o esquema MySQL para o backup.');
    }
    $handle = fopen($databaseBackup, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível criar o arquivo de backup MySQL.');
    }
    try {
        if (!chmod($databaseBackup, 0600)) {
            throw new RuntimeException('Não foi possível proteger o arquivo de backup MySQL.');
        }
        write_backup_contents($handle, "SET FOREIGN_KEY_CHECKS=0;\n{$schema}\n");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        foreach ([
            'users', 'tickets', 'ticket_messages', 'ticket_attachments', 'ticket_ratings',
            'login_attempts', 'password_reset_tokens', 'audit_log', 'mail_queue',
            'app_settings', 'ticket_drafts',
        ] as $table) {
            $rows = $pdo->query("SELECT * FROM `{$table}`");
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map(static fn (string $column): string => "`{$column}`", array_keys($row));
                $values = [];
                foreach (array_values($row) as $value) {
                    $quoted = $value === null ? 'NULL' : $pdo->quote((string) $value);
                    if (!is_string($quoted)) {
                        throw new RuntimeException('Não foi possível codificar um valor do backup MySQL.');
                    }
                    $values[] = $quoted;
                }
                write_backup_contents(
                    $handle,
                    "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n"
                );
            }
            $rows->closeCursor();
        }
        $pdo->commit();
        write_backup_contents($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        if (is_file($databaseBackup)) {
            unlink($databaseBackup);
        }
        throw $error;
    }
    fclose($handle);
} else {
    $pdo->exec('VACUUM INTO ' . $pdo->quote($databaseBackup));
}
chmod($databaseBackup, 0600);

try {
    $archive = new PharData($uploadsArchive);
    if (is_dir(upload_directory())) {
        $archive->buildFromDirectory(upload_directory());
    }
    $compressedArchive = $archive->compress(Phar::GZ);
    $compressedUploadsArchive = $compressedArchive->getPath();
    unset($compressedArchive);
    unset($archive);
    if (is_file($uploadsArchive) && !unlink($uploadsArchive)) {
        throw new RuntimeException('Não foi possível remover o arquivo temporário do backup de anexos.');
    }
    if (!is_file($compressedUploadsArchive) || !chmod($compressedUploadsArchive, 0600)) {
        throw new RuntimeException('Não foi possível proteger o arquivo compactado dos anexos.');
    }
} catch (Throwable $error) {
    if (is_file($databaseBackup)) {
        unlink($databaseBackup);
    }
    if (is_file($uploadsArchive)) {
        unlink($uploadsArchive);
    }
    if (is_file($compressedUploadsArchive)) {
        unlink($compressedUploadsArchive);
    }
    throw $error;
}

$retentionDays = filter_var(getenv('CHAMO_BACKUP_RETENTION_DAYS') ?: '30', FILTER_VALIDATE_INT);
if ($retentionDays === false || $retentionDays < 1 || $retentionDays > 3650) {
    throw new RuntimeException('CHAMO_BACKUP_RETENTION_DAYS deve estar entre 1 e 3650.');
}
$threshold = time() - ($retentionDays * 86400);
foreach (scandir($backupRoot) ?: [] as $name) {
    if (!preg_match('/^chamo-\d{8}-\d{6}\.(?:sqlite|sql|tar\.gz)$/', $name)) {
        continue;
    }
    $path = $backupRoot . DIRECTORY_SEPARATOR . $name;
    if (is_file($path) && filemtime($path) < $threshold) {
        unlink($path);
    }
}

printf("Backup concluído: %s (banco) e %s (anexos)\n", $databaseBackup, $compressedUploadsArchive);
