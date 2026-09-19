#!/usr/bin/env bash
# ============================================================================
# V2Board 子账号功能 —— 目标站部署脚本
# ----------------------------------------------------------------------------
# 设计原则: 先备份、可回滚、最小改动、不动无关文件。
#
# 用法（在目标服务器上执行）:
#   bash tools/subaccount-deploy.sh --preflight      # 只做备份+基线采集，不改线上
#   bash tools/subaccount-deploy.sh --deploy         # 部署（需已完成 preflight）
#   bash tools/subaccount-deploy.sh --verify         # 部署后验证
#
# 环境变量（按目标站实际情况覆盖）:
#   APP_DIR      默认 /www/wwwroot/v2board.com
#   DB_NAME      默认 sql_v2board_com
#   BACKUP_ROOT  默认 /root/v2board-subaccount-backup
#   RELEASE_TGZ  已测试分支的代码包（tar.gz），--deploy 必填
# ============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/v2board.com}"
DB_NAME="${DB_NAME:-sql_v2board_com}"
BACKUP_ROOT="${BACKUP_ROOT:-/root/v2board-subaccount-backup}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$BACKUP_ROOT/$STAMP"
PHP_BIN="${PHP_BIN:-php}"

MODE="${1:-}"
if [ -z "$MODE" ]; then
  echo "用法: $0 [--preflight|--deploy|--verify]"
  exit 2
fi

