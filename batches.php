<?php
// batches.php - 批次历史列表 + 导出CSV + 失败重试
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

$cfg = load_config();
require_admin_if_enabled($cfg);
$pdo = ensure_db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$token = (string)($_GET["token"] ?? "");
$tokenQ = $token !== "" ? ("?token=" . urlencode($token)) : "";

$action = (string)($_GET["action"] ?? "");
$batch  = trim((string)($_GET["batch"] ?? ""));

// ====== 导出 CSV ======
if ($action === "export" && $batch !== "") {
  $stmt = $pdo->prepare("SELECT id, email, status, last_error, created_at, updated_at
                         FROM mail_queue WHERE batch_id = :b ORDER BY id ASC");
  $stmt->execute([":b"=>$batch]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  header("Content-Type: text/csv; charset=utf-8");
  header("Content-Disposition: attachment; filename=\"batch_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $batch) . ".csv\"");

  // UTF-8 BOM（Excel 兼容）
  echo "\xEF\xBB\xBF";
  $out = fopen("php://output", "w");
  fputcsv($out, ["id","email","status","last_error","created_at","updated_at"]);
  foreach ($rows as $r) {
    fputcsv($out, [
      (int)$r["id"],
      (string)$r["email"],
      (string)$r["status"],
      (string)$r["last_error"],
      (string)$r["created_at"],
      (string)$r["updated_at"],
    ]);
  }
  fclose($out);
  exit;
}

// ====== 重试失败（failed -> pending）=====
$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $postAction = (string)($_POST["action"] ?? "");
  $postBatch  = trim((string)($_POST["batch"] ?? ""));

  if ($postAction === "requeue_failed" && $postBatch !== "") {
    $upd = $pdo->prepare("UPDATE mail_queue
                          SET status='pending', last_error='', updated_at=:ts
                          WHERE batch_id=:b AND status='failed'");
    $upd->execute([":ts"=>gmdate("c"), ":b"=>$postBatch]);
    $msg = "✅ 已将该批次 failed 重新入队（pending）：batch_id=" . $postBatch;
  }
}

// ====== 读取批次列表 ======
$sql = "
SELECT
  batch_id,
  MIN(created_at) AS first_created_at,
  MAX(updated_at) AS last_updated_at,
  SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
  SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent,
  SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
  SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) AS skipped,
  COUNT(*) AS total
FROM mail_queue
WHERE batch_id <> ''
GROUP BY batch_id
ORDER BY MAX(updated_at) DESC
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>批次历史</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui;max-width:1100px;margin:24px auto;padding:0 16px;}
  .card{border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0;}
  table{width:100%;border-collapse:collapse;}
  th,td{border-bottom:1px solid #eee;padding:10px;text-align:left;vertical-align:top;font-size:14px;}
  th{background:#fafafa;}
  .muted{color:#666;font-size:13px;}
  .tag{display:inline-block;padding:2px 8px;border:1px solid #ddd;border-radius:999px;font-size:12px;margin-right:6px;}
  a{color:#0ea5e9;text-decoration:none;}
  code{background:#f6f6f6;padding:2px 6px;border-radius:6px;}
  .btn{display:inline-block;padding:6px 10px;border-radius:8px;border:1px solid #ddd;background:#fff;cursor:pointer;}
  .btn-primary{border-color:#0ea5e9;color:#0ea5e9;}
  .btn-warn{border-color:#ef4444;color:#ef4444;}
  .row{display:flex;gap:8px;flex-wrap:wrap;}
</style>
</head>
<body>

<h2>批次历史列表</h2>
<div class="muted">
  <a href="./index.php<?php echo h($tokenQ); ?>">← 返回发送页</a>
  <span style="margin:0 8px;">|</span>
  <a href="./settings.php<?php echo h($tokenQ); ?>">SMTP/频率设置</a>
</div>

<?php if ($msg): ?>
  <div class="card" style="border-color:#22c55e;"><?php echo h($msg); ?></div>
<?php endif; ?>

<div class="card">
  <div class="muted">共 <?php echo count($rows); ?> 个批次（按最后更新时间倒序）</div>

  <div style="overflow:auto;margin-top:10px;">
    <table>
      <thead>
        <tr>
          <th>batch_id</th>
          <th>统计</th>
          <th>时间</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted">暂无批次</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): 
          $b = (string)$r["batch_id"];
          $pending = (int)$r["pending"];
          $sent = (int)$r["sent"];
          $failed = (int)$r["failed"];
          $skipped = (int)$r["skipped"];
          $total = (int)$r["total"];
          $done = $sent + $failed + $skipped;
          $pct = $total > 0 ? (int)round($done * 100 / $total) : 0;

          $viewLink = "./index.php?batch=" . urlencode($b) . ($token !== "" ? "&token=" . urlencode($token) : "");
          $statusLink = "./status.php?batch=" . urlencode($b);
          $exportLink = "./batches.php?action=export&batch=" . urlencode($b) . ($token !== "" ? "&token=" . urlencode($token) : "");
        ?>
          <tr>
            <td>
              <code><?php echo h($b); ?></code><br>
              <div class="muted" style="margin-top:6px;">
                进度：<?php echo $pct; ?>%
              </div>
            </td>
            <td>
              <span class="tag">pending <?php echo $pending; ?></span>
              <span class="tag">sent <?php echo $sent; ?></span>
              <span class="tag">failed <?php echo $failed; ?></span>
              <span class="tag">skipped <?php echo $skipped; ?></span>
              <span class="tag">total <?php echo $total; ?></span>
            </td>
            <td>
              <div class="muted">开始：<?php echo h((string)$r["first_created_at"]); ?></div>
              <div class="muted">更新：<?php echo h((string)$r["last_updated_at"]); ?></div>
            </td>
            <td>
              <div class="row">
                <a class="btn btn-primary" href="<?php echo h($viewLink); ?>">打开进度页</a>
                <a class="btn" href="<?php echo h($statusLink); ?>" target="_blank">JSON</a>
                <a class="btn" href="<?php echo h($exportLink); ?>">导出 CSV</a>

                <?php if ($failed > 0): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('确认把该批次 failed 重新入队（pending）吗？');">
                    <input type="hidden" name="action" value="requeue_failed">
                    <input type="hidden" name="batch" value="<?php echo h($b); ?>">
                    <?php if ($token !== ""): ?>
                      <input type="hidden" name="token" value="<?php echo h($token); ?>">
                    <?php endif; ?>
                    <button class="btn btn-warn" type="submit">重试 failed</button>
                  </form>
                <?php else: ?>
                  <span class="muted">无 failed</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</body>
</html>