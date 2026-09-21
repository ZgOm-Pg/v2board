/*!
 * 签到管理 / 活动弹窗 —— 后台原生路由页面脚本
 *
 * 集成方式：由 umi.js 原生路由 /checkin 与 /promotion 提供容器
 *   #checkin-admin-root / #promotion-admin-root（见 tools/patch-checkin-promotion-admin.php）。
 * 本脚本只负责在容器内渲染界面：
 *   - 不扫描、不克隆、不修改侧边栏菜单；
 *   - 不做 hash 跳转、不使用全屏遮罩、不轮询。
 */
(function () {
    'use strict';

    if (window.__checkinPromotionAdminLoaded) return;
    window.__checkinPromotionAdminLoaded = true;

    var CHECKIN_ROOT = 'checkin-admin-root';
    var PROMOTION_ROOT = 'promotion-admin-root';

    // EZ-Theme 只会发送这 4 个页面值（PromotionPopup.vue pageMap）
    var PAGE_KEYS = ['dashboard', 'shop', 'checkin', 'home'];
    // 按钮动作与主题分支一一对应（缺失/未知 -> claim_and_redirect）
    var ACTIONS = [
        { value: 'claim_and_redirect', text: '领取并跳转（默认）' },
        { value: 'redirect', text: '仅跳转' },
        { value: 'claim', text: '仅领取（写行为记录）' },
        { value: 'copy_coupon', text: '复制优惠码' }
    ];
    var SCOPES = [
        { value: 'all', text: '全部用户' },
        { value: 'new', text: '新用户（注册 7 天内）' },
        { value: 'paid', text: '有效套餐用户' },
        { value: 'unpaid', text: '无有效套餐用户' }
    ];

    /* ================================================================ 基础工具 */

    function log() {
        if (!window.console || !window.console.warn) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('[checkin-promotion-admin]');
        window.console.warn.apply(window.console, args);
    }

    function byId(id) { return document.getElementById(id); }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function clear(node) { while (node && node.firstChild) node.removeChild(node.firstChild); }

    function text(value) { return value === null || value === undefined || value === '' ? '-' : String(value); }
    function num(value, fallback) { var n = parseInt(value, 10); return isNaN(n) ? fallback : n; }

    function formatTime(seconds) {
        var n = parseInt(seconds, 10);
        if (!n || n <= 0) return '-';
        var d = new Date(n * 1000);
        function p(v) { return (v < 10 ? '0' : '') + v; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
    }

    function toLocalInput(seconds) {
        var n = parseInt(seconds, 10);
        if (!n || n <= 0) return '';
        var d = new Date(n * 1000);
        function p(v) { return (v < 10 ? '0' : '') + v; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function fromLocalInput(value) {
        if (!value) return null;
        var t = Date.parse(value);
        return isNaN(t) ? null : Math.floor(t / 1000);
    }

    function formatBytes(bytes) {
        var b = parseInt(bytes, 10) || 0;
        if (b <= 0) return '0 B';
        var gb = 1073741824, mb = 1048576, kb = 1024;
        if (b >= gb) return (Math.round(b / gb * 100) / 100) + ' GB';
        if (b >= mb) return (Math.round(b / mb * 100) / 100) + ' MB';
        if (b >= kb) return (Math.round(b / kb * 100) / 100) + ' KB';
        return b + ' B';
    }

    /* ================================================================ API */

    function basePath() {
        var secure = (window.settings && window.settings.secure_path) || '';
        secure = String(secure).replace(/^\/+|\/+$/g, '');
        return secure ? ('/api/v1/' + secure) : '/api/v1';
    }

    function authToken() {
        try { return window.localStorage.getItem('authorization'); } catch (e) { return null; }
    }

    function request(method, path, options) {
        options = options || {};
        return new Promise(function (resolve, reject) {
            var url = basePath() + path;
            if (options.params) {
                var parts = [];
                for (var key in options.params) {
                    if (!Object.prototype.hasOwnProperty.call(options.params, key)) continue;
                    var value = options.params[key];
                    if (value === undefined || value === null || value === '') continue;
                    parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
                }
                if (parts.length) url += '?' + parts.join('&');
            }
            var xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('authorization', authToken() || '');
            if (options.body !== undefined) xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) return;
                var payload = null;
                try { payload = xhr.responseText ? JSON.parse(xhr.responseText) : null; } catch (e) { payload = null; }
                if (xhr.status >= 200 && xhr.status < 300) {
                    if (payload && payload.data !== undefined) resolve(payload.data);
                    else resolve(payload);
                    return;
                }
                var message = payload && payload.message ? String(payload.message) : '';
                if (!message) {
                    if (xhr.status === 401 || xhr.status === 403) message = '登录状态已失效或无权限，请重新登录后台';
                    else message = '请求失败（HTTP ' + xhr.status + '）';
                }
                reject(new Error(message));
            };
            xhr.onerror = function () { reject(new Error('网络请求失败')); };
            try { xhr.send(options.body === undefined ? null : JSON.stringify(options.body)); }
            catch (e) { reject(new Error('请求发送失败：' + (e && e.message ? e.message : e))); }
        });
    }

    function apiGet(path, params) { return request('GET', path, { params: params }); }
    function apiPost(path, body) { return request('POST', path, { body: body || {} }); }

    /* ================================================================ 通用组件 */

    function buildButton(label, className, handler) {
        var btn = el('button', className || 'cp-btn', label);
        btn.type = 'button';
        if (handler) btn.addEventListener('click', handler);
        return btn;
    }

    function buildInput(options) {
        options = options || {};
        var input = el('input');
        input.type = options.type || 'text';
        if (options.placeholder) input.placeholder = options.placeholder;
        if (options.value !== undefined && options.value !== null) input.value = options.value;
        if (options.style) input.style.cssText = options.style;
        if (options.min !== undefined) input.min = options.min;
        return input;
    }

    function buildSelect(value, items, options) {
        options = options || {};
        var select = el('select');
        if (options.style) select.style.cssText = options.style;
        for (var i = 0; i < items.length; i++) {
            var opt = document.createElement('option');
            opt.value = items[i].value;
            opt.textContent = items[i].text;
            select.appendChild(opt);
        }
        select.value = value === undefined || value === null ? '' : String(value);
        return select;
    }

    function buildField(labelText, control) {
        var field = el('div', 'cp-field');
        if (labelText) field.appendChild(el('label', 'cp-label', labelText));
        field.appendChild(control);
        return field;
    }

    function buildCard(titleText, extraNodes) {
        var card = el('div', 'cp-card');
        var head = el('div', 'cp-card-head');
        head.appendChild(el('h3', 'cp-card-title', titleText));
        if (extraNodes && extraNodes.length) {
            var extra = el('div', 'cp-card-extra');
            for (var i = 0; i < extraNodes.length; i++) extra.appendChild(extraNodes[i]);
            head.appendChild(extra);
        }
        card.appendChild(head);
        var body = el('div', 'cp-card-body');
        card.appendChild(body);
        var loading = el('div', 'cp-loading', '加载中…');
        loading.style.display = 'none';
        card.appendChild(loading);
        var error = el('div', 'cp-error');
        error.style.display = 'none';
        card.appendChild(error);
        card.getBody = function () { return body; };
        card.setLoading = function (on) { loading.style.display = on ? 'block' : 'none'; };
        card.setError = function (message) {
            error.textContent = message || '';
            error.style.display = message ? 'block' : 'none';
        };
        return card;
    }

    function buildTable(columns, rows, emptyText) {
        var wrap = el('div', 'cp-table-wrap');
        var table = el('table', 'cp-table');
        var thead = el('thead');
        var headRow = el('tr');
        for (var c = 0; c < columns.length; c++) headRow.appendChild(el('th', null, columns[c].text));
        thead.appendChild(headRow);
        table.appendChild(thead);
        var tbody = el('tbody');
        if (!rows.length) {
            var emptyRow = el('tr');
            var cell = el('td', 'cp-empty', emptyText || '暂无数据');
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

    function buildPager(total, current, pageSize, onGo) {
        var pages = Math.max(1, Math.ceil(total / pageSize));
        var bar = el('div', 'cp-pager');
        var prev = buildButton('上一页', 'cp-btn cp-btn-ghost', function () { if (current > 1) onGo(current - 1); });
        var next = buildButton('下一页', 'cp-btn cp-btn-ghost', function () { if (current < pages) onGo(current + 1); });
        if (current <= 1) prev.disabled = true;
        if (current >= pages) next.disabled = true;
        bar.appendChild(prev);
        bar.appendChild(next);
        bar.appendChild(el('span', 'cp-pager-info', '第 ' + current + ' / ' + pages + ' 页 · 共 ' + total + ' 条'));
        return bar;
    }

    function buildBanner() {
        var banner = el('div', 'cp-banner');
        banner.style.display = 'none';
        return banner;
    }

    function showBanner(banner, kind, message) {
        banner.className = 'cp-banner cp-banner-' + (kind === 'error' ? 'error' : 'success');
        banner.textContent = message;
        banner.style.display = 'block';
        window.setTimeout(function () { banner.style.display = 'none'; }, 6000);
    }

    function buildPageHeader(titleText, subtitle, actions) {
        var bar = el('div', 'cp-header');
        var title = el('div', 'cp-header-title');
        title.appendChild(el('span', 'cp-header-name', titleText));
        if (subtitle) title.appendChild(el('span', 'cp-header-sub', subtitle));
        bar.appendChild(title);
        if (actions && actions.length) {
            var right = el('div', 'cp-header-actions');
            for (var i = 0; i < actions.length; i++) right.appendChild(actions[i]);
            bar.appendChild(right);
        }
        return bar;
    }

    function buildTabs(defs, onSwitch) {
        var bar = el('div', 'cp-tabbar');
        var buttons = [];
        for (var i = 0; i < defs.length; i++) {
            (function (def, index) {
                var btn = el('button', 'cp-tab', def.text);
                btn.type = 'button';
                btn.setAttribute('data-tab', def.key);
                btn.addEventListener('click', function () { onSwitch(def.key); });
                bar.appendChild(btn);
                buttons.push(btn);
                if (index === 0) def.button = btn;
            })(defs[i], i);
        }
        return { bar: bar, buttons: buttons };
    }

    /* ================================================================ 签到管理 */

    function buildCheckinPage() {
        var state = {
            root: null,
            activeTab: 'rewards',
            banners: {},
            rewards: { items: [], card: null, editId: null },
            logs: { items: [], total: 0, current: 1, card: null, readFilters: null, inflight: false },
            config: { data: null, section: 'checkin', enableKey: 'checkin_enable' },
            stats: null
        };

        var page = el('div', 'cp-page');
        page.appendChild(buildPageHeader('签到管理', '每日签到规则、日志与开关（默认关闭）', [
            buildButton('刷新当前页', 'cp-btn', function () { reloadActive(); })
        ]));

        var tabs = buildTabs([
            { key: 'rewards', text: '奖励规则' },
            { key: 'logs', text: '签到日志' },
            { key: 'settings', text: '功能设置' }
        ], function (key) { switchTab(key); });

        page.appendChild(tabs.bar);
        var panels = el('div', 'cp-panels');
        page.appendChild(panels);

        function switchTab(key) {
            state.activeTab = key;
            for (var i = 0; i < tabs.buttons.length; i++) {
                var isActive = tabs.buttons[i].getAttribute('data-tab') === key;
                tabs.buttons[i].className = 'cp-tab' + (isActive ? ' cp-tab-active' : '');
            }
            var list = panels.querySelectorAll('.cp-panel');
            for (var j = 0; j < list.length; j++) {
                list[j].style.display = list[j].getAttribute('data-panel') === key ? 'block' : 'none';
            }
            if (key === 'logs') loadLogs();
            if (key === 'settings') loadSettings();
            if (key === 'rewards') loadRewards();
        }

        function reloadActive() {
            if (state.activeTab === 'logs') loadLogs();
            else if (state.activeTab === 'settings') loadSettings();
            else loadRewards();
        }

        // ---------------- 奖励规则 ----------------
        var rewardsPanel = el('div', 'cp-panel');
        rewardsPanel.setAttribute('data-panel', 'rewards');
        rewardsPanel.appendChild(buildBannerRef('rewards'));
        var rewardsCard = buildCard('奖励规则（1–30 天循环档位）', [
            buildButton('新增/修改档位', 'cp-btn cp-btn-primary', function () {
                state.rewards.editId = state.rewards.editId === 'new' ? null : 'new';
                renderRewards();
            })
        ]);
        rewardsPanel.appendChild(rewardsCard);
        state.rewards.card = rewardsCard;
        panels.appendChild(rewardsPanel);

        function buildBannerRef(key) {
            var banner = buildBanner();
            state.banners[key] = banner;
            return banner;
        }

        function loadRewards() {
            var card = state.rewards.card;
            card.setLoading(true);
            card.setError(null);
            apiGet('/checkin/reward/fetch').then(function (data) {
                state.rewards.items = (data && data.items) || [];
                renderRewards();
            }).catch(function (e) {
                card.setError(e.message);
            }).then(function () { card.setLoading(false); });
        }

        function renderRewards() {
            var card = state.rewards.card;
            var body = card.getBody();
            clear(body);
            var rows = [];
            var items = state.rewards.items.slice();
            if (state.rewards.editId === 'new') items.push({ id: null, day_index: '', reward_bytes: 0, reward_text: '', enabled: 1, isNew: true });
            for (var i = 0; i < items.length; i++) rows.push(rewardRow(items[i]));
            body.appendChild(buildTable([
                { text: '档位（第 N 天）' }, { text: '奖励（MB）' }, { text: '文案' },
                { text: '状态' }, { text: '操作' }
            ], rows, '暂无规则，点击右上角「新增/修改档位」'));
            var hint = el('p', 'cp-hint', '奖励会累加到用户可用流量（transfer_enable）。被日志引用过的档位只能停用，不能删除。默认档位：第 7 天 500MB、第 14 天 2GB、第 21 天 4GB、第 30 天 7GB。');
            body.appendChild(hint);
        }

        function rewardRow(item) {
            var tr = el('tr');
            if (state.rewards.editId === (item.isNew ? 'new' : num(item.id, null))) {
                var td1 = el('td');
                var dayInput = buildInput({ type: 'number', min: 1, value: item.day_index, style: 'width:90px' });
                td1.appendChild(dayInput);
                var td2 = el('td');
                var mbInput = buildInput({ type: 'number', min: 0, value: item.reward_bytes ? Math.round(item.reward_bytes / 1048576) : 0, style: 'width:110px' });
                td2.appendChild(mbInput);
                var td3 = el('td');
                var textInput = buildInput({ value: item.reward_text, placeholder: '如 500MB / 2GB' });
                td3.appendChild(textInput);
                var td4 = el('td');
                var enabledSelect = buildSelect(String(num(item.enabled, 1)), [{ value: '1', text: '启用' }, { value: '0', text: '停用' }]);
                td4.appendChild(enabledSelect);
                var td5 = el('td');
                var saveBtn = buildButton('保存', 'cp-btn cp-btn-primary', function () {
                    saveBtn.disabled = true;
                    apiPost('/checkin/reward/save', {
                        id: item.id || undefined,
                        day_index: dayInput.value,
                        reward_mb: mbInput.value,
                        reward_text: textInput.value,
                        enabled: enabledSelect.value
                    }).then(function () {
                        showBanner(state.banners.rewards, 'success', '保存成功');
                        state.rewards.editId = null;
                        loadRewards();
                    }).catch(function (e) {
                        saveBtn.disabled = false;
                        showBanner(state.banners.rewards, 'error', e.message);
                    });
                });
                var cancelBtn = buildButton('取消', 'cp-btn cp-btn-ghost', function () {
                    state.rewards.editId = null;
                    renderRewards();
                });
                td5.appendChild(saveBtn);
                td5.appendChild(cancelBtn);
                tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4); tr.appendChild(td5);
                return tr;
            }

            tr.appendChild(el('td', 'cp-col-id', text(item.day_index)));
            tr.appendChild(el('td', null, formatBytes(item.reward_bytes) + '（' + Math.round((item.reward_bytes || 0) / 1048576) + ' MB）'));
            tr.appendChild(el('td', null, text(item.reward_text)));
            tr.appendChild(el('td', null, num(item.enabled, 0) === 1 ? '启用' : '停用'));
            var ops = el('td');
            ops.appendChild(buildButton('编辑', 'cp-btn cp-btn-sm', function () {
                state.rewards.editId = num(item.id, null);
                renderRewards();
            }));
            ops.appendChild(buildButton(num(item.enabled, 0) === 1 ? '停用' : '启用', 'cp-btn cp-btn-sm cp-btn-ghost', function () {
                apiPost('/checkin/reward/toggle', { id: item.id, enabled: num(item.enabled, 0) === 1 ? 0 : 1 })
                    .then(function () { loadRewards(); })
                    .catch(function (e) { showBanner(state.banners.rewards, 'error', e.message); });
            }));
            ops.appendChild(buildButton('删除', 'cp-btn cp-btn-sm cp-btn-danger', function () {
                if (!window.confirm('确定删除该档位规则？被日志引用的规则会自动改为停用。')) return;
                apiPost('/checkin/reward/drop', { id: item.id })
                    .then(function () { showBanner(state.banners.rewards, 'success', '已删除/停用'); loadRewards(); })
                    .catch(function (e) { showBanner(state.banners.rewards, 'error', e.message); });
            }));
            tr.appendChild(ops);
            return tr;
        }

        // ---------------- 签到日志 ----------------
        var logsPanel = el('div', 'cp-panel');
        logsPanel.setAttribute('data-panel', 'logs');
        logsPanel.appendChild(buildBannerRef('logs'));
        var statusLine = el('div', 'cp-stats');
        logsPanel.appendChild(statusLine);
        var userInput = buildInput({ placeholder: '用户ID', type: 'number', min: 1, style: 'width:120px' });
        var emailInput = buildInput({ placeholder: '邮箱关键字', style: 'width:180px' });
        var fromInput = buildInput({ type: 'date', style: 'width:150px' });
        var toInput = buildInput({ type: 'date', style: 'width:150px' });
        var searchBtn = buildButton('查询', 'cp-btn cp-btn-primary', function () { state.logs.current = 1; loadLogs(); });
        var resetBtn = buildButton('重置', 'cp-btn cp-btn-ghost', function () {
            userInput.value = ''; emailInput.value = ''; fromInput.value = ''; toInput.value = '';
            state.logs.current = 1; loadLogs();
        });
        var filterBar = el('div', 'cp-filter-bar');
        filterBar.appendChild(buildField('用户ID', userInput));
        filterBar.appendChild(buildField('邮箱', emailInput));
        filterBar.appendChild(buildField('开始日期', fromInput));
        filterBar.appendChild(buildField('结束日期', toInput));
        filterBar.appendChild(buildField('', searchBtn));
        filterBar.appendChild(buildField('', resetBtn));
        logsPanel.appendChild(filterBar);
        var logsCard = buildCard('签到日志', []);
        logsPanel.appendChild(logsCard);
        state.logs.card = logsCard;
        panels.appendChild(logsPanel);

        function loadLogs() {
            var card = state.logs.card;
            if (state.logs.inflight) return;
            state.logs.inflight = true;
            card.setLoading(true);
            card.setError(null);
            apiGet('/checkin/log/fetch', {
                current: state.logs.current,
                page_size: 20,
                user_id: userInput.value.trim(),
                email: emailInput.value.trim(),
                date_from: fromInput.value,
                date_to: toInput.value
            }).then(function (data) {
                state.logs.items = (data && data.items) || [];
                state.logs.total = (data && data.total) || 0;
                renderLogs();
            }).catch(function (e) {
                card.setError(e.message);
            }).then(function () {
                state.logs.inflight = false;
                card.setLoading(false);
            });
        }

        function renderLogs() {
            var card = state.logs.card;
            var body = card.getBody();
            clear(body);
            var rows = [];
            for (var i = 0; i < state.logs.items.length; i++) {
                var item = state.logs.items[i];
                var tr = el('tr');
                tr.appendChild(el('td', 'cp-col-id', text(item.id)));
                tr.appendChild(el('td', null, text(item.email || ('#' + item.user_id))));
                tr.appendChild(el('td', null, text(item.checkin_date)));
                tr.appendChild(el('td', null, text(item.continuous_days)));
                tr.appendChild(el('td', null, text(item.day_index)));
                tr.appendChild(el('td', null, formatBytes(item.reward_bytes)));
                tr.appendChild(el('td', null, text(item.reward_text)));
                tr.appendChild(el('td', null, num(item.credited, 0) === 1 ? '已计入' : '未计入'));
                tr.appendChild(el('td', null, text(item.source)));
                tr.appendChild(el('td', null, text(item.created_at_text)));
                rows.push(tr);
            }
            body.appendChild(buildTable([
                { text: 'ID' }, { text: '用户' }, { text: '签到日期' }, { text: '连续天数' },
                { text: '档位' }, { text: '奖励' }, { text: '文案' }, { text: '额度计入' },
                { text: '来源' }, { text: '创建时间' }
            ], rows, '暂无签到记录'));
            body.appendChild(buildPager(state.logs.total, state.logs.current, 20, function (page) {
                state.logs.current = page;
                loadLogs();
            }));
            loadStats();
        }

        function loadStats() {
            apiGet('/checkin/status').then(function (data) {
                state.stats = data;
                clear(statusLine);
                var pairs = [
                    ['功能状态', num(data.enabled, 0) === 1 ? '已开启' : '未开启'],
                    ['时区', text(data.timezone)],
                    ['循环天数', text(data.cycle_days)],
                    ['规则（启用/总）', text(data.rewards_enabled) + ' / ' + text(data.rewards_configured)],
                    ['今日签到', text(data.logs_today)],
                    ['本月签到', text(data.logs_month)],
                    ['日志总数', text(data.logs_total)],
                    ['今日奖励流量', formatBytes(data.reward_bytes_today)],
                    ['历史导入记录', text(data.migrated_logs)]
                ];
                for (var i = 0; i < pairs.length; i++) {
                    var item = el('div', 'cp-stat');
                    item.appendChild(el('span', 'cp-stat-label', pairs[i][0]));
                    item.appendChild(el('span', 'cp-stat-value', pairs[i][1]));
                    statusLine.appendChild(item);
                }
            }).catch(function () {});
        }

        // ---------------- 功能设置 ----------------
        var settingsPanel = el('div', 'cp-panel');
        settingsPanel.setAttribute('data-panel', 'settings');
        settingsPanel.appendChild(buildBannerRef('settings'));
        var settingsCard = buildCard('功能设置', []);
        settingsPanel.appendChild(settingsCard);
        panels.appendChild(settingsPanel);

        function loadSettings() {
            var card = settingsCard;
            card.setLoading(true);
            card.setError(null);
            // 兼容两种部署：
            //   - 裸 V2Board：config 段 'checkin'，开关键 checkin_enable
            //   - v3board：v3board 自带签到已占用 checkin_enable，
            //     故 EZ-Theme 签到使用独立段 'theme_checkin' / 键 theme_checkin_enable
            apiGet('/config/fetch', { key: 'theme_checkin' }).then(function (data) {
                var cfg = (data && data.theme_checkin) || null;
                if (cfg && cfg.theme_checkin_enable !== undefined) {
                    state.config.section = 'theme_checkin';
                    state.config.enableKey = 'theme_checkin_enable';
                    return cfg;
                }
                return apiGet('/config/fetch', { key: 'checkin' }).then(function (inner) {
                    state.config.section = 'checkin';
                    state.config.enableKey = 'checkin_enable';
                    return (inner && inner.checkin) || {};
                });
            }).then(function (cfg) {
                state.config.data = cfg;
                renderSettings(cfg);
            }).catch(function (e) { card.setError(e.message); })
              .then(function () { card.setLoading(false); });
        }

        function renderSettings(cfg) {
            var body = settingsCard.getBody();
            clear(body);
            var enableKey = state.config.enableKey || 'checkin_enable';
            var enableSelect = buildSelect(String(num(cfg[enableKey], 0)), [
                { value: '0', text: '关闭（默认）' }, { value: '1', text: '开启' }
            ], { style: 'width:160px' });
            var tzInput = buildInput({ value: cfg.checkin_timezone || 'Asia/Shanghai', style: 'width:220px' });
            var row = el('div', 'cp-form-row');
            row.appendChild(buildField('签到功能（' + enableKey + '）', enableSelect));
            row.appendChild(buildField('签到时区（PHP 时区名）', tzInput));
            row.appendChild(buildField('', buildButton('保存设置', 'cp-btn cp-btn-primary', function () {
                var payload = { checkin_timezone: tzInput.value.trim() };
                payload[enableKey] = enableSelect.value;
                apiPost('/config/save', payload).then(function () {
                    showBanner(state.banners.settings, 'success', '设置已保存（后台需重载配置后生效，页面已即时更新）');
                    loadStats();
                }).catch(function (e) {
                    showBanner(state.banners.settings, 'error', e.message);
                });
            })));
            body.appendChild(row);
            body.appendChild(el('p', 'cp-hint', '签到默认关闭。时区用于计算“自然日”，默认 Asia/Shanghai（北京时间）。'));
        }

        switchTab('rewards');
        state.root = page;
        return page;
    }

    /* ================================================================ 活动弹窗 */

    function buildPromotionPage() {
        var state = {
            activeTab: 'list',
            banners: {},
            list: { items: [], total: 0, current: 1, card: null, inflight: false },
            records: { items: [], total: 0, current: 1, card: null, inflight: false },
            editing: null,
            meta: null
        };

        var page = el('div', 'cp-page');
        page.appendChild(buildPageHeader('活动弹窗', '登录后弹窗的配置与行为记录（claim 只记录行为，不自动发券）', [
            buildButton('新建活动', 'cp-btn cp-btn-primary', function () {
                state.editing = {
                    id: null, title: '', subtitle: '', coupon_code: '', coupon_name: '',
                    discount_text: '', button_text: '', button_url: '/shop',
                    button_action: 'claim_and_redirect', pages: 'all',
                    user_scope: 'all', sort: 0, cooldown_hours: 0, show: 0, starts_at: null, ends_at: null
                };
                switchTab('list');
                renderList();
            }),
            buildButton('刷新当前页', 'cp-btn', function () {
                if (state.activeTab === 'records') loadRecords(); else loadList();
            })
        ]));

        var tabs = buildTabs([
            { key: 'list', text: '活动列表' },
            { key: 'records', text: '行为记录' }
        ], function (key) { switchTab(key); });
        page.appendChild(tabs.bar);
        var panels = el('div', 'cp-panels');
        page.appendChild(panels);

        function switchTab(key) {
            state.activeTab = key;
            for (var i = 0; i < tabs.buttons.length; i++) {
                var isActive = tabs.buttons[i].getAttribute('data-tab') === key;
                tabs.buttons[i].className = 'cp-tab' + (isActive ? ' cp-tab-active' : '');
            }
            var list = panels.querySelectorAll('.cp-panel');
            for (var j = 0; j < list.length; j++) {
                list[j].style.display = list[j].getAttribute('data-panel') === key ? 'block' : 'none';
            }
            if (key === 'records') loadRecords(); else loadList();
        }

        function banner(key) {
            var b = buildBanner();
            state.banners[key] = b;
            return b;
        }

        // ---------------- 列表 / 编辑 ----------------
        var listPanel = el('div', 'cp-panel');
        listPanel.setAttribute('data-panel', 'list');
        listPanel.appendChild(banner('list'));
        var metaLine = el('div', 'cp-stats');
        listPanel.appendChild(metaLine);
        var listCard = buildCard('活动列表', []);
        listPanel.appendChild(listCard);
        state.list.card = listCard;
        panels.appendChild(listPanel);

        function loadMeta() {
            apiGet('/promotion/status').then(function (data) {
                state.meta = data;
                clear(metaLine);
                var pairs = [
                    ['活动总数', text(data.total)],
                    ['已启用', text(data.showing)],
                    ['当前生效', text(data.visible_now)],
                    ['行为记录', text(data.records_total)],
                    ['领取记录', text(data.records_claim)],
                    ['今日记录', text(data.records_today)]
                ];
                for (var i = 0; i < pairs.length; i++) {
                    var item = el('div', 'cp-stat');
                    item.appendChild(el('span', 'cp-stat-label', pairs[i][0]));
                    item.appendChild(el('span', 'cp-stat-value', pairs[i][1]));
                    metaLine.appendChild(item);
                }
            }).catch(function () {});
        }

        function loadList() {
            var card = state.list.card;
            if (state.list.inflight) return;
            state.list.inflight = true;
            card.setLoading(true);
            card.setError(null);
            apiGet('/promotion/fetch', { current: state.list.current, page_size: 20 }).then(function (data) {
                state.list.items = (data && data.items) || [];
                state.list.total = (data && data.total) || 0;
                renderList();
            }).catch(function (e) { card.setError(e.message); })
              .then(function () { state.list.inflight = false; card.setLoading(false); });
        }

        function renderList() {
            var card = state.list.card;
            var body = card.getBody();
            clear(body);
            if (state.editing) body.appendChild(buildEditForm());
            var rows = [];
            for (var i = 0; i < state.list.items.length; i++) rows.push(promotionRow(state.list.items[i]));
            body.appendChild(buildTable([
                { text: 'ID' }, { text: '标题' }, { text: '副标题' }, { text: '优惠码' },
                { text: '按钮动作' }, { text: '页面范围' }, { text: '生效时间' }, { text: '排序' },
                { text: '状态' }, { text: '操作' }
            ], rows, '暂无活动，点击右上角「新建活动」'));
            body.appendChild(buildPager(state.list.total, state.list.current, 20, function (page) {
                state.list.current = page;
                loadList();
            }));
            loadMeta();
        }

        function promotionRow(item) {
            var tr = el('tr');
            tr.appendChild(el('td', 'cp-col-id', text(item.id)));
            tr.appendChild(el('td', null, text(item.title)));
            tr.appendChild(el('td', null, text(item.subtitle)));
            tr.appendChild(el('td', null, text(item.coupon_code)));
            tr.appendChild(el('td', null, text(item.button_action)));
            tr.appendChild(el('td', null, text(item.pages)));
            var timeText = (item.starts_at ? formatTime(item.starts_at) : '不限') + ' ~ ' + (item.ends_at ? formatTime(item.ends_at) : '不限');
            tr.appendChild(el('td', null, timeText));
            tr.appendChild(el('td', null, text(item.sort)));
            tr.appendChild(el('td', null, num(item.show, 0) === 1 ? '启用' : '关闭'));
            var ops = el('td');
            ops.appendChild(buildButton('编辑', 'cp-btn cp-btn-sm', function () {
                state.editing = JSON.parse(JSON.stringify(item));
                renderList();
            }));
            ops.appendChild(buildButton('删除', 'cp-btn cp-btn-sm cp-btn-danger', function () {
                if (!window.confirm('确定删除该活动？行为记录会保留。')) return;
                apiPost('/promotion/drop', { id: item.id }).then(function () {
                    showBanner(state.banners.list, 'success', '已删除');
                    loadList();
                }).catch(function (e) { showBanner(state.banners.list, 'error', e.message); });
            }));
            tr.appendChild(ops);
            return tr;
        }

        function buildEditForm() {
            var item = state.editing;
            var wrap = el('div', 'cp-edit-form');
            wrap.appendChild(el('h4', 'cp-edit-title', item.id ? ('编辑活动 #' + item.id) : '新建活动'));

            var titleInput = buildInput({ value: item.title, placeholder: '标题（必填）', style: 'width:280px' });
            var subtitleInput = buildInput({ value: item.subtitle, placeholder: '副标题（默认：限时优惠）', style: 'width:240px' });
            var couponInput = buildInput({ value: item.coupon_code, placeholder: '优惠码（仅展示/复制）', style: 'width:200px' });
            var couponNameInput = buildInput({ value: item.coupon_name, placeholder: '优惠券名称', style: 'width:180px' });
            var discountInput = buildInput({ value: item.discount_text, placeholder: '折扣文案，如 8 折', style: 'width:180px' });
            var buttonTextInput = buildInput({ value: item.button_text, placeholder: '按钮文案（默认：立即查看）', style: 'width:200px' });
            var urlInput = buildInput({ value: item.button_url, placeholder: '默认 /shop；redirect 可填 http(s)://', style: 'width:300px' });
            var actionSelect = buildSelect(item.button_action || 'claim_and_redirect', ACTIONS, { style: 'width:220px' });
            var scopeSelect = buildSelect(item.user_scope || 'all', SCOPES, { style: 'width:200px' });
            var sortInput = buildInput({ type: 'number', value: item.sort || 0, style: 'width:90px' });
            var cooldownInput = buildInput({ type: 'number', value: item.cooldown_hours || 0, style: 'width:120px' });
            var showSelect = buildSelect(String(num(item.show, 0)), [
                { value: '0', text: '关闭' }, { value: '1', text: '启用' }
            ], { style: 'width:120px' });
            var startInput = buildInput({ type: 'datetime-local', value: toLocalInput(item.starts_at), style: 'width:210px' });
            var endInput = buildInput({ type: 'datetime-local', value: toLocalInput(item.ends_at), style: 'width:210px' });

            var pageBoxes = {};
            var pageRow = el('div', 'cp-checkbox-row');
            var allBox = document.createElement('input');
            allBox.type = 'checkbox';
            allBox.checked = (item.pages || 'all').indexOf('all') !== -1;
            var allLabel = el('label', 'cp-checkbox');
            allLabel.appendChild(allBox);
            allLabel.appendChild(el('span', null, '全部页面'));
            pageRow.appendChild(allLabel);
            for (var i = 0; i < PAGE_KEYS.length; i++) {
                (function (key) {
                    var box = document.createElement('input');
                    box.type = 'checkbox';
                    box.checked = (item.pages || '').split(',').map(function (s) { return s.trim(); }).indexOf(key) !== -1;
                    pageBoxes[key] = box;
                    var label = el('label', 'cp-checkbox');
                    label.appendChild(box);
                    label.appendChild(el('span', null, key));
                    pageRow.appendChild(label);
                })(PAGE_KEYS[i]);
            }
            allBox.addEventListener('change', function () {
                if (allBox.checked) {
                    for (var k in pageBoxes) if (pageBoxes.hasOwnProperty(k)) pageBoxes[k].checked = false;
                }
            });

            var row1 = el('div', 'cp-form-row');
            row1.appendChild(buildField('标题', titleInput));
            row1.appendChild(buildField('副标题', subtitleInput));
            row1.appendChild(buildField('状态', showSelect));
            var row2 = el('div', 'cp-form-row');
            row2.appendChild(buildField('优惠码', couponInput));
            row2.appendChild(buildField('优惠券名称', couponNameInput));
            row2.appendChild(buildField('折扣文案', discountInput));
            var row3 = el('div', 'cp-form-row');
            row3.appendChild(buildField('按钮文案', buttonTextInput));
            row3.appendChild(buildField('按钮动作', actionSelect));
            row3.appendChild(buildField('按钮链接（button_url）', urlInput));
            var row4 = el('div', 'cp-form-row');
            row4.appendChild(buildField('页面范围', pageRow));
            var row5 = el('div', 'cp-form-row');
            row5.appendChild(buildField('用户范围（服务端筛选）', scopeSelect));
            row5.appendChild(buildField('排序（小在前）', sortInput));
            row5.appendChild(buildField('关闭冷却（小时）', cooldownInput));
            var row6 = el('div', 'cp-form-row');
            row6.appendChild(buildField('开始时间（服务端生效窗口）', startInput));
            row6.appendChild(buildField('结束时间（有值即下发 show_countdown=true 与 ends_at）', endInput));

            wrap.appendChild(row1);
            wrap.appendChild(row2);
            wrap.appendChild(row3);
            wrap.appendChild(row4);
            wrap.appendChild(row5);
            wrap.appendChild(row6);

            var saveBtn = buildButton('保存', 'cp-btn cp-btn-primary', function () {
                var pages = [];
                if (allBox.checked) pages.push('all');
                else for (var k in pageBoxes) if (pageBoxes.hasOwnProperty(k) && pageBoxes[k].checked) pages.push(k);
                if (!pages.length) pages.push('all');

                saveBtn.disabled = true;
                apiPost('/promotion/save', {
                    id: item.id || undefined,
                    title: titleInput.value.trim(),
                    subtitle: subtitleInput.value.trim(),
                    coupon_code: couponInput.value.trim(),
                    coupon_name: couponNameInput.value.trim(),
                    discount_text: discountInput.value.trim(),
                    button_text: buttonTextInput.value.trim(),
                    button_url: urlInput.value.trim(),
                    button_action: actionSelect.value,
                    pages: pages.join(','),
                    user_scope: scopeSelect.value,
                    sort: sortInput.value,
                    cooldown_hours: cooldownInput.value,
                    show: showSelect.value,
                    starts_at: fromLocalInput(startInput.value),
                    ends_at: fromLocalInput(endInput.value)
                }).then(function () {
                    showBanner(state.banners.list, 'success', '保存成功');
                    state.editing = null;
                    loadList();
                }).catch(function (e) {
                    saveBtn.disabled = false;
                    showBanner(state.banners.list, 'error', e.message);
                });
            });
            var cancelBtn = buildButton('取消', 'cp-btn cp-btn-ghost', function () {
                state.editing = null;
                renderList();
            });
            var actions = el('div', 'cp-edit-actions');
            actions.appendChild(saveBtn);
            actions.appendChild(cancelBtn);
            wrap.appendChild(actions);
            return wrap;
        }

        // ---------------- 行为记录 ----------------
        var recordsPanel = el('div', 'cp-panel');
        recordsPanel.setAttribute('data-panel', 'records');
        recordsPanel.appendChild(banner('records'));
        var recIdInput = buildInput({ placeholder: '活动ID', type: 'number', min: 1, style: 'width:110px' });
        var recEmailInput = buildInput({ placeholder: '用户邮箱', style: 'width:180px' });
        var recActionSelect = buildSelect('', [
            { value: '', text: '全部动作' },
            { value: 'view', text: 'view 展示' },
            { value: 'close', text: 'close 关闭' },
            { value: 'claim', text: 'claim 领取' },
            { value: 'click', text: 'click 点击' }
        ], { style: 'width:150px' });
        var recFromInput = buildInput({ type: 'date', style: 'width:150px' });
        var recToInput = buildInput({ type: 'date', style: 'width:150px' });
        var recFilter = el('div', 'cp-filter-bar');
        recFilter.appendChild(buildField('活动ID', recIdInput));
        recFilter.appendChild(buildField('用户邮箱', recEmailInput));
        recFilter.appendChild(buildField('动作', recActionSelect));
        recFilter.appendChild(buildField('开始日期', recFromInput));
        recFilter.appendChild(buildField('结束日期', recToInput));
        recFilter.appendChild(buildField('', buildButton('查询', 'cp-btn cp-btn-primary', function () {
            state.records.current = 1;
            loadRecords();
        })));
        recFilter.appendChild(buildField('', buildButton('重置', 'cp-btn cp-btn-ghost', function () {
            recIdInput.value = ''; recEmailInput.value = ''; recActionSelect.value = '';
            recFromInput.value = ''; recToInput.value = '';
            state.records.current = 1;
            loadRecords();
        })));
        recordsPanel.appendChild(recFilter);
        var recordsCard = buildCard('行为记录', []);
        recordsPanel.appendChild(recordsCard);
        state.records.card = recordsCard;
        panels.appendChild(recordsPanel);

        function loadRecords() {
            var card = state.records.card;
            if (state.records.inflight) return;
            state.records.inflight = true;
            card.setLoading(true);
            card.setError(null);
            apiGet('/promotion/record/fetch', {
                current: state.records.current,
                page_size: 20,
                promotion_id: recIdInput.value.trim(),
                email: recEmailInput.value.trim(),
                action: recActionSelect.value,
                date_from: recFromInput.value,
                date_to: recToInput.value
            }).then(function (data) {
                state.records.items = (data && data.items) || [];
                state.records.total = (data && data.total) || 0;
                renderRecords();
            }).catch(function (e) { card.setError(e.message); })
              .then(function () { state.records.inflight = false; card.setLoading(false); });
        }

        function renderRecords() {
            var card = state.records.card;
            var body = card.getBody();
            clear(body);
            var rows = [];
            for (var i = 0; i < state.records.items.length; i++) {
                var item = state.records.items[i];
                var tr = el('tr');
                tr.appendChild(el('td', 'cp-col-id', text(item.id)));
                tr.appendChild(el('td', null, text(item.promotion_id) + (item.promotion_title ? '（' + item.promotion_title + '）' : '')));
                tr.appendChild(el('td', null, text(item.email || ('#' + item.user_id))));
                tr.appendChild(el('td', null, text(item.action)));
                tr.appendChild(el('td', null, text(item.channel)));
                tr.appendChild(el('td', null, text(item.ip)));
                tr.appendChild(el('td', null, text(item.created_at_text)));
                rows.push(tr);
            }
            body.appendChild(buildTable([
                { text: 'ID' }, { text: '活动' }, { text: '用户' }, { text: '动作' },
                { text: '来源' }, { text: 'IP' }, { text: '时间' }
            ], rows, '暂无行为记录'));
            body.appendChild(buildPager(state.records.total, state.records.current, 20, function (page) {
                state.records.current = page;
                loadRecords();
            }));
            loadMeta();
        }

        switchTab('list');
        return page;
    }

    /* ================================================================ 挂载 */

    function buildPageFor(containerId) {
        if (containerId === CHECKIN_ROOT) return buildCheckinPage();
        return buildPromotionPage();
    }

    function mount(containerId) {
        var container = document.getElementById(containerId);
        if (!container) return false;
        if (container.getAttribute('data-cp-mounted') === '1') {
            if (container.querySelector('.cp-page')) return true;
            while (container.firstChild) container.removeChild(container.firstChild);
        }
        container.setAttribute('data-cp-mounted', '1');
        container.appendChild(buildPageFor(containerId));
        return true;
    }

    function mountAll() {
        var any = false;
        if (mount(CHECKIN_ROOT)) any = true;
        if (mount(PROMOTION_ROOT)) any = true;
        return any;
    }

    function start() {
        mountAll();
        if (window.MutationObserver) {
            try {
                var observer = new MutationObserver(function () { mountAll(); });
                observer.observe(document.body, { childList: true, subtree: true });
            } catch (e) {
                log('容器检测 observer 注册失败', e);
            }
        }
        window.addEventListener('hashchange', function () { mountAll(); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
