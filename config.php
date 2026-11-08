<?php
declare(strict_types=1);

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Configuração padrão do Chamô (integração MySQL).
 * Variáveis de ambiente do servidor (IIS / Apache / PHP-FPM) ou do arquivo .env,
 * quando definidas, têm prioridade sobre estes valores.
 */
return [
    'APP_ENV' => 'local',
    'CHAMO_DEMO_ACCOUNTS' => '1',
    'CHAMO_DB_DRIVER' => 'mysql',
    'CHAMO_DB_HOST' => '127.0.0.1',
    'CHAMO_DB_PORT' => '3306',
    'CHAMO_DB_NAME' => 'chamo',
    'CHAMO_DB_USER' => 'root',
    'CHAMO_DB_PASSWORD' => '',
    'CHAMO_TIMEZONE' => 'America/Sao_Paulo',
    'CHAMO_BUSINESS_START_HOUR' => '9',
    'CHAMO_BUSINESS_END_HOUR' => '18',
];
