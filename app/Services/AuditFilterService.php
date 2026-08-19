<?php
namespace App\Services;

final class AuditFilterService
{
    public function sanitize(array $input): array
    {
        $date = static fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null;
        $status = strtoupper((string) ($input['status'] ?? ''));
        $severity = strtoupper((string) ($input['severity'] ?? ''));
        $category = strtoupper((string) ($input['category'] ?? ''));
        return [
            'date_from' => $date($input['date_from'] ?? date('Y-m-d', strtotime('-30 days'))),
            'date_to' => $date($input['date_to'] ?? date('Y-m-d')),
            'user' => trim((string) ($input['user'] ?? '')),
            'module' => trim((string) ($input['module'] ?? '')),
            'action' => trim((string) ($input['action'] ?? '')),
            // FAILED/REFUSED sont des valeurs de "status" réellement écrites
            // (login échoué, verrou d'import refusé) en plus de SUCCESS/WARNING/ERROR.
            'status' => in_array($status, ['SUCCESS', 'FAILED', 'WARNING', 'ERROR', 'REFUSED'], true) ? $status : '',
            'severity' => in_array($severity, ['INFO', 'SUCCESS', 'WARNING', 'ERROR', 'CRITICAL'], true) ? $severity : '',
            'category' => in_array($category, ['AUTHENTICATION', 'AUTHORIZATION', 'IMPORT', 'EXPORT', 'MEMORY', 'DATABASE', 'APPLICATION', 'SECURITY'], true) ? $category : '',
            'correlation_id' => trim((string) ($input['correlation_id'] ?? '')),
            'incident_ref' => trim((string) ($input['incident_ref'] ?? '')),
            'file' => trim((string) ($input['file'] ?? '')),
            'search' => trim((string) ($input['search'] ?? '')),
            'sort' => in_array($input['sort'] ?? '', ['date','user','module','action','file','rows','duration','ip','status','severity','message'], true) ? $input['sort'] : 'date',
            'direction' => strtolower((string) ($input['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }
}
