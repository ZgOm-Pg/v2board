<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 子账号数据结构检测与安装。
 *
 * 目标库没有 migrations 记录表，因此不提供通用 migrate，只提供本功能专用命令:
 *   php artisan sub-account:status
 *   php artisan sub-account:install --check
 *   php artisan sub-account:install --apply
 *
 * 约束:
 *   - --check 只读；
 *   - --apply 只创建本功能的两张表与索引，不做任何其它改动；
 *   - 重复执行安全（已装好则直接成功返回）；
 *   - 部分安装或结构不匹配时明确失败，不静默修复、不静默捕获异常；
 *   - 永不删除表。
 */
class SubAccountInstall extends Command
{
    protected $signature = 'sub-account:install {--check : 只做结构与类型检测，不写入任何数据}
                                                 {--apply : 创建本功能缺失的表与索引}';

    /** 兼容旧命令名，避免已部署脚本失效。 */
    protected $aliases = ['subaccount:install'];

    protected $description = '子账号功能: 检测(--check) 或 安装(--apply) 数据表与索引';

    const TABLE_RELATIONS = 'v2_user_sub_accounts';
    const TABLE_AUDIT = 'v2_sub_account_audit_logs';

    /** 与 v2_user.id 同类型的列 */
    const USER_ID_COLUMNS = ['parent_user_id', 'child_user_id', 'actor_user_id'];

    public function handle()
    {
        $check = (bool)$this->option('check');
        $apply = (bool)$this->option('apply');

        if ($check && $apply) {
            $this->error('--check 与 --apply 不能同时使用。');
            return 1;
        }
        if (!$check && !$apply) {
            $this->error('必须指定 --check 或 --apply。（只读总览请使用 php artisan sub-account:status）');
            return 1;
        }

        $db = DB::connection();
        $database = $db->getDatabaseName();
        $this->line("database: <info>{$database}</info>");

        // 1. v2_user 必须存在，且 id 类型可识别
        $idType = $this->detectUserIdType($database);
        if ($idType === null) {
            $this->error('无法识别 v2_user.id 的列类型，拒绝继续（不猜测结构）。');
            return 1;
        }
        $this->line("v2_user.id 类型: <info>{$idType['raw']}</info> (归一化: {$idType['normalized']})");
        if ($idType['base'] === null) {
            $this->error('v2_user.id 不是整数类型，关系表外键无法建立，拒绝继续。');
            return 1;
        }

        if ($check) {
            $problems = $this->inspect($database, $idType);
            return $this->report($problems, false);
        }

        // ---- apply ----
        $problems = $this->inspect($database, $idType);
        if (empty($problems)) {
            $this->info('两张表已存在且结构匹配，无需安装（幂等）。');
            return 0;
        }
        $blocking = array_filter($problems, function ($p) {
            return $p['kind'] !== 'missing_table';
        });
        if (!empty($blocking)) {
            $this->error('检测到结构不匹配或部分安装，为避免破坏数据，--apply 拒绝继续。请人工核对：');
            $this->report($problems, true);
            return 1;
        }

        $this->warn('将创建以下缺失的表: ' . implode(', ', array_column($problems, 'table')));
        foreach ($problems as $problem) {
            $this->line("  创建 {$problem['table']} ...");
            DB::statement($this->createTableSql($problem['table'], $idType));
        }

        // 重新校验，任何残留问题都必须显式失败
        $problems = $this->inspect($database, $idType);
        if (!empty($problems)) {
            $this->error('安装后校验仍未通过，请人工检查：');
            $this->report($problems, true);
            return 1;
        }
        $this->info('安装完成，结构校验通过。');
        return 0;
    }

    // ------------------------------------------------------------- 类型识别

    private function detectUserIdType($database)
    {
        $row = DB::selectOne(
            "SELECT COLUMN_TYPE AS column_type, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'v2_user' AND COLUMN_NAME = 'id'",
            [$database]
        );
        if (!$row) return null;
        return [
            'raw' => $row->column_type,
            'normalized' => $this->normalizeType($row->column_type),
            'base' => $this->integerBase($row->data_type),
            'nullable' => strtoupper($row->is_nullable) === 'YES'
        ];
    }

