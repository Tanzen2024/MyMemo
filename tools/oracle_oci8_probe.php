<?php

declare(strict_types=1);

/**
 * Standalone OCI8 connectivity probe. It deliberately does not bootstrap
 * CodeIgniter and executes no SQL: only oci_connect() and oci_close().
 */

$root = dirname(__DIR__);
$envFile = $root . DIRECTORY_SEPARATOR . '.env';
$logFile = $root . DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'oracle-oci8-probe.log';

/** @return array<string, string> */
function readDotEnv(string $filename): array
{
    if (! is_readable($filename)) {
        throw new RuntimeException('Le fichier .env est introuvable ou illisible.');
    }

    $values = [];
    foreach (file($filename, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        if (strlen($value) >= 2 && (($value[0] === "'" && str_ends_with($value, "'")) || ($value[0] === '"' && str_ends_with($value, '"')))) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

/** @param array<string, mixed> $record */
function writeReport(string $filename, array $record): void
{
    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if ($line === false || file_put_contents($filename, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’écrire le rapport OCI8.');
    }
}

$report = [
    'timestamp' => date(DATE_ATOM),
    'script' => basename(__FILE__),
    'oci8_version' => phpversion('oci8') ?: 'not loaded',
    'instant_client_version' => function_exists('oci_client_version') ? oci_client_version() : 'unavailable',
];

try {
    if (! function_exists('oci_connect')) {
        throw new RuntimeException('L’extension OCI8 n’est pas chargée.');
    }

    $env = readDotEnv($envFile);
    $username = $env['database.oracle.username'] ?? '';
    $password = $env['database.oracle.password'] ?? '';
    $dsn = $env['database.oracle.DSN'] ?? '';

    if ($dsn === '') {
        $host = $env['database.oracle.hostname'] ?? '';
        $port = $env['database.oracle.port'] ?? '1521';
        $service = $env['database.oracle.database'] ?? '';
        $dsn = $host !== '' && $service !== '' ? "{$host}:{$port}/{$service}" : '';
    }

    if ($username === '' || $password === '' || $dsn === '') {
        throw new RuntimeException('Configuration Oracle incomplète dans .env.');
    }

    $report['dsn'] = $dsn;
    $connection = @oci_connect($username, $password, $dsn, 'AL32UTF8');
    if ($connection === false) {
        $report['result'] = 'failed';
        $report['oci_error'] = oci_error();
        writeReport($logFile, $report);
        fwrite(STDERR, 'OCI8 connection failed; see writable/logs/oracle-oci8-probe.log' . PHP_EOL);
        exit(1);
    }

    $report['result'] = 'connected';
    $report['oci_error'] = null;
    oci_close($connection);
    writeReport($logFile, $report);
    echo 'OCI8 connection succeeded; connection closed.' . PHP_EOL;
} catch (Throwable $exception) {
    $report['result'] = 'probe_error';
    $report['oci_error'] = [
        'code' => $exception->getCode(),
        'message' => $exception->getMessage(),
    ];
    writeReport($logFile, $report);
    fwrite(STDERR, 'OCI8 probe failed; see writable/logs/oracle-oci8-probe.log' . PHP_EOL);
    exit(2);
}
