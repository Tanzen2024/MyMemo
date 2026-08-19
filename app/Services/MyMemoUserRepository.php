<?php

namespace App\Services;

/**
 * Seule classe autorisée à lire/écrire writable/security/users.csv — les
 * contrôleurs ne doivent jamais ouvrir ce fichier directement. Verrouillage
 * et écriture atomique sur le modèle de ImportLockService (fopen 'x' /
 * flock / remplacement par rename()), adapté ici à un fichier de données
 * modifié plutôt qu'un simple verrou éphémère.
 */
final class MyMemoUserRepository
{
    private const HEADER = ['username', 'roles', 'enabled', 'display_name'];

    /** Amorçage initial : seul utilisateur réellement observé sur ce projet (voir docs/MYMEMO_USERS_CSV.md). */
    private const SEED_ADMIN = ['hugues.nwameh', 'ADMIN', '1', 'Hugues Roland NWAMEH'];

    public function __construct(private readonly ?string $directory = null)
    {
    }

    /** @return array<string,array{username:string,roles:string[],enabled:bool,display_name:string}> Indexé par identifiant normalisé. */
    public function readAll(): array
    {
        $this->ensureBootstrapped();

        return $this->parseFile();
    }

    public function findByUsername(string $username): ?array
    {
        return $this->readAll()[self::normalize($username)] ?? null;
    }

