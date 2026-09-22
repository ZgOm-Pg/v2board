# 每日签到 + 活动弹窗（V2Board / Laravel 8）

> 两项功能均按 **EZ-Theme 实际调用的接口契约**实现；签到默认关闭，活动默认无数据。
> 不引入 migration：建表 SQL 同时维护在 `database/install.sql`、`database/update.sql`（末尾独立区块）
> 与 `database/sql/checkin_promotion_mysql57.sql`（可审查等价版本）。

## 一、文件清单

| 类别 | 文件 |
|---|---|
| 表结构 | `database/sql/checkin_promotion_mysql57.sql`、`database/install.sql`、`database/update.sql` |
| 模型 | `app/Models/CheckinReward.php`、`UserCheckinLog.php`、`PromotionPopup.php`、`PromotionRecord.php` |
| 服务 | `app/Services/CheckinService.php`、`app/Services/PromotionService.php` |
| 用户端接口 | `app/Http/Controllers/V1/User/CheckinController.php`、`PromotionController.php` |
| 管理端接口 | `app/Http/Controllers/V1/Admin/CheckinController.php`、`PromotionController.php` |
| 路由 | `app/Http/Routes/V1/UserRoute.php`、`AdminRoute.php` |
| 配置 | `app/Http/Controllers/V1/Admin/ConfigController.php`、`app/Http/Requests/Admin/ConfigSave.php` |
| 命令 | `app/Console/Commands/CheckinInstall.php`（`php artisan checkin:install`） |
| 后台页面 | `public/assets/admin/checkin-promotion-admin-page.js`、`checkin-promotion-admin.css`、`resources/views/admin.blade.php` |
| 后台补丁 | `tools/patch-checkin-promotion-admin.php` |
| 数据同步 | `tools/checkin-migrate-logs.php` |
| 测试 | `tests/Feature/CheckinTest.php`、`PromotionTest.php`、`CheckinPromotionTestCase.php` |

## 二、安装 / 升级

```bash
# 1) 建表（幂等）+ 写入默认奖励规则（第 7/14/21/30 天 = 500MB/2GB/4GB/7GB）
php artisan checkin:install --check
php artisan checkin:install --apply

# 2) 后台菜单与路由（umi 编译产物补丁，幂等；上游同步后必须重跑）
php tools/patch-checkin-promotion-admin.php --check
php tools/patch-checkin-promotion-admin.php --apply

# 3) 生效
php artisan view:clear && php artisan config:cache
```

> `database/install.sql` / `database/update.sql` 末尾已包含同样内容（`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`），
> 用它们建库/升级同样会得到这四张表。

## 三、配置

后台「系统配置」写入 `config/v2board.php`：

| 配置项 | 默认 | 说明 |
|---|---|---|
| `checkin_enable` | `0` | 签到总开关，**默认关闭** |
| `checkin_timezone` | `Asia/Shanghai` | 计算「自然日」的时区 |

后台页面：**签到管理**（`/checkin`，奖励规则 / 签到日志 / 功能设置）、**活动弹窗**（`/promotion`，活动列表 / 行为记录）。

## 四、接口契约（EZ-Theme）

### 签到

```
GET  /api/v1/user/checkin/status     无参数
POST /api/v1/user/checkin/claim      空 body（Content-Type: application/json）
```

`status` / `claim` 的 `data` 字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `enabled` | bool | **严格布尔**（0 不会关闭主题界面） |
| `checked_in` | bool | 严格 `=== true` |
| `continuous_days` | int | 当前连续天数（漏签从 1 重算；跨循环继续累加） |
| `rewards` | array | 元素 `{days,title,reward_text,claimed}`（只含启用规则） |
| `next_reward` | object | `{days,reward_text}` —— **必须是对象**，裸数字会让主题显示 `undefined 天` |
| `month_days` | array | 元素 `{date:'YYYY-MM-DD',status:'checked'\|'missed',checked:bool}` |
| `reward_bytes` | int | 今日已得奖励（0 表示不显示今日奖励文案） |
| `reward_text` / `today_reward_text` | string | 今日奖励文案 |
| `already_checked` | bool | claim 专属：本次是否为重复领取 |

规则：北京时间计日；漏签重算；30 天循环（第 31 天回到第 1 档）；奖励写入 `v2_user.transfer_enable`；
`(user_id, checkin_date)` 唯一索引 + 事务行锁保证同一天只奖励一次；**启用中的子账号调用 claim 返回 403**。

