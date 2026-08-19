<?php

namespace App\Libraries;

/**
 * Compare un ensemble de groupes AD réels (memberOf, tels que capturés en
 * session au login) à un ensemble de groupes configurés (ldap.adminGroups,
 * ldap.userGroups...), de façon tolérante :
 *  - insensible à la casse et aux espaces superflus ;
 *  - accepte que la valeur configurée soit un DN complet OU un simple nom
 *    de groupe (CN), auquel cas seul le CN de chaque groupe réel est
 *    comparé (utile car la profondeur des OU peut varier selon le groupe
 *    dans l'annuaire) ;
 *  - gère plusieurs groupes réels et plusieurs groupes configurés.
 *
 * Logique extraite de AdminAuditFilter (où elle ne servait qu'aux droits
 * administrateur) pour être réutilisée telle quelle par la détermination
 * des droits utilisateur MyMemo (is_mymemo_user).
 *
 * DÉPRÉCIÉ pour l'autorisation MyMemo : depuis le passage à
 * writable/security/users.csv comme source de vérité des rôles (voir
 * docs/MYMEMO_USERS_CSV.md et App\Services\MyMemoAuthorizationService),
 * cette classe n'est plus appelée par le chemin d'autorisation réel.
 * Conservée pour compatibilité/historique (tests existants), suppression
 * envisageable une fois validé.
 */
final class LdapGroupMatcher
{
    public static function matchesAny(array $groups, array $configuredGroups): bool
    {
        if ($configuredGroups === []) {
            return false;
        }

        $normalizedRealGroups = array_map(
            static fn ($g) => strtolower(trim((string) $g)),
            $groups
        );

        foreach ($configuredGroups as $configured) {
            $configured = strtolower(trim($configured));
            if ($configured === '') {
                continue;
            }

            // Comparaison DN complet, exacte après normalisation.
            if (in_array($configured, $normalizedRealGroups, true)) {
                return true;
            }

            // Valeur configurée sous forme de simple nom de groupe (pas un
            // DN) : comparer uniquement le CN de chaque groupe réel.
            if (!str_starts_with($configured, 'cn=')) {
                foreach ($normalizedRealGroups as $realGroup) {
                    if (preg_match('/^cn=([^,]+)/', $realGroup, $m) && trim($m[1]) === $configured) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Détermine les droits MyMemo d'un utilisateur à partir de ses groupes
     * AD réels et de la configuration (ldap.userGroups / ldap.adminGroups).
     *
     * Règle obligatoire : un membre de $adminGroups a toujours les droits
     * utilisateur MyMemo, même s'il n'apparaît pas explicitement dans
     * $userGroups — MyMemoAdmins implique fonctionnellement MyMemoUsers,
     * sans dépendre d'une imbrication de groupes remontée par AD.
     *
     * @return array{is_mymemo_user: bool, is_mymemo_admin: bool}
     */
    public static function computeMyMemoRoles(array $groups, array $userGroups, array $adminGroups): array
    {
        $isAdmin = self::matchesAny($groups, $adminGroups);

        return [
            'is_mymemo_user'  => $isAdmin || self::matchesAny($groups, $userGroups),
            'is_mymemo_admin' => $isAdmin,
        ];
    }
}
