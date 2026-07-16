<?php

namespace App\Services;

use Config\Database;
use CodeIgniter\Database\BaseConnection;
use Config\ReferentielImportConfig;

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
        $query = $this->db->query($sql, $binds);

        if ($query === false) {
            log_message('error', 'Oracle SQL error: ' . json_encode($this->db->error()));
            return false;
        }

        // Détecte si SELECT pour retourner les résultats
        $isSelect = stripos(ltrim($sql), 'SELECT') === 0;
        return $isSelect ? $query->getResultArray() : true;
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
        try {
            $this->db->query("CALL cmsreport.cmsreport_do_truncate(?)", [$table]);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'Truncate error: ' . $e->getMessage());
            return false;
        }
    }

    public function truncateReferentielType(string $referentielType): bool
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
    $configs = ReferentielImportConfig::get();

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
        try {
            $this->db->query("ALTER SESSION SET NLS_NUMERIC_CHARACTERS = ?", [$decimal . $group]);
            return true;
        } catch (\Throwable $e) {
            log_message('error', 'NLS error: ' . $e->getMessage());
            return false;
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
