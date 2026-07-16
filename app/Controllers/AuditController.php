<?php
namespace App\Controllers;

use App\Services\AuditFilterService;
use App\Services\AuditReaderService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class AuditController extends BaseController
{
    private function rows(): array { $filters=(new AuditFilterService())->sanitize($this->request->getGet()); return [$filters,(new AuditReaderService())->find($filters)]; }
    public function index() { [$filters,$rows]=$this->rows(); return view('audit/index', compact('filters','rows')); }
    public function export(string $format)
    {
        [$filters,$rows]=$this->rows(); $format=strtolower($format); if (!in_array($format,['csv','json','excel','pdf'],true)) return $this->response->setStatusCode(404);
        $columns=['date'=>'Date','user'=>'Utilisateur','module'=>'Module','action'=>'Action','file'=>'Fichier','rows'=>'Nombre de lignes','duration'=>'Durée','ip'=>'Adresse IP','status'=>'Statut','message'=>'Message'];
        if ($format==='json') return $this->response->setHeader('Content-Disposition','attachment; filename="audit.json"')->setJSON($rows);
        if ($format==='csv') { $out=fopen('php://temp','r+'); fputcsv($out,array_values($columns),';'); foreach($rows as $r) fputcsv($out,array_map(fn($k)=>$r[$k],array_keys($columns)),';'); rewind($out); $body=stream_get_contents($out); fclose($out); return $this->response->setHeader('Content-Type','text/csv; charset=utf-8')->setHeader('Content-Disposition','attachment; filename="audit.csv"')->setBody("\xEF\xBB\xBF".$body); }
        if ($format==='excel') { $sheet=(new Spreadsheet())->getActiveSheet(); $sheet->fromArray([array_values($columns)],null,'A1'); $sheet->fromArray(array_map(fn($r)=>array_map(fn($k)=>$r[$k],array_keys($columns)),$rows),null,'A2'); $tmp=tempnam(WRITEPATH,'audit_'); (new Xlsx($sheet->getParent()))->save($tmp); return $this->response->download($tmp,null)->setFileName('audit.xlsx'); }
        $lines = array_map(fn($r) => substr(preg_replace('/[^\x20-\x7E]/', ' ', implode(' | ', array_map(fn($k) => (string) $r[$k], array_keys($columns)))) ?? '', 0, 110), $rows);
        $stream = "BT /F1 10 Tf 40 800 Td (Audit log) Tj 0 -16 Td "; foreach ($lines as $line) $stream .= '(' . str_replace(['\\','(',')'], ['\\\\','\\(', '\\)'], $line) . ') Tj 0 -14 Td '; $pdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids[3 0 R] /Count 1 >>endobj\n3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox[0 0 595 842] /Resources<< /Font<< /F1 4 0 R >> >> /Contents 5 0 R >>endobj\n4 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>endobj\n5 0 obj<< /Length ".strlen($stream)." >>stream\n$stream\nET\nendstream endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
        return $this->response->setHeader('Content-Type','application/pdf')->setHeader('Content-Disposition','attachment; filename="audit.pdf"')->setBody($pdf);
    }
}
