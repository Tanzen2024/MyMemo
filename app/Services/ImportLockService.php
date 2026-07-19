<?php

namespace App\Services;

final class ImportLockService
{
    public function __construct(
        private readonly ?string $directory = null,
        private readonly int $ttlSeconds = 1800
    ) {
    }

    public function acquire(string $type): ?ImportLock
    {
        $directory = $this->directory ?? WRITEPATH . 'locks' . DIRECTORY_SEPARATOR;
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire des verrous.');
        }

        $path = $this->path($directory, $type);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = bin2hex(random_bytes(16));
            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                fwrite($handle, json_encode([
                    'type'       => $type,
                    'token'      => $token,
                    'created_at' => time(),
                    'expires_at' => time() + $this->ttlSeconds,
                ]));
                fclose($handle);

                return new ImportLock($this, $type, $token);
            }

            if (! $this->removeIfExpired($path)) {
                return null;
            }
        }

        return null;
    }

    public function release(string $type, string $token): void
    {
        $directory = rtrim($this->directory ?? WRITEPATH . 'locks' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = $this->path($directory, $type);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            rewind($handle);
            $metadata = json_decode(stream_get_contents($handle) ?: '{}', true);
            if (is_array($metadata) && hash_equals((string) ($metadata['token'] ?? ''), $token)) {
                fclose($handle);
                $handle = null;
                @unlink($path);
            }
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function removeIfExpired(string $path): bool
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }
            rewind($handle);
            $metadata = json_decode(stream_get_contents($handle) ?: '{}', true);
            $expired = ! is_array($metadata) || (int) ($metadata['expires_at'] ?? 0) <= time();
            if (! $expired) {
                return false;
            }
            fclose($handle);
            $handle = null;
            @unlink($path);

            return true;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function path(string $directory, string $type): string
    {
        return $directory . 'import-' . hash('sha256', $type) . '.lock';
    }
}
