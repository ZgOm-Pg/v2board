<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Services\SubAccountService;

class SendRemindMail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:remindMail';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '发送提醒邮件';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        $users = User::all();
        $mailService = new MailService();
        // 跳过启用中的子账号: 子账号不独立发送套餐到期提醒，避免误用其 NULL 到期时间（需求书第十四节）
        $subAccountChildIds = (new SubAccountService())->enabledChildIdMap();
        foreach ($users as $user) {
            if (isset($subAccountChildIds[(int)$user->id])) continue;
            if ($user->remind_expire) $mailService->remindExpire($user);
            if (!($user->expired_at !== NULL && $user->expired_at < time()) && $user->remind_traffic) {
                $mailService->remindTraffic($user);
            }
        }
    }
}
