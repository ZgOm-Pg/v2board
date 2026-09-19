<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubAccountService;
use Illuminate\Http\Request;

/**
 * V2Board V1 用户侧子账号接口。
 *
 * 全部接口都要求: 请求者为主账号（非子账号），且对目标关系拥有归属权。
 */
class SubAccountController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new SubAccountService();
    }

    private function parent(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) abort(500, __('The user does not exist'));
        return $user;
    }

    /**
     * GET /api/v1/user/sub-account/list
     */
    public function list(Request $request)
    {
        $parent = $this->parent($request);
        if (!$this->service->isEnabled()) {
            return response(['data' => [
                'enabled' => false,
                'is_sub_account' => $this->service->isSubAccount($parent) ? 1 : 0,
                'max_count' => $this->service->getMaxCount(),
                'created_count' => 0,
                'items' => []
            ]]);
        }
        if ($this->service->isSubAccount($parent)) {
            // 子账号不能创建子账号，返回空列表而不是报错，保持主题兼容
            return response(['data' => [
                'enabled' => true,
                'is_sub_account' => 1,
                'max_count' => $this->service->getMaxCount(),
                'created_count' => 0,
                'items' => []
            ]]);
        }
        return response(['data' => $this->service->summarizeForParent($parent)]);
    }

    /**
     * POST /api/v1/user/sub-account/send-code
     */
    public function sendCode(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->sendBindCode($parent, $request->input('email'), $request->ip()));
    }

    /**
     * POST /api/v1/user/sub-account/bind
     *
     * EZ-Theme 提交: email / email_code / traffic_limit_gb / remark
     * 兼容旧字段:     code / traffic_limit(字节)
     */
    public function bind(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->bind($parent, $request->only([
            'email', 'email_code', 'code', 'remark', 'traffic_limit', 'traffic_limit_gb', 'password'
        ]), $request->ip()));
    }

    /**
     * POST /api/v1/user/sub-account/update
     *
     * EZ-Theme 提交: id / traffic_limit_gb / remark（备注保存时同时回传配额）
     */
    public function update(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->updateRelation($parent, $request->only([
            'id', 'child_user_id', 'traffic_limit', 'traffic_limit_gb', 'remark'
        ]), $request->ip()));
    }

    /**
     * POST /api/v1/user/sub-account/change-password
     *
     * EZ-Theme 同时提交 id / child_user_id / email / new_password / password。
     */
    public function changePassword(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->changeChildPassword($parent, $request->only([
            'id', 'child_user_id', 'new_password', 'password'
        ]), $request->ip()));
    }

    /**
     * POST /api/v1/user/sub-account/reset-traffic
     */
    public function resetTraffic(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->resetChildTraffic($parent, $request->only(['id']), $request->ip()));
    }

    /**
     * GET /api/v1/user/sub-account/subscribe?id=...
     *
     * EZ-Theme 传关系 id；同时兼容 child_user_id。
     */
    public function subscribe(Request $request)
    {
        $parent = $this->parent($request);
        $idOrChildId = $request->input('id');
        if ($idOrChildId === null || $idOrChildId === '') {
            $idOrChildId = $request->input('child_user_id');
        }
        return response($this->service->childSubscribe($parent, $idOrChildId ? (int)$idOrChildId : null));
    }

    /**
     * POST /api/v1/user/sub-account/reset-subscribe
     */
    public function resetSubscribe(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->resetChildSubscribe($parent, $request->only(['id']), $request->ip()));
    }

    /**
     * POST /api/v1/user/sub-account/unbind
     */
    public function unbind(Request $request)
    {
        $parent = $this->parent($request);
        return response($this->service->unbind($parent, $request->only(['id']), $request->ip()));
    }
}
