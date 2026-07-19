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
            'user'     => (string) (session()->get('username') ?? 'anonymous'),
            'ip'       => $request->getIPAddress(),
            'action'   => $action,
            'status'   => $status,
            'duration' => round($duration, 4),
        ] + $context;

        $handle = @fopen($directory . date('Y-m-d') . '.json', 'c+');
        if ($handle === false) {
            log_message('error', 'Impossible d’écrire le journal d’audit.');
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            $events = json_decode(stream_get_contents($handle) ?: '[]', true);
            $events = is_array($events) ? $events : [];
            $events[] = $entry;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
