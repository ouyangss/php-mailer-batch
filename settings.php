<?php
// settings.php
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$cfg = load_config();
require_admin_if_enabled($cfg);

/**
 * 输出 JSON 并退出（方案2：强制清理所有意外输出，确保返回纯 JSON）
 */
function json_exit(array $data, int $code = 200): void {
  // 清掉任何 warning/notice/deprecated 的输出（即使有 display_errors 也不污染 JSON）
  while (ob_get_level() > 0) { @ob_end_clean(); }
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * HTML 转义
 */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ============ AJAX: SMTP 测试接口（返回 JSON）============
// 目标：
// - 快速失败（避免 FPM 超时导致 502）
// - 给出明确错误信息（TCP/DNS/认证/TLS/From 被拒绝等）
// - 无论 PHP 产生任何输出，都强制只返回纯 JSON（方案2）
if (isset($_GET["action"]) && $_GET["action"] === "smtp_test") {

  // 开启输出缓冲：捕获任何意外输出，最后 json_exit 会统一清理
  ob_start();

  // 进一步保险：不把错误直接输出到响应
  @ini_set('display_errors', '0');
  @ini_set('html_errors', '0');
  @error_reporting(E_ALL);

  // 若启用了 admin_token，则 AJAX 也需要 token
  $adminToken = trim((string)($cfg["security"]["admin_token"] ?? ""));
  if ($adminToken !== "") {
    $given = (string)($_POST["token"] ?? "");
    if (!hash_equals($adminToken, $given)) {
      json_exit(["ok" => false, "error" => "Forbidden: admin token required."], 403);
    }
  }

  // 强制限制本次请求总耗时，防止卡死导致 502
  @set_time_limit(20);
  @ini_set('default_socket_timeout', '10');
  @putenv('RES_OPTIONS=attempts:1 timeout:1'); // 尽量减少 DNS 阻塞时间

  $smtpHost = trim((string)($_POST["smtp_host"] ?? ""));
  $smtpPort = (int)($_POST["smtp_port"] ?? 587);
  $smtpUser = trim((string)($_POST["smtp_user"] ?? ""));
  $smtpPass = (string)($_POST["smtp_pass"] ?? "");
  $smtpEnc  = trim((string)($_POST["smtp_enc"] ?? "tls"));

  $fromEmail = trim((string)($_POST["from_email"] ?? ""));
  $fromName  = trim((string)($_POST["from_name"] ?? ""));
  $testTo    = trim((string)($_POST["test_to"] ?? ""));

  // 基础校验
  if ($smtpHost === "" || $smtpPort <= 0) {
    json_exit(["ok" => false, "error" => "SMTP Host/Port 不能为空或不合法"]);
  }
  if ($smtpUser === "" || $smtpPass === "") {
    json_exit(["ok" => false, "error" => "SMTP Username/Password 不能为空"]);
  }
  if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    json_exit(["ok" => false, "error" => "From Email 不合法"]);
  }
  if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
    json_exit(["ok" => false, "error" => "测试收件邮箱不合法"]);
  }

  // 1) TCP 连接预检测（给出清晰错误：解析失败/超时/拒绝）
  $errno = 0; $errstr = '';
  $fp = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
  if (!$fp) {
    json_exit([
      "ok" => false,
      "error" => "❌ TCP 连接失败：{$smtpHost}:{$smtpPort}\nerrno={$errno}\nerr={$errstr}\n\n提示：若你用 nc 测端口 open，但这里失败，可能是 settings 页面填的 host/port 与 nc 测试不一致。"
    ]);
  }
  fclose($fp);

  // 2) 发送测试邮件（验证 TLS + AUTH + 发件人限制）
  $m = new PHPMailer(true);
  $debug = '';

  try {
    $m->CharSet = "UTF-8";
    $m->isSMTP();

    // Debug 收集到变量里（仅测试接口）
    $m->SMTPDebug = 2; // 0/1/2：2 最详细
    $m->Debugoutput = function($str, $level) use (&$debug) {
      $debug .= "[{$level}] {$str}\n";
    };

    $m->Host = $smtpHost;
    $m->Port = $smtpPort;
    $m->SMTPAuth = true;
    $m->Username = $smtpUser;
    $m->Password = $smtpPass;

    // 快速失败参数：避免卡到 FPM 超时（502）
    $m->Timeout = 10;           // socket 超时（秒）
    $m->SMTPKeepAlive = false;  // 测试不复用连接

    if ($smtpEnc === "tls") $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    elseif ($smtpEnc === "ssl") $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    else $m->SMTPSecure = false;

    $m->setFrom($fromEmail, $fromName !== "" ? $fromName : "Mailer");
    $m->addAddress($testTo);
    $m->Subject = "SMTP 测试邮件 - " . date("Y-m-d H:i:s");
    $m->isHTML(true);
    $m->Body = "<p>这是一封 SMTP 测试邮件。</p><p>Host: <b>" . h($smtpHost) . "</b></p>";
    $m->AltBody = "这是一封 SMTP 测试邮件。Host: " . $smtpHost;

    $m->send();

    json_exit([
      "ok" => true,
      "message" => "✅ SMTP 可用：已成功发送测试邮件到 {$testTo}",
      "debug" => $debug ? ("--- SMTP DEBUG ---\n" . $debug) : ""
    ]);
  } catch (Exception $e) {
    $err = $m->ErrorInfo ?: $e->getMessage();
    $extra = trim($debug) !== '' ? ("\n\n--- SMTP DEBUG ---\n" . $debug) : '';
    json_exit(["ok" => false, "error" => "❌ SMTP 不可用：{$err}{$extra}"]);
  }
}
// ============ AJAX 结束 ============

