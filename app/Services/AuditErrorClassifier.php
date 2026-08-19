<?php

namespace App\Services;

/**
 * Traduit une erreur technique (exception applicative ou erreur Oracle) en :
 *  - une catégorie/type d'erreur pour le journal d'audit (administrateur) ;
 *  - un message utilisateur sûr, sans détail technique ni trace ;
 *  - une sévérité (WARNING/ERROR/CRITICAL).
 *
 * Le mapping ne couvre que les cas réellement rencontrés dans ce projet
 * (messages métier déjà lisibles levés par ExcelImportService, absence de
 * données mémoire, erreurs Oracle usuelles, échec d'écriture fichier) ; tout
 * le reste retombe sur UNKNOWN_ERROR plutôt qu'un mapping Oracle exhaustif
 * inventé.
 */
final class AuditErrorClassifier
{
    /**
     * @return array{category:string,error_type:string,severity:string,user_message:string,technical_message:string}
     */
    public function classify(\Throwable $e): array
    {
        $technical = $e->getMessage();

        if ($e instanceof \App\Exceptions\NoDataException) {
            return $this->result('NO_DATA_ERROR', 'WARNING', $technical !== '' ? $technical : 'Aucune donnée disponible pour les critères sélectionnés.', $technical);
        }

        if ($e instanceof \App\Exceptions\FileGenerationException) {
            return $this->result('FILE_GENERATION_ERROR', 'ERROR', 'Le fichier n\'a pas pu être enregistré sur le serveur. Vérifiez l\'espace disponible ou contactez l\'administrateur.', $technical);
        }

        // Messages métier déjà rédigés de façon lisible par ExcelImportService :
        // on les remonte tels quels à l'utilisateur plutôt que de les reformuler.
        if ($e instanceof \RuntimeException) {
            if (str_contains($technical, 'illisible ou corrompu')) {
                return $this->result('VALIDATION_ERROR', 'WARNING', $technical, $technical);
            }
            if (str_contains($technical, 'fichier Excel est vide') || str_contains($technical, 'Aucune ligne valide')) {
                return $this->result('VALIDATION_ERROR', 'WARNING', $technical, $technical);
            }
            if (str_contains($technical, 'Oracle batch insert failed') || str_contains($technical, 'Oracle insert failed')) {
                return $this->result('DATABASE_ERROR', 'ERROR', 'Une erreur est survenue lors de l\'enregistrement des données. Contactez l\'administrateur.', $technical);
            }
        }

        if ($e instanceof \InvalidArgumentException) {
            return $this->result('VALIDATION_ERROR', 'WARNING', 'La demande contient des paramètres invalides ou incomplets.', $technical);
        }

        // Erreurs Oracle remontées comme exception (au lieu du retour false
        // silencieux normalement intercepté par OracleService/oracle_error) :
        // reconnues par préfixe ORA- déjà rencontré dans ce projet.
        if (preg_match('/ORA-\d{5}/', $technical, $m)) {
            $code = $m[0];
            $userMessage = match (true) {
                $code === 'ORA-12154', $code === 'ORA-12504', str_contains($technical, 'connexion') => 'Le service de données est momentanément inaccessible. Veuillez réessayer plus tard.',
                $code === 'ORA-01031' => 'Droits insuffisants pour accéder aux données demandées. Contactez l\'administrateur.',
                default => 'Une erreur de traitement des données empêche la génération du fichier. Contactez l\'administrateur.',
            };
            return $this->result('ORACLE_ERROR', 'ERROR', $userMessage, $technical);
        }

        if (str_contains($technical, 'Aucun fichier généré') || str_contains($technical, 'Aucune donnée')) {
            return $this->result('NO_DATA_ERROR', 'WARNING', 'Aucune donnée n\'a été trouvée pour les critères sélectionnés (période, cycle ou regroupement).', $technical);
        }

        return $this->result('UNKNOWN_ERROR', 'ERROR', 'Une erreur inattendue est survenue pendant le traitement. Contactez l\'administrateur si le problème persiste.', $technical);
    }

    private function result(string $errorType, string $severity, string $userMessage, string $technicalMessage): array
    {
        $category = match (true) {
            str_starts_with($errorType, 'ORACLE') || str_starts_with($errorType, 'DATABASE') => 'DATABASE',
            default => 'APPLICATION',
        };

        return [
            'category'           => $category,
            'error_type'         => $errorType,
            'severity'           => $severity,
            'user_message'       => $userMessage,
            'technical_message'  => $technicalMessage,
        ];
    }

    /** Référence d'incident communicable à l'utilisateur (support). */
    public static function newIncidentRef(): string
    {
        return 'AUD-' . date('Ymd') . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Identifiant reliant toutes les étapes d'une même opération (import → génération → export). */
    public static function newCorrelationId(): string
    {
        return 'CORR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
