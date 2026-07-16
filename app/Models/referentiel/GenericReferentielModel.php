<?php

namespace App\Models\referentiel;

use CodeIgniter\Model;

class GenericReferentielModel extends Model
{
    protected $primaryKey       = '';
    protected $useAutoIncrement = false;
    protected $useTimestamps    = false;

    /**
     * Insert batch pour table sans primary key, de manière générique
     *
     * @param array $data
     * @return int Nombre de lignes insérées
     */
    public function insertBatchNoPK(array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $builder = $this->db->table($this->table);
        $inserted = 0;

        foreach ($data as $row) {
            // Ignore les lignes complètement vides
            if (empty(array_filter($row))) {
                continue;
            }

            $builder->insert($row);
            $inserted++;
        }

        return $inserted;
    }

    /**
     * Vérifie si une valeur existe déjà pour un champ donné
     *
     * @param string $field Nom du champ
     * @param string $value Valeur à rechercher
     * @return bool
     */
    public function exists(string $field, string $value): bool
    {
        return $this->db->table($this->table)
            ->where($field, $value)
            ->countAllResults() > 0;
    }
}
