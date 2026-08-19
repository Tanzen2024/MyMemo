<?php
namespace App\Models;

final class AuditEntry
{
    public static function fromArray(array $entry): array
    {
        $entry = array_change_key_case($entry, CASE_LOWER);
        return [
            'date' => (string) ($entry['date'] ?? $entry['timestamp'] ?? ''),
            'user' => (string) ($entry['user'] ?? $entry['username'] ?? 'system'),
            'session' => (string) ($entry['session'] ?? ''),
            'ip' => (string) ($entry['ip'] ?? $entry['ip_address'] ?? ''),
            'user_agent' => (string) ($entry['user_agent'] ?? ''),
            'module' => (string) ($entry['module'] ?? ''),
            'action' => (string) ($entry['action'] ?? ''),
            'file' => (string) ($entry['file'] ?? $entry['filename'] ?? ''),
            'file_size' => (int) ($entry['file_size'] ?? 0),
            'rows' => (int) ($entry['rows'] ?? $entry['line_count'] ?? 0),
            'started_at' => (string) ($entry['started_at'] ?? ''),
            'ended_at' => (string) ($entry['ended_at'] ?? ''),
            'duration' => (float) ($entry['duration'] ?? 0),
            'status' => strtoupper((string) ($entry['status'] ?? 'SUCCESS')),
            'message' => (string) ($entry['message'] ?? ''),
            'error_stack' => (string) ($entry['error_stack'] ?? ''),
            // Champs "audit enterprise" : présents seulement sur les événements
            // instrumentés en conséquence (authentification, import, mémoire,
            // erreurs Oracle) ; vides sinon, sans casser les anciennes entrées.
            'category' => strtoupper((string) ($entry['category'] ?? '')),
            'severity' => strtoupper((string) ($entry['severity'] ?? self::defaultSeverity((string) ($entry['status'] ?? 'SUCCESS')))),
            'error_type' => strtoupper((string) ($entry['error_type'] ?? '')),
            'correlation_id' => (string) ($entry['correlation_id'] ?? ''),
            'incident_ref' => (string) ($entry['incident_ref'] ?? ''),
            'user_message' => (string) ($entry['user_message'] ?? ''),
            'technical_message' => (string) ($entry['technical_message'] ?? ''),
            'exception' => (string) ($entry['exception'] ?? ''),
            'raw' => $entry,
        ];
    }

    /** Sévérité par défaut pour les entrées écrites avant l'introduction du champ. */
    private static function defaultSeverity(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS' => 'SUCCESS',
            'WARNING', 'REFUSED' => 'WARNING',
            'FAILED', 'ERROR' => 'ERROR',
            default => 'INFO',
        };
    }
}
