#!/bin/sh
# Wavelog 业务容器启动链（替代 setup_script，避免 lzcinit 交接处偶发
# `panic: waitid: no child processes`——与 misago/alfio/bar-assistant 同模式）：
#   1) 数据库/配置/离线数据初始化（幂等）
#   2) 内容类型补丁（幂等、soft-fail）
#   3) exec 镜像原本的 entrypoint（cron + apache2-foreground）
set -e
# 清理上次运行可能遗留的迁移锁（迁移中途被杀会留下锁，导致后续迁移被静默跳过）
rm -f /tmp/.migration_running
php /lzcapp/pkg/content/init/init.php
php /lzcapp/pkg/content/init/lzc-patch-content-types.php /var/www/html
# 单线程跑完数据库迁移：登录页每次 GET 都会尝试 migration->current()，
# 并发请求会互相打断并遗留 /tmp/.migration_running 锁（实测首装卡在 189/255/291）。
# 在 Apache 起来之前用 CLI 跑完，之后运行时的迁移调用全部是 no-op。
su -s /bin/sh www-data -c 'php /var/www/html/index.php user login' >/dev/null 2>&1 || true
rm -f /tmp/.migration_running
exec /usr/local/bin/entrypoint.sh apache2-foreground
