/**
 * Backend sidebar menu signal badges (admin.menuSignals).
 * Counts are never SSR'd — paint only after Query refresh.
 *
 * Depends on MenuRender empty hooks: [data-backend-menu-signal]
 * (+ optional [data-backend-menu-signal-badge] / .w-backend-nav__signal-badge).
 * Clickable badges open a Weline UI w-menu panel listing Provider deep-link items
 * (message/session hrefs) or contributing leaf menus. Leaf menu <a href> is
 * rewritten to primary_href when count>0 and restored from data-backend-menu-href-base
 * when cleared. Admin JS never invents business URLs.
 */
(function (global) {
    'use strict';

    var POLL_MS = 30000;
    var adminApiPromise = null;
    var pollTimer = null;
    var visibilityBound = false;
    var clickBound = false;
    var historyBound = false;
    var notedInitialView = false;
    var refreshSeq = 0;
    var lastByCode = {};
    var lastRawByCode = {};
    var viewedHrefKeys = Object.create(null);
    var reopenedHrefKeys = Object.create(null);
    var lastLeavesByBadge = typeof WeakMap === 'function' ? new WeakMap() : null;

    function ensureWelineApi() {
        if (global.Weline && global.Weline.Api && typeof global.Weline.Api.resource === 'function') {
            return Promise.resolve(global.Weline.Api);
        }
        if (global.Weline && typeof global.Weline.load === 'function') {
            return global.Weline.load('api').then(function () {
                if (!global.Weline || !global.Weline.Api || typeof global.Weline.Api.resource !== 'function') {
                    throw new Error('[BackendMenuSignals] Weline.Api unavailable after load.');
                }
                return global.Weline.Api;
            });
        }
        return Promise.reject(new Error('[BackendMenuSignals] Weline.Api unavailable.'));
    }

    function getAdminApi() {
        if (!adminApiPromise) {
            adminApiPromise = ensureWelineApi().then(function (api) {
                return api.resource('admin');
            });
        }
        return adminApiPromise;
    }

    function unwrapPayload(result) {
        var current = result && typeof result === 'object' ? result : {};
        for (var depth = 0; depth < 3; depth += 1) {
            if (!current || typeof current !== 'object') {
                break;
            }
            if (Object.prototype.hasOwnProperty.call(current, 'by_code')
                || Object.prototype.hasOwnProperty.call(current, 'total')) {
                break;
            }
            if (current.data && typeof current.data === 'object') {
                current = current.data;
                continue;
            }
            break;
        }
        var byCode = current && current.by_code && typeof current.by_code === 'object'
            ? current.by_code
            : {};
        return {
            total: Math.max(0, Number(current && current.total) || 0),
            by_code: byCode,
        };
    }

    function formatCount(n) {
        var count = Math.max(0, Number(n) || 0);
        if (count <= 0) {
            return '';
        }
        return count > 99 ? '99+' : String(count);
    }

    /**
     * Normalize by_code[code]: legacy int OR {count, items[], primary_href}.
     */
    function normalizeCodeEntry(raw) {
        if (typeof raw === 'number') {
            return {
                count: Math.max(0, Number(raw) || 0),
                items: [],
                primary_href: '',
            };
        }
        if (raw && typeof raw === 'object') {
            var items = [];
            if (Array.isArray(raw.items)) {
                for (var i = 0; i < raw.items.length; i += 1) {
                    var row = raw.items[i];
                    if (!row || typeof row !== 'object') {
                        continue;
                    }
                    var href = String(row.href || '').trim();
                    if (href === '') {
                        continue;
                    }
                    items.push({
                        id: String(row.id || href).trim(),
                        title: String(row.title || row.id || href).trim(),
                        href: href,
                        count: Math.max(0, Number(row.count) || 1),
                    });
                }
            }
            return {
                count: Math.max(0, Number(raw.count) || 0),
                items: items,
                primary_href: String(raw.primary_href || (items[0] && items[0].href) || '').trim(),
            };
        }
        return { count: 0, items: [], primary_href: '' };
    }

    function entryCount(byCode, code) {
        if (!hasOwnCount(byCode, code)) {
            return 0;
        }
        return normalizeCodeEntry(byCode[code]).count;
    }

    function hasOwnCount(byCode, code) {
        return !!(byCode && code !== '' && Object.prototype.hasOwnProperty.call(byCode, code));
    }

    function signalSearchRoot(host) {
        // Group hooks may still sit on <summary>; rollup must search from the entry <li>
        // so child menu items under details>ul are included.
        if (host && typeof host.closest === 'function') {
            var entry = host.closest('.w-backend-nav__entry');
            if (entry) {
                return entry;
            }
        }
        return host;
    }

    function isSignalLeaf(node) {
        var root = signalSearchRoot(node);
        var descendants = root.querySelectorAll('[data-backend-menu-signal]');
        for (var i = 0; i < descendants.length; i += 1) {
            if (descendants[i] !== node && descendants[i] !== root) {
                return false;
            }
        }
        return true;
    }

    function sumDescendantLeafCounts(host, byCode) {
        var sum = 0;
        var root = signalSearchRoot(host);
        var descendants = root.querySelectorAll('[data-backend-menu-signal]');
        for (var i = 0; i < descendants.length; i += 1) {
            var child = descendants[i];
            if (child === host || child === root || !isSignalLeaf(child)) {
                continue;
            }
            var childCode = String(child.getAttribute('data-backend-menu-signal') || '').trim();
            if (childCode === '') {
                continue;
            }
            sum += entryCount(byCode, childCode);
        }
        return sum;
    }

    function resolveCountForNode(host, byCode) {
        var code = String(host.getAttribute('data-backend-menu-signal') || '').trim();
        if (hasOwnCount(byCode, code)) {
            return entryCount(byCode, code);
        }
        if (!isSignalLeaf(host)) {
            return sumDescendantLeafCounts(host, byCode);
        }
        return code !== '' ? entryCount(byCode, code) : 0;
    }

    function resolveBadgeHost(signalNode) {
        if (signalNode.matches('.w-backend-nav__item, .w-backend-nav__group')) {
            return signalNode;
        }
        var item = signalNode.querySelector(
            ':scope > a.w-backend-nav__item, :scope > .w-backend-nav__item, '
            + ':scope > details.w-backend-nav__disclosure > summary.w-backend-nav__item'
        );
        if (item) {
            return item;
        }
        return signalNode.querySelector('.w-backend-nav__item, .w-backend-nav__group') || signalNode;
    }

    function ensureBadge(signalNode) {
        var existing = signalNode.matches('[data-backend-menu-signal-badge], .w-backend-nav__signal-badge')
            ? signalNode
            : signalNode.querySelector('[data-backend-menu-signal-badge], .w-backend-nav__signal-badge');
        if (existing) {
            return existing;
        }
        var host = resolveBadgeHost(signalNode);
        var badge = global.document.createElement('span');
        badge.className = 'w-backend-nav__signal-badge';
        badge.setAttribute('data-backend-menu-signal-badge', '');
        badge.hidden = true;
        badge.setAttribute('aria-hidden', 'true');

        var chevron = host.querySelector(':scope > .w-icon:last-child');
        if (chevron && chevron.parentNode === host) {
            host.insertBefore(badge, chevron);
        } else {
            host.appendChild(badge);
        }
        return badge;
    }

    function leafTitle(signalNode) {
        var host = resolveBadgeHost(signalNode);
        var titleSpan = host.querySelector(':scope > span:not([data-backend-menu-signal-badge]):not(.w-backend-nav__signal-badge)');
        if (titleSpan) {
            return String(titleSpan.textContent || '').trim();
        }
        return String(host.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function leafAnchor(signalNode) {
        if (signalNode && signalNode.matches && signalNode.matches('a.w-backend-nav__item[href]')) {
            return signalNode;
        }
        var root = signalSearchRoot(signalNode);
        if (!root || typeof root.querySelector !== 'function') {
            return null;
        }
        return root.querySelector('a.w-backend-nav__item[href]');
    }

    function leafHref(signalNode) {
        var link = leafAnchor(signalNode);
        if (!link) {
            return '';
        }
        var base = String(link.getAttribute('data-backend-menu-href-base') || '').trim();
        if (base !== '') {
            return base;
        }
        return String(link.getAttribute('href') || '').trim();
    }

    /**
     * Rewrite leaf menu href to Provider primary_href while count>0; restore base when cleared.
     */
    function applyLeafHrefOverride(signalNode, byCode) {
        if (!isSignalLeaf(signalNode)) {
            return;
        }
        var link = leafAnchor(signalNode);
        if (!link) {
            return;
        }
        if (!link.hasAttribute('data-backend-menu-href-base')) {
            link.setAttribute(
                'data-backend-menu-href-base',
                String(link.getAttribute('href') || '').trim()
            );
        }
        var base = String(link.getAttribute('data-backend-menu-href-base') || '').trim();
        var code = String(signalNode.getAttribute('data-backend-menu-signal') || '').trim();
        var entry = normalizeCodeEntry(hasOwnCount(byCode, code) ? byCode[code] : null);
        if (entry.count > 0 && entry.primary_href !== '') {
            link.setAttribute('href', entry.primary_href);
            return;
        }
        if (base !== '') {
            link.setAttribute('href', base);
        }
    }

    function collectContributingLeaves(signalNode, byCode) {
        var leaves = [];
        var seen = {};
        var root = signalSearchRoot(signalNode);
        var candidates = [];
        if (isSignalLeaf(signalNode)) {
            candidates.push(signalNode);
        } else {
            var descendants = root.querySelectorAll('[data-backend-menu-signal]');
            for (var i = 0; i < descendants.length; i += 1) {
                var child = descendants[i];
                if (child === signalNode || child === root || !isSignalLeaf(child)) {
                    continue;
                }
                candidates.push(child);
            }
        }
        for (var j = 0; j < candidates.length; j += 1) {
            var node = candidates[j];
            var code = String(node.getAttribute('data-backend-menu-signal') || '').trim();
            if (code === '' || seen[code]) {
                continue;
            }
            var entry = normalizeCodeEntry(hasOwnCount(byCode, code) ? byCode[code] : null);
            if (entry.count <= 0) {
                continue;
            }
            seen[code] = true;
            leaves.push({
                code: code,
                title: leafTitle(node) || code,
                count: entry.count,
                href: entry.primary_href || leafHref(node),
                items: entry.items,
                node: node,
            });
        }
        return leaves;
    }

    /**
     * Panel rows: prefer Provider message/session items; else leaf menu entries.
     */
    function collectPanelEntries(signalNode, byCode) {
        var leaves = collectContributingLeaves(signalNode, byCode);
        var rows = [];
        for (var i = 0; i < leaves.length; i += 1) {
            var leaf = leaves[i];
            var items = leaf.items || [];
            if (items.length > 0) {
                for (var j = 0; j < items.length; j += 1) {
                    var item = items[j];
                    rows.push({
                        code: leaf.code,
                        id: item.id,
                        title: item.title || leaf.title,
                        count: item.count || 1,
                        href: item.href,
                        node: leaf.node,
                    });
                }
            } else {
                rows.push({
                    code: leaf.code,
                    id: leaf.code,
                    title: leaf.title,
                    count: leaf.count,
                    href: leaf.href,
                    node: leaf.node,
                });
            }
        }
        return rows;
    }

    function signalMenuRoot() {
        return global.document.getElementById('w-backend-menu-signal-menu');
    }

    function signalMenuPanel() {
        return global.document.getElementById('w-backend-menu-signal-panel');
    }

    function i18nFromMenu(attr, fallback) {
        var root = signalMenuRoot();
        if (!root) {
            return fallback;
        }
        var value = String(root.getAttribute(attr) || '').trim();
        return value !== '' ? value : fallback;
    }

    function rememberPanelEntries(badge, entries) {
        if (lastLeavesByBadge) {
            lastLeavesByBadge.set(badge, entries);
        }
    }

    function recalledPanelEntries(badge) {
        if (lastLeavesByBadge && lastLeavesByBadge.has(badge)) {
            return lastLeavesByBadge.get(badge) || [];
        }
        return [];
    }

    function applyBadge(badge, count, entries) {
        var label = formatCount(count);
        badge.textContent = label;
        badge.hidden = label === '';
        badge.setAttribute('aria-hidden', label === '' ? 'true' : 'false');
        badge.classList.toggle('is-clickable', label !== '');
        if (label === '') {
            badge.removeAttribute('role');
            badge.removeAttribute('tabindex');
            badge.removeAttribute('title');
            badge.removeAttribute('aria-label');
            rememberPanelEntries(badge, []);
            return;
        }
        var leafNames = (entries || []).map(function (leaf) {
            return leaf.title;
        }).filter(Boolean);
        var namesText = leafNames.length > 0 ? leafNames.join('、') : '';
        var badgeTpl = i18nFromMenu('data-i18n-signal-badge', '查看对应菜单：%s');
        var aria = namesText !== ''
            ? badgeTpl.replace('%s', namesText)
            : i18nFromMenu('data-i18n-signal-panel', '菜单待办');
        badge.setAttribute('role', 'button');
        badge.setAttribute('tabindex', '0');
        badge.setAttribute('title', aria);
        badge.setAttribute('aria-label', aria);
        badge.setAttribute('aria-haspopup', 'menu');
        rememberPanelEntries(badge, entries || []);
    }

    function expandSidebarOverlay() {
        var shell = global.document.querySelector('.w-backend-shell');
        var ui = global.Weline && global.Weline.UI;
        if (!shell || !ui || typeof ui.ensureMounted !== 'function') {
            return;
        }
        try {
            var api = ui.ensureMounted(shell, 'backend-sidebar-collapse');
            if (api && typeof api.expandOverlay === 'function') {
                api.expandOverlay();
            }
        } catch (_error) {
            // Sidebar collapse may be absent on some shells.
        }
    }

    function expandAncestors(node) {
        if (!node || typeof node.closest !== 'function') {
            return;
        }
        var current = node.parentElement;
        while (current) {
            if (current instanceof global.HTMLDetailsElement
                || (current.tagName && String(current.tagName).toLowerCase() === 'details')) {
                current.open = true;
            }
            current = current.parentElement;
        }
        var entry = node.closest('.w-backend-nav__entry');
        if (entry) {
            var disclosure = entry.querySelector(':scope > details.w-backend-nav__disclosure');
            if (disclosure) {
                disclosure.open = true;
            }
        }
    }

    function normalizePath(url) {
        try {
            var parsed = new global.URL(url, global.location.href);
            var path = String(parsed.pathname || '').replace(/\/+$/, '');
            return path === '' ? '/' : path;
        } catch (_error) {
            return String(url || '').split('?')[0].replace(/\/+$/, '') || '/';
        }
    }

    function navigateToLeaf(leaf) {
        if (!leaf) {
            return;
        }
        expandSidebarOverlay();
        expandAncestors(leaf.node);
        var href = String(leaf.href || '').trim();
        if (href === '') {
            if (leaf.node && typeof leaf.node.scrollIntoView === 'function') {
                leaf.node.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            return;
        }
        var currentPath = normalizePath(global.location.href);
        var targetPath = normalizePath(href);
        // Same path but different query (deep link) must still navigate.
        var currentFull = '';
        var targetFull = '';
        try {
            currentFull = new global.URL(global.location.href).pathname
                + new global.URL(global.location.href).search
                + new global.URL(global.location.href).hash;
            var parsed = new global.URL(href, global.location.href);
            targetFull = parsed.pathname + parsed.search + parsed.hash;
        } catch (_error) {
            currentFull = currentPath;
            targetFull = targetPath;
        }
        if (currentFull === targetFull || (currentPath === targetPath && targetFull.indexOf('?') === -1 && targetFull.indexOf('#') === -1)) {
            if (leaf.node && typeof leaf.node.scrollIntoView === 'function') {
                leaf.node.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            return;
        }
        global.location.assign(href);
    }

    function fillSignalPanel(entries) {
        var panel = signalMenuPanel();
        if (!panel) {
            return null;
        }
        while (panel.firstChild) {
            panel.removeChild(panel.firstChild);
        }
        var header = global.document.createElement('div');
        header.className = 'w-menu__header';
        header.textContent = i18nFromMenu('data-i18n-signal-panel', '菜单待办');
        panel.appendChild(header);

        for (var i = 0; i < entries.length; i += 1) {
            var leaf = entries[i];
            var item = global.document.createElement(leaf.href ? 'a' : 'button');
            item.className = 'w-menu__item w-backend-nav__signal-panel-item';
            item.setAttribute('role', 'menuitem');
            item.setAttribute('data-backend-menu-signal-target', leaf.code || '');
            if (leaf.id) {
                item.setAttribute('data-backend-menu-signal-item', String(leaf.id));
            }
            item.setAttribute('data-backend-menu-signal-count', String(leaf.count));
            if (leaf.href) {
                item.setAttribute('href', leaf.href);
            } else {
                item.setAttribute('type', 'button');
            }
            var label = global.document.createElement('span');
            label.className = 'w-backend-nav__signal-panel-item-title';
            label.textContent = leaf.title;
            item.appendChild(label);
            var countBadge = global.document.createElement('span');
            countBadge.className = 'w-backend-nav__signal-badge w-backend-nav__signal-badge--panel';
            countBadge.textContent = formatCount(leaf.count);
            item.appendChild(countBadge);
            item.addEventListener('click', (function (targetLeaf) {
                return function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    navigateToLeaf(targetLeaf);
                };
            })(leaf));
            panel.appendChild(item);
        }
        return panel;
    }

    function anchorSignalTriggerToBadge(badge) {
        var root = signalMenuRoot();
        var trigger = global.document.getElementById('w-backend-menu-signal-trigger');
        if (!root || !trigger || !badge || typeof badge.getBoundingClientRect !== 'function') {
            return null;
        }
        var rect = badge.getBoundingClientRect();
        // Visually-hidden trigger has zero box; pin a 1px+ fixed hit box on the badge
        // so Weline UI floating place() sees an on-screen anchor.
        trigger.classList.remove('w-visually-hidden');
        trigger.setAttribute('data-backend-menu-signal-trigger-anchored', '');
        trigger.style.position = 'fixed';
        trigger.style.left = Math.max(0, rect.left) + 'px';
        trigger.style.top = Math.max(0, rect.top) + 'px';
        trigger.style.width = Math.max(1, rect.width || 1) + 'px';
        trigger.style.height = Math.max(1, rect.height || 1) + 'px';
        trigger.style.margin = '0';
        trigger.style.padding = '0';
        trigger.style.border = '0';
        trigger.style.opacity = '0';
        trigger.style.overflow = 'hidden';
        trigger.style.pointerEvents = 'none';
        trigger.style.zIndex = '0';
        root.style.pointerEvents = 'none';
        return trigger;
    }

    function openSignalPanel(badge, entries) {
        if (!entries || entries.length === 0) {
            return false;
        }
        var root = signalMenuRoot();
        var ui = global.Weline && global.Weline.UI;
        if (!root || !ui || typeof ui.ensureMounted !== 'function') {
            // Fallback: navigate to the single entry when UI menu is unavailable.
            if (entries.length === 1) {
                navigateToLeaf(entries[0]);
                return true;
            }
            return false;
        }
        fillSignalPanel(entries);
        var trigger = anchorSignalTriggerToBadge(badge);
        var menuApi = ui.ensureMounted(root, 'menu');
        if (!menuApi || typeof menuApi.open !== 'function') {
            if (entries.length === 1) {
                navigateToLeaf(entries[0]);
                return true;
            }
            return false;
        }
        var reference = null;
        if (ui.floating && typeof ui.floating.capture === 'function') {
            reference = ui.floating.capture(trigger || badge);
        }
        return !!menuApi.open(true, reference);
    }

    function signalNodeForBadge(badge) {
        if (!badge || typeof badge.closest !== 'function') {
            return null;
        }
        var onSelf = badge.closest('[data-backend-menu-signal]');
        if (onSelf) {
            return onSelf;
        }
        var entry = badge.closest('.w-backend-nav__entry, .w-backend-nav__group');
        if (entry && entry.hasAttribute('data-backend-menu-signal')) {
            return entry;
        }
        return entry ? entry.querySelector('[data-backend-menu-signal]') : null;
    }

    function handleBadgeActivate(badge, event) {
        if (!badge || badge.hidden) {
            return;
        }
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        var entries = recalledPanelEntries(badge);
        if (!entries.length) {
            var signalNode = signalNodeForBadge(badge);
            if (signalNode) {
                entries = collectPanelEntries(signalNode, lastByCode);
            }
        }
        openSignalPanel(badge, entries);
    }

    function bindBadgeInteractions() {
        if (clickBound || !global.document || !global.document.addEventListener) {
            return;
        }
        clickBound = true;
        global.document.addEventListener('click', function (event) {
            var badge = event.target && event.target.closest
                ? event.target.closest('[data-backend-menu-signal-badge], .w-backend-nav__signal-badge')
                : null;
            if (!badge || badge.hidden || !badge.classList.contains('is-clickable')) {
                return;
            }
            handleBadgeActivate(badge, event);
        }, true);
        global.document.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            var badge = event.target && event.target.closest
                ? event.target.closest('[data-backend-menu-signal-badge], .w-backend-nav__signal-badge')
                : null;
            if (!badge || badge.hidden || !badge.classList.contains('is-clickable')) {
                return;
            }
            handleBadgeActivate(badge, event);
        }, true);
    }

    function hrefKey(href) {
        try {
            var parsed = new global.URL(String(href || ''), global.location.href);
            var path = normalizePath(parsed.pathname);
            var params = [];
            parsed.searchParams.forEach(function (value, key) {
                if (String(value) === '') {
                    return;
                }
                params.push(String(key) + '=' + String(value));
            });
            params.sort();
            return path + (params.length > 0 ? '?' + params.join('&') : '');
        } catch (_error) {
            return String(href || '').trim();
        }
    }

    function hrefMatchesLocation(href) {
        try {
            var current = new global.URL(global.location.href);
            var target = new global.URL(String(href || ''), global.location.href);
            if (normalizePath(current.pathname) !== normalizePath(target.pathname)) {
                return false;
            }
            var required = 0;
            var matched = true;
            target.searchParams.forEach(function (value, key) {
                if (String(value) === '') {
                    return;
                }
                required += 1;
                if (current.searchParams.get(key) !== value) {
                    matched = false;
                }
            });
            return required > 0 && matched;
        } catch (_error) {
            return false;
        }
    }

    function forEachSignalHref(byCode, visit) {
        var codes = byCode && typeof byCode === 'object' ? Object.keys(byCode) : [];
        for (var i = 0; i < codes.length; i += 1) {
            var entry = normalizeCodeEntry(byCode[codes[i]]);
            if (entry.primary_href) {
                visit(entry.primary_href);
            }
            var items = entry.items || [];
            for (var j = 0; j < items.length; j += 1) {
                if (items[j] && items[j].href) {
                    visit(items[j].href);
                }
            }
        }
    }

    function isViewedHref(href) {
        var key = hrefKey(href);
        if (key === '' || reopenedHrefKeys[key]) {
            return false;
        }
        return viewedHrefKeys[key] === true;
    }

    function omitViewedItems(byCode) {
        var out = {};
        var codes = byCode && typeof byCode === 'object' ? Object.keys(byCode) : [];
        for (var i = 0; i < codes.length; i += 1) {
            var code = codes[i];
            var entry = normalizeCodeEntry(byCode[code]);
            if (entry.items.length === 0) {
                if (entry.count > 0 && entry.primary_href && isViewedHref(entry.primary_href)) {
                    continue;
                }
                if (entry.count > 0) {
                    out[code] = byCode[code];
                }
                continue;
            }
            var items = [];
            for (var j = 0; j < entry.items.length; j += 1) {
                if (isViewedHref(entry.items[j].href)) {
                    continue;
                }
                items.push(entry.items[j]);
            }
            if (items.length === 0) {
                continue;
            }
            var count = 0;
            for (var k = 0; k < items.length; k += 1) {
                count += items[k].count || 1;
            }
            out[code] = {
                count: count,
                items: items,
                primary_href: String(items[0].href || ''),
            };
        }
        return out;
    }

    function rememberViewedFromRaw() {
        forEachSignalHref(lastRawByCode, function (href) {
            if (!hrefMatchesLocation(href)) {
                return;
            }
            var key = hrefKey(href);
            if (key === '') {
                return;
            }
            viewedHrefKeys[key] = true;
            delete reopenedHrefKeys[key];
        });
    }

    function noteViewed() {
        rememberViewedFromRaw();
        return paintBackendMenuSignals({ total: 0, by_code: lastRawByCode });
    }

    function releaseViewed() {
        function reopen(href) {
            if (!hrefMatchesLocation(href)) {
                return;
            }
            var key = hrefKey(href);
            if (key === '') {
                return;
            }
            delete viewedHrefKeys[key];
            reopenedHrefKeys[key] = true;
        }
        forEachSignalHref(lastRawByCode, reopen);
        Object.keys(viewedHrefKeys).forEach(reopen);
        return paintBackendMenuSignals({ total: 0, by_code: lastRawByCode });
    }

    function paintBackendMenuSignals(signals) {
        var payload = unwrapPayload(signals);
        lastRawByCode = payload.by_code;
        var byCode = omitViewedItems(payload.by_code);
        lastByCode = byCode;
        var nodes = global.document.querySelectorAll('[data-backend-menu-signal]');
        for (var i = 0; i < nodes.length; i += 1) {
            var node = nodes[i];
            var count = resolveCountForNode(node, byCode);
            var badge = ensureBadge(node);
            var entries = count > 0 ? collectPanelEntries(node, byCode) : [];
            applyBadge(badge, count, entries);
            applyLeafHrefOverride(node, byCode);
        }
        return payload;
    }

    function clearBackendMenuSignals() {
        return paintBackendMenuSignals({ total: 0, by_code: {} });
    }

    function refreshBackendMenuSignals() {
        var seq = ++refreshSeq;
        return getAdminApi()
            .then(function (api) {
                if (!api || typeof api.menuSignals !== 'function') {
                    throw new Error('[BackendMenuSignals] admin.menuSignals unavailable.');
                }
                return api.menuSignals({}, { silent: true, keepBusinessResult: true });
            })
            .then(function (result) {
                if (seq !== refreshSeq) {
                    return unwrapPayload(result);
                }
                var painted = paintBackendMenuSignals(unwrapPayload(result));
                if (!notedInitialView) {
                    notedInitialView = true;
                    return noteViewed();
                }
                return painted;
            })
            .catch(function (error) {
                if (global.console && typeof global.console.warn === 'function') {
                    global.console.warn('[BackendMenuSignals] refresh failed:', error);
                }
                clearBackendMenuSignals();
                return { total: 0, by_code: {} };
            });
    }

    function stopPolling() {
        if (pollTimer) {
            global.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPolling() {
        stopPolling();
        pollTimer = global.setInterval(function () {
            if (global.document.hidden) {
                return;
            }
            refreshBackendMenuSignals().catch(function () {});
        }, POLL_MS);
    }

    function bindVisibility() {
        if (visibilityBound || !global.document || !global.document.addEventListener) {
            return;
        }
        visibilityBound = true;
        global.document.addEventListener('visibilitychange', function () {
            if (global.document.hidden) {
                return;
            }
            refreshBackendMenuSignals().catch(function () {});
        });
    }

    function bindHistory() {
        if (historyBound || !global.addEventListener) {
            return;
        }
        historyBound = true;
        global.addEventListener('popstate', function () {
            noteViewed();
        });
    }

    function boot() {
        if (!global.document.getElementById('w-backend-sidebar')
            && !global.document.querySelector('[data-backend-menu-signal]')) {
            return;
        }
        bindVisibility();
        bindHistory();
        bindBadgeInteractions();
        refreshBackendMenuSignals().catch(function () {});
        startPolling();
    }

    var api = {
        refresh: refreshBackendMenuSignals,
        noteViewed: noteViewed,
        releaseViewed: releaseViewed,
        paint: paintBackendMenuSignals,
        clear: clearBackendMenuSignals,
        startPolling: startPolling,
        stopPolling: stopPolling,
        collectContributingLeaves: collectContributingLeaves,
        collectPanelEntries: collectPanelEntries,
        normalizeCodeEntry: normalizeCodeEntry,
        applyLeafHrefOverride: applyLeafHrefOverride,
        openSignalPanel: openSignalPanel,
        navigateToLeaf: navigateToLeaf,
    };

    global.refreshBackendMenuSignals = refreshBackendMenuSignals;
    global.paintBackendMenuSignals = paintBackendMenuSignals;
    global.clearBackendMenuSignals = clearBackendMenuSignals;

    global.Weline = global.Weline || {};
    global.Weline.BackendMenuSignals = api;

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(typeof window !== 'undefined' ? window : this));
