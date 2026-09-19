<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SubAccountService;
use Illuminate\Http\Request;

/**
 * 后台子账号管理接口。
 *
 * 复用目标 V2Board 现有的 secure_path + admin 中间件规范
 * （与 AdminRoute 其它接口一致），不引入任何 XBoard V2 风格的路由前缀。
 * 配置读写复用现有 /config/fetch?key=sub_account 与 /config/save。
 */
class SubAccountController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new SubAccountService();
    }

    private function actorId(Request $request)
    {
        return isset($request->user['id']) ? (int)$request->user['id'] : null;
    }

    /** GET {secure_path}/sub-account/fetch */
    public function fetch(Request $request)
    {
        return response($this->service->adminList([
            'current' => $request->input('current', 1),
            'page_size' => $request->input('page_size', 20),
            'status' => $request->input('status'),
            'parent_user_id' => $request->input('parent_user_id'),
            'child_user_id' => $request->input('child_user_id')
        ]));
    }

    /** GET {secure_path}/sub-account/detail */
    public function detail(Request $request)
    {
        return response($this->service->adminDetail($request->input('id')));
    }

    /** GET {secure_path}/sub-account/audit */
    public function audit(Request $request)
    {
        return response($this->service->adminAudit([
            'current' => $request->input('current', 1),
            'page_size' => $request->input('page_size', 20),
            'relation_id' => $request->input('relation_id'),
            'parent_user_id' => $request->input('parent_user_id'),
            'child_user_id' => $request->input('child_user_id'),
            'action' => $request->input('action')
        ]));
    }

    /** POST {secure_path}/sub-account/update */
    public function update(Request $request)
    {
        return response($this->service->adminUpdate(
            $request->input('id'),
            $request->only(['traffic_limit', 'remark', 'status']),
            $this->actorId($request),
            $request->ip()
        ));
    }

    /** POST {secure_path}/sub-account/unbind */
    public function unbind(Request $request)
    {
        return response($this->service->adminUnbind(
            $request->input('id'),
            $this->actorId($request),
            $request->ip()
        ));
    }

    /** POST {secure_path}/sub-account/reset-subscribe */
    public function resetSubscribe(Request $request)
    {
        return response($this->service->adminResetSubscribe(
            $request->input('id'),
            $this->actorId($request),
            $request->ip()
        ));
    }

    /** POST {secure_path}/sub-account/reset-traffic */
    public function resetTraffic(Request $request)
    {
        return response($this->service->adminResetTraffic(
            $request->input('id'),
            $this->actorId($request),
            $request->ip()
        ));
    }

    /** GET {secure_path}/sub-account/status */
    public function status(Request $request)
    {
        return response(['data' => [
            'enabled' => $this->service->isEnabled() ? 1 : 0,
            'max_count' => $this->service->getMaxCount(),
            'email_code_ttl' => $this->service->getEmailCodeTtl(),
            'email_code_interval' => $this->service->getEmailCodeInterval(),
            'stats' => $this->service->healthStats()
        ]]);
    }
}
