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
     * Importe un fichier Excel dans la table Oracle
     * Toutes les valeurs sont converties en texte (VARCHAR2)
     */
    public function import(string $type, string $filePath, OracleService $oracle): array
    {
        log_message('debug', "Import Excel type={$type}");

        $configs = ReferentielImportConfig::get();
        if (!isset($configs[$type])) {
            throw new RuntimeException("Type invalide : {$type}");
        }

        $config = $configs[$type];

        // Lecture du fichier Excel
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, true);

        if (count($rows) <= 1) {
            return ['inserted' => 0, 'skipped' => 0, 'message' => 'Fichier vide'];
        }

        $data = [];
        $skipped = 0;

        // Parcours des lignes Excel
        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // ignorer l’en-tête

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

            if (!$valid) {
                $skipped++;
                $reason = [];

                foreach ($config['required'] as $req) {
                    if (!isset($mapped[$req]) || $mapped[$req] === '') {
                        $reason[] = "champ manquant: {$req}";
                    }
                }

                log_message('debug', json_encode([
                    'line' => $i,
                    'reason' => $reason,
                    'row' => $row,
                    'mapped' => $mapped
                ]));
                continue;
            }

            $data[] = $mapped;
        }

        if (!$data) {
            return ['inserted' => 0, 'skipped' => $skipped, 'message' => 'Aucune ligne valide'];
        }

        $inserted = 0;

        // Insertion Oracle
        foreach ($data as $row) {
            $cols = implode(',', array_keys($row));

            // Toutes les valeurs sont insérées en VARCHAR2
            $placeholders = implode(',', array_fill(0, count($row), '?'));
            $sql = "INSERT INTO {$config['table']} ({$cols}) VALUES ({$placeholders})";

            try {
                if ($oracle->executeSql($sql, array_values($row)) === false) {
                    throw new RuntimeException('Oracle insert failed.');
                }
                $inserted++;
            } catch (\Throwable $e) {
                log_message('error', "Erreur insertion : ".$e->getMessage());
                $skipped++;
            }
        }

        log_message('debug', "Import terminé : inserted={$inserted}, skipped={$skipped}");

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'message'  => "{$inserted} lignes insérées, {$skipped} rejetées",
        ];
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
