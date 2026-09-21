<?php

namespace App\Services;

use App\Models\CheckinReward;
use App\Models\User;
use App\Models\UserCheckinLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 每日签到服务。
 *
 * 规则（与原站 XBoard 行为一致）：
 *   - 按配置时区（默认 Asia/Shanghai）计算自然日；
 *   - 漏签则连续天数从 1 重算；
 *   - 连续天数按 30 天循环取档位：day_index = ((continuous_days - 1) % 30) + 1；
 *   - 奖励为流量，直接累加到 v2_user.transfer_enable；
 *   - 同一用户同一天只能领取一次：(user_id, checkin_date) 唯一索引 + 事务行锁；
 *   - 启用中的子账号不得独立领取签到流量。
 */
class CheckinService
{
    /** 连续签到循环长度 */
    const CYCLE_DAYS = 30;

    /** 默认时区 */
    const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    // ------------------------------------------------------------------ 配置

    public function isEnabled()
    {
        return (int)config('v2board.checkin_enable', 0) === 1;
    }

    public function timezone()
    {
        $tz = (string)config('v2board.checkin_timezone', self::DEFAULT_TIMEZONE);
        $tz = trim($tz) !== '' ? trim($tz) : self::DEFAULT_TIMEZONE;
        try {
            new \DateTimeZone($tz);
        } catch (\Exception $e) {
            return self::DEFAULT_TIMEZONE;
        }
        return $tz;
    }

    public function now()
    {
        return Carbon::now($this->timezone());
    }

    /** 当前自然日（Y-m-d） */
    public function today()
    {
        return $this->now()->toDateString();
    }

    public function yesterday()
    {
        return $this->now()->subDay()->toDateString();
    }

    // ------------------------------------------------------------------ 规则

    /** 连续天数 -> 30 天循环档位 */
    public function dayIndexFor($continuousDays)
    {
        $continuousDays = (int)$continuousDays;
        if ($continuousDays < 1) $continuousDays = 1;
        return (($continuousDays - 1) % self::CYCLE_DAYS) + 1;
    }

    public function ruleAt($dayIndex)
    {
        $rule = CheckinReward::where('day_index', (int)$dayIndex)->first();
        if (!$rule || (int)$rule->enabled !== 1) return null;
        return $rule;
    }

    /**
     * 前端展示用的奖励档位（EZ-Theme：`rewards[]` 元素为 {days,title,reward_text,claimed}）。
     *
     * 只返回启用中的规则；`claimed` 为装饰性字段（前端会用 continuous_days >= days 再算一次）。
     */
    public function rewards($continuousDays = 0)
    {
        $rules = CheckinReward::where('enabled', 1)->orderBy('day_index', 'asc')->get();
        $cycleStart = $this->cycleStartFor($continuousDays);
        $list = [];
        foreach ($rules as $rule) {
            $days = (int)$rule->day_index;
            if ($days <= 0) continue;
            $bytes = (int)$rule->reward_bytes;
            $list[] = [
                'days' => $days,
                'title' => '连续 ' . $days . ' 天',
                'reward_text' => (string)$rule->reward_text !== '' ? (string)$rule->reward_text : $this->formatBytes($bytes),
                'claimed' => $continuousDays >= ($cycleStart + $days)
            ];
        }
        return $list;
    }

    /** 当前 30 天循环的起点（第 1 个循环为 0，第 2 个循环为 30…） */
    public function cycleStartFor($continuousDays)
    {
        $continuousDays = (int)$continuousDays;
        if ($continuousDays < 1) return 0;
        return (int)(floor(($continuousDays - 1) / self::CYCLE_DAYS) * self::CYCLE_DAYS);
    }

    /**
     * 下一个里程碑（绝对连续天数，可能大于 30 —— 进入下一循环时）。
     * 返回 {days, reward_text}；没有可用规则时返回 null。
     */
    public function nextReward($continuousDays)
    {
        $rules = CheckinReward::where('enabled', 1)->orderBy('day_index', 'asc')->get();
        if ($rules->isEmpty()) return null;

        $continuousDays = (int)$continuousDays;
        $cycleStart = $this->cycleStartFor($continuousDays);
        // 本轮内还没到过的档位
        foreach ($rules as $rule) {
            $days = $cycleStart + (int)$rule->day_index;
            if ($days > $continuousDays) {
                return [
                    'days' => $days,
                    'reward_text' => (string)$rule->reward_text !== ''
                        ? (string)$rule->reward_text
                        : $this->formatBytes((int)$rule->reward_bytes)
                ];
            }
        }
        // 本轮里程碑已全部达成 -> 下一循环的第一个档位
        $first = $rules->first();
        $days = $cycleStart + self::CYCLE_DAYS + (int)$first->day_index;
        return [
            'days' => $days,
            'reward_text' => (string)$first->reward_text !== ''
                ? (string)$first->reward_text
                : $this->formatBytes((int)$first->reward_bytes)
        ];
    }

