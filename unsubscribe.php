<?php
// unsubscribe.php
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

$cfg = load_config();
$pdo = ensure_db();

$token = (string)($_GET["t"] ?? "");
$email = null;

if ($token !== "") {
  $email = parse_unsub_token($token, (string)$cfg["unsubscribe"]["secret"]);
  if ($email) {
    add_unsubscribe($pdo, $email);
  }
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>退订</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;max-width:720px;margin:60px auto;padding:0 16px;}
    .card{border:1px solid #ddd;border-radius:10px;padding:18px;}
    .ok{border-color:#22c55e;}
    .bad{border-color:#ef4444;}
    a{color:#0ea5e9;text-decoration:none;}
  </style>
</head>
<body>
  <?php if ($email): ?>
    <div class="card ok">
      <h2>已退订</h2>
      <p><?php echo h($email); ?> 已成功退订，后续将不再收到群发邮件。</p>
    </div>
  <?php else: ?>
    <div class="card bad">
      <h2>退订链接无效</h2>
      <p>你的退订链接可能已损坏或过期。</p>
    </div>
  <?php endif; ?>
</body>
</html>