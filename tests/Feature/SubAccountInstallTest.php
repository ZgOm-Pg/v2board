<?php

namespace Tests\Feature;

use App\Console\Commands\SubAccountInstall;
use App\Services\SubAccountService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 覆盖点 23（安装/检测命令）、24（运行环境断言）、部分 25（审计表结构）。
 *
 * 事务策略: MySQL 的 DDL 会隐式提交事务，本类又要 drop/create 两张子账号表，
 * 与基类的 DatabaseTransactions 无法共存（回滚时会抛 "There is no active
 * transaction"）。因此本类显式关闭事务（$connectionsToTransact = []），
 * 改为在每个用例的 finally 中把表结构恢复成"已正确安装"状态。
 */
class SubAccountInstallTest extends SubAccountTestCase
{
    /**
     * 关闭 DatabaseTransactions 的事务包裹。
     *
     * Laravel 的 DatabaseTransactions::beginDatabaseTransaction() 只在
     * $connectionsToTransact 非空时才 beginTransaction()，tearDown 末尾也以
     * 同一条件判断是否 rollBack()，因此置空即可同时关闭 begin 与 rollback。
     *
     * @var array
     */
    protected $connectionsToTransact = [];

    public function createApplication()
    {
        // 安装类测试需要自己控制表是否存在，跳过基类的应用创建期自动安装
        self::$skipAutoInstallInApplication = true;
        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSubAccount();
        // 每个测试开始前保证结构存在（可能是上一个测试 drop 过）
        $this->ensureSubAccountTables();
    }

    protected function tearDown(): void
    {
        // 即使用例本身失败，也必须把结构恢复成"已安装完成"状态，避免污染其它测试类
        $this->restoreSubAccountTables();
        parent::tearDown();
    }

    /**
     * 重建两张子账号表（drop + install --apply），保证结构回到规范状态。
     */
    protected function restoreSubAccountTables(): void
    {
        $this->dropSubAccountTables();
        Artisan::call('sub-account:install --apply');
    }

    // ------------------------------------------------------------- 覆盖点 24

