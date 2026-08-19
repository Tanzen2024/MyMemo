<?php

use App\Models\AuditEntry;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Couvre les deux corrections UX du Journal d'audit : format d'affichage des
 * dates (dd-mm-aaaa hh:mm:ss, sans toucher à la valeur source ni casser le
 * tri) et absence de débordement (table-responsive, colonnes marquées
 * nowrap/tronquées avec info complète accessible).
 */
final class AuditIndexViewFormattingTest extends CIUnitTestCase
{
    private function row(): array
    {
        return AuditEntry::fromArray([
            'date' => '2026-08-19T15:05:18+00:00',
            'user' => 'hugues.nwameh',
            'category' => 'ADMINISTRATION',
            'action' => 'MYMEMO_USER_DELETED',
            'module' => 'GestionUtilisateurs',
            'status' => 'SUCCESS',
            'severity' => 'INFO',
            'duration' => 0.01,
        ]);
    }

    private function render(): string
    {
        return view('audit/index', [
            'filters' => (new AuditFilterServiceStub())->sanitize([]),
            'rows' => [$this->row()],
            'kpis' => ['total' => 1, 'today' => 1, 'success' => 1, 'warning' => 0, 'failed' => 0, 'critical' => 0],
        ]);
    }

    // TEST : la CELLULE visible affiche dd-mm-aaaa hh:mm:ss, jamais le format ISO technique.
    public function testDateCellIsDisplayedInFrenchFormatNotIso(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/<td class="col-date"[^>]*>19-08-2026 15:05:18<\/td>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<td class="col-date"[^>]*>2026-08-19T15:05:18[^<]*<\/td>/', $html);
    }

    // TEST : la valeur de tri DataTables (data-order) reste la vraie date/heure (timestamp), pas la chaîne affichée.
    public function testSortOrderAttributeUsesRealTimestampNotDisplayString(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-order="' . strtotime('2026-08-19T15:05:18+00:00') . '"', $html);
    }

    // TEST : le tableau est enveloppé dans .table-responsive (débordement horizontal maîtrisé).
    public function testTableIsWrappedInResponsiveContainer(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/table-responsive[^>]*>\s*<table id="auditTable"/', $html);
    }

    // TEST : les colonnes à contenu long portent un titre (tooltip) avec la valeur complète, jamais masquée.
    public function testLongContentColumnsExposeFullValueViaTitleAttribute(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('title="MYMEMO_USER_DELETED"', $html);
        $this->assertStringContainsString('title="GestionUtilisateurs"', $html);
    }

    // TEST : le détail JSON embarqué pour la modale « Voir » garde la date ISO source intacte
    // (reformatage fait en JS à l'affichage, pas en PHP) — décodé, car esc('attr') encode les
    // deux-points (&#x3A;) en contexte attribut, ce qui est correct et pré-existant.
    public function testEmbeddedDetailJsonKeepsSourceIsoDateUntouched(): void
    {
        $html = $this->render();

        preg_match("/data-entry='([^']*)'/", $html, $m);
        $this->assertNotEmpty($m, 'attribut data-entry introuvable');
        $this->assertStringContainsString('2026-08-19T15:05:18', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
    }
}

/**
 * Évite une dépendance à la config .env pour ce test de rendu pur : mêmes
 * clés que AuditFilterService::sanitize(), valeurs fixes déterministes.
 */
final class AuditFilterServiceStub
{
    public function sanitize(array $input): array
    {
        return [
            'date_from' => '2026-07-20', 'date_to' => '2026-08-19', 'user' => '', 'module' => '',
            'action' => '', 'status' => '', 'severity' => '', 'category' => '',
            'correlation_id' => '', 'incident_ref' => '', 'file' => '', 'search' => '',
            'sort' => 'date', 'direction' => 'desc',
        ];
    }
}
