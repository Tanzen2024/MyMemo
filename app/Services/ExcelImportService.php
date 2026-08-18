<?php

namespace App\Services;

use Config\ReferentielImportConfig;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use CodeIgniter\HTTP\IncomingRequest;
use App\Services\OracleService;

class ExcelImportService
{
    protected array $lastImportedData = [];

    /**
     * Nombre de lignes regroupées par requête Oracle (INSERT ALL) lors de
     * l'insertion. Réduit le nombre d'aller-retours réseau/parse par rapport
     * à une insertion ligne par ligne, sans changer les données insérées.
     */
    private const BATCH_SIZE = 200;

    /**
     * Importe un fichier Excel dans la table Oracle
     * Toutes les valeurs sont converties en texte (VARCHAR2)
     */
    public function import(string $type, string $filePath, OracleService $oracle): array
    {
        $startedAt = microtime(true);
        log_message('info', "Import Excel démarré : type={$type}, fichier=" . basename($filePath));

        $configs = ReferentielImportConfig::get();
        if (!isset($configs[$type])) {
            throw new RuntimeException("Type invalide : {$type}");
        }

        $config = $configs[$type];

        // Lecture du fichier Excel. setReadDataOnly() évite de charger les
        // styles/mises en forme/dessins (non utilisés ici, seules les valeurs
        // le sont via toArray()) : gain notable de vitesse et de mémoire sur
        // les gros fichiers, sans changer les valeurs lues.
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            log_message('error', "Import Excel : fichier illisible ({$filePath}) : " . $e->getMessage());
            throw new RuntimeException('Le fichier Excel est illisible ou corrompu.', 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, true);

        if (count($rows) <= 1) {
            log_message('warning', "Import Excel : fichier vide (type={$type}).");
            throw new RuntimeException('Le fichier Excel est vide (aucune ligne de données après l\'en-tête).');
        }

        $data       = [];
        $skipped    = 0;
        $duplicates = 0;
        $seenKeys   = [];

        // Parcours des lignes Excel
        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // ignorer l'en-tête

            try {
                $mapped = [];
                $valid = true;

                // Mapper les colonnes Excel vers la base
                foreach ($config['map'] as $excelIndex => $dbField) {
                    $col = chr(65 + $excelIndex);
                    $raw = $row[$col] ?? null;

                    // Conversion en texte
                    $value = $this->formatValue(
                        $raw,
                        $config['types'][$dbField] ?? 'string'
                    );

                    if ($value === '__INVALID__') {
                        $valid = false;
                        break;
                    }

                    $mapped[$dbField] = $value;
                }

                // Vérification des champs obligatoires
                foreach ($config['required'] as $req) {
                    if (!isset($mapped[$req]) || $mapped[$req] === '') {
                        $valid = false;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                // Une valeur de cellule inattendue (ex. date Excel invalide) ne doit
                // pas interrompre tout l'import : la ligne est simplement rejetée.
                $skipped++;
                log_message('debug', "Import Excel : ligne {$i} ignorée (erreur de conversion) : " . $e->getMessage());
                continue;
            }

            if (!$valid) {
                $skipped++;
                $reason = [];

                foreach ($config['required'] as $req) {
                    if (!isset($mapped[$req]) || $mapped[$req] === '') {
                        $reason[] = "champ manquant: {$req}";
                    }
                }

                log_message('debug', "Import Excel : ligne {$i} rejetée" . ($reason !== [] ? ' (' . implode(', ', $reason) . ')' : ' (valeur invalide)') . '.');
                continue;
            }

            // Prévention des doublons : une même clé métier (champs obligatoires
            // du référentiel) ne doit pas être insérée plusieurs fois pour un
            // même import, sous peine de fausser les agrégats calculés en aval.
            $key = implode('|', array_map(
                static fn (string $f) => strtoupper(trim((string) ($mapped[$f] ?? ''))),
                $config['required']
            ));

            if (isset($seenKeys[$key])) {
                $duplicates++;
                log_message('warning', "Import Excel : ligne {$i} ignorée, doublon de la ligne {$seenKeys[$key]} (clé: {$key}).");
                continue;
            }

            $seenKeys[$key] = $i;
            $data[] = $mapped;
        }

        if (!$data) {
            $message = $duplicates > 0
                ? "Aucune ligne valide : {$skipped} rejetée(s), {$duplicates} doublon(s)."
                : "Aucune ligne valide : {$skipped} rejetée(s) (champs obligatoires manquants ou invalides).";

            log_message('warning', "Import Excel : {$message}");
            throw new RuntimeException($message);
        }

        $this->lastImportedData = $data;

        // Insertion Oracle par lots (INSERT ALL) : en cas d'échec d'un lot,
        // repli automatique sur une insertion ligne par ligne pour isoler
        // précisément la ou les lignes fautives (mêmes résultats qu'une
        // insertion ligne par ligne, en beaucoup moins d'aller-retours réseau).
        $inserted = 0;

        foreach (array_chunk($data, self::BATCH_SIZE) as $batch) {
            [$batchInserted, $batchSkipped] = $this->insertBatch($oracle, $config['table'], $batch);
            $inserted += $batchInserted;
            $skipped  += $batchSkipped;
        }

        $duration = round(microtime(true) - $startedAt, 2);
        $message  = "{$inserted} ligne(s) importée(s), {$skipped} rejetée(s), {$duplicates} doublon(s) ignoré(s).";

        log_message('info', "Import Excel terminé (type={$type}, table={$config['table']}) en {$duration}s : {$message}");

        return [
            'inserted'   => $inserted,
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
            'message'    => $message,
        ];
    }

    /**
     * Insère un lot de lignes en une seule requête Oracle (INSERT ALL ... SELECT 1 FROM DUAL).
     * Si le lot échoue (contrainte, valeur invalide, etc.), il est rejoué ligne
     * par ligne afin d'isoler exactement la ou les lignes fautives et de
     * conserver le même niveau de granularité d'erreur qu'une insertion unitaire.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: int, 1: int} [inséré, rejeté]
     */
    private function insertBatch(OracleService $oracle, string $table, array $rows): array
    {
        if (count($rows) === 1) {
            return $this->insertRows($oracle, $table, $rows);
        }

        $cols        = implode(',', array_keys($rows[0]));
        $intoClauses = [];
        $binds       = [];

        foreach (array_values($rows) as $r => $row) {
            $placeholders = [];

            foreach ($row as $col => $value) {
                $bindKey        = "b{$r}_{$col}";
                $placeholders[] = ":{$bindKey}:";
                $binds[$bindKey] = $value;
            }

            $intoClauses[] = "INTO {$table} ({$cols}) VALUES (" . implode(',', $placeholders) . ')';
        }

        $sql = "INSERT ALL\n" . implode("\n", $intoClauses) . "\nSELECT 1 FROM DUAL";

        try {
            if ($oracle->executeSql($sql, $binds) === false) {
                throw new RuntimeException('Oracle batch insert failed.');
            }

            return [count($rows), 0];
        } catch (\Throwable $e) {
            log_message('warning', 'Import Excel : échec du lot (' . count($rows) . ' lignes), nouvelle tentative ligne par ligne : ' . $e->getMessage());

            return $this->insertRows($oracle, $table, $rows);
        }
    }

    /**
     * Insertion ligne par ligne (lot de taille 1, ou repli après échec d'un lot groupé).
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: int, 1: int} [inséré, rejeté]
     */
    private function insertRows(OracleService $oracle, string $table, array $rows): array
    {
        $inserted = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $cols = implode(',', array_keys($row));

            // Toutes les valeurs sont insérées en VARCHAR2
            $placeholders = implode(',', array_fill(0, count($row), '?'));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";

            try {
                if ($oracle->executeSql($sql, array_values($row)) === false) {
                    throw new RuntimeException('Oracle insert failed.');
                }
                $inserted++;
            } catch (\Throwable $e) {
                log_message('error', 'Import Excel : échec insertion (' . json_encode($row) . ') : ' . $e->getMessage());
                $skipped++;
            }
        }

        return [$inserted, $skipped];
    }