    // ------------------------------------------------------------------ 状态

    public function lastLog($userId)
    {
        return UserCheckinLog::where('user_id', $userId)
            ->orderBy('checkin_date', 'desc')
            ->first();
    }

    /** 本月已签到天数（按签到自然日） */
    public function monthDays($userId, $today = null)
    {
        $today = $today ?: $this->today();
        $month = substr($today, 0, 7);
        return (int)UserCheckinLog::where('user_id', $userId)
            ->where('checkin_date', '>=', $month . '-01')
            ->where('checkin_date', '<=', $today)
            ->count();
    }

    /** 当前连续天数（已签到=含今天；未签到但昨天签过=延续；否则 0） */
    public function continuousDays($userId, $today = null)
    {
        $today = $today ?: $this->today();
        $last = $this->lastLog($userId);
        if (!$last) return 0;
        if ($last->checkin_date === $today) return (int)$last->continuous_days;
        if ($last->checkin_date === $this->yesterday()) return (int)$last->continuous_days;
        return 0;
    }

    /**
     * 签到状态（EZ-Theme CheckinPage.vue 契约，字段名逐一对齐）。
     *
     * 注意：
     *   - enabled / checked_in 必须是**严格布尔**；
     *   - rewards[] 元素必须是 {days,title,reward_text,claimed}；
     *   - next_reward 必须是对象 {days,reward_text}（裸数字会让界面出现 undefined 天 / NaN）；
     *   - month_days[] 元素的 date 必须是 YYYY-MM-DD **字符串**；
     *   - reward_bytes 只用于「是否显示今日奖励文案」的判断。
     */
    public function status(User $user)
    {
        $today = $this->today();
        $last = $this->lastLog($user->id);
        $checkedIn = $last && (string)$last->checkin_date === $today;
        $continuous = $this->continuousDays($user->id, $today);

        // 已签到则下一档位看 continuous+1，未签到看 continuous+1（今天的档位）
        $nextFor = $continuous + 1;
        $nextReward = $this->nextReward($nextFor);

        $todayBytes = $checkedIn ? (int)$last->reward_bytes : 0;
        $todayText = $checkedIn
            ? ((string)$last->reward_text !== '' ? (string)$last->reward_text : $this->formatBytes($todayBytes))
            : '';

        return [
            'enabled' => $this->isEnabled(),
            'checked_in' => (bool)$checkedIn,
            'continuous_days' => (int)$continuous,
            'rewards' => $this->rewards($continuous),
            'next_reward' => $nextReward,
            'month_days' => $this->monthDaysPayload($user->id, $today),
            'reward_bytes' => $todayBytes,
            'reward_text' => $todayText,
            'today_reward_text' => $todayText,
            'already_checked' => (bool)$checkedIn
        ];
    }

    /**
     * 本月日历数据：{date:'YYYY-MM-DD', status:'checked'|'missed', checked:bool}
     * （只到「今天」为止；未来日期由前端自己渲染）
     */
    public function monthDaysPayload($userId, $today = null)
    {
        $today = $today ?: $this->today();
        $monthStart = substr($today, 0, 7) . '-01';
        $checked = UserCheckinLog::where('user_id', $userId)
            ->where('checkin_date', '>=', $monthStart)
            ->where('checkin_date', '<=', $today)
            ->pluck('checkin_date')
            ->map(function ($d) { return (string)$d; })
            ->toArray();
        $checkedSet = array_flip($checked);

        $list = [];
        $cursor = $monthStart;
        $guard = 0;
        while ($cursor <= $today && $guard < 40) {
            $isChecked = isset($checkedSet[$cursor]);
            $list[] = [
                'date' => $cursor,
                'status' => $isChecked ? 'checked' : 'missed',
                'checked' => (bool)$isChecked
            ];
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
            $guard++;
        }
        return $list;
    }

    // ------------------------------------------------------------------ 领取

    /**
     * 启用中的子账号不得独立领取签到流量。
     */
    public function assertNotSubAccount(User $user)
    {
        if (!Schema::hasTable('v2_user_sub_accounts')) return true;
        $service = new SubAccountService();
        if ($service->isSubAccount($user)) {
            abort(403, __('Sub-accounts cannot claim check-in rewards independently'));
        }
        return true;
    }

