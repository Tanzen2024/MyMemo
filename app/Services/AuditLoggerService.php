<?php

namespace App\Services;

use CodeIgniter\HTTP\RequestInterface;

final class AuditLoggerService
{
    public function log(string $action, string $status, float $duration, RequestInterface $request, array $context = []): void
    {
        $directory = WRITEPATH . 'audit' . DIRECTORY_SEPARATOR;
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            log_message('error', 'Impossible de créer le répertoire d’audit.');
            return;
        }

        $entry = [
            'date'     => date('c'),
            // Authentication attempts must not retain a supplied identifier.
            'user'     => $action === 'login' ? 'anonymous' : (string) (session()->get('username') ?? 'anonymous'),
            'ip'       => $request->getIPAddress(),
            'action'   => $action,
            'status'   => $status,
            'duration' => round($duration, 4),
        ] + $context;

        // Format JSON Lines (une entrée = une ligne, écriture en ajout pur) :
        // contrairement à un tableau JSON unique, ceci évite de relire et de
        // réécrire tout le fichier du jour à chaque événement (O(1) au lieu
        // de O(n) par écriture, donc O(n²) cumulé sur la journée).
        $handle = @fopen($directory . date('Y-m-d') . '.jsonl', 'a');
        if ($handle === false) {
            log_message('error', 'Impossible d’écrire le journal d’audit.');
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            fwrite($handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
