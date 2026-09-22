<?php
/**
 * 后台「子账号管理」原生菜单与路由补丁（幂等 · 支持路径迁移）
 *
 * 页面路径：/subaccounts（无连字符；旧版为 /sub-accounts）
 *   - 菜单项插入 umi 原生菜单数组（nav），紧跟「用户管理」之后；
 *   - 路由插入 umi 原生 routes 数组最前，页面容器 #subaccount-admin-root。
 *
 * 三种输入都能得到同一结果：
 *   1) 已打过旧版补丁（href/path 为 /sub-accounts） -> **原位迁移**为 /subaccounts（不重复插入）
 *   2) 完全未打补丁的上游产物（没有该菜单/路由）    -> 直接插入
 *   3) 已是 /subaccounts                            -> 不做任何修改
 *
 * 用法：
 *   php tools/patch-subaccount-admin.php --check
 *   php tools/patch-subaccount-admin.php --apply
 *   php tools/patch-subaccount-admin.php --apply --file=/path/to/umi.js
 *
 * --check 校验的是**实际路径状态**（新路径存在且唯一、旧路径必须已消失），不正确时退出码为 1。
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

$MENU_HREF = '/subaccounts';          // 新路径
$OLD_MENU_HREF = '/sub-accounts';     // 旧路径（需要迁移）
$MENU_TITLE = '\u5b50\u8d26\u53f7\u7ba1\u7406';   // 子账号管理
$USER_TITLE = '\u7528\u6237\u7ba1\u7406';         // 用户管理

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

/** 菜单项块（title + type:item + href + icon），含结尾 `}, {` */
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

echo "=== 子账号后台原生菜单/路由补丁（路径 {$MENU_HREF}）===\n";
echo "文件   : {$file}\n";
echo "大小   : " . strlen($src) . " 字节\n";
echo "模式   : " . ($apply ? 'APPLY' : 'CHECK') . "\n\n";

/* ------------------------------------------------------------------ 1. 定位锚点 */
// 注意：模式里包含 umi 产物中的字面量 "\u7528\u6237\u7ba1\u7406"，
// 必须用 preg_quote 转义反斜杠，否则 PCRE2 会把 \u 当作非法转义。
$menuPattern = '/(?P<item>title: "' . preg_quote($USER_TITLE, '/') . '",\s*\n'
    . '(?P<field>[ \t]*)type: "item",\s*\n'
    . '[ \t]*href: "\/user",\s*\n'
    . '[ \t]*icon: o\.a\.createElement\("i", \{\s*\n'
    . '[ \t]*className: "nav-main-link-icon si si-users"\s*\n'
    . '[ \t]*\}\)\s*\n'
    . '(?P<outer>[ \t]*)\}, \{)/';

$routePattern = '/, u = \[\{\s*\n(?P<field>[ \t]*)path: "(?P<first>[^"]+)"/';

$menuCount = preg_match_all($menuPattern, $src, $menuMatches, PREG_OFFSET_CAPTURE);
$routeCount = preg_match_all($routePattern, $src, $routeMatches, PREG_OFFSET_CAPTURE);

echo "--- 锚点 ---\n";
report($menuCount === 1, "「用户管理」菜单项锚点命中 {$menuCount} 次（插入场景要求 1 次）");
report($routeCount === 1, "routes 数组起点锚点命中 {$routeCount} 次（插入场景要求 1 次）"
    . ($routeCount === 1 ? "，首个路由 path=\"{$routeMatches['first'][0][0]}\"" : ''));

/* ------------------------------------------------------------------ 2. 当前状态 */
$newMenuCount = substr_count($src, 'href: "' . $MENU_HREF . '"');
$newRouteCount = substr_count($src, 'path: "' . $MENU_HREF . '"');
$oldMenuCount = substr_count($src, 'href: "' . $OLD_MENU_HREF . '"');
$oldRouteCount = substr_count($src, 'path: "' . $OLD_MENU_HREF . '"');
$containerCount = substr_count($src, 'id: "subaccount-admin-root"');
$iconCount = substr_count($src, 'si si-user-follow');
$rawOldCount = substr_count($src, $OLD_MENU_HREF);

