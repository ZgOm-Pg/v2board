<?php
/**
 * 子账号功能 —— 后台原生菜单与路由补丁（umi.js）
 *
 * 用途：
 *   把「子账号管理」作为**原生**菜单项与**原生**路由写进后台 SPA 的编译产物
 *   public/assets/admin/umi.js，使页面由 umi 的菜单组件与路由表渲染，
 *   不再依赖任何 DOM 注入 / 菜单克隆 / 遮罩层。
 *
 * 用法（在站点根目录执行）：
 *   php tools/patch-subaccount-admin.php --check     # 只检查锚点与当前状态
 *   php tools/patch-subaccount-admin.php --apply     # 幂等应用补丁（自动备份）
 *   php tools/patch-subaccount-admin.php --apply --file=public/assets/admin/umi.js
 *
 * 兼容：
 *   - wyx2685/v2board 基线产物（umi.js 中 routes 数组以 /config/payment 开头）
 *   - codeman857/v3board 产物（routes 数组以 /newPeriodLog 开头）
 *   两套产物的菜单项与路由表结构一致，因此锚点规则相同。
 *
 * 幂等与安全：
 *   - 菜单锚点：`title: "\u7528\u6237\u7ba1\u7406" … href: "/user" … si si-users`
 *     必须**恰好命中一次**，否则立即失败退出（不写入任何内容）；
 *   - 路由锚点：routes 数组起点 `, u = [{` + 紧随其后的第一个 `path: "…"`，
 *     同样要求恰好命中一次；
 *   - 已包含 `href: "/sub-accounts"` / `path: "/sub-accounts"` 时跳过对应部分（幂等）；
 *   - 仅对命中区间做插入，不做全文替换；
 *   - 写入前自动备份 umi.js.bak-<时间戳>；
 *   - 写入后复核：两处标记各恰好 1 次、大括号数量平衡。
 *
 * 上游同步后重新应用：
 *   git 拉取到新版本后，umi.js 会被覆盖为未打补丁的产物，
 *   重新执行 `php tools/patch-subaccount-admin.php --apply` 即可；
 *   若锚点不匹配（前端结构变更），脚本会明确报错，先按提示核对新产物中的
 *   菜单项与路由表结构，再更新本脚本的锚点规则。
 */

$root = dirname(__DIR__);
$args = $argv;
array_shift($args);

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

$MENU_HREF = '/sub-accounts';
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

echo "=== 子账号后台原生菜单/路由补丁 ===\n";
echo "文件   : {$file}\n";
echo "大小   : " . strlen($src) . " 字节\n";
echo "模式   : " . ($apply ? 'APPLY' : 'CHECK') . "\n\n";

/* ------------------------------------------------------------------ 1. 菜单锚点 */
// 注意：模式里包含 umi 产物中的字面量 "\u7528\u6237\u7ba1\u7406"，
// 必须用 preg_quote 转义反斜杠，否则 PCRE2 会把 \u 当作非法转义。
$menuPattern = '/(?P<item>title: "' . preg_quote($USER_TITLE, '/') . '",\s*\n'
    . '(?P<field>[ \t]*)type: "item",\s*\n'
    . '[ \t]*href: "\/user",\s*\n'
    . '[ \t]*icon: o\.a\.createElement\("i", \{\s*\n'
    . '[ \t]*className: "nav-main-link-icon si si-users"\s*\n'
    . '[ \t]*\}\)\s*\n'
    . '(?P<outer>[ \t]*)\}, \{)/';

$menuMatches = [];
preg_match_all($menuPattern, $src, $menuMatches, PREG_OFFSET_CAPTURE);
$menuCount = isset($menuMatches[0]) ? count($menuMatches[0]) : 0;

echo "--- 菜单锚点（用户管理菜单项）---\n";
if ($menuCount === 0) {
    report(false, '未命中：umi.js 中找不到「用户管理」菜单项锚点');
} elseif ($menuCount > 1) {
    report(false, "命中 {$menuCount} 次（要求恰好 1 次），拒绝继续");
} else {
    report(true, '恰好命中 1 次（偏移 ' . $menuMatches[0][0][1] . '）');
}

/* ------------------------------------------------------------------ 2. 路由锚点 */
$routePattern = '/, u = \[\{\s*\n(?P<field>[ \t]*)path: "(?P<first>[^"]+)"/';
$routeMatches = [];
preg_match_all($routePattern, $src, $routeMatches, PREG_OFFSET_CAPTURE);
$routeCount = isset($routeMatches[0]) ? count($routeMatches[0]) : 0;

echo "\n--- 路由锚点（routes 数组起点）---\n";
if ($routeCount === 0) {
    report(false, '未命中：找不到 routes 数组起点 `, u = [{`');
} elseif ($routeCount > 1) {
    report(false, "命中 {$routeCount} 次（要求恰好 1 次），拒绝继续");
} else {
    report(true, '恰好命中 1 次（偏移 ' . $routeMatches[0][0][1] . '，首个路由 path="' . $routeMatches['first'][0][0] . '"）');
}

/* ------------------------------------------------------------------ 3. 当前状态 */
$hasMenu = strpos($src, 'href: "' . $MENU_HREF . '"') !== false;
$hasRoute = strpos($src, 'path: "' . $MENU_HREF . '"') !== false;
echo "\n--- 当前状态 ---\n";
report(true, '菜单项 ' . ($hasMenu ? '已存在（跳过）' : '不存在（将插入）'));
report(true, '路由   ' . ($hasRoute ? '已存在（跳过）' : '不存在（将插入）'));

