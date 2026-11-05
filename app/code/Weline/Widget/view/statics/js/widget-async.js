/**
 * Weline 部件异步加载基类（所有异步部件模块继承它）。
 *
 * 首屏只输出框架生成的空 SSR 标记（data-widget-async="1"）：
 *   data-widget-fragment  统一片段端点地址（带发布代次 v）
 *   data-widget-async-module  部件自己的 JS 模块名
 *   data-widget-async-defer   1=片段注入后再加载部件模块；0=起手并行预加载
 *   data-widget-async-cache   片段 TTL 秒（服务端 Cache-Control 已带，浏览器按它缓存）
 *   data-widget-config        部件渲染上下文（JSON，提交回片段端点）
 *
 * 这里只做：扫描占位 → 批量取片段 → 注入 → 按 defer 加载部件模块。
 * 请求失败静默不注入；模块失败保留片段、只是没有交互增强。
 */
(function (window, document) {
    'use strict';

    var SELECTOR = '[data-widget-async="1"]';
    var STATE_ATTR = 'data-widget-async-state';
    var MAX_CONCURRENCY = 2;

    var queue = [];
    var active = 0;
    var scheduled = false;

    function splitList(value) {
        return String(value || '')
            .split(',')
            .map(function (item) {
                return item.trim();
            })
            .filter(Boolean);
    }

    function getState(host) {
        return String(host.getAttribute(STATE_ATTR) || '');
    }

    function setState(host, state) {
        try {
            host.setAttribute(STATE_ATTR, state);
        } catch (error) {
            /* ignore */
        }
    }

    function readConfig(host) {
        var raw = host.getAttribute('data-widget-config') || '';
        if (raw === '') {
            return {};
        }
        try {
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function loadModule(name) {
        if (!name) {
            return Promise.resolve(null);
        }
        if (!window.Weline || typeof window.Weline.load !== 'function') {
            return Promise.resolve(null);
        }
        return window.Weline.load(name).catch(function (error) {
            if (window.console && console.debug) {
                console.debug('[WelineWidgetAsync] module load failed:', name, error);
            }
            return null;
        });
    }

    function isDeferred(host) {
        return String(host.getAttribute('data-widget-async-defer') || '1') !== '0';
    }

    function moduleName(host) {
        return String(host.getAttribute('data-widget-async-module') || '').trim();
    }

    function fragmentUrl(host) {
        return String(host.getAttribute('data-widget-fragment') || '').trim();
    }

    function fetchFragment(host) {
        var url = fragmentUrl(host);
        if (url === '') {
            return Promise.resolve('');
        }
        var config = readConfig(host);
        var options = {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Weline-Widget-Async': '1'
            },
            body: JSON.stringify({ params: config.params || {} })
        };

        return fetch(url, options).then(function (response) {
            if (!response.ok) {
                return '';
            }
            return response.json().then(function (payload) {
                return payload && typeof payload.html === 'string' ? payload.html : '';
            });
        }).catch(function () {
            return '';
        });
    }

    function hydrate(host) {
        var state = getState(host);
        if (state === 'loading' || state === 'ready' || state === 'empty') {
            return Promise.resolve(state);
        }

        setState(host, 'loading');
        var name = moduleName(host);
        var deferred = isDeferred(host);
        var modulePromise = deferred ? Promise.resolve(null) : loadModule(name);

        return Promise.all([modulePromise, fetchFragment(host)]).then(function (results) {
            var html = results[1];
            if (html === '') {
                setState(host, 'empty');
                return 'empty';
            }

            host.innerHTML = html;
            setState(host, 'ready');
            try {
                host.dispatchEvent(new CustomEvent('weline:widget:async:ready', {
                    bubbles: true,
                    detail: {
                        code: host.getAttribute('data-widget-code') || '',
                        module: name
                    }
                }));
            } catch (error) {
                /* ignore */
            }

            return deferred ? loadModule(name).then(function () {
                return 'ready';
            }) : 'ready';
        }).catch(function () {
            setState(host, 'error');
            return 'error';
        });
    }

    function pump() {
        while (active < MAX_CONCURRENCY && queue.length > 0) {
            var host = queue.shift();
            if (!host || !host.isConnected) {
                continue;
            }
            if (getState(host) !== '') {
                continue;
            }
            active += 1;
            hydrate(host).finally(function () {
                active -= 1;
                pump();
            });
        }
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var hosts = [];
        if (scope.nodeType === 1 && scope.matches && scope.matches(SELECTOR)) {
            hosts.push(scope);
        }
        Array.prototype.push.apply(hosts, Array.prototype.slice.call(scope.querySelectorAll(SELECTOR)));
        if (hosts.length === 0) {
            return 0;
        }

        hosts.forEach(function (host) {
            if (getState(host) !== '') {
                return;
            }
            if (!isDeferred(host)) {
                loadModule(moduleName(host));
            }
            queue.push(host);
        });
        pump();

        return hosts.length;
    }

    function idle(run) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(run, { timeout: 2000 });
            return;
        }
        window.setTimeout(run, 200);
    }

    function schedule() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        idle(function () {
            scheduled = false;
            try {
                scan(document);
            } catch (error) {
                /* enhancement only */
            }
        });
    }

    function observe() {
        if (typeof window.MutationObserver !== 'function' || !document.body) {
            return;
        }
        var observer = new window.MutationObserver(function (records) {
            for (var i = 0; i < records.length; i += 1) {
                var added = records[i].addedNodes || [];
                for (var j = 0; j < added.length; j += 1) {
                    var node = added[j];
                    if (node && node.nodeType === 1) {
                        schedule();
                        return;
                    }
                }
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.WelineWidgetAsync = {
        __full: true,
        scan: scan,
        hydrate: hydrate
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            observe();
            schedule();
        }, { once: true });
    } else {
        observe();
        schedule();
    }
    window.addEventListener('load', schedule, { once: true });
})(window, document);