    /**
     * 领取今日签到奖励。
     *
     * @return array{already:bool, log:UserCheckinLog, reward_bytes:int, continuous_days:int, status:array}
     */
    public function claim(User $user, $ip = null)
    {
        if (!$this->isEnabled()) {
            abort(500, __('Check-in is not enabled'));
        }
        $this->assertNotSubAccount($user);

        $today = $this->today();
        $log = null;
        $already = false;
        $rewardBytes = 0;
        $continuous = 0;

        DB::beginTransaction();
        try {
            // 行锁：同一用户串行化，配合 (user_id, checkin_date) 唯一索引双重保险
            $lockedUser = User::lockForUpdate()->find($user->id);
            if (!$lockedUser) throw new \Exception(__('The user does not exist'));
            // 锁内再次校验（子账号状态可能在请求之间变化）
            $this->assertNotSubAccount($lockedUser);

            $existing = UserCheckinLog::where('user_id', $lockedUser->id)
                ->where('checkin_date', $today)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $log = $existing;
                $already = true;
                $rewardBytes = (int)$existing->reward_bytes;
                $continuous = (int)$existing->continuous_days;
            } else {
                $last = UserCheckinLog::where('user_id', $lockedUser->id)
                    ->orderBy('checkin_date', 'desc')
                    ->lockForUpdate()
                    ->first();
                $continuous = ($last && (string)$last->checkin_date === $this->yesterday())
                    ? (int)$last->continuous_days + 1
                    : 1;
                $dayIndex = $this->dayIndexFor($continuous);
                $rule = $this->ruleAt($dayIndex);
                $rewardBytes = $rule ? (int)$rule->reward_bytes : 0;
                $rewardText = $rule && (string)$rule->reward_text !== ''
                    ? (string)$rule->reward_text
                    : $this->formatBytes($rewardBytes);

                $log = new UserCheckinLog();
                $log->user_id = $lockedUser->id;
                $log->checkin_date = $today;
                $log->continuous_days = $continuous;
                $log->day_index = $dayIndex;
                $log->reward_rule_id = $rule ? (int)$rule->id : null;
                $log->reward_bytes = $rewardBytes;
                $log->reward_text = $rewardText;
                $log->credited = 1;
                $log->source = UserCheckinLog::SOURCE_LOCAL;
                $log->created_at = time();
                if (!$log->save()) throw new \Exception(__('Save failed'));

                if ($rewardBytes > 0) {
                    // 奖励直接累加到用户可用流量（历史导入时不走这里，见迁移脚本）
                    $lockedUser->increment('transfer_enable', $rewardBytes);
                }
            }
            DB::commit();
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();
            // 并发下唯一索引冲突：视为"今天已经签过"
            if ($this->isDuplicateKey($e)) {
                $log = UserCheckinLog::where('user_id', $user->id)->where('checkin_date', $today)->first();
                return [
                    'already' => true,
                    'log' => $log,
                    'reward_bytes' => $log ? (int)$log->reward_bytes : 0,
                    'continuous_days' => $log ? (int)$log->continuous_days : 0,
                    'status' => $this->status(User::find($user->id))
                ];
            }
            abort(500, $e->getMessage());
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            abort(500, $e->getMessage());
        }

        return [
            'already' => $already,
            'log' => $log,
            'reward_bytes' => $rewardBytes,
            'continuous_days' => $continuous,
            'status' => $this->status(User::find($user->id))
        ];
    }

    private function isDuplicateKey(\Illuminate\Database\QueryException $e)
    {
        $code = $e->errorInfo[1] ?? null;
        return (int)$code === 1062 || strpos($e->getMessage(), 'Duplicate entry') !== false;
    }

    // ------------------------------------------------------------------ 工具

    /**
     * 字节 -> 展示文案（与原站一致：>=1GB 用 GB，>=1MB 用 MB，最多两位小数）。
     */
    public function formatBytes($bytes)
    {
        $bytes = (int)$bytes;
        if ($bytes <= 0) return '0B';
        $gb = 1024 * 1024 * 1024;
        $mb = 1024 * 1024;
        $kb = 1024;
        if ($bytes >= $gb) {
            return rtrim(rtrim(number_format($bytes / $gb, 2, '.', ''), '0'), '.') . 'GB';
        }
        if ($bytes >= $mb) {
            return rtrim(rtrim(number_format($bytes / $mb, 2, '.', ''), '0'), '.') . 'MB';
        }
        if ($bytes >= $kb) {
            return rtrim(rtrim(number_format($bytes / $kb, 2, '.', ''), '0'), '.') . 'KB';
        }
        return $bytes . 'B';
    }
}
