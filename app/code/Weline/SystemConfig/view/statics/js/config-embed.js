/**
 * Immediate save for <w:config:embed> controls via system_config.setScopedConfig.
 */
(function () {
    'use strict';

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

    function readControlValue(control) {
        if (!control) {
            return '';
        }
        if (control.type === 'checkbox') {
            return control.checked ? '1' : '0';
        }
        return String(control.value == null ? '' : control.value);
    }

    function setControlValue(control, value) {
        if (!control) {
            return;
        }
        if (control.type === 'checkbox') {
            control.checked = ['1', 'true', 'on', 'yes'].indexOf(String(value).toLowerCase()) !== -1;
            return;
        }
        control.value = value == null ? '' : String(value);
    }

    function saveField(root, fieldEl, control) {
        if (!root || !fieldEl || !control || root.dataset.canUpdate !== '1') {
            return;
        }
        if (fieldEl.dataset.status && fieldEl.dataset.status !== 'ok') {
            return;
        }
        if (control.disabled || control.dataset.saving === '1') {
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
        control.disabled = true;

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

        Promise.resolve(window.Weline && window.Weline.load ? window.Weline.load('api') : window.Weline && window.Weline.Api)
            .then(function (api) {
                if (!api || !api.resource) {
                    throw new Error('weline_api_unavailable');
                }
                return api.resource('system_config').setScopedConfig(payload);
            })
            .then(function (data) {
                if (data === false || (data && typeof data === 'object' && data.result === false)) {
                    throw new Error('set_scoped_config_failed');
                }
                control.dataset.lastValue = next;
                toast('success', (window.__ && window.__('配置已保存')) || '配置已保存');
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
                control.disabled = false;
            });
    }

    function bindRoot(root) {
        if (!root || root.dataset.embedBound === '1') {
            return;
        }
        root.dataset.embedBound = '1';

        root.querySelectorAll('[data-w-config-embed-control]').forEach(function (control) {
            control.dataset.lastValue = readControlValue(control);
            var fieldEl = control.closest('[data-testid="config-embed-field"]');
            var isSwitch = control.type === 'checkbox'
                || control.tagName === 'SELECT'
                || !!(control.closest && control.closest('[data-w-search-select]'));

            if (isSwitch) {
                control.addEventListener('change', function () {
                    saveField(root, fieldEl, control);
                });
                return;
            }

            var timer = null;
            control.addEventListener('input', function () {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(function () {
                    saveField(root, fieldEl, control);
                }, 300);
            });
            control.addEventListener('blur', function () {
                if (timer) {
                    clearTimeout(timer);
                    timer = null;
                }
                saveField(root, fieldEl, control);
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
