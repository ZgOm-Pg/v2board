# V2Board 原生子账号（Sub-Account）功能

> 基线：`wyx2685/v2board` `99f8526eddb72a4e8f6cbccd58cc0656bb91fe88`
> 分支：`feature/sub-accounts`（Fork：`ZgOm-Pg/v2board`）
> 兼容桌面主题：EZ-Theme 的 `#/sub-accounts` 页面（`src/api/subAccount.js` 契约）

## 一、功能范围

| 能力 | 说明 |
|---|---|
| 绑定 | 主账号绑定已有空白用户，或用邮箱验证码新建子账号 |
| 权益继承 | 子账号使用主账号的套餐、权限组、到期时间、限速、设备数；子账号自身字段保持中性 |
| 独立凭据 | 每个子账号有自己的 `id / uuid / token / email / password` |
| 独立额度 | `traffic_limit`（字节，0 = 不设独立上限），与主账号共享池双重限制 |
| 流量记账 | 子账号 u/d 记在自己身上，同时等额汇入主账号 Redis Hash（同批聚合，一次查询） |
| 周期重置 | 主账号套餐周期重置时级联清零启用子账号；子账号不独立参与周期判断 |
| 权限限制 | 启用中的子账号禁止下单/充值/提现/佣金/邀请/独立续费/创建子账号 |
| 解绑 | 只停用关系并轮换凭据，不删除用户、流量历史与审计 |
| 管理端 | 后台「用户管理 → 子账号管理」：关系 / 设置 / 审计 三个标签 |

## 二、安装

```bash
# 1. 代码
git fetch origin feature/sub-accounts && git checkout feature/sub-accounts

# 2. 建表（幂等，只新增两张表，不改任何既有表结构）
php artisan sub-account:install --check    # 只读检测：列类型/索引/外键是否与预期一致
php artisan sub-account:install --apply    # 创建缺失的表与索引

# 3. 刷新缓存
php artisan config:cache
```

`sub-account:install` 会先读取 `v2_user.id` 的真实列类型（本仓库常见为 `int(11)`），
按实际类型生成 `parent_user_id / child_user_id / actor_user_id` 的 DDL，类型不匹配时直接失败，不做猜测。
旧命令名 `subaccount:install` 仍可用（别名）。

也可直接执行 `database/install.sql` / `database/update.sql` 末尾的「子账号功能」独立增量区块
（内容等价、`CREATE TABLE IF NOT EXISTS` 幂等）。

状态总览：

```bash
php artisan sub-account:status
```

## 三、配置

沿用 V2Board 现有配置机制（`config/v2board.php` + 后台「系统设置」→ `V1\Admin\ConfigController` + `ConfigSave`）：

| 键 | 默认 | 说明 |
|---|---|---|
| `sub_account_enable` | `1` | 子账号功能总开关 |
| `sub_account_max_count` | `1` | 每个主账号最多可绑定的子账号数 |
| `sub_account_email_code_ttl` | `300` | 邮箱验证码有效期（秒） |
| `sub_account_email_code_interval` | `60` | 同一邮箱验证码发送间隔（秒） |

后台「子账号管理 → 设置」标签可直接读写以上四项。

## 四、用户端 API（EZ-Theme 契约）

| 方法 | 路径 | 参数 | 编码 |
|---|---|---|---|
| GET | `/api/v1/user/sub-account/list` | — | — |
| POST | `/api/v1/user/sub-account/send-code` | `email` | form |
| POST | `/api/v1/user/sub-account/bind` | `email` `email_code` `traffic_limit_gb` `remark` | form |
| POST | `/api/v1/user/sub-account/update` | `id` `traffic_limit_gb` `remark` | form |
| POST | `/api/v1/user/sub-account/change-password` | `id` 或 `child_user_id`，`new_password`（或 `password`） | JSON |
| POST | `/api/v1/user/sub-account/reset-traffic` | `id` | form |
| GET | `/api/v1/user/sub-account/subscribe` | `id`（关系 id）或 `child_user_id` | — |
| POST | `/api/v1/user/sub-account/reset-subscribe` | `id` | JSON |
| POST | `/api/v1/user/sub-account/unbind` | `id` | form |

