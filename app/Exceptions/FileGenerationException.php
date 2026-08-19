<?php

namespace App\Exceptions;

/**
 * Le fichier attendu (Excel, ZIP...) n'a pas pu être écrit, ou a été écrit
 * mais est absent/vide à l'emplacement attendu au moment de la vérification
 * post-génération.
 */
class FileGenerationException extends \RuntimeException
{
}