echo "\n--- 当前状态 ---\n";
report($newMenuCount <= 1, "菜单 href \"{$MENU_HREF}\" = {$newMenuCount}");
report($newRouteCount <= 1, "路由 path \"{$MENU_HREF}\" = {$newRouteCount}");
report($oldMenuCount <= 1, "旧菜单 href \"{$OLD_MENU_HREF}\" = {$oldMenuCount}");
report($oldRouteCount <= 1, "旧路由 path \"{$OLD_MENU_HREF}\" = {$oldRouteCount}");
if ($rawOldCount > $oldMenuCount + $oldRouteCount) {
    echo "  注意：除菜单/路由外还有 " . ($rawOldCount - $oldMenuCount - $oldRouteCount)
        . " 处 \"{$OLD_MENU_HREF}\" 字符串（本脚本不改动它们，如需一并替换请人工处理）\n";
}

$migrate = ($oldMenuCount === 1 || $oldRouteCount === 1);
$needInsert = (!$migrate && ($newMenuCount === 0 || $newRouteCount === 0));

if ($migrate) {
    echo "\n--- 判定 ---\n  检测到旧路径 {$OLD_MENU_HREF}，将原位迁移为 {$MENU_HREF}\n";
} elseif ($needInsert) {
    echo "\n--- 判定 ---\n  未检测到该菜单/路由，将按新路径插入\n";
} else {
    echo "\n--- 判定 ---\n  已是新路径且数量正确\n";
}

$alreadyOk = (!$migrate && $newMenuCount === 1 && $newRouteCount === 1
    && $containerCount === 1 && $iconCount === 1);

if ($alreadyOk) {
    echo "\n结构正确，无需变更。\n";
    exit(0);
}

if (!$apply) {
    echo "\n[CHECK] 需要变更" . ($migrate ? '（旧路径迁移）' : '（插入缺失项）') . "。加 --apply 执行。\n";
    exit(1);
}

if ($newMenuCount > 1 || $newRouteCount > 1 || $oldMenuCount > 1 || $oldRouteCount > 1) {
    fwrite(STDERR, "\n存在重复项，请先人工清理（脚本不做自动去重）。\n");
    exit(1);
}
if ($needInsert && ($menuCount !== 1 || $routeCount !== 1)) {
    fwrite(STDERR, "\n插入场景但锚点异常，已中止（未写入）。\n");
    exit(1);
}

/* ------------------------------------------------------------------ 3. 迁移旧路径 */
$changed = [];
if ($migrate) {
    if ($oldMenuCount === 1) {
        $src = str_replace('href: "' . $OLD_MENU_HREF . '"', 'href: "' . $MENU_HREF . '"', $src);
    }
    if ($oldRouteCount === 1) {
        $src = str_replace('path: "' . $OLD_MENU_HREF . '"', 'path: "' . $MENU_HREF . '"', $src);
    }
    $changed[] = "菜单/路由路径 {$OLD_MENU_HREF} -> {$MENU_HREF}";
    echo "  [迁移] 已把菜单与路由路径改为 {$MENU_HREF}\n";
}

/* ------------------------------------------------------------------ 4. 插入缺失项 */
$insertedMenu = '';
$insertedRoute = '';