兼容要点（与主题源码逐条对齐）：

- `list` 的 `data.enabled` 是 **JSON 布尔**（只有严格 `false` 表示未启用）；`items` 必须是数组，
  每项至少包含 `id`、`child_user_id`、`email`、`remark`、`traffic_limit_gb`、`used_traffic_text`、`subscribe_url`。
- 额度入参以 **GB** 为准（`traffic_limit_gb`），服务层换算为字节；同时兼容字节语义的 `traffic_limit`。
- 错误统一返回 HTTP 状态码 + `message` 字段（前端的唯一文案来源）。
- 8 个 `fetch` 端点发 `form-urlencoded`，`change-password` / `reset-subscribe` 发 JSON，后端两种都收。

## 五、管理端 API

前缀为后台安全路径（`secure_path`），复用现有 admin 中间件：

```
GET  /api/v1/{secure_path}/sub-account/fetch            列表（parent_user_id/child_user_id 支持 ID 或邮箱关键字、status）
GET  /api/v1/{secure_path}/sub-account/detail           关系详情（父子快照 + 权益 + 最近审计）
GET  /api/v1/{secure_path}/sub-account/audit            审计日志
GET  /api/v1/{secure_path}/sub-account/status           开关/上限/健康度
POST /api/v1/{secure_path}/sub-account/update           改额度(字节或GB)/备注/状态
POST /api/v1/{secure_path}/sub-account/reset-subscribe  重置子账号订阅
POST /api/v1/{secure_path}/sub-account/reset-traffic    重置子账号流量
POST /api/v1/{secure_path}/sub-account/unbind           解绑
```

后台页面通过克隆原生「用户管理」菜单节点注入「子账号管理」，**不修改 `public/assets/admin/umi.js`**，
资源为独立文件 `public/assets/admin/subaccount-admin-page.{js,css}`，在 `resources/views/admin.blade.php` 中加载。

## 六、升级

```bash
git fetch upstream && git merge upstream/master   # 标准上游同步
php artisan sub-account:install --check           # 结构仍需匹配
php artisan config:cache
```

> 不使用 `git reset --hard` 作为部署更新流程；不引入 v3board 的 `update.sh` 或其它功能。

## 七、回滚

```bash
# 方式一：只回退关系数据（功能仍保留）
mysql -e "DROP TABLE IF EXISTS v2_user_sub_accounts; DROP TABLE IF EXISTS v2_sub_account_audit_logs;"

# 方式二：整库/整码回退（推荐，见 tools/subaccount-deploy.sh）
bash tools/subaccount-deploy.sh --rollback /root/v2board-subaccount-backup/<时间戳>
```

**卸载默认不删除任何数据**：两张表独立存在，删除表即卸载功能，用户、订单、流量历史不受影响。

## 八、迁移既有子账号关系（源 XBoard → 本功能）

```bash
# 1. 源站只读导出（在执行迁移的机器上，连到源库）
bash export_source_relations.sh                 # 生成 relations.jsonl

# 2. 目标站预检（不写入）
php tools/subaccount-migrate-relations.php --source=/path/relations.jsonl --dry-run

# 3. 导入（单事务；只写关系表与审计表，绝不修改 v2_user）
php tools/subaccount-migrate-relations.php --source=/path/relations.jsonl --apply
```

- 只自动导入「源 status=1 且父子用户在目标库都存在且邮箱一致」的关系；
- 停用关系、孤儿关系、目标缺用户、邮箱不一致、ID 冲突、自绑定、多层关系一律进入异常清单文件，不导入；
- 重复执行幂等（同一 `child_user_id` 复用原关系行）。

## 九、测试

```bash
php vendor/bin/phpunit --filter 'SubAccount'         # 119 tests / 923 assertions
php tools/subaccount-redis-traffic-test.php          # 真实 Redis 精确流量测试（20 项）
php tools/subaccount-acceptance.php http://127.0.0.1:8085   # 独立实例真实 HTTP 验收（53 项）
```

> `tools/subaccount-acceptance.php` 与 `tools/subaccount-redis-traffic-test.php` 会自动建/删测试数据，
> **只允许在独立测试实例执行**（脚本会检查数据库名是否像测试库）。
