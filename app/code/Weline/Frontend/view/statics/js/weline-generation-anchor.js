/**
 * 世代锚点对齐（Task 4 · 2026-11-01）——前端**唯一**判定"页面是否落后"的地方。
 *
 * 背景：FPC 叶里原本内嵌 `themePublishedVersionId` / `assetVersion`，签章 Cookie 里也有
 * `page_generation`，两边都有值却**没有任何代码比对**，且 `ensureScopeStamp()` 被
 * `isScopeStampAlreadyEnsured()` 短路 → 永远发现不了不一致。
 *
 * 新锚点：`generation_uid = {deploy_build}~{theme_generation}`，由服务端
 * `GenerationRegistry` 统一提供（页面侧写 `Weline.config.generationUid`）。
 * `themePublishedVersionId` / `assetVersion` **不再参与任何判定**，只进日志。
 *
 * 三条防循环硬规则：
 *  - R1 单调：只认"新一代"，任何"把新签章降级成旧值"的方向一律禁止；
 *  - R2 单锚点：**比较用 generation_uid，导航用 page_generation_token**；
 *  - R3 终态：锚点一致即写 sessionStorage，后续加载零请求。
 */
(function (global) {
    'use strict';

    const LAST_ALIGNED_KEY = 'weline.storefront_last_aligned_anchor';
    const MIN_INTERVAL_MS = 30000;
    const STATE_KEY = 'weline.storefront_anchor_align_at';

    const config = () => (global.Weline && global.Weline.config) || {};

    /** 页面锚点：只读 generationUid / data-weline-generation；缺失返回 ''。 */
    const readPageAnchor = () => {
        try {
            const fromConfig = String(config().generationUid || '').trim();
            if (fromConfig !== '') {
                return fromConfig;
            }
            const el = global.document && global.document.documentElement;
            const fromAttr = el && el.getAttribute ? String(el.getAttribute('data-weline-generation') || '').trim() : '';
            return fromAttr;
        } catch (_e) {
            return '';
        }
    };

    const readUrlToken = () => {
        try {
            const url = new URL(global.location.href);
            const token = String(url.searchParams.get('w_pg') || '').trim().toLowerCase();
            return /^[a-f0-9]{16}$/.test(token) ? token : '';
        } catch (_e) {
            return '';
        }
    };

    /** R1：只认"新一代"。a 比 b 新返回 true；无法比较返回 false（不比就前进）。 */
    const anchorNewer = (a, b) => {
        a = String(a || '').trim();
        b = String(b || '').trim();
        if (a === '' || b === '' || a === b) {
            return false;
        }
        const build = (v) => {
            const i = v.indexOf('~');
            return i < 0 ? v : v.slice(0, i);
        };
        // 部署号不同：以部署号字典序作为单调方向（部署号是内容指纹，不做大小假设时只认"不同"）
        const buildA = build(a);
        const buildB = build(b);
        if (buildA !== buildB) {
            return true; // 版本不同即视为需要跟进，由服务端 token 决定方向
        }
        return false;
    };

    const readLastAligned = () => {
        try {
            return String(global.sessionStorage.getItem(LAST_ALIGNED_KEY) || '');
        } catch (_e) {
            return '';
        }
    };

    const writeLastAligned = (anchor) => {
        try {
            global.sessionStorage.setItem(LAST_ALIGNED_KEY, String(anchor || ''));
        } catch (_e) {
            /* ignore */
        }
    };

    const throttled = () => {
        try {
            const last = Number(global.sessionStorage.getItem(STATE_KEY) || 0) || 0;
            const now = Date.now();
            if (last > 0 && (now - last) < MIN_INTERVAL_MS) {
                return true;
            }
            global.sessionStorage.setItem(STATE_KEY, String(now));
        } catch (_e) {
            /* ignore */
        }
        return false;
    };

    /**
     * 页面加载后调用一次。返回 true 表示"已对齐/无需动作"，false 表示"需要跟进"。
     * 不直接导航：导航交给 `navigateToPageToken`，由调用方决定（R2）。
     */
    const ensureScopeAnchorAligned = async () => {
        const pageAnchor = readPageAnchor();
        const lastAligned = readLastAligned();
        // R3 终态：本 tab 已对齐过同一锚点 → 零请求
        if (pageAnchor !== '' && pageAnchor === lastAligned) {
            return { aligned: true, reason: 'terminal' };
        }
        if (typeof global.fetch !== 'function') {
            return { aligned: false, reason: 'no_fetch' };
        }
        if (throttled()) {
            return { aligned: false, reason: 'throttled' };
        }

        let payload = null;
        try {
            const resp = await global.fetch('/api/frontend/scope-bootstrap', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({}),
            });
            payload = await resp.json();
        } catch (_e) {
            return { aligned: false, reason: 'request_failed' };
        }
        if (!payload || payload.ok === false) {
            return { aligned: false, reason: 'denied' };
        }

        const serverAnchor = String(payload.generation_uid || '').trim();
        const acceptable = Array.isArray(payload.acceptable_generation_uids)
            ? payload.acceptable_generation_uids.map((v) => String(v || '').trim()).filter(Boolean)
            : [];
        const serverToken = String(payload.page_generation_token || '').trim().toLowerCase();

        // 一致 → 终态
        if (serverAnchor !== '' && serverAnchor === pageAnchor) {
            writeLastAligned(pageAnchor);
            return { aligned: true, reason: 'match', anchor: pageAnchor };
        }
        // 页面锚点仍在 3 版窗口内 → 正常服务，不跟进
        if (pageAnchor !== '' && acceptable.indexOf(pageAnchor) >= 0) {
            writeLastAligned(pageAnchor);
            return { aligned: true, reason: 'in_window', anchor: pageAnchor };
        }
        // 老缓存叶没有新锚点 → 只标记，不导航（避免旧页面反复跳）
        if (pageAnchor === '') {
            writeLastAligned(serverAnchor);
            return { aligned: true, reason: 'legacy_page', anchor: serverAnchor };
        }
        // R2：已完成在同一个桶里（URL token 相同）→ 只重签，不导航
        const urlToken = readUrlToken();
        if (serverToken !== '' && serverToken === urlToken) {
            writeLastAligned(serverAnchor);
            return { aligned: true, reason: 'same_bucket', anchor: serverAnchor };
        }

        return {
            aligned: false,
            reason: anchorNewer(serverAnchor, pageAnchor) ? 'server_newer' : 'unknown',
            anchor: serverAnchor,
            token: serverToken,
        };
    };

    /** R2：导航只认服务端下发的 page_generation_token。 */
    const navigateToPageToken = (token) => {
        const normalized = String(token || '').trim().toLowerCase();
        if (!/^[a-f0-9]{16}$/.test(normalized)) {
            return false;
        }
        try {
            const url = new URL(global.location.href);
            url.searchParams.set('w_pg', normalized);
            global.location.replace(url.toString());
            return true;
        } catch (_e) {
            return false;
        }
    };

    global.WelineGenerationAnchor = {
        readPageAnchor,
        anchorNewer,
        ensureScopeAnchorAligned,
        navigateToPageToken,
        LAST_ALIGNED_KEY,
        MIN_INTERVAL_MS,
    };
})(typeof window !== 'undefined' ? window : globalThis);
