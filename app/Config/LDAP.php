<?php

namespace Config;

class LDAP
{
    public string $host = '';
    public int $port = 636;
    public string $baseDn = '';
    public string $domain = '';
    public bool $startTls = false;
    public int $networkTimeout = 5;

    /**
     * Groupe(s) AD dont l'appartenance donne accès aux écrans réservés aux
     * administrateurs MyMemo (ex. Administration > Journal d'audit), utilisé
     * par AdminAuditFilter. Accepte un DN complet ou un simple nom de groupe
     * (CN), un ou plusieurs, séparés par des points-virgules dans .env
     * (un DN contenant lui-même des virgules, la virgule ne peut pas servir
     * de séparateur entre plusieurs groupes).
     *
     * Groupe AD officiel MyMemo : "MyMemoAdmins" (décision fonctionnelle du
     * 19/08/2026). Ni l'ancien groupe historique "Admins" ni
     * "ApplicationsAdmins" (groupe applicatif générique utilisé pendant le
     * diagnostic précédent) ne donnent les droits administrateur MyMemo.
     *
     * DÉPRÉCIÉ pour l'autorisation MyMemo : les rôles fonctionnels viennent
     * désormais de writable/security/users.csv (voir
     * docs/MYMEMO_USERS_CSV.md), plus des groupes AD. Conservé pour
     * compatibilité/historique.
     *
     * @var list<string>
     */
    public array $adminGroups = [];

    /**
     * Groupe(s) AD dont l'appartenance autorise l'usage fonctionnel de
     * MyMemo (import, génération, export). Même format que $adminGroups.
     *
     * DÉPRÉCIÉ pour l'autorisation MyMemo (voir $adminGroups ci-dessus).
     *
     * Groupe AD officiel MyMemo : "MyMemoUsers".
     *
     * @var list<string>
     */
    public array $userGroups = [];

    public function __construct()
    {
        $this->host = (string) env('ldap.host', '');
        $this->port = (int) env('ldap.port', 636);
        $this->baseDn = (string) env('ldap.baseDn', '');
        $this->domain = (string) env('ldap.domain', '');
        $this->startTls = filter_var(env('ldap.startTls', false), FILTER_VALIDATE_BOOL);
        $this->networkTimeout = max(1, (int) env('ldap.networkTimeout', 5));

        $adminGroups = (string) env('ldap.adminGroups', 'MyMemoAdmins');
        $this->adminGroups = array_values(array_filter(array_map('trim', explode(';', $adminGroups))));

        $userGroups = (string) env('ldap.userGroups', 'MyMemoUsers');
        $this->userGroups = array_values(array_filter(array_map('trim', explode(';', $userGroups))));
    }

    /** Build a PHP 8.1+-compatible LDAP connection URI. */
    public function uri(): string
    {
        $host = trim($this->host);
        if ($host === '') {
            return '';
        }

        if (! preg_match('#^ldaps?://#i', $host)) {
            $host = 'ldap://' . $host;
        }

        $parts = parse_url($host);
        if ($parts === false || ! isset($parts['host'])) {
            return '';
        }

        return sprintf(
            '%s://%s:%d',
            strtolower((string) ($parts['scheme'] ?? 'ldap')),
            $parts['host'],
            $parts['port'] ?? $this->port,
        );
    }
}
