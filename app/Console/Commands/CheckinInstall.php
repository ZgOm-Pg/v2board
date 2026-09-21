<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 签到 / 活动弹窗 —— 四张表的安装与校验（幂等）。
 *
 * 与项目现有约定一致：不引入 migration，建表 SQL 同时维护在
 *   - database/install.sql（新装）
 *   - database/update.sql（升级，末尾独立区块）
 *   - database/sql/checkin_promotion_mysql57.sql（可审查等价版本，本命令执行的就是它）
 *
 * 用法：
 *   php artisan checkin:install --check   仅检查（只读）
 *   php artisan checkin:install --apply   幂等建表 + 写入默认奖励规则
 */
class CheckinInstall extends Command
{
    protected $signature = 'checkin:install {--check} {--apply} {--file=}';

    protected $description = '创建/校验每日签到与活动弹窗所需的四张表（幂等，不含任何历史数据导入）';

    protected $tables = [
        'v2_checkin_rewards',
        'v2_user_checkin_logs',
        'v2_promotion_popups',
        'v2_promotion_records'
    ];

    public function handle()
    {
        $file = $this->option('file') ?: database_path('sql/checkin_promotion_mysql57.sql');
        if (!is_file($file)) {
            $this->error("找不到建表脚本: {$file}");
            return 1;
        }

        $apply = (bool)$this->option('apply');
        $check = (bool)$this->option('check') || !$apply;

        $this->line('database: ' . DB::connection()->getDatabaseName());
        $this->line('脚本    : ' . $file);
        $this->line('模式    : ' . ($apply ? 'APPLY' : 'CHECK'));
        $this->line('');

        $existing = [];
        foreach ($this->tables as $table) {
            $existing[$table] = Schema::hasTable($table);
        }

        $this->line('--- 当前状态 ---');
        foreach ($existing as $table => $has) {
            $this->line('  ' . ($has ? 'OK  ' : 'MISS') . ' ' . $table);
        }

        if ($apply) {
            $sql = file_get_contents($file);
            $statements = $this->splitStatements($sql);
            $executed = 0;
            foreach ($statements as $statement) {
                if (trim($statement) === '') continue;
                try {
                    DB::statement($statement);
                    $executed++;
                } catch (\Throwable $e) {
                    $this->error('执行失败: ' . substr(trim($statement), 0, 120));
                    $this->error('  ' . $e->getMessage());
                    return 1;
                }
            }
            $this->line('');
            $this->line("已执行 {$executed} 条语句（建表幂等，默认规则 INSERT IGNORE）");
        }

        $this->line('');
        $this->line('--- 校验 ---');
        $ok = true;
        foreach ($this->tables as $table) {
            $has = Schema::hasTable($table);
            $this->line('  ' . ($has ? 'OK  ' : 'FAIL') . ' ' . $table);
            if (!$has) $ok = false;
        }

        if ($ok) {
            // 关键列与唯一索引校验（(user_id, checkin_date) 唯一是同日只奖励一次的基础）
            $columns = [
                'v2_user_checkin_logs' => ['user_id', 'checkin_date', 'continuous_days', 'day_index', 'reward_bytes', 'credited', 'source'],
                'v2_checkin_rewards' => ['day_index', 'reward_bytes', 'reward_text', 'enabled'],
                'v2_promotion_popups' => ['title', 'subtitle', 'coupon_code', 'coupon_name', 'discount_text', 'button_text', 'button_url', 'button_action', 'pages', 'cooldown_hours', 'show', 'starts_at', 'ends_at'],
                'v2_promotion_records' => ['promotion_id', 'user_id', 'action', 'channel', 'ip', 'created_at']
            ];
            foreach ($columns as $table => $expected) {
                $missing = [];
                foreach ($expected as $column) {
                    if (!Schema::hasColumn($table, $column)) $missing[] = $column;
                }
                if (!empty($missing)) {
                    $ok = false;
                    $this->line('  FAIL ' . $table . ' 缺少列: ' . implode(', ', $missing));
                }
            }

            $indexes = DB::select("SHOW INDEX FROM v2_user_checkin_logs WHERE Key_name = 'v2_user_checkin_logs_user_date_unique'");
            if (count($indexes) === 2) {
                $this->line('  OK   v2_user_checkin_logs 唯一索引 (user_id, checkin_date) 存在');
            } else {
                $ok = false;
                $this->line('  FAIL 缺少唯一索引 v2_user_checkin_logs_user_date_unique');
            }

            $idType = DB::selectOne("SHOW COLUMNS FROM v2_user WHERE Field = 'id'");
            $logType = DB::selectOne("SHOW COLUMNS FROM v2_user_checkin_logs WHERE Field = 'user_id'");
            if ($idType && $logType) {
                $normalize = function ($type) { return strtolower(preg_replace('/\s+|\(\d+\)/', '', $type)); };
                if ($normalize($idType->Type) === $normalize($logType->Type)) {
                    $this->line("  OK   v2_user.id 与 v2_user_checkin_logs.user_id 类型一致（{$idType->Type}）");
                } else {
                    $ok = false;
                    $this->line("  FAIL 类型不一致: v2_user.id={$idType->Type} / logs.user_id={$logType->Type}");
                }
            }

            $rules = DB::table('v2_checkin_rewards')->count();
            $this->line("  默认奖励规则行数: {$rules}");
        }

        $this->line('');
        if ($ok) {
            $this->info('结构检测通过。');
            return 0;
        }
        $this->error('结构检测失败。');
        return 1;
    }

    /** 去掉 `--` 注释后按分号拆分语句 */
    private function splitStatements($sql)
    {
        $lines = preg_split('/\r?\n/', $sql);
        $clean = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (strpos($trimmed, '--') === 0) continue;
            $clean[] = $line;
        }
        $sql = implode("\n", $clean);
        $parts = explode(';', $sql);
        $statements = [];
        foreach ($parts as $part) {
            if (trim($part) === '') continue;
            $statements[] = trim($part);
        }
        return $statements;
    }
}
