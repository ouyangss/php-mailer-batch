PHP Bulk Mailer (Batch Queue Version)

一个基于 PHP + PHPMailer + SQLite 的轻量级批量邮件发送系统，支持：

批量邮箱入队

SMTP 可视化测试（含 Debug）

异步 Worker 发送（避免 502）

批次进度实时显示

退订机制（HMAC 签名链接）

批次历史 + 导出 CSV + 失败重试

适配 宝塔 / Nginx / Debian 12

✨ 功能特性
1. 批次发送机制

每次发送自动生成 batch_id

实时查看该批次发送进度
支持失败邮件重新入队

2. 异步发送架构

点击发送只“入队”

实际发送由 worker.php 处理

通过宝塔计划任务触发

避免 Nginx 502 / FPM 超时

3. SMTP 设置页

可视化填写 SMTP 参数

支持 TLS / SSL / NONE

内置 TCP 预检测

返回完整 SMTP Debug 信息

4. 退订机制

自动生成签名退订链接

使用 HMAC 防篡改

SQLite 存储退订列表

5. 批次历史页

查看所有历史批次

实时统计状态

导出 CSV

一键重试 failed

📁 目录结构
mailer/
│
├── index.php            发送页面（生成批次 + 实时进度）
├── settings.php         SMTP 设置页
├── worker.php           后台发送程序
├── status.php           批次进度接口
├── batches.php          批次历史页
├── unsubscribe.php      退订页面
├── lib.php              公共函数
│
├── config/
│   ├── config.json
│   └── config.example.json
│
├── data/                SQLite 数据目录
├── composer.json
└── README.md
🚀 宝塔环境部署教程（Debian12 + Nginx）
一、准备环境

确保服务器已安装：

宝塔面板

PHP 8.1+（推荐 8.2）

Nginx

Composer

二、上传代码

将项目上传到站点目录，例如：

/www/wwwroot/smtp.yjnet.xyz/mailer
三、安装依赖

SSH 执行：

cd /www/wwwroot/smtp.yjnet.xyz/mailer
composer install --no-dev
四、创建运行时目录（非常重要）
mkdir -p data
chown -R www:www data config
chmod 700 data

说明：

data/ 用于 SQLite

config/ 需要可写（保存 SMTP 设置）

五、配置 config.json

复制模板：

cp config/config.example.json config/config.json

编辑：

nano config/config.json

修改：

smtp.host
smtp.port
smtp.username
smtp.password
smtp.encryption
mail.from_email
unsubscribe.base_url

退订链接示例：

https://smtp.yjnet.xyz/mailer/unsubscribe.php
六、访问设置页测试 SMTP

打开：

https://smtp.yjnet.xyz/mailer/settings.php

填写 SMTP 信息后点击：

验证 SMTP 并发送测试邮件

确保显示：

✅ SMTP 可用
⏱ 宝塔计划任务（必须）

进入：

宝塔 → 计划任务 → 添加任务

设置：

类型：Shell 脚本

执行周期：每 1 分钟

脚本内容：

cd /www/wwwroot/smtp.yjnet.xyz/mailer && php worker.php >> worker.log 2>&1

保存即可。

说明：

worker 每次发送完 pending 即退出

下一分钟继续执行

不会产生 502

📤 使用方法

打开：

index.php

输入邮箱（每行一个）

输入邮件内容

点击“加入队列”

系统会：

自动生成批次

跳转到 ?batch=xxx

每 2 秒刷新进度

📊 批次历史

访问：

batches.php

可：

查看历史批次

导出 CSV

重试失败邮件

🔐 可选：启用后台保护

在 config.json 中设置：

"security": {
  "admin_token": "your_random_token"
}

访问时：

settings.php?token=your_random_token
batches.php?token=your_random_token
🛠 GitHub 部署流程
初始化仓库
git init
git add .
git commit -m "Initial commit"
忽略敏感文件（.gitignore）
/vendor/
/data/
/config/config.json
*.log
推送到 GitHub
git remote add origin https://github.com/yourname/php-mailer.git
git branch -M main
git push -u origin main

建议：

使用 Private 仓库

使用 SSH 或 Personal Access Token

🧪 常见问题
1. SMTP 测试失败：TCP 连接失败

说明：

端口不通

防火墙未放行

host 填错

测试：

nc -vz smtp.server.com 587
2. 点击发送出现 502

原因：

没有使用 worker

或 PHP 超时

解决：

使用计划任务

不要在 index.php 直接发送

3. 发送很慢

调整：

settings.php → delay_ms
4. failed 很多

查看：

index 页面“最近失败”

worker.log

常见原因：

SMTP 限流

发件人未认证

SPF/DKIM 未正确配置

🗄 数据说明

使用 SQLite：

data/unsub.sqlite

包含：

mail_queue

unsubscribes

备份方法：

cp data/unsub.sqlite backup.sqlite
⚠ 安全建议

不要提交 config.json

定期备份 SQLite

使用 HTTPS

设置强随机 unsubscribe secret

建议将仓库设为 Private

📜 License

MIT License (可自行修改)
