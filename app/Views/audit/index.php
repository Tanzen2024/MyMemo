<?php
// Formatage d'AFFICHAGE uniquement : la valeur source (ISO 8601, avec son
// décalage d'origine) n'est jamais modifiée — tri, filtres, corrélation et
// fichiers JSONL restent inchangés. Pas de conversion de fuseau horaire :
// juste un réarrangement des mêmes chiffres (aucun appel à setTimezone()).
$formatAuditDate = static function (string $iso): string {
    try {
        return (new DateTime($iso))->format('d-m-Y H:i:s');
    } catch (\Exception $e) {
        return $iso;
    }
};
?>
<?= $this->include('templates/header') ?>
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?= site_url('administration/audit') ?>" class="nav-link active"><?= lang('Audit.pageTitle') ?></a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto">
    <?= $this->include('templates/topbar_actions') ?>
    <li class="nav-item">
      <a class="nav-link logout-link" href="<?= site_url('authentification/logout') ?>">
        <i class="fas fa-sign-out-alt"></i> <?= lang('App.logout') ?>
      </a>
    </li>
  </ul>
</nav>
<div class="content-wrapper"><section class="content pt-3"><div class="container-fluid">

<div class="row">
  <div class="col-lg-2 col-6">
    <div class="small-box bg-secondary">
      <div class="inner"><h3><?= (int) $kpis['total'] ?></h3><p><?= lang('Audit.kpiTotal') ?></p></div>
      <div class="icon"><i class="fas fa-list"></i></div>
    </div>
  </div>
  <div class="col-lg-2 col-6">
    <div class="small-box bg-info">
      <div class="inner"><h3><?= (int) $kpis['today'] ?></h3><p><?= lang('Audit.kpiToday') ?></p></div>
      <div class="icon"><i class="fas fa-calendar-day"></i></div>
    </div>
  </div>
  <div class="col-lg-2 col-6">
    <div class="small-box bg-success">
      <div class="inner"><h3><?= (int) $kpis['success'] ?></h3><p><?= lang('Audit.kpiSuccess') ?></p></div>
      <div class="icon"><i class="fas fa-check-circle"></i></div>
    </div>
  </div>
  <div class="col-lg-2 col-6">
    <div class="small-box bg-warning">
      <div class="inner"><h3><?= (int) $kpis['warning'] ?></h3><p><?= lang('Audit.kpiWarning') ?></p></div>
      <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
    </div>
  </div>
  <div class="col-lg-2 col-6">
    <div class="small-box bg-danger">
      <div class="inner"><h3><?= (int) $kpis['failed'] ?></h3><p><?= lang('Audit.kpiFailed') ?></p></div>
      <div class="icon"><i class="fas fa-times-circle"></i></div>
    </div>
  </div>
  <div class="col-lg-2 col-6">
    <div class="small-box bg-dark">
      <div class="inner"><h3><?= (int) $kpis['critical'] ?></h3><p><?= lang('Audit.kpiCritical') ?></p></div>
      <div class="icon"><i class="fas fa-skull-crossbones"></i></div>
    </div>
  </div>
</div>

