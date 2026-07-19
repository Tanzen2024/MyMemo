<?php

namespace App\Filters;

use App\Services\AuditLoggerService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class ImportManagerFilter implements FilterInterface
{
    public function __construct(private readonly ?AuditLoggerService $auditLogger = null)
    {
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        $startedAt = microtime(true);
        $roles = (array) session()->get('roles');

        if (in_array(AuditLoggerService::ROLE_IMPORT_MANAGER, $roles, true)) {
            return null;
        }

        ($this->auditLogger ?? new AuditLoggerService())->log(
            'sensitive_operation_authorization',
            'REFUSED',
            microtime(true) - $startedAt,
            $request,
            ['message' => 'Le rôle ROLE_IMPORT_MANAGER est requis.']
        );

        return service('response')
            ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN)
            ->setJSON(['message' => 'Accès refusé : le rôle ROLE_IMPORT_MANAGER est requis.']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
