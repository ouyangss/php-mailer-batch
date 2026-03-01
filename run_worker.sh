#!/bin/bash
cd /www/wwwroot/smtp.yjnet.xyz/mailer

# 如果已有 worker 在跑就退出（避免重复启动）
if pgrep -f "php .*worker\.php" >/dev/null 2>&1; then
  exit 0
fi

# 启动后台 worker
nohup php worker.php >> /www/wwwroot/smtp.yjnet.xyz/mailer/worker.log 2>&1 &
exit 0
