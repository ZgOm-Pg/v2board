<?php
/**
 * 后台「签到管理」/「活动弹窗」原生菜单与路由补丁（幂等、可检查）
 *
 * 背景：仓库只有 umi 编译产物 public/assets/admin/umi.js，没有管理前端源码，
 *       因此菜单项与路由以**最小、可复现**的补丁方式写入编译产物：
 *         - 菜单项插入到 umi 原生菜单数组（nav）中，紧跟「子账号管理」（没有则「用户管理」）；
 *         - 路由插入到 umi 原生 routes 数组（, u = [{）最前面，页面容器：
 *             #checkin-admin-root  （/checkin）
 *             #promotion-admin-root（/promotion）
 *       页面脚本 checkin-promotion-admin-page.js 只在这些容器内渲染，不做任何 DOM 注入。
 *
 * 用法：
 *   php tools/patch-checkin-promotion-admin.php --check
 *   php tools/patch-checkin-promotion-admin.php --apply
 *   php tools/patch-checkin-promotion-admin.php --apply --file=/path/to/umi.js
 *
 * 上游同步（git merge upstream/master）会覆盖 umi.js，需要重新执行 --apply。
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
$SUB_TITLE  = '\u5b50\u8d26\u53f7\u7ba1\u7406';   // 子账号管理
$USER_TITLE = '\u7528\u6237\u7ba1\u7406';         // 用户管理
$CHECKIN_TITLE   = '\u7b7e\u5230\u7ba1\u7406';    // 签到管理
$PROMOTION_TITLE = '\u6d3b\u52a8\u5f39\u7a97';    // 活动弹窗

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

echo "=== 后台「签到管理」「活动弹窗」原生菜单/路由补丁 ===\n";
echo "文件 : {$file}\n";
echo "大小 : " . strlen($src) . " 字节\n";
echo "模式 : " . ($apply ? 'APPLY' : 'CHECK') . "\n\n";

$hasCheckinMenu   = substr_count($src, 'href: "/checkin"') > 0;
$hasPromotionMenu = substr_count($src, 'href: "/promotion"') > 0;
$hasCheckinRoute  = substr_count($src, 'path: "/checkin"') > 0;
$hasPromotionRoute = substr_count($src, 'path: "/promotion"') > 0;

/* ------------------------------------------------------------------ 1. 菜单锚点 */
// 优先挂在「子账号管理」之后；未打子账号补丁时退化到「用户管理」之后
$anchorPatterns = [
    '子账号管理' => '/(?P<item>title: "' . preg_quote($SUB_TITLE, '/') . '",\s*\n'
        . '(?P<field>[ \t]*)type: "item",\s*\n'
        . '[ \t]*href: "\/sub-accounts",\s*\n'
        . '[ \t]*icon: o\.a\.createElement\("i", \{\s*\n'
        . '[ \t]*className: "nav-main-link-icon si si-user-follow"\s*\n'
        . '[ \t]*\}\)\s*\n'
        . '(?P<outer>[ \t]*)\}, \{)/',
    '用户管理' => '/(?P<item>title: "' . preg_quote($USER_TITLE, '/') . '",\s*\n'
        . '(?P<field>[ \t]*)type: "item",\s*\n'
        . '[ \t]*href: "\/user",\s*\n'
        . '[ \t]*icon: o\.a\.createElement\("i", \{\s*\n'
        . '[ \t]*className: "nav-main-link-icon si si-users"\s*\n'
        . '[ \t]*\}\)\s*\n'
        . '(?P<outer>[ \t]*)\}, \{)/',
];

$menuPattern = null;
$menuMatches = [];
$anchorName = null;
foreach ($anchorPatterns as $name => $pattern) {
    $matches = [];
    preg_match_all($pattern, $src, $matches, PREG_OFFSET_CAPTURE);
    $count = isset($matches[0]) ? count($matches[0]) : 0;
    if ($count === 1) {
        $menuPattern = $pattern;
        $menuMatches = $matches;
        $anchorName = $name;
        break;
    }
    if ($count > 1) {
        echo "--- 菜单锚点（{$name}）---\n";
        report(false, "命中 {$count} 次（要求恰好 1 次），拒绝继续");
        exit(1);
    }
}

echo "--- 菜单锚点 ---\n";
if ($menuPattern === null) {
    report(false, '未命中：umi.js 中既找不到「子账号管理」也找不到「用户管理」菜单项锚点');
    exit(1);
}
$menuCount = count($menuMatches[0]);
report(true, "命中「{$anchorName}」菜单项 1 次（偏移 " . $menuMatches[0][0][1] . '）');

/* ------------------------------------------------------------------ 2. 路由锚点 */
$routePattern = '/, u = \[\{\s*\n(?P<field>[ \t]*)path: "(?P<first>[^"]+)"/';
$routeMatches = [];
preg_match_all($routePattern, $src, $routeMatches, PREG_OFFSET_CAPTURE);
$routeCount = isset($routeMatches[0]) ? count($routeMatches[0]) : 0;

echo "\n--- 路由锚点（routes 数组起点）---\n";
if ($routeCount === 0) {
    report(false, '未命中：找不到 routes 数组起点 `, u = [{`');
    exit(1);
}
if ($routeCount > 1) {
    report(false, "命中 {$routeCount} 次（要求恰好 1 次），拒绝继续");
    exit(1);
}
$firstPath = $routeMatches['first'][0][0];
report(true, '恰好命中 1 次（偏移 ' . $routeMatches[0][0][1] . "，首个路由 path=\"{$firstPath}\"）");