    /**
     * 归一化列类型，用于跨 MySQL 版本比较（忽略整数显示宽度）。
     */
    private function normalizeType($type)
    {
        $t = strtolower(trim((string)$type));
        $t = preg_replace('/\s+/', ' ', $t);
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\s*(\(\d+\))?\s*(unsigned)?$/', $t, $m)) {
            $base = $m[1] === 'integer' ? 'int' : $m[1];
            return $base . (empty($m[3]) ? '' : ' unsigned');
        }
        return $t;
    }

    private function integerBase($dataType)
    {
        $t = strtolower((string)$dataType);
        return in_array($t, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true) ? $t : null;
    }

    /**
     * 把 v2_user.id 的实际类型渲染成可直接用于 DDL 的写法（保留显示宽度以贴近原表）。
     */
    private function ddlUserIdType($idType)
    {
        $raw = strtolower(trim($idType['raw']));
        $unsigned = strpos($raw, 'unsigned') !== false;
        $base = $idType['base'] === 'integer' ? 'int' : $idType['base'];
        $width = '';
        if (preg_match('/\((\d+)\)/', $raw, $m)) {
            $width = '(' . $m[1] . ')';
        }
        return strtoupper($base) . $width . ($unsigned ? ' UNSIGNED' : '');
    }

    // ------------------------------------------------------------- 期望结构

    /**
     * 返回期望的表结构定义。
     */
    private function expectedSchema($idType)
    {
        $userIdType = $this->ddlUserIdType($idType);
        $userIdCompare = $idType['normalized'];

        return [
            self::TABLE_RELATIONS => [
                'columns' => [
                    'id' => ['type' => 'bigint(20) unsigned', 'nullable' => false],
                    'parent_user_id' => ['type' => $userIdCompare, 'nullable' => false],
                    'child_user_id' => ['type' => $userIdCompare, 'nullable' => false],
                    'traffic_limit' => ['type' => 'bigint(20)', 'nullable' => false],
                    'remark' => ['type' => 'varchar(255)', 'nullable' => true],
                    'status' => ['type' => 'tinyint(4)', 'nullable' => false],
                    'created_by_parent' => ['type' => 'tinyint(4)', 'nullable' => false],
                    'created_at' => ['type' => 'int(11)', 'nullable' => true],
                    'updated_at' => ['type' => 'int(11)', 'nullable' => true],
                ],
                'indexes' => [
                    'PRIMARY' => ['id'],
                    'v2_user_sub_accounts_child_user_id_unique' => ['child_user_id'],
                    // child_user_id 上的唯一索引已能服务「按子账号查询」，
                    // 不再额外建立 (child_user_id, status) 重复索引（无收益）。
                    'v2_user_sub_accounts_parent_user_id_status_index' => ['parent_user_id', 'status'],
                ],
                'foreign_keys' => [
                    'v2_user_sub_accounts_parent_user_id_foreign' => ['parent_user_id', 'v2_user', 'id', 'CASCADE'],
                    'v2_user_sub_accounts_child_user_id_foreign' => ['child_user_id', 'v2_user', 'id', 'CASCADE'],
                ],
            ],
            self::TABLE_AUDIT => [
                'columns' => [
                    'id' => ['type' => 'bigint(20) unsigned', 'nullable' => false],
                    'relation_id' => ['type' => 'bigint(20) unsigned', 'nullable' => true],
                    'parent_user_id' => ['type' => $userIdCompare, 'nullable' => true],
                    'child_user_id' => ['type' => $userIdCompare, 'nullable' => true],
                    'actor_type' => ['type' => 'varchar(20)', 'nullable' => false],
                    'actor_user_id' => ['type' => $userIdCompare, 'nullable' => true],
                    'action' => ['type' => 'varchar(50)', 'nullable' => false],
                    'metadata' => ['type' => 'json', 'nullable' => true],
                    'ip' => ['type' => 'varchar(64)', 'nullable' => true],
                    'created_at' => ['type' => 'int(11)', 'nullable' => true],
                ],
                'indexes' => [
                    'PRIMARY' => ['id'],
                    'v2_sub_account_audit_logs_relation_id_index' => ['relation_id'],
                    'v2_sub_account_audit_logs_parent_user_id_index' => ['parent_user_id'],
                    'v2_sub_account_audit_logs_child_user_id_index' => ['child_user_id'],
                    'v2_sub_account_audit_logs_actor_user_id_index' => ['actor_user_id'],
                    'v2_sub_account_audit_logs_action_index' => ['action'],
                    'v2_sub_account_audit_logs_created_at_index' => ['created_at'],
                ],
                'foreign_keys' => [],
            ],
        ];
    }

    // ---------------------------------------------------------------- 检测

    /**
     * @return array 问题列表；空数组代表结构完全匹配。
     */
    private function inspect($database, $idType)
    {
        $problems = $this->inspectUserPreconditions($database);
        $schema = $this->expectedSchema($idType);

        foreach ($schema as $table => $expect) {
            if (!$this->tableExists($database, $table)) {
                $problems[] = ['kind' => 'missing_table', 'table' => $table, 'detail' => '表不存在'];
                continue;
            }
            if (!$this->engineIsInnoDb($database, $table)) {
                $problems[] = ['kind' => 'engine_mismatch', 'table' => $table, 'detail' => '引擎不是 InnoDB，外键与事务语义不成立'];
            }

            $columns = $this->columnsOf($database, $table);
            foreach ($expect['columns'] as $column => $spec) {
                if (!isset($columns[$column])) {
                    $problems[] = ['kind' => 'missing_column', 'table' => $table, 'detail' => "缺列 {$column}"];
                    continue;
                }
                $actual = $this->normalizeType($columns[$column]->COLUMN_TYPE);
                $expected = $this->normalizeType($spec['type']);
                if ($actual !== $expected) {
                    $problems[] = [
                        'kind' => 'column_type_mismatch',
                        'table' => $table,
                        'detail' => "列 {$column} 类型 {$columns[$column]->COLUMN_TYPE}，期望 {$spec['type']}"
                    ];
                }
                $actualNullable = strtoupper($columns[$column]->IS_NULLABLE) === 'YES';
                if ($actualNullable !== $spec['nullable']) {
                    $problems[] = [
                        'kind' => 'column_nullability_mismatch',
                        'table' => $table,
                        'detail' => "列 {$column} 可空性 " . ($actualNullable ? 'YES' : 'NO') . '，期望 ' . ($spec['nullable'] ? 'YES' : 'NO')
                    ];
                }
            }
            foreach ($columns as $column => $meta) {
                if (!isset($expect['columns'][$column])) {
                    $problems[] = ['kind' => 'unexpected_column', 'table' => $table, 'detail' => "存在未预期列 {$column}"];
                }
            }

            $indexes = $this->indexesOf($database, $table);
            foreach ($expect['indexes'] as $name => $cols) {
                if (!isset($indexes[$name])) {
                    $problems[] = ['kind' => 'missing_index', 'table' => $table, 'detail' => "缺索引 {$name}"];
                    continue;
                }
                if ($indexes[$name] !== $cols) {
                    $problems[] = [
                        'kind' => 'index_mismatch',
                        'table' => $table,
                        'detail' => "索引 {$name} 列为 [" . implode(',', $indexes[$name]) . ']，期望 [' . implode(',', $cols) . ']'
                    ];
                }
            }

            $fks = $this->foreignKeysOf($database, $table);
            foreach ($expect['foreign_keys'] as $name => $spec) {
                if (!isset($fks[$name])) {
                    $problems[] = ['kind' => 'missing_foreign_key', 'table' => $table, 'detail' => "缺外键 {$name}"];
                    continue;
                }
                $fk = $fks[$name];
                if ($fk['column'] !== $spec[0] || $fk['ref_table'] !== $spec[1]
                    || $fk['ref_column'] !== $spec[2] || strtoupper($fk['on_delete']) !== $spec[3]) {
                    $problems[] = [
                        'kind' => 'foreign_key_mismatch',
                        'table' => $table,
                        'detail' => "外键 {$name} 定义不匹配（ON DELETE {$fk['on_delete']}）"
                    ];
                }
            }
        }

        return $problems;
    }

    /**
     * v2_user 侧前置条件校验。
     *
     * 子账号的语义要求把 plan_id / group_id / expired_at / speed_limit /
     * device_limit 写成 NULL 并继承主账号，同时依赖 email / token 的唯一性。
     * 目标库若不满足，必须在 --check 阶段明确失败，而不是等运行时才报错。
     */
    private function inspectUserPreconditions($database)
    {
        $problems = [];
        $columns = $this->columnsOf($database, 'v2_user');

        foreach (['plan_id', 'group_id', 'expired_at', 'speed_limit', 'device_limit'] as $column) {
            if (!isset($columns[$column])) {
                $problems[] = [
                    'kind' => 'precondition_failed',
                    'table' => 'v2_user',
                    'detail' => "缺列 {$column}（子账号需要写入 NULL）"
                ];
                continue;
            }
            if (strtoupper($columns[$column]->IS_NULLABLE) !== 'YES') {
                $problems[] = [
                    'kind' => 'precondition_failed',
                    'table' => 'v2_user',
                    'detail' => "列 {$column} 为 NOT NULL，但子账号必须写入 NULL；请先改为可空"
                ];
            }
        }

        $uniqueRows = DB::select(
            "SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'v2_user' AND NON_UNIQUE = 0",
            [$database]
        );
        $uniqueSingle = [];
        foreach ($uniqueRows as $row) {
            $uniqueSingle[$row->COLUMN_NAME] = $row->INDEX_NAME;
        }
        foreach (['email', 'token'] as $column) {
            if (isset($columns[$column]) && !isset($uniqueSingle[$column])) {
                $problems[] = [
                    'kind' => 'precondition_failed',
                    'table' => 'v2_user',
                    'detail' => "列 {$column} 缺少唯一索引（子账号凭据独立性依赖）"
                ];
            }
        }

        return $problems;
    }

    private function report(array $problems, $asError = false)
    {
        if (empty($problems)) {
            $this->info('结构检测通过: 两张表、列类型、索引与外键全部匹配。');
            return 0;
        }
        $this->line('检测到 ' . count($problems) . ' 个问题:');
        foreach ($problems as $problem) {
            $line = "  [{$problem['kind']}] {$problem['table']}: {$problem['detail']}";
            if ($asError) $this->error($line); else $this->warn($line);
        }
        return 1;
    }

    private function tableExists($database, $table)
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );
        return $row && (int)$row->c > 0;
    }

    private function engineIsInnoDb($database, $table)
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );
        return $row && strtoupper((string)$row->engine) === 'INNODB';
    }

    private function columnsOf($database, $table)
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $table]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row->COLUMN_NAME] = $row;
        }
        return $map;
    }

    private function indexesOf($database, $table)
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table]
        );
        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->INDEX_NAME])) $map[$row->INDEX_NAME] = [];
            $map[$row->INDEX_NAME][] = $row->COLUMN_NAME;
        }
        return $map;
    }

    private function foreignKeysOf($database, $table)
    {
        $rows = DB::select(
            "SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL",
            [$database, $table]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row->CONSTRAINT_NAME] = [
                'column' => $row->COLUMN_NAME,
                'ref_table' => $row->REFERENCED_TABLE_NAME,
                'ref_column' => $row->REFERENCED_COLUMN_NAME,
                'on_delete' => $row->DELETE_RULE
            ];
        }
        return $map;
    }

    // ---------------------------------------------------------------- 建表

    private function createTableSql($table, $idType)
    {
        $u = $this->ddlUserIdType($idType);

        if ($table === self::TABLE_RELATIONS) {
            return "CREATE TABLE IF NOT EXISTS `" . self::TABLE_RELATIONS . "` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_user_id` {$u} NOT NULL COMMENT '主账号 v2_user.id',
                `child_user_id` {$u} NOT NULL COMMENT '子账号 v2_user.id',
                `traffic_limit` BIGINT(20) NOT NULL DEFAULT 0 COMMENT '子账号个人额度(字节)',
                `remark` VARCHAR(255) DEFAULT NULL,
                `status` TINYINT(4) NOT NULL DEFAULT 1 COMMENT '1=启用 0=停用',
                `created_by_parent` TINYINT(4) NOT NULL DEFAULT 0 COMMENT '1=父账号新建 0=绑定已有账号',
                `created_at` INT(11) DEFAULT NULL,
                `updated_at` INT(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `v2_user_sub_accounts_child_user_id_unique` (`child_user_id`),
                KEY `v2_user_sub_accounts_parent_user_id_status_index` (`parent_user_id`, `status`),
                CONSTRAINT `v2_user_sub_accounts_parent_user_id_foreign`
                    FOREIGN KEY (`parent_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE,
                CONSTRAINT `v2_user_sub_accounts_child_user_id_foreign`
                    FOREIGN KEY (`child_user_id`) REFERENCES `v2_user` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2Board 子账号关系表'";
        }

        if ($table === self::TABLE_AUDIT) {
            return "CREATE TABLE IF NOT EXISTS `" . self::TABLE_AUDIT . "` (
                `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                `relation_id` BIGINT(20) UNSIGNED DEFAULT NULL,
                `parent_user_id` {$u} DEFAULT NULL,
                `child_user_id` {$u} DEFAULT NULL,
                `actor_type` VARCHAR(20) NOT NULL DEFAULT 'user' COMMENT 'user|admin|system',
                `actor_user_id` {$u} DEFAULT NULL,
                `action` VARCHAR(50) NOT NULL,
                `metadata` JSON DEFAULT NULL,
                `ip` VARCHAR(64) DEFAULT NULL,
                `created_at` INT(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `v2_sub_account_audit_logs_relation_id_index` (`relation_id`),
                KEY `v2_sub_account_audit_logs_parent_user_id_index` (`parent_user_id`),
                KEY `v2_sub_account_audit_logs_child_user_id_index` (`child_user_id`),
                KEY `v2_sub_account_audit_logs_actor_user_id_index` (`actor_user_id`),
                KEY `v2_sub_account_audit_logs_action_index` (`action`),
                KEY `v2_sub_account_audit_logs_created_at_index` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='V2Board 子账号审计日志表'";
        }

        throw new \RuntimeException("未知的表: {$table}");
    }
}