// 保存配置
$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_GET["action"])) {
  $cfg["smtp"]["host"] = trim($_POST["smtp_host"] ?? "");
  $cfg["smtp"]["port"] = (int)($_POST["smtp_port"] ?? 587);
  $cfg["smtp"]["username"] = trim($_POST["smtp_user"] ?? "");
  $cfg["smtp"]["password"] = trim($_POST["smtp_pass"] ?? "");
  $cfg["smtp"]["encryption"] = trim($_POST["smtp_enc"] ?? "tls");

  $cfg["mail"]["from_email"] = trim($_POST["from_email"] ?? "");
  $cfg["mail"]["from_name"]  = trim($_POST["from_name"] ?? "");
  $cfg["mail"]["subject"]    = trim($_POST["subject"] ?? "Hello");
  $cfg["mail"]["is_html"]    = isset($_POST["is_html"]) ? true : false;

  $cfg["rate"]["delay_ms"] = max(0, (int)($_POST["delay_ms"] ?? 300));

  $cfg["unsubscribe"]["base_url"] = trim($_POST["unsub_base"] ?? "");
  $cfg["unsubscribe"]["secret"] = trim($_POST["unsub_secret"] ?? "");

  $cfg["security"]["admin_token"] = trim($_POST["admin_token"] ?? "");

  save_config($cfg);
  $msg = "✅ 保存成功";
}

