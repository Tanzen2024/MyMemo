<?php

namespace App\Services;

/**
 * Source unique de vérité pour l'autorisation MyMemo (rôles issus de
 * writable/security/users.csv, jamais des groupes AD — voir
 * docs/MYMEMO_USERS_CSV.md). Centralise la liste des rôles connus et la
 * table rôle → capacités : un contrôleur ne doit jamais écrire
 * `if ($role === 'ADMIN')`, seulement `can($username, self::CAPABILITY_*)`.
 */
final class MyMemoAuthorizationService
{
    public const ROLE_USER  = 'USER';
    public const ROLE_ADMIN = 'ADMIN';

    /** Rôles disponibles à l'attribution (extensible : ajouter ici + dans ROLE_CAPABILITIES suffit). */
    public const KNOWN_ROLES = [self::ROLE_USER, self::ROLE_ADMIN];

    public const CAPABILITY_MYMEMO_ACCESS         = 'MYMEMO_ACCESS';
    public const CAPABILITY_ADMINISTRATION_ACCESS = 'ADMINISTRATION_ACCESS';
    public const CAPABILITY_MANAGE_USERS          = 'MANAGE_USERS';
    public const CAPABILITY_VIEW_AUDIT            = 'VIEW_AUDIT';

    private const ROLE_CAPABILITIES = [
        self::ROLE_USER => [
            self::CAPABILITY_MYMEMO_ACCESS,
        ],
        self::ROLE_ADMIN => [
            self::CAPABILITY_MYMEMO_ACCESS,
            self::CAPABILITY_ADMINISTRATION_ACCESS,
            self::CAPABILITY_MANAGE_USERS,
            self::CAPABILITY_VIEW_AUDIT,
        ],
    ];

    public function __construct(private readonly MyMemoUserRepository $repository = new MyMemoUserRepository())
    {
    }

    public function getUser(string $username): ?array
    {
        return $this->repository->findByUsername($username);
    }

    public function isEnabled(string $username): bool
    {
        return (bool) ($this->getUser($username)['enabled'] ?? false);
    }

    /** @return string[] */
    public function getRoles(string $username): array
    {
        return $this->getUser($username)['roles'] ?? [];
    }

    public function hasRole(string $username, string $role): bool
    {
        return in_array($role, $this->getRoles($username), true);
    }

    /** @param string[] $roles */
    public function hasAnyRole(string $username, array $roles): bool
    {
        return array_intersect($roles, $this->getRoles($username)) !== [];
    }

    /** @param string[] $roles */
    public function hasAllRoles(string $username, array $roles): bool
    {
        return array_diff($roles, $this->getRoles($username)) === [];
    }

    /** Accès accordé si l'utilisateur possède AU MOINS UN rôle conférant la capacité (union des permissions, §16). */
    public function can(string $username, string $capability): bool
    {
        if (! $this->isAuthorized($username)) {
            return false;
        }

        foreach ($this->getRoles($username) as $role) {
            if (in_array($capability, self::ROLE_CAPABILITIES[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** Porte d'entrée unique : présent + activé + au moins un rôle. Refuse par défaut sinon (§6). */
    public function isAuthorized(string $username): bool
    {
        $user = $this->getUser($username);

        return $user !== null && $user['enabled'] && $user['roles'] !== [];
    }

    public function isKnownRole(string $role): bool
    {
        return in_array($role, self::KNOWN_ROLES, true);
    }

    /**
     * Calcule en un seul appel tout ce que la session doit retenir après
     * authentification AD (login) ou à chaque requête (rafraîchissement,
     * voir AutoLogoutFilter) : jamais de rôle mis en cache au-delà de la
     * requête courante (§24).
     */
    public function resolveSession(string $username): array
    {
        $user = $this->getUser($username);
        $roles = $user['roles'] ?? [];
        $authorized = $user !== null && $user['enabled'] && $roles !== [];

        return [
            'roles'           => $roles,
            'enabled'         => $user['enabled'] ?? false,
            'display_name'    => $user['display_name'] ?? null,
            'authorized'      => $authorized,
            'is_mymemo_user'  => $authorized,
            'is_mymemo_admin' => $authorized && in_array(self::ROLE_ADMIN, $roles, true),
        ];
    }

    /**
     * Garde anti-dernier-admin (§20), à passer à
     * MyMemoUserRepository::update()/remove() pour s'exécuter DANS la même
     * section verrouillée que l'écriture (pas de condition de course entre
     * deux actions admin simultanées). Couvre uniformément désactivation,
     * révocation du rôle ADMIN et suppression — y compris l'auto-action d'un
     * admin seul, cas explicitement cité par la consigne.
     *
     * @throws \DomainException
     */
    public function lastAdminGuard(): callable
    {
        return function (array $allUsers, string $key, ?array $before, ?array $after): void {
            $wasAdmin = $before !== null && $before['enabled'] && in_array(self::ROLE_ADMIN, $before['roles'], true);
            if (! $wasAdmin) {
                return;
            }

            $stillAdmin = $after !== null && $after['enabled'] && in_array(self::ROLE_ADMIN, $after['roles'], true);
            if ($stillAdmin) {
                return;
            }

            foreach ($allUsers as $otherKey => $otherUser) {
                if ($otherKey === $key) {
                    continue;
                }
                if ($otherUser['enabled'] && in_array(self::ROLE_ADMIN, $otherUser['roles'], true)) {
                    return; // un autre administrateur actif subsiste
                }
            }

            throw new \DomainException('Impossible de retirer le dernier administrateur MyMemo.');
        };
    }
}
