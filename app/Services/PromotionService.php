<?php

namespace App\Services;

use App\Models\PromotionPopup;
use App\Models\PromotionRecord;
use App\Models\User;

/**
 * 活动弹窗服务。
 *
 * 重要语义：claim 只写行为记录并返回优惠码（用于前端展示/复制），
 * **不会真正发放优惠券或任何奖励**，也不改动用户余额/流量。
 */
class PromotionService
{
    /** 允许的页面范围 key（EZ-Theme PromotionPopup.vue 只会发送这 4 个值） */
    const PAGE_KEYS = ['dashboard', 'shop', 'checkin', 'home'];

    /** “新用户”判定：注册后 N 天内 */
    const NEW_USER_DAYS = 7;

    // ------------------------------------------------------------------ 查询

    /**
     * 取当前用户应当看到的弹窗（按 sort 升序取第一个命中的）。
     * 没有可展示活动时返回 ['enabled' => false]（前端只认 enabled + id）。
     */
    public function popup(User $user, $page = null, $channel = null)
    {
        $items = PromotionPopup::visible()
            ->orderBy('sort', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($items as $item) {
            if (!$this->matchPages($item, $page)) continue;
            if (!$this->matchScope($item, $user)) continue;
            return $this->payload($item, $user, $channel);
        }
        return ['enabled' => false];
    }

    public function matchPages(PromotionPopup $item, $page)
    {
        $pages = array_filter(array_map('trim', explode(',', (string)$item->pages)));
        if (empty($pages) || in_array('all', $pages, true)) return true;
        if (!$page) return false;
        return in_array((string)$page, $pages, true);
    }

    public function matchScope(PromotionPopup $item, User $user)
    {
        $scope = (string)$item->user_scope;
        if ($scope === PromotionPopup::SCOPE_ALL || $scope === '') return true;

        $hasActivePlan = $user->plan_id !== null
            && ($user->expired_at === null || (int)$user->expired_at > time());

        if ($scope === PromotionPopup::SCOPE_PAID) return $hasActivePlan;
        if ($scope === PromotionPopup::SCOPE_UNPAID) return !$hasActivePlan;
        if ($scope === PromotionPopup::SCOPE_NEW) {
            $createdAt = (int)$user->created_at;
            return $createdAt > 0 && $createdAt >= (time() - self::NEW_USER_DAYS * 86400);
        }
        return true;
    }

    /**
     * 弹窗响应体 —— 严格对齐 EZ-Theme PromotionPopup.vue 消费的 14 个字段：
     *   enabled, id, title, subtitle, coupon_code, coupon_name, discount_text,
     *   button_text, button_url, button_action, show_countdown, ends_at,
     *   server_time, cooldown_hours
     *
     * 说明：
     *   - ends_at / server_time 均为 UNIX 秒（前端 ×1000，语义无歧义）；
     *   - `enabled` 必须是真值（false 时前端永不展示）；
     *   - theme 不消费 content/image/starts_at，故不下发。
     */
    public function payload(PromotionPopup $item, User $user, $channel = null)
    {
        $now = time();
        $endsAt = $item->ends_at !== null ? (int)$item->ends_at : null;

        return [
            'enabled' => true,
            'id' => (int)$item->id,
            'title' => (string)$item->title,
            'subtitle' => $item->subtitle ?: null,
            'coupon_code' => $item->coupon_code ?: null,
            'coupon_name' => $item->coupon_name ?: null,
            'discount_text' => $item->discount_text ?: null,
            'button_text' => $item->button_text ?: null,
            'button_url' => $item->button_url ?: '/shop',
            'button_action' => (string)$item->button_action,
            'show_countdown' => $endsAt !== null && $endsAt > 0,
            'ends_at' => ($endsAt !== null && $endsAt > 0) ? $endsAt : null,
            'server_time' => $now,
            'cooldown_hours' => (int)$item->cooldown_hours
        ];
    }

    public function hasClaimed($promotionId, $userId)
    {
        return PromotionRecord::where('promotion_id', $promotionId)
            ->where('user_id', $userId)
            ->where('action', PromotionRecord::ACTION_CLAIM)
            ->exists();
    }

    // ------------------------------------------------------------------ 行为

    /**
     * 记录行为（展示 / 关闭 / 点击 / 复制优惠码）。
     */
    public function record(User $user, $promotionId, $action, $channel = null, $metadata = null, $ip = null)
    {
        $popup = PromotionPopup::find($promotionId);
        if (!$popup) abort(500, __('The promotion does not exist'));
        if (!in_array($action, PromotionRecord::actions(), true)) {
            abort(500, __('Invalid promotion action'));
        }

        $record = new PromotionRecord();
        $record->promotion_id = (int)$popup->id;
        $record->user_id = (int)$user->id;
        $record->action = $action;
        $record->channel = $channel ? mb_substr((string)$channel, 0, 32) : null;
        $record->metadata = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        $record->ip = $ip;
        $record->created_at = time();
        $record->save();

        return $record;
    }

    /**
     * “领取”活动：只写 claim 行为记录 + 返回优惠码，不真正发券。
     * 前端只读取 coupon_code 与 message。
     */
    public function claim(User $user, $promotionId, $channel = null, $ip = null)
    {
        $popup = PromotionPopup::where('id', $promotionId)->where('show', 1)->first();
        if (!$popup) abort(500, __('The promotion does not exist'));

        $this->record($user, $popup->id, PromotionRecord::ACTION_CLAIM, $channel, null, $ip);

        return [
            'id' => (int)$popup->id,
            'coupon_code' => $popup->coupon_code ?: null,
            'coupon_name' => $popup->coupon_name ?: null,
            'discount_text' => $popup->discount_text ?: null
        ];
    }

    // ------------------------------------------------------------------ 校验

    /**
     * 管理端校验（供控制器调用）。
     */
    public function assertValidPayload(array $input)
    {
        if (isset($input['button_action']) && (string)$input['button_action'] !== ''
            && !in_array($input['button_action'], PromotionPopup::actions(), true)) {
            abort(500, __('Invalid button action'));
        }
        if (isset($input['user_scope']) && (string)$input['user_scope'] !== ''
            && !in_array($input['user_scope'], PromotionPopup::scopes(), true)) {
            abort(500, __('Invalid user scope'));
        }
        if (isset($input['pages']) && trim((string)$input['pages']) !== '' && trim((string)$input['pages']) !== 'all') {
            foreach (explode(',', (string)$input['pages']) as $page) {
                $page = trim($page);
                if ($page === '') continue;
                if (!in_array($page, self::PAGE_KEYS, true)) {
                    abort(500, __('Invalid page scope: :page', ['page' => $page]));
                }
            }
        }

        $action = isset($input['button_action']) && $input['button_action'] !== ''
            ? $input['button_action']
            : PromotionPopup::ACTION_CLAIM_AND_REDIRECT;
        $url = isset($input['button_url']) ? trim((string)$input['button_url']) : '';

        // 跳转类动作必须给出合法链接（外部 http(s) 或站内 /path）
        if (in_array($action, [PromotionPopup::ACTION_REDIRECT, PromotionPopup::ACTION_CLAIM_AND_REDIRECT], true)
            && $url !== '') {
            if (!preg_match('#^https?://#i', $url) && strpos($url, '/') !== 0) {
                abort(500, __('Button url must start with http:// or https:// or /'));
            }
        }
        if ($action === PromotionPopup::ACTION_REDIRECT && $url === '') {
            abort(500, __('Button url is required for redirect action'));
        }
        if ($action === PromotionPopup::ACTION_COPY_COUPON && empty($input['coupon_code'])) {
            abort(500, __('Coupon code is required for copy action'));
        }

        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($input[$field]) && $input[$field] !== null && $input[$field] !== ''
                && (int)$input[$field] < 0) {
                abort(500, __('Invalid time'));
            }
        }
        if (!empty($input['starts_at']) && !empty($input['ends_at'])
            && (int)$input['ends_at'] <= (int)$input['starts_at']) {
            abort(500, __('End time must be later than start time'));
        }
        if (isset($input['cooldown_hours']) && $input['cooldown_hours'] !== '' && (int)$input['cooldown_hours'] < 0) {
            abort(500, __('Invalid cooldown'));
        }
        return true;
    }
}
