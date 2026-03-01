<?php
// index.php - 入队 + 批次进度展示（不使用 exec，适配宝塔）
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

$cfg = load_config();
$pdo = ensure_db();

$report = [
  "total" => 0,
  "valid" => 0,
  "invalid" => [],
  "skipped_unsub" => [],
  "queued" => 0,
  "batch_id" => "",
];

$emailsText = $_POST["emails"] ?? "";
$content = $_POST["content"] ?? "";
$subjectOverride = trim((string)($_POST["subject"] ?? ""));

// 如果是刷新页面也能继续查看某个 batch（可选）
$viewBatch = trim((string)($_GET["batch"] ?? ""));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // 生成本次批次 ID（短一些，够用）
  $batchId = bin2hex(random_bytes(8)) . "_" . time();
  $report["batch_id"] = $batchId;
  $viewBatch = $batchId;

  $lines = preg_split("/\r\n|\n|\r/", (string)$emailsText);
  $emails = [];

  foreach ($lines as $line) {
    $e = normalize_email((string)$line);
    if ($e === "") continue;
    $emails[] = $e;
  }

  $emails = array_values(array_unique($emails));
  $report["total"] = count($emails);

  $subject = $subjectOverride !== "" ? $subjectOverride : (string)$cfg["mail"]["subject"];
  $isHtml = ((bool)($cfg["mail"]["is_html"] ?? true)) ? 1 : 0;

  foreach ($emails as $email) {
    if (!is_valid_email($email)) {
      $report["invalid"][] = $email;
      continue;
    }

    $report["valid"]++;

    if (is_unsubscribed($pdo, $email)) {
      $report["skipped_unsub"][] = $email;
      continue;
    }

    $ins = $pdo->prepare("
      INSERT INTO mail_queue (batch_id, email, subject, content, is_html, status, created_at, updated_at)
      VALUES (:b, :e, :s, :c, :h, 'pending', :ts, :ts)
    ");

    $ins->execute([
      ":b"  => $batchId,
      ":e"  => $email,
      ":s"  => $subject,
      ":c"  => (string)$content,
      ":h"  => $isHtml,
      ":ts" => gmdate("c"),
    ]);

    $report["queued"]++;
  }

  // 入队后自动跳转到 ?batch=xxx（避免刷新丢失批次）
  header("Location: ./index.php?batch=" . urlencode($batchId));
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>群发系统</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui;max-width:980px;margin:24px auto;padding:0 16px;}
.card{border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0;}
textarea,input{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;}
textarea{min-height:180px;}
button{padding:10px 14px;border:0;border-radius:8px;background:#0ea5e9;color:#fff;cursor:pointer;}
.badge{display:inline-block;padding:3px 10px;border:1px solid #ccc;border-radius:999px;margin-right:6px;font-size:12px;}
.muted{color:#666;font-size:13px;}
.bar{height:10px;background:#eee;border-radius:999px;overflow:hidden;margin-top:8px;}
.bar > div{height:10px;width:0%;background:#22c55e;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
pre{white-space:pre-wrap;word-break:break-word;background:#f7f7f7;border:1px solid #eee;border-radius:8px;padding:10px;max-height:220px;overflow:auto;}
a{color:#0ea5e9;text-decoration:none;}
code{background:#f6f6f6;padding:2px 6px;border-radius:6px;}
</style>
</head>
<body>

<h2>邮件群发（批次进度）</h2>
<div class="muted">
  <a href="settings.php">SMTP/频率设置 →</a>
  <span style="margin:0 8px;">|</span>
  <a href="index.php">新建任务</a>
</div>

<span style="margin:0 8px;">|</span>
<a href="batches.php">任务历史</a>

<form method="post" class="card" id="sendForm">
  <label class="muted">邮箱列表（每行一个，自动去重）</label>
  <textarea name="emails" placeholder="a@example.com&#10;b@example.com"></textarea>

  <div style="height:10px"></div>

  <label class="muted">主题（留空用默认）</label>
  <input name="subject" value="" placeholder="可选">

  <div style="height:10px"></div>

  <label class="muted">邮件内容（worker 会自动追加退订链接，除非你内容里已包含“退订/unsubscribe”）</label>
  <textarea name="content" placeholder="支持 HTML 或纯文本（由 settings.php 决定）"></textarea>

  <div style="height:12px"></div>
  <button type="submit">加入队列（生成新批次）</button>

  <div class="muted" style="margin-top:10px;">
    发送由宝塔计划任务运行的 <code>worker.php</code> 执行。你创建批次后，本页只显示该批次的进度。
  </div>
</form>

<?php if ($viewBatch !== ""): ?>
<div class="card" id="progressCard">
  <h3>本次批次进度</h3>
  <div class="muted">batch_id：<code id="batchId"><?php echo h($viewBatch); ?></code></div>
  <div style="margin-top:10px;">
    <span class="badge">pending <span id="st_pending">0</span></span>
    <span class="badge">sent <span id="st_sent">0</span></span>
    <span class="badge">failed <span id="st_failed">0</span></span>
    <span class="badge">skipped <span id="st_skipped">0</span></span>
    <span class="badge">total <span id="st_total">0</span></span>
  </div>

  <div class="bar"><div id="barInner"></div></div>
  <div class="muted" style="margin-top:6px;">
    完成率：<span id="pct">0%</span> |
    最近更新时间：<span id="lastTs">-</span>
  </div>

  <div class="grid" style="margin-top:12px;">
    <div>
      <h4 style="margin:0 0 6px;">最近失败（最多10条）</h4>
      <pre id="failedBox" class="muted">暂无</pre>
    </div>
    <div>
      <h4 style="margin:0 0 6px;">提示</h4>
      <div class="muted">
        - pending 长时间不动：检查宝塔计划任务是否执行 worker（以及 worker.log）。<br>
        - failed 多：通常是 SMTP 限流/From 不允许/收件人拒收。
      </div>
    </div>
  </div>

  <div class="muted" style="margin-top:10px;">
    查看该批次 JSON：<a id="statusLink" href="status.php?batch=<?php echo h(urlencode($viewBatch)); ?>" target="_blank">status.php?batch=...</a>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const batch = <?php echo json_encode($viewBatch, JSON_UNESCAPED_UNICODE); ?>;
  if (!batch) return;

  const el = (id)=>document.getElementById(id);

  async function fetchStatus(){
    try{
      const url = 'status.php?batch=' + encodeURIComponent(batch) + '&_=' + Date.now();
      const resp = await fetch(url, {cache:'no-store'});
      const data = await resp.json();
      if(!data.ok) return;

      const s = data.stats || {};
      el('st_pending').textContent = s.pending ?? 0;
      el('st_sent').textContent = s.sent ?? 0;
      el('st_failed').textContent = s.failed ?? 0;
      el('st_skipped').textContent = s.skipped ?? 0;
      el('st_total').textContent = s.total ?? 0;

      const done = (s.sent ?? 0) + (s.failed ?? 0) + (s.skipped ?? 0);
      const total = (s.total ?? 0);
      const pct = total > 0 ? Math.round(done * 100 / total) : 0;
      el('pct').textContent = pct + '%';
      el('barInner').style.width = pct + '%';
      el('lastTs').textContent = data.ts || '-';

      const failed = data.failed_recent || [];
      el('failedBox').textContent = failed.length
        ? failed.map(r => `#${r.id} ${r.email}\n${r.error}\n${r.updated_at}\n---`).join('\n')
        : '暂无';
    } catch(e){
      // ignore
    }
  }

  fetchStatus();
  setInterval(fetchStatus, 2000);
})();
</script>

</body>
</html>