echo "\n--- 当前状态 ---\n";
report(true, '菜单项 /checkin   ' . ($hasCheckinMenu ? '已存在（跳过）' : '不存在（将插入）'));
report(true, '菜单项 /promotion ' . ($hasPromotionMenu ? '已存在（跳过）' : '不存在（将插入）'));
report(true, '路由   /checkin   ' . ($hasCheckinRoute ? '已存在（跳过）' : '不存在（将插入）'));
report(true, '路由   /promotion ' . ($hasPromotionRoute ? '已存在（跳过）' : '不存在（将插入）'));

if ($hasCheckinMenu && $hasPromotionMenu && $hasCheckinRoute && $hasPromotionRoute) {
    echo "\n补丁已应用过，无需变更。\n";
    exit(0);
}

if (!$apply) {
    echo "\n[CHECK] 未写入任何内容。加 --apply 执行补丁。\n";
    exit(0);
}

/* ------------------------------------------------------------------ 3. 应用 */
$changed = false;
$insertedMenu = '';
$insertedRoute = '';

if (!$hasCheckinMenu || !$hasPromotionMenu) {
    $src = preg_replace_callback($menuPattern, function ($m) use (&$insertedMenu, $CHECKIN_TITLE, $PROMOTION_TITLE, $hasCheckinMenu, $hasPromotionMenu) {
        $field = $m['field'];
        $outer = $m['outer'];
        $item = $m['item'];
        $iconIndent = $field . '    ';

        $block = '';
        if (!$hasCheckinMenu) {
            $block .= $field . 'title: "' . $CHECKIN_TITLE . '",' . "\n"
                . $field . 'type: "item",' . "\n"
                . $field . 'href: "/checkin",' . "\n"
                . $field . 'icon: o.a.createElement("i", {' . "\n"
                . $iconIndent . 'className: "nav-main-link-icon si si-calendar"' . "\n"
                . $field . '})' . "\n"
                . $outer . '}, {' . "\n";
        }
        if (!$hasPromotionMenu) {
            $block .= $field . 'title: "' . $PROMOTION_TITLE . '",' . "\n"
                . $field . 'type: "item",' . "\n"
                . $field . 'href: "/promotion",' . "\n"
                . $field . 'icon: o.a.createElement("i", {' . "\n"
                . $iconIndent . 'className: "nav-main-link-icon si si-bell"' . "\n"
                . $field . '})' . "\n"
                . $outer . '}, {';
        }

        $insertedMenu = $block;
        return $item . "\n" . $block;
    }, $src, 1);
    $changed = true;
    echo "  [菜单] 已在「{$anchorName}」之后插入「签到管理」「活动弹窗」\n";
}

if (!$hasCheckinRoute || !$hasPromotionRoute) {
    $src = preg_replace_callback($routePattern, function ($m) use (&$insertedRoute, $CHECKIN_TITLE, $PROMOTION_TITLE, $firstPath) {
        $p = $m['field'];
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
        $insertedRoute = $body;
        return ', u = [{' . "\n" . $body;
    }, $src, 1);
    $changed = true;
    echo "  [路由] 已插入 /checkin 与 /promotion 原生路由\n";
}

/* ------------------------------------------------------------------ 4. 校验 */
$markers = [
    '菜单 href: "/checkin"' => ['href: "/checkin"', 1],
    '菜单 href: "/promotion"' => ['href: "/promotion"', 1],
    '路由 path: "/checkin"' => ['path: "/checkin"', 1],
    '路由 path: "/promotion"' => ['path: "/promotion"', 1],
    '容器 checkin-admin-root' => ['id: "checkin-admin-root"', 1],
    '容器 promotion-admin-root' => ['id: "promotion-admin-root"', 1],
    '图标 si si-calendar' => ['si si-calendar', 1],
    '图标 si si-bell' => ['si si-bell', 1],
];

echo "\n--- 写入前校验 ---\n";
$ok = true;
foreach ($markers as $label => $spec) {
    $count = substr_count($src, $spec[0]);
    $ok = report($count === $spec[1], "{$label} 出现 {$count} 次（应为 {$spec[1]}）") && $ok;
}
if ($insertedMenu !== '') {
    $ok = report(substr_count($src, $insertedMenu) === 1, '插入的菜单片段可原样定位') && $ok;
}
if ($insertedRoute !== '') {
    $ok = report(substr_count($src, $insertedRoute) === 1, '插入的路由片段可原样定位') && $ok;
}
if (!$ok) {
    fwrite(STDERR, "\n校验失败，未写入任何内容。\n");
    exit(1);
}

/* ------------------------------------------------------------------ 5. 写入 */
$backup = $file . '.bak-' . date('Ymd-His');
if (!copy($file, $backup)) {
    fwrite(STDERR, "备份失败: {$backup}\n");
    exit(1);
}
if (file_put_contents($file, $src) === false) {
    fwrite(STDERR, "写入失败: {$file}\n");
    exit(1);
}

echo "\n备份   : {$backup}\n";
echo '已写入 : ' . $file . '（' . strlen($orig ?? '') . ' -> ' . strlen($src) . ' 字节）' . PHP_EOL;
echo "\n完成。请在后台硬刷新（Ctrl+Shift+R）后确认左侧出现「签到管理」「活动弹窗」，\n";
echo "并确认直接访问 #/checkin 与 #/promotion 可正常打开。\n";