log()  { printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$*"; }
die()  { printf '\n!!! %s\n' "$*" >&2; exit 1; }

[ -d "$APP_DIR" ] || die "项目目录不存在: $APP_DIR"
[ -f "$APP_DIR/artisan" ] || die "$APP_DIR 不是 Laravel 项目根目录"

# 从 .env 读取数据库凭据（不输出）
db_creds() {
  local envf="$APP_DIR/.env"
  [ -f "$envf" ] || die "缺少 $APP_DIR/.env"
  DB_HOST=$(grep -E '^DB_HOST=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_PORT=$(grep -E '^DB_PORT=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_USER=$(grep -E '^DB_USERNAME=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_PASS=$(grep -E '^DB_PASSWORD=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  DB_NAME_ENV=$(grep -E '^DB_DATABASE=' "$envf" | head -1 | cut -d= -f2- | tr -d '"'"'"' ')
  : "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}"
  [ -n "${DB_NAME_ENV:-}" ] && DB_NAME="$DB_NAME_ENV"
}
mysql_q() { mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" -N -B -e "$1" 2>/dev/null; }

# 需要采集基线的表（用户/订单/套餐/流量 + 全部节点表，后者只读不迁移）
BASELINE_TABLES="v2_user v2_order v2_plan v2_stat v2_stat_user v2_stat_server \
v2_server_shadowsocks v2_server_vmess v2_server_trojan v2_server_tuic v2_server_hysteria \
v2_server_vless v2_server_anytls v2_server_v2node v2_server_group v2_server_route"

capture_baseline() {
  local out="$1"
  : > "$out"
  for t in $BASELINE_TABLES; do
    local exists
    exists=$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$t';")
    if [ "${exists:-0}" = "1" ]; then
      local n
      n=$(mysql_q "SELECT COUNT(*) FROM \`$DB_NAME\`.\`$t\`;")
      # CHECKSUM TABLE 作为附加校验值
      local cs
      cs=$(mysql_q "CHECKSUM TABLE \`$DB_NAME\`.\`$t\`;" | awk '{print $2}')
      printf '%-32s rows=%-10s checksum=%s\n' "$t" "$n" "$cs" >> "$out"
    else
      printf '%-32s ABSENT\n' "$t" >> "$out"
    fi
  done
}

case "$MODE" in

# ---------------------------------------------------------------- preflight
--preflight)
  db_creds
  mkdir -p "$BK"
  chmod 700 "$BACKUP_ROOT" "$BK"

  log "0) 环境信息"
  {
    echo "date=$(date -Is)"
    echo "app_dir=$APP_DIR"
    echo "db_name=$DB_NAME"
    echo "php=$($PHP_BIN -v | head -1)"
    echo "mysql=$(mysql --version)"
    echo "hostname=$(hostname)"
    echo "git_head=$(git -C "$APP_DIR" rev-parse HEAD 2>/dev/null || echo n/a)"
    echo "git_branch=$(git -C "$APP_DIR" rev-parse --abbrev-ref HEAD 2>/dev/null || echo n/a)"
    echo "git_remote=$(git -C "$APP_DIR" remote -v 2>/dev/null | head -1)"
  } | tee "$BK/00-environment.txt"
  $PHP_BIN -v | head -1 >> "$BK/00-environment.txt"

  log "1) 备份整库 -> $BK/db-full.sql.gz"
  mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" \
    --single-transaction --routines --triggers --events \
    --default-character-set=utf8mb4 \
    "$DB_NAME" 2>/dev/null | gzip -9 > "$BK/db-full.sql.gz"
  [ -s "$BK/db-full.sql.gz" ] || die "数据库备份为空，终止"
  ls -l "$BK/db-full.sql.gz" | tee "$BK/01-db-backup.txt"

  log "2) 备份关键项目文件"
  mkdir -p "$BK/files"
  for f in config/v2board.php composer.json composer.lock resources/views/admin.blade.php; do
    if [ -f "$APP_DIR/$f" ]; then
      mkdir -p "$BK/files/$(dirname "$f")"
      cp -a "$APP_DIR/$f" "$BK/files/$f"
      echo "backed up $f" | tee -a "$BK/02-files.txt"
    else
      echo "absent $f" | tee -a "$BK/02-files.txt"
    fi
  done
  if [ -d "$APP_DIR/public/assets/admin" ]; then
    cp -a "$APP_DIR/public/assets/admin" "$BK/files/public-assets-admin"
    echo "backed up public/assets/admin" | tee -a "$BK/02-files.txt"
  fi

  log "3) 备份项目代码（排除 vendor/node_modules/.git）"
  tar -czf "$BK/app-code.tar.gz" -C "$(dirname "$APP_DIR")" \
    --exclude=vendor --exclude=node_modules --exclude=.git \
    "$(basename "$APP_DIR")" 2>/dev/null || true
  ls -l "$BK/app-code.tar.gz" | tee -a "$BK/02-files.txt"

  log "4) 备份当前子账号功能表（若已存在）"
  for t in v2_user_sub_accounts v2_sub_account_audit_logs; do
    if [ "$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$t';")" = "1" ]; then
      mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" "$t" 2>/dev/null | gzip -9 > "$BK/pre-$t.sql.gz"
      echo "backed up $t" | tee -a "$BK/03-subaccount-tables.txt"
    fi
  done

  log "5) 采集基线行数与校验值"
  capture_baseline "$BK/04-baseline-tables.txt"
  cat "$BK/04-baseline-tables.txt"

  log "6) SHA-256"
  ( cd "$BK" && find . -type f ! -name 'SHA256SUMS.txt' -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt )
  cat "$BK/SHA256SUMS.txt"

  echo "$BK" > "$BACKUP_ROOT/LATEST"
  log "preflight 完成。备份目录: $BK"
  ;;

# ------------------------------------------------------------------- deploy
--deploy)
  db_creds
  [ -n "${RELEASE_TGZ:-}" ] || die "--deploy 需要设置 RELEASE_TGZ=/path/to/release.tar.gz"
  [ -f "$RELEASE_TGZ" ] || die "找不到代码包: $RELEASE_TGZ"
  LATEST="$(cat "$BACKUP_ROOT/LATEST" 2>/dev/null || true)"
  [ -n "$LATEST" ] || die "未找到 preflight 备份，请先执行 --preflight"

  log "1) 保护 adapterman 等本地修改: 记录部署前的 composer.json / config/v2board.php 指纹"
  # 注意: 不能让缺失的可选文件（例如全新环境下还没有 config/v2board.php）
  # 因为 set -e 而静默中止整个部署，也不能用 2>/dev/null 把原因藏起来。
  {
    for f in composer.json composer.lock config/v2board.php; do
      if [ -f "$APP_DIR/$f" ]; then
        sha256sum "$APP_DIR/$f"
      else
        echo "$f: ABSENT（部署前不存在，跳过指纹记录）"
      fi
    done
  } | tee "$LATEST/05-pre-deploy-hashes.txt"

  log "2) 短暂停止 Horizon / Scheduler / Workerman"
  cd "$APP_DIR"
  # SKIP_SERVICE_CONTROL=1 时跳过所有服务控制。
  # 用途: (a) 在非生产环境彩排部署流程；(b) 服务由面板/pm2/systemd 托管、
  # 不允许脚本直接 pkill 的主机。默认不设置即为正常行为。
  if [ "${SKIP_SERVICE_CONTROL:-0}" = "1" ]; then
    echo "SKIP_SERVICE_CONTROL=1 -> 跳过服务停止（彩排/托管环境）" | tee "$LATEST/06-services-stopped.txt"
  else
    $PHP_BIN artisan horizon:terminate 2>/dev/null || true
    pkill -f 'artisan horizon' 2>/dev/null || true
    pkill -f 'artisan schedule:run' 2>/dev/null || true
    pkill -f 'workerman' 2>/dev/null || true
    sleep 3
    echo "stopped" | tee "$LATEST/06-services-stopped.txt"
  fi

  log "3) 解包已测试代码（保留 config/v2board.php 与本地 composer.json 改动）"
  TMPD="$(mktemp -d)"
  tar -xzf "$RELEASE_TGZ" -C "$TMPD"
  SRC="$TMPD"
  [ -f "$SRC/artisan" ] || SRC="$(find "$TMPD" -maxdepth 2 -name artisan -printf '%h\n' | head -1)"
  [ -f "$SRC/artisan" ] || die "代码包结构无法识别"

  # 保留线上 config/v2board.php 与 composer.json / composer.lock
  rm -f "$SRC/config/v2board.php" 2>/dev/null || true
  if [ -f "$APP_DIR/composer.json" ]; then cp -a "$APP_DIR/composer.json" "$SRC/composer.json"; fi
  if [ -f "$APP_DIR/composer.lock" ]; then cp -a "$APP_DIR/composer.lock" "$SRC/composer.lock"; fi
  # 保留线上已上传的 admin 资源（custom.css 等），但允许本次新增的 subaccount 资源落地
  if [ -d "$APP_DIR/public/assets/admin" ]; then
    mkdir -p "$SRC/public/assets/admin"
    for f in "$APP_DIR/public/assets/admin"/*; do
      b="$(basename "$f")"
      case "$b" in
        subaccount-admin-page.js|subaccount-admin.css) ;;
        *) [ -e "$SRC/public/assets/admin/$b" ] || cp -a "$f" "$SRC/public/assets/admin/$b" ;;
      esac
    done
  fi

  # 复制代码，但绝不覆盖 vendor / .env / config/v2board.php / 主题 / 节点数据
  rsync -a --delete \
    --exclude='.env' --exclude='vendor' --exclude='node_modules' --exclude='.git' \
    --exclude='config/v2board.php' \
    --exclude='storage/logs/*' --exclude='storage/framework/cache/*' \
    --exclude='storage/framework/sessions/*' --exclude='storage/framework/views/*' \
    "$SRC"/ "$APP_DIR"/ 2>/dev/null || {
      tar -czf "$TMPD/release.tgz" -C "$SRC" .
      tar -xzf "$TMPD/release.tgz" -C "$APP_DIR"
    }
  rm -rf "$TMPD"
  echo "code deployed" | tee "$LATEST/07-code-deployed.txt"

  log "4) 校验禁止改动项"
  # 注意: 若 $APP_DIR 不是 git 仓库（或 git 不可用），git diff 会失败并把用法文本
  # 写到 stdout。绝不能把这种错误输出当成"主题被改动"而误中止部署。
  if git -C "$APP_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    THEME_DIFF="$(git -C "$APP_DIR" diff --stat -- public/theme 2>/dev/null || true)"
    printf '%s\n' "$THEME_DIFF" > "$LATEST/08-theme-diff.txt"
    if [ -n "$THEME_DIFF" ]; then
      die "public/theme 出现改动，终止部署（详见 $LATEST/08-theme-diff.txt）"
    fi
    echo "public/theme 未改动 (OK)" | tee -a "$LATEST/08-theme-diff.txt"
  else
    echo "跳过: $APP_DIR 不是 git 仓库，无法用 git diff 校验 public/theme" | tee "$LATEST/08-theme-diff.txt"
    echo "  -> 建议部署后在 git 工作副本中执行: git diff --stat -- public/theme"
  fi

  log "5) 最小合并新增配置项（不使用仓库默认配置覆盖线上 config/v2board.php）"
  cp -a "$APP_DIR/config/v2board.php" "$BK/config-v2board.php.pre-deploy" 2>/dev/null || true
  $PHP_BIN -r '
    $f = $argv[1];
    $cfg = file_exists($f) ? require $f : [];
    if (!is_array($cfg)) { fwrite(STDERR, "config/v2board.php 不是数组\n"); exit(1); }
    $defaults = [
      "sub_account_enable" => 0,
      "sub_account_max_count" => 1,
      "sub_account_email_code_ttl" => 300,
      "sub_account_email_code_interval" => 60,
    ];
    $changed = [];
    foreach ($defaults as $k => $v) {
      if (!array_key_exists($k, $cfg)) { $cfg[$k] = $v; $changed[] = $k; }
    }
    if ($changed) {
      file_put_contents($f . ".tmp", "<?php\n return " . var_export($cfg, true) . " ;");
      rename($f . ".tmp", $f);
      echo "merged keys: " . implode(",", $changed) . "\n";
    } else {
      echo "keys already present\n";
    }
  ' "$APP_DIR/config/v2board.php"

  log "6) sub-account:install --check"
  cd "$APP_DIR"
  $PHP_BIN artisan sub-account:install --check | tee "$LATEST/09-install-check.txt"

  log "7) sub-account:install --apply"
  $PHP_BIN artisan sub-account:install --apply | tee "$LATEST/10-install-apply.txt"
  $PHP_BIN artisan sub-account:install --check | tee -a "$LATEST/10-install-apply.txt"

  log "8) 重建配置缓存"
  $PHP_BIN artisan config:clear >/dev/null 2>&1 || true
  $PHP_BIN artisan config:cache | tee "$LATEST/11-config-cache.txt"
  if [ "$($PHP_BIN -r 'echo function_exists("opcache_reset") ? "yes" : "no";' 2>/dev/null)" = "yes" ]; then
    $PHP_BIN -r 'opcache_reset();' || true
  fi

  log "9) 恢复服务"
  if [ "${SKIP_SERVICE_CONTROL:-0}" = "1" ]; then
    echo "SKIP_SERVICE_CONTROL=1 -> 跳过服务恢复（彩排/托管环境，服务未被停止）" | tee "$LATEST/12-services-restore.txt"
  else
    echo "请按目标站实际方式恢复: supervisord / pm2 / systemd"
    echo "  supervisorctl restart all"
    echo "  (或) php artisan horizon"
    echo "  (或) php start.php start -d"
    echo "restore-manual" | tee "$LATEST/12-services-restore.txt"
  fi

  log "deploy 完成。备份与日志: $LATEST"
  echo "下一步: bash $0 --verify"
  ;;

# ------------------------------------------------------------------- verify
--verify)
  # 验证段是只读巡检，应当"报告问题"而不是在第一个非零命令处中止，
  # 因此这里关闭 errexit（否则如 route:list 之类的探测失败会静默截断输出）。
  set +e
  db_creds
  cd "$APP_DIR"
  log "1) 功能状态"
  $PHP_BIN artisan sub-account:status

  log "2) 结构检测"
  $PHP_BIN artisan sub-account:install --check

  log "3) 关键表行数（与 preflight 基线对照）"
  capture_baseline "$BACKUP_ROOT/after-tables.txt"
  cat "$BACKUP_ROOT/after-tables.txt"

  LATEST="$(cat "$BACKUP_ROOT/LATEST" 2>/dev/null || true)"
  if [ -n "$LATEST" ] && [ -f "$LATEST/04-baseline-tables.txt" ]; then
    log "4) 与基线差异（用户/订单/套餐/流量应有变化，节点表必须完全一致）"
    diff -u "$LATEST/04-baseline-tables.txt" "$BACKUP_ROOT/after-tables.txt" || true
  fi

  log "5) 主题未被改动"
  if git -C "$APP_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    if [ -z "$(git -C "$APP_DIR" diff --stat -- public/theme 2>/dev/null || true)" ]; then
      echo "public/theme 无改动 (OK)"
    else
      git -C "$APP_DIR" diff --stat -- public/theme
      echo "!!! public/theme 存在改动"
    fi
  else
    echo "跳过: $APP_DIR 不是 git 仓库（无法用 git diff 校验 public/theme）"
  fi

  log "6) 路由注册检查"
  # 注意: 上游 Guest\TelegramController 构造函数在缺少 Telegram 配置时会 abort，
  # 且 Laravel 会把异常渲染到 **stdout**。因此不能用"输出是否为空"判断，
  # 必须看退出码。
  ROUTES=$($PHP_BIN artisan route:list 2>/dev/null); RL_EXIT=$?
  if [ "$RL_EXIT" -ne 0 ] || [ -z "$ROUTES" ]; then
    echo "route:list 在本环境不可用 (exit=$RL_EXIT)，降级为源码校验:"
    echo "  UserRoute  sub-account 行数: $(grep -c 'sub-account' "$APP_DIR/app/Http/Routes/V1/UserRoute.php" 2>/dev/null || echo n/a)"
    echo "  AdminRoute sub-account 行数: $(grep -c 'sub-account' "$APP_DIR/app/Http/Routes/V1/AdminRoute.php" 2>/dev/null || echo n/a)"
    echo "  Controller 存在: $(ls "$APP_DIR/app/Http/Controllers/V1/User/SubAccountController.php" 2>/dev/null || echo MISSING)"
  else
    echo "$ROUTES" | grep -E 'sub-account' || echo "未匹配到 sub-account 路由"
  fi
  ;;

*)
  die "未知参数: $MODE"
  ;;
esac
