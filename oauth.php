<?php
require_once __DIR__ . '/lib/bootstrap.php';
?><!doctype html><meta charset="utf-8"><pre>
OAuth redirect landed here.

MVP note:
- This package relies on BX24.getAuth() from inside the iframe to obtain an access_token.
- If you want full OAuth (code -> token exchange + refresh), implement it here.
</pre>
