#!/usr/bin/env bash
# ============================================================================
# V2Board 子账号功能 —— 生产部署脚本（**默认不执行，仅供生产窗口使用**）
# ----------------------------------------------------------------------------
# 设计原则：先备份、可回滚、最小改动、不改表结构以外的任何东西。
#
# 用法（在生产服务器上执行）：
#   bash tools/subaccount-deploy.sh --preflight   # 只读：环境自检 + 基线采集（不写业务数据）
#   bash tools/subaccount-deploy.sh --deploy      # 备份 → 落代码 → 建表 → 清缓存 → 重载
#   bash tools/subaccount-deploy.sh --verify      # 部署后验证
#   bash tools/subaccount-deploy.sh --rollback <备份目录>   # 回滚到指定备份
#
# 环境变量（按目标站实际覆盖）：
#   APP_DIR      默认 /www/wwwroot/v2board.com
#   DB_NAME      默认取 .env 中 DB_DATABASE
#   BACKUP_ROOT  默认 /root/v2board-subaccount-backup
#   BRANCH       默认 feature/sub-accounts
#
# 前置条件：
#   1) 生产代码库已加好远端（例如 myfork=https://github.com/ZgOm-Pg/v2board.git）；
#   2) 生产站以 AdapterMan/Workerman 常驻，改代码后必须发 SIGUSR1 重载；
#   3) 执行窗口内暂停 Horizon / Scheduler（脚本会提示，不自动停服）。
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/v2board.com}"
BACKUP_ROOT="${BACKUP_ROOT:-/root/v2board-subaccount-backup}"
BRANCH="${BRANCH:-feature/sub-accounts}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$BACKUP_ROOT/$STAMP"
PHP_BIN="${PHP_BIN:-php}"

MODE="${1:-}"
[ -z "$MODE" ] && { echo "用法: $0 [--preflight|--deploy|--verify|--rollback <dir>]"; exit 2; }

log() { printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
die() { printf '\n!!! %s\n' "$*" >&2; exit 1; }

[ -d "$APP_DIR" ] || die "项目目录不存在: $APP_DIR"
[ -f "$APP_DIR/artisan" ] || die "$APP_DIR 不是 Laravel 项目根目录"

# ---- 数据库凭据（从 .env 读取，绝不打印） ----
db_creds() {
  local envf="$APP_DIR/.env"
  [ -f "$envf" ] || die "缺少 $envf"
  DB_HOST=$(grep -E '^DB_HOST=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_PORT=$(grep -E '^DB_PORT=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_USER=$(grep -E '^DB_USERNAME=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_PASS=$(grep -E '^DB_PASSWORD=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_NAME_ENV=$(grep -E '^DB_DATABASE=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  : "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}"
  [ -n "${DB_NAME_ENV:-}" ] && DB_NAME="$DB_NAME_ENV"
}
mysql_q() { mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -N -B -e "$1" 2>/dev/null; }

# 基线表：迁移前后必须逐表一致（本功能只新增两张表，不改任何既有表结构）
BASELINE_TABLES="v2_user v2_order v2_plan v2_stat_user v2_stat_server v2_server_group \
v2_server_vless v2_server_hysteria v2_server_shadowsocks v2_server_trojan v2_server_vmess \
v2_server_tuic v2_server_anytls v2_server_v2node"

capture_baseline() {
  local out="$1"; : > "$out"
  for t in $BASELINE_TABLES; do
    printf '%s\t%s\n' "$t" "$(mysql_q "SELECT COUNT(*) FROM \`$t\`;")" >> "$out"
  done
  mysql_q "SELECT CONCAT('admin_id1_fp=', MD5(CONCAT_WS('|', id, email, token, uuid, is_admin, balance, commission_balance))) FROM v2_user WHERE id=1;" >> "$out"
  mysql_q "SHOW CREATE TABLE v2_user;" | md5sum | awk '{print "v2_user_ddl_fp="$1}' >> "$out"
  echo "$out"
}

case "$MODE" in
  --preflight)
    log "只读自检"
    db_creds
    echo "  数据库: $DB_NAME @ $DB_HOST:$DB_PORT"
    echo "  当前分支: $(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo '(非 git 仓库)')"
    echo "  当前提交: $(git -C "$APP_DIR" log -1 --format='%h %s' 2>/dev/null || echo '-')"
    echo "  子账号表: $(mysql_q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='v2_user_sub_accounts';")"
    echo "  审计表:   $(mysql_q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='v2_sub_account_audit_logs';")"
    log "基线采集"
    mkdir -p "$BK"
    capture_baseline "$BK/baseline-before.txt"
    cat "$BK/baseline-before.txt"
    log "自检完成（未做任何写入）。备份目录: $BK"
    ;;

  --deploy)
    log "1/6 备份（代码 + 全库 + .env）"
    db_creds
    mkdir -p "$BK"
    mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" --single-transaction --quick \
      "$DB_NAME" | gzip -9 > "$BK/db-full.sql.gz"
    tar -czf "$BK/app-code.tar.gz" -C "$APP_DIR" app config database public/assets resources tools 2>/dev/null || true
    cp -a "$APP_DIR/.env" "$BK/env.backup"
    ( cd "$BK" && sha256sum db-full.sql.gz app-code.tar.gz env.backup > SHA256SUMS )
    log "备份完成: $BK"

    log "2/6 落代码（git，绝不 reset --hard 覆盖生产改动前请先确认工作树干净）"
    [ -z "$(git -C "$APP_DIR" status --porcelain)" ] || die "生产工作树不干净，请先人工确认后再部署"
    git -C "$APP_DIR" fetch --all -q
    git -C "$APP_DIR" checkout -q "$BRANCH"
    git -C "$APP_DIR" pull --ff-only -q
    echo "  现在提交: $(git -C "$APP_DIR" log -1 --format='%h %s')"
    grep -q 'joanhey/adapterman' "$APP_DIR/composer.json" || echo "  警告: composer.json 中未发现 joanhey/adapterman（生产依赖它，请人工确认）"

    log "3/6 建表（幂等，只新增两张表）"
    ( cd "$APP_DIR" && $PHP_BIN artisan sub-account:install --check && $PHP_BIN artisan sub-account:install --apply )

    log "4/6 配置与缓存"
    ( cd "$APP_DIR" && $PHP_BIN artisan config:cache && $PHP_BIN artisan route:cache 2>/dev/null || true )

    log "5/6 重载 AdapterMan（SIGUSR1），不重启整机"
    PID=$(pgrep -f 'workerman|adapterman|webman' | head -1 || true)
    if [ -n "${PID:-}" ]; then kill -USR1 "$PID" && echo "  已向 pid=$PID 发送 SIGUSR1"; else echo "  未找到常驻进程，请人工重启"; fi

    log "6/6 健康检查"
    bash "$0" --verify || die "部署后验证失败，请立即回滚: bash $0 --rollback $BK"
    log "部署完成。备份: $BK"
    ;;

  --verify)
    db_creds
    log "验证"
    ( cd "$APP_DIR" && $PHP_BIN artisan sub-account:install --check )
    echo "  之前基线:"
    [ -f "$(ls -d "$BACKUP_ROOT"/* 2>/dev/null | tail -1)/baseline-before.txt" ] && cat "$(ls -d "$BACKUP_ROOT"/* | tail -1)/baseline-before.txt"
    echo "  现在基线:"
    capture_baseline /tmp/baseline-after.txt
    cat /tmp/baseline-after.txt
    log "差异（期望：仅新增两张子账号表，其它一致）"
    diff "$(ls -d "$BACKUP_ROOT"/* | tail -1)/baseline-before.txt" /tmp/baseline-after.txt || true
    ;;

  --rollback)
    TARGET="${2:-}"
    [ -n "$TARGET" ] && [ -d "$TARGET" ] || die "用法: $0 --rollback <备份目录>"
    db_creds
    log "回滚数据库（$TARGET/db-full.sql.gz）"
    gunzip -c "$TARGET/db-full.sql.gz" | mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"
    log "回滚代码（$TARGET/app-code.tar.gz）"
    tar -xzf "$TARGET/app-code.tar.gz" -C "$APP_DIR"
    cp -a "$TARGET/env.backup" "$APP_DIR/.env"
    ( cd "$APP_DIR" && $PHP_BIN artisan config:cache )
    PID=$(pgrep -f 'workerman|adapterman|webman' | head -1 || true)
    [ -n "${PID:-}" ] && kill -USR1 "$PID" || true
    log "回滚完成（关系表随整库备份一并回滚）"
    ;;

  *) die "未知模式: $MODE" ;;
esac
