<?php

namespace App\Controllers;

/**
 * Compatibility entry point for code that still refers to AuthLDAP.
 *
 * Routes use Views\AuthentificationController; inheriting it prevents this
 * legacy file from retaining a second, divergent LDAP implementation.
 */
class AuthLDAP extends \App\Controllers\Views\AuthentificationController
{
}
