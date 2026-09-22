<?php
/**
 * 后台「签到管理」/「活动弹窗」原生菜单与路由补丁（分组感知 · 幂等 · 可原位迁移）
 *
 * 背景：仓库只有 umi 编译产物 public/assets/admin/umi.js，没有管理前端源码。
 *       菜单项与路由以最小、可复现的补丁方式写入编译产物。
 *
 * 菜单归属（本版要求）：
 *   「签到管理」(/checkin) 与「活动弹窗」(/promotion) 不属于「用户」分组，
 *   而是独立分组「活动运营」，位置在「用户」分组现有项目结束之后、「指标」分组之前：
 *
 *       heading 用户
 *         用户管理 / 子账号管理 / 公告管理 / 工单管理 / 知识库管理
 *       heading 活动运营        <- 本脚本维护
 *         签到管理 / 活动弹窗
 *       heading 指标
 *
 * 三种输入都能得到同一结果：
 *   1) 已打过旧补丁（两个菜单项挂在「用户」分组里）  -> 原位迁移到「活动运营」
 *   2) 完全未打补丁的上游 umi.js（没有这两个菜单项）  -> 直接生成正确分组
 *   3) 已经是正确结构                                -> 不做任何修改
 *
 * 用法：
 *   php tools/patch-checkin-promotion-admin.php --check
 *   php tools/patch-checkin-promotion-admin.php --apply
 *   php tools/patch-checkin-promotion-admin.php --apply --file=/path/to/umi.js
 *
 * --check 校验的是**实际分组位置**（不只是 href/path 是否存在），结构不正确时退出码为 1。
 * 上游同步会覆盖 umi.js，需要重新执行 --apply。
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

$apply = false;
$file = $root . '/public/assets/admin/umi.js';
foreach ($args as $arg) {
    if ($arg === '--apply') $apply = true;
    elseif ($arg === '--check') $apply = false;
    elseif (strpos($arg, '--file=') === 0) $file = substr($arg, 7);
    else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

// umi 产物中的中文是 \uXXXX 字面量，必须用 preg_quote 转义，避免 PCRE2 把 \u 当非法转义
$CHECKIN_TITLE   = '\u7b7e\u5230\u7ba1\u7406';   // 签到管理
$PROMOTION_TITLE = '\u6d3b\u52a8\u5f39\u7a97';   // 活动弹窗
$GROUP_TITLE     = '\u6d3b\u52a8\u8fd0\u8425';   // 活动运营（新分组）
$USER_TITLE      = '\u7528\u6237';               // 用户（用于判断是否仍在该分组内）
$METRIC_TITLE    = '\u6307\u6807';               // 指标（新分组插入锚点）

if (!is_file($file)) {
    fwrite(STDERR, "找不到文件: {$file}\n");
    exit(1);
}
$src = file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "读取失败: {$file}\n");
    exit(1);
}
$orig = $src;

function report($ok, $msg)
{
    echo ($ok ? '  OK   ' : '  FAIL ') . $msg . PHP_EOL;
    return $ok;
}

/** 菜单项块：title + type:item + href + icon，含结尾的 `}, {` 分隔符 */
function itemPattern($title, $href, $icon)
{
    return '/(?P<item>title: "' . preg_quote($title, '/') . '",\s*\n'
        . '(?P<field>[ \t]*)type: "item",\s*\n'
        . '[ \t]*href: "' . preg_quote($href, '/') . '",\s*\n'
        . '[ \t]*icon: o\.a\.createElement\("i", \{\s*\n'
        . '[ \t]*className: "nav-main-link-icon ' . preg_quote($icon, '/') . '"\s*\n'
        . '[ \t]*\}\)\s*\n'
        . '(?P<outer>[ \t]*)\}, \{)/';
}

/** heading 块：title + type:heading，含结尾的 `}, {` 分隔符 */
function headingPattern($title)
{
    return '/(?P<item>(?P<field>[ \t]*)title: "' . preg_quote($title, '/') . '",\s*\n'
        . '[ \t]*type: "heading"\s*\n'
        . '(?P<outer>[ \t]*)\}, \{)/';
}