if (!$apply) {
    echo "\n[CHECK] 未写入任何内容。加 --apply 执行补丁。\n";
    exit(($menuCount === 1 || $hasMenu) && ($routeCount === 1 || $hasRoute) ? 0 : 1);
}

if ($menuCount !== 1 && !$hasMenu) {
    fwrite(STDERR, "\n菜单锚点异常，已中止（未写入）。\n");
    exit(1);
}
if ($routeCount !== 1 && !$hasRoute) {
    fwrite(STDERR, "\n路由锚点异常，已中止（未写入）。\n");
    exit(1);
}

/* ------------------------------------------------------------------ 4. 应用 */
$changed = false;
$insertedMenu = '';
$insertedRoute = '';

if (!$hasMenu) {
    $src = preg_replace_callback($menuPattern, function ($m) use (&$insertedMenu) {
        $field = $m['field'];
        $outer = $m['outer'];
        $item = $m['item'];
        $iconIndent = $field . '    ';
        $new = $item . "\n"
            . $field . 'title: "\u5b50\u8d26\u53f7\u7ba1\u7406",' . "\n"
            . $field . 'type: "item",' . "\n"
            . $field . 'href: "/sub-accounts",' . "\n"
            . $field . 'icon: o.a.createElement("i", {' . "\n"
            . $iconIndent . 'className: "nav-main-link-icon si si-user-follow"' . "\n"
            . $field . '})' . "\n"
            . $outer . '}, {';
        // 记录本次插入的片段（不含锚点原文），用于写入前精确比对
        $insertedMenu = $field . 'title: "\u5b50\u8d26\u53f7\u7ba1\u7406",' . "\n"
            . $field . 'type: "item",' . "\n"
            . $field . 'href: "/sub-accounts",' . "\n"
            . $field . 'icon: o.a.createElement("i", {' . "\n"
            . $iconIndent . 'className: "nav-main-link-icon si si-user-follow"' . "\n"
            . $field . '})';
        return $new;
    }, $src, 1);
    $changed = true;
    echo "  [菜单] 已插入「子账号管理」菜单项（icon: si si-user-follow）\n";
} else {
    echo "  [菜单] 已存在，跳过\n";
}

if (!$hasRoute) {
    $src = preg_replace_callback($routePattern, function ($m) use (&$insertedRoute) {
        $field = $m['field'];
        $first = $m['first'];
        $p = $field;
        $body = $p . 'path: "/sub-accounts",' . "\n"
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
    $changed = true;
    echo "  [路由] 已插入 /sub-accounts 原生路由（容器 #subaccount-admin-root）\n";
} else {
    echo "  [路由] 已存在，跳过\n";
}

if (!$changed) {
    echo "\n没有需要变更的内容（补丁已应用过）。\n";
    exit(0);
}

/* ------------------------------------------------------------------ 5. 校验与写入
 *
 * 注意：umi.js 是压缩产物，全文 `{}`/`()` 计数天然不平衡（字符串/正则里含括号），
 * 因此不做全文平衡校验，改为：
 *   - 4 个标记（菜单 href / 路由 path / 容器 id / 图标）各恰好 1 次；
 *   - 本次实际插入的片段原文各恰好出现 1 次（插入内容由本脚本生成，可精确比对）。
 */
$menuAfter = substr_count($src, 'href: "' . $MENU_HREF . '"');
$routeAfter = substr_count($src, 'path: "' . $MENU_HREF . '"');
$containerAfter = substr_count($src, 'id: "subaccount-admin-root"');
$iconAfter = substr_count($src, 'si si-user-follow');
$menuInsertAfter = $insertedMenu === '' ? 0 : substr_count($src, $insertedMenu);
$routeInsertAfter = $insertedRoute === '' ? 0 : substr_count($src, $insertedRoute);

echo "\n--- 写入前校验 ---\n";
$ok = true;
$ok = report($menuAfter === 1, "菜单标记 href: \"{$MENU_HREF}\" 出现 {$menuAfter} 次（应为 1）") && $ok;
$ok = report($routeAfter === 1, "路由标记 path: \"{$MENU_HREF}\" 出现 {$routeAfter} 次（应为 1）") && $ok;
$ok = report($containerAfter === 1, "页面容器 id: \"subaccount-admin-root\" 出现 {$containerAfter} 次（应为 1）") && $ok;
$ok = report($iconAfter === 1, "菜单图标 si si-user-follow 出现 {$iconAfter} 次（应为 1）") && $ok;
$ok = report($insertedMenu === '' || $menuInsertAfter === 1, '插入的菜单片段可原样定位') && $ok;
$ok = report($insertedRoute === '' || $routeInsertAfter === 1, '插入的路由片段可原样定位') && $ok;
if (!$ok) {
    fwrite(STDERR, "\n校验失败，未写入任何内容。\n");
    exit(1);
}

$backup = $file . '.bak-' . date('Ymd-His');
if (!copy($file, $backup)) {
    fwrite(STDERR, "备份失败: {$backup}\n");
    exit(1);
}
echo "\n备份   : {$backup}\n";

if (file_put_contents($file, $src) === false) {
    fwrite(STDERR, "写入失败: {$file}\n");
    exit(1);
}
echo "已写入 : {$file}（" . strlen($orig) . " -> " . strlen($src) . " 字节）\n";
echo "\n完成。请在后台硬刷新（Ctrl+Shift+R）后确认「用户管理」下方出现「子账号管理」，\n";
echo "并确认直接访问 #/sub-accounts 不再出现 404。\n";
exit(0);