// 如果启用了 admin_token，用户一般会通过 ?token=xxx 进入设置页
$tokenFromQuery = (string)($_GET["token"] ?? "");
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>群发系统设置</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;max-width:980px;margin:24px auto;padding:0 16px;}
    .card{border:1px solid #ddd;border-radius:10px;padding:16px;margin:12px 0;}
    label{display:block;margin:10px 0 6px;font-weight:600;}
    input,select{width:100%;padding:10px;border:1px solid #ccc;border-radius:8px;}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    button{padding:10px 14px;border:0;border-radius:8px;cursor:pointer;}
    .ok{background:#0ea5e9;color:#fff;}
    .test{background:#22c55e;color:#fff;margin-left:10px;}
    .muted{color:#666;font-size:13px;}
    .topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;}
    a{color:#0ea5e9;text-decoration:none;}
    .btnrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  </style>
</head>
<body>
  <div class="topbar">
    <h2>设置</h2>
    <div><a href="./index.php">← 返回发送页面</a></div>
  </div>

  <?php if ($msg): ?>
    <div class="card" style="border-color:#0ea5e9;"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <form method="post" class="card" id="settingsForm">
    <?php if ($tokenFromQuery !== ""): ?>
      <input type="hidden" name="token" value="<?php echo h($tokenFromQuery); ?>">
    <?php endif; ?>

    <h3>SMTP 配置</h3>

    <label>SMTP Host</label>
    <input name="smtp_host" value="<?php echo h($cfg["smtp"]["host"]); ?>" placeholder="smtp.xxx.com">

    <div class="row">
      <div>
        <label>SMTP Port</label>
        <input name="smtp_port" value="<?php echo h($cfg["smtp"]["port"]); ?>" placeholder="587">
      </div>
      <div>
        <label>加密方式</label>
        <select name="smtp_enc">
          <?php
            $enc = $cfg["smtp"]["encryption"];
            foreach (["tls","ssl","none"] as $opt) {
              $sel = ($enc === $opt) ? "selected" : "";
              echo "<option value='".h($opt)."' $sel>".h($opt)."</option>";
            }
          ?>
        </select>
        <div class="muted">提示：你测过 688 open 的话，这里也要填 688；加密可能需要 tls/ssl/none 逐个试。</div>
      </div>
    </div>

    <label>SMTP Username（建议填完整邮箱地址）</label>
    <input name="smtp_user" value="<?php echo h($cfg["smtp"]["username"]); ?>">

    <label>SMTP Password</label>
    <input name="smtp_pass" value="<?php echo h($cfg["smtp"]["password"]); ?>" type="password">

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

    <h3>邮件默认设置</h3>
    <div class="row">
      <div>
        <label>发件人邮箱 From Email（建议先与 Username 相同）</label>
        <input name="from_email" value="<?php echo h($cfg["mail"]["from_email"]); ?>">
      </div>
      <div>
        <label>发件人名称 From Name</label>
        <input name="from_name" value="<?php echo h($cfg["mail"]["from_name"]); ?>">
      </div>
    </div>

    <label>默认主题 Subject</label>
    <input name="subject" value="<?php echo h($cfg["mail"]["subject"]); ?>">

    <label>
      <input type="checkbox" name="is_html" <?php echo $cfg["mail"]["is_html"] ? "checked" : ""; ?>>
      内容按 HTML 发送（不勾选则纯文本）
    </label>

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

    <h3>发送频率</h3>
    <label>每封间隔（毫秒）delay_ms</label>
    <input name="delay_ms" value="<?php echo h($cfg["rate"]["delay_ms"]); ?>" placeholder="300">

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

    <h3>退订机制</h3>
    <label>退订链接 base_url</label>
    <input name="unsub_base" value="<?php echo h($cfg["unsubscribe"]["base_url"]); ?>" placeholder="https://yourdomain.com/mailer/unsubscribe.php">

    <label>退订签名 secret（请设置长随机字符串）</label>
    <input name="unsub_secret" value="<?php echo h($cfg["unsubscribe"]["secret"]); ?>" placeholder="LONG_RANDOM_SECRET">

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

    <h3>SMTP 可用性验证</h3>
    <label>测试收件邮箱（会发送一封测试邮件）</label>
    <input name="test_to" id="test_to" value="<?php echo h($cfg["smtp"]["username"]); ?>" placeholder="test@example.com">
    <div class="muted">现在会先做 TCP 预检测；失败会显示 errno/errstr。并返回 SMTP DEBUG 细节。</div>

    <hr style="margin:18px 0;border:none;border-top:1px solid #eee;">

    <h3>可选：保护设置页</h3>
    <label>admin_token（不为空则 settings.php 需要 ?token=xxx 才能访问）</label>
    <input name="admin_token" value="<?php echo h($cfg["security"]["admin_token"]); ?>" placeholder="留空不启用">

    <div class="btnrow" style="margin-top:12px;">
      <button class="ok" type="submit">保存设置</button>
      <button class="test" type="button" id="btnTest">验证 SMTP 并发送测试邮件</button>
    </div>
  </form>

<script>
(function(){
  const btn = document.getElementById('btnTest');
  const form = document.getElementById('settingsForm');

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = '测试中...';

    try {
      const fd = new FormData(form);
      const url = new URL(window.location.href);
      url.searchParams.set('action', 'smtp_test');

      const resp = await fetch(url.toString(), { method: 'POST', body: fd });
      const text = await resp.text();

      let data = null;
      try { data = JSON.parse(text); } catch(e) {}

      if (!resp.ok) {
        if (data && data.error) alert(data.error);
        else alert('测试失败：HTTP ' + resp.status + '\n\n' + text);
      } else {
        if (data && data.ok) {
          let msg = data.message || '✅ SMTP 可用';
          if (data.debug) msg += "\n\n" + data.debug;
          alert(msg);
        } else if (data && data.error) {
          alert(data.error);
        } else {
          alert('❌ 返回不是 JSON：\n\n' + text);
        }
      }
    } catch (e) {
      alert('测试异常：' + (e && e.message ? e.message : e));
    } finally {
      btn.disabled = false;
      btn.textContent = oldText;
    }
  });
})();
</script>

</body>
</html>