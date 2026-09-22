<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CheckinReward;
use App\Models\User;
use App\Models\UserCheckinLog;
use App\Services\ThemeCheckinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 后台「签到管理」接口。
 *
 * 开关与时区走现有配置机制（config/v2board.php + ConfigController/ConfigSave），
 * 这里负责奖励规则维护、日志查询与概览。
 */
class CheckinController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new ThemeCheckinService();
    }

    /** GET {secure_path}/checkin/config */
    public function config(Request $request)
    {
        return response(['data' => [
            'enabled' => $this->service->isEnabled() ? 1 : 0,
            'timezone' => $this->service->timezone(),
            'cycle_days' => ThemeCheckinService::CYCLE_DAYS
        ]]);
    }

    /** GET {secure_path}/checkin/reward/fetch */
    public function rewardFetch(Request $request)
    {
        $rules = CheckinReward::orderBy('day_index', 'asc')->get();
        $items = [];
        foreach ($rules as $rule) {
            $items[] = [
                'id' => (int)$rule->id,
                'day_index' => (int)$rule->day_index,
                'reward_bytes' => (int)$rule->reward_bytes,
                'reward_mb' => round((int)$rule->reward_bytes / 1048576, 2),
                'reward_text' => (string)$rule->reward_text,
                'enabled' => (int)$rule->enabled,
                'sort' => (int)$rule->sort
            ];
        }
        return response(['data' => ['items' => $items]]);
    }

    /** POST {secure_path}/checkin/reward/save */
    public function rewardSave(Request $request)
    {
        $id = $request->input('id');
        $dayIndex = (int)$request->input('day_index');
        if ($dayIndex < 1 || $dayIndex > ThemeCheckinService::CYCLE_DAYS) {
            abort(500, __('Day index must be between 1 and 30'));
        }

        // 流量额度：接受字节或 MB（后台表单用 MB 更直观）
        if ($request->has('reward_mb') && $request->input('reward_mb') !== null && $request->input('reward_mb') !== '') {
            if (!is_numeric($request->input('reward_mb'))) abort(500, __('Invalid reward'));
            $bytes = (int)round((float)$request->input('reward_mb') * 1048576);
        } else {
            $bytes = (int)$request->input('reward_bytes', 0);
        }
        if ($bytes < 0) abort(500, __('Invalid reward'));

        $existing = CheckinReward::where('day_index', $dayIndex)->first();
        if ($id && $existing && (int)$existing->id !== (int)$id) {
            abort(500, __('This day already has a rule'));
        }
        if ($id && $existing && (int)$existing->id === (int)$id) {
            // 同一档位自身更新
        } elseif (!$id && $existing) {
            abort(500, __('This day already has a rule'));
        }

        $rule = $id ? CheckinReward::find($id) : new CheckinReward();
        if (!$rule) abort(500, __('The rule does not exist'));
        $rule->day_index = $dayIndex;
        $rule->reward_bytes = $bytes;
        $rule->reward_text = trim((string)$request->input('reward_text', '')) !== ''
            ? mb_substr(trim((string)$request->input('reward_text')), 0, 32)
            : $this->service->formatBytes($bytes);
        $rule->enabled = (int)$request->input('enabled', 1) === 1 ? 1 : 0;
        $rule->sort = (int)$request->input('sort', $dayIndex);
        $rule->updated_at = time();
        if (!$rule->exists) $rule->created_at = time();
        if (!$rule->save()) abort(500, __('Save failed'));

        return response(['data' => ['id' => (int)$rule->id]]);
    }

    /** POST {secure_path}/checkin/reward/toggle */
    public function rewardToggle(Request $request)
    {
        $rule = CheckinReward::find($request->input('id'));
        if (!$rule) abort(500, __('The rule does not exist'));
        $rule->enabled = (int)$request->input('enabled', 1) === 1 ? 1 : 0;
        $rule->updated_at = time();
        if (!$rule->save()) abort(500, __('Save failed'));
        return response(['data' => true]);
    }

    /**
     * POST {secure_path}/checkin/reward/drop
     * 已被签到日志引用的规则只停用，不删除（保留历史可解释性）。
     */
    public function rewardDrop(Request $request)
    {
        $rule = CheckinReward::find($request->input('id'));
        if (!$rule) abort(500, __('The rule does not exist'));

        $referenced = UserCheckinLog::where('reward_rule_id', $rule->id)->exists();
        if ($referenced) {
            $rule->enabled = 0;
            $rule->updated_at = time();
            $rule->save();
            return response(['data' => true, 'message' => __('Rule is referenced by logs; disabled instead of deleted')]);
        }

        $rule->delete();
        return response(['data' => true]);
    }

    /** GET {secure_path}/checkin/log/fetch */
    public function logFetch(Request $request)
    {
        $query = UserCheckinLog::query();

        if ($request->input('user_id')) {
            $query->where('user_id', (int)$request->input('user_id'));
        }
        if ($request->input('email')) {
            $ids = User::where('email', 'like', '%' . $request->input('email') . '%')
                ->limit(200)->pluck('id')->map(function ($v) { return (int)$v; })->toArray();
            $query->whereIn('user_id', empty($ids) ? [0] : $ids);
        }
        if ($request->input('date_from')) $query->where('checkin_date', '>=', $request->input('date_from'));
        if ($request->input('date_to')) $query->where('checkin_date', '<=', $request->input('date_to'));
        if ($request->input('source')) $query->where('source', $request->input('source'));

        $page = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('page_size', 20)));

        $total = (clone $query)->count();
        $logs = $query->orderBy('id', 'desc')->skip(($page - 1) * $size)->take($size)->get();

        $emails = User::whereIn('id', $logs->pluck('user_id')->unique()->toArray())
            ->pluck('email', 'id');

        $items = [];
        foreach ($logs as $log) {
            $items[] = [
                'id' => (int)$log->id,
                'user_id' => (int)$log->user_id,
                'email' => isset($emails[$log->user_id]) ? $emails[$log->user_id] : null,
                'checkin_date' => (string)$log->checkin_date,
                'continuous_days' => (int)$log->continuous_days,
                'day_index' => (int)$log->day_index,
                'reward_bytes' => (int)$log->reward_bytes,
                'reward_text' => (string)$log->reward_text,
                'credited' => (int)$log->credited,
                'source' => (string)$log->source,
                'created_at' => $log->created_at ? (int)$log->created_at : null,
                'created_at_text' => $log->created_at ? date('Y-m-d H:i:s', (int)$log->created_at) : null
            ];
        }

        return response(['data' => ['total' => $total, 'items' => $items]]);
    }

    /** GET {secure_path}/checkin/status */
    public function status(Request $request)
    {
        $today = $this->service->today();
        $monthStart = substr($today, 0, 7) . '-01';

        return response(['data' => [
            'enabled' => $this->service->isEnabled() ? 1 : 0,
            'timezone' => $this->service->timezone(),
            'cycle_days' => ThemeCheckinService::CYCLE_DAYS,
            'rewards_configured' => (int)CheckinReward::count(),
            'rewards_enabled' => (int)CheckinReward::where('enabled', 1)->count(),
            'logs_total' => (int)UserCheckinLog::count(),
            'logs_today' => (int)UserCheckinLog::where('checkin_date', $today)->count(),
            'logs_month' => (int)UserCheckinLog::where('checkin_date', '>=', $monthStart)->where('checkin_date', '<=', $today)->count(),
            'reward_bytes_today' => (int)UserCheckinLog::where('checkin_date', $today)->sum('reward_bytes'),
            'migrated_logs' => (int)UserCheckinLog::where('source', UserCheckinLog::SOURCE_MIGRATION)->count(),
            'server_date' => $today
        ]]);
    }
}
