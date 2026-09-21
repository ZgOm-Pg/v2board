<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\PromotionController as AdminPromotionController;
use App\Models\PromotionPopup;
use App\Models\PromotionRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 活动弹窗功能测试。
 *
 * 覆盖：EZ-Theme 14 字段契约、启停/起止时间/页面范围/用户范围/排序/冷却、
 * claim 只写行为记录并返回优惠码（不真正发券）、record 埋点、管理端校验与增删改查。
 */
class PromotionTest extends CheckinPromotionTestCase
{
    private function assertHttpFailure(callable $fn, int $status, string $messageKey = null)
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), '状态码不符：' . $e->getMessage());
            if ($messageKey !== null) {
                $this->assertSame(__($messageKey), $e->getMessage());
            }
            return;
        }
        $this->fail('预期抛出 HttpException(' . $status . ')，但没有抛出');
    }

    private function adminRequest(array $input = []): Request
    {
        $request = Request::create('/testsecure/promotion/x', 'POST', $input, [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'phpunit',
        ]);
        $request->merge(['user' => $input['__actor'] ?? []]);
        return $request;
    }

    // ============================================================ 契约

    public function testPopupPayloadHasExactlyTheThemeContractFields()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup(['ends_at' => time() + 3600, 'cooldown_hours' => 24]);

        $data = $this->promotion()->popup($user, 'dashboard');

        $expected = [
            'enabled', 'id', 'title', 'subtitle', 'coupon_code', 'coupon_name', 'discount_text',
            'button_text', 'button_url', 'button_action', 'show_countdown', 'ends_at',
            'server_time', 'cooldown_hours'
        ];
        $this->assertSame($expected, array_keys($data), '字段集合必须与主题契约一致');
        $this->assertTrue($data['enabled']);
        $this->assertSame((int)$popup->id, $data['id']);
        $this->assertIsInt($data['server_time']);
        $this->assertGreaterThan(time() - 60, $data['server_time']);
    }

    public function testNoPopupReturnsEnabledFalse()
    {
        $user = $this->makeUser();
        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertSame(['enabled' => false], $data, '无活动时必须只有 enabled=false');
    }

    public function testEndsAtDrivesShowCountdown()
    {
        $user = $this->makeUser();
        $ends = time() + 7200;
        $this->makePopup(['ends_at' => $ends]);

        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertTrue($data['show_countdown'], 'ends_at 有值必须 show_countdown=true');
        $this->assertSame($ends, $data['ends_at'], 'ends_at 必须是 UNIX 秒（主题 ×1000）');
        $this->assertIsInt($data['ends_at']);
    }

    public function testPopupWithoutEndsAtHasNoCountdown()
    {
        $user = $this->makeUser();
        $this->makePopup(['ends_at' => null]);
        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertFalse($data['show_countdown']);
        $this->assertNull($data['ends_at']);
    }

    public function testButtonUrlDefaultsToShopWhenEmpty()
    {
        $user = $this->makeUser();
        $this->makePopup(['button_url' => null]);
        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertSame('/shop', $data['button_url'], '主题缺省值也是 /shop');
    }

    // ============================================================ 展示条件

    public function testDisabledPopupIsNotServed()
    {
        $user = $this->makeUser();
        $this->makePopup(['show' => 0]);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($user, 'dashboard'));
    }

    public function testStartAndEndWindowFiltersPopup()
    {
        $user = $this->makeUser();

        $future = $this->makePopup(['starts_at' => time() + 3600, 'title' => 'future']);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($user, 'dashboard'));

        $future->starts_at = time() - 60;
        $future->ends_at = time() - 30;
        $future->save();
        $this->assertSame(['enabled' => false], $this->promotion()->popup($user, 'dashboard'), '已结束的活动不得下发');

        $future->ends_at = time() + 60;
        $future->save();
        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertSame('future', $data['title'], '在生效窗口内应下发');
    }

    public function testPageScopeFiltersPopup()
    {
        $user = $this->makeUser();
        $this->makePopup(['pages' => 'shop,checkin']);

        $this->assertSame(['enabled' => false], $this->promotion()->popup($user, 'dashboard'), '页面不匹配不得下发');
        $this->assertSame('限时活动', $this->promotion()->popup($user, 'shop')['title']);
        $this->assertSame('限时活动', $this->promotion()->popup($user, 'checkin')['title']);

        // all 表示任意页面
        PromotionPopup::query()->update(['pages' => 'all']);
        $this->assertSame('限时活动', $this->promotion()->popup($user, 'dashboard')['title']);

        // 不传 page 时只有 all 生效
        PromotionPopup::query()->update(['pages' => 'shop']);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($user, null));
    }

    public function testUserScopeFiltersPopup()
    {
        $popup = $this->makePopup(['user_scope' => 'paid']);

        $unpaid = $this->makeUser(['plan_id' => null]);
        $paid = $this->makeUser(['plan_id' => 1, 'expired_at' => time() + 86400]);
        $expired = $this->makeUser(['plan_id' => 1, 'expired_at' => time() - 60]);

        $this->assertSame(['enabled' => false], $this->promotion()->popup($unpaid, 'dashboard'));
        $this->assertSame('限时活动', $this->promotion()->popup($paid, 'dashboard')['title']);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($expired, 'dashboard'));

        $popup->user_scope = 'unpaid';
        $popup->save();
        $this->assertSame('限时活动', $this->promotion()->popup($unpaid, 'dashboard')['title']);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($paid, 'dashboard'));

        $popup->user_scope = 'new';
        $popup->save();
        $fresh = $this->makeUser(['created_at' => time() - 3600]);
        $old = $this->makeUser(['created_at' => time() - 30 * 86400]);
        $this->assertSame('限时活动', $this->promotion()->popup($fresh, 'dashboard')['title']);
        $this->assertSame(['enabled' => false], $this->promotion()->popup($old, 'dashboard'));

        $popup->user_scope = 'all';
        $popup->save();
        $this->assertSame('限时活动', $this->promotion()->popup($old, 'dashboard')['title']);
    }

    public function testSortControlsWhichPopupWins()
    {
        $user = $this->makeUser();
        $this->makePopup(['title' => 'second', 'sort' => 10]);
        $this->makePopup(['title' => 'first', 'sort' => 1]);
        $this->assertSame('first', $this->promotion()->popup($user, 'dashboard')['title']);
    }

    public function testCooldownHoursIsPassedThrough()
    {
        $user = $this->makeUser();
        $this->makePopup(['cooldown_hours' => 12]);
        $data = $this->promotion()->popup($user, 'dashboard');
        $this->assertSame(12, $data['cooldown_hours']);
        $this->assertIsInt($data['cooldown_hours']);
    }

    // ============================================================ 行为与领取

    public function testClaimOnlyWritesRecordAndReturnsCouponCode()
    {
        $user = $this->makeUser(['transfer_enable' => 555]);
        $popup = $this->makePopup(['coupon_code' => 'ABC123']);

        $result = $this->promotion()->claim($user, $popup->id, 'dashboard', '127.0.0.1');

        $this->assertSame('ABC123', $result['coupon_code']);
        $record = PromotionRecord::where('promotion_id', $popup->id)->where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(PromotionRecord::ACTION_CLAIM, $record->action);
        $this->assertSame('dashboard', $record->channel);

        // 不真正发券/发奖：额度不变、不产生订单
        $this->assertSame(555, (int)User::find($user->id)->transfer_enable);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('v2_order')->where('user_id', $user->id)->count());
    }

    public function testClaimOnMissingPromotionFails()
    {
        $user = $this->makeUser();
        $this->assertHttpFailure(function () use ($user) {
            $this->promotion()->claim($user, 999999, 'dashboard', '127.0.0.1');
        }, 500, 'The promotion does not exist');
    }

    public function testRecordStoresEachThemeAction()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup();

        foreach ([PromotionRecord::ACTION_VIEW, PromotionRecord::ACTION_CLOSE, PromotionRecord::ACTION_CLICK] as $action) {
            $this->promotion()->record($user, $popup->id, $action, 'shop', ['from' => 'phpunit'], '127.0.0.1');
        }

        $this->assertSame(3, PromotionRecord::where('user_id', $user->id)->count());
        $this->assertSame(1, PromotionRecord::where('user_id', $user->id)->where('action', 'view')->count());
        $this->assertSame('shop', PromotionRecord::where('user_id', $user->id)->where('action', 'close')->first()->channel);
    }

    public function testRecordRejectsUnknownAction()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup();
        $this->assertHttpFailure(function () use ($user, $popup) {
            $this->promotion()->record($user, $popup->id, 'hack', null, null, null);
        }, 500, 'Invalid promotion action');
        $this->assertSame(0, PromotionRecord::where('user_id', $user->id)->count());
    }

    // ============================================================ 管理端校验

    public function testValidationRejectsInvalidInputs()
    {
        $service = $this->promotion();

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'pages' => 'dashboard,unknown-page']);
        }, 500);

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'button_action' => 'explode']);
        }, 500, 'Invalid button action');

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'button_action' => 'redirect', 'button_url' => '']);
        }, 500, 'Button url is required for redirect action');

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'button_action' => 'redirect', 'button_url' => 'javascript:alert(1)']);
        }, 500);

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'button_action' => 'copy_coupon', 'coupon_code' => '']);
        }, 500, 'Coupon code is required for copy action');

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'starts_at' => time(), 'ends_at' => time() - 10]);
        }, 500, 'End time must be later than start time');

        $this->assertHttpFailure(function () use ($service) {
            $service->assertValidPayload(['title' => 't', 'user_scope' => 'vip']);
        }, 500, 'Invalid user scope');

        // 合法输入
        $this->assertTrue($service->assertValidPayload([
            'title' => 'ok',
            'pages' => 'dashboard,shop',
            'button_action' => 'redirect',
            'button_url' => 'https://example.com/act'
        ]));
        $this->assertTrue($service->assertValidPayload([
            'title' => 'ok',
            'button_action' => 'claim_and_redirect',
            'button_url' => '/shop'
        ]));
    }

    public function testAdminSaveCreatesAndUpdatesPopup()
    {
        $controller = new AdminPromotionController();

        $response = $controller->save($this->adminRequest([
            'title' => '后台新建活动',
            'subtitle' => '副标题',
            'coupon_code' => 'NEW10',
            'coupon_name' => '新人券',
            'discount_text' => '9 折',
            'button_text' => '去看看',
            'button_url' => '/shop',
            'button_action' => 'claim_and_redirect',
            'pages' => 'dashboard',
            'user_scope' => 'all',
            'sort' => 3,
            'cooldown_hours' => 24,
            'show' => 1,
            'starts_at' => time() - 60,
            'ends_at' => time() + 86400,
        ]));
        $created = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('id', $created['data']);

        $popup = PromotionPopup::find($created['data']['id']);
        $this->assertSame('后台新建活动', $popup->title);
        $this->assertSame('dashboard', $popup->pages);
        $this->assertSame(24, (int)$popup->cooldown_hours);
        $this->assertSame(1, (int)$popup->show);

        // 更新
        $controller->save($this->adminRequest([
            'id' => $popup->id,
            'title' => '改名',
            'button_action' => 'copy_coupon',
            'coupon_code' => 'CPN',
            'pages' => 'all',
            'show' => 0,
        ]));
        $popup = PromotionPopup::find($popup->id);
        $this->assertSame('改名', $popup->title);
        $this->assertSame('copy_coupon', $popup->button_action);
        $this->assertSame(0, (int)$popup->show);
    }

    public function testAdminSaveRejectsInvalidPageScope()
    {
        $controller = new AdminPromotionController();
        $this->assertHttpFailure(function () use ($controller) {
            $controller->save($this->adminRequest([
                'title' => 'bad',
                'pages' => 'dashboard,../etc/passwd',
            ]));
        }, 500);
        $this->assertSame(0, PromotionPopup::where('title', 'bad')->count());
    }

    public function testAdminFetchAndRecordsAndDrop()
    {
        $popup = $this->makePopup(['title' => '可删除活动']);
        $user = $this->makeUser();
        $this->promotion()->record($user, $popup->id, PromotionRecord::ACTION_VIEW, 'dashboard', null, '127.0.0.1');

        $controller = new AdminPromotionController();

        $fetch = json_decode($controller->fetch($this->adminRequest(['current' => 1, 'page_size' => 20, 'show' => 1]))->getContent(), true);
        $this->assertGreaterThanOrEqual(1, $fetch['data']['total']);
        $titles = array_column($fetch['data']['items'], 'title');
        $this->assertContains('可删除活动', $titles);

        $records = json_decode($controller->recordFetch($this->adminRequest(['promotion_id' => $popup->id]))->getContent(), true);
        $this->assertSame(1, $records['data']['total']);
        $this->assertSame('view', $records['data']['items'][0]['action']);
        $this->assertSame($user->email, $records['data']['items'][0]['email']);

        $status = json_decode($controller->status($this->adminRequest())->getContent(), true);
        $this->assertSame(['dashboard', 'shop', 'checkin', 'home'], $status['data']['page_keys']);
        $this->assertContains('claim_and_redirect', $status['data']['button_actions']);

        // 删除配置后，行为记录保留
        $controller->drop($this->adminRequest(['id' => $popup->id]));
        $this->assertNull(PromotionPopup::find($popup->id));
        $this->assertSame(1, PromotionRecord::where('promotion_id', $popup->id)->count());
    }

    // ============================================================ HTTP 层

    public function testUserEndpointsViaHttp()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup(['ends_at' => time() + 3600]);

        $popupResponse = $this->withHeaders($this->headersFor($user))
            ->getJson('/api/v1/user/promotion/popup?page=dashboard');
        $popupResponse->assertStatus(200);
        $data = $popupResponse->json('data');
        $this->assertTrue($data['enabled']);
        $this->assertSame((int)$popup->id, $data['id']);
        $this->assertTrue($data['show_countdown']);

        $claimResponse = $this->withHeaders($this->headersFor($user))
            ->postJson('/api/v1/user/promotion/claim', ['id' => $popup->id]);
        $claimResponse->assertStatus(200);
        $this->assertSame('SAVE10', $claimResponse->json('data.coupon_code'));
        $this->assertSame(1, PromotionRecord::where('action', 'claim')->count());

        $recordResponse = $this->withHeaders($this->headersFor($user))
            ->postJson('/api/v1/user/promotion/record', ['id' => $popup->id, 'action' => 'click', 'page' => 'shop']);
        $recordResponse->assertStatus(200);
        $this->assertTrue($recordResponse->json('data'));
        $this->assertSame(1, PromotionRecord::where('action', 'click')->count());
    }

    /**
     * 主题使用 form-urlencoded（PANEL_TYPE=Xboard）且 id 会被字符串化，
     * 后端必须同时容忍 form 与 JSON。
     */
    public function testClaimAcceptsFormUrlencodedBody()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup();

        $response = $this->withHeaders($this->headersFor($user))
            ->post('/api/v1/user/promotion/claim', ['id' => (string)$popup->id]);
        $response->assertStatus(200);
        $this->assertSame('SAVE10', $response->json('data.coupon_code'));
        $this->assertSame(1, PromotionRecord::where('promotion_id', $popup->id)->where('action', 'claim')->count());
    }

    public function testPopupEndpointWithoutAnyPromotionReturnsEnabledFalse()
    {
        $user = $this->makeUser();
        $response = $this->withHeaders($this->headersFor($user))
            ->getJson('/api/v1/user/promotion/popup?page=shop');
        $response->assertStatus(200);
        $this->assertSame(['enabled' => false], $response->json('data'));
    }

    public function testRecordEndpointRejectsInvalidAction()
    {
        $user = $this->makeUser();
        $popup = $this->makePopup();
        $response = $this->withHeaders($this->headersFor($user))
            ->postJson('/api/v1/user/promotion/record', ['id' => $popup->id, 'action' => 'nope']);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }
}
