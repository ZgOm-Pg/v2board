/*!
 * 子账号管理 - 独立后台页面（自包含）
 *
 * 该脚本不依赖编译产物 / 前端主题，也不注册 umi 路由：
 *   - 通过 stable href ("#/user") 定位原生「用户管理」菜单并克隆插入「子账号管理」
 *   - 在 body 上创建独立遮罩层容器 (#subaccount-admin-root)，仅在 hash 以 #/sub-accounts 开头时显示
 *   - 直接调用后端接口 base = '/' + window.settings.secure_path
 *
 * 纯浏览器 JS（ES5/ES2015 安全），无 import/export/module，无构建步骤。
 */
(function () {
    'use strict';

    if (window.__subAccountAdminPageLoaded) return;
    window.__subAccountAdminPageLoaded = true;

    /* ==================================================================
     * 常量
     * ================================================================== */

    var ROOT_ID = 'subaccount-admin-root';
    var ROUTE_PREFIX = '#/sub-accounts';
    var MENU_TEXT = '子账号管理';
    var MENU_FLAG = 'data-subaccount-menu';
    var PAGE_SIZE = 20;
    var MAX_PAGE_SIZE = 100;

    var RELATION_STATUS = { 1: '启用', 0: '停用' };
    var CREATE_TYPE = { 1: '父账号新建', 0: '绑定已有账号' };

    var ACTION_LABELS = {
        bind: '绑定',
        unbind: '解绑',
        update_traffic: '修改额度',
        update_remark: '修改备注',
        change_password: '修改密码',
        reset_traffic: '重置流量',
        reset_subscribe: '重置订阅',
        create: '创建',
        admin_unbind: '管理员解绑',
        send_bind_code: '发送绑定验证码',
        orphan_deactivate: '孤儿停用'
    };
    var ACTION_KEYS = [
        'create', 'bind', 'unbind', 'admin_unbind', 'update_traffic', 'update_remark',
        'change_password', 'reset_traffic', 'reset_subscribe', 'send_bind_code', 'orphan_deactivate'
    ];

    var AUDIT_COLUMNS = [
        { text: '时间' }, { text: '操作人' }, { text: '动作' }, { text: '关系ID' },
        { text: '主账号ID' }, { text: '子账号ID' }, { text: 'IP' }, { text: '修改内容' }
    ];
    var RELATION_COLUMNS = [
        { text: 'ID' }, { text: '主账号(邮箱/#ID)' }, { text: '子账号(邮箱/#ID)' }, { text: '个人额度(GB)' },
        { text: '个人用量' }, { text: '主账号共享剩余' }, { text: '套餐ID' }, { text: '权限组ID' },
        { text: '到期时间' }, { text: '状态' }, { text: '备注' }, { text: '创建方式' },
        { text: '创建时间' }, { text: '操作' }
    ];

    /* ==================================================================
     * 基础工具
     * ================================================================== */

    function log() {
        if (!window.console || !window.console.warn) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[子账号管理]');
        window.console.warn.apply(window.console, args);
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function clear(node) {
        if (!node) return;
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function isObject(value) {
        return !!value && typeof value === 'object';
    }

    function num(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) ? fallback : n;
    }

    /** 任意值 -> 单行紧凑文本 */
    function text(value) {
        if (value === undefined || value === null || value === '') return '-';
        if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        try {
            return JSON.stringify(value);
        } catch (e) {
            return String(value);
        }
    }

    /** unix 秒 -> YYYY-MM-DD HH:mm:ss（本地时区） */
    function formatTime(epochSeconds, fallbackText) {
        if (fallbackText) return fallbackText;
        var ts = num(epochSeconds, 0);
        if (!ts) return '-';
        var d = new Date(ts * 1000);
        if (isNaN(d.getTime())) return '-';
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) +
            ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function accountText(email, userId) {
        var mail = (email === undefined || email === null || email === '') ? '未知邮箱' : String(email);
        return mail + ' / #' + text(userId);
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            if (timer) window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                timer = null;
                fn();
            }, wait);
        };
    }

    /* ==================================================================
     * API
     * ================================================================== */

    // 后台接口的真实前缀是 /api/v1/{secure_path}。umi.js 中管理端 serviceHost
    // 为 origin + "/api/v1"，随后拼接 "/" + secure_path + path，本页必须保持一致。
    function basePath() {
        var secure = (window.settings && window.settings.secure_path) || '';
        secure = String(secure).replace(/^\/+|\/+$/g, '');
        return secure ? ('/api/v1/' + secure) : '/api/v1';
    }

    function endpoint(path) {
        return basePath() + path;
    }

    function queryString(params) {
        var parts = [];
        for (var key in params) {
            if (!Object.prototype.hasOwnProperty.call(params, key)) continue;
            var value = params[key];
            if (value === undefined || value === null) continue;
            parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
        }
        return parts.length ? ('?' + parts.join('&')) : '';
    }

    function authToken() {
        try {
            return window.localStorage.getItem('authorization');
        } catch (e) {
            return null;
        }
    }

    function parseJson(text) {
        if (!text) return null;
        try {
            return JSON.parse(text);
        } catch (e) {
            return null;
        }
    }

    /**
     * 统一请求封装。
     * 成功 -> resolve(payload.data)；失败 -> reject(Error(message))
     */
    function request(method, path, options) {
        options = options || {};
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open(method, endpoint(path) + queryString(options.params), true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('authorization', authToken() || '');
            if (options.body !== undefined) {
                xhr.setRequestHeader('Content-Type', 'application/json');
            }
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                var payload = parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300) {
                    if (payload && payload.data !== undefined) {
                        resolve(payload.data);
                    } else {
                        resolve(payload);
                    }
                    return;
                }
                var message = '';
                if (isObject(payload) && payload.message) message = String(payload.message);
                if (!message) {
                    if (xhr.status === 401 || xhr.status === 403) message = '登录状态已失效或无权限，请重新登录后台';
                    else if (xhr.status === 0) message = '网络请求失败，请检查网络连接';
                    else message = '请求失败（HTTP ' + xhr.status + '）';
                }
                reject(new Error(message));
            };
            xhr.onerror = function () {
                reject(new Error('网络请求失败，请检查网络连接'));
            };
            try {
                xhr.send(options.body === undefined ? null : JSON.stringify(options.body));
            } catch (e) {
                reject(new Error('请求发送失败：' + (e && e.message ? e.message : e)));
            }
        });
    }

    function apiGet(path, params) {
        return request('GET', path, { params: params });
    }

    function apiPost(path, body) {
        return request('POST', path, { body: body || {} });
    }

    /* ==================================================================
     * 菜单注入
     * ================================================================== */

    var MENU_RETRY_DELAYS = [0, 250, 600, 1200, 2200, 3500, 5000, 8000];

    function isUserMenuHref(href) {
        return !!href && href.indexOf('#/user') !== -1;
    }

    /**
     * 定位原生「用户管理」菜单项。
     * 注意：注入项的 href 是 "#/sub-accounts"（包含 "#/user"? 不包含，但
     * "#/sub-accounts" 会被 '#/user' 之外的规则干扰），因此这里同时用
     * href 片段与 data 标记双重排除，避免把自己当成原生项。
     */
    function isInjectedItem(node) {
        return node.getAttribute(MENU_FLAG) === '1' ||
            node.getAttribute('data-subaccount-menu') === '1' ||
            String(node.getAttribute('href') || '').indexOf('#/sub-accounts') !== -1;
    }

    function findUserMenuItem() {
        var links = document.querySelectorAll('a[href]');
        var i;
        for (i = 0; i < links.length; i++) {
            if (isInjectedItem(links[i])) continue;
            if (isUserMenuHref(links[i].getAttribute('href'))) return links[i];
        }
        // fallback: 仅当找不到 href 匹配时，才用中文链接文案匹配
        for (i = 0; i < links.length; i++) {
            if (isInjectedItem(links[i])) continue;
            var content = (links[i].textContent || '').replace(/\s+/g, '');
            if (content === '用户管理' || content.indexOf('用户管理') === 0) return links[i];
        }
        return null;
    }

    /**
     * 找到原生「用户管理」的可克隆菜单节点。
     * 优先使用其 <li> 祖先（侧边栏结构通常是 ul > li > a），
     * 这样克隆后插入的是同级菜单项，而不是嵌套 <a>。
     */
    function resolveMenuHost(link) {
        var list = closestListAncestor(link);
        if (list && list.parentNode) {
            var item = closestListItem(list, link);
            if (item) return { item: item, host: list };
        }
        // 结构不符合预期时退回：直接克隆 <a> 并作为其兄弟节点插入
        return { item: link, host: link.parentNode };
    }

    function closestListAncestor(node) {
        var current = node.parentNode;
        while (current) {
            if (current.tagName === 'UL' || current.tagName === 'OL') return current;
            current = current.parentNode;
        }
        return null;
    }

    function closestListItem(list, link) {
        var current = link.parentNode;
        while (current && current !== list) {
            if (current.tagName === 'LI') return current;
            current = current.parentNode;
        }
        return null;
    }

    function ensureMenu() {
        var container = byId(ROOT_ID);
        // 已有注入则直接返回（双保险：data 属性 + href 检查）
        if (container && container.querySelector('[' + MENU_FLAG + '="1"]')) return true;
        if (document.querySelector('[' + MENU_FLAG + '="1"]')) return true;
        var existing = document.querySelector('a[href*="#/sub-accounts"]');
        if (existing) {
            existing.setAttribute(MENU_FLAG, '1');
            return true;
        }

        var userLink = findUserMenuItem();
        if (!userLink || !userLink.parentNode) return false;

        var resolved = resolveMenuHost(userLink);
        var nativeItem = resolved.item;
        var host = resolved.host;
        if (!host) return false;

        var node = nativeItem.cloneNode(true);
        node.setAttribute(MENU_FLAG, '1');
        node.setAttribute('data-subaccount-menu', '1');

        // 找到克隆体内对应的链接并改成子账号路由
        var clonedLinks = node.tagName === 'A' ? [node] : node.querySelectorAll('a[href]');
        for (var k = 0; k < clonedLinks.length; k++) {
            if (isInjectedItem(clonedLinks[k])) continue;
            if (isUserMenuHref(clonedLinks[k].getAttribute('href'))) {
                clonedLinks[k].setAttribute('href', subAccountsHref(clonedLinks[k].getAttribute('href')));
            }
        }
        if (node.tagName === 'A' && !isUserMenuHref(node.getAttribute('href'))) {
            node.setAttribute('href', ROUTE_PREFIX);
        }

        // 克隆后清掉 antd 的选中态/子菜单展开态，避免视觉错位
        node.classList.remove('ant-menu-item-selected', 'ant-menu-item-active', 'ant-menu-submenu-selected');
        var actives = node.querySelectorAll('.ant-menu-item-selected, .ant-menu-item-active');
        for (var i = 0; i < actives.length; i++) {
            actives[i].classList.remove('ant-menu-item-selected', 'ant-menu-item-active');
        }
        // 替换文案（保留图标结构，只改带文本的节点）
        var replaced = false;
        var walker = node.querySelectorAll('span, a');
        for (var j = 0; j < walker.length; j++) {
            if (hasOwnText(walker[j])) {
                walker[j].textContent = MENU_TEXT;
                replaced = true;
                break;
            }
        }
        if (!replaced && node.tagName === 'A') node.textContent = MENU_TEXT;

        host.insertBefore(node, nativeItem.nextSibling);

        // hash 路由：#/sub-accounts 不注册在 SPA 内，SPA 会显示 404 内容，
        // 此处会同步触发 hashchange -> 显示遮罩层。
        node.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            setHash(ROUTE_PREFIX);
            scheduleVisibility();
            window.setTimeout(scheduleVisibility, 60);
        }, true);

        log('菜单已注入，位于「用户管理」之后');
        return true;
    }

    function hasOwnText(node) {
        for (var i = 0; i < node.childNodes.length; i++) {
            var child = node.childNodes[i];
            if (child.nodeType === 3 && String(child.nodeValue || '').replace(/\s+/g, '') !== '') return true;
        }
        return false;
    }

    function subAccountsHref(nativeHref) {
        var href = nativeHref || '#/user/list';
        var hashIndex = href.indexOf('#');
        if (hashIndex === -1) return ROUTE_PREFIX;
        return href.substring(0, hashIndex) + ROUTE_PREFIX;
    }

    function setHash(hash) {
        if (location.hash === hash) return;
        try {
            location.hash = hash;
        } catch (e) {
            log('设置 hash 失败', e);
        }
    }

    function ensureMenuWithRetry() {
        var attempt = 0;
        function run() {
            var ok = false;
            try {
                ok = ensureMenu();
            } catch (e) {
                log('菜单注入异常', e);
            }
            if (ok) return;
            attempt += 1;
            if (attempt < MENU_RETRY_DELAYS.length) {
                window.setTimeout(run, MENU_RETRY_DELAYS[attempt]);
            }
        }
        run();
    }

    /* ==================================================================
     * 遮罩层 / 内联样式兜底
     * ================================================================== */

    function buildOverlay() {
        var root = el('div', 'sa-root');
        root.id = ROOT_ID;
        root.style.position = 'fixed';
        root.style.zIndex = '1000';
        root.style.background = '#fff';
        root.style.overflow = 'auto';
        root.style.display = 'none';
        root.appendChild(buildHeader());

        var tabsBar = el('div', 'sa-tabbar');
        var panels = el('div', 'sa-panels');
        tabsBar.appendChild(el('div', 'sa-tabbar-title', '子账号管理'));
        root.appendChild(tabsBar);
        root.appendChild(panels);

        var defs = [
            { key: 'relations', text: '关系管理', build: buildRelationsPanel },
            { key: 'audit', text: '审计日志', build: buildAuditPanel },
            { key: 'settings', text: '功能设置', build: buildSettingsPanel }
        ];
        state.tabs = defs;
        for (var i = 0; i < defs.length; i++) {
            (function (def, index) {
                var button = el('button', 'sa-tab', def.text);
                button.type = 'button';
                button.setAttribute('data-tab', def.key);
                button.addEventListener('click', function () {
                    switchTab(def.key);
                });
                tabsBar.appendChild(button);
                var panel = def.build();
                panel.setAttribute('data-panel', def.key);
                panel.style.display = 'none';
                panels.appendChild(panel);
                if (index === 0) def.button = button;
            })(defs[i], i);
        }
        return root;
    }

    function buildHeader() {
        var bar = el('div', 'sa-header');
        var title = el('div', 'sa-header-title');
        title.appendChild(el('span', 'sa-header-name', MENU_TEXT));
        title.appendChild(el('span', 'sa-header-sub', '独立页面 · 不依赖前端主题'));
        bar.appendChild(title);

        var actions = el('div', 'sa-header-actions');
        var refresh = el('button', 'sa-btn', '刷新当前页');
        refresh.type = 'button';
        refresh.addEventListener('click', function () {
            reloadActiveTab();
        });
        var back = el('button', 'sa-btn sa-btn-ghost', '返回用户管理');
        back.type = 'button';
        back.addEventListener('click', function () {
            hideRoot();
            var userItem = findUserMenuItem();
            var href = userItem ? userItem.getAttribute('href') : '#/user/list';
            setHash(href && href.indexOf('#') !== -1 ? href.substring(href.indexOf('#')) : '#/user/list');
        });
        actions.appendChild(refresh);
        actions.appendChild(back);
        bar.appendChild(actions);
        return bar;
    }

    /**
     * 当前是否处于子账号路由。
     * 抹掉 query 后再比对，兼容 #/sub-accounts?x=1 之类的写法。
     */
    function isRouteActive() {
        var hash = String(location.hash || '');
        var cut = hash.indexOf('?');
        if (cut !== -1) hash = hash.substring(0, cut);
        return hash.indexOf(ROUTE_PREFIX) === 0;
    }

    /** 让遮罩层避开 SPA 顶部 header 与左侧 sidebar */
    function applyOffset() {
        if (!state.root) return;
        var bounds = measureChrome();
        var style = state.root.style;
        if (!bounds) {
            style.top = '0px';
            style.left = '0px';
            style.right = '0px';
            style.bottom = '0px';
            style.width = '';
            style.height = '';
            return;
        }
        style.top = bounds.top + 'px';
        style.left = bounds.left + 'px';
        style.right = bounds.right + 'px';
        style.bottom = bounds.bottom + 'px';
        style.width = '';
        style.height = '';
    }

    /**
     * 尝试测量 SPA 外壳：
     *  - 顶部 header：贴顶的横幅（含 header/.ant-layout-header/.ant-pro-global-header）
     *  - 左侧 sidebar：贴左的竖向色块（含 aside/.ant-layout-sider/.ant-pro-sider）
     * 返回 null 表示测量失败 -> 使用全屏兜底。
     */
    function measureChrome() {
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        if (!viewportWidth || !viewportHeight) return null;

        var top = 0;
        var left = 0;
        var found = false;

        var header = pickHeader(viewportWidth);
        if (header) {
            var headerRect = header.getBoundingClientRect();
            if (headerRect.height > 10 && headerRect.bottom > 0 && headerRect.bottom <= viewportHeight / 2) {
                top = Math.round(headerRect.bottom);
                found = true;
            }
        }

        var sidebar = pickSidebar(viewportHeight, top);
        if (sidebar) {
            var sidebarRect = sidebar.getBoundingClientRect();
            if (sidebarRect.width > 40 && sidebarRect.right > 0 && sidebarRect.right <= viewportWidth * 0.6) {
                left = Math.round(sidebarRect.right);
                found = true;
            }
        }

        if (!found) return null;
        if (viewportWidth - left < 240) return null; // 剩余空间过窄，视为测量失败
        if (viewportHeight - top < 200) return null;
        // right / bottom 使用 0：容器本身 fixed，靠 top/left/right/bottom 撑满剩余空间
        return { top: top, left: left, right: 0, bottom: 0 };
    }

    function pickHeader(viewportWidth) {
        var selectors = ['.ant-layout-header', '.ant-pro-global-header', 'header', '#root > div > header'];
        for (var i = 0; i < selectors.length; i++) {
            var nodes = document.querySelectorAll(selectors[i]);
            for (var j = 0; j < nodes.length; j++) {
                var rect = nodes[j].getBoundingClientRect();
                if (rect.height > 10 && rect.height < 160 && rect.top <= 4 && rect.width >= viewportWidth * 0.5) {
                    return nodes[j];
                }
            }
        }
        return null;
    }

    function pickSidebar(viewportHeight, topOffset) {
        var selectors = ['.ant-layout-sider', '.ant-pro-sider', 'aside'];
        for (var i = 0; i < selectors.length; i++) {
            var nodes = document.querySelectorAll(selectors[i]);
            for (var j = 0; j < nodes.length; j++) {
                var rect = nodes[j].getBoundingClientRect();
                if (rect.width >= 80 && rect.width <= 400 && rect.left <= 4 && rect.height >= viewportHeight * 0.3) {
                    return nodes[j];
                }
            }
        }
        // 兜底：扫描贴左的竖向 fixed/absolute 元素
        var all = document.body.children;
        for (var k = 0; k < all.length; k++) {
            var node = all[k];
            if (node === state.root) continue;
            var style = window.getComputedStyle ? window.getComputedStyle(node) : null;
            if (!style || (style.position !== 'fixed' && style.position !== 'absolute')) continue;
            var box = node.getBoundingClientRect();
            if (box.left <= 4 && box.width >= 80 && box.width <= 400 && box.height >= Math.max(200, viewportHeight - topOffset - 40)) {
                return node;
            }
        }
        return null;
    }

    function showRoot() {
        if (!state.root) return;
        state.root.style.display = 'block';
        applyOffset();
    }

    function hideRoot() {
        if (!state.root) return;
        state.root.style.display = 'none';
        state.visible = false;
    }

    function applyVisibility() {
        var active = isRouteActive();
        if (!active) {
            if (state.visible) hideRoot();
            return;
        }
        if (!state.root) {
            state.root = buildOverlay();
            document.body.appendChild(state.root);
            switchTab('relations');
        }
        if (!state.visible) {
            state.visible = true;
            showRoot();
            if (state.activeTab === 'settings') {
                loadSettings();
            } else if (state.activeTab === 'audit') {
                if (!state.audit.loaded) loadAudit();
            } else if (!state.relations.loaded) {
                loadRelations();
            }
        }
    }

    var scheduleVisibility = debounce(function () {
        try {
            applyVisibility();
        } catch (e) {
            log('渲染遮罩层异常', e);
        }
    }, 60);

    /* ==================================================================
     * 通用 UI 组件
     * ================================================================== */

    function buildBanner() {
        var banner = el('div', 'sa-banner');
        banner.style.display = 'none';
        return banner;
    }

    var bannerTimer = null;

    function showBanner(kind, message) {
        if (!state.banner) return;
        state.banner.className = 'sa-banner sa-banner-' + kind;
        state.banner.textContent = String(message);
        state.banner.style.display = 'block';
        if (bannerTimer) window.clearTimeout(bannerTimer);
        if (kind === 'success') {
            bannerTimer = window.setTimeout(function () {
                hideBanner();
            }, 3000);
        }
    }

    function hideBanner() {
        if (!state.banner) return;
        state.banner.style.display = 'none';
        state.banner.textContent = '';
    }

    /** 关系管理的按钮固定在页面上，与 settings 共用同一套横幅渲染（见 showBannerFor） */

    function buildButton(label, className, handler) {
        var button = el('button', className || 'sa-btn', label);
        button.type = 'button';
        if (handler) button.addEventListener('click', handler);
        return button;
    }

    function buildInput(options) {
        options = options || {};
        var input = el('input', options.className || 'sa-input');
        input.type = options.type || 'text';
        if (options.placeholder) input.placeholder = options.placeholder;
        if (options.min !== undefined) input.min = String(options.min);
        if (options.value !== undefined && options.value !== null) input.value = String(options.value);
        if (options.width) input.style.width = options.width;
        return input;
    }

    function buildSelect(options, items, value) {
        var select = el('select', options && options.className ? options.className : 'sa-select');
        for (var i = 0; i < items.length; i++) {
            var option = el('option', null, items[i].text);
            option.value = String(items[i].value);
            if (String(items[i].value) === String(value)) option.selected = true;
            select.appendChild(option);
        }
        if (options && options.width) select.style.width = options.width;
        return select;
    }

    function buildField(labelText, control, wide) {
        var field = el('div', 'sa-field' + (wide ? ' sa-field-wide' : ''));
        field.appendChild(el('label', 'sa-label', labelText));
        field.appendChild(control);
        return field;
    }

    function buildFilterBar(fields) {
        var bar = el('div', 'sa-filterbar');
        for (var i = 0; i < fields.length; i++) bar.appendChild(fields[i]);
        return bar;
    }

    /** 构建卡片；返回值带 setLoading / setError / setBody / getBody */
    function buildCard(titleText, extraNodes) {
        var card = el('section', 'sa-card');
        var head = el('div', 'sa-card-head');
        head.appendChild(el('h3', 'sa-card-title', titleText));
        var extra = el('div', 'sa-card-extra');
        if (extraNodes) {
            for (var i = 0; i < extraNodes.length; i++) extra.appendChild(extraNodes[i]);
        }
        head.appendChild(extra);
        card.appendChild(head);

        var body = el('div', 'sa-card-body');
        card.appendChild(body);

        var loading = el('div', 'sa-loading', '加载中…');
        loading.style.display = 'none';
        card.appendChild(loading);

        var error = el('div', 'sa-error');
        error.style.display = 'none';
        card.appendChild(error);

        card.setLoading = function (on) {
            loading.style.display = on ? 'block' : 'none';
            if (on) {
                error.style.display = 'none';
            }
        };
        card.setError = function (message) {
            if (!message) {
                error.style.display = 'none';
                error.textContent = '';
                return;
            }
            error.textContent = String(message);
            error.style.display = 'block';
        };
        card.getBody = function () {
            return body;
        };
        return card;
    }

    function buildTable(columns, rows, emptyText) {
        var wrap = el('div', 'sa-table-wrap');
        var table = el('table', 'sa-table');
        var thead = el('thead');
        var headRow = el('tr');
        for (var c = 0; c < columns.length; c++) {
            headRow.appendChild(el('th', null, columns[c].text));
        }
        thead.appendChild(headRow);
        table.appendChild(thead);

        var tbody = el('tbody');
        if (!rows.length) {
            var emptyRow = el('tr', 'sa-empty-row');
            var cell = el('td', 'sa-empty', emptyText || '暂无数据');
            cell.colSpan = columns.length;
            emptyRow.appendChild(cell);
            tbody.appendChild(emptyRow);
        } else {
            for (var r = 0; r < rows.length; r++) tbody.appendChild(rows[r]);
        }
        table.appendChild(tbody);
        wrap.appendChild(table);
        return wrap;
    }

    function buildStatusPill(status) {
        var enabled = num(status, 0) === 1;
        var pill = el('span', 'sa-pill ' + (enabled ? 'sa-pill-on' : 'sa-pill-off'),
            enabled ? RELATION_STATUS[1] : RELATION_STATUS[0]);
        return pill;
    }

    function buildPager(card, total, current, onGo) {
        var wrap = el('div', 'sa-pager');
        var pageCount = Math.max(1, Math.ceil(num(total, 0) / PAGE_SIZE));
        var info = el('span', 'sa-pager-info',
            '第 ' + current + ' / ' + pageCount + ' 页 · 共 ' + num(total, 0) + ' 条');
        var prev = buildButton('上一页', 'sa-btn sa-btn-ghost', function () {
            if (current <= 1) return;
            onGo(current - 1);
        });
        var next = buildButton('下一页', 'sa-btn sa-btn-ghost', function () {
            if (current >= pageCount) return;
            onGo(current + 1);
        });
        if (current <= 1) prev.disabled = true;
        if (current >= pageCount) next.disabled = true;
        wrap.appendChild(prev);
        wrap.appendChild(next);
        wrap.appendChild(info);
        return wrap;
    }

    /* ==================================================================
     * TAB 1 - 关系管理
     * ================================================================== */

    function buildRelationsPanel() {
        var panel = el('div', 'sa-panel');
        panel.appendChild(buildBannerRef('relations'));

        var statusSelect = buildSelect({ width: '130px' }, [
            { value: '', text: '全部状态' },
            { value: '1', text: '启用' },
            { value: '0', text: '停用' }
        ], '');
        var parentInput = buildInput({ placeholder: '主账号ID', width: '130px', type: 'number', min: 1 });
        var childInput = buildInput({ placeholder: '子账号ID', width: '130px', type: 'number', min: 1 });
        var search = buildButton('查询', 'sa-btn sa-btn-primary', function () {
            state.relations.current = 1;
            loadRelations();
        });
        var reset = buildButton('重置', 'sa-btn sa-btn-ghost', function () {
            statusSelect.value = '';
            parentInput.value = '';
            childInput.value = '';
            state.relations.current = 1;
            loadRelations();
        });

        panel.appendChild(buildFilterBar([
            buildField('状态', statusSelect),
            buildField('主账号ID', parentInput),
            buildField('子账号ID', childInput),
            buildField('', search),
            buildField('', reset)
        ]));

        var card = buildCard('关系列表', []);
        panel.appendChild(card);
        state.relations.card = card;

        state.relations.readFilters = function () {
            return {
                status: statusSelect.value,
                parent_user_id: parentInput.value.trim(),
                child_user_id: childInput.value.trim()
            };
        };
        return panel;
    }

    function buildBannerRef(key) {
        var banner = buildBanner();
        if (key === 'audit') state.audit.banner = banner;
        else if (key === 'settings') state.settings.banner = banner;
        else state.banner = banner;
        return banner;
    }

    function relationRow(row) {
        var card = state.relations.card;
        var tr = el('tr');
        if (state.relations.editingId !== null && state.relations.editingId === num(row.id, null)) {
            return relationEditRow(row);
        }

        tr.appendChild(el('td', 'sa-col-id', text(row.id)));

        tr.appendChild(buildAccountCell(row.parent_email, row.parent_user_id));
        tr.appendChild(buildAccountCell(row.child_email, row.child_user_id));

        var gb = row.traffic_limit_gb !== undefined && row.traffic_limit_gb !== null
            ? row.traffic_limit_gb
            : round2(num(row.traffic_limit, 0) / 1073741824);
        tr.appendChild(el('td', null, text(gb)));

        tr.appendChild(el('td', null, row.used_traffic_text
            ? String(row.used_traffic_text)
            : (row.used_traffic === null || row.used_traffic === undefined ? '-' : String(row.used_traffic))));

        tr.appendChild(el('td', null, row.parent_remaining_traffic_text
            ? String(row.parent_remaining_traffic_text)
            : (row.parent_remaining_traffic === undefined || row.parent_remaining_traffic === null
                ? '-' : String(row.parent_remaining_traffic))));

        tr.appendChild(el('td', null, text(row.plan_id)));
        tr.appendChild(el('td', null, text(row.group_id)));
        tr.appendChild(el('td', null, formatTime(row.expired_at, row.expired_at_text)));

        var statusCell = el('td');
        statusCell.appendChild(buildStatusPill(row.status));
        tr.appendChild(statusCell);

        var remarkCell = el('td', 'sa-col-remark', text(row.remark));
        if (row.remark) remarkCell.title = String(row.remark);
        tr.appendChild(remarkCell);

        tr.appendChild(el('td', null, CREATE_TYPE[num(row.created_by_parent, 0)] || '-'));
        tr.appendChild(el('td', null, formatTime(row.created_at, row.created_at_text)));

        var actions = el('td', 'sa-col-actions');
        actions.appendChild(buildButton('修改', 'sa-btn sa-btn-mini sa-btn-primary', function () {
            state.relations.editingId = num(row.id, null);
            renderRelationRows();
        }));
        actions.appendChild(buildButton('重置订阅', 'sa-btn sa-btn-mini', function () {
            confirmAndPost('重置订阅', '确认重置子账号 #' + row.child_user_id + ' 的订阅链接？',
                '/sub-account/reset-subscribe', { id: row.id });
        }));
        actions.appendChild(buildButton('重置流量', 'sa-btn sa-btn-mini', function () {
            confirmAndPost('重置流量', '确认重置子账号 #' + row.child_user_id + ' 的已用流量？',
                '/sub-account/reset-traffic', { id: row.id });
        }));
        actions.appendChild(buildButton('解绑', 'sa-btn sa-btn-mini sa-btn-danger', function () {
            confirmAndPost('解绑', '确认解除主账号 #' + row.parent_user_id + ' 与子账号 #' + row.child_user_id + ' 的绑定关系？',
                '/sub-account/unbind', { id: row.id });
        }));
        tr.appendChild(actions);
        return tr;
    }

    function buildAccountCell(email, userId) {
        var td = el('td', 'sa-col-account');
        td.appendChild(el('span', 'sa-email', text(email === undefined || email === null || email === '' ? '未知邮箱' : email)));
        td.appendChild(el('span', 'sa-uid', '#' + text(userId)));
        return td;
    }

    function relationEditRow(row) {
        var tr = el('tr', 'sa-editing-row');
        var gb = row.traffic_limit_gb !== undefined && row.traffic_limit_gb !== null
            ? row.traffic_limit_gb
            : round2(num(row.traffic_limit, 0) / 1073741824);

        var gbInput = buildInput({ type: 'number', min: 0, value: gb, width: '130px' });
        var remarkInput = buildInput({ placeholder: '备注', value: row.remark === null || row.remark === undefined ? '' : row.remark, width: '100%' });
        var statusSelect = buildSelect({ width: '110px' }, [
            { value: '1', text: '启用' },
            { value: '0', text: '停用' }
        ], String(num(row.status, 0)));

        tr.appendChild(el('td', null, text(row.id)));
        tr.appendChild(el('td', null, accountText(row.parent_email, row.parent_user_id)));
        tr.appendChild(el('td', null, accountText(row.child_email, row.child_user_id)));
        tr.appendChild(wrapInTd(gbInput));
        tr.appendChild(el('td', null, row.used_traffic_text ? String(row.used_traffic_text) : '-'));
        tr.appendChild(el('td', null, row.parent_remaining_traffic_text ? String(row.parent_remaining_traffic_text) : '-'));
        tr.appendChild(el('td', null, text(row.plan_id)));
        tr.appendChild(el('td', null, text(row.group_id)));
        tr.appendChild(el('td', null, formatTime(row.expired_at, row.expired_at_text)));
        tr.appendChild(wrapInTd(statusSelect));
        tr.appendChild(wrapInTd(remarkInput));
        tr.appendChild(el('td', null, CREATE_TYPE[num(row.created_by_parent, 0)] || '-'));
        tr.appendChild(el('td', null, formatTime(row.created_at, row.created_at_text)));

        var actions = el('td', 'sa-col-actions');
        var save = buildButton('保存', 'sa-btn sa-btn-mini sa-btn-primary', function () {
            saveRelation(row, gbInput, remarkInput, statusSelect, save);
        });
        var cancel = buildButton('取消', 'sa-btn sa-btn-mini sa-btn-ghost', function () {
            state.relations.editingId = null;
            renderRelationRows();
        });
        actions.appendChild(save);
        actions.appendChild(cancel);
        tr.appendChild(actions);
        return tr;
    }

    function wrapInTd(node) {
        var td = el('td');
        td.appendChild(node);
        return td;
    }

    function saveRelation(row, gbInput, remarkInput, statusSelect, saveButton) {
        var gb = parseFloat(gbInput.value);
        if (isNaN(gb) || gb < 0) {
            showBanner('error', '个人额度必须是大于等于 0 的数字（单位 GB）');
            return;
        }
        var body = {
            id: row.id,
            traffic_limit: Math.round(gb * 1073741824),
            remark: remarkInput.value,
            status: num(statusSelect.value, 1)
        };
        saveButton.disabled = true;
        saveButton.textContent = '保存中…';
        apiPost('/sub-account/update', body).then(function () {
            state.relations.editingId = null;
            showBanner('success', '修改成功');
            loadRelations();
        }).catch(function (error) {
            saveButton.disabled = false;
            saveButton.textContent = '保存';
            showBanner('error', error.message);
        });
    }

    function confirmAndPost(title, message, path, body) {
        if (!window.confirm(message)) return;
        apiPost(path, body).then(function () {
            showBanner('success', title + '成功');
            loadRelations();
        }).catch(function (error) {
            showBanner('error', title + '失败：' + error.message);
        });
    }

    function loadRelations() {
        var rel = state.relations;
        var card = rel.card;
        if (!card) return;
        if (rel.inflight) return; // 防止重复触发导致并发请求
        var filters = rel.readFilters ? rel.readFilters() : {};
        var params = {
            current: rel.current,
            page_size: PAGE_SIZE,
            status: filters.status,
            parent_user_id: filters.parent_user_id,
            child_user_id: filters.child_user_id
        };
        rel.inflight = true;
        card.setLoading(true);
        card.setError(null);
        apiGet('/sub-account/fetch', params).then(function (data) {
            var items = (data && data.items) || [];
            var total = (data && data.total) || 0;
            // 页码越界（例如筛选后）时回到第一页
            if (!items.length && total > 0 && rel.current > 1) {
                rel.current = 1;
                rel.inflight = false;
                loadRelations();
                return;
            }
            rel.items = items;
            rel.total = total;
            rel.loaded = true;
            renderRelationRows();
        }).catch(function (error) {
            card.setError(error.message);
        }).then(function () {
            rel.inflight = false;
            card.setLoading(false);
        });
    }

    function renderRelationRows() {
        var rel = state.relations;
        var card = rel.card;
        if (!card) return;
        var body = card.getBody();
        clear(body);
        var rows = [];
        for (var i = 0; i < rel.items.length; i++) rows.push(relationRow(rel.items[i]));
        body.appendChild(buildTable(RELATION_COLUMNS, rows, '暂无子账号关系'));
        body.appendChild(buildPager(card, rel.total, rel.current, function (page) {
            rel.current = page;
            rel.editingId = null;
            loadRelations();
        }));
    }

    /* ==================================================================
     * TAB 2 - 审计日志
     * ================================================================== */

    function buildAuditPanel() {
        var panel = el('div', 'sa-panel');
        panel.appendChild(buildBannerRef('audit'));

        var relationInput = buildInput({ placeholder: '关系ID', type: 'number', min: 1, width: '120px' });
        var parentInput = buildInput({ placeholder: '主账号ID', type: 'number', min: 1, width: '120px' });
        var childInput = buildInput({ placeholder: '子账号ID', type: 'number', min: 1, width: '120px' });

        var actionItems = [{ value: '', text: '全部动作' }];
        for (var i = 0; i < ACTION_KEYS.length; i++) {
            actionItems.push({
                value: ACTION_KEYS[i],
                text: ACTION_LABELS[ACTION_KEYS[i]] + '(' + ACTION_KEYS[i] + ')'
            });
        }
        var actionSelect = buildSelect({ width: '200px' }, actionItems, '');

        var search = buildButton('查询', 'sa-btn sa-btn-primary', function () {
            state.audit.current = 1;
            loadAudit();
        });
        var reset = buildButton('重置', 'sa-btn sa-btn-ghost', function () {
            relationInput.value = '';
            parentInput.value = '';
            childInput.value = '';
            actionSelect.value = '';
            state.audit.current = 1;
            loadAudit();
        });

        panel.appendChild(buildFilterBar([
            buildField('关系ID', relationInput),
            buildField('主账号ID', parentInput),
            buildField('子账号ID', childInput),
            buildField('动作', actionSelect),
            buildField('', search),
            buildField('', reset)
        ]));

        var card = buildCard('审计日志', []);
        panel.appendChild(card);
        state.audit.card = card;
        state.audit.readFilters = function () {
            return {
                relation_id: relationInput.value.trim(),
                parent_user_id: parentInput.value.trim(),
                child_user_id: childInput.value.trim(),
                action: actionSelect.value
            };
        };
        return panel;
    }

    function auditRow(row) {
        var tr = el('tr');
        tr.appendChild(el('td', null, formatTime(row.created_at, row.created_at_text)));

        var actor = row.actor_email
            ? String(row.actor_email)
            : (text(row.actor_type) + '#' + text(row.actor_user_id));
        tr.appendChild(el('td', 'sa-col-actor', actor));

        var actionCell = el('td');
        actionCell.appendChild(el('span', 'sa-action-tag', ACTION_LABELS[row.action] || text(row.action)));
        if (ACTION_LABELS[row.action]) actionCell.title = String(row.action);
        tr.appendChild(actionCell);

        tr.appendChild(el('td', 'sa-col-id', text(row.relation_id)));
        tr.appendChild(el('td', null, text(row.parent_user_id)));
        tr.appendChild(el('td', null, text(row.child_user_id)));
        tr.appendChild(el('td', 'sa-col-ip', text(row.ip)));

        var metaCell = el('td', 'sa-col-meta');
        var metaText = isObject(row.metadata) ? safeJson(row.metadata) : text(row.metadata);
        metaCell.appendChild(el('code', 'sa-json', metaText));
        if (metaText !== '-') metaCell.title = metaText;
        tr.appendChild(metaCell);
        return tr;
    }

    function safeJson(value) {
        try {
            return JSON.stringify(value);
        } catch (e) {
            return String(value);
        }
    }

    function loadAudit() {
        var audit = state.audit;
        var card = audit.card;
        if (!card) return;
        if (audit.inflight) return;
        var filters = audit.readFilters ? audit.readFilters() : {};
        var params = {
            current: audit.current,
            page_size: PAGE_SIZE,
            relation_id: filters.relation_id,
            parent_user_id: filters.parent_user_id,
            child_user_id: filters.child_user_id,
            action: filters.action
        };
        audit.inflight = true;
        card.setLoading(true);
        card.setError(null);
        apiGet('/sub-account/audit', params).then(function (data) {
            var items = (data && data.items) || [];
            var total = (data && data.total) || 0;
            if (!items.length && total > 0 && audit.current > 1) {
                audit.current = 1;
                audit.inflight = false;
                loadAudit();
                return;
            }
            audit.items = items;
            audit.total = total;
            audit.loaded = true;
            renderAuditRows();
        }).catch(function (error) {
            card.setError(error.message);
        }).then(function () {
            audit.inflight = false;
            card.setLoading(false);
        });
    }

    function renderAuditRows() {
        var audit = state.audit;
        var card = audit.card;
        if (!card) return;
        var body = card.getBody();
        clear(body);
        var rows = [];
        for (var i = 0; i < audit.items.length; i++) rows.push(auditRow(audit.items[i]));
        body.appendChild(buildTable(AUDIT_COLUMNS, rows, '暂无审计日志'));
        body.appendChild(buildPager(card, audit.total, audit.current, function (page) {
            audit.current = page;
            loadAudit();
        }));
    }

    /* ==================================================================
     * TAB 3 - 功能设置
     * ================================================================== */

    function buildSettingsPanel() {
        var panel = el('div', 'sa-panel');
        panel.appendChild(buildBannerRef('settings'));

        var card = buildCard('功能配置', []);
        panel.appendChild(card);
        state.settings.card = card;

        var body = card.getBody();
        body.appendChild(el('p', 'sa-hint',
            '配置项对应 /config/fetch?key=sub_account 与 /config/save，保存后立即生效。'));

        // 启用开关
        var enableSwitch = el('button', 'sa-switch', '停用');
        enableSwitch.type = 'button';
        enableSwitch.setAttribute('role', 'switch');
        var enableState = { value: 0 };
        enableSwitch.addEventListener('click', function () {
            enableState.value = enableState.value ? 0 : 1;
            paintSwitch(enableSwitch, enableState.value);
        });
        var enableField = el('div', 'sa-form-row');
        var enableLabel = el('div', 'sa-form-label');
        enableLabel.appendChild(el('span', null, '启用子账号功能'));
        enableLabel.appendChild(el('small', null, '关闭后用户端入口与相关接口将不可用'));
        enableField.appendChild(enableLabel);
        var enableControl = el('div', 'sa-form-control');
        enableControl.appendChild(enableSwitch);
        enableField.appendChild(enableControl);
        body.appendChild(enableField);

        var maxInput = buildInput({ type: 'number', min: 1, value: 1, width: '180px' });
        var ttlInput = buildInput({ type: 'number', min: 30, value: 300, width: '180px' });
        var intervalInput = buildInput({ type: 'number', min: 5, value: 60, width: '180px' });

        body.appendChild(buildFormRow('最大子账号数量', '每个主账号最多可绑定的子账号数（最小 1）', maxInput));
        body.appendChild(buildFormRow('验证码有效期(秒)', '绑定邮箱验证码的有效时间（最小 30）', ttlInput));
        body.appendChild(buildFormRow('验证码发送间隔(秒)', '同一验证码的重复发送间隔（最小 5）', intervalInput));

        var save = buildButton('保存', 'sa-btn sa-btn-primary', function () {
            saveSettings(enableState, maxInput, ttlInput, intervalInput, save);
        });
        var reload = buildButton('重新加载', 'sa-btn sa-btn-ghost', function () {
            loadSettings();
        });
        var footer = el('div', 'sa-form-footer');
        footer.appendChild(save);
        footer.appendChild(reload);
        body.appendChild(footer);

        // 健康摘要
        var healthCard = buildCard('运行状态', []);
        panel.appendChild(healthCard);
        state.settings.healthCard = healthCard;
        state.settings.healthBody = healthCard.getBody();

        state.settings.applyValues = function (values) {
            var enable = num(values.sub_account_enable, 0) === 1 ? 1 : 0;
            enableState.value = enable;
            paintSwitch(enableSwitch, enable);
            maxInput.value = num(values.sub_account_max_count, 1);
            ttlInput.value = num(values.sub_account_email_code_ttl, 300);
            intervalInput.value = num(values.sub_account_email_code_interval, 60);
        };

        return panel;
    }

    function paintSwitch(node, value) {
        var on = num(value, 0) === 1;
        node.className = 'sa-switch ' + (on ? 'sa-switch-on' : 'sa-switch-off');
        node.textContent = on ? '启用' : '停用';
        node.setAttribute('aria-checked', on ? 'true' : 'false');
    }

    function buildFormRow(labelText, hintText, control) {
        var row = el('div', 'sa-form-row');
        var label = el('div', 'sa-form-label');
        label.appendChild(el('span', null, labelText));
        label.appendChild(el('small', null, hintText));
        row.appendChild(label);
        var box = el('div', 'sa-form-control');
        box.appendChild(control);
        row.appendChild(box);
        return row;
    }

    function saveSettings(enableState, maxInput, ttlInput, intervalInput, saveButton) {
        var max = num(maxInput.value, 0);
        var ttl = num(ttlInput.value, 0);
        var interval = num(intervalInput.value, 0);
        if (max < 1) {
            showSettingsError('最大子账号数量必须大于等于 1');
            return;
        }
        if (ttl < 30) {
            showSettingsError('验证码有效期必须大于等于 30 秒');
            return;
        }
        if (interval < 5) {
            showSettingsError('验证码发送间隔必须大于等于 5 秒');
            return;
        }
        var payload = {
            sub_account_enable: num(enableState.value, 0),
            sub_account_max_count: max,
            sub_account_email_code_ttl: ttl,
            sub_account_email_code_interval: interval
        };
        saveButton.disabled = true;
        saveButton.textContent = '保存中…';
        hideBannerFor('settings');
        apiPost('/config/save', payload).then(function () {
            showBannerFor('settings', 'success', '配置已保存');
            loadSettings();
        }).catch(function (error) {
            showBannerFor('settings', 'error', '保存失败：' + error.message);
        }).then(function () {
            saveButton.disabled = false;
            saveButton.textContent = '保存';
        });
    }

    function showSettingsError(message) {
        showBannerFor('settings', 'error', message);
    }

    /* 设置页单独的横幅（与关系管理页横幅互不干扰） */
    var settingsBannerTimer = null;

    function showBannerFor(key, kind, message) {
        var banner = key === 'settings' ? state.settings.banner : state.banner;
        if (!banner) return;
        banner.className = 'sa-banner sa-banner-' + kind;
        banner.textContent = String(message);
        banner.style.display = 'block';
        if (settingsBannerTimer) window.clearTimeout(settingsBannerTimer);
        if (kind === 'success') {
            settingsBannerTimer = window.setTimeout(function () {
                banner.style.display = 'none';
                banner.textContent = '';
            }, 3000);
        }
    }

    function hideBannerFor(key) {
        var banner = key === 'settings' ? state.settings.banner : state.banner;
        if (!banner) return;
        banner.style.display = 'none';
        banner.textContent = '';
    }

    function loadSettings() {
        var settings = state.settings;
        var card = settings.card;
        if (!card) return;
        card.setLoading(true);
        card.setError(null);

        apiGet('/config/fetch', { key: 'sub_account' }).then(function (data) {
            var values = (data && data.sub_account) ? data.sub_account : null;
            if (!values) throw new Error('配置数据格式异常：缺少 sub_account 字段');
            settings.applyValues(values);
        }).catch(function (error) {
            card.setError(error.message);
        }).then(function () {
            card.setLoading(false);
        });

        loadHealth();
    }

    function loadHealth() {
        var healthCard = state.settings.healthCard;
        if (!healthCard) return;
        healthCard.setLoading(true);
        healthCard.setError(null);
        apiGet('/sub-account/status').then(function (data) {
            renderHealth(data);
        }).catch(function (error) {
            healthCard.setError(error.message);
        }).then(function () {
            healthCard.setLoading(false);
        });
    }

    function renderHealth(data) {
        var body = state.settings.healthBody;
        if (!body) return;
        clear(body);
        var stats = (data && data.stats) || {};

        var grid = el('div', 'sa-health-grid');
        grid.appendChild(buildHealthItem('启用状态', num(data && data.enabled, 0) === 1 ? '已启用' : '未启用',
            num(data && data.enabled, 0) === 1 ? 'sa-health-ok' : 'sa-health-off'));
        grid.appendChild(buildHealthItem('生效最大数量', text(data && data.max_count)));
        grid.appendChild(buildHealthItem('有效关系数', text(stats.active_relations), 'sa-health-ok'));
        grid.appendChild(buildHealthItem('孤儿关系数', text(stats.orphan_relations),
            num(stats.orphan_relations, 0) > 0 ? 'sa-health-warn' : 'sa-health-ok'));
        grid.appendChild(buildHealthItem('重复子账号数', text(stats.duplicate_child),
            num(stats.duplicate_child, 0) > 0 ? 'sa-health-bad' : 'sa-health-ok'));
        grid.appendChild(buildHealthItem('循环引用数', text(stats.cycles),
            num(stats.cycles, 0) > 0 ? 'sa-health-bad' : 'sa-health-ok'));
        body.appendChild(grid);

        var meta = el('p', 'sa-hint',
            '验证码有效期：' + text(data && data.email_code_ttl) + ' 秒 · 发送间隔：' + text(data && data.email_code_interval) + ' 秒');
        body.appendChild(meta);
    }

    function buildHealthItem(labelText, valueText, className) {
        var item = el('div', 'sa-health-item');
        item.appendChild(el('span', 'sa-health-label', labelText));
        item.appendChild(el('span', 'sa-health-value ' + (className || ''), valueText));
        return item;
    }

    /* ==================================================================
     * Tab 切换
     * ================================================================== */

    function switchTab(key) {
        state.activeTab = key;
        if (!state.root) return;
        var buttons = state.root.querySelectorAll('.sa-tab');
        for (var i = 0; i < buttons.length; i++) {
            var isActive = buttons[i].getAttribute('data-tab') === key;
            buttons[i].className = 'sa-tab' + (isActive ? ' sa-tab-active' : '');
        }
        var panels = state.root.querySelectorAll('.sa-panel');
        for (var j = 0; j < panels.length; j++) {
            panels[j].style.display = panels[j].getAttribute('data-panel') === key ? 'block' : 'none';
        }
        if (key === 'relations' && !state.relations.loaded) loadRelations();
        if (key === 'audit' && !state.audit.loaded) loadAudit();
        if (key === 'settings') loadSettings();
    }

    function reloadActiveTab() {
        if (state.activeTab === 'audit') loadAudit();
        else if (state.activeTab === 'settings') loadSettings();
        else loadRelations();
    }

    /* ==================================================================
     * 状态
     * ================================================================== */

    var state = {
        root: null,
        visible: false,
        activeTab: 'relations',
        banner: null,
        relations: { items: [], total: 0, current: 1, loaded: false, editingId: null, card: null, readFilters: null, inflight: false },
        audit: { items: [], total: 0, current: 1, loaded: false, card: null, readFilters: null, banner: null, inflight: false },
        settings: { card: null, healthCard: null, healthBody: null, banner: null, applyValues: null }
    };

    /* ==================================================================
     * 启动
     * ================================================================== */

    var initMenu = debounce(function () {
        ensureMenuWithRetry();
    }, 150);

    function onResize() {
        if (state.visible) applyOffset();
    }

    function start() {
        ensureMenuWithRetry();
        scheduleVisibility();

        // hashchange: SPA 通过 pushState / location.hash 切换路由
        window.addEventListener('hashchange', function () {
            scheduleVisibility();
            initMenu();
        });

        // MutationObserver: SPA 重新渲染侧边栏时补注入
        if (window.MutationObserver) {
            var observer = new MutationObserver(function () {
                initMenu();
            });
            try {
                observer.observe(document.body, { childList: true, subtree: true });
            } catch (e) {
                log('MutationObserver 注册失败', e);
            }
        }

        // 轮询兜底：SPA 有时不触发 hashchange（例如 replaceState）
        var lastHash = String(location.hash || '');
        window.setInterval(function () {
            var hash = String(location.hash || '');
            if (hash !== lastHash) {
                lastHash = hash;
                scheduleVisibility();
                initMenu();
            }
        }, 300);

        window.addEventListener('resize', onResize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
