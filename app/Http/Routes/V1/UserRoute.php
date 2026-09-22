<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get ('/unbindTelegram', 'V1\User\UserController@unbindTelegram');
            $router->get ('/resetSecurity', 'V1\User\UserController@resetSecurity');
            $router->get ('/info', 'V1\User\UserController@info');
            $router->post('/newPeriod', 'V1\User\UserController@newPeriod');
            $router->post('/redeemgiftcard', 'V1\User\UserController@redeemgiftcard');
            $router->post('/changePassword', 'V1\User\UserController@changePassword');
            $router->post('/update', 'V1\User\UserController@update');
            $router->get ('/getSubscribe', 'V1\User\UserController@getSubscribe');
            $router->get ('/getStat', 'V1\User\UserController@getStat');
            $router->get ('/checkLogin', 'V1\User\UserController@checkLogin');
            $router->post('/transfer', 'V1\User\UserController@transfer');
            $router->post('/getQuickLoginUrl', 'V1\User\UserController@getQuickLoginUrl');
            $router->get ('/getActiveSession', 'V1\User\UserController@getActiveSession');
            $router->post('/removeActiveSession', 'V1\User\UserController@removeActiveSession');
            // Checkin
            $router->post('/checkin', 'V1\User\CheckinController@checkin');
            // EZ-Theme 兼容签到（ThemeCheckinService；与 v3board 自带 POST /checkin 互不影响）
            $router->get ('/checkin/status', 'V1\User\ThemeCheckinController@status');
            $router->post('/checkin/claim', 'V1\User\ThemeCheckinController@claim');
            // 活动弹窗（EZ-Theme 契约；claim 只写行为记录并返回优惠码）
            $router->get ('/promotion/popup', 'V1\User\PromotionController@popup');
            $router->post('/promotion/claim', 'V1\User\PromotionController@claim');
            $router->post('/promotion/record', 'V1\User\PromotionController@record');
            // Order
            $router->post('/order/save', 'V1\User\OrderController@save');
            $router->post('/order/checkout', 'V1\User\OrderController@checkout');
            $router->get ('/order/check', 'V1\User\OrderController@check');
            $router->get ('/order/detail', 'V1\User\OrderController@detail');
            $router->get ('/order/fetch', 'V1\User\OrderController@fetch');
            $router->get ('/order/getPaymentMethod', 'V1\User\OrderController@getPaymentMethod');
            $router->post('/order/cancel', 'V1\User\OrderController@cancel');
            // Plan
            $router->get ('/plan/fetch', 'V1\User\PlanController@fetch');
            // Invite
            $router->get ('/invite/save', 'V1\User\InviteController@save');
            $router->get ('/invite/fetch', 'V1\User\InviteController@fetch');
            $router->get ('/invite/details', 'V1\User\InviteController@details');
            // Notice
            $router->get ('/notice/fetch', 'V1\User\NoticeController@fetch');
            // Ticket
            $router->post('/ticket/reply', 'V1\User\TicketController@reply');
            $router->post('/ticket/close', 'V1\User\TicketController@close');
            $router->post('/ticket/save', 'V1\User\TicketController@save');
            $router->get ('/ticket/fetch', 'V1\User\TicketController@fetch');
            $router->post('/ticket/withdraw', 'V1\User\TicketController@withdraw');
            // Server
            $router->get ('/server/fetch', 'V1\User\ServerController@fetch');
            // Coupon
            $router->post('/coupon/check', 'V1\User\CouponController@check');
            $router->get ('/coupon/fetch', 'V1\User\CouponController@fetch');
            // Telegram
            $router->get ('/telegram/getBotInfo', 'V1\User\TelegramController@getBotInfo');
            // Comm
            $router->get ('/comm/config', 'V1\User\CommController@config');
            $router->Post('/comm/getStripePublicKey', 'V1\User\CommController@getStripePublicKey');
            // Knowledge
            $router->get ('/knowledge/fetch', 'V1\User\KnowledgeController@fetch');
            $router->get ('/knowledge/getCategory', 'V1\User\KnowledgeController@getCategory');
            // Stat
            $router->get ('/stat/getTrafficLog', 'V1\User\StatController@getTrafficLog');
            // Delete Account
            $router->post('/deleteAccount', 'V1\User\UserController@deleteAccount');

            // Sub-account 只读列表: 主账号看到自己的子账号；子账号自身调用返回 is_sub_account=1 且列表为空
            $router->get ('/sub-account/list', 'V1\User\SubAccountController@list');

            // ---------------------------------------------------------------
            // 以下动作对「启用中的子账号」一律禁止（下单/续费/变更套餐/充值/
            // 提现/邀请/佣金/佣金提现/创建子账号），由集中式 sub_account 中间件拦截。
            // 普通用户与管理员行为完全不变。
            // ---------------------------------------------------------------
            $router->group([
                'middleware' => 'sub_account'
            ], function ($router) {
                $router->post('/newPeriod', 'V1\User\UserController@newPeriod');
                $router->post('/redeemgiftcard', 'V1\User\UserController@redeemgiftcard');
                $router->post('/transfer', 'V1\User\UserController@transfer');
                // Order
                $router->post('/order/save', 'V1\User\OrderController@save');
                $router->post('/order/checkout', 'V1\User\OrderController@checkout');
                $router->get ('/order/check', 'V1\User\OrderController@check');
                $router->get ('/order/detail', 'V1\User\OrderController@detail');
                $router->get ('/order/fetch', 'V1\User\OrderController@fetch');
                $router->get ('/order/getPaymentMethod', 'V1\User\OrderController@getPaymentMethod');
                $router->post('/order/cancel', 'V1\User\OrderController@cancel');
                // Invite & commission
                $router->get ('/invite/save', 'V1\User\InviteController@save');
                $router->get ('/invite/fetch', 'V1\User\InviteController@fetch');
                $router->get ('/invite/details', 'V1\User\InviteController@details');
                // Withdraw
                $router->post('/ticket/withdraw', 'V1\User\TicketController@withdraw');

                // -----------------------------------------------------------
                // 子账号管理（V2Board V1 用户接口契约，主题兼容）
                // -----------------------------------------------------------
                $router->post('/sub-account/send-code', 'V1\User\SubAccountController@sendCode');
                $router->post('/sub-account/bind', 'V1\User\SubAccountController@bind');
                $router->post('/sub-account/update', 'V1\User\SubAccountController@update');
                $router->post('/sub-account/change-password', 'V1\User\SubAccountController@changePassword');
                $router->post('/sub-account/reset-traffic', 'V1\User\SubAccountController@resetTraffic');
                $router->get ('/sub-account/subscribe', 'V1\User\SubAccountController@subscribe');
                $router->post('/sub-account/reset-subscribe', 'V1\User\SubAccountController@resetSubscribe');
                $router->post('/sub-account/unbind', 'V1\User\SubAccountController@unbind');
            });
        });
    }
}
