<?php

namespace App\Jobs;

use App\Services\SubAccountService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * 节点流量上报落 Redis。
 *
 * 子账号语义（需求书第十二节）:
 *   - 子账号 v2_user.u/d 记录的是"个人用量"（原有 HINCRBY 行为完全保留）；
 *   - 主账号 v2_user.u/d 需要包含自身流量 + 全部子账号流量；
 *   - v2_stat_user 仍然只记录真实使用节点的账号（本 Job 不参与 StatUserJob 入参）。
 *
 * 实现要点: 由于 Redis HINCRBY 本身是原子累加，同一批次里主账号自身流量与
 * 其子账号聚合流量会自然相加，不需要额外合并逻辑；也不会向 StatUserJob
 * 传递任何主账号聚合数据（UserService::trafficFetch 未改动）。
 */
class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;

    public $tries = 3;
    public $timeout = 10;

    const UPLOAD_KEY = 'v2board_upload_traffic';
    const DOWNLOAD_KEY = 'v2board_download_traffic';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol)
    {
        $this->onQueue('traffic_fetch');
        $this->data = $data;
        $this->server = $server;
        $this->protocol = $protocol;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $userIds = array_keys($this->data);

        // 1. 保留原有行为: 按真实上报账号累加个人用量。
        foreach ($userIds as $userId) {
            Redis::hincrby(self::UPLOAD_KEY, $userId, $this->data[$userId][0] * $this->server['rate']);
            Redis::hincrby(self::DOWNLOAD_KEY, $userId, $this->data[$userId][1] * $this->server['rate']);
        }

        if (!(int)config('v2board.sub_account_enable', 1)) {
            return;
        }

        // 2. 批量查询本批次中的启用子账号关系（一次查询，不做 N+1）。
        $service = new SubAccountService();
        $relations = $service->activeRelationsByChildIds($userIds);
        if (empty($relations)) {
            return;
        }

        // 3. 按 parent_user_id 聚合子账号流量。
        $parentUploads = [];
        $parentDownloads = [];
        foreach ($relations as $childUserId => $relation) {
            if (!isset($this->data[$childUserId])) continue;
            $parentUserId = (int)$relation->parent_user_id;
            $upload = $this->data[$childUserId][0] * $this->server['rate'];
            $download = $this->data[$childUserId][1] * $this->server['rate'];
            if (!isset($parentUploads[$parentUserId])) {
                $parentUploads[$parentUserId] = 0;
                $parentDownloads[$parentUserId] = 0;
            }
            $parentUploads[$parentUserId] += $upload;
            $parentDownloads[$parentUserId] += $download;
        }

        // 4/5. 累加到主账号。同批次主账号自身流量已在第 1 步写入，
        //      HINCRBY 的累加语义保证两者正确相加。
        foreach ($parentUploads as $parentUserId => $upload) {
            Redis::hincrby(self::UPLOAD_KEY, $parentUserId, $upload);
            Redis::hincrby(self::DOWNLOAD_KEY, $parentUserId, $parentDownloads[$parentUserId]);
        }
    }
}
