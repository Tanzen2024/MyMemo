<?php

namespace App\Services;

use CodeIgniter\HTTP\RequestInterface;

final class AuditLoggerService
{
    public function log(string $action, string $status, float $duration, RequestInterface $request, array $context = []): void
    {
        $directory = WRITEPATH . 'audit' . DIRECTORY_SEPARATOR;
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            log_message('error', 'Impossible de créer le répertoire d’audit.');
            return;
        }

        // Un échec/erreur d'authentification ne doit jamais conserver
        // l'identifiant saisi (non vérifié) ; un succès, en revanche, DOIT
        // enregistrer l'identité désormais vérifiée par l'annuaire AD.
        $isUnverifiedLoginAttempt = in_array($action, ['login', 'LOGIN_SUCCESS', 'LOGIN_FAILED', 'LOGIN_ERROR'], true)
            && $status !== 'SUCCESS';

        // Un appelant peut fournir explicitement l'utilisateur via $context
        // (ex. SESSION_EXPIRED, journalisé après destruction de la session :
        // session()->get('username') ne serait alors plus disponible).
        $user = $isUnverifiedLoginAttempt
            ? 'anonymous'
            : (string) ($context['user'] ?? session()->get('username') ?? 'anonymous');
        unset($context['user']);

        $entry = [
            'date'     => date('c'),
            'user'     => $user,
            'ip'       => $request->getIPAddress(),
            'action'   => $action,
            'status'   => $status,
            'duration' => round($duration, 4),
        ] + self::redactSensitive($context);

        // Format JSON Lines (une entrée = une ligne, écriture en ajout pur) :
        // contrairement à un tableau JSON unique, ceci évite de relire et de
        // réécrire tout le fichier du jour à chaque événement (O(1) au lieu
        // de O(n) par écriture, donc O(n²) cumulé sur la journée).
        $handle = @fopen($directory . date('Y-m-d') . '.jsonl', 'a');
        if ($handle === false) {
            log_message('error', 'Impossible d’écrire le journal d’audit.');
            return;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            fwrite($handle, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Filet de sécurité (défense en profondeur) : même si un appelant
     * introduisait par erreur une clé sensible dans $context (mot de passe,
     * token, secret...), sa valeur est remplacée avant écriture. Ne remplace
     * pas la discipline des appelants (aucun ne doit passer ces valeurs),
     * mais garantit qu'une régression future ne fuite jamais de secret.
     */
    private static function redactSensitive(array $context): array
    {
        static $pattern = '/password|passwd|token|secret|credential|api[_-]?key|cookie|authorization/i';

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = self::redactSensitive($value);
            } elseif (is_string($key) && preg_match($pattern, $key)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }
}
