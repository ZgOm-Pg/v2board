<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Http\Request;

/**
 * 用户端活动弹窗接口（EZ-Theme 契约）。
 *
 *   GET  /api/v1/user/promotion/popup   ?page=&channel=
 *   POST /api/v1/user/promotion/claim   {id}
 *   POST /api/v1/user/promotion/record  {id, action, channel}
 *
 * 说明：claim 只写行为记录并返回优惠码，不真正发券。
 */
class PromotionController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new PromotionService();
    }

    private function user(Request $request)
    {
        $user = User::find(isset($request->user['id']) ? $request->user['id'] : null);
        if (!$user) abort(500, __('The user does not exist'));
        return $user;
    }

    public function popup(Request $request)
    {
        $user = $this->user($request);
        $page = $request->input('page');
        $channel = $request->input('channel');
        return response([
            'data' => $this->service->popup($user, $page, $channel)
        ]);
    }

    public function claim(Request $request)
    {
        $user = $this->user($request);
        $id = $request->input('id');
        if (!$id) abort(500, __('The promotion does not exist'));
        return response([
            'data' => $this->service->claim($user, $id, $request->input('channel'), $request->ip()),
            'message' => __('Claimed successfully')
        ]);
    }

    public function record(Request $request)
    {
        $user = $this->user($request);
        $id = $request->input('id');
        if (!$id) $id = $request->input('promotion_id');
        $action = $request->input('action');
        if (!$id) abort(500, __('The promotion does not exist'));
        if (!$action) abort(500, __('Invalid promotion action'));

        $this->service->record($user, $id, $action, $request->input('channel'), [
            'from' => $request->input('from')
        ], $request->ip());

        return response(['data' => true]);
    }
}
