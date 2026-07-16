<?php
namespace App\Services;

final class AuditFilterService
{
    public function sanitize(array $input): array
    {
        $date = static fn ($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null;
        $status = strtoupper((string) ($input['status'] ?? ''));
        return [
            'date_from' => $date($input['date_from'] ?? date('Y-m-d', strtotime('-30 days'))),
            'date_to' => $date($input['date_to'] ?? date('Y-m-d')),
            'user' => trim((string) ($input['user'] ?? '')),
            'module' => trim((string) ($input['module'] ?? '')),
            'action' => trim((string) ($input['action'] ?? '')),
            'status' => in_array($status, ['SUCCESS', 'WARNING', 'ERROR'], true) ? $status : '',
            'file' => trim((string) ($input['file'] ?? '')),
            'search' => trim((string) ($input['search'] ?? '')),
            'sort' => in_array($input['sort'] ?? '', ['date','user','module','action','file','rows','duration','ip','status','message'], true) ? $input['sort'] : 'date',
            'direction' => strtolower((string) ($input['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }
}
