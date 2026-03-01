<?php
declare(strict_types=1);
require_once __DIR__ . "/lib.php";

$cfg = load_config();
$pdo = ensure_db();

$delayMs = (int)($cfg["rate"]["delay_ms"] ?? 300);
$batch = 50; // 每轮最多处理50封，避免无限运行

while (true) {
  $stmt = $pdo->prepare("SELECT id, email, subject, content, is_html FROM mail_queue WHERE status='pending' ORDER BY id ASC LIMIT :lim");
  $stmt->bindValue(":lim", $batch, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!$rows) exit(0);

  foreach ($rows as $r) {
    $id = (int)$r["id"];
    $email = (string)$r["email"];

    if (is_unsubscribed($pdo, $email)) {
      $upd = $pdo->prepare("UPDATE mail_queue SET status='skipped', last_error='unsubscribed', updated_at=:ts WHERE id=:id");
      $upd->execute([":ts"=>gmdate("c"), ":id"=>$id]);
      continue;
    }

    $cfg["mail"]["subject"] = (string)$r["subject"];
    $cfg["mail"]["is_html"] = ((int)$r["is_html"] === 1);

    $unsub = build_unsub_link($cfg, $email);
    $content = (string)$r["content"];

    if ($cfg["mail"]["is_html"]) {
      if (!str_contains($content, "unsubscribe") && !str_contains($content, "退订")) {
        $content .= "<hr><p style='font-size:12px;color:#666'>不想再收到邮件？<a href='".htmlspecialchars($unsub, ENT_QUOTES, 'UTF-8')."'>点击退订</a></p>";
      }
    } else {
      if (!str_contains($content, "退订") && !str_contains($content, "unsubscribe")) {
        $content .= "\n\n----\n不想再收到邮件？退订链接：".$unsub."\n";
      }
    }

    $res = send_one($cfg, $email, $content);

    if ($res["ok"]) {
      $upd = $pdo->prepare("UPDATE mail_queue SET status='sent', last_error='', updated_at=:ts WHERE id=:id");
      $upd->execute([":ts"=>gmdate("c"), ":id"=>$id]);
    } else {
      $upd = $pdo->prepare("UPDATE mail_queue SET status='failed', last_error=:err, updated_at=:ts WHERE id=:id");
      $upd->execute([":err"=>$res["error"], ":ts"=>gmdate("c"), ":id"=>$id]);
    }

    if ($delayMs > 0) usleep($delayMs * 1000);
  }
}