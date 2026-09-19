<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SubAccountService;
use Closure;

/**
 * 集中式子账号权限守卫。
 *
 * 启用中的子账号只允许: 登录、查看个人流量、获取订阅、使用节点、修改个人登录信息与密码。
 * 下单/购买/续费/变更套餐/充值/提现/邀请/佣金/佣金提现/创建子账号 一律拒绝。
 *
 * 说明: 该中间件只挂在受限路由上（见 UserRoute），普通用户与管理员完全不受影响，
 * 不会对全局请求产生任何额外查询。
 */
class SubAccountGuard
{
    public function handle($request, Closure $next)
    {
        $userId = isset($request->user['id']) ? $request->user['id'] : null;
        if ($userId) {
            $service = new SubAccountService();
            if ($service->isEnabled()) {
                $user = User::select(['id', 'banned'])->find($userId);
                if ($user && $service->isSubAccount($user)) {
                    abort(403, __('Sub-accounts are not allowed to perform this action'));
                }
            }
        }
        return $next($request);
    }
}
