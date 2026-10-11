/**
 * head 顺序自检 —— 前端访问时运行，产出可机读的验收结论。
 *
 * 契约（与 dev/tmp/head-order-accept.php 共用同一份判据）：
 *   1. css-before-js        第一条 stylesheet 早于第一条外部 <script src>
 *   2. layout-css-present   存在带 data-weline-layout-css 的布局样式
 *   3. no-blocking-head-js  head 内没有「无 defer/async/type=module」的外部脚本
 *   4. fouc-gate-released   html[data-weline-css] 最终不是 pending（并给出解锁耗时）
 *
 * 输出：
 *   - window.__WELINE_HEAD_ORDER__  = 结论对象（供 e2e / 控制台读取）
 *   - window 事件 'weline:head-order'（detail = 结论对象，供自动化等待）
 *   - 控制台表格：DEV / ?weline-head-order=1 时打印
 *
 * 只读 DOM，不发请求，不修改页面。
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || window.__WELINE_HEAD_ORDER__) {
        return;
    }

    var docEl = document.documentElement;
    var head = document.head;
    if (!docEl || !head) {
        return;
    }

    var CSS_STATE_ATTR = 'data-weline-css';

    function attrLower(el, name) {
        var v = el.getAttribute ? el.getAttribute(name) : null;
        return (v || '').toLowerCase();
    }

    function collect() {
        var elements = [];
        var kids = head.childNodes;
        for (var i = 0; i < kids.length; i++) {
            if (kids[i].nodeType !== 1) {
                continue;
            }
            elements.push({ el: kids[i], index: i });
        }

        var scripts = [];
        var styles = [];
        var orders = [];

        for (var j = 0; j < elements.length; j++) {
            var el = elements[j].el;
            var index = elements[j].index;
            var tag = (el.tagName || '').toLowerCase();

            if (tag === 'script' && el.getAttribute('src')) {
                var isModule = attrLower(el, 'type') === 'module';
                var isDefer = el.hasAttribute('defer');
                var isAsync = el.hasAttribute('async');
                scripts.push({
                    src: el.getAttribute('src'),
                    index: index,
                    module: isModule,
                    defer: isDefer,
                    async: isAsync,
                    blocking: !isModule && !isDefer && !isAsync
                });
            } else if (tag === 'link' && attrLower(el, 'rel') === 'stylesheet') {
                styles.push({
                    href: el.getAttribute('href') || '',
                    index: index,
                    layoutCss: el.hasAttribute('data-weline-layout-css')
                });
            }

            var order = el.getAttribute('data-order');
            if (order !== null && /^-?\d+$/.test(order)) {
                orders.push({ index: index, order: parseInt(order, 10), tag: tag });
            }
        }

        return { scripts: scripts, styles: styles, orders: orders, elementCount: elements.length };
    }

    function build(gateState, gateMs) {
        var c = collect();

        var firstStyle = c.styles.length ? c.styles[0].index : -1;
        var firstScript = c.scripts.length ? c.scripts[0].index : -1;
        var blocking = [];
        for (var i = 0; i < c.scripts.length; i++) {
            if (c.scripts[i].blocking) {
                blocking.push(c.scripts[i]);
            }
        }
        var layoutStyles = [];
        for (var k = 0; k < c.styles.length; k++) {
            if (c.styles[k].layoutCss) {
                layoutStyles.push(c.styles[k]);
            }
        }

        var checks = [
            {
                id: 'css-before-js',
                ok: firstStyle !== -1 && (firstScript === -1 || firstStyle < firstScript),
                detail: 'firstStylesheet=' + firstStyle + ' firstScript=' + firstScript
            },
            {
                id: 'layout-css-present',
                ok: layoutStyles.length > 0,
                detail: 'layoutCss=' + layoutStyles.length
            },
            {
                id: 'no-blocking-head-js',
                ok: blocking.length === 0,
                detail: 'blocking=' + blocking.length + '/' + c.scripts.length
            },
            {
                id: 'fouc-gate-released',
                ok: gateState !== 'pending',
                detail: 'state=' + gateState + (gateMs === null ? '' : ' readyIn=' + gateMs + 'ms')
            }
        ];

        var passed = 0;
        for (var m = 0; m < checks.length; m++) {
            if (checks[m].ok) {
                passed++;
            }
        }

        var orderValues = [];
        for (var n = 0; n < c.orders.length; n++) {
            orderValues.push(c.orders[n].order);
        }

        return {
            ok: passed === checks.length,
            passed: passed,
            total: checks.length,
            checks: checks,
            stats: {
                headElements: c.elementCount,
                stylesheets: c.styles.length,
                scripts: c.scripts.length,
                blockingScripts: blocking.length,
                blockingSrc: blocking.map(function (s) { return s.src; }),
                dataOrderValues: orderValues
            },
            url: location.href,
            at: new Date().toISOString()
        };
    }

    function publish(verdict, verbose) {
        window.__WELINE_HEAD_ORDER__ = verdict;

        if (verbose && typeof console !== 'undefined' && console.log) {
            console.log(
                '[head-order] ' + (verdict.ok ? 'PASS' : 'FAIL') + ' (' + verdict.passed + '/' + verdict.total + ')',
                verdict.stats
            );
            if (console.table) {
                console.table(verdict.checks.map(function (c) {
                    return { check: c.id, ok: c.ok, detail: c.detail };
                }));
            }
        }

        try {
            window.dispatchEvent(new CustomEvent('weline:head-order', { detail: verdict }));
        } catch (e) {
        }
    }

    var verbose = !!(window.DEV
        || window.WELINE_ENV === 'DEV'
        || window.__WELINE_DEBUG__
        || /[?&]weline-head-order=1(&|$)/.test(location.search));

    var initialGate = docEl.getAttribute(CSS_STATE_ATTR);
    publish(build(initialGate, null), verbose);

    var settled = false;
    var startedAt = (window.performance && performance.now) ? performance.now() : Date.now();

    function settle(state) {
        if (settled) {
            return;
        }
        settled = true;
        var now = (window.performance && performance.now) ? performance.now() : Date.now();
        publish(build(state, Math.round(now - startedAt)), verbose);
    }

    if (initialGate !== 'pending') {
        settle(initialGate);
        return;
    }

    if (typeof MutationObserver === 'function') {
        var observer = new MutationObserver(function () {
            var state = docEl.getAttribute(CSS_STATE_ATTR);
            if (state !== 'pending') {
                observer.disconnect();
                settle(state);
            }
        });
        observer.observe(docEl, { attributes: true, attributeFilter: [CSS_STATE_ATTR] });
        window.setTimeout(function () {
            observer.disconnect();
            settle(docEl.getAttribute(CSS_STATE_ATTR));
        }, 2500);
        return;
    }

    settle(docEl.getAttribute(CSS_STATE_ATTR));
})();
