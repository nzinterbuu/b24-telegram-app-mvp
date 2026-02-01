<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/b24.php';

try {
  $data = read_json();
  $auth = $data['auth'] ?? $_REQUEST;
  $public = rtrim(cfg('PUBLIC_URL'), '/');

  $res1 = b24_call($auth, 'placement.bind', [
    'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
    'HANDLER'   => $public . '/deal_tab.php',
    'TITLE'     => 'Telegram Chat',
    'DESCRIPTION' => 'Chat with client via Telegram',
    'OPTIONS'   => ['width' => 800, 'height' => 600]
  ]);

  $res2 = b24_call($auth, 'placement.bind', [
    'PLACEMENT' => 'CONTACT_CENTER',
    'HANDLER'   => $public . '/index.php',
    'TITLE'     => 'Telegram (GreyTG)',
    'DESCRIPTION' => 'Telegram connector (MVP)',
    'OPTIONS'   => ['width' => 980, 'height' => 900]
  ]);

  json_response(['ok'=>true,'deal_tab'=>$res1,'contact_center'=>$res2]);
} catch (Throwable $e) {
  log_debug("install error", ['e'=>$e->getMessage()]);
  json_response(['ok'=>false,'error'=>$e->getMessage()], 400);
}
