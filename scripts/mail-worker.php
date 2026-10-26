<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../api.php';

use PHPMailer\PHPMailer\PHPMailer;

$required = ['CHAMO_SMTP_HOST', 'CHAMO_MAIL_FROM', 'CHAMO_BASE_URL'];
foreach ($required as $key) {
    if (trim((string) getenv($key)) === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$key}\n");
        exit(2);
    }
}

$baseUrl = rtrim((string) getenv('CHAMO_BASE_URL'), '/');
if (!filter_var($baseUrl, FILTER_VALIDATE_URL)
    || (getenv('APP_ENV') === 'production' && strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https')) {
    fwrite(STDERR, "CHAMO_BASE_URL precisa ser uma URL válida (HTTPS em produção).\n");
    exit(2);
}
$encryption = strtolower((string) (getenv('CHAMO_SMTP_ENCRYPTION') ?: 'tls'));
if (!in_array($encryption, ['tls', 'ssl', 'none'], true)
    || (getenv('APP_ENV') === 'production' && $encryption === 'none')) {
    fwrite(STDERR, "Configure CHAMO_SMTP_ENCRYPTION como tls ou ssl em produção.\n");
    exit(2);
}
$smtpUsername = trim((string) getenv('CHAMO_SMTP_USERNAME'));
$smtpPassword = (string) getenv('CHAMO_SMTP_PASSWORD');
if ($smtpUsername !== '' && $smtpPassword === '') {
    fwrite(STDERR, "CHAMO_SMTP_PASSWORD é obrigatório quando CHAMO_SMTP_USERNAME está definido.\n");
    exit(2);
}
$smtpPort = filter_var(getenv('CHAMO_SMTP_PORT') ?: '587', FILTER_VALIDATE_INT);
if ($smtpPort === false || $smtpPort < 1 || $smtpPort > 65535) {
    fwrite(STDERR, "CHAMO_SMTP_PORT precisa estar entre 1 e 65535.\n");
    exit(2);
}

$pdo = database();
$sent = 0;
$failed = 0;
$batchSize = 50;

while ($sent + $failed < $batchSize) {
    $pdo->beginTransaction();
    try {
        $queued = $pdo->query(
            'SELECT id, recipient, subject, body FROM mail_queue
             WHERE sent_at IS NULL AND attempts < 5 ORDER BY id LIMIT 1'
        )->fetch();
        if (!$queued) {
            $pdo->commit();
            break;
        }
        $claim = $pdo->prepare(
            'UPDATE mail_queue SET attempts = attempts + 1 WHERE id = ? AND sent_at IS NULL AND attempts < 5'
        );
        $claim->execute([(int) $queued['id']]);
        if ($claim->rowCount() !== 1) {
            $pdo->commit();
            continue;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) getenv('CHAMO_SMTP_HOST');
        $mailer->Port = $smtpPort;
        $mailer->SMTPAuth = $smtpUsername !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $smtpUsername;
            $mailer->Password = $smtpPassword;
        }
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom(
            (string) getenv('CHAMO_MAIL_FROM'),
            (string) (getenv('CHAMO_MAIL_FROM_NAME') ?: 'Chamô')
        );
        $mailer->addAddress((string) $queued['recipient']);
        $mailer->Subject = str_replace(["\r", "\n"], ' ', (string) $queued['subject']);
        $mailer->Body = str_replace('{{BASE_URL}}', $baseUrl, (string) $queued['body']);
        $mailer->send();

        $pdo->prepare("UPDATE mail_queue SET sent_at = ?, body = '', last_error = '' WHERE id = ?")
            ->execute([now(), (int) $queued['id']]);
        $sent++;
    } catch (Throwable $error) {
        $message = mb_substr($error->getMessage(), 0, 500);
        $pdo->prepare(
            'UPDATE mail_queue SET last_error = ?, body = CASE WHEN attempts >= 5 THEN "" ELSE body END WHERE id = ?'
        )->execute([$message, (int) $queued['id']]);
        error_log('Falha ao enviar e-mail da fila ' . (int) $queued['id'] . ': ' . $message);
        fwrite(STDERR, "Falha no e-mail " . (int) $queued['id'] . ": {$message}\n");
        $failed++;
    }
}

printf("E-mails enviados: %d; falhas: %d\n", $sent, $failed);
exit($failed > 0 ? 1 : 0);
