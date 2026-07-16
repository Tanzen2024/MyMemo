<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;
use CodeIgniter\HTTP\ResponseInterface;

class DatabaseController extends Controller
{
    /**
     * Vérifie la disponibilité de la base Oracle
     * Endpoint technique (health check)
     */
    public function oracleStatus(): ResponseInterface
    {
        try {
            $db = Database::connect('oracle');

            if (! $db->connID) {
                throw new \RuntimeException('Connexion Oracle indisponible');
            }

            // Ping léger (sans impact)
            $db->query('SELECT 1 FROM dual');

            return $this->response->setJSON([
                'status'  => 'UP',
                'service' => 'oracle'
            ])->setStatusCode(ResponseInterface::HTTP_OK);

        } catch (\Throwable $e) {

            log_message('error', '[ORACLE] ' . $e->getMessage());

            return $this->response->setJSON([
                'status'  => 'DOWN',
                'service' => 'oracle'
            ])->setStatusCode(ResponseInterface::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
