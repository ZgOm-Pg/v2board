<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromotionPopup;
use App\Models\PromotionRecord;
use App\Models\User;
use App\Services\PromotionService;
use Illuminate\Http\Request;

/**
 * 后台「活动弹窗」接口：配置增删改查 + 行为记录查询。
 */
class PromotionController extends Controller
{
    private $service;

    public function __construct()
    {
        $this->service = new PromotionService();
    }

    private function payloadOf(PromotionPopup $item)
    {
        return [
            'id' => (int)$item->id,
            'title' => (string)$item->title,
            'subtitle' => $item->subtitle ?: '',
            'coupon_code' => $item->coupon_code ?: '',
            'coupon_name' => $item->coupon_name ?: '',
            'discount_text' => $item->discount_text ?: '',
            'button_text' => $item->button_text ?: '',
            'button_url' => $item->button_url ?: '',
            'button_action' => (string)$item->button_action,
            'pages' => (string)$item->pages,
            'user_scope' => (string)$item->user_scope,
            'sort' => (int)$item->sort,
            'cooldown_hours' => (int)$item->cooldown_hours,
            'show' => (int)$item->show,
            'starts_at' => $item->starts_at !== null ? (int)$item->starts_at : null,
            'ends_at' => $item->ends_at !== null ? (int)$item->ends_at : null,
            'starts_at_text' => $item->starts_at ? date('Y-m-d H:i:s', (int)$item->starts_at) : '',
            'ends_at_text' => $item->ends_at ? date('Y-m-d H:i:s', (int)$item->ends_at) : '',
            'created_at' => $item->created_at ? (int)$item->created_at : null,
            'updated_at' => $item->updated_at ? (int)$item->updated_at : null
        ];
    }

    /** GET {secure_path}/promotion/fetch */
    public function fetch(Request $request)
    {
        $query = PromotionPopup::query();
        if ($request->input('show') !== null && $request->input('show') !== '') {
            $query->where('show', (int)$request->input('show'));
        }
        if ($request->input('keyword')) {
            $kw = '%' . $request->input('keyword') . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('title', 'like', $kw)->orWhere('coupon_code', 'like', $kw);
            });
        }

        $page = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('page_size', 20)));
        $total = (clone $query)->count();
        $items = $query->orderBy('sort', 'asc')->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)->take($size)->get();

        $list = [];
        foreach ($items as $item) {
            $list[] = $this->payloadOf($item);
        }
        return response(['data' => ['total' => $total, 'items' => $list]]);
    }

    /** GET {secure_path}/promotion/detail */
    public function detail(Request $request)
    {
        $item = PromotionPopup::find($request->input('id'));
        if (!$item) abort(500, __('The promotion does not exist'));
        return response(['data' => $this->payloadOf($item)]);
    }

    /** POST {secure_path}/promotion/save */
    public function save(Request $request)
    {
        $input = $request->only([
            'id', 'title', 'subtitle', 'coupon_code', 'coupon_name', 'discount_text',
            'button_text', 'button_url', 'button_action', 'pages', 'user_scope',
            'sort', 'cooldown_hours', 'show', 'starts_at', 'ends_at'
        ]);
        $this->service->assertValidPayload($input);

        $id = isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null;
        $item = $id ? PromotionPopup::find($id) : new PromotionPopup();
        if (!$item) abort(500, __('The promotion does not exist'));

        $item->title = mb_substr(trim((string)($input['title'] ?? '')), 0, 128);
        if ($item->title === '') abort(500, __('Title is required'));
        $item->subtitle = $this->nullableString($input['subtitle'] ?? null, 128);
        $item->coupon_code = $this->nullableString($input['coupon_code'] ?? null, 64);
        $item->coupon_name = $this->nullableString($input['coupon_name'] ?? null, 64);
        $item->discount_text = $this->nullableString($input['discount_text'] ?? null, 64);
        $item->button_text = $this->nullableString($input['button_text'] ?? null, 64);
        $item->button_url = $this->nullableString($input['button_url'] ?? null, 255);
        $item->button_action = isset($input['button_action']) && $input['button_action'] !== ''
            ? $input['button_action']
            : PromotionPopup::ACTION_CLAIM_AND_REDIRECT;
        $item->pages = trim((string)($input['pages'] ?? 'all')) !== '' ? trim((string)$input['pages']) : 'all';
        $item->user_scope = isset($input['user_scope']) && $input['user_scope'] !== ''
            ? $input['user_scope']
            : PromotionPopup::SCOPE_ALL;
        $item->sort = (int)($input['sort'] ?? 0);
        $item->cooldown_hours = max(0, (int)($input['cooldown_hours'] ?? 0));
        $item->show = (int)($input['show'] ?? 0) === 1 ? 1 : 0;
        $item->starts_at = $this->nullableInt($input['starts_at'] ?? null);
        $item->ends_at = $this->nullableInt($input['ends_at'] ?? null);
        $item->updated_at = time();
        if (!$item->exists) $item->created_at = time();
        if (!$item->save()) abort(500, __('Save failed'));

        return response(['data' => ['id' => (int)$item->id]]);
    }

    /** POST {secure_path}/promotion/drop */
    public function drop(Request $request)
    {
        $item = PromotionPopup::find($request->input('id'));
        if (!$item) abort(500, __('The promotion does not exist'));
        // 行为记录保留（软引用 promotion_id），只删除配置本身
        $item->delete();
        return response(['data' => true]);
    }

    /** GET {secure_path}/promotion/record/fetch */
    public function recordFetch(Request $request)
    {
        $query = PromotionRecord::query();
        if ($request->input('promotion_id')) $query->where('promotion_id', (int)$request->input('promotion_id'));
        if ($request->input('user_id')) $query->where('user_id', (int)$request->input('user_id'));
        if ($request->input('email')) {
            $ids = User::where('email', 'like', '%' . $request->input('email') . '%')
                ->limit(200)->pluck('id')->map(function ($v) { return (int)$v; })->toArray();
            $query->whereIn('user_id', empty($ids) ? [0] : $ids);
        }
        if ($request->input('action')) $query->where('action', $request->input('action'));
        if ($request->input('date_from')) $query->where('created_at', '>=', strtotime((string)$request->input('date_from')));
        if ($request->input('date_to')) $query->where('created_at', '<=', strtotime((string)$request->input('date_to')) + 86399);

        $page = max(1, (int)$request->input('current', 1));
        $size = min(100, max(1, (int)$request->input('page_size', 20)));
        $total = (clone $query)->count();
        $rows = $query->orderBy('id', 'desc')->skip(($page - 1) * $size)->take($size)->get();

        $emails = User::whereIn('id', $rows->pluck('user_id')->unique()->toArray())->pluck('email', 'id');
        $titles = PromotionPopup::whereIn('id', $rows->pluck('promotion_id')->unique()->toArray())->pluck('title', 'id');

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int)$row->id,
                'promotion_id' => (int)$row->promotion_id,
                'promotion_title' => isset($titles[$row->promotion_id]) ? $titles[$row->promotion_id] : null,
                'user_id' => (int)$row->user_id,
                'email' => isset($emails[$row->user_id]) ? $emails[$row->user_id] : null,
                'action' => (string)$row->action,
                'channel' => $row->channel,
                'ip' => $row->ip,
                'created_at' => $row->created_at ? (int)$row->created_at : null,
                'created_at_text' => $row->created_at ? date('Y-m-d H:i:s', (int)$row->created_at) : null
            ];
        }
        return response(['data' => ['total' => $total, 'items' => $items]]);
    }

    /** GET {secure_path}/promotion/status */
    public function status(Request $request)
    {
        $now = time();
        return response(['data' => [
            'total' => (int)PromotionPopup::count(),
            'showing' => (int)PromotionPopup::where('show', 1)->count(),
            'visible_now' => (int)PromotionPopup::visible()->count(),
            'records_total' => (int)PromotionRecord::count(),
            'records_claim' => (int)PromotionRecord::where('action', PromotionRecord::ACTION_CLAIM)->count(),
            'records_today' => (int)PromotionRecord::where('created_at', '>=', strtotime(date('Y-m-d')))->count(),
            'button_actions' => PromotionPopup::actions(),
            'user_scopes' => PromotionPopup::scopes(),
            'page_keys' => PromotionService::PAGE_KEYS,
            'server_time' => $now
        ]]);
    }

    private function nullableString($value, $max)
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        return mb_substr($value, 0, $max);
    }

    private function nullableInt($value)
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value)) abort(500, __('Invalid time'));
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }
}
