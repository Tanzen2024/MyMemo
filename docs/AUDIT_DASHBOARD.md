# Journal d’audit

Le module `Administration > Journal d'audit` ne contacte jamais Oracle. `AuditReaderService` lit uniquement les fichiers quotidiens `writable/audit/YYYY-MM-DD.json` dans l’intervalle demandé, puis `AuditFilterService` valide les filtres.

Chaque événement est un objet JSON : `date`, `user`, `session`, `ip`, `user_agent`, `module`, `action`, `file`, `file_size`, `rows`, `started_at`, `ended_at`, `duration`, `status`, `message`, `error_stack`.

L’écran fournit DataTables (tri, pagination 25/50/100/tous), filtres, détail modal et exports CSV, JSON, Excel et HTML imprimable. L’accès est filtré par `auditadmin` (`AdminAuditFilter`).

Architecture : `AuditController` → `AuditFilterService` → `AuditReaderService` → `AuditEntry`; vues `app/Views/audit`; assets DataTables AdminLTE existants. Les fichiers JSON restent sous `writable/`, donc ne sont pas accessibles depuis le répertoire public.

## Droits MyMemo (utilisateur / administrateur)

Depuis la migration décrite dans [`MYMEMO_USERS_CSV.md`](MYMEMO_USERS_CSV.md), l'autorisation MyMemo (rôles `USER`/`ADMIN`) est pilotée par `writable/security/users.csv`, **plus par les groupes AD**. Active Directory reste strictement responsable de l'authentification. Voir ce document pour le détail complet (format du fichier, rôles multiples, protection du dernier administrateur, migration depuis `MyMemoUsers`/`MyMemoAdmins`).

## Utilisateurs MyMemo (`Administration > Utilisateurs MyMemo`)

Écran de gestion **en lecture-écriture** (voir [`MYMEMO_USERS_CSV.md`](MYMEMO_USERS_CSV.md)) : autoriser un compte AD existant, modifier ses rôles, l'activer/désactiver/supprimer. Ce n'est toujours pas une recherche Active Directory en direct (aucun compte de service LDAP configuré) — l'identifiant AD doit être connu et saisi par l'administrateur.
