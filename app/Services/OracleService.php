<?php

namespace App\Services;

use Config\Database;
use CodeIgniter\Database\BaseConnection;
use Config\ReferentielImportConfig;
use App\Services\AuditLoggerService;

class OracleService
{
    protected BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /* =========================
     * TRANSACTIONS
     * ========================= */

    public function begin(): void
    {
        $this->db->transStart();
    }

    public function commit(): void
    {
        $this->db->transCommit();
    }

    public function rollback(): void
    {
        $this->db->transRollback();
    }

    /* =========================
     * SQL EXECUTION
     * ========================= */

    public function executeSql(string $sql, array $binds = [])
    {
        $startedAt = microtime(true);
        $query = $this->db->query($sql, $binds);
        $duration = microtime(true) - $startedAt;

        if ($query === false) {
            $error = $this->db->error();
            log_message('error', 'Oracle SQL error: ' . json_encode($error));
            $this->logOracleError('oracle_error', $duration, $error, $sql);
            return false;
        }

        // Détecte si SELECT pour retourner les résultats
        $isSelect = stripos(ltrim($sql), 'SELECT') === 0;
        return $isSelect ? $query->getResultArray() : true;
    }

    /**
     * Journalise une erreur Oracle dans le journal d'audit (utilisateur, IP,
     * durée de la requête fautive) en plus du log technique déjà écrit par
     * l'appelant. Ne doit jamais faire échouer l'appelant si l'audit échoue.
     */
    private function logOracleError(string $action, float $duration, array $error, string $sql): void
    {
        try {
            (new AuditLoggerService())->log($action, 'ERROR', $duration, service('request'), [
                'code'    => $error['code'] ?? null,
                'message' => $error['message'] ?? 'Erreur Oracle inconnue',
                'sql'     => substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 300),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Échec de journalisation d\'audit (oracle_error) : ' . $e->getMessage());
        }
    }

    public function affectedRows(): int
    {
        return $this->db->affectedRows();
    }

    /* =========================
     * ORACLE SPECIFIC
     * ========================= */

