<?php

namespace App\Services;

use CodeIgniter\HTTP\RequestInterface;

final class AuditLoggerService
{
    public const ROLE_IMPORT_MANAGER = 'ROLE_IMPORT_MANAGER';

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function log(
        string $action,
        string $status,
        float $duration = 0.0,
        ?RequestInterface $request = null,
        array $context = []
    ): void {
        $session = session();
        $roles = array_values((array) $session->get('roles'));
        $now = date('c');
        $entry = [
            'date'     => $now,
            'user'     => (string) ($session->get('username') ?? 'anonymous'),
            'role'     => implode(',', $roles),
            'ip'       => $request?->getIPAddress() ?? service('request')->getIPAddress(),
            'action'   => $action,
            'status'   => $status,
            'duration' => round($duration, 4),
        ] + $context;

        $directory = $this->directory ?? WRITEPATH . 'audit' . DIRECTORY_SEPARATOR;
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            log_message('error', 'Unable to create audit log directory.');
            return;
        }

        $path = $directory . date('Y-m-d') . '.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            log_message('error', 'Unable to open audit log file.');
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }

            $contents = stream_get_contents($handle);
            $events = json_decode($contents ?: '[]', true);
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
