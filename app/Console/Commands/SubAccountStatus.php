<?php

namespace App\Console\Commands;

use App\Services\SubAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 子账号功能只读总览。
 * 不修改任何数据，可用于部署后验收与日常巡检。
 */
class SubAccountStatus extends Command
{
    protected $signature = 'sub-account:status';
    protected $description = '子账号功能只读总览: 配置、表结构、关系健康度';

    public function handle()
    {
        $service = new SubAccountService();
        $database = DB::connection()->getDatabaseName();

        $this->line('=== 子账号功能状态 ===');
        $this->line('database            : ' . $database);
        $this->line('sub_account_enable  : ' . (int)config('v2board.sub_account_enable', 0));
        $this->line('sub_account_max_count: ' . $service->getMaxCount());
        $this->line('email_code_ttl      : ' . $service->getEmailCodeTtl());
        $this->line('email_code_interval : ' . $service->getEmailCodeInterval());

        $tables = ['v2_user_sub_accounts', 'v2_sub_account_audit_logs'];
        $this->line('');
        $this->line('=== 表 ===');
        $allPresent = true;
        foreach ($tables as $table) {
            $row = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table]
            );
            if ($row && (int)$row->c > 0) {
                $count = DB::selectOne("SELECT COUNT(*) AS c FROM `{$table}`")->c;
                $this->line(sprintf('  %-28s 存在  行数=%s', $table, $count));
            } else {
                $allPresent = false;
                $this->line(sprintf('  %-28s 缺失', $table));
            }
        }

        $idRow = DB::selectOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'v2_user' AND COLUMN_NAME = 'id'",
            [$database]
        );
        $this->line('');
        $this->line('v2_user.id 类型     : ' . ($idRow ? $idRow->t : '未检测到'));

        if ($allPresent) {
            $stats = $service->healthStats();
            $this->line('');
            $this->line('=== 关系健康度 ===');
            $this->line('active relations   : ' . $stats['active_relations']);
            $this->line('orphan relations   : ' . $stats['orphan_relations']);
            $this->line('duplicate child    : ' . $stats['duplicate_child']);
            $this->line('cycles             : ' . $stats['cycles']);
        } else {
            $this->warn('表未安装完毕，请先执行: php artisan sub-account:install --check');
            return 1;
        }

        $this->line('');
        $this->info('提示: 结构检测请执行 php artisan sub-account:install --check');
        return 0;
    }
}