    public function truncate(string $table): bool
    {
        $startedAt = microtime(true);
        try {
            $result = $this->db->query("CALL cmsreport.cmsreport_do_truncate(?)", [$table]);

            if ($result === false) {
                $error = $this->db->error();
                log_message('error', 'Truncate error: ' . json_encode($error));
                $this->logOracleError('oracle_error', microtime(true) - $startedAt, $error, "TRUNCATE {$table}");
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', 'Truncate error: ' . $e->getMessage());
            $this->logOracleError('oracle_error', microtime(true) - $startedAt, ['message' => $e->getMessage()], "TRUNCATE {$table}");
            return false;
        }
    }

    /**
     * @param array|null $configs Config déjà chargée (ex. MemoryController::$configMap) pour
     *                            éviter de reconstruire ReferentielImportConfig::get() une
     *                            seconde fois dans la même requête. Rechargée si absente.
     */
    public function truncateReferentielType(string $referentielType, ?array $configs = null): bool
{
    $startTime = microtime(true);
    $traceId = 'truncate_' . uniqid('', true);

    log_message('info', "=== START TRUNCATE {$traceId} ===");
    log_message('debug', json_encode([
        'trace_id' => $traceId,
        'step'     => 'input_received',
        'type'     => $referentielType
    ]));

    // 1. Chargement config
    $configs ??= ReferentielImportConfig::get();

    log_message('debug', json_encode([
        'trace_id' => $traceId,
        'step'     => 'config_loaded',
        'available_types' => array_keys($configs)
    ]));

    // 2. Validation type
    if (!isset($configs[$referentielType])) {

        log_message('error', json_encode([
            'trace_id' => $traceId,
            'step'     => 'invalid_type',
            'type'     => $referentielType,
            'message'  => 'Type de référentiel inconnu pour truncate'
        ]));

        return false;
    }

    $truncateTables = $configs[$referentielType]['truncate_tables'] ?? [];

    log_message('debug', json_encode([
        'trace_id' => $traceId,
        'step'     => 'truncate_tables_loaded',
        'type'     => $referentielType,
        'tables'   => $truncateTables
    ]));

    // 3. Cas vide
    if (empty($truncateTables)) {

        log_message('warning', json_encode([
            'trace_id' => $traceId,
            'step'     => 'no_tables',
            'type'     => $referentielType,
            'message'  => 'Aucune table à tronquer'
        ]));

        return true;
    }

    // 4. Execution truncate
    $allSuccess = true;
    $index = 0;

    foreach ($truncateTables as $table) {

        $index++;

        log_message('info', json_encode([
            'trace_id' => $traceId,
            'step'     => 'truncate_start',
            'index'    => $index,
            'table'    => $table
        ]));

        $t0 = microtime(true);

        try {
            $success = $this->truncate($table);

            $duration = round(microtime(true) - $t0, 4);

            log_message('debug', json_encode([
                'trace_id' => $traceId,
                'step'     => 'truncate_result',
                'table'    => $table,
                'success'  => $success,
                'time_ms'  => $duration * 1000
            ]));

            if (!$success) {
                $allSuccess = false;

                log_message('error', json_encode([
                    'trace_id' => $traceId,
                    'step'     => 'truncate_failed',
                    'table'    => $table
                ]));
            }

        } catch (\Throwable $e) {

            $allSuccess = false;

            log_message('error', json_encode([
                'trace_id' => $traceId,
                'step'     => 'truncate_exception',
                'table'    => $table,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString()
            ]));
        }
    }

    // 5. FIN
    $totalTime = round(microtime(true) - $startTime, 4);

    log_message('info', json_encode([
        'trace_id' => $traceId,
        'step'     => 'truncate_finished',
        'type'     => $referentielType,
        'success'  => $allSuccess,
        'duration_ms' => $totalTime * 1000
    ]));

    log_message('info', "=== END TRUNCATE {$traceId} ===");

    return $allSuccess;
}

    public function setNumericCharacters(string $decimal = '.', string $group = ' '): bool
    {
        $startedAt = microtime(true);
        try {
            $this->assertConnectionConfiguration();
            $result = $this->db->query("ALTER SESSION SET NLS_NUMERIC_CHARACTERS = ?", [$decimal . $group]);

            if ($result === false) {
                $error = $this->db->error();
                log_message('error', 'NLS error: ' . json_encode($error));
                $this->logOracleError('oracle_error', microtime(true) - $startedAt, $error, 'ALTER SESSION SET NLS_NUMERIC_CHARACTERS');
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            log_message('error', 'NLS error: ' . $e->getMessage());
            $this->logOracleError('oracle_error', microtime(true) - $startedAt, ['message' => $e->getMessage()], 'ALTER SESSION SET NLS_NUMERIC_CHARACTERS');
            return false;
        }
    }

    /**
     * Fails before OCI8 receives an empty username or connect descriptor.
     * The thrown message is intentionally free of credentials and is caught
     * by the caller, which records it in the server log.
     */
    private function assertConnectionConfiguration(): void
    {
        if (! function_exists('oci_connect')) {
            throw new \RuntimeException('L’extension PHP OCI8 est indisponible.');
        }

        $oracle = config('Database')->oracle;
        if (trim((string) ($oracle['username'] ?? '')) === '') {
            throw new \RuntimeException('La configuration Oracle ne définit pas database.oracle.username.');
        }

        if (trim((string) ($oracle['password'] ?? '')) === '') {
            throw new \RuntimeException('La configuration Oracle ne définit pas database.oracle.password.');
        }

        $hasDsn = trim((string) ($oracle['DSN'] ?? '')) !== '';
        $hasHostAndService = trim((string) ($oracle['hostname'] ?? '')) !== ''
            && trim((string) ($oracle['database'] ?? '')) !== '';

        if (! $hasDsn && ! $hasHostAndService) {
            throw new \RuntimeException('La configuration Oracle ne définit ni DSN ni paire hostname/database.');
        }
    }

    /* =========================
     * FETCH ALL
     * ========================= */
   public function fetchAll(string $sql, array $binds = []): array
    {
        $result = $this->executeSql($sql, $binds);

        if ($result === false) {
            log_message('error', 'fetchAll: échec exécution SQL');
            return [];
        }

        if ($result === true) {
            // Requête non SELECT exécutée avec succès
            return [];
        }

        // Ici on est sûr que c’est un array
        return $result;
    }
}
