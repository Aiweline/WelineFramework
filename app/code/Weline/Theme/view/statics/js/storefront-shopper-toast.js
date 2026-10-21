(function (global) {
    'use strict';

    var doc = global.document;
    if (!doc || doc.documentElement.getAttribute('data-w-area') !== 'frontend') {
        return;
    }

    var REGION_CLASS = 'w-shopper-notice-region';
    var LEGACY_REGION_CLASS = 'w-amz-shopper-notice-region';
    var LEGACY_CART_REGION = 'w-amz-cart-added-region';
    var CART_REGION_CLASS = 'w-shopper-cart-added-region';
    var DEFAULT_DISMISS_MS = 5200;
    var CSS_STAMP = '20261015-shopper-toast-arch-v1';
    var CSS_ATTR = 'data-weline-shopper-toast-live';
    var W18N_RE = /\[\[W18N:([A-Za-z0-9_-]+):([A-Za-z0-9_-]+)\]\]/g;
    var toastCssReady = false;

    function b64urlDecode(encoded) {
        try {
            var s = String(encoded || '').replace(/-/g, '+').replace(/_/g, '/');
            while (s.length % 4) {
                s += '=';
            }
            return decodeURIComponent(escape(atob(s)));
        } catch (e) {
            return '';
        }
    }

    /** Peel nested/polluted [[W18N:…]] before textContent (inline-edit markers). */
    function unwrapW18nDisplay(raw) {
        var s = String(raw == null ? '' : raw);
        var guard = 0;
        while (guard++ < 8 && s.indexOf('[[W18N:') !== -1) {
            W18N_RE.lastIndex = 0;
            var next = s.replace(W18N_RE, function (_, srcB64, trB64) {
                return b64urlDecode(trB64) || b64urlDecode(srcB64) || '';
            });
            if (next === s) {
                break;
            }
            s = next;
        }
        return s;
    }

    function resolvePublicStaticUrl(devPath) {
        try {
            var cfgNode = doc.getElementById('weline-frontend-runtime-config');
            if (cfgNode && cfgNode.textContent) {
                var cfg = JSON.parse(cfgNode.textContent);
                if (cfg && cfg.env && cfg.env.useProductionPublicUrls) {
                    var m = String(devPath).match(/^\/([^/]+)\/([^/]+)\/view\/statics\/(.*)$/);
                    if (m) {
                        return (cfg.staticBaseUrl || '/static') + '/' + m[1] + '/' + m[2] + '/' + m[3];
                    }
                }
            }
        } catch (err) { /* keep DEV path */ }
        return String(devPath);
    }

    function ensureStylesheet() {
        if (toastCssReady) {
            var existingLive = doc.querySelector('link[' + CSS_ATTR + '="1"]');
            if (existingLive) {
                return;
            }
        }
        var assetVersion = '';
        try {
            var cfgNode = doc.getElementById('weline-frontend-runtime-config');
            if (cfgNode && cfgNode.textContent) {
                var cfg = JSON.parse(cfgNode.textContent);
                assetVersion = String((cfg && cfg.assetVersion) || '');
            }
        } catch (err) {
            assetVersion = '';
        }
        var href = resolvePublicStaticUrl(
            '/Weline/Theme/view/statics/css/widgets/storefront-shopper-toast.css',
        ) + '?v=' + CSS_STAMP;
        if (assetVersion) {
            href += '&_weline_dev=' + encodeURIComponent(assetVersion);
        }
        var existing = doc.querySelector(
            'link[' + CSS_ATTR + '="1"], link[rel="stylesheet"][href*="storefront-shopper-toast"]',
        );
        if (existing) {
            if (String(existing.getAttribute('href') || '').indexOf(CSS_STAMP) === -1) {
                existing.setAttribute('href', href);
                existing.setAttribute(CSS_ATTR, '1');
            }
        } else {
            var link = doc.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            link.setAttribute(CSS_ATTR, '1');
            doc.head.appendChild(link);
        }
        toastCssReady = true;
    }

    function readCloseLabel() {
        var lang = String(
            doc.documentElement.lang
            || doc.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        return lang.startsWith('zh') ? '关闭' : 'Close';
    }

    function readCompareNoticeLabels() {
        var bar = doc.querySelector('[data-compare-bar]');
        var lang = String(
            doc.documentElement.lang
            || doc.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        var isZh = lang.startsWith('zh');
        var readAttr = function (name, fallback) {
            if (!bar) {
                return fallback;
            }
            var value = unwrapW18nDisplay(String(bar.getAttribute(name) || '').trim());
            return value || fallback;
        };
        var compareLink = bar ? bar.querySelector('a[href*="/compare"]') : null;
        return {
            compare: readAttr('data-i18n-compare-label', isZh ? '对比' : 'Compare'),
            startCompare: compareLink
                ? unwrapW18nDisplay(compareLink.textContent).trim()
                : (isZh ? '开始对比' : 'Compare now'),
            continueShopping: isZh ? '继续购物' : 'Continue shopping',
            compareUrl: compareLink ? (compareLink.getAttribute('href') || '/compare') : '/compare',
            close: readCloseLabel(),
        };
    }

    function formatCompareCountLabel(count, compareLabel) {
        var itemCount = Math.max(0, Number(count) || 0);
        var lang = String(
            doc.documentElement.lang
            || doc.documentElement.getAttribute('data-lang')
            || '',
        ).toLowerCase();
        if (lang.startsWith('zh')) {
            return compareLabel + '（' + itemCount + ' 件商品）';
        }
        var itemWord = itemCount === 1 ? 'item' : 'items';
        return compareLabel + ' (' + itemCount + ' ' + itemWord + ')';
    }

    function normalizeTone(tone) {
        if (tone === 'error') {
            return 'danger';
        }
        if (['success', 'info', 'warning', 'danger'].indexOf(tone) >= 0) {
            return tone;
        }
        if (tone === 'neutral') {
            return 'info';
        }
        return null;
    }

    function ensureRegion() {
        ensureStylesheet();
        var host = doc.querySelector('.' + REGION_CLASS)
            || doc.querySelector('.' + LEGACY_REGION_CLASS)
            || doc.querySelector('.' + LEGACY_CART_REGION)
            || doc.querySelector('.' + CART_REGION_CLASS);
        if (host) {
            host.classList.add(REGION_CLASS, CART_REGION_CLASS);
            return host;
        }
        host = doc.createElement('div');
        host.className = REGION_CLASS + ' ' + CART_REGION_CLASS;
        host.setAttribute('aria-live', 'polite');
        host.setAttribute('aria-atomic', 'false');
        doc.body.appendChild(host);
        return host;
    }

    function positionRegion(host) {
        if (!(host instanceof HTMLElement)) {
            return;
        }
        host.style.position = 'fixed';
        host.style.insetBlockStart = '';
        host.style.insetInlineEnd = '';
        host.style.insetInlineStart = '';
        var noticeWidth = Math.min(22 * 16, Math.max(240, global.innerWidth - 32));
        host.style.width = noticeWidth + 'px';

        var trigger = doc.querySelector('[data-mini-cart-trigger]');
        if (!(trigger instanceof HTMLElement)) {
            host.style.top = 'max(1rem, env(safe-area-inset-top))';
            host.style.right = 'max(1rem, env(safe-area-inset-right))';
            host.style.left = 'auto';
            return;
        }

        var rect = trigger.getBoundingClientRect();
        var gap = 8;
        var top = Math.max(8, rect.bottom + gap);
        var right = Math.max(16, global.innerWidth - rect.right);
        if (right + noticeWidth > global.innerWidth - 16) {
            right = Math.max(16, global.innerWidth - 16 - noticeWidth);
        }
        host.style.top = top + 'px';
        host.style.right = right + 'px';
        host.style.left = 'auto';
    }

    function dismissPanel(panel) {
        if (!panel || !panel.isConnected) {
            return;
        }
        panel.classList.remove('is-visible');
        global.setTimeout(function () {
            if (panel.isConnected) {
                panel.remove();
            }
        }, 220);
    }

    function mountActionPanel(panelClassName, buildBody) {
        ensureStylesheet();
        var host = ensureRegion();
        positionRegion(host);
        host.querySelectorAll('.' + panelClassName.split(/\s+/)[0]).forEach(function (existing) {
            dismissPanel(existing);
        });

        var panel = doc.createElement('aside');
        panel.className = panelClassName;
        panel.setAttribute('role', 'status');

        var header = doc.createElement('div');
        header.className = 'w-shopper-cart-added__header';

        var titleWrap = doc.createElement('div');
        titleWrap.className = 'w-shopper-cart-added__title-wrap';

        var check = doc.createElement('span');
        check.className = 'w-shopper-cart-added__check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = '✓';

        var title = doc.createElement('strong');
        title.className = 'w-shopper-cart-added__title';

        titleWrap.append(check, title);

        var close = doc.createElement('button');
        close.type = 'button';
        close.className = 'w-shopper-cart-added__close';
        close.setAttribute('aria-label', readCloseLabel());
        close.textContent = '×';

        header.append(titleWrap, close);
        panel.append(header);

        var dismiss = function () {
            dismissPanel(panel);
        };
        close.addEventListener('click', dismiss);

        buildBody(panel, title, dismiss);

        host.append(panel);
        global.requestAnimationFrame(function () {
            positionRegion(host);
            panel.classList.add('is-visible');
        });

        return { panel: panel, dismiss: dismiss };
    }

    function buildCompactNotice(message, options) {
        options = options || {};
        var tone = normalizeTone(options.tone) || 'success';
        var text = unwrapW18nDisplay(String(message || '')).trim();
        if (text === '') {
            return null;
        }

        ensureStylesheet();
        var host = ensureRegion();
        positionRegion(host);
        host.querySelectorAll('.w-shopper-toast, .w-amz-shopper-toast').forEach(function (existing) {
            dismissPanel(existing);
        });

        var panel = doc.createElement('aside');
        panel.className = 'w-shopper-toast w-shopper-toast--' + tone;
        panel.setAttribute('role', tone === 'danger' ? 'alert' : 'status');

        var header = doc.createElement('div');
        header.className = 'w-shopper-toast__header';

        var titleWrap = doc.createElement('div');
        titleWrap.className = 'w-shopper-toast__title-wrap';

        var check = doc.createElement('span');
        check.className = 'w-shopper-toast__check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = tone === 'danger' ? '!' : '✓';

        var title = doc.createElement('strong');
        title.className = 'w-shopper-toast__title';
        title.textContent = text;

        titleWrap.append(check, title);

        var close = doc.createElement('button');
        close.type = 'button';
        close.className = 'w-shopper-toast__close';
        close.setAttribute('aria-label', readCloseLabel());
        close.textContent = '×';

        header.append(titleWrap, close);
        panel.append(header);

        var dismiss = function () {
            dismissPanel(panel);
        };
        close.addEventListener('click', dismiss);

        host.append(panel);
        global.requestAnimationFrame(function () {
            positionRegion(host);
            panel.classList.add('is-visible');
        });

        var duration = Number.isFinite(options.duration) ? Math.max(0, options.duration) : DEFAULT_DISMISS_MS;
        if (duration > 0) {
            global.setTimeout(dismiss, duration);
        }

        return panel;
    }

    function buildCompareAddedNotice(message, options) {
        options = options || {};
        var text = unwrapW18nDisplay(String(message || '')).trim();
        if (text === '') {
            return null;
        }

        var labels = readCompareNoticeLabels();
        var count = Math.max(0, Number(options.count) || 0);
        var max = Math.max(1, Number(options.max) || 8);
        var mounted = mountActionPanel('w-shopper-cart-added w-shopper-compare-added', function (panel, title, dismiss) {
            title.textContent = text;

            var subtotalRow = doc.createElement('div');
            subtotalRow.className = 'w-shopper-cart-added__subtotal';

            var subtotalLabel = doc.createElement('span');
            subtotalLabel.className = 'w-shopper-cart-added__subtotal-label';
            subtotalLabel.textContent = formatCompareCountLabel(count, labels.compare);

            var subtotalAmount = doc.createElement('span');
            subtotalAmount.className = 'w-shopper-cart-added__subtotal-amount';
            subtotalAmount.textContent = count + '/' + max;

            subtotalRow.append(subtotalLabel, subtotalAmount);

            var actions = doc.createElement('div');
            actions.className = 'w-shopper-cart-added__actions';

            var startCompare = doc.createElement('a');
            startCompare.className = 'w-shopper-cart-added__btn w-shopper-cart-added__btn--checkout';
            startCompare.href = labels.compareUrl;
            startCompare.textContent = labels.startCompare;

            var continueShopping = doc.createElement('button');
            continueShopping.type = 'button';
            continueShopping.className = 'w-shopper-cart-added__btn w-shopper-cart-added__btn--cart';
            continueShopping.textContent = labels.continueShopping;
            continueShopping.addEventListener('click', dismiss);

            actions.append(startCompare, continueShopping);
            panel.append(subtotalRow, actions);
        });

        var duration = Number.isFinite(options.duration) ? Math.max(0, options.duration) : DEFAULT_DISMISS_MS;
        if (duration > 0 && mounted && typeof mounted.dismiss === 'function') {
            global.setTimeout(mounted.dismiss, duration);
        }

        return mounted ? mounted.panel : null;
    }

    global.Weline = global.Weline || {};
    global.Weline.ShopperNotice = {
        ensureStylesheet: ensureStylesheet,
        ensureRegion: ensureRegion,
        positionRegion: positionRegion,
        unwrapW18nDisplay: unwrapW18nDisplay,
        showCompact: buildCompactNotice,
        showCompareAdded: buildCompareAddedNotice,
        dismiss: dismissPanel,
    };

    function patchToast() {
        var ui = global.Weline && global.Weline.UI;
        if (!ui || !ui.toast || ui.toast.__shopperToastPatched || ui.toast.__shopperAmazonPatched) {
            return;
        }
        if (typeof ui.toast.show !== 'function') {
            return;
        }

        var originals = {
            show: ui.toast.show.bind(ui.toast),
            success: typeof ui.toast.success === 'function' ? ui.toast.success.bind(ui.toast) : null,
            warning: typeof ui.toast.warning === 'function' ? ui.toast.warning.bind(ui.toast) : null,
            error: typeof ui.toast.error === 'function' ? ui.toast.error.bind(ui.toast) : null,
            info: typeof ui.toast.info === 'function' ? ui.toast.info.bind(ui.toast) : null,
        };

        function delegateShopper(message, options, fallbackFn, defaultTone) {
            options = options || {};
            if (options.amazon === false || options.shopper === false || options.title) {
                return fallbackFn(message, options);
            }
            var tone = normalizeTone(options.tone != null ? options.tone : defaultTone);
            if (tone) {
                return buildCompactNotice(message, Object.assign({}, options, { tone: tone }));
            }
            return fallbackFn(message, options);
        }

        ui.toast.show = function (message, options) {
            return delegateShopper(message, options, originals.show, 'neutral');
        };
        if (originals.success) {
            ui.toast.success = function (message, options) {
                return delegateShopper(message, options, originals.success, 'success');
            };
        }
        if (originals.warning) {
            ui.toast.warning = function (message, options) {
                return delegateShopper(message, options, originals.warning, 'warning');
            };
        }
        if (originals.error) {
            ui.toast.error = function (message, options) {
                return delegateShopper(message, options, originals.error, 'danger');
            };
        }
        if (originals.info) {
            ui.toast.info = function (message, options) {
                return delegateShopper(message, options, originals.info, 'info');
            };
        }

        ui.toast.__shopperToastPatched = true;
        ui.toast.__shopperAmazonPatched = true;
    }

    function boot() {
        ensureStylesheet();
        var attempts = 0;
        (function tryPatch() {
            patchToast();
            if (!global.Weline || !global.Weline.UI || !global.Weline.UI.toast
                || !(global.Weline.UI.toast.__shopperToastPatched || global.Weline.UI.toast.__shopperAmazonPatched)) {
                attempts += 1;
                if (attempts < 120) {
                    global.setTimeout(tryPatch, 50);
                }
            }
        }());
    }

    boot();
    doc.addEventListener('DOMContentLoaded', boot);
    global.addEventListener('load', boot);
    doc.addEventListener('weline:ui:ready', boot);

    global.addEventListener('resize', function () {
        var host = doc.querySelector('.' + REGION_CLASS + ', .' + LEGACY_REGION_CLASS);
        if (host && host.childElementCount > 0) {
            positionRegion(host);
        }
    });

    global.addEventListener('scroll', function () {
        var host = doc.querySelector('.' + REGION_CLASS + ', .' + LEGACY_REGION_CLASS);
        if (host && host.childElementCount > 0) {
            positionRegion(host);
        }
    }, { passive: true });
}(window));