if (substr_count($src, 'href: "' . $MENU_HREF . '"') === 0) {
    $src = preg_replace_callback($menuPattern, function ($m) use (&$insertedMenu, $MENU_HREF) {
        $field = $m['field'];
        $outer = $m['outer'];
        $item = $m['item'];
        $iconIndent = $field . '    ';
        $insertedMenu = $field . 'title: "\u5b50\u8d26\u53f7\u7ba1\u7406",' . "\n"
            . $field . 'type: "item",' . "\n"
            . $field . 'href: "' . $MENU_HREF . '",' . "\n"
            . $field . 'icon: o.a.createElement("i", {' . "\n"
            . $iconIndent . 'className: "nav-main-link-icon si si-user-follow"' . "\n"
            . $field . '})';
        return $item . "\n" . $insertedMenu . "\n" . $outer . '}, {';
    }, $src, 1);
    $changed[] = '菜单项「子账号管理」';
    echo "  [菜单] 已插入「子账号管理」（href: {$MENU_HREF}，icon: si si-user-follow）\n";
}

if (substr_count($src, 'path: "' . $MENU_HREF . '"') === 0) {
    $src = preg_replace_callback($routePattern, function ($m) use (&$insertedRoute, $MENU_HREF) {
        $p = $m['field'];
        $first = $m['first'];
        $body = $p . 'path: "' . $MENU_HREF . '",' . "\n"
            . $p . 'exact: !0,' . "\n"
            . $p . 'component: function(e) {' . "\n"
            . $p . '    return i.a.createElement(n("Bl7J")["a"], Object.assign({}, e, {' . "\n"
            . $p . '        title: "\u5b50\u8d26\u53f7\u7ba1\u7406"' . "\n"
            . $p . '    }), i.a.createElement("div", {' . "\n"
            . $p . '        id: "subaccount-admin-root"' . "\n"
            . $p . '    }))' . "\n"
            . $p . '}' . "\n"
            . $p . '}, {' . "\n"
            . $p . 'path: "' . $first . '"';
        $insertedRoute = $body;
        return ', u = [{' . "\n" . $body;
    }, $src, 1);
    $changed[] = "路由 {$MENU_HREF}";
    echo "  [路由] 已插入 {$MENU_HREF} 原生路由（容器 #subaccount-admin-root）\n";
}

/* ------------------------------------------------------------------ 5. 写入前校验 */
echo "\n--- 写入前校验 ---\n";
$ok = true;
$newMenuAfter = substr_count($src, 'href: "' . $MENU_HREF . '"');
$newRouteAfter = substr_count($src, 'path: "' . $MENU_HREF . '"');
$oldMenuAfter = substr_count($src, 'href: "' . $OLD_MENU_HREF . '"');
$oldRouteAfter = substr_count($src, 'path: "' . $OLD_MENU_HREF . '"');
$containerAfter = substr_count($src, 'id: "subaccount-admin-root"');
$iconAfter = substr_count($src, 'si si-user-follow');

$ok = report($newMenuAfter === 1, "菜单 href \"{$MENU_HREF}\" = {$newMenuAfter}（应为 1）") && $ok;
$ok = report($newRouteAfter === 1, "路由 path \"{$MENU_HREF}\" = {$newRouteAfter}（应为 1）") && $ok;
$ok = report($oldMenuAfter === 0, "旧菜单 href \"{$OLD_MENU_HREF}\" = {$oldMenuAfter}（应为 0）") && $ok;
$ok = report($oldRouteAfter === 0, "旧路由 path \"{$OLD_MENU_HREF}\" = {$oldRouteAfter}（应为 0）") && $ok;
$ok = report($containerAfter === 1, "页面容器 id \"subaccount-admin-root\" = {$containerAfter}（应为 1）") && $ok;
$ok = report($iconAfter === 1, "菜单图标 si si-user-follow = {$iconAfter}（应为 1）") && $ok;
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

echo "\n变更   : " . implode('、', $changed) . "\n";
echo "备份   : {$backup}\n";
echo "已写入 : {$file}（" . strlen($orig) . " -> " . strlen($src) . " 字节）\n";
echo "\n完成。请在后台硬刷新（Ctrl+Shift+R）后确认菜单「子账号管理」可打开 #{$MENU_HREF}。\n";
exit(0);
