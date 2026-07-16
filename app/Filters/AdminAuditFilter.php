<?php
namespace App\Filters;
use CodeIgniter\Filters\FilterInterface; use CodeIgniter\HTTP\RequestInterface; use CodeIgniter\HTTP\ResponseInterface;
final class AdminAuditFilter implements FilterInterface {
 public function before(RequestInterface $request, $arguments = null) { $s=session(); $groups=(array)$s->get('groups'); if ($s->get('is_admin') === true || in_array('CN=Admins,OU=Groups,DC=camlight,DC=cm',$groups,true)) return; return redirect()->to('/dashboard')->with('msg','Accès administrateur requis.'); }
 public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
