<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
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
            // 启用中的子账号: 不独立发送到期/流量提醒（其到期时间与额度来自主账号）
            if (isset($subAccountChildIds[(int)$user->id])) continue;
            // 如果用户开启了邮件通知
            if ($user->remind_expire) {
                $mailService->remindExpire($user);
            }
            // 如果用户关闭了邮件通知但绑定了Telegram
            elseif ($user->telegram_id) {
                $maxLoops = MailService::expireRemindTimes();
                $loops = 0;
                while ($mailService->shouldSendExpireRemind($user) && $loops < $maxLoops) {
                    $loops++;
                    $remainHours = max(1, (int)round(($user->expired_at - time()) / 3600));
                    $expireDate = date('Y-m-d H:i', $user->expired_at);
                    $message = "⏰ 您的服务即将到期（剩余约 {$remainHours} 小时），请及时续费。\n\n📅 到期时间：{$expireDate}";
                    $mailService->sendTelegramNotification($user, $message);
                    $mailService->incrementExpireSentCount($user);
                }
            }

            // 流量提醒（邮件）
            if (!($user->expired_at !== NULL && $user->expired_at < time()) && $user->remind_traffic) {
                $mailService->remindTraffic($user);
            } 
            // 如果用户关闭了邮件通知但绑定了Telegram
            elseif ($user->telegram_id) {
                // 检查流量是否即将耗尽
                if ($mailService->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) {
                    $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
                    if (!Cache::get($flag) && Cache::put($flag, 1, 24 * 3600)) {
                        $pct = MailService::trafficRemindPercent();
                        $message = "⚠️ 您的流量使用已达到{$pct}%，请及时充值。\n\n💡 当前已使用流量：{$mailService->formatTraffic($user->u + $user->d)}\n📊 总流量：{$mailService->formatTraffic($user->transfer_enable)}";
                        $mailService->sendTelegramNotification($user, $message);
                    }
                }
            }
        }
    }
}
