/**
 * Debounced save for <w:config:embed> controls via system_config.setScopedConfig.
 * Text: TEXT_DEBOUNCE_MS idle + blur flush (no mid-type disable).
 * Discrete (checkbox/select/search-select): change immediate.
 */
(function () {
    'use strict';

    /** Idle delay before text autosave (ms). Keep >= 1000 so typing is not per-keystroke. */
    var TEXT_DEBOUNCE_MS = 1200;

    function toast(type, message) {
        var ui = window.Weline && window.Weline.UI && window.Weline.UI.toast;
        if (ui && typeof ui[type] === 'function') {
            ui[type](message);
            return;
        }
        if (typeof console !== 'undefined' && console[type === 'error' ? 'error' : 'log']) {
            console[type === 'error' ? 'error' : 'log']('[config-embed]', message);
        }
    }

    function dualValuePair(control) {
        if (!control || typeof control.getAttribute !== 'function') {
            return null;
        }
        var onValue = control.getAttribute('data-on-value');
        var offValue = control.getAttribute('data-off-value');
        if (onValue == null || offValue == null || onValue === '' || offValue === '') {
            return null;
        }

        return { on: String(onValue), off: String(offValue) };
    }

    function readControlValue(control) {
        if (!control) {
            return '';
        }
        if (control.type === 'checkbox') {
            var pair = dualValuePair(control);
            if (pair) {
                return control.checked ? pair.on : pair.off;
            }
            return control.checked ? '1' : '0';
        }
        return String(control.value == null ? '' : control.value);
    }

    function setControlValue(control, value) {
        if (!control) {
            return;
        }
        if (control.type === 'checkbox') {
            var pair = dualValuePair(control);
            if (pair) {
                control.checked = String(value) === pair.on;
                return;
            }
            control.checked = ['1', 'true', 'on', 'yes'].indexOf(String(value).toLowerCase()) !== -1;
            return;
        }
        control.value = value == null ? '' : String(value);
    }

    function saveField(root, fieldEl, control, options) {
        if (!root || !fieldEl || !control || root.dataset.canUpdate !== '1') {
            return;
        }
        if (fieldEl.dataset.status && fieldEl.dataset.status !== 'ok') {
            return;
        }
        options = options || {};
        var lockControl = options.lockControl === true;
        // ACL / scope-denied controls stay disabled; never write those.
        if (control.disabled) {
            return;
        }
        if (control.dataset.saving === '1') {
            control.dataset.pendingSave = '1';
            return;
        }

        var previous = control.dataset.lastValue;
        if (previous === undefined) {
            previous = readControlValue(control);
            control.dataset.lastValue = previous;
        }
        var next = readControlValue(control);
        if (String(previous) === String(next)) {
            return;
        }

        control.dataset.saving = '1';
        if (lockControl) {
            control.disabled = true;
        }

        // Must match embed read locale (data-locale). Omitting locale lets
        // setScopedConfig fall into admin UI language and write a different row
        // than the one rendered after refresh.
        var payload = {
            key: fieldEl.dataset.configKey,
            value: next,
            module: root.dataset.module,
            area: root.dataset.area || 'backend',
            locale: root.dataset.locale || 'default',
            target_scope: root.dataset.storageScope,
            expected_grant_version: root.dataset.grantVersion,
            reason: 'config_embed_immediate_save'
        };
        if (fieldEl.dataset.valueType) {
            payload.value_type = fieldEl.dataset.valueType;
        }
        if (root.dataset.websiteCode) {
            payload.website_code = root.dataset.websiteCode;
        }
        if (root.dataset.storeCode) {
            payload.store_code = root.dataset.storeCode;
        }
        if (root.dataset.channelCode) {
            payload.channel_code = root.dataset.channelCode;
        }
        if (fieldEl.dataset.cacheNamespaces) {
            payload.cache_namespaces = String(fieldEl.dataset.cacheNamespaces)
                .split(/[\s,]+/)
                .filter(Boolean);
        }

        Promise.resolve(window.Weline && window.Weline.load ? window.Weline.load('api') : window.Weline && window.Weline.Api)
            .then(function (api) {
                if (!api || !api.resource) {
                    throw new Error('weline_api_unavailable');
                }
                return api.resource('system_config').setScopedConfig(payload);
            })
            .then(function (data) {
                // Query may return the save payload directly, or wrap it.
                if (data && typeof data === 'object' && data.data && typeof data.data === 'object'
                    && (data.data.message || data.data.cache_invalidation || data.data.success !== undefined)
                    && data.message === undefined) {
                    data = data.data;
                }
                if (data === false || (data && typeof data === 'object' && (data.result === false || data.success === false))) {
                    throw new Error((data && (data.message || data.msg)) || 'set_scoped_config_failed');
                }
                control.dataset.lastValue = next;
                root.dispatchEvent(new CustomEvent('weline:config-saved', {
                    bubbles: true,
                    detail: { module: payload.module, area: payload.area, key: payload.key, scope: payload.target_scope, result: data },
                }));
                var msg = '';
                if (data && typeof data === 'object') {
                    msg = String(data.message || (data.cache_invalidation && data.cache_invalidation.summary) || '').trim();
                }
                if (!msg) {
                    msg = (window.__ && window.__('配置已保存')) || '配置已保存';
                }
                if (data && typeof data === 'object' && data.status === 'noop') {
                    var toastUi = window.Weline && window.Weline.UI && window.Weline.UI.toast;
                    if (toastUi && typeof toastUi.warning === 'function') {
                        toast('warning', msg);
                    } else {
                        toast('success', msg);
                    }
                    return;
                }
                var toastApi = window.Weline && window.Weline.UI && window.Weline.UI.toast;
                var title = data && typeof data === 'object'
                    ? String(data.message_title || '').trim()
                    : '';
                var body = data && typeof data === 'object'
                    ? String(data.message_body || '').trim()
                    : '';
                if (toastApi && typeof toastApi.success === 'function' && (title || body)) {
                    toastApi.success(body || msg, {
                        title: title || ((window.__ && window.__('配置已保存')) || '配置已保存'),
                        duration: 6500
                    });
                    return;
                }
                toast('success', msg);
            })
            .catch(function (error) {
                setControlValue(control, previous);
                var message = (error && error.message) || ((window.__ && window.__('配置保存失败')) || '配置保存失败');
                toast('error', message);
                if (typeof console !== 'undefined' && console.error) {
                    console.error('config-embed save failed', error);
                }
            })
            .finally(function () {
                control.dataset.saving = '0';
                if (lockControl) {
                    control.disabled = false;
                }
                if (control.dataset.pendingSave === '1') {
                    control.dataset.pendingSave = '0';
                    saveField(root, fieldEl, control, options);
                }
            });
    }

    function bindRoot(root) {
        if (!root || root.dataset.embedBound === '1') {
            return;
        }
        root.dataset.embedBound = '1';

        var controlSelector = [
            '[data-w-config-embed-control]',
            '[data-testid="config-embed-field"] [data-w-language-field]',
            '[data-testid="config-embed-field"] [data-ai-model-value]'
        ].join(',');

        root.querySelectorAll(controlSelector).forEach(function (control) {
            control.dataset.lastValue = readControlValue(control);
            var fieldEl = control.closest('[data-testid="config-embed-field"]');
            var isImmediate = control.type === 'checkbox'
                || control.type === 'hidden'
                || control.hasAttribute('data-w-config-embed-media')
                || control.tagName === 'SELECT'
                || control.hasAttribute('data-w-language-field')
                || control.hasAttribute('data-ai-model-value')
                || !!(control.closest && (
                    control.closest('[data-w-search-select]')
                    || control.closest('[data-w-component="language-select"]')
                    || control.closest('[data-w-component="ai-model-select"]')
                ));

            if (isImmediate) {
                control.addEventListener('change', function () {
                    saveField(root, fieldEl, control, { lockControl: true });
                });
                return;
            }

            var timer = null;
            control.addEventListener('input', function () {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(function () {
                    timer = null;
                    saveField(root, fieldEl, control, { lockControl: false });
                }, TEXT_DEBOUNCE_MS);
            });
            control.addEventListener('blur', function () {
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
                saveField(root, fieldEl, control, { lockControl: false });
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-w-config-embed]').forEach(bindRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
