(function (global) {
    'use strict';

    var doc = global.document;
    if (!doc || doc.documentElement.getAttribute('data-w-area') !== 'frontend') {
        return;
    }

    var REGION_CLASS = 'w-amz-shopper-notice-region';
    var LEGACY_REGION_CLASS = 'w-amz-cart-added-region';
    var DEFAULT_DISMISS_MS = 5200;

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
            var value = String(bar.getAttribute(name) || '').trim();
            return value || fallback;
        };
        var compareLink = bar ? bar.querySelector('a[href*="/compare"]') : null;
        return {
            compare: readAttr('data-i18n-compare-label', isZh ? '对比' : 'Compare'),
            startCompare: compareLink ? compareLink.textContent.trim() : (isZh ? '开始对比' : 'Compare now'),
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
        var host = doc.querySelector('.' + REGION_CLASS)
            || doc.querySelector('.' + LEGACY_REGION_CLASS);
        if (host) {
            if (!host.classList.contains(REGION_CLASS)) {
                host.classList.add(REGION_CLASS);
            }
            return host;
        }
        host = doc.createElement('div');
        host.className = REGION_CLASS + ' ' + LEGACY_REGION_CLASS;
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
        var host = ensureRegion();
        positionRegion(host);
        host.querySelectorAll('.' + panelClassName.split(/\s+/)[0]).forEach(function (existing) {
            dismissPanel(existing);
        });

        var panel = doc.createElement('aside');
        panel.className = panelClassName;
        panel.setAttribute('role', 'status');

        var header = doc.createElement('div');
        header.className = 'w-amz-cart-added__header';

        var titleWrap = doc.createElement('div');
        titleWrap.className = 'w-amz-cart-added__title-wrap';

        var check = doc.createElement('span');
        check.className = 'w-amz-cart-added__check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = '✓';

        var title = doc.createElement('strong');
        title.className = 'w-amz-cart-added__title';

        titleWrap.append(check, title);

        var close = doc.createElement('button');
        close.type = 'button';
        close.className = 'w-amz-cart-added__close';
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
        var text = String(message || '').trim();
        if (text === '') {
            return null;
        }

        var host = ensureRegion();
        positionRegion(host);
        host.querySelectorAll('.w-amz-shopper-toast').forEach(function (existing) {
            dismissPanel(existing);
        });

        var panel = doc.createElement('aside');
        panel.className = 'w-amz-shopper-toast w-amz-shopper-toast--' + tone;
        panel.setAttribute('role', tone === 'danger' ? 'alert' : 'status');

        var header = doc.createElement('div');
        header.className = 'w-amz-shopper-toast__header';

        var titleWrap = doc.createElement('div');
        titleWrap.className = 'w-amz-shopper-toast__title-wrap';

        var check = doc.createElement('span');
        check.className = 'w-amz-shopper-toast__check';
        check.setAttribute('aria-hidden', 'true');
        check.textContent = tone === 'danger' ? '!' : '✓';

        var title = doc.createElement('strong');
        title.className = 'w-amz-shopper-toast__title';
        title.textContent = text;

        titleWrap.append(check, title);

        var close = doc.createElement('button');
        close.type = 'button';
        close.className = 'w-amz-shopper-toast__close';
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
        var text = String(message || '').trim();
        if (text === '') {
            return null;
        }

        var labels = readCompareNoticeLabels();
        var count = Math.max(0, Number(options.count) || 0);
        var max = Math.max(1, Number(options.max) || 8);
        var mounted = mountActionPanel('w-amz-cart-added w-amz-compare-added', function (panel, title, dismiss) {
            title.textContent = text;

            var subtotalRow = doc.createElement('div');
            subtotalRow.className = 'w-amz-cart-added__subtotal';

            var subtotalLabel = doc.createElement('span');
            subtotalLabel.className = 'w-amz-cart-added__subtotal-label';
            subtotalLabel.textContent = formatCompareCountLabel(count, labels.compare);

            var subtotalAmount = doc.createElement('span');
            subtotalAmount.className = 'w-amz-cart-added__subtotal-amount';
            subtotalAmount.textContent = count + '/' + max;

            subtotalRow.append(subtotalLabel, subtotalAmount);

            var actions = doc.createElement('div');
            actions.className = 'w-amz-cart-added__actions';

            var startCompare = doc.createElement('a');
            startCompare.className = 'w-amz-cart-added__btn w-amz-cart-added__btn--checkout';
            startCompare.href = labels.compareUrl;
            startCompare.textContent = labels.startCompare;

            var continueShopping = doc.createElement('button');
            continueShopping.type = 'button';
            continueShopping.className = 'w-amz-cart-added__btn w-amz-cart-added__btn--cart';
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
        ensureRegion: ensureRegion,
        positionRegion: positionRegion,
        showCompact: buildCompactNotice,
        showCompareAdded: buildCompareAddedNotice,
        dismiss: dismissPanel,
    };

    function patchToast() {
        var ui = global.Weline && global.Weline.UI;
        if (!ui || !ui.toast || ui.toast.__shopperAmazonPatched) {
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

        function delegateAmazon(message, options, fallbackFn, defaultTone) {
            options = options || {};
            if (options.amazon === false || options.title) {
                return fallbackFn(message, options);
            }
            var tone = normalizeTone(options.tone != null ? options.tone : defaultTone);
            if (tone) {
                return buildCompactNotice(message, Object.assign({}, options, { tone: tone }));
            }
            return fallbackFn(message, options);
        }

        ui.toast.show = function (message, options) {
            return delegateAmazon(message, options, originals.show, 'neutral');
        };
        if (originals.success) {
            ui.toast.success = function (message, options) {
                return delegateAmazon(message, options, originals.success, 'success');
            };
        }
        if (originals.warning) {
            ui.toast.warning = function (message, options) {
                return delegateAmazon(message, options, originals.warning, 'warning');
            };
        }
        if (originals.error) {
            ui.toast.error = function (message, options) {
                return delegateAmazon(message, options, originals.error, 'danger');
            };
        }
        if (originals.info) {
            ui.toast.info = function (message, options) {
                return delegateAmazon(message, options, originals.info, 'info');
            };
        }

        ui.toast.__shopperAmazonPatched = true;
    }

    function boot() {
        var attempts = 0;
        (function tryPatch() {
            patchToast();
            if (!global.Weline || !global.Weline.UI || !global.Weline.UI.toast || !global.Weline.UI.toast.__shopperAmazonPatched) {
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
        var host = doc.querySelector('.' + REGION_CLASS);
        if (host && host.childElementCount > 0) {
            positionRegion(host);
        }
    });

    global.addEventListener('scroll', function () {
        var host = doc.querySelector('.' + REGION_CLASS);
        if (host && host.childElementCount > 0) {
            positionRegion(host);
        }
    }, { passive: true });
}(window));
