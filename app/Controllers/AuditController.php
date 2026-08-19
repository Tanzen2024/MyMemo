<?php
namespace App\Controllers;

use App\Services\AuditFilterService;
use App\Services\AuditReaderService;
use App\Services\AuditLoggerService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class AuditController extends BaseController
{
    private function rows(): array { $filters=(new AuditFilterService())->sanitize($this->request->getGet()); return [$filters,(new AuditReaderService())->find($filters)]; }

    /**
     * Cartes KPI de l'écran : calculées sur les événements réellement
     * journalisés dans la période filtrée, plus un compteur "Aujourd'hui"
     * indépendant du filtre de dates pour rester toujours pertinent.
     */
    private function kpis(array $filters, array $rows): array
    {
        $kpis = ['total' => count($rows), 'today' => 0, 'success' => 0, 'failed' => 0, 'warning' => 0, 'critical' => 0];

        foreach ($rows as $r) {
            $severity = $r['severity'] ?: (in_array($r['status'], ['SUCCESS'], true) ? 'SUCCESS' : (in_array($r['status'], ['WARNING', 'REFUSED'], true) ? 'WARNING' : 'ERROR'));
            match ($severity) {
                'SUCCESS' => $kpis['success']++,
                'WARNING' => $kpis['warning']++,
                'CRITICAL' => $kpis['critical']++,
                'ERROR' => $kpis['failed']++,
                default => null,
            };
        }

        $today = date('Y-m-d');
        if ($filters['date_from'] === $today && $filters['date_to'] === $today) {
            $kpis['today'] = $kpis['total'];
        } else {
            $todayFilters = ['date_from' => $today, 'date_to' => $today, 'user' => '', 'module' => '', 'action' => '', 'status' => '', 'severity' => '', 'category' => '', 'correlation_id' => '', 'incident_ref' => '', 'file' => '', 'search' => '', 'sort' => 'date', 'direction' => 'desc'];
            $kpis['today'] = count((new AuditReaderService())->find($todayFilters));
        }

        return $kpis;
    }

    public function index()
    {
        [$filters,$rows]=$this->rows();
        $kpis = $this->kpis($filters, $rows);
        return view('audit/index', compact('filters','rows','kpis'));
    }

    /**
     * Neutralise une valeur pouvant être interprétée comme une formule par un
     * tableur (CWE-1236) : toute valeur commençant par =, +, - ou @ est
     * préfixée d'une apostrophe pour forcer son interprétation en texte.
     */
    private function safeCell($value)
    {
        if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@") !== false) {
            return "'" . $value;
        }
        return $value;
    }

    public function export(string $format)
    {
        $startedAt = microtime(true);
        [$filters,$rows]=$this->rows(); $format=strtolower($format); if (!in_array($format,['csv','json','excel','pdf'],true)) return $this->response->setStatusCode(404);
        $columns=['date'=>'Date','user'=>'Utilisateur','category'=>'Catégorie','module'=>'Module','action'=>'Action','file'=>'Fichier','rows'=>'Nombre de lignes','duration'=>'Durée','ip'=>'Adresse IP','status'=>'Statut','severity'=>'Sévérité','incident_ref'=>'Réf. incident','message'=>'Message'];

        // L'export du journal d'audit lui-même est une opération sensible et
        // doit être tracé (cf. §29 : ne pas exporter des données sans que
        // cet export soit lui-même auditable).
        (new AuditLoggerService())->log('AUDIT_EXPORT', 'SUCCESS', microtime(true) - $startedAt, $this->request, [
            'category' => 'SECURITY',
            'severity' => 'INFO',
            'module'   => 'Administration',
            'format'   => $format,
            'rows'     => count($rows),
        ]);

        if ($format==='json') return $this->response->setHeader('Content-Disposition','attachment; filename="audit.json"')->setJSON($rows);
        if ($format==='csv') { $out=fopen('php://temp','r+'); fputcsv($out,array_values($columns),';'); foreach($rows as $r) fputcsv($out,array_map(fn($k)=>$this->safeCell($r[$k] ?? ''),array_keys($columns)),';'); rewind($out); $body=stream_get_contents($out); fclose($out); return $this->response->setHeader('Content-Type','text/csv; charset=utf-8')->setHeader('Content-Disposition','attachment; filename="audit.csv"')->setBody("\xEF\xBB\xBF".$body); }
        if ($format==='excel') { $sheet=(new Spreadsheet())->getActiveSheet(); $sheet->fromArray([array_values($columns)],null,'A1'); $sheet->fromArray(array_map(fn($r)=>array_map(fn($k)=>$this->safeCell($r[$k] ?? ''),array_keys($columns)),$rows),null,'A2'); $tmp=tempnam(WRITEPATH,'audit_'); (new Xlsx($sheet->getParent()))->save($tmp); return $this->response->download($tmp,null)->setFileName('audit.xlsx'); }
        $lines = array_map(fn($r) => substr(preg_replace('/[^\x20-\x7E]/', ' ', implode(' | ', array_map(fn($k) => (string) ($r[$k] ?? ''), array_keys($columns)))) ?? '', 0, 110), $rows);
        $stream = "BT /F1 10 Tf 40 800 Td (Audit log) Tj 0 -16 Td "; foreach ($lines as $line) $stream .= '(' . str_replace(['\\','(',')'], ['\\\\','\\(', '\\)'], $line) . ') Tj 0 -14 Td '; $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids[3 0 R] /Count 1 >>endobj\n3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox[0 0 595 842] /Resources<< /Font<< /F1 4 0 R >> >> /Contents 5 0 R >>endobj\n4 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>endobj\n5 0 obj<< /Length ".strlen($stream)." >>stream\n$stream\nET\nendstream endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
        return $this->response->setHeader('Content-Type','application/pdf')->setHeader('Content-Disposition','attachment; filename="audit.pdf"')->setBody($pdf);
    }
}