    public function exists(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    /**
     * @throws \InvalidArgumentException|\DomainException
     */
    public function add(array $user): bool
    {
        return $this->mutate(function (array $users) use ($user) {
            $sanitized = $this->sanitizeUser($user);
            $key = self::normalize($sanitized['username']);
            if (isset($users[$key])) {
                throw new \InvalidArgumentException('Cet utilisateur est déjà autorisé.');
            }

            $users[$key] = $sanitized;

            return $users;
        });
    }

    /**
     * $guard, si fourni, est appelé APRÈS calcul du nouvel état mais AVANT
     * écriture, dans la même section verrouillée (protection dernier admin
     * sans condition de course) : function(array $allUsers, string $key,
     * ?array $before, ?array $after): void — doit lever une exception pour
     * refuser la modification.
     *
     * @throws \DomainException|\InvalidArgumentException
     */
    public function update(string $username, array $changes, ?callable $guard = null): bool
    {
        return $this->mutate(function (array $users) use ($username, $changes, $guard) {
            $key = self::normalize($username);
            if (! isset($users[$key])) {
                throw new \DomainException('Utilisateur introuvable.');
            }

            $before = $users[$key];
            $merged = array_merge($before, $changes);
            $merged['username'] = $before['username']; // identifiant stable, jamais modifiable via update()
            $after = $this->sanitizeUser($merged);

            if ($guard !== null) {
                $guard($users, $key, $before, $after);
            }

            $users[$key] = $after;

            return $users;
        });
    }

    /**
     * @throws \DomainException
     */
    public function remove(string $username, ?callable $guard = null): bool
    {
        return $this->mutate(function (array $users) use ($username, $guard) {
            $key = self::normalize($username);
            if (! isset($users[$key])) {
                throw new \DomainException('Utilisateur introuvable.');
            }

            if ($guard !== null) {
                $guard($users, $key, $users[$key], null);
            }

            unset($users[$key]);

            return $users;
        });
    }

    /** Règle de normalisation unique (§8) : espaces superflus ignorés, comparaison insensible à la casse. */
    public static function normalize(string $username): string
    {
        return strtolower(trim($username));
    }

    private function mutate(callable $callback): bool
    {
        $this->ensureBootstrapped();

        $lockHandle = @fopen($this->lockPath(), 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException("Impossible d'acquérir le verrou du fichier utilisateurs.");
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Verrou du fichier utilisateurs indisponible.');
            }

            $users = $this->parseFile();
            $users = $callback($users);

            $this->backup();
            $this->writeAtomic($users);

            return true;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function ensureBootstrapped(): void
    {
        $dir = $this->baseDir();
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le répertoire writable/security.');
        }
        if (! is_dir($this->backupsDir())) {
            @mkdir($this->backupsDir(), 0750, true);
        }
        if (file_exists($this->csvPath())) {
            return;
        }

        $lockHandle = @fopen($this->lockPath(), 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException("Impossible d'acquérir le verrou du fichier utilisateurs.");
        }

        try {
            flock($lockHandle, LOCK_EX);
            if (file_exists($this->csvPath())) {
                return; // créé par une requête concurrente pendant l'attente du verrou
            }

            $handle = fopen($this->csvPath(), 'w');
            fputcsv($handle, self::HEADER);
            fputcsv($handle, self::SEED_ADMIN);
            fclose($handle);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return array<string,array{username:string,roles:string[],enabled:bool,display_name:string}> */
    private function parseFile(): array
    {
        $users = [];
        // ensureBootstrapped() (appelé par tout point d'entrée public avant
        // parseFile()) garantit déjà l'existence du fichier : un échec de
        // fopen() ici est donc une vraie erreur (permissions, disque...),
        // jamais l'absence normale du fichier. Une liste vide silencieuse
        // ferait croire à zéro utilisateur — pire, dans mutate(), écraserait
        // le fichier réel par un fichier vide à la prochaine écriture.
        $handle = @fopen($this->csvPath(), 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de lire writable/security/users.csv (fichier inaccessible).');
        }

        try {
            fgetcsv($handle); // en-tête
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || count($row) < 4) {
                    continue;
                }

                $user = $this->rowToUser($row);
                $users[self::normalize($user['username'])] = $user;
            }
        } finally {
            fclose($handle);
        }

        return $users;
    }

    private function rowToUser(array $row): array
    {
        [$username, $rolesField, $enabled, $displayName] = array_pad($row, 4, '');

        return [
            'username'     => (string) $username,
            'roles'        => array_values(array_filter(array_map('trim', explode('|', (string) $rolesField)))),
            'enabled'      => ((string) $enabled) === '1',
            'display_name' => (string) $displayName,
        ];
    }

    /** Invariants de format uniquement — le contrôle de la liste blanche des rôles est fait par MyMemoAuthorizationService/le contrôleur. */
    private function sanitizeUser(array $user): array
    {
        $username = self::normalize((string) ($user['username'] ?? ''));
        if ($username === '' || ! preg_match('/^[a-z0-9._-]{1,64}$/', $username)) {
            throw new \InvalidArgumentException('Identifiant AD invalide.');
        }

        $roles = array_values(array_unique(array_map(
            static fn ($r) => strtoupper(trim((string) $r)),
            (array) ($user['roles'] ?? [])
        )));

        $displayName = preg_replace('/[\x00-\x1F\x7F]/', '', trim((string) ($user['display_name'] ?? ''))) ?? '';
        if (mb_strlen($displayName) > 190) {
            $displayName = mb_substr($displayName, 0, 190);
        }

        return [
            'username'     => $username,
            'roles'        => $roles,
            'enabled'      => (bool) ($user['enabled'] ?? false),
            'display_name' => $displayName,
        ];
    }

    private function backup(): void
    {
        if (! file_exists($this->csvPath())) {
            return;
        }

        $target = $this->backupsDir() . 'users_' . date('Ymd_His') . '.csv';
        if (! @copy($this->csvPath(), $target)) {
            log_message('warning', 'Sauvegarde de users.csv impossible avant modification.');
        }
    }

    private function writeAtomic(array $users): void
    {
        $tmpPath = $this->csvPath() . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($tmpPath, 'w');
        if ($handle === false) {
            throw new \RuntimeException('Impossible de préparer l\'écriture de users.csv.');
        }

        fputcsv($handle, self::HEADER);
        foreach ($users as $user) {
            fputcsv($handle, [
                $user['username'],
                implode('|', $user['roles']),
                $user['enabled'] ? '1' : '0',
                $user['display_name'],
            ]);
        }
        fflush($handle);
        fclose($handle);

        if (! rename($tmpPath, $this->csvPath())) {
            @unlink($tmpPath);
            throw new \RuntimeException('Impossible de finaliser l\'écriture de users.csv.');
        }
    }

    private function baseDir(): string
    {
        return rtrim($this->directory ?? WRITEPATH . 'security', '/\\') . DIRECTORY_SEPARATOR;
    }

    private function backupsDir(): string
    {
        return $this->baseDir() . 'backups' . DIRECTORY_SEPARATOR;
    }

    private function csvPath(): string
    {
        return $this->baseDir() . 'users.csv';
    }

    private function lockPath(): string
    {
        return $this->baseDir() . 'users.csv.lock';
    }
}