<div class="card"><div class="card-header"><h3 class="card-title"><i class="fas fa-clipboard-list"></i> <?= lang('Audit.pageTitle') ?></h3></div><div class="card-body">
<form class="row" method="get">
  <div class="col-auto mb-2"><input class="form-control" type="date" name="date_from" value="<?= esc($filters['date_from']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" type="date" name="date_to" value="<?= esc($filters['date_to']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" name="user" placeholder="<?= esc(lang('Audit.filterUser'), 'attr') ?>" value="<?= esc($filters['user']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" name="module" placeholder="<?= esc(lang('Audit.filterModule'), 'attr') ?>" value="<?= esc($filters['module']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" name="action" placeholder="<?= esc(lang('Audit.filterAction'), 'attr') ?>" value="<?= esc($filters['action']) ?>"></div>
  <div class="col-auto mb-2"><select class="form-control" name="category"><option value=""><?= lang('Audit.filterCategory') ?></option><?php foreach(['AUTHENTICATION','AUTHORIZATION','IMPORT','EXPORT','MEMORY','DATABASE','APPLICATION','SECURITY'] as $c): ?><option <?= $filters['category']===$c?'selected':'' ?>><?= $c ?></option><?php endforeach ?></select></div>
  <div class="col-auto mb-2"><select class="form-control" name="status"><option value=""><?= lang('Audit.filterStatus') ?></option><?php foreach(['SUCCESS','FAILED','WARNING','ERROR','REFUSED'] as $s): ?><option <?= $filters['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach ?></select></div>
  <div class="col-auto mb-2"><select class="form-control" name="severity"><option value=""><?= lang('Audit.filterSeverity') ?></option><?php foreach(['INFO','SUCCESS','WARNING','ERROR','CRITICAL'] as $s): ?><option <?= $filters['severity']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach ?></select></div>
  <div class="col-auto mb-2"><input class="form-control" name="correlation_id" placeholder="<?= esc(lang('Audit.filterCorrelationId'), 'attr') ?>" value="<?= esc($filters['correlation_id']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" name="incident_ref" placeholder="<?= esc(lang('Audit.filterIncidentRef'), 'attr') ?>" value="<?= esc($filters['incident_ref']) ?>"></div>
  <div class="col-auto mb-2"><input class="form-control" name="search" placeholder="<?= esc(lang('Audit.filterSearch'), 'attr') ?>" value="<?= esc($filters['search']) ?>"></div>
  <div class="col-auto mb-2">
    <button class="btn btn-primary"><?= lang('App.search') ?></button>
    <a class="btn btn-outline-secondary" href="<?= site_url('administration/audit') ?>"><?= lang('App.reset') ?></a>
  </div>
</form><hr>
<div class="mb-2"><?php foreach(['excel'=>lang('Audit.exportExcel'),'csv'=>lang('Audit.exportCsv'),'json'=>lang('Audit.exportJson'),'pdf'=>lang('Audit.exportPdf')] as $f=>$label): ?><a class="btn btn-sm btn-outline-secondary" href="<?= site_url('administration/audit/export/'.$f.'?'.http_build_query($filters)) ?>"><?= $label ?></a> <?php endforeach ?></div>
<style>
/* Portée à cet écran uniquement (#auditTable) : lisibilité du tableau,
   pas de redesign global. */
#auditTable { width: 100%; }
#auditTable th, #auditTable td { vertical-align: middle; }
#auditTable .col-date { white-space: nowrap; }
#auditTable .col-user { white-space: nowrap; }
#auditTable .col-truncate { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#auditTable .col-actions { white-space: nowrap; }
</style>
<div class="table-responsive">
<table id="auditTable" class="table table-bordered table-hover"><thead><tr><th class="col-date"><?= lang('Audit.colDate') ?></th><th class="col-user"><?= lang('Audit.colUser') ?></th><th><?= lang('Audit.colCategory') ?></th><th><?= lang('Audit.colAction') ?></th><th><?= lang('Audit.colModule') ?></th><th><?= lang('Audit.colTarget') ?></th><th><?= lang('Audit.colStatus') ?></th><th><?= lang('Audit.colSeverity') ?></th><th><?= lang('Audit.colDuration') ?></th><th class="col-actions"> </th></tr></thead><tbody><?php foreach($rows as $i=>$r): $cible = $r['file'] ?: ($r['raw']['client'] ?? ''); ?><tr>
  <td class="col-date" data-order="<?= (int) strtotime($r['date']) ?>"><?= esc($formatAuditDate($r['date'])) ?></td>
  <td class="col-user"><?= esc($r['user']) ?></td>
  <td><?= esc($r['category']) ?></td>
  <td class="col-truncate" title="<?= esc($r['action'], 'attr') ?>"><?= esc($r['action']) ?></td>
  <td class="col-truncate" title="<?= esc($r['module'], 'attr') ?>"><?= esc($r['module']) ?></td>
  <td class="col-truncate" title="<?= esc($cible, 'attr') ?>"><?= esc($cible) ?></td>
  <td><span class="badge badge-<?= $r['status']==='SUCCESS'?'success':(in_array($r['status'],['WARNING','REFUSED'],true)?'warning':'danger') ?>"><?= esc($r['status']) ?></span></td>
  <td><span class="badge badge-<?= match($r['severity']){'SUCCESS'=>'success','INFO'=>'secondary','WARNING'=>'warning','CRITICAL'=>'dark',default=>'danger'} ?>"><?= esc($r['severity']) ?></span></td>
  <td><?= esc($r['duration']) ?> s</td>
  <td class="col-actions"><button class="btn btn-sm btn-info audit-see" data-entry='<?= esc(json_encode(array_diff_key($r, ['raw' => true])), 'attr') ?>'><?= lang('Audit.viewButton') ?></button></td>
</tr><?php endforeach ?></tbody></table>
</div>
</div></div></div></section></div><?= $this->include('audit/modal_detail') ?><?= $this->include('templates/footer') ?>
<link rel="stylesheet" href="<?= base_url('assets/adminlte/plugins/datatables-bs4/css/dataTables.bootstrap4.min.css') ?>"><script src="<?= base_url('assets/adminlte/plugins/datatables/jquery.dataTables.min.js') ?>"></script><script src="<?= base_url('assets/adminlte/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js') ?>"></script><script>
$(function(){
  var AUDIT_I18N = <?= json_encode([
      'diagnostic'   => lang('Audit.diagnosticLabel'),
      'incidentRef'  => lang('Audit.filterIncidentRef'),
      'correlationId'=> lang('Audit.filterCorrelationId'),
      'errorType'    => lang('Audit.errorTypeLabel'),
      'userMessage'  => lang('Audit.userMessageLabel'),
      'technicalMessage' => lang('Audit.technicalMessageLabel'),
      'exception'    => lang('Audit.exceptionLabel'),
  ], JSON_UNESCAPED_UNICODE) ?>;
  $('#auditTable').DataTable({pageLength:25,lengthMenu:[[25,50,100,-1],[25,50,100,'Tous']],order:[[0,'desc']],columnDefs:[{targets:0,type:'num'}]});
  // Même règle que côté serveur : reformater l'AFFICHAGE d'une date ISO sans
  // jamais réinterpréter son fuseau horaire (pas de new Date(), qui la
  // recalculerait dans le fuseau du navigateur — simple extraction de texte).
  function formatAuditDateJs(iso) {
    const m = typeof iso === 'string' && iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/);
    return m ? (m[3] + '-' + m[2] + '-' + m[1] + ' ' + m[4] + ':' + m[5] + ':' + m[6]) : iso;
  }
  $('.audit-see').on('click',function(){
    const e=$(this).data('entry');
    let h='';
    const isFailure = e.status !== 'SUCCESS';
    if (isFailure && (e.user_message || e.technical_message || e.incident_ref)) {
      h += '<div class="alert alert-warning"><strong>'+AUDIT_I18N.diagnostic+'</strong></div>';
      if (e.incident_ref) h += '<dt class="col-sm-4">'+AUDIT_I18N.incidentRef+'</dt><dd class="col-sm-8"><code>'+$('<div>').text(e.incident_ref).html()+'</code></dd>';
      if (e.correlation_id) h += '<dt class="col-sm-4">'+AUDIT_I18N.correlationId+'</dt><dd class="col-sm-8"><code>'+$('<div>').text(e.correlation_id).html()+'</code></dd>';
      if (e.error_type) h += '<dt class="col-sm-4">'+AUDIT_I18N.errorType+'</dt><dd class="col-sm-8">'+$('<div>').text(e.error_type).html()+'</dd>';
      if (e.user_message) h += '<dt class="col-sm-4">'+AUDIT_I18N.userMessage+'</dt><dd class="col-sm-8">'+$('<div>').text(e.user_message).html()+'</dd>';
      if (e.technical_message) h += '<dt class="col-sm-4">'+AUDIT_I18N.technicalMessage+'</dt><dd class="col-sm-8"><pre>'+$('<div>').text(e.technical_message).html()+'</pre></dd>';
      if (e.exception) h += '<dt class="col-sm-4">'+AUDIT_I18N.exception+'</dt><dd class="col-sm-8">'+$('<div>').text(e.exception).html()+'</dd>';
      h += '<div class="col-12"><hr></div>';
    }
    Object.entries(e).forEach(([k,v])=>{
      if (k === 'raw') return;
      const display = typeof v === 'string' ? formatAuditDateJs(v) : v;
      h+='<dt class="col-sm-4">'+$('<div>').text(k).html()+'</dt><dd class="col-sm-8"><pre>'+ $('<div>').text(typeof display==='object'?JSON.stringify(display,null,2):display).html()+'</pre></dd>';
    });
    $('#auditDetailBody').html(h);
    $('#auditDetail').modal('show');
  });
});
</script>
