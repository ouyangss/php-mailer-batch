<?php
// lib.php
declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

const CONFIG_FILE = __DIR__ . '/config.json';
const DATA_DIR = __DIR__ . '/data';
const DB_FILE = __DIR__ . '/data/unsub.sqlite';

function default_config(): array {
  return [
    "smtp" => [
      "host" => "smtp.example.com",
      "port" => 587,
      "username" => "user@example.com",
      "password" => "CHANGE_ME",
      "encryption" => "tls" // tls | ssl | none
    ],
    "mail" => [
      "from_email" => "no-reply@example.com",
      "from_name"  => "Mailer",
      "subject"    => "Hello",
      "is_html"    => true
    ],
    "rate" => [
      "delay_ms" => 300 // 每封间隔毫秒，控制频率（例如 300ms≈3.3封/秒）
    ],
    "security" => [
      "admin_token" => "" // 可选：简单保护 settings.php（留空则不启用）
    ],
    "unsubscribe" => [
      "base_url" => "https://yourdomain.com/unsubscribe.php", // 改成你的真实地址
      "secret" => "CHANGE_TO_A_LONG_RANDOM_SECRET"
    ]
  ];
}

function load_config(): array {
  if (!file_exists(CONFIG_FILE)) {
    save_config(default_config());
  }
  $raw = file_get_contents(CONFIG_FILE);
  $cfg = json_decode($raw ?: "{}", true);
  if (!is_array($cfg)) $cfg = default_config();
  // 补缺省
  $merged = array_replace_recursive(default_config(), $cfg);
  return $merged;
}

function save_config(array $cfg): void {
  file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function ensure_db(): \PDO {
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
  $pdo = new \PDO("sqlite:" . DB_FILE);
  $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  $pdo->exec("
  CREATE TABLE IF NOT EXISTS unsubscribes (
    email TEXT PRIMARY KEY,
    created_at TEXT NOT NULL
  );

  CREATE TABLE IF NOT EXISTS mail_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    subject TEXT NOT NULL,
    content TEXT NOT NULL,
    is_html INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'pending', -- pending/sent/failed/skipped
    last_error TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
  );
");
  return $pdo;
}

function normalize_email(string $email): string {
  $email = trim($email);
  $email = strtolower($email);
  return $email;
}

function is_valid_email(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function is_unsubscribed(\PDO $pdo, string $email): bool {
  $email = normalize_email($email);
  $stmt = $pdo->prepare("SELECT 1 FROM unsubscribes WHERE email = :email LIMIT 1");
  $stmt->execute([":email" => $email]);
  return (bool)$stmt->fetchColumn();
}

function add_unsubscribe(\PDO $pdo, string $email): void {
  $email = normalize_email($email);
  $stmt = $pdo->prepare("INSERT OR IGNORE INTO unsubscribes (email, created_at) VALUES (:email, :ts)");
  $stmt->execute([":email" => $email, ":ts" => gmdate("c")]);
}

function make_unsub_token(string $email, string $secret): string {
  // token = base64url(email) . "." . hmac(email)
  $emailNorm = normalize_email($email);
  $emailB64 = rtrim(strtr(base64_encode($emailNorm), '+/', '-_'), '=');
  $sig = hash_hmac('sha256', $emailNorm, $secret);
  return $emailB64 . "." . $sig;
}

function parse_unsub_token(string $token, string $secret): ?string {
  $parts = explode(".", $token, 2);
  if (count($parts) !== 2) return null;
  [$emailB64, $sig] = $parts;
  $email = base64_decode(strtr($emailB64, '-_', '+/'), true);
  if ($email === false) return null;
  $email = normalize_email($email);
  if (!is_valid_email($email)) return null;
  $expected = hash_hmac('sha256', $email, $secret);
  if (!hash_equals($expected, $sig)) return null;
  return $email;
}

function build_unsub_link(array $cfg, string $email): string {
  $base = $cfg["unsubscribe"]["base_url"] ?? "";
  $secret = $cfg["unsubscribe"]["secret"] ?? "";
  $token = make_unsub_token($email, $secret);
  $sep = (str_contains($base, "?")) ? "&" : "?";
  return $base . $sep . "t=" . urlencode($token);
}

function send_one(array $cfg, string $toEmail, string $content): array {
  $smtp = $cfg["smtp"];
  $mailCfg = $cfg["mail"];

  $m = new PHPMailer(true);
  try {
    $m->CharSet = "UTF-8";
    $m->isSMTP();
    $m->Host = (string)$smtp["host"];
    $m->Port = (int)$smtp["port"];
    $m->SMTPAuth = true;
    $m->Username = (string)$smtp["username"];
    $m->Password = (string)$smtp["password"];

    $enc = (string)($smtp["encryption"] ?? "tls");
    if ($enc === "tls") $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    elseif ($enc === "ssl") $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    else $m->SMTPSecure = false;

    $m->setFrom((string)$mailCfg["from_email"], (string)$mailCfg["from_name"]);
    $m->addAddress($toEmail);
    $m->Subject = (string)$mailCfg["subject"];

    $isHtml = (bool)$mailCfg["is_html"];
    $m->isHTML($isHtml);

    if ($isHtml) {
      $m->Body = $content;
      $m->AltBody = strip_tags($content);
    } else {
      $m->Body = $content;
    }

    $m->send();
    return ["ok" => true, "error" => ""];
  } catch (Exception $e) {
    return ["ok" => false, "error" => $m->ErrorInfo ?: $e->getMessage()];
  }
}

function require_admin_if_enabled(array $cfg): void {
  $token = trim((string)($cfg["security"]["admin_token"] ?? ""));
  if ($token === "") return;

  $given = "";
  if (isset($_GET["token"])) $given = (string)$_GET["token"];
  if (isset($_POST["token"])) $given = (string)$_POST["token"];

  if (!hash_equals($token, $given)) {
    http_response_code(403);
    echo "Forbidden (admin token required).";
    exit;
  }
}