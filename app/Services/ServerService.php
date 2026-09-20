<?php

namespace App\Services;

use App\Models\ServerHysteria;
use App\Models\ServerLog;
use App\Models\ServerRoute;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerV2node;
use App\Models\User;
use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerService
{
    public function getAvailableVless(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerVless::orderBy('sort', 'ASC');
        $server = $model->get();
        foreach ($server as $key => $v) {
            if (!$v['show']) continue;
            $server[$key]['type'] = 'vless';
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $server[$key]['group_id'])) continue;
            if (strpos($server[$key]['port'], '-') !== false) {
                $server[$key]['port'] = Helper::randomPort($server[$key]['port']);
            }
            if ($server[$key]['parent_id']) {
                $server[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $server[$key]['parent_id']));
            } else {
                $server[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $server[$key]['id']));
            }
            if (isset($server[$key]['tls_settings'])) {
                $server[$key]['tls_settings'] = array_diff_key(
                    $server[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($server, $key) {
                        return isset($server[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($server[$key]['encryption_settings'])) {
                if (isset($server[$key]['encryption_settings']['private_key'])) {
                    $server[$key]['encryption_settings'] = array_diff_key($server[$key]['encryption_settings'], array('private_key' => ''));
                }
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($server[$key]['host'])) {
                $server[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($server[$key]['server'])) {
                $server[$key]['server'] = 'hidden.example.com';
            }

            $servers[] = $server[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableVmess(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerVmess::orderBy('sort', 'ASC');
        $vmess = $model->get();
        foreach ($vmess as $key => $v) {
            if (!$v['show']) continue;
            $vmess[$key]['type'] = 'vmess';
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $vmess[$key]['group_id'])) continue;
            if (strpos($vmess[$key]['port'], '-') !== false) {
                $vmess[$key]['port'] = Helper::randomPort($vmess[$key]['port']);
            }
            if ($vmess[$key]['parent_id']) {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['parent_id']));
            } else {
                $vmess[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $vmess[$key]['id']));
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($vmess[$key]['host'])) {
                $vmess[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($vmess[$key]['server'])) {
                $vmess[$key]['server'] = 'hidden.example.com';
            }

            $servers[] = $vmess[$key]->toArray();
        }


        return $servers;
    }

    public function getAvailableTrojan(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerTrojan::orderBy('sort', 'ASC');
        $trojan = $model->get();
        foreach ($trojan as $key => $v) {
            if (!$v['show']) continue;
            $trojan[$key]['type'] = 'trojan';
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $trojan[$key]['group_id'])) continue;
            if (strpos($trojan[$key]['port'], '-') !== false) {
                $trojan[$key]['port'] = Helper::randomPort($trojan[$key]['port']);
            }
            if ($trojan[$key]['parent_id']) {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['parent_id']));
            } else {
                $trojan[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $trojan[$key]['id']));
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($trojan[$key]['host'])) {
                $trojan[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($trojan[$key]['server'])) {
                $trojan[$key]['server'] = 'hidden.example.com';
            }

            $servers[] = $trojan[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableTuic(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $availableServers = [];
        $model = ServerTuic::orderBy('sort', 'ASC');
        $servers = $model->get()->keyBy('id');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'tuic';
            $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TUIC_LAST_CHECK_AT', $v['id']));
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_TUIC_LAST_CHECK_AT', $v['parent_id']));
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($servers[$key]['host'])) {
                $servers[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($servers[$key]['server'])) {
                $servers[$key]['server'] = 'hidden.example.com';
            }

            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableHysteria(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $availableServers = [];
        $model = ServerHysteria::orderBy('sort', 'ASC');
        $servers = $model->get()->keyBy('id');
        foreach ($servers as $key => $v) {
            if (!$v['show']) continue;
            $servers[$key]['type'] = 'hysteria';
            $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_HYSTERIA_LAST_CHECK_AT', $v['id']));
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $v['group_id'])) continue;
            if (isset($servers[$v['parent_id']])) {
                $servers[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_HYSTERIA_LAST_CHECK_AT', $v['parent_id']));
                $servers[$key]['created_at'] = $servers[$v['parent_id']]['created_at'];
            }
            $servers[$key]['server_key'] = Helper::getServerKey($servers[$key]['created_at'], 16);

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($servers[$key]['host'])) {
                $servers[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($servers[$key]['server'])) {
                $servers[$key]['server'] = 'hidden.example.com';
            }

            $availableServers[] = $servers[$key]->toArray();
        }
        return $availableServers;
    }

    public function getAvailableShadowsocks(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerShadowsocks::orderBy('sort', 'ASC');
        $shadowsocks = $model->get()->keyBy('id');
        foreach ($shadowsocks as $key => $v) {
            if (!$v['show']) continue;
            $shadowsocks[$key]['type'] = 'shadowsocks';
            $shadowsocks[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $v['id']));
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $shadowsocks[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($shadowsocks[$v['parent_id']])) {
                $shadowsocks[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_SHADOWSOCKS_LAST_CHECK_AT', $v['parent_id']));
                $shadowsocks[$key]['created_at'] = $shadowsocks[$v['parent_id']]['created_at'];
            }
            if ($v['obfs'] === 'http') {
                $shadowsocks[$key]['obfs'] = 'http';
                $shadowsocks[$key]['obfs-host'] = $v['obfs_settings']['host'];
                $shadowsocks[$key]['obfs-path'] = $v['obfs_settings']['path'];
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($shadowsocks[$key]['host'])) {
                $shadowsocks[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($shadowsocks[$key]['server'])) {
                $shadowsocks[$key]['server'] = 'hidden.example.com';
            }

            $servers[] = $shadowsocks[$key]->toArray();
        }
        return $servers;
    }

    public function getAvailableAnyTLS(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false): array
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerAnytls::orderBy('sort', 'ASC');
        $anytls = $model->get()->keyBy('id');
        foreach ($anytls as $key => $v) {
            if (!$v['show']) continue;
            $anytls[$key]['type'] = 'anytls';
            $anytls[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_ANYTLS_LAST_CHECK_AT', $v['id']));
            // 如果不忽略组限制且用户不在允许的组中，则跳过
            if (!$ignoreGroupLimit && !in_array($groupId, $v['group_id'])) continue;
            if (strpos($v['port'], '-') !== false) {
                $anytls[$key]['port'] = Helper::randomPort($v['port']);
            }
            if (isset($anytls[$v['parent_id']])) {
                $anytls[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_ANYTLS_LAST_CHECK_AT', $v['parent_id']));
                $anytls[$key]['created_at'] = $anytls[$v['parent_id']]['created_at'];
            }

            // 如果不显示真实地址，则隐藏
            if (!$showRealAddress && isset($anytls[$key]['host'])) {
                $anytls[$key]['host'] = 'hidden.example.com';
            }
            if (!$showRealAddress && isset($anytls[$key]['server'])) {
                $anytls[$key]['server'] = 'hidden.example.com';
            }

            $servers[] = $anytls[$key]->toArray();
        }
        return $servers;
    }

    public function getServers(User $user, bool $isAvailable)
    {
        if ($isAvailable) {
            // 用户有有效订阅，返回完整的节点列表
            return $this->getAvailableServers($user);
        } else {
            // 用户没有有效订阅，返回节点列表但隐藏真实地址
            // 注意：这里我们传递一个特殊的标志来表示需要显示所有节点
            return $this->getAvailableServers($user, false, true);
        }
    }

    public function getAvailableV2node(User $user, $groupId = null, bool $showRealAddress = true, bool $ignoreGroupLimit = false)
    {
        $groupId = $groupId === null ? $user->group_id : $groupId;
        $servers = [];
        $model = ServerV2node::orderBy('sort', 'ASC');
        $v2node = $model->get()->keyBy('id');
        foreach ($v2node as $key => $v) {
            if (!$v['show']) continue;
            $v2node[$key]['type'] = 'v2node';
            $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['id']));
            if (!$ignoreGroupLimit && !in_array($groupId, $v['group_id'])) continue;
            if (isset($v2node[$v['parent_id']])) {
                $v2node[$key]['last_check_at'] = Cache::get(CacheKey::get('SERVER_V2NODE_LAST_CHECK_AT', $v['parent_id']));
                $v2node[$key]['created_at'] = $v2node[$v['parent_id']]['created_at'];
            }
            if (isset($v2node[$key]['tls_settings'])) {
                $v2node[$key]['tls_settings'] = array_diff_key(
                    $v2node[$key]['tls_settings'],
                    array_flip(array_filter(['private_key', 'ech_key'], function($k) use ($v2node, $key) {
                        return isset($v2node[$key]['tls_settings'][$k]);
                    }))
                );
            }
            if (isset($v2node[$key]['encryption_settings'])) {
                if (isset($v2node[$key]['encryption_settings']['private_key'])) {
                    $v2node[$key]['encryption_settings'] = array_diff_key($v2node[$key]['encryption_settings'], array('private_key' => ''));
                }
            }
            $servers[] = $v2node[$key]->toArray();
        }
        return $servers;
    }

    /**
     * 解析用户的有效订阅授权。未启用子账号功能时直接返回普通用户快照，
     * 保证普通用户行为与改动前完全一致。
     */
    private function resolveEntitlement(User $user)
    {
        if (!(int)config('v2board.sub_account_enable', 1)) {
            return SubAccountEntitlement::forNormalUser($user);
        }
        $service = new SubAccountService();
        return $service->resolveEntitlement($user);
    }

    public function getAvailableServers(User $user, bool $showRealAddress = true, bool $ignoreGroupLimit = false)
    {
        // 只在入口解析一次有效授权，然后把 effective group_id 传给各协议筛选，
        // 避免在每个协议里重复查询父账号（子账号继承主账号权限组）。
        $entitlement = $this->resolveEntitlement($user);
        $groupId = $entitlement->getEffectiveGroupId();

        $servers = array_merge(
            $this->getAvailableShadowsocks($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableVmess($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableTrojan($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableTuic($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableHysteria($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableVless($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableAnyTLS($user, $groupId, $showRealAddress, $ignoreGroupLimit),
            $this->getAvailableV2node($user, $groupId, $showRealAddress, $ignoreGroupLimit)
        );
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return array_map(function ($server) use ($showRealAddress) {
            // 如果不显示真实地址，则隐藏所有地址字段并伪装其他字段
            if (!$showRealAddress) {
                $addressFields = ['host', 'server', 'address'];
                foreach ($addressFields as $field) {
                    if (isset($server[$field])) {
                        $server[$field] = 'hidden.example.com';
                    }
                }

                // 伪装其他字段
                $server['port'] = 0;
                if (isset($server['server_port'])) {
                    $server['server_port'] = 0;
                }
                $server['is_online'] = 1;
                $server['cache_key'] = '';
                $server['last_check_at'] = time(); // 设置为当前时间
            }

            // 处理端口字段
            if (isset($server['port'])) {
                if (strpos((string)$server['port'], '-')) {
                    $server['mport'] = (string)$server['port'];
                } else {
                    $server['port'] = (int)$server['port'];
                }
            }

            // 处理在线状态
            if (!isset($server['is_online'])) {
                $server['is_online'] = (time() - 300 > ($server['last_check_at'] ?? 0)) ? 0 : 1;
            }

            // 处理缓存键
            if (!isset($server['cache_key']) || ($showRealAddress && $server['cache_key'] === '')) {
                $server['cache_key'] = isset($server['type']) && isset($server['id']) && isset($server['updated_at']) && isset($server['is_online'])
                    ? "{$server['type']}-{$server['id']}-{$server['updated_at']}-{$server['is_online']}"
                    : '';
            }

            return $server;
        }, $servers);
    }

    /**
     * 节点拉取可用用户列表。
     *
     * 拆成两段（需求书第十三节）:
     *   1. 普通用户查询：保持原有条件，并排除启用中的子账号，避免同一账号出现两次；
     *   2. 启用子账号 JOIN 关系表 + 主账号查询：节点记录使用
     *      子账号 id / 子账号 uuid / 主账号 group_id、expired_at、speed_limit、device_limit，
     *      并同时校验父子封禁、个人额度、共享额度与关系状态。
     */
    public function getAvailableUsers($groupId)
    {
        $groupIds = is_array($groupId) ? $groupId : [$groupId];
        $now = time();

        // 普通用户分支保持改造前的原始条件（含上游 orWhere(col, NULL) 的既有语义），
        // 确保普通用户的节点名单与升级前逐行一致（验收项 26 无回归）。
        $query = User::whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0);

        if ((int)config('v2board.sub_account_enable', 1)) {
            $query->whereNotIn('id', function ($sub) {
                $sub->select('child_user_id')
                    ->from('v2_user_sub_accounts')
                    ->where('status', 1);
            });
        }

        $normalUsers = $query->select([
            'id',
            'uuid',
            'speed_limit',
            'device_limit'
        ])->get();

        if (!(int)config('v2board.sub_account_enable', 1)) {
            return $normalUsers;
        }

        $subRows = DB::table('v2_user_sub_accounts as r')
            ->join('v2_user as c', 'c.id', '=', 'r.child_user_id')
            ->join('v2_user as p', 'p.id', '=', 'r.parent_user_id')
            ->where('r.status', 1)
            ->whereIn('p.group_id', $groupIds)
            ->where('c.banned', 0)
            ->where('p.banned', 0)
            ->whereNotNull('p.plan_id')
            // 与普通用户分支保持一致的有效期语义（更早到期不算有效）。
            ->where('p.expired_at', '>=', $now)
            ->whereRaw('(r.traffic_limit = 0 OR c.u + c.d < r.traffic_limit)')
            ->whereRaw('p.u + p.d < p.transfer_enable')
            ->select([
                'c.id as id',
                'c.uuid as uuid',
                'p.speed_limit as speed_limit',
                'p.device_limit as device_limit'
            ])
            ->get();

        if ($subRows->isEmpty()) {
            return $normalUsers;
        }

        return $normalUsers->concat(User::hydrate($subRows->all()));
    }

    public function log(int $userId, int $serverId, int $u, int $d, float $rate, string $method)
    {
        if (($u + $d) < 10240) return true;
        $timestamp = strtotime(date('Y-m-d'));
        $serverLog = ServerLog::where('log_at', '>=', $timestamp)
            ->where('log_at', '<', $timestamp + 3600)
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->where('rate', $rate)
            ->where('method', $method)
            ->first();
        if ($serverLog) {
            try {
                $serverLog->increment('u', $u);
                $serverLog->increment('d', $d);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            $serverLog = new ServerLog();
            $serverLog->user_id = $userId;
            $serverLog->server_id = $serverId;
            $serverLog->u = $u;
            $serverLog->d = $d;
            $serverLog->rate = $rate;
            $serverLog->log_at = $timestamp;
            $serverLog->method = $method;
            return $serverLog->save();
        }
    }

    public function getAllShadowsocks()
    {
        $servers = ServerShadowsocks::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'shadowsocks';
        }
        return $servers;
    }

    public function getAllVMess()
    {
        $servers = ServerVmess::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vmess';
        }
        return $servers;
    }

    public function getAllVLess()
    {
        $servers = ServerVless::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'vless';
        }
        return $servers;
    }

    public function getAllTrojan()
    {
        $servers = ServerTrojan::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'trojan';
        }
        return $servers;
    }

    public function getAllTuic()
    {
        $servers = ServerTuic::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'tuic';
        }
        return $servers;
    }

    public function getAllHysteria()
    {
        $servers = ServerHysteria::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'hysteria';
        }
        return $servers;
    }

    public function getAllAnyTLS()
    {
        $servers = ServerAnytls::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'anytls';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }
        }
        return $servers;
    }

    public function getAllV2node()
    {
        $servers = ServerV2node::orderBy('sort', 'ASC')
            ->get()
            ->toArray();
        foreach ($servers as $k => $v) {
            $servers[$k]['type'] = 'v2node';
            if (isset($v['padding_scheme'])) {
                $servers[$k]['padding_scheme'] = json_encode($v['padding_scheme']);
            }

            $apiHost = config('v2board.server_api_url', config('v2board.app_url'));
            $apiKey = config('v2board.server_token', '');
            $nodeId = (int) $v['id'];
            $apiHostArg = escapeshellarg((string) $apiHost);
            $apiKeyArg = escapeshellarg((string) $apiKey);
            $servers[$k]['install_command'] = sprintf(
                'wget -N https://raw.githubusercontent.com/wyx2685/v2node/master/script/install.sh && bash install.sh --api-host %s --node-id %d --api-key %s',
                $apiHostArg,
                $nodeId,
                $apiKeyArg
            );
        }
        return $servers;
    }

    private function mergeData(&$servers)
    {
        foreach ($servers as $k => $v) {
            $serverType = strtoupper($v['type']);
            $servers[$k]['online'] = Cache::get(CacheKey::get("SERVER_{$serverType}_ONLINE_USER", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_check_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_CHECK_AT", $v['parent_id'] ?? $v['id']));
            $servers[$k]['last_push_at'] = Cache::get(CacheKey::get("SERVER_{$serverType}_LAST_PUSH_AT", $v['parent_id'] ?? $v['id']));
            if ((time() - 300) >= $servers[$k]['last_check_at']) {
                $servers[$k]['available_status'] = 0;
            } else if ((time() - 300) >= $servers[$k]['last_push_at']) {
                $servers[$k]['available_status'] = 1;
            } else {
                $servers[$k]['available_status'] = 2;
            }
        }
    }

    public function getAllServers()
    {
        $servers = array_merge(
            $this->getAllShadowsocks(),
            $this->getAllVMess(),
            $this->getAllTrojan(),
            $this->getAllTuic(),
            $this->getAllHysteria(),
            $this->getAllVLess(),
            $this->getAllAnyTLS(),
            $this->getAllV2node()
        );
        $this->mergeData($servers);
        $tmp = array_column($servers, 'sort');
        array_multisort($tmp, SORT_ASC, $servers);
        return $servers;
    }

    public function getRoutes(array $routeIds)
    {
        $routeIds = array_map('intval', $routeIds);
        $order = implode(',', $routeIds);
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])
            ->whereIn('id', $routeIds)
            ->orderByRaw("FIELD(id, $order)")
            ->get();
        foreach ($routes as $k => $route) {
            $array = json_decode($route->match, true);
            if (is_array($array)) $routes[$k]['match'] = $array;
        }
        return $routes;
    }

    public function getServer($serverId, $serverType)
    {
        switch ($serverType) {
            case 'v2node':
                return ServerV2node::find($serverId);
            case 'vmess':
                return ServerVmess::find($serverId);
            case 'shadowsocks':
                return ServerShadowsocks::find($serverId);
            case 'trojan':
                return ServerTrojan::find($serverId);
            case 'tuic':
                return ServerTuic::find($serverId);
            case 'hysteria':
                return ServerHysteria::find($serverId);
            case 'vless':
                return ServerVless::find($serverId);
            case 'anytls':
                return ServerAnytls::find($serverId);
            default:
                return false;
        }
    }
}
