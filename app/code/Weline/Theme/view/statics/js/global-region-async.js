/**
 * 全局区异步片段的水合器。
 *
 * 全局区里的 base 组件多数在首屏并不需要（客服浮层、Cookie 同意条、进店音乐、
 * 联盟/社媒入口等），把它们的标记直接写进每个页面只会撑大 HTML。片段因此只
 * 留一个占位节点，由这里在空闲时段按需取回标记并替换，页面体积与首屏阻塞都不受影响。
 *
 * 需要立即生效的基础设施（例如像素引导）在片段里声明 `@global-region.eager {true}`，
 * 不会生成占位节点，仍由服务端直接内联。
 */
(function () {
    'use strict';

    var SELECTOR = '[data-global-region-async]';
    var state = { started: false, queue: [], running: 0, limit: 2 };

    function hydrate(node) {
        var url = node.getAttribute('data-global-region-endpoint') || '';
        if (!url) {
            node.removeAttribute('data-global-region-async');
            return;
        }
        state.running++;
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.ok ? response.text() : '';
            })
            .then(function (payload) {
                var html = payload;
                // The endpoint answers with the framework's JSON envelope ({html: "…"}).
                try {
                    var parsed = JSON.parse(payload);
                    if (parsed && typeof parsed.html === 'string') {
                        html = parsed.html;
                    }
                } catch (e) {
                    // Plain HTML response: keep it as-is.
                }
                if (html) {
                    node.innerHTML = html;
                    node.hidden = false;
                    // 片段的 CSS/JS 必须搬到 head：主题的浮动层脚本会把槽位内容重新包一层
                    // （w-storefront-float-edge__chrome），留在槽里的 <link>/<script> 会被丢掉，
                    // 表现就是组件出来了但没有样式。
                    hoistAssets(node);
                    // 水合进来的部件声明的模块必须在这里激活：
                    // weline.js 的 data-weline-load 扫描只在 DOMContentLoaded 跑一次，
                    // 异步注入的部件永远赶不上那一轮（进店音乐 / 悬浮客服点不动就是这个原因）。
                    activateDeclaredModules(node);
                    // 浮层槽位在「空的时候」会被浮动层 JS 收敛成 is-edge-collapsed；
                    // 内容补进来之后必须展开，否则组件虽然水合了却看不见（右下角客服即此例）。
                    var slot = node.closest('[data-global-region-float]');
                    if (slot) {
                        slot.classList.remove('is-edge-collapsed');
                        slot.classList.add('is-edge-expanded');
                        slot.dispatchEvent(new CustomEvent('weline:float-slot-filled', { bubbles: true }));
                    }
                }
                node.removeAttribute('data-global-region-async');
            })
            .catch(function () {
                node.removeAttribute('data-global-region-async');
            })
            .then(function () {
                state.running--;
                pump();
            });
    }

    /**
     * 激活水合内容里声明的模块（原生入口：Weline.load）。
     *
     * `data-weline-load` / `data-weline-declare` 是主题部件的模块声明协议，
     * 由 weline.js 在 DOMContentLoaded 统一扫描。异步片段晚于那一轮注入，
     * 声明本身不会再生效，所以水合方必须负责把新内容里的声明交给原生加载器。
     *
     * 只做「按声明调用 Weline.load」——重试、去重、已加载跳过都由加载器负责
     * （shouldSkipAttributeLoad）。这里不关心加载时机策略，也不重复实现扫描逻辑。
     */
    function activateDeclaredModules(root) {
        if (!root || typeof root.querySelectorAll !== 'function') {
            return;
        }
        var loader = window.Weline;
        var load = loader && typeof loader.load === 'function' ? loader.load.bind(loader) : null;
        if (!load) {
            return;
        }
        var names = [];
        var collect = function (attr) {
            var nodes = root.querySelectorAll('[' + attr + ']');
            for (var i = 0; i < nodes.length; i++) {
                var raw = String(nodes[i].getAttribute(attr) || '');
                var parts = raw.split(',');
                for (var j = 0; j < parts.length; j++) {
                    var name = parts[j].trim();
                    // 'dom' 是探测标记，不是模块。
                    if (name && name !== 'dom' && names.indexOf(name) === -1) {
                        names.push(name);
                    }
                }
            }
        };
        collect('data-weline-load');
        collect('data-weline-declare');
        for (var k = 0; k < names.length; k++) {
            try {
                Promise.resolve(load(names[k])).catch(function () {
                    // 单个模块失败不影响其它模块；错误由加载器自身记录。
                });
            } catch (e) {
                // 同步异常同样不阻断水合流程。
            }
        }
    }

    /** 把水合内容里的样式/脚本搬到 head（脚本必须重新创建才会执行）。 */
    function hoistAssets(root) {
        var links = root.querySelectorAll('link[rel="stylesheet"]');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href');
            if (!href || document.querySelector('link[data-global-region-asset="' + href.replace(/"/g, '') + '"]')) {
                links[i].remove();
                continue;
            }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.setAttribute('data-global-region-asset', href);
            document.head.appendChild(link);
            links[i].remove();
        }
        var scripts = root.querySelectorAll('script[src]');
        for (var j = 0; j < scripts.length; j++) {
            var src = scripts[j].getAttribute('src');
            if (!src || document.querySelector('script[data-global-region-asset="' + src.replace(/"/g, '') + '"]')) {
                scripts[j].remove();
                continue;
            }
            var script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.setAttribute('data-global-region-asset', src);
            document.head.appendChild(script);
            scripts[j].remove();
        }
    }

    function pump() {
        while (state.running < state.limit && state.queue.length) {
            hydrate(state.queue.shift());
        }
    }

    /**
     * 把浮层槽位挂进主题的浮动层容器。
     *
     * 主题约定：`#w-storefront-float-layer` 是 `position: fixed` 的容器，槽位用
     * `w-storefront-float-layer__slot--{start|end|side|middle-start|…}` 定位。全局区片段
     * 会渲染在 body 末尾（容器之外），若不搬进容器，`position: absolute` 会相对正文定位——
     * 表现就是「客服不在右下角」「社媒图标挤成一排」。
     */
    function adoptFloats() {
        var slots = document.querySelectorAll('[data-global-region-float]');
        if (!slots.length) {
            return;
        }
        var layer = document.getElementById('w-storefront-float-layer');
        if (!layer) {
            layer = document.createElement('div');
            layer.id = 'w-storefront-float-layer';
            layer.className = 'w-storefront-float-layer';
            layer.setAttribute('data-storefront-float-layer', '');
            layer.setAttribute('data-weline-load', 'storefrontFloatLayer');
            layer.setAttribute('data-testid', 'storefront-float-layer');
            layer.setAttribute('aria-live', 'off');
            document.body.appendChild(layer);
        }
        for (var i = 0; i < slots.length; i++) {
            layer.appendChild(slots[i]);
            keepSlotExpanded(slots[i]);
        }
    }

    /**
     * 主题的浮动层 JS 会把「初始化时为空」的槽位收敛成 is-edge-collapsed，之后即使内容补进来
     * 也不会自己展开（右下角客服看不见就是这个原因）。这里在内容水合后保持展开，
     * 但尊重用户的主动收起（点了收起按钮就不再干预）。
     */
    function keepSlotExpanded(slot) {
        if (!slot || typeof MutationObserver === 'undefined') {
            return;
        }
        var busy = false;
        var apply = function () {
            if (busy || slot.dataset.regionUserCollapsed === '1') {
                return;
            }
            if (slot.querySelector('[data-global-region-async]')) {
                return; // 还没水合，先不动
            }
            if (slot.classList.contains('is-edge-collapsed')) {
                busy = true;
                slot.classList.remove('is-edge-collapsed');
                slot.classList.add('is-edge-expanded');
                busy = false;
            }
        };
        slot.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.closest && target.closest('[data-edge-dismiss], .w-storefront-float-edge__dismiss')) {
                slot.dataset.regionUserCollapsed = '1';
            }
        }, true);
        new MutationObserver(apply).observe(slot, { attributes: true, attributeFilter: ['class'] });
        apply();
    }

    function scan() {
        adoptFloats();
        var nodes = document.querySelectorAll(SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            state.queue.push(nodes[i]);
        }
        pump();
    }

    function start() {
        if (state.started) {
            return;
        }
        state.started = true;
        // Yield to the browser: hydration must never compete with first paint.
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(scan, { timeout: 1500 });
        } else {
            setTimeout(scan, 200);
        }
    }

    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start, { once: true });
    }

    window.WelineGlobalRegion = { hydrate: scan };
})();
