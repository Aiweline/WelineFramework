/**
 * 高级事件链运行时：按 website 注入的 version 缓存定义（无 TTL），
 * localStorage 跨页进度；严格按序匹配；凑齐后 WelinePixel.track(complete_event)。
 */
(function () {
    'use strict';

    if (window.__WelineEventChainsBooted) {
        return;
    }
    window.__WelineEventChainsBooted = true;

    var LS_VER = 'weline_evch_ver';
    var LS_DEFS = 'weline_evch_defs';
    var LS_PROG = 'weline_evch_prog';
    var COMPLETE_FLAG = '__event_chain_complete';

    function cfgBundle() {
        var cfg = window.__WelineVisitorTrackingConfig || {};
        var b = cfg.eventChains || {};
        return {
            version: parseInt(b.version, 10) || 0,
            chains: Array.isArray(b.chains) ? b.chains : []
        };
    }

    function lsGet(key, fallback) {
        try {
            var raw = localStorage.getItem(key);
            if (raw == null || raw === '') return fallback;
            return JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function lsSet(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
        }
    }

    function syncDefsFromRuntime() {
        var bundle = cfgBundle();
        var localVer = parseInt(localStorage.getItem(LS_VER) || '0', 10) || 0;
        if (bundle.version !== localVer) {
            localStorage.setItem(LS_VER, String(bundle.version));
            lsSet(LS_DEFS, bundle.chains);
            lsSet(LS_PROG, {});
            return bundle.chains;
        }
        var defs = lsGet(LS_DEFS, null);
        if (!Array.isArray(defs)) {
            lsSet(LS_DEFS, bundle.chains);
            return bundle.chains;
        }
        return defs;
    }

    function normalizeEvent(name) {
        return String(name || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9_\-]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function pathNorm(p) {
        p = String(p || '');
        if (p === '') return '/';
        return p;
    }

    function stepMatches(step, signal) {
        if (!step || !signal) return false;
        var type = String(step.type || '');
        var sigType = String(signal.type || '');
        if (type === 'page') {
            var isPage = sigType === 'page'
                || (sigType === 'track' && normalizeEvent(signal.event) === 'page_view');
            if (!isPage) return false;
            var path = pathNorm(signal.path);
            var exact = String(step.path || '');
            var prefix = String(step.path_prefix || '');
            if (exact) {
                var a = path.replace(/\/$/, '') || '/';
                var b = exact.replace(/\/$/, '') || '/';
                return a === b;
            }
            if (prefix === '/') {
                return path === '/' || path === '';
            }
            if (prefix) {
                return path.indexOf(prefix) === 0;
            }
            return true;
        }
        if (type === 'track' || type === 'click') {
            var stepPath = pathNorm(step.path || '');
            var isPageSig = sigType === 'page'
                || (sigType === 'track' && normalizeEvent(signal.event) === 'page_view');
            // 路径回放：进入该步记录的 path 即推进
            if (isPageSig && stepPath) {
                var a = pathNorm(signal.path).replace(/\/$/, '') || '/';
                var b = stepPath.replace(/\/$/, '') || '/';
                return a === b;
            }
            if (sigType !== 'track' && sigType !== 'click') return false;
            var want = normalizeEvent(step.event);
            var got = normalizeEvent(signal.event);
            if (!want || !got || want !== got) return false;
            var sel = String(step.selector || '').trim();
            if (sel) {
                var gotSel = String(signal.selector || '');
                return gotSel && (gotSel === sel || gotSel.indexOf(sel) !== -1);
            }
            return true;
        }
        if (type === 'input' || type === 'submit') {
            return sigType === type;
        }
        return false;
    }

    function fireComplete(chain, progressHits) {
        var ev = normalizeEvent(chain.complete_event || chain.close_event);
        if (!ev) return;
        var payload = {};
        payload[COMPLETE_FLAG] = true;
        payload.chain_id = chain.id;
        payload.chain_name = chain.name || '';
        payload.chain_version = parseInt(localStorage.getItem(LS_VER) || '0', 10) || 0;
        payload.funnel_complete = true;
        payload.steps_completed = (chain.steps && chain.steps.length) || 0;
        payload.hits = progressHits || [];
        function doTrack() {
            if (!window.WelinePixel || typeof window.WelinePixel.track !== 'function') {
                return false;
            }
            window.WelinePixel.track(ev, payload);
            return true;
        }
        if (!doTrack()) {
            var n = 0;
            var t = setInterval(function () {
                n += 1;
                if (doTrack() || n > 40) clearInterval(t);
            }, 250);
        }
        try {
            window.dispatchEvent(new CustomEvent('weline:event-chain-complete', {
                detail: { chain: chain, event: ev, payload: payload }
            }));
        } catch (e) {
        }
        if (window.DEV || (location && /test\.weline\.com/.test(location.hostname || ''))) {
            console.log('[WelineEventChain] complete', ev, chain.id);
        }
    }

    function onSignal(signal) {
        var chains = syncDefsFromRuntime();
        if (!chains.length) return;
        var prog = lsGet(LS_PROG, {}) || {};
        var changed = false;
        for (var i = 0; i < chains.length; i++) {
            var chain = chains[i];
            if (!chain || !chain.id || !Array.isArray(chain.steps) || !chain.steps.length) continue;
            var st = prog[chain.id] || { i: 0, hits: [] };
            var idx = parseInt(st.i, 10) || 0;
            if (idx >= chain.steps.length) {
                prog[chain.id] = { i: 0, hits: [] };
                st = prog[chain.id];
                idx = 0;
            }
            var step = chain.steps[idx];
            if (!stepMatches(step, signal)) continue;
            st.hits = Array.isArray(st.hits) ? st.hits : [];
            st.hits.push({
                at: new Date().toISOString(),
                type: signal.type,
                event: signal.event || '',
                path: signal.path || ''
            });
            st.i = idx + 1;
            prog[chain.id] = st;
            changed = true;
            if (st.i >= chain.steps.length) {
                fireComplete(chain, st.hits.slice());
                prog[chain.id] = { i: 0, hits: [] };
            }
            try {
                // 监视流必须用 chain_step，禁止复用 signal.event（如 page_view）：
                // 多条链同页起步时否则会刷出「多个 page_view」假象（实为链进度，非真实 PV）。
                if (window.WelineEventSandbox && typeof window.WelineEventSandbox.emit === 'function') {
                    window.WelineEventSandbox.emit({
                        eventName: 'chain_step',
                        name: 'chain_step',
                        payload: {
                            path: signal.path || '',
                            type: signal.type || '',
                            signal_event: signal.event || '',
                            chain_id: chain.id,
                            chain_name: chain.name || '',
                            step_i: st.i,
                            steps: (chain.steps && chain.steps.length) || 0
                        }
                    }, {
                        source: 'chain',
                        event_hit: true,
                        hit_kind: 'system',
                        chain: {
                            chain_id: chain.id,
                            step_i: st.i,
                            steps: (chain.steps && chain.steps.length) || 0,
                            complete: st.i >= chain.steps.length
                        }
                    });
                }
            } catch (eEmit) {
            }
        }
        if (changed) lsSet(LS_PROG, prog);
    }

    function completeEventSet() {
        var set = {};
        var chains = syncDefsFromRuntime();
        for (var i = 0; i < chains.length; i++) {
            var ev = normalizeEvent(chains[i] && (chains[i].complete_event || chains[i].close_event));
            if (ev) set[ev] = true;
        }
        return set;
    }

    function wrapTrack() {
        if (!window.WelinePixel || typeof window.WelinePixel.track !== 'function') return false;
        if (window.WelinePixel.__eventChainWrapped) return true;
        var orig = window.WelinePixel.track.bind(window.WelinePixel);
        window.WelinePixel.track = function (name, props) {
            var p = props && typeof props === 'object' ? props : {};
            var normalized = normalizeEvent(name);
            // 闭环事件：进度只在客户端；无凑齐标记则不上报（禁止直接 track 冒充）
            var completes = completeEventSet();
            if (normalized && completes[normalized] && !p[COMPLETE_FLAG] && !p.funnel_complete) {
                if (window.DEV || (location && /test\.weline\.com/.test(location.hostname || ''))) {
                    console.log('[WelineEventChain] skip direct complete track', normalized);
                }
                return null;
            }
            var ret = orig(name, props);
            if (!p[COMPLETE_FLAG]) {
                onSignal({
                    type: 'track',
                    event: name,
                    path: location.pathname,
                    selector: p.selector || p.css_selector || ''
                });
            }
            return ret;
        };
        window.WelinePixel.__eventChainWrapped = true;
        return true;
    }

    function boot() {
        syncDefsFromRuntime();
        onSignal({ type: 'page', path: location.pathname, event: 'page_view' });
        if (!wrapTrack()) {
            var n = 0;
            var t = setInterval(function () {
                n += 1;
                if (wrapTrack() || n > 60) clearInterval(t);
            }, 200);
        }
        window.addEventListener('weline:visitor-tracking-config', function () {
            syncDefsFromRuntime();
        });
    }

    window.WelineEventChains = {
        onSignal: onSignal,
        sync: syncDefsFromRuntime,
        progress: function () { return lsGet(LS_PROG, {}) || {}; },
        defs: function () { return syncDefsFromRuntime(); },
        completeEvents: function () { return Object.keys(completeEventSet()); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