    /**
     * Conversion en texte uniforme pour Oracle VARCHAR2
     */
    private function formatValue($value, string $type)
    {
        if (is_numeric($value) && $type !== 'date') {
            return number_format($value, 0, '', '');
        }
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        // Tout convertir en texte
        $str = trim((string)$value);

        // Nettoyer séparateurs de milliers pour les nombres
       if (in_array($type, ['string', 'varchar'])) {
            return trim($str);
        }

        if (in_array($type, ['int', 'number'])) {
            return preg_replace('/[^0-9]/', '', $str);
        }

        // Dates converties en texte YYYY-MM-DD
        if ($type === 'date') {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            }
            $ts = strtotime($value);
            return $ts ? date('Y-m-d', $ts) : null;
        }

        return $str;
    }

    public function getLastImportedData(): array
    {
        return $this->lastImportedData;
    }

    public function determineReferentielType(IncomingRequest $request): array
    {
        $context = $request->getPost('referentiel_context');
        $type    = $request->getPost('radio_referentiel_type_' . $context);
        $niveau  = $request->getPost('radio_postpaid_bt_mt_etat');

        if (!$context || !$type) {
            throw new \InvalidArgumentException('Contexte ou type de référentiel manquant.');
        }

        switch ($context) {
            case 'postpaid_general':
                $scope = $request->getPost('regroupName_postpaid_general');
                return [
                    'context' => 'postpaid_general',
                    'type' => match ($type) {
                        'contrat' => $scope . '_contrat',
                        'facture' => $scope . '_facture',
                        default => throw new \InvalidArgumentException('Type postpaid région invalide'),
                    }
                ];
            case 'postpaid_particulier':
                return [
                    'context' => 'postpaid_particulier',
                    'type' => match ($type) {
                        'contrat' => $context . '_contrat',
                        'facture' => $context . '_facture',
                        default => throw new \InvalidArgumentException('Type postpaid invalide'),
                    }
                ];
            case 'postpaid_etat':
                if (!$niveau) {
                    throw new \InvalidArgumentException('Niveau BT / MT manquant pour ETAT.');
                }
                return [
                    'context' => 'postpaid_etat',
                    'type' => match (true) {
                        $type === 'contrat' && $niveau === 'bt' => 'etat_bt_contrat',
                        $type === 'contrat' && $niveau === 'mt' => 'etat_mt_contrat',
                        $type === 'facture' && $niveau === 'bt' => 'etat_bt_facture',
                        $type === 'facture' && $niveau === 'mt' => 'etat_mt_facture',
                        default => throw new \InvalidArgumentException(
                            "Combinaison ETAT invalide : {$type} / {$niveau}"
                        ),
                    }
                ];
            case 'prepaid':
                return [
                    'context' => 'prepaid',
                    'type' => match ($type) {
                        'contrat' => 'prepaid_contrat',
                        'recu', 'facture' => 'prepaid_recu',
                        default => throw new \InvalidArgumentException('Type prepaid invalide'),
                    }
                ];
            default:
                throw new \InvalidArgumentException("Contexte de référentiel inconnu : {$context}");
        }
    }
}