    public function testEnvironmentRunsPhp83AndMysql57()
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare(PHP_VERSION, '8.4.0', '>=')) {
            $this->markTestSkipped('当前 PHP 版本为 ' . PHP_VERSION . '，本测试套件针对 PHP 8.3.x 验证。');
        }
        $this->assertSame('8.3', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);

        $pdo = DB::connection()->getPdo();
        $this->assertNotNull($pdo, '无法建立数据库连接');

        $version = (string)DB::selectOne('SELECT VERSION() AS v')->v;
        if (strpos($version, '5.7.') === false) {
            $this->markTestSkipped('当前 MySQL/MariaDB 版本为 ' . $version . '，本测试套件针对 MySQL 5.7.x 验证。');
        }
        $this->assertStringContainsString('5.7.', $version);
    }

    // ------------------------------------------------------------- 覆盖点 23

    public function testApplyCreatesBothTablesWhenMissing()
    {
        $this->dropSubAccountTables();
        $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_RELATIONS));
        $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_AUDIT));

        try {
            $exit = Artisan::call('sub-account:install --apply');
            $output = Artisan::output();
            $this->assertSame(0, $exit, $output);
            $this->assertTrue($this->tableExists(SubAccountInstall::TABLE_RELATIONS));
            $this->assertTrue($this->tableExists(SubAccountInstall::TABLE_AUDIT));
        } finally {
            $this->ensureSubAccountTables();
        }
    }

    public function testCheckIsReadOnlyAndDoesNotCreateTables()
    {
        $this->dropSubAccountTables();
        $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_RELATIONS));
        $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_AUDIT));

        try {
            $exit = Artisan::call('sub-account:install --check');
            $output = Artisan::output();

            // 表缺失 => 明确失败，且绝不建表（只读）
            $this->assertSame(1, $exit, $output);
            $this->assertStringContainsString('missing_table', $output);
            $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_RELATIONS), '--check 不得创建表');
            $this->assertFalse($this->tableExists(SubAccountInstall::TABLE_AUDIT), '--check 不得创建表');
        } finally {
            $this->ensureSubAccountTables();
        }
    }

    public function testInstalledStructureMatchesExactColumnsIndexesAndForeignKeys()
    {
        $exit = Artisan::call('sub-account:install --check');
        // Artisan::output() 底层是 BufferedOutput::fetch()，读取一次后缓冲区即被清空，
        // 因此必须先取到局部变量再重复断言。
        $output = Artisan::output();
        $this->assertSame(0, $exit, '结构检测应通过: ' . $output);
        $this->assertStringContainsString('结构检测通过', $output);

        // ---- 关系表列 ----
        $relationsColumns = $this->columnsOf(SubAccountInstall::TABLE_RELATIONS);
        $expectedRelationColumns = [
            'id', 'parent_user_id', 'child_user_id', 'traffic_limit', 'remark',
            'status', 'created_by_parent', 'created_at', 'updated_at',
        ];
        sort($expectedRelationColumns);
        $actualRelationColumns = array_keys($relationsColumns);
        sort($actualRelationColumns);
        $this->assertSame($expectedRelationColumns, $actualRelationColumns);

        $this->assertSame('bigint(20) unsigned', strtolower($relationsColumns['id']->COLUMN_TYPE));
        $this->assertSame('bigint(20)', strtolower($relationsColumns['traffic_limit']->COLUMN_TYPE));
        $this->assertSame('varchar(255)', strtolower($relationsColumns['remark']->COLUMN_TYPE));
        $this->assertSame('tinyint(4)', strtolower($relationsColumns['status']->COLUMN_TYPE));
        $this->assertSame('tinyint(4)', strtolower($relationsColumns['created_by_parent']->COLUMN_TYPE));
        $this->assertSame('int(11)', strtolower($relationsColumns['created_at']->COLUMN_TYPE));
        $this->assertSame('NO', strtoupper($relationsColumns['parent_user_id']->IS_NULLABLE));
        $this->assertSame('NO', strtoupper($relationsColumns['child_user_id']->IS_NULLABLE));
        $this->assertSame('YES', strtoupper($relationsColumns['remark']->IS_NULLABLE));

        // ---- 关系表索引 ----
        $relationIndexes = $this->indexesOf(SubAccountInstall::TABLE_RELATIONS);
        $this->assertSame(['id'], $relationIndexes['PRIMARY']);
        $this->assertSame(['child_user_id'], $relationIndexes['v2_user_sub_accounts_child_user_id_unique']);
        $this->assertSame(['parent_user_id', 'status'], $relationIndexes['v2_user_sub_accounts_parent_user_id_status_index']);
        // 不再建立无收益的 (child_user_id, status) 重复索引：
        // child_user_id 上的唯一索引已能服务按子账号查询。
        $this->assertArrayNotHasKey('v2_user_sub_accounts_child_user_id_status_index', $relationIndexes);

        // ---- 关系表外键（CASCADE）----
        $relationFks = $this->foreignKeysOf(SubAccountInstall::TABLE_RELATIONS);
        $this->assertArrayHasKey('v2_user_sub_accounts_parent_user_id_foreign', $relationFks);
        $this->assertArrayHasKey('v2_user_sub_accounts_child_user_id_foreign', $relationFks);
        $this->assertSame('parent_user_id', $relationFks['v2_user_sub_accounts_parent_user_id_foreign']['column']);
        $this->assertSame('v2_user', $relationFks['v2_user_sub_accounts_parent_user_id_foreign']['ref_table']);
        $this->assertSame('id', $relationFks['v2_user_sub_accounts_parent_user_id_foreign']['ref_column']);
        $this->assertSame('CASCADE', strtoupper($relationFks['v2_user_sub_accounts_parent_user_id_foreign']['on_delete']));
        $this->assertSame('child_user_id', $relationFks['v2_user_sub_accounts_child_user_id_foreign']['column']);
        $this->assertSame('CASCADE', strtoupper($relationFks['v2_user_sub_accounts_child_user_id_foreign']['on_delete']));

        // ---- 审计表列 ----
        $auditColumns = $this->columnsOf(SubAccountInstall::TABLE_AUDIT);
        $expectedAuditColumns = [
            'id', 'relation_id', 'parent_user_id', 'child_user_id', 'actor_type',
            'actor_user_id', 'action', 'metadata', 'ip', 'created_at',
        ];
        sort($expectedAuditColumns);
        $actualAuditColumns = array_keys($auditColumns);
        sort($actualAuditColumns);
        $this->assertSame($expectedAuditColumns, $actualAuditColumns);

        $this->assertSame('bigint(20) unsigned', strtolower($auditColumns['id']->COLUMN_TYPE));
        $this->assertSame('bigint(20) unsigned', strtolower($auditColumns['relation_id']->COLUMN_TYPE));
        $this->assertSame('varchar(20)', strtolower($auditColumns['actor_type']->COLUMN_TYPE));
        $this->assertSame('varchar(50)', strtolower($auditColumns['action']->COLUMN_TYPE));
        $this->assertSame('json', strtolower($auditColumns['metadata']->COLUMN_TYPE));
        $this->assertSame('varchar(64)', strtolower($auditColumns['ip']->COLUMN_TYPE));
        $this->assertSame('int(11)', strtolower($auditColumns['created_at']->COLUMN_TYPE));
        $this->assertSame('NO', strtoupper($auditColumns['action']->IS_NULLABLE));
        $this->assertSame('YES', strtoupper($auditColumns['metadata']->IS_NULLABLE));
        $this->assertSame('YES', strtoupper($auditColumns['parent_user_id']->IS_NULLABLE));

        // ---- 审计表索引 ----
        $auditIndexes = $this->indexesOf(SubAccountInstall::TABLE_AUDIT);
        foreach ([
            'v2_sub_account_audit_logs_relation_id_index' => ['relation_id'],
            'v2_sub_account_audit_logs_parent_user_id_index' => ['parent_user_id'],
            'v2_sub_account_audit_logs_child_user_id_index' => ['child_user_id'],
            'v2_sub_account_audit_logs_actor_user_id_index' => ['actor_user_id'],
            'v2_sub_account_audit_logs_action_index' => ['action'],
            'v2_sub_account_audit_logs_created_at_index' => ['created_at'],
        ] as $name => $columns) {
            $this->assertArrayHasKey($name, $auditIndexes, "缺少索引 {$name}");
            $this->assertSame($columns, $auditIndexes[$name]);
        }
        $this->assertSame(['id'], $auditIndexes['PRIMARY']);

        // ---- 审计表刻意不建外键（删除用户不丢历史）----
        $this->assertSame([], $this->foreignKeysOf(SubAccountInstall::TABLE_AUDIT));

        // ---- 引擎必须是 InnoDB（外键与事务语义的前提）----
        $this->assertSame('InnoDB', $this->engineOf(SubAccountInstall::TABLE_RELATIONS));
        $this->assertSame('InnoDB', $this->engineOf(SubAccountInstall::TABLE_AUDIT));
    }

    public function testApplyTwiceIsNoOpSuccessAndKeepsStructure()
    {
        $first = Artisan::call('sub-account:install --apply');
        $this->assertSame(0, $first, Artisan::output());

        $before = $this->showCreateTable(SubAccountInstall::TABLE_RELATIONS);

        $second = Artisan::call('sub-account:install --apply');
        $secondOutput = Artisan::output();
        $this->assertSame(0, $second, $secondOutput);
        $this->assertStringContainsString('无需安装', $secondOutput);

        $after = $this->showCreateTable(SubAccountInstall::TABLE_RELATIONS);
        $this->assertSame($before, $after, '重复 --apply 不得改动已有结构');

        // 仍然处于结构匹配状态
        $this->assertSame(0, Artisan::call('sub-account:install --check'), Artisan::output());
    }

    public function testMismatchedStructureIsReportedByCheckAndApplyRefuses()
    {
        // 构造结构不匹配: 把 status 改成 varchar
        DB::statement('ALTER TABLE `v2_user_sub_accounts` MODIFY `status` VARCHAR(16) NOT NULL DEFAULT \'1\'');
        try {
            $checkExit = Artisan::call('sub-account:install --check');
            $checkOutput = Artisan::output();
            $this->assertSame(1, $checkExit, '结构不匹配时 --check 必须失败');
            $this->assertStringContainsString('column_type_mismatch', $checkOutput);

            $applyExit = Artisan::call('sub-account:install --apply');
            $applyOutput = Artisan::output();
            $this->assertSame(1, $applyExit, '结构不匹配时 --apply 必须拒绝执行');
            $this->assertStringContainsString('列', $applyOutput);

            // 拒绝执行 => 表仍在，且没有被静默修复
            $this->assertTrue($this->tableExists(SubAccountInstall::TABLE_RELATIONS));
            $this->assertSame('varchar(16)', strtolower($this->columnsOf(SubAccountInstall::TABLE_RELATIONS)['status']->COLUMN_TYPE));
        } finally {
            // 恢复正确结构
            DB::statement('ALTER TABLE `v2_user_sub_accounts` MODIFY `status` TINYINT(4) NOT NULL DEFAULT 1');
        }

        $this->assertSame(0, Artisan::call('sub-account:install --check'), Artisan::output());
    }

    public function testMissingRelationTableIsReportedByCheck()
    {
        DB::statement('DROP TABLE IF EXISTS `v2_user_sub_accounts`');
        try {
            $exit = Artisan::call('sub-account:install --check');
            $output = Artisan::output();
            $this->assertSame(1, $exit, $output);
            $this->assertStringContainsString('missing_table', $output);
            $this->assertStringContainsString(SubAccountInstall::TABLE_RELATIONS, $output);
        } finally {
            $this->ensureSubAccountTables();
        }
    }

    public function testStatusCommandIsReadOnlyOverview()
    {
        $exit = Artisan::call('sub-account:status');
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString(SubAccountInstall::TABLE_RELATIONS, $output);
        $this->assertStringContainsString(SubAccountInstall::TABLE_AUDIT, $output);
        $this->assertStringContainsString('active relations', $output);
    }

    /**
     * 覆盖点 1（配置开关本身）: 四个配置键都存在且默认值合理。
     */
    public function testServiceExposesConfigDefaults()
    {
        $service = new SubAccountService();
        $this->disableSubAccount();
        $this->assertSame(0, (int)config('v2board.sub_account_enable', 0));
        $this->assertFalse($service->isEnabled());

        $this->enableSubAccount(7, 120, 30);
        $this->assertTrue($service->isEnabled());
        $this->assertSame(7, $service->getMaxCount());
        $this->assertSame(120, $service->getEmailCodeTtl());
        $this->assertSame(30, $service->getEmailCodeInterval());

        // 非法值回落到安全默认
        config([
            'v2board.sub_account_max_count' => 0,
            'v2board.sub_account_email_code_ttl' => 0,
            'v2board.sub_account_email_code_interval' => -5,
        ]);
        $this->assertSame(1, $service->getMaxCount());
        $this->assertSame(300, $service->getEmailCodeTtl());
        $this->assertSame(60, $service->getEmailCodeInterval());
    }

    // ---------------------------------------------------------------- 辅助

    protected function ensureSubAccountTables(): void
    {
        Artisan::call('sub-account:install --apply');
        $this->assertTrue($this->tableExists(SubAccountInstall::TABLE_RELATIONS), 'sub-account:install --apply 未创建关系表');
        $this->assertTrue($this->tableExists(SubAccountInstall::TABLE_AUDIT), 'sub-account:install --apply 未创建审计表');
    }

    protected function dropSubAccountTables(): void
    {
        DB::statement('DROP TABLE IF EXISTS `v2_sub_account_audit_logs`');
        DB::statement('DROP TABLE IF EXISTS `v2_user_sub_accounts`');
    }

    protected function columnsOf(string $table): array
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row->COLUMN_NAME] = $row;
        }
        return $map;
    }

    protected function indexesOf(string $table): array
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );
        $map = [];
        foreach ($rows as $row) {
            if (!isset($map[$row->INDEX_NAME])) $map[$row->INDEX_NAME] = [];
            $map[$row->INDEX_NAME][] = $row->COLUMN_NAME;
        }
        return $map;
    }

    protected function foreignKeysOf(string $table): array
    {
        $rows = DB::select(
            "SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL",
            [$table]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row->CONSTRAINT_NAME] = [
                'column' => $row->COLUMN_NAME,
                'ref_table' => $row->REFERENCED_TABLE_NAME,
                'ref_column' => $row->REFERENCED_COLUMN_NAME,
                'on_delete' => $row->DELETE_RULE,
            ];
        }
        return $map;
    }

    protected function engineOf(string $table): string
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        return $row ? (string)$row->engine : '';
    }

    protected function showCreateTable(string $table): string
    {
        $row = DB::selectOne('SHOW CREATE TABLE `' . $table . '`');
        $data = (array)$row;
        return (string)array_pop($data);
    }
}