### 活动弹窗

```
GET  /api/v1/user/promotion/popup?page={dashboard|shop|checkin|home}
POST /api/v1/user/promotion/claim   id
POST /api/v1/user/promotion/record  id, action(view|close|click), page
```

`popup` 的 `data` 只包含主题消费的 14 个字段：
`enabled, id, title, subtitle, coupon_code, coupon_name, discount_text, button_text, button_url,
button_action, show_countdown, ends_at, server_time, cooldown_hours`
（无可展示活动时返回 `{"enabled": false}`）。

- `ends_at` / `server_time` 均为 **UNIX 秒**；`ends_at` 有值时 `show_countdown = true`；
- `button_action` 枚举：`claim_and_redirect`（默认）、`redirect`、`claim`、`copy_coupon`；
- `pages` 取值：`all` 或 `dashboard,shop,checkin,home`（主题只会发送这 4 个页面值）；
- `user_scope`：`all` / `new`（注册 7 天内）/ `paid` / `unpaid` —— 仅服务端筛选，不下发；
- **claim 只写行为记录并返回 `coupon_code`，不真正发券**，也不改动用户额度或订单。

## 五、后台集成层级（如实说明）

仓库里**没有管理前端源码**，只有 umi 编译产物 `public/assets/admin/umi.js`。因此：

- 菜单项与路由由 `tools/patch-checkin-promotion-admin.php` **直接写入 umi 原生菜单数组（nav）与路由数组（routes）**，
  容器为 `#checkin-admin-root` / `#promotion-admin-root`（与官方 `/newPeriodLog` 等页面的写法一致）；
- 页面脚本只在上述容器内渲染，**不扫描/克隆/修改侧边栏菜单，无全屏遮罩、无 hash 轮询、无 DOM 注入**；
- 这属于「编译产物补丁」，**不能称为原生源码集成**；上游同步后需重新执行 `--apply`（脚本会先校验锚点唯一性再写入，并自动备份）。

## 六、测试

```bash
php vendor/bin/phpunit --filter 'CheckinTest|PromotionTest'   # 44 tests
php vendor/bin/phpunit                                        # 全量（含子账号回归）
```

## 七、停站后的历史数据同步（原站停止写入之后再做）

> 目标站现在**不导入**历史数据、**不开启**签到。等原站停用后按下面顺序执行。

### 1) 在原站只读导出

```sql
-- 规则
SELECT day_index, reward_bytes, reward_text, enabled FROM <原站签到规则表>;
-- 日志（按用户 ID + 日期）
SELECT user_id, email, checkin_date, continuous_days, day_index, reward_bytes, reward_text, created_at
FROM <原站签到日志表> ORDER BY user_id, checkin_date;
-- 额度快照（用于停站后核对增量）
SELECT id AS user_id, email, transfer_enable FROM v2_user;
```

导出为 JSONL（每行一个对象，字段名与上表一致），例如 `logs.jsonl`、`rules.jsonl`、`quota.jsonl`。

### 2) 预演（不写库）

```bash
php tools/checkin-migrate-logs.php --source=logs.jsonl --rules=rules.jsonl
```
输出可导入条数、已存在跳过条数、异常清单（目标库不存在该 user_id / 邮箱不一致 / 日期非法 / 源内重复），
异常明细写入 `storage/app/checkin-migration-exceptions.jsonl`。

### 3) 正式导入

```bash
php tools/checkin-migrate-logs.php --source=logs.jsonl --rules=rules.jsonl --apply
```

- 单事务、幂等；已存在的 `(user_id, checkin_date)` **保留原值**（不重算、不覆盖）；
- 导入行一律 `credited = 0`、`source = migration` —— **原站已把奖励计入 `transfer_enable`，绝不能再加一次**；
- 结束时打印校验：`v2_user` 行数与 `transfer_enable` 合计**必须不变**，否则说明脚本行为异常，立即回滚。

### 4) 核对额度增量与不存在的用户引用

```bash
php tools/checkin-migrate-logs.php --quota=quota.jsonl
```
逐用户比较原站与目标库的 `transfer_enable`，列出额度不一致（停站后仍有写入）与目标库不存在的用户引用。

### 5) 开启签到

```bash
# 后台「签到管理 → 功能设置」开启，或写入 config/v2board.php: checkin_enable = 1
php artisan config:cache
```
