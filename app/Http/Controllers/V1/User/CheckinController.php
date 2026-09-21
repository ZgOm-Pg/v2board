<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CheckinService;
use Illuminate\Http\Request;

/**
 * 用户端签到接口（EZ-Theme 契约）。
 *
 *   GET  /api/v1/user/checkin/status
 *   POST /api/v1/user/checkin/claim
 */
class CheckinController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new CheckinService();
    }

    private function user(Request $request)
    {
        $user = User::find(isset($request->user['id']) ? $request->user['id'] : null);
        if (!$user) abort(500, __('The user does not exist'));
        return $user;
    }

    public function status(Request $request)
    {
        return response([
            'data' => $this->service->status($this->user($request))
        ]);
    }

    public function claim(Request $request)
    {
        $user = $this->user($request);
        $result = $this->service->claim($user, $request->ip());

        // 返回结构与 status 完全一致（主题领取后直接用这些字段刷新界面，不会再次请求 status）
        $data = $result['status'];
        $data['already_checked'] = (bool)$result['already'];
        $rewardBytes = (int)$result['reward_bytes'];
        $rewardText = $result['log'] && (string)$result['log']->reward_text !== ''
            ? (string)$result['log']->reward_text
            : $this->service->formatBytes($rewardBytes);
        $data['reward_bytes'] = $rewardBytes;
        $data['reward_text'] = $rewardText;
        $data['today_reward_text'] = $rewardText;
        $data['continuous_days'] = (int)$result['continuous_days'];

        return response([
            'data' => $data,
            'message' => $result['already'] ? __('Already checked in today') : __('Check-in successful')
        ]);
    }
}