function buildItem($title, $href, $icon, $field, $outer)
{
    return $field . 'title: "' . $title . '",' . "\n"
        . $field . 'type: "item",' . "\n"
        . $field . 'href: "' . $href . '",' . "\n"
        . $field . 'icon: o.a.createElement("i", {' . "\n"
        . $field . '    className: "nav-main-link-icon ' . $icon . '"' . "\n"
        . $field . '})' . "\n"
        . $outer . '}, {';
}

function buildHeading($title, $field, $outer)
{
    return $field . 'title: "' . $title . '",' . "\n"
        . $field . 'type: "heading"' . "\n"
        . $outer . '}, {';
}

function countMatches($pattern, $src)
{
    preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE);
    return isset($m[0]) ? count($m[0]) : 0;
}

function firstMatch($pattern, $src)
{
    if (preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
        return $m;
    }
    return null;
}

function lineOf($src, $offset)
{
    return substr_count(substr($src, 0, $offset), "\n") + 1;
}

/**
 * 打印导航分组结构（诊断/报告用）：heading 与其后的菜单项 href。
 */
function describeNav($src)
{
    $plain = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($m) {
        return mb_convert_encoding(pack('n', hexdec($m[1])), 'UTF-8', 'UTF-16BE');
    }, $src);
    preg_match_all('/(?P<t>title: "(?P<title>[^"]+)",\s*\n[ \t]*type: "(?P<kind>heading|item)"(?:,\s*\n[ \t]*href: "(?P<href>[^"]+)")?)/',
        $plain, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $one) {
        if ($one['kind'] === 'heading') {
            if (!empty($out)) $out[] = '';
            $out[] = '  [分组] ' . $one['title'];
        } else {
            $out[] = '         - ' . $one['title'] . '  ' . (isset($one['href']) ? $one['href'] : '');
        }
    }
    return implode("\n", $out);
}

echo "=== 后台「签到管理 / 活动弹窗」菜单分组与路由补丁 ===\n";
echo "文件 : {$file}\n";
echo "大小 : " . strlen($src) . " 字节\n";
echo "模式 : " . ($apply ? 'APPLY' : 'CHECK') . "\n\n";

/* ------------------------------------------------------------------ 1. 定位 */

$checkinPattern   = itemPattern($CHECKIN_TITLE, '/checkin', 'si si-calendar');
$promotionPattern = itemPattern($PROMOTION_TITLE, '/promotion', 'si si-bell');
$groupPattern     = headingPattern($GROUP_TITLE);
$metricPattern    = headingPattern($METRIC_TITLE);
$userHeadingPattern = headingPattern($USER_TITLE);

$checkinCount   = countMatches($checkinPattern, $src);
$promotionCount = countMatches($promotionPattern, $src);
$groupCount     = countMatches($groupPattern, $src);
$metricCount    = countMatches($metricPattern, $src);
$routeCheckinCount   = substr_count($src, 'path: "/checkin"');
$routePromotionCount = substr_count($src, 'path: "/promotion"');

echo "--- 当前状态 ---\n";
report($checkinCount <= 1, "菜单项「签到管理」出现 {$checkinCount} 次" . ($checkinCount > 1 ? '（重复，需人工处理）' : ''));
report($promotionCount <= 1, "菜单项「活动弹窗」出现 {$promotionCount} 次" . ($promotionCount > 1 ? '（重复，需人工处理）' : ''));
report($groupCount <= 1, "分组「活动运营」出现 {$groupCount} 次" . ($groupCount > 1 ? '（重复，需人工处理）' : ''));
report($routeCheckinCount <= 1, "路由 path: \"/checkin\" 出现 {$routeCheckinCount} 次");
report($routePromotionCount <= 1, "路由 path: \"/promotion\" 出现 {$routePromotionCount} 次");
echo "\n--- 导航分组现状 ---\n";
echo describeNav($src) . "\n";

if ($checkinCount > 1 || $promotionCount > 1 || $groupCount > 1
    || $routeCheckinCount > 1 || $routePromotionCount > 1) {
    fwrite(STDERR, "\n存在重复项，请先人工清理（脚本不做自动去重）。\n");
    exit(1);
}

if ($metricCount !== 1) {
    report(false, "「指标」分组锚点命中 {$metricCount} 次（要求 1 次），无法确定「活动运营」分组位置");
    exit(1);
}
$metric = firstMatch($metricPattern, $src);
$fieldIndent = $metric['field'][0];
$outerIndent = $metric['outer'][0];
report(true, '「指标」分组锚点唯一（第 ' . lineOf($src, $metric[0][1]) . ' 行）');

