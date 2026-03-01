<?php
// status.php - 批次/全局队列进度查询（JSON）
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

$pdo = ensure_db();

$batch = trim((string)($_GET["batch"] ?? ""));
$where = "";
$params = [];

if ($batch !== "") {
  $where = "WHERE batch_id = :b";
  $params[":b"] = $batch;
}

$stats = [
  "pending" => 0,
  "sent" => 0,
  "failed" => 0,
  "skipped" => 0,
  "total" => 0,
];

try {
  // 统计
  if ($where) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM mail_queue $where GROUP BY status");
    $stmt->execute($params);
  } else {
    $stmt = $pdo->query("SELECT status, COUNT(*) AS c FROM mail_queue GROUP BY status");
  }

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $st = (string)$row["status"];
    $c  = (int)$row["c"];
    if (isset($stats[$st])) $stats[$st] = $c;
  }
  $stats["total"] = $stats["pending"] + $stats["sent"] + $stats["failed"] + $stats["skipped"];

  // 最近失败（最多10条）
  if ($where) {
    $q = $pdo->prepare("SELECT id, email, last_error, updated_at FROM mail_queue $where AND status='failed' ORDER BY id DESC LIMIT 10");
    $q->execute($params);
  } else {
    $q = $pdo->query("SELECT id, email, last_error, updated_at FROM mail_queue WHERE status='failed' ORDER BY id DESC LIMIT 10");
  }

  $failed = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $failed[] = [
      "id" => (int)$r["id"],
      "email" => (string)$r["email"],
      "error" => (string)$r["last_error"],
      "updated_at" => (string)$r["updated_at"],
    ];
  }

  header("Content-Type: application/json; charset=utf-8");
  echo json_encode([
    "ok" => true,
    "batch" => $batch,
    "stats" => $stats,
    "failed_recent" => $failed,
    "ts" => gmdate("c"),
  ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
  http_response_code(500);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["ok"=>false, "error"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}