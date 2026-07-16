<?php

namespace Config;

class LDAP
{
    public string $host = '';
    public int $port = 636;
    public string $baseDn = '';
    public string $domain = '';

    public function __construct()
    {
        $this->host = (string) env('ldap.host', '');
        $this->port = (int) env('ldap.port', 636);
        $this->baseDn = (string) env('ldap.baseDn', '');
        $this->domain = (string) env('ldap.domain', '');
    }
}
