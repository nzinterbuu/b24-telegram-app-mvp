<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/b24.php';

try {
  $data = read_json();
  $auth = $data['auth'] ?? $_REQUEST;
  $public = rtrim(cfg('PUBLIC_URL'), '/');

  $r1 = b24_call($auth, 'placement.unbind', ['PLACEMENT'=>'CRM_DEAL_DETAIL_TAB','HANDLER'=>$public.'/deal_tab.php']);
  $r2 = b24_call($auth, 'placement.unbind', ['PLACEMENT'=>'CONTACT_CENTER','HANDLER'=>$public.'/index.php']);

  json_response(['ok'=>true,'deal_tab'=>$r1,'contact_center'=>$r2]);
} catch (Throwable $e) {
  log_debug("uninstall error", ['e'=>$e->getMessage()]);
  json_response(['ok'=>false,'error'=>$e->getMessage()], 400);
}
