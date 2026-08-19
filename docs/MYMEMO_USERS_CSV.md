# Autorisation MyMemo (writable/security/users.csv)

## Principe

Active Directory reste l'**unique** source d'authentification (identité + mot de passe, via LDAP bind — inchangé). Les **rôles fonctionnels** MyMemo (accès applicatif, administration, gestion des utilisateurs, journal d'audit) proviennent exclusivement de `writable/security/users.csv`. Les groupes AD `MyMemoUsers`/`MyMemoAdmins` (et `App\Libraries\LdapGroupMatcher`, `Config\LDAP::$userGroups`/`$adminGroups`) restent dans le code et éventuellement dans AD pour compatibilité/historique, mais ne sont plus consultés pour décider d'un accès.

```
AD/LDAP (authentification) → username AD (sAMAccountName) → users.csv (autorisation) → USER / ADMIN
```

## Identifiant

La clé d'autorisation est le `sAMAccountName` AD (forme courte, sans `@domaine`), déjà calculé par `AuthentificationController::doLogin()` pour la recherche LDAP — c'est l'identifiant AD fiable et stable, indépendant de ce que l'utilisateur a saisi (`jdupont` ou `jdupont@camlight.cm` donnent le même identifiant). Comparaison normalisée : `strtolower(trim($sAMAccountName))` (`MyMemoUserRepository::normalize()`), insensible à la casse et aux espaces. La valeur stockée dans le CSV n'est jamais réécrite par une simple comparaison — seule une action explicite (Ajouter/Modifier) la modifie.

## Format du fichier

`writable/security/users.csv`, créé automatiquement (avec un premier compte `ADMIN` réel, voir plus bas) s'il n'existe pas :

```csv
username,roles,enabled,display_name
hugues.nwameh,ADMIN,1,Hugues Roland NWAMEH
jean.dupont,USER,1,Jean DUPONT
```

- `roles` : un ou plusieurs rôles séparés par `|` (ex. `"ADMIN|USER"`) — le `|` ne rentre jamais en conflit avec le délimiteur `,` du CSV.
- `enabled` : `1` ou `0` strictement.
- Toute lecture/écriture passe par `App\Services\MyMemoUserRepository` (jamais d'accès fichier direct dans un contrôleur) : écriture verrouillée (`flock`), atomique (fichier temporaire + `rename()`), sauvegardée avant modification dans `writable/security/backups/users_YYYYMMDD_HHMMSS.csv`.

## Rôles et permissions

Centralisés dans `App\Services\MyMemoAuthorizationService` (jamais de `if ($role === 'ADMIN')` dispersé dans les contrôleurs) :

| Rôle    | Capacités                                                              |
|---------|-------------------------------------------------------------------------|
| `USER`  | Accès fonctionnel MyMemo                                                |
| `ADMIN` | Accès fonctionnel + Administration + Gestion des utilisateurs + Journal d'audit |

Un utilisateur avec plusieurs rôles cumule l'**union** de leurs capacités. Ajouter un rôle futur (`REPORT_VIEWER`, `AUDITOR`...) se fait dans `MyMemoAuthorizationService::KNOWN_ROLES`/`ROLE_CAPABILITIES` uniquement.

## Règles de refus (deny-by-default)

- Authentification AD échouée → refusé (inchangé).
- Authentifié mais absent de `users.csv` → refusé.
- Présent mais `enabled=0` → refusé.
- Présent, actif, mais sans aucun rôle → refusé.
- Le message affiché au navigateur est volontairement générique (« compte non autorisé ») dans les trois derniers cas ; le détail exact (absent/désactivé/sans rôle) est journalisé dans l'événement d'audit `ACCESS_DENIED`.

Vérifié au login (`AuthentificationController::doLogin()`, refus immédiat sans créer de session) **et** à chaque requête protégée (`AutoLogoutFilter`, filtre global) — un changement de rôle ou une désactivation prend donc effet immédiatement, sans attendre une reconnexion. `AdminAuditFilter` n'a pas été modifié : il continue de lire `session('is_mymemo_admin')`, garanti à jour par ce rafraîchissement.

## Gestion des utilisateurs (`Administration > Utilisateurs MyMemo`)

CRUD complet (`MyMemoUsersController` + `mymemo_users/index.php`/`form.php`), réservé aux `ADMIN` (filtre de route `auditadmin`, inchangé). « Ajouter » **autorise un compte AD existant** — ne crée ni compte AD, ni mot de passe, ni compte local ; l'identifiant est saisi manuellement (aucun compte de service LDAP disponible pour une recherche en direct).

- Rôles acceptés : uniquement ceux de `MyMemoAuthorizationService::KNOWN_ROLES`, jamais une valeur arbitraire envoyée par le navigateur.
- **Protection du dernier administrateur** : impossible de désactiver, supprimer, ou retirer le rôle `ADMIN` au dernier `ADMIN` actif restant (y compris pour lui-même) — vérifié dans la même section verrouillée que l'écriture, message : « Impossible de retirer le dernier administrateur MyMemo. »
- Toute mutation est auditée via `AuditLoggerService` (`MYMEMO_USER_CREATED/UPDATED/ENABLED/DISABLED/DELETED`, `MYMEMO_ROLE_GRANTED/REVOKED`), au même endroit que le reste du Journal d'audit — aucun second système d'audit.

## Sécurité du fichier

`writable/security/` est déjà bloqué en accès HTTP direct par `writable/.htaccess` (`Require all denied`), comme tout le reste de `writable/` — aucune configuration Apache supplémentaire n'était nécessaire.

## Migration depuis MyMemoUsers/MyMemoAdmins

`App\Libraries\LdapGroupMatcher` et `Config\LDAP::$userGroups`/`$adminGroups` restent dans le code (non supprimés), mais ne sont plus appelés par le chemin d'autorisation réel — dépréciés, suppression envisageable dans une tâche ultérieure une fois validé en production. Les groupes AD eux-mêmes peuvent rester en place sans effet sur MyMemo.

## Amorçage initial

Le premier `ADMIN` (`hugues.nwameh`, « Hugues Roland NWAMEH ») correspond au compte AD réellement observé lors des connexions LDAP effectuées sur ce projet — aucune valeur inventée.
