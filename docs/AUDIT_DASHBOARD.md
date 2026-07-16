# Journal d’audit

Le module `Administration > Journal d'audit` ne contacte jamais Oracle. `AuditReaderService` lit uniquement les fichiers quotidiens `writable/audit/YYYY-MM-DD.json` dans l’intervalle demandé, puis `AuditFilterService` valide les filtres.

Chaque événement est un objet JSON : `date`, `user`, `session`, `ip`, `user_agent`, `module`, `action`, `file`, `file_size`, `rows`, `started_at`, `ended_at`, `duration`, `status`, `message`, `error_stack`.

L’écran fournit DataTables (tri, pagination 25/50/100/tous), filtres, détail modal et exports CSV, JSON, Excel et HTML imprimable. L’accès est filtré par `auditadmin`; une session doit avoir `is_admin` ou appartenir au groupe LDAP Administrateurs.

Architecture : `AuditController` → `AuditFilterService` → `AuditReaderService` → `AuditEntry`; vues `app/Views/audit`; assets DataTables AdminLTE existants. Les fichiers JSON restent sous `writable/`, donc ne sont pas accessibles depuis le répertoire public.
