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

    public function __construct()
    {
        $this->host = (string) env('ldap.host', '');
        $this->port = (int) env('ldap.port', 636);
        $this->baseDn = (string) env('ldap.baseDn', '');
        $this->domain = (string) env('ldap.domain', '');
        $this->startTls = filter_var(env('ldap.startTls', false), FILTER_VALIDATE_BOOL);
        $this->networkTimeout = max(1, (int) env('ldap.networkTimeout', 5));
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