/* -------------------------------------------------- 2. 判断当前分组位置是否正确 */

$groupBlock     = buildHeading($GROUP_TITLE, $fieldIndent, $outerIndent);
$checkinBlock   = buildItem($CHECKIN_TITLE, '/checkin', 'si si-calendar', $fieldIndent, $outerIndent);
$promotionBlock = buildItem($PROMOTION_TITLE, '/promotion', 'si si-bell', $fieldIndent, $outerIndent);
$expectedRegion = $groupBlock . "\n" . $checkinBlock . "\n" . $promotionBlock;

$structureOk = ($groupCount === 1 && $checkinCount === 1 && $promotionCount === 1)
    && (strpos($src, $expectedRegion) !== false);

// 「用户」分组内不得残留这两个菜单：
// 区段取「用户」heading 到**其后第一个 heading**（不能用「指标」，因为中间现在有「活动运营」分组）
$userOwned = false;
$userHeading = firstMatch($userHeadingPattern, $src);
if ($userHeading) {
    $userStart = $userHeading[0][1];
    $anyHeading = '/(?P<item>[ \t]*title: "[^"]+",\s*\n[ \t]*type: "heading"\s*\n[ \t]*\}, \{)/';
    $nextHeadingAt = null;
    if (preg_match_all($anyHeading, $src, $hm, PREG_OFFSET_CAPTURE, $userStart + 10)) {
        foreach ($hm[0] as $one) {
            if ($one[1] > $userStart) {
                $nextHeadingAt = $one[1];
                break;
            }
        }
    }
    $userEnd = $nextHeadingAt !== null ? $nextHeadingAt : strlen($src);
    $userRegion = substr($src, $userStart, $userEnd - $userStart);
    $userOwned = (strpos($userRegion, 'href: "/checkin"') !== false)
        || (strpos($userRegion, 'href: "/promotion"') !== false);
}

$routesOk = ($routeCheckinCount === 1 && $routePromotionCount === 1);

echo "\n--- 分组结构检查 ---\n";
report(!$userOwned, $userOwned ? '「签到管理/活动弹窗」仍在「用户」分组内（需要迁移）' : '「用户」分组内不含这两个菜单');
report($structureOk, $structureOk ? '「活动运营」分组紧随其后为「签到管理」「活动弹窗」' : '「活动运营」分组/菜单顺序不正确');
report($routesOk, $routesOk ? '两条路由各出现 1 次' : "路由数量异常（checkin={$routeCheckinCount}, promotion={$routePromotionCount}）");

$needMenuWork = !($structureOk && !$userOwned);
$needRouteWork = !$routesOk;

if (!$needMenuWork && !$needRouteWork) {
    echo "\n结构正确，无需变更。\n";
    exit(0);
}

if (!$apply) {
    echo "\n[CHECK] 需要变更（菜单=" . ($needMenuWork ? '是' : '否') . "，路由=" . ($needRouteWork ? '是' : '否') . "）。加 --apply 执行。\n";
    exit(1);
}

/* ------------------------------------------------------------------ 3. 迁移/插入菜单 */

$changed = [];

if ($needMenuWork) {
    // 3.1 移除旧块（旧分组中的菜单项；以及位置不正确的「活动运营」heading）
    if ($checkinCount === 1) $src = preg_replace($checkinPattern, '', $src, 1);
    if ($promotionCount === 1) $src = preg_replace($promotionPattern, '', $src, 1);
    if ($groupCount === 1) $src = preg_replace($groupPattern, '', $src, 1);

    // 3.2 在「指标」heading 之前插入：活动运营 heading + 两个菜单项
    $metric = firstMatch($metricPattern, $src);
    if (!$metric) {
        fwrite(STDERR, "移除旧块后找不到「指标」分组锚点，已中止（未写入）。\n");
        exit(1);
    }
    $insertAt = $metric[0][1];
    $src = substr($src, 0, $insertAt) . $expectedRegion . "\n" . substr($src, $insertAt);
    $changed[] = '菜单分组迁移到「活动运营」';
}

/* ------------------------------------------------------------------ 4. 路由 */

