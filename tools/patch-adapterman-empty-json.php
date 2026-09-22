<?php
/**
 * AdapterMan（Workerman）主机环境修复：空 body / 非法 JSON 不得抛 JsonException
 *
 * 背景（实测）：
 *   tx1.nexa.lat 由 AdapterMan 承载。vendor/joanhey/adapterman/src/Http.php 中：
 *     'application/json' => $_POST = \json_decode($http_body, true, flags: \JSON_THROW_ON_ERROR) ?? [],
 *   当 Content-Type: application/json 且 body 为空时 json_decode('') 抛 JsonException，
 *   异常未被捕获 → workerman 子进程以 64000 退出 → 该请求悬挂（客户端超时/502）。
 *
 *   而 EZ-Theme 的「领取签到」正是 POST + application/json + **空 body**
 *   （见 checkin 契约：E2 POST 无 body；后端不得要求任何必填参数）。
 *
 * 修复：空 body 直接视为 []；非法 JSON 不再抛异常（与常规 Web 服务器行为一致）。
 *   仅改这一处解析逻辑，不改变任何业务语义。
 *
 * 用法：
 *   php tools/patch-adapterman-empty-json.php --check
 *   php tools/patch-adapterman-empty-json.php --apply
 *   php tools/patch-adapterman-empty-json.php --apply --file=/path/to/Http.php
 *
 * 注意：vendor/ 不纳入 git；每次 composer install/update 之后需重新执行本脚本。
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$apply = false;
$file = $root . '/vendor/joanhey/adapterman/src/Http.php';

foreach ($args as $arg) {
    if ($arg === '--apply') $apply = true;
    elseif ($arg === '--check') $apply = false;
    elseif (strpos($arg, '--file=') === 0) $file = substr($arg, 7);
    else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

function report($ok, $msg)
{
    echo ($ok ? '  OK   ' : '  FAIL ') . $msg . PHP_EOL;
    return $ok;
}

echo "=== AdapterMan 空 body JSON 修复 ===\n";
echo "文件 : {$file}\n";
echo "模式 : " . ($apply ? 'APPLY' : 'CHECK') . "\n\n";

if (!is_file($file)) {
    fwrite(STDERR, "找不到文件: {$file}\n");
    exit(1);
}
$src = file_get_contents($file);
$orig = $src;

$badPost = "'application/json' => \$_POST = \\json_decode(\$http_body, true, flags: \\JSON_THROW_ON_ERROR) ?? [],";
$badData = "'application/json' => \$data = \\json_decode(\$http_body, true, flags: \\JSON_THROW_ON_ERROR) ?? [],";

$goodPost = "// 本地修复：空 body / 非法 JSON 不抛 JsonException（否则会杀死 workerman worker，请求悬挂）\n"
    . "                'application/json' => \$_POST = ((\$http_body === '' || \$http_body === null) ? [] : (\\json_decode(\$http_body, true) ?: [])),";
$goodData = "'application/json' => \$data = ((\$http_body === '' || \$http_body === null) ? [] : (\\json_decode(\$http_body, true) ?: [])),";

$ok = true;
$changed = 0;

if (strpos($src, '空 body / 非法 JSON 不抛 JsonException') !== false) {
    echo "  --   已打过补丁，跳过\n";
} else {
    foreach ([[$badPost, $goodPost, 'POST 解析'], [$badData, $goodData, '其它方法解析']] as $pair) {
        list($bad, $good, $label) = $pair;
        $count = substr_count($src, $bad);
        if ($count !== 1) {
            report(false, "{$label}: 锚点命中 {$count} 次（要求 1 次）");
            $ok = false;
            continue;
        }
        $src = str_replace($bad, $good, $src);
        report(true, "{$label}: 已替换");
        $changed++;
    }
}

if (!$ok) {
    fwrite(STDERR, "\n锚点异常，未写入任何内容。\n");
    exit(1);
}

if ($changed === 0) {
    echo "\n无需变更。\n";
    exit(0);
}

if (!$apply) {
    echo "\n[CHECK] 未写入任何内容。加 --apply 执行。\n";
    exit(0);
}

$backup = $file . '.bak-' . date('Ymd-His');
if (!copy($file, $backup)) {
    fwrite(STDERR, "备份失败: {$backup}\n");
    exit(1);
}
if (file_put_contents($file, $src) === false) {
    fwrite(STDERR, "写入失败: {$file}\n");
    exit(1);
}

echo "\n备份 : {$backup}\n";
echo "已写入 {$file}\n";
echo "\n提示：composer install/update 之后需要重新执行本脚本；\n";
echo "      workerman 需 reload 才会生效（supervisorctl restart 或 kill -USR1 主进程）。\n";
