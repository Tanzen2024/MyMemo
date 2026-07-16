<?php
namespace App\Services;

use App\Models\AuditEntry;

final class AuditReaderService
{
    private string $directory;
    public function __construct(?string $directory = null) { $this->directory = $directory ?? WRITEPATH . 'audit' . DIRECTORY_SEPARATOR; }
    /** Reads only daily files whose names belong to the requested date interval. */
    public function find(array $filters): array
    {
        $files = glob($this->directory . '*.json') ?: [];
        $rows = [];
        foreach ($files as $file) {
            $day = basename($file, '.json');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ($filters['date_from'] && $day < $filters['date_from']) || ($filters['date_to'] && $day > $filters['date_to'])) continue;
            $json = json_decode((string) file_get_contents($file), true);
            foreach (($json['events'] ?? $json ?? []) as $event) if (is_array($event)) { $row = AuditEntry::fromArray($event); if ($this->matches($row, $filters)) $rows[] = $row; }
        }
        usort($rows, fn($a,$b) => ($filters['direction'] === 'asc' ? 1 : -1) * (($a[$filters['sort']] ?? '') <=> ($b[$filters['sort']] ?? '')));
        return $rows;
    }
    private function matches(array $row, array $filters): bool
    {
        foreach (['user','module','action','file','status'] as $field) if ($filters[$field] !== '' && stripos((string) $row[$field], $filters[$field]) === false) return false;
        if ($filters['search'] === '') return true;
        return stripos(implode(' ', array_map('strval', [$row['date'],$row['user'],$row['module'],$row['action'],$row['file'],$row['message']])), $filters['search']) !== false;
    }
}