if ($needRouteWork) {
    $routePattern = '/, u = \[\{\s*\n(?P<field>[ \t]*)path: "(?P<first>[^"]+)"/';
    $routeMatches = [];
    preg_match_all($routePattern, $src, $routeMatches, PREG_OFFSET_CAPTURE);
    $routeCount = isset($routeMatches[0]) ? count($routeMatches[0]) : 0;
    if ($routeCount !== 1) {
        fwrite(STDERR, "路由锚点命中 {$routeCount} 次（要求 1 次），已中止（未写入）。\n");
        exit(1);
    }
    $firstPath = $routeMatches['first'][0][0];
    $p = $routeMatches['field'][0][0];

    $body = '';
    $body .= $p . 'path: "/checkin",' . "\n"
        . $p . 'exact: !0,' . "\n"
        . $p . 'component: function(e) {' . "\n"
        . $p . '    return i.a.createElement(n("Bl7J")["a"], Object.assign({}, e, {' . "\n"
        . $p . '        title: "' . $CHECKIN_TITLE . '"' . "\n"
        . $p . '    }), i.a.createElement("div", {' . "\n"
        . $p . '        id: "checkin-admin-root"' . "\n"
        . $p . '    }))' . "\n"
        . $p . '}' . "\n"
        . $p . '}, {' . "\n";
    $body .= $p . 'path: "/promotion",' . "\n"
        . $p . 'exact: !0,' . "\n"
        . $p . 'component: function(e) {' . "\n"
        . $p . '    return i.a.createElement(n("Bl7J")["a"], Object.assign({}, e, {' . "\n"
        . $p . '        title: "' . $PROMOTION_TITLE . '"' . "\n"
        . $p . '    }), i.a.createElement("div", {' . "\n"
        . $p . '        id: "promotion-admin-root"' . "\n"
        . $p . '    }))' . "\n"
        . $p . '}' . "\n"
        . $p . '}, {' . "\n";
    $body .= $p . 'path: "' . $firstPath . '"';

    $src = preg_replace($routePattern, ', u = [{' . "\n" . $body, $src, 1);
    $changed[] = '路由 /checkin 与 /promotion';
}

/* ---------------------------------------------------- 5. 写入前校验（结构级） */

$postCheck = function ($text) use ($checkinPattern, $promotionPattern, $groupPattern, $expectedRegion) {
    preg_match_all($checkinPattern, $text, $a);
    preg_match_all($promotionPattern, $text, $b);
    preg_match_all($groupPattern, $text, $c);
    return [
        'checkin' => count($a[0]),
        'promotion' => count($b[0]),
        'group' => count($c[0]),
        'region' => strpos($text, $expectedRegion) !== false,
        'routes' => substr_count($text, 'path: "/checkin"') . '/' . substr_count($text, 'path: "/promotion"')
    ];
};

echo "\n--- 写入前校验 ---\n";
$ok = true;
$c = $postCheck($src);
$ok = report($c['group'] === 1, "分组「活动运营」= {$c['group']}（应为 1）") && $ok;
$ok = report($c['checkin'] === 1, "菜单项「签到管理」= {$c['checkin']}（应为 1）") && $ok;
$ok = report($c['promotion'] === 1, "菜单项「活动弹窗」= {$c['promotion']}（应为 1）") && $ok;
$ok = report($c['region'] === true, '分组与菜单顺序正确（heading 后紧跟两个菜单项）') && $ok;
$ok = report($c['routes'] === '1/1', "路由出现次数 {$c['routes']}（应为 1/1）") && $ok;

if (!$ok) {
    fwrite(STDERR, "\n校验失败，未写入任何内容。\n");
    exit(1);
}

/* ------------------------------------------------------------------ 6. 写入 */

$backup = $file . '.bak-' . date('Ymd-His');
if (!copy($file, $backup)) {
    fwrite(STDERR, "备份失败: {$backup}\n");
    exit(1);
}
if (file_put_contents($file, $src) === false) {
    fwrite(STDERR, "写入失败: {$file}\n");
    exit(1);
}

echo "\n变更 : " . implode('、', $changed) . "\n";
echo "备份 : {$backup}\n";
echo '已写入: ' . $file . '（' . strlen($orig) . ' -> ' . strlen($src) . ' 字节）' . PHP_EOL;
echo "\n请在后台硬刷新（Ctrl+Shift+R）后确认左侧「活动运营」分组下有「签到管理」「活动弹窗」，\n";
echo "并确认直接访问 #/checkin 与 #/promotion 正常。\n";
