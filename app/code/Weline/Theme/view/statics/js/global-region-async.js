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

    var CACHE_PREFIX = 'weline.region.cache.';

    /**
     * 缓存作用域：部件 id + 语言前缀 + 币种 + 发布代次（不含页面路径）。
     * 同一部件在不同页面是同一份内容，TTL 内不应重复回源。
     */
    function cacheScope(node, url) {
        var id = node.getAttribute('data-global-region-async') || '';
        var cur = '';
        var version = '';
        try {
            var parsed = new URL(url, window.location.origin);
            cur = parsed.searchParams.get('cur') || '';
            version = parsed.searchParams.get('v') || '';
        } catch (e) {
            // 解析失败就用空维度，退化到按部件 id 缓存。
        }
        var match = String(url).match(/^\/([a-z]{2}_[A-Za-z]{2,8})\//);
        var locale = match ? match[1] : '';

        return [id, locale, cur, version].join('|');
    }

    function readCache(key, ttl) {
        if (ttl <= 0) {
            return '';
        }
        try {
            var raw = window.localStorage.getItem(CACHE_PREFIX + key);
            if (!raw) {
                return '';
            }
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.html !== 'string' || !parsed.exp) {
                return '';
            }
            if (Number(parsed.exp) <= Date.now()) {
                window.localStorage.removeItem(CACHE_PREFIX + key);
                return '';
            }
            return parsed.html;
        } catch (e) {
            return '';
        }
    }

    function writeCache(key, html, ttl) {
        if (ttl <= 0 || !html) {
            return;
        }
        try {
            window.localStorage.setItem(
                CACHE_PREFIX + key,
                JSON.stringify({ html: html, exp: Date.now() + ttl * 1000 })
            );
        } catch (e) {
            // 配额满 / 隐私模式禁用：忽略，下次照常回源。
        }
    }

    function paint(node, html) {
        if (!html) {
            return;
        }
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
        // 槽位收起状态由主题浮动层脚本按 localStorage 恢复：这里只通报「已填充」，
        // 不强行展开（收起后必须等用户点唤出才出现）。
        var slot = node.closest('[data-global-region-float]');
        if (slot) {
            slot.dispatchEvent(new CustomEvent('weline:float-slot-filled', { bubbles: true }));
            // 通知浮动层按 localStorage 收敛一次；禁止本文件再改 is-edge-* class。
            requestFloatLayerSync();
        }
    }

    function hydrate(node) {
        var url = node.getAttribute('data-global-region-endpoint') || '';
        if (!url) {
            node.removeAttribute('data-global-region-async');
            return;
        }
        var ttl = parseInt(node.getAttribute('data-global-region-ttl') || '0', 10) || 0;
        // 同一个部件同一个 URL 的内容在 TTL 内不会变：本地命中就不再回源，
        // 避免每翻一个页面都把全局部件重新请求一遍。
        // 缓存键不含页面路径（同一部件在不同页面是同一份内容），只保留
        // 部件 id + 币种 + 发布代次；语言由 URL 前缀决定，仍在内容里生效，
        // 但各语言页面彼此独立缓存（见 cacheScope）。
        var cacheKey = cacheScope(node, url);
        var cached = readCache(cacheKey, ttl);
        if (cached) {
            paint(node, cached);
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
                    writeCache(cacheKey, html, ttl);
                    paint(node, html);
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
            adoptSlot(slots[i], layer);
        }
        // 槽位收起/展开只由 storefront-float-layer 按 localStorage 裁决。
        // 这里禁止再挂 class MutationObserver 去抢 is-edge-*（会与 syncDock 死循环）。
        // Marker: storefront-float-no-keep-expanded-observer-v1
        requestFloatLayerSync();
    }

    function requestFloatLayerSync() {
        var api = window.WelineStorefrontFloatLayer;
        if (api && typeof api.sync === 'function') {
            try {
                api.sync();
            } catch (e) {
                /* ignore */
            }
            return;
        }
        // 浮动层脚本可能还在懒加载：等一帧再试一次即可，不要轮询。
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                var late = window.WelineStorefrontFloatLayer;
                if (late && typeof late.sync === 'function') {
                    try {
                        late.sync();
                    } catch (e2) {
                        /* ignore */
                    }
                }
            });
        }
    }

    /**
     * 片段自带的浮层槽只有 `data-global-region-float="start|side|…"`，
     * 缺主题的定位 class/data-float-* 就会被 absolute 定位到容器左上角
     * （表现：下载 App、分销跑到顶部）。这里按方向补齐主题槽位契约。
     */
    function adoptSlot(slot, layer) {
        var dir = (slot.getAttribute('data-global-region-float') || '').trim();
        if (!dir) {
            layer.appendChild(slot);
            return;
        }
        slot.classList.add('w-storefront-float-layer__slot');
        slot.classList.add('w-storefront-float-layer__slot--' + dir);
        if (!slot.getAttribute('data-slot-id')) {
            slot.setAttribute('data-slot-id', 'storefront-float-' + dir);
        }
        if (!slot.getAttribute('data-float-slot')) {
            slot.setAttribute('data-float-slot', dir);
        }
        if (!slot.getAttribute('data-float-dock')) {
            slot.setAttribute('data-float-dock', dockFor(dir));
        }
        layer.appendChild(slot);
    }

    function dockFor(dir) {
        switch (dir) {
            case 'start':
            case 'middle-start':
            case 'top-start':
                return 'left';
            case 'top-center':
                return 'up';
            case 'bottom-center':
                return 'down';
            default:
                return 'right';
        }
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
