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
        // .jsonl (nouveau format, écriture en ajout) + .json (anciens journaux,
        // un unique tableau) pour ne pas perdre l'historique déjà écrit.
        $files = array_merge(glob($this->directory . '*.jsonl') ?: [], glob($this->directory . '*.json') ?: []);
        $rows = [];
        foreach ($files as $file) {
            $day = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ($filters['date_from'] && $day < $filters['date_from']) || ($filters['date_to'] && $day > $filters['date_to'])) continue;
            foreach ($this->readEvents($file) as $event) if (is_array($event)) { $row = AuditEntry::fromArray($event); if ($this->matches($row, $filters)) $rows[] = $row; }
        }
        usort($rows, fn($a,$b) => ($filters['direction'] === 'asc' ? 1 : -1) * (($a[$filters['sort']] ?? '') <=> ($b[$filters['sort']] ?? '')));
        return $rows;
    }

    /**
     * Lit un fichier d'audit ligne par ligne pour le format .jsonl (une entrée
     * par ligne, pas besoin de charger tout le fichier d'un coup) ; conserve
     * la lecture d'un tableau JSON unique pour les anciens fichiers .json.
     */
    private function readEvents(string $file): iterable
    {
        if (!str_ends_with($file, '.jsonl')) {
            $json = json_decode((string) file_get_contents($file), true);
            foreach (($json['events'] ?? $json ?? []) as $event) yield $event;
            return;
        }

        $handle = @fopen($file, 'r');
        if ($handle === false) return;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $event = json_decode($line, true);
                if (is_array($event)) yield $event;
            }
        } finally {
            fclose($handle);
        }
    }
    private function matches(array $row, array $filters): bool
    {
        foreach (['user','module','action','file','status','severity','category','correlation_id','incident_ref'] as $field) {
            if (($filters[$field] ?? '') !== '' && stripos((string) ($row[$field] ?? ''), $filters[$field]) === false) return false;
        }
        if ($filters['search'] === '') return true;
        return stripos(implode(' ', array_map('strval', [$row['date'],$row['user'],$row['module'],$row['action'],$row['file'],$row['message'],$row['user_message'] ?? '',$row['incident_ref'] ?? ''])), $filters['search']) !== false;
    }
}
