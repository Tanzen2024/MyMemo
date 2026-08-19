<?php

namespace App\Exceptions;

/**
 * Aucune donnée métier disponible pour les critères demandés (période,
 * cycle, regroupement...). Distincte d'une erreur technique : c'est une
 * situation attendue, pas un dysfonctionnement.
 */
class NoDataException extends \RuntimeException
{
}
