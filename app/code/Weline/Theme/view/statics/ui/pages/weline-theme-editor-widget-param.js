/* Weline UI source: js/widget-param-types.js */
/**
 * Widget 参数类型 UI - 自包含脚本（IIFE 闭包，唯一选择器 w-param-*）
 * 所有逻辑仅针对 .w-param-form 及其子节点，不污染表单外 DOM；getElementById 仅用于表单内字段 ID。
 */
(function () {
    'use strict';
    var doc = document;

    function ready(fn) {
        if (doc.readyState !== 'loading') fn();
        else doc.addEventListener('DOMContentLoaded', fn);
    }

    function q(root, sel) { return root.querySelector(sel); }
    function qa(root, sel) { return root.querySelectorAll(sel); }

    function getUi() {
        return window.Weline && window.Weline.UI ? window.Weline.UI : null;
    }

    var querySelectRegistered = false;
    var querySelectReadyListenerBound = false;
    var querySelectDefinitionMarker = typeof Symbol === 'function'
        ? Symbol.for('weline.ui.definition.widget-query-select')
        : '__welineUiWidgetQuerySelectDefined';

    function registerQuerySelectComponent() {
        if (querySelectRegistered) return true;
        var UI = getUi();
        if (!UI || typeof UI.define !== 'function') return false;
        if (UI[querySelectDefinitionMarker] === true) {
            querySelectRegistered = true;
            return true;
        }

        UI.define('widget-query-select', function (context) {
            var root = context.element;
            var input = q(root, '[data-query-search]');
            var select = q(root, '[data-query-value]');
            var status = q(root, '[data-query-status], .w-query-select__status');
            if (!(input instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) return {};

            var provider = root.dataset.provider || '';
            var operation = root.dataset.operation || '';
            var valueKey = root.dataset.valueKey || 'value';
            var labelKey = root.dataset.labelKey || 'label';
            var timer = 0;
            var sequence = 0;

            function setStatus(state, message) {
                root.dataset.state = state;
                if (!(status instanceof HTMLElement)) return;
                status.textContent = message || '';
                status.dataset.state = state;
                status.hidden = !message;
            }

            function unwrapItems(payload) {
                if (Array.isArray(payload)) return payload;
                if (!payload || typeof payload !== 'object') return [];
                if (Array.isArray(payload.items)) return payload.items;
                if (Array.isArray(payload.options)) return payload.options;
                if (Object.prototype.hasOwnProperty.call(payload, 'data')) return unwrapItems(payload.data);
                return Object.keys(payload).map(function (key) {
                    return { value: key, label: payload[key] };
                });
            }

            async function requestItems(search) {
                var Weline = window.Weline || {};
                if (!/^[a-z][a-z0-9_.-]{0,127}$/i.test(provider)
                    || !/^[a-z][a-z0-9_.-]{0,127}$/i.test(operation)
                    || ['constructor', 'prototype', '__proto__'].indexOf(operation.toLowerCase()) !== -1) {
                    throw new Error('Widget query provider or operation is invalid.');
                }
                var api = Weline.Api;
                if ((!api || typeof api.resource !== 'function') && typeof Weline.load === 'function') {
                    api = await Weline.load('api');
                }
                if (!api || typeof api.resource !== 'function') {
                    throw new Error('Weline.Api.resource is unavailable.');
                }
                var resource = await api.resource(provider);
                if (!resource || typeof resource[operation] !== 'function') {
                    throw new Error('Widget query operation is unavailable.');
                }
                return unwrapItems(await resource[operation]({search: search}));
            }

            function appendOption(value, label, selected) {
                var option = doc.createElement('option');
                option.value = String(value == null ? '' : value);
                option.textContent = String(label == null || label === '' ? option.value : label);
                option.selected = selected;
                select.appendChild(option);
            }

            async function load() {
                var requestId = ++sequence;
                var currentValue = String(select.value || root.dataset.current || '');
                var currentLabel = select.selectedOptions[0] ? select.selectedOptions[0].textContent : currentValue;
                root.setAttribute('aria-busy', 'true');
                setStatus('loading', root.dataset.loadingLabel || '');
                try {
                    var items = await requestItems(input.value.trim());
                    if (requestId !== sequence || !root.isConnected) return false;
                    var latestValue = String(root.dataset.current || '');
                    var latestOption = Array.prototype.find.call(select.options, function (option) {
                        return option.value === latestValue;
                    });
                    var latestLabel = latestOption ? latestOption.textContent : currentLabel;
                    select.replaceChildren();
                    var hasCurrent = false;
                    items.forEach(function (item) {
                        var objectItem = item && typeof item === 'object';
                        var itemValue = objectItem ? item[valueKey] : item;
                        var itemLabel = objectItem ? item[labelKey] : item;
                        var normalizedValue = String(itemValue == null ? '' : itemValue);
                        var selected = normalizedValue === latestValue;
                        if (selected) hasCurrent = true;
                        appendOption(normalizedValue, itemLabel, selected);
                    });
                    if (latestValue !== '' && !hasCurrent) {
                        var retained = doc.createElement('option');
                        retained.value = latestValue;
                        retained.textContent = latestLabel || latestValue;
                        retained.selected = true;
                        select.prepend(retained);
                    }
                    root.dataset.current = select.value;
                    setStatus(items.length === 0 ? 'empty' : 'ready', items.length === 0 ? root.dataset.emptyLabel || '' : '');
                    context.emit('load', {items: items}, false);
                    return true;
                } catch (error) {
                    if (requestId === sequence) {
                        setStatus('error', root.dataset.errorLabel || '');
                        context.emit('error', {error: error}, false);
                    }
                    return false;
                } finally {
                    if (requestId === sequence) root.removeAttribute('aria-busy');
                }
            }

            context.listen(input, 'input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(load, 180);
            });
            context.listen(select, 'change', function () {
                root.dataset.current = select.value;
            });
            load();
            return {
                load: load,
                element: root,
                destroy: function () {
                    sequence += 1;
                    window.clearTimeout(timer);
                    root.removeAttribute('aria-busy');
                },
            };
        });
        try {
            Object.defineProperty(UI, querySelectDefinitionMarker, {
                configurable: false,
                enumerable: false,
                value: true,
                writable: false,
            });
        } catch (_error) {
            UI[querySelectDefinitionMarker] = true;
        }
        querySelectRegistered = true;
        return true;
    }

    function waitForQuerySelectUi() {
        if (querySelectReadyListenerBound) return;
        querySelectReadyListenerBound = true;
        doc.addEventListener('weline:ui:ready', function () {
            querySelectReadyListenerBound = false;
            initForms(doc);
        }, { once: true });
    }

    function initForms(root) {
        if (!registerQuerySelectComponent()) waitForQuerySelectUi();
        root = root || doc;
        var forms = [];
        if (root.nodeType === 9) {
            forms = root.querySelectorAll('.w-param-form');
        } else if (root.nodeType === 1) {
            if (root.classList && root.classList.contains('w-param-form')) forms = [root];
            else forms = root.querySelectorAll ? root.querySelectorAll('.w-param-form') : [];
        }
        for (var i = 0; i < forms.length; i++) {
            initArrayEditors(forms[i]);
            initNavTreeEditors(forms[i]);
            initRangeSliders(forms[i]);
            initDatetimeShortcuts(forms[i]);
            initColorPickers(forms[i]);
            initImagePreview(forms[i]);
            initMediaImagePicker(forms[i]);
            initGroupToggles(forms[i]);
            var ui = getUi();
            if (ui) ui.mount(forms[i]);
        }
    }

    function readMediaImagePreviewUrl(input, preview, node) {
        if (!input) return '';
        var fromAttr = String(input.getAttribute('data-preview-url') || input.dataset.previewUrl || '').trim();
        var resolved = sanitizeLegacyImagePreviewUrl(fromAttr);
        if (resolved) return resolved;
        if (!node) {
            return sanitizeLegacyImagePreviewUrl(String(input.value || '').trim());
        }
        var inner = preview ? q(preview, 'img') : null;
        if (inner) {
            resolved = sanitizeLegacyImagePreviewUrl(inner.currentSrc || inner.getAttribute('src') || '');
            if (resolved) return resolved;
        }
        return '';
    }

    function updateMediaImagePreview(input) {
        if (!input) return;
        var previewId = input.getAttribute('data-preview');
        var preview = previewId ? doc.getElementById(previewId) : doc.getElementById(input.id + '_preview');
        if (!preview) return;
        var val = (input.value || '').trim();
        var node = parseFileImageNode(val);
        var previewUrl = readMediaImagePreviewUrl(input, preview, node);
        var inner = q(preview, 'img');
        var placeholder = q(preview, '.w-param-image-placeholder');
        var mediaWrap = preview.closest('.w-param-media-image');
        var actions = q(preview, '.w-param-image-actions');
        var clearBtn = actions ? q(actions, '.w-param-image-clear') : null;
        if (val) {
            if (previewUrl && !inner) {
                inner = doc.createElement('img');
                inner.alt = 'preview';
                preview.insertBefore(inner, placeholder || preview.firstChild);
            }
            if (inner && previewUrl) inner.src = previewUrl;
            if (inner && !previewUrl) {
                previewUrl = sanitizeLegacyImagePreviewUrl(inner.currentSrc || inner.getAttribute('src') || '');
                if (!previewUrl) inner.remove();
            }
            preview.classList.add('w-param-has-image');
            if (placeholder) {
                placeholder.hidden = !!previewUrl;
                if (!previewUrl) {
                    placeholder.textContent = placeholder.dataset.emptyLabel
                        || placeholder.getAttribute('data-empty-label')
                        || '从媒体库选择';
                }
            }
            if (actions && !clearBtn) {
                clearBtn = doc.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'w-button w-param-image-clear';
                clearBtn.dataset.tone = 'danger';
                clearBtn.dataset.variant = 'outline';
                clearBtn.dataset.size = 'sm';
                clearBtn.dataset.iconOnly = 'true';
                clearBtn.setAttribute('data-target', input.id);
                clearBtn.setAttribute('aria-label', input.dataset.clearLabel || '×');
                clearBtn.textContent = '×';
                actions.appendChild(clearBtn);
            }
        } else {
            preview.classList.remove('w-param-has-image');
            if (inner) inner.remove();
            if (placeholder) placeholder.hidden = false;
            if (clearBtn) clearBtn.remove();
        }
        if (mediaWrap) {
            initMediaImagePicker(mediaWrap);
        }
    }

    function parseFileImageNode(value) {
        var node = value;
        if (typeof node === 'string') {
            var trimmed = node.trim();
            if (!trimmed || trimmed.charAt(0) !== '{') return null;
            try { node = JSON.parse(trimmed); } catch (error) { return null; }
        }
        if (!node || typeof node !== 'object' || Array.isArray(node) || node.type !== 'file-image') return null;
        var usage = node.usage;
        if (!usage || typeof usage !== 'object' || !usage.asset_id || !usage.locale_code || Number(usage.version) !== 1) return null;
        return { type: 'file-image', usage: usage };
    }

    function sanitizeLegacyImagePreviewUrl(value) {
        var raw = String(value || '').trim();
        if (!raw
            || raw.length > 8192
            || /[\u0000-\u001F\u007F\\]/.test(raw)
            || raw.indexOf('//') === 0
        ) {
            return '';
        }
        try {
            var parsed = new URL(raw, document.baseURI);
            if (['http:', 'https:'].indexOf(parsed.protocol) === -1 || parsed.username || parsed.password) {
                return '';
            }
            var explicitScheme = /^[a-z][a-z0-9+.-]*:/i.exec(raw);
            if (explicitScheme && !/^https?:$/i.test(explicitScheme[0])) {
                return '';
            }
            return raw;
        } catch (_error) {
            return '';
        }
    }

    function selectedFileImageNode(file) {
        return parseFileImageNode(file && file.file_image_node);
    }

    function selectedFileImageValue(file) {
        var node = selectedFileImageNode(file);
        return node ? JSON.stringify(node) : '';
    }

    function selectedMediaPreviewUrl(file) {
        if (!file || typeof file !== 'object') return '';
        return sanitizeLegacyImagePreviewUrl(
            file.editor_preview_url || file.preview_url || file.thumb || file.url || file.path || ''
        );
    }

    function resolvePickerLocale(themeEl) {
        var locale = '';
        try { locale = new URLSearchParams(window.location.search).get('locale') || ''; } catch (error) {}
        if (!locale && themeEl) {
            locale = themeEl.getAttribute('data-config-locale')
                || themeEl.getAttribute('data-locale-code')
                || '';
        }
        // "default / 全语言" is a layout identity, not a FileAsset locale.
        // Stamp images with the site default locale so preview hydration matches.
        if (!locale || locale === 'default') {
            locale = (themeEl && themeEl.getAttribute('data-default-locale')) || '';
        }
        if (!locale || locale === 'default') {
            locale = document.documentElement.lang || 'zh_Hans_CN';
        }
        return String(locale).replace(/-/g, '_');
    }

    function getSelectedMediaValue(files) {
        if (!files || !files.length) return '';
        var file = files[0] || {};
        var typedValue = selectedFileImageValue(file);
        if (typedValue) return typedValue;
        if (file.type !== 'legacy-media-path') return '';
        return String(file.url || file.path || file.thumb || '').trim();
    }

    function getSelectedMediaValues(files) {
        var values = [];
        (files || []).forEach(function (file) {
            var value = selectedFileImageValue(file);
            if (value) values.push(value);
        });
        return values;
    }

    function dispatchMediaInputChange(input) {
        if (!input) return;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bindMediaManagerMessages(targetId, frame, onSelect, onCancel) {
        function isCurrentTarget(data) {
            return typeof data.target === 'string' && data.target === targetId;
        }

        function handleMessage(e) {
            if (e.origin !== window.location.origin || !frame || e.source !== frame.contentWindow) return;
            var data = e.data;
            if (!data || !data.type || !isCurrentTarget(data)) return;

            if (data.type === 'weline-media-manager-select') {
                var value = getSelectedMediaValue(data.files || []);
                if (!value) return;
                onSelect(value, data.files || []);
                return;
            }

            if (data.type === 'weline-media-manager-cancel' && onCancel) {
                onCancel();
            }
        }

        window.addEventListener('message', handleMessage);
        return function () {
            window.removeEventListener('message', handleMessage);
        };
    }

    var activeMediaDialog = null;

    function openMediaManagerDialog(options) {
        var ui = getUi();
        if (!ui || !options || !options.targetId || !options.url) return false;
        if (activeMediaDialog && activeMediaDialog.isConnected) {
            activeMediaDialog.focus({ preventScroll: true });
            return false;
        }

        var dialog = doc.createElement('dialog');
        dialog.className = 'w-dialog w-param-media-dialog';
        dialog.dataset.wComponent = 'dialog';
        dialog.dataset.state = 'closed';
        dialog.dataset.size = 'lg';
        dialog.dataset.wClosable = 'true';
        dialog.dataset.wBackdrop = 'dismissible';

        var header = doc.createElement('header');
        header.className = 'w-dialog__header';
        var title = doc.createElement('h2');
        title.className = 'w-dialog__title';
        title.id = 'w-param-media-title-' + Date.now();
        title.textContent = options.title || '选择媒体';
        dialog.setAttribute('aria-labelledby', title.id);
        var closeBtn = doc.createElement('button');
        closeBtn.type = 'button';
        if (options.closeId) closeBtn.id = options.closeId;
        closeBtn.className = 'w-button';
        closeBtn.dataset.tone = 'quiet';
        closeBtn.dataset.size = 'sm';
        closeBtn.textContent = '关闭';
        header.append(title, closeBtn);

        var body = doc.createElement('div');
        body.className = 'w-dialog__body w-param-media-dialog__body';
        var iframe = doc.createElement('iframe');
        iframe.className = 'w-param-media-dialog__frame';
        iframe.src = options.url;
        iframe.title = options.title || '媒体管理器';
        body.appendChild(iframe);
        dialog.append(header, body);

        var closed = false;
        var removeMessageHandler = bindMediaManagerMessages(options.targetId, iframe, function (value, files) {
            if (typeof options.onSelect === 'function') options.onSelect(value, files || []);
            ui.dialog.close(dialog, 'select');
        }, function () {
            ui.dialog.close(dialog, 'cancel');
        });
        function finish(event) {
            if (closed) return;
            closed = true;
            removeMessageHandler();
            if (activeMediaDialog === dialog) activeMediaDialog = null;
            if (typeof options.onClose === 'function') options.onClose(event?.detail?.returnValue || 'close');
            ui.unmount(dialog);
            dialog.remove();
        }
        dialog.addEventListener('weline:ui:dialog:close', finish, { once: true });
        closeBtn.addEventListener('click', function () { ui.dialog.close(dialog, 'button'); }, { once: true });
        doc.body.appendChild(dialog);
        activeMediaDialog = dialog;
        ui.mount(dialog);
        if (!ui.dialog.open(dialog)) {
            finish();
            return false;
        }
        return true;
    }

    function initMediaImagePicker(container) {
        qa(container, '.w-param-media-image-select').forEach(function (btn) {
            if (btn.dataset.wParamMediaInited) return;
            btn.dataset.wParamMediaInited = '1';
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-target');
                var defaultDir = btn.getAttribute('data-default-dir') || 'banner';
                var recommendW = btn.getAttribute('data-recommend-w') || '';
                var recommendH = btn.getAttribute('data-recommend-h') || '';
                var themeEl = doc.getElementById('themeEditor');
                var baseUrl = (themeEl && themeEl.getAttribute('data-file-manager-connector-base')) || '';
                if (!baseUrl || !targetId) return;
                var closeId = 'w-param-media-close-' + (targetId.replace(/[^a-z0-9_-]/gi, '_')) + '-' + Date.now();
                var params = ['path=' + encodeURIComponent(defaultDir), 'target=' + encodeURIComponent(targetId), 'close=' + encodeURIComponent(closeId), 'ext=jpg,png,gif,webp', 'usage=1', 'locale_code=' + encodeURIComponent(resolvePickerLocale(themeEl))];
                if (recommendW) params.push('recommend_width=' + encodeURIComponent(recommendW));
                if (recommendH) params.push('recommend_height=' + encodeURIComponent(recommendH));
                var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
                var mediaSelectionChanged = false;
                openMediaManagerDialog({
                    targetId: targetId,
                    closeId: closeId,
                    url: url,
                    title: '\u9009\u62e9\u5a92\u4f53',
                    onSelect: function (value, files) {
                        var input = doc.getElementById(targetId);
                        var file = files && files[0] ? files[0] : null;
                        var storedValue = selectedFileImageValue(file);
                        if (input && storedValue) {
                            input.value = storedValue;
                            var previewUrl = selectedMediaPreviewUrl(file);
                            if (previewUrl) {
                                input.dataset.previewUrl = previewUrl;
                                input.setAttribute('data-preview-url', previewUrl);
                            } else {
                                delete input.dataset.previewUrl;
                                input.removeAttribute('data-preview-url');
                            }
                            updateMediaImagePreview(input);
                            mediaSelectionChanged = true;
                        }
                    },
                    onClose: function () {
                        if (!mediaSelectionChanged) return;
                        var input = doc.getElementById(targetId);
                        if (input) {
                            updateMediaImagePreview(input);
                            dispatchMediaInputChange(input);
                        }
                    }
                });
            });
        });
        qa(container, '.w-param-media-image .w-param-image-clear').forEach(function (btn) {
            if (btn.dataset.wParamMediaClearInited) return;
            btn.dataset.wParamMediaClearInited = '1';
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-target');
                var input = doc.getElementById(targetId);
                var preview = doc.getElementById(targetId + '_preview');
                if (input) {
                    input.value = '';
                    delete input.dataset.previewUrl;
                    input.removeAttribute('data-preview-url');
                }
                if (preview) {
                    var img = q(preview, 'img');
                    if (img) img.remove();
                    preview.classList.remove('w-param-has-image');
                    var placeholder = q(preview, '.w-param-image-placeholder');
                    if (placeholder) placeholder.hidden = false;
                }
                if (input) input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    }

    function initGroupToggles(container) {
        if (container.closest && container.closest('[data-theme-editor-config-modal="1"]')) return;
        qa(container, '.w-param-group-title').forEach(function (title) {
            if (title.dataset.wParamInited) return;
            title.dataset.wParamInited = '1';
            var group = title.closest('.w-param-group');
            var fields = group ? q(group, ':scope > .w-param-fields') : null;
            function setExpanded(expanded) {
                if (!group) return;
                group.classList.toggle('w-param-collapsed', !expanded);
                group.dataset.state = expanded ? 'open' : 'closed';
                title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                if (fields) fields.hidden = !expanded;
            }
            setExpanded(title.getAttribute('aria-expanded') !== 'false' && !(group && group.classList.contains('w-param-collapsed')));
            title.addEventListener('click', function () {
                setExpanded(title.getAttribute('aria-expanded') !== 'true');
            });
        });
    }

    function initNavTreeEditors(container) {
        qa(container, '.w-param-nav-tree[data-w-component="nav-tree"]').forEach(function (wrapper) {
            if (wrapper.dataset.wParamInited) return;
            wrapper.dataset.wParamInited = '1';
            var fieldId = wrapper.getAttribute('data-field-id');
            var maxDepth = parseInt(wrapper.getAttribute('data-max-depth'), 10) || 3;
            var hiddenInput = doc.getElementById(fieldId);
            var mount = doc.getElementById(fieldId + '_editor');
            var bootEl = doc.getElementById(fieldId + '_nav_tree_boot');
            if (!hiddenInput || !mount) return;
            var boot = {};
            if (bootEl) {
                try {
                    boot = JSON.parse(bootEl.value || bootEl.textContent || '{}') || {};
                } catch (e) {
                    boot = {};
                }
            }
            var labels = boot.labels || {};
            var locales = resolveNavTreeLocales(boot);
            var state = {
                tree: Array.isArray(boot.tree) ? boot.tree : [],
                pageCandidates: Array.isArray(boot.page_candidates) ? boot.page_candidates : [],
                categoryCandidates: Array.isArray(boot.category_candidates) ? boot.category_candidates : [],
                detailPath: null
            };

            function resolveNavTreeLocales(bootPayload) {
                if (bootPayload && Array.isArray(bootPayload.locales) && bootPayload.locales.length) {
                    return bootPayload.locales;
                }
                var themeEl = doc.getElementById('themeEditor');
                if (themeEl) {
                    try {
                        var parsed = JSON.parse(themeEl.getAttribute('data-installed-locales') || '[]');
                        if (Array.isArray(parsed) && parsed.length) return parsed;
                    } catch (e) {}
                }
                return [{ code: 'zh_Hans_CN', name: '简体中文' }, { code: 'en_US', name: 'English' }];
            }

            function serializeImageFormValue(image) {
                if (image === null || image === undefined || image === '') return '';
                if (typeof image === 'object') {
                    try { return JSON.stringify(image); } catch (e) { return ''; }
                }
                return String(image);
            }

            function imagePreviewFromNode(image) {
                if (!image) return '';
                var node = parseFileImageNode(image);
                if (node && node.usage && node.usage.preview_url) {
                    return sanitizeLegacyImagePreviewUrl(String(node.usage.preview_url));
                }
                if (typeof image === 'string') {
                    var trimmed = image.trim();
                    if (trimmed.charAt(0) === '{') {
                        var parsed = parseFileImageNode(trimmed);
                        if (parsed && parsed.usage && parsed.usage.preview_url) {
                            return sanitizeLegacyImagePreviewUrl(String(parsed.usage.preview_url));
                        }
                        return '';
                    }
                    return sanitizeLegacyImagePreviewUrl(trimmed);
                }
                return '';
            }

            function ensureNodeI18n(node) {
                if (!node.i18n || typeof node.i18n !== 'object') node.i18n = {};
                if (!node.i18n.name || typeof node.i18n.name !== 'object') node.i18n.name = {};
                if (!node.i18n.description || typeof node.i18n.description !== 'object') node.i18n.description = {};
                return node.i18n;
            }

            function uid() {
                return 'n_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
            }

            function cloneNode(src, tagOverride) {
                var node = {
                    id: uid(),
                    tag: tagOverride || src.tag || 'custom',
                    name: String(src.name || src.text || ''),
                    url: String(src.url || '#'),
                    children: []
                };
                if (src.ref) node.ref = String(src.ref);
                if (src.description) node.description = String(src.description);
                if (src.image) node.image = src.image;
                if (src.i18n && typeof src.i18n === 'object') node.i18n = JSON.parse(JSON.stringify(src.i18n));
                if (src.meta && typeof src.meta === 'object') node.meta = src.meta;
                return node;
            }

            function walk(list, fn, path) {
                path = path || [];
                (list || []).forEach(function (node, index) {
                    var p = path.concat([index]);
                    fn(node, p);
                    if (node.children && node.children.length) walk(node.children, fn, p);
                });
            }

            function getAt(path) {
                if (!path || !path.length) {
                    return { parent: null, index: -1, node: null };
                }
                var parent = state.tree;
                for (var i = 0; i < path.length - 1; i++) {
                    var mid = parent[path[i]];
                    if (!mid) return { parent: null, index: -1, node: null };
                    mid.children = mid.children || [];
                    parent = mid.children;
                }
                var index = path[path.length - 1];
                return { parent: parent, index: index, node: parent[index] || null };
            }

            function removeAt(path) {
                var hit = getAt(path);
                if (!hit.parent || hit.index < 0) return;
                hit.parent.splice(hit.index, 1);
            }

            function depthOf(path) {
                return path.length;
            }

            function pathsEqual(a, b) {
                if (!a || !b || a.length !== b.length) {
                    return false;
                }
                for (var i = 0; i < a.length; i++) {
                    if (a[i] !== b[i]) {
                        return false;
                    }
                }
                return true;
            }

            function pathIsPrefix(prefix, path) {
                if (!prefix || !path || prefix.length > path.length) {
                    return false;
                }
                for (var i = 0; i < prefix.length; i++) {
                    if (prefix[i] !== path[i]) {
                        return false;
                    }
                }
                return true;
            }

            function pathIsAncestor(ancestor, path) {
                return pathIsPrefix(ancestor, path) && ancestor.length < path.length;
            }

            function subtreeDepth(node) {
                var kids = node && node.children ? node.children : [];
                if (!kids.length) {
                    return 1;
                }
                var maxChild = 1;
                kids.forEach(function (child) {
                    maxChild = Math.max(maxChild, subtreeDepth(child));
                });
                return 1 + maxChild;
            }

            function canNestUnder(targetPath, node) {
                return targetPath.length + subtreeDepth(node) <= maxDepth;
            }

            function remapPathAfterRemoval(removedPath, targetPath) {
                if (!targetPath || !targetPath.length) {
                    return [];
                }
                if (pathsEqual(removedPath, targetPath)) {
                    return null;
                }
                var next = targetPath.slice();
                var limit = Math.min(removedPath.length, targetPath.length);
                for (var level = 0; level < limit; level++) {
                    if (removedPath[level] !== targetPath[level]) {
                        return next;
                    }
                }
                if (removedPath.length <= targetPath.length) {
                    var removedIndex = removedPath[removedPath.length - 1];
                    var targetIndex = targetPath[removedPath.length - 1];
                    if (targetIndex > removedIndex) {
                        next[removedPath.length - 1] = targetIndex - 1;
                    }
                }
                return next;
            }

            function resolveDropMode(rowEl, ev) {
                var rect = rowEl.getBoundingClientRect();
                if (!rect.height) {
                    return 'child';
                }
                var ratio = (ev.clientY - rect.top) / rect.height;
                if (ratio < 0.28) {
                    return 'before';
                }
                if (ratio > 0.72) {
                    return 'after';
                }
                return 'child';
            }

            function clearDropMarkers(scope) {
                qa(scope || mount, '.w-nav-tree-row.is-drop-before, .w-nav-tree-row.is-drop-after, .w-nav-tree-row.is-drop-child, .w-nav-tree-children.is-drop-child, .w-nav-tree-drop-end.is-drop-target').forEach(function (el) {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-child', 'is-drop-target');
                });
            }

            function markDropTarget(el, mode) {
                clearDropMarkers(mount);
                if (!el) {
                    return;
                }
                if (el.classList.contains('w-nav-tree-drop-end')) {
                    el.classList.add('is-drop-target');
                    return;
                }
                if (el.classList.contains('w-nav-tree-children')) {
                    el.classList.add('is-drop-child');
                    return;
                }
                el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-child');
                if (mode === 'before') {
                    el.classList.add('is-drop-before');
                } else if (mode === 'after') {
                    el.classList.add('is-drop-after');
                } else {
                    el.classList.add('is-drop-child');
                }
            }

            function insertBefore(path, node) {
                if (!path || !path.length) {
                    state.tree.unshift(node);
                    return;
                }
                var hit = getAt(path);
                if (!hit.parent) {
                    state.tree.push(node);
                    return;
                }
                hit.parent.splice(hit.index, 0, node);
            }

            function insertAfter(path, node) {
                if (!path || !path.length) {
                    state.tree.push(node);
                    return;
                }
                var hit = getAt(path);
                if (!hit.parent) {
                    state.tree.push(node);
                    return;
                }
                hit.parent.splice(hit.index + 1, 0, node);
            }

            function insertAsChild(path, node) {
                if (!path || !path.length) {
                    state.tree.push(node);
                    return;
                }
                var hit = getAt(path);
                if (!hit.node) {
                    state.tree.push(node);
                    return;
                }
                hit.node.children = hit.node.children || [];
                hit.node.children.push(node);
            }

            function applyDrop(node, targetPath, mode) {
                if (!node) {
                    return;
                }
                if (mode === 'append-root') {
                    state.tree.push(node);
                    return;
                }
                if (!targetPath || !targetPath.length) {
                    if (mode === 'before') {
                        state.tree.unshift(node);
                    } else {
                        state.tree.push(node);
                    }
                    return;
                }
                if (mode === 'child') {
                    if (targetPath.length >= maxDepth || !canNestUnder(targetPath, node)) {
                        insertAfter(targetPath, node);
                        return;
                    }
                    insertAsChild(targetPath, node);
                    return;
                }
                if (mode === 'before') {
                    insertBefore(targetPath, node);
                    return;
                }
                insertAfter(targetPath, node);
            }

            function moveRow(fromPath, targetPath, mode) {
                if (!fromPath || !fromPath.length) {
                    return;
                }
                var fromHit = getAt(fromPath);
                if (!fromHit.node) {
                    return;
                }
                if (pathsEqual(fromPath, targetPath)) {
                    return;
                }
                if (targetPath && targetPath.length && pathIsAncestor(fromPath, targetPath)) {
                    return;
                }
                var moving = JSON.parse(JSON.stringify(fromHit.node));
                if (mode === 'child' && targetPath && targetPath.length && !canNestUnder(targetPath, moving)) {
                    mode = 'after';
                }
                removeAt(fromPath);
                var adjusted = mode === 'append-root' ? [] : remapPathAfterRemoval(fromPath, targetPath || []);
                if (adjusted === null) {
                    state.tree.push(moving);
                    persist();
                    render();
                    return;
                }
                applyDrop(moving, adjusted, mode);
            }

            function handleTreeDrop(ev, targetPath, mode) {
                ev.preventDefault();
                clearDropMarkers(mount);
                var candRaw = ev.dataTransfer.getData('application/x-w-nav-candidate');
                var rowRaw = ev.dataTransfer.getData('application/x-w-nav-row');
                if (candRaw) {
                    try {
                        var meta = JSON.parse(candRaw);
                        var list = meta.source === 'category' ? state.categoryCandidates : state.pageCandidates;
                        var src = list[meta.index];
                        if (src) {
                            applyDrop(cloneNode(src, meta.source), targetPath, mode);
                            persist();
                            render();
                        }
                    } catch (e) {}
                    return;
                }
                if (rowRaw) {
                    moveRow(parsePath(rowRaw), targetPath, mode);
                    persist();
                    render();
                }
            }

            function persist() {
                hiddenInput.value = JSON.stringify(state.tree || []);
                try {
                    hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) {}
            }

            function tagLabel(tag) {
                if (tag === 'page') return labels.tag_page || '页面';
                if (tag === 'category') return labels.tag_category || '分类';
                return labels.tag_custom || '自定义';
            }

            function renderCandidateList(title, items, tag) {
                var html = '<div class="w-nav-tree-panel"><div class="w-nav-tree-panel-title">' + esc(title) + '</div><ul class="w-nav-tree-candidates">';
                if (!items.length) {
                    html += '<li class="w-nav-tree-empty">—</li>';
                } else {
                    items.forEach(function (item, idx) {
                        html += '<li class="w-nav-tree-candidate" draggable="true" data-source="' + esc(tag) + '" data-index="' + idx + '" title="' + esc(labels.add_to_tree || '点击添加到菜单树') + '">'
                            + '<span class="w-nav-tree-badge">' + esc(tagLabel(tag)) + '</span> '
                            + '<span class="w-nav-tree-candidate-name">' + esc(item.name || '') + '</span>'
                            + '<button type="button" class="w-button w-nav-tree-candidate-add" data-tone="primary" data-variant="outline" data-size="sm" data-source="' + esc(tag) + '" data-index="' + idx + '" title="' + esc(labels.add_to_tree || '点击添加到菜单树') + '">+</button>'
                            + '</li>';
                    });
                }
                html += '</ul></div>';
                return html;
            }

            function addCandidateToTree(source, index, targetPath, mode) {
                var list = source === 'category' ? state.categoryCandidates : state.pageCandidates;
                var src = list[index];
                if (!src) {
                    return false;
                }
                applyDrop(cloneNode(src, source), targetPath || [], mode || 'append-root');
                persist();
                render();
                return true;
            }

            function renderRows(list, pathPrefix) {
                pathPrefix = pathPrefix || [];
                var html = '';
                (list || []).forEach(function (node, index) {
                    var path = pathPrefix.concat([index]);
                    var depth = path.length;
                    var indent = (depth - 1) * 20;
                    var previewUrl = imagePreviewFromNode(node.image);
                    var thumbHtml = previewUrl
                        ? '<span class="w-nav-tree-thumb"><img src="' + esc(previewUrl) + '" alt=""></span>'
                        : (node.image ? '<span class="w-nav-tree-thumb w-nav-tree-thumb--placeholder" title="' + esc(labels.has_image || '已设图片') + '">🖼</span>' : '');
                    var metaHtml = '';
                    if (node.description && String(node.description).trim()) {
                        metaHtml += '<span class="w-nav-tree-meta-badge" title="' + esc(node.description) + '">' + esc(labels.has_description || '描述') + '</span>';
                    }
                    html += '<div class="w-nav-tree-node" data-node-path="' + esc(path.join('.')) + '">';
                    html += '<div class="w-nav-tree-row" draggable="true" data-path="' + esc(path.join('.')) + '" style="padding-left:' + indent + 'px">'
                        + '<span class="w-nav-tree-handle" title="' + esc(labels.indent_hint || '') + '">⋮⋮</span>'
                        + thumbHtml
                        + '<span class="w-nav-tree-badge w-nav-tree-badge--' + esc(node.tag || 'custom') + '">' + esc(tagLabel(node.tag)) + '</span>'
                        + '<span class="w-nav-tree-name">' + esc(node.name || '') + '</span>'
                        + metaHtml
                        + '<span class="w-nav-tree-url">' + esc(node.url || '') + '</span>'
                        + '<button type="button" class="w-button w-nav-tree-detail" data-tone="neutral" data-variant="outline" data-size="sm" data-path="' + esc(path.join('.')) + '">' + esc(labels.detail || '编辑') + '</button>'
                        + '<button type="button" class="w-button w-nav-tree-remove" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-path="' + esc(path.join('.')) + '" aria-label="' + esc(labels.remove || '删除') + '">×</button>'
                        + '</div>';
                    if (depth < maxDepth) {
                        html += '<div class="w-nav-tree-children" data-parent-path="' + esc(path.join('.')) + '">';
                        if (node.children && node.children.length) {
                            html += renderRows(node.children, path);
                        } else {
                            html += '<div class="w-nav-tree-children-empty">' + esc(labels.drop_child_empty || '拖入成为子项') + '</div>';
                        }
                        html += '</div>';
                    }
                    html += '</div>';
                });
                return html;
            }

            function esc(v) {
                return String(v == null ? '' : v)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function renderDetailMediaImage(inputId, node) {
                var stored = serializeImageFormValue(node.image);
                var previewUrl = imagePreviewFromNode(node.image);
                var hasImage = stored !== '';
                var html = '<div class="w-param-media-image w-nav-tree-detail-image">';
                html += '<div class="w-param-image-preview' + (hasImage ? ' w-param-has-image' : '') + '" id="' + esc(inputId) + '_preview">';
                if (previewUrl) html += '<img src="' + esc(previewUrl) + '" alt="">';
                html += '<div class="w-param-image-placeholder"' + (hasImage && previewUrl ? ' hidden' : '') + '>' + esc(labels.image_pick || '从媒体库选择') + '</div>';
                html += '<div class="w-param-image-actions">';
                html += '<button type="button" class="w-button w-param-media-image-select" data-tone="primary" data-variant="outline" data-size="sm" data-target="' + esc(inputId) + '" data-default-dir="nav">' + esc(labels.image_pick || '选择') + '</button>';
                if (hasImage) {
                    html += '<button type="button" class="w-button w-param-image-clear" data-tone="danger" data-variant="outline" data-size="sm" data-icon-only="true" data-target="' + esc(inputId) + '" aria-label="×">×</button>';
                }
                html += '</div></div>';
                html += '<input type="hidden" id="' + esc(inputId) + '" data-detail-field="image" value="' + esc(stored) + '" data-preview="' + esc(inputId) + '_preview" data-clear-label="×">';
                html += '</div>';
                return html;
            }

            function renderDetailI18nSection(node) {
                var i18n = ensureNodeI18n(node);
                var html = '<div class="w-nav-tree-detail-i18n"><div class="w-nav-tree-detail-i18n-title">' + esc(labels.i18n || '多语言') + '</div>';
                locales.forEach(function (locale) {
                    var code = locale.code || locale.locale_code || '';
                    if (!code) return;
                    var localeName = locale.name || code;
                    var nameVal = (i18n.name && i18n.name[code]) ? i18n.name[code] : (code === 'zh_Hans_CN' ? (node.name || '') : '');
                    var descVal = (i18n.description && i18n.description[code]) ? i18n.description[code] : (code === 'zh_Hans_CN' ? (node.description || '') : '');
                    html += '<div class="w-nav-tree-detail-i18n-row" data-locale="' + esc(code) + '">';
                    html += '<div class="w-nav-tree-detail-i18n-locale">' + esc(localeName) + ' <small>(' + esc(code) + ')</small></div>';
                    html += '<label>' + esc(labels.i18n_name || '名称翻译') + '<input type="text" class="w-input" data-i18n-field="name" data-locale="' + esc(code) + '" value="' + esc(nameVal) + '"></label>';
                    html += '<label>' + esc(labels.i18n_description || '描述翻译') + '<textarea class="w-textarea" rows="2" data-i18n-field="description" data-locale="' + esc(code) + '">' + esc(descVal) + '</textarea></label>';
                    html += '</div>';
                });
                html += '</div>';
                return html;
            }

            function renderDetail() {
                if (!state.detailPath) return '';
                var hit = getAt(state.detailPath.split('.').map(function (n) { return parseInt(n, 10); }));
                var node = hit.node || { id: uid(), name: '', url: '', tag: 'custom', description: '', image: '', ref: '' };
                if (!node.id) node.id = uid();
                var imageInputId = fieldId + '_nav_detail_image_' + String(node.id).replace(/[^a-z0-9_-]/gi, '_');
                return '<div class="w-nav-tree-detail-dialog" role="dialog">'
                    + '<div class="w-nav-tree-detail-card">'
                    + '<h4>' + esc(labels.detail || '编辑') + '</h4>'
                    + '<label>' + esc(labels.name || '名称') + ' (' + esc(labels.i18n_name || '中文源串') + ')<input type="text" class="w-input" data-detail-field="name" value="' + esc(node.name || '') + '"></label>'
                    + '<label>' + esc(labels.url || '链接') + '<input type="text" class="w-input" data-detail-field="url" value="' + esc(node.url || '') + '"></label>'
                    + '<label>' + esc(labels.description || '描述') + '<textarea class="w-textarea" rows="2" data-detail-field="description">' + esc(node.description || '') + '</textarea></label>'
                    + renderDetailMediaImage(imageInputId, node)
                    + renderDetailI18nSection(node)
                    + '<label>' + esc(labels.ref || '引用') + '<input type="text" class="w-input" data-detail-field="ref" value="' + esc(node.ref || '') + '"></label>'
                    + '<div class="w-nav-tree-detail-actions">'
                    + '<button type="button" class="w-button" data-tone="primary" data-detail-save="1">' + esc(labels.save_detail || '保存') + '</button>'
                    + '<button type="button" class="w-button" data-tone="neutral" data-variant="outline" data-detail-cancel="1">' + esc(labels.cancel || '取消') + '</button>'
                    + '</div></div></div>';
            }

            function render() {
                mount.innerHTML = '<div class="w-nav-tree-layout">'
                    + '<div class="w-nav-tree-sources">'
                    + renderCandidateList(labels.pages || '页面', state.pageCandidates, 'page')
                    + renderCandidateList(labels.categories || '分类', state.categoryCandidates, 'category')
                    + '<button type="button" class="w-button w-nav-tree-add-custom" data-tone="primary" data-variant="outline">+ '
                    + esc(labels.add_custom || '添加自定义') + '</button>'
                    + '</div>'
                    + '<div class="w-nav-tree-main">'
                    + '<div class="w-nav-tree-hint">' + esc(labels.indent_hint || '') + '</div>'
                    + '<div class="w-nav-tree-rows" data-drop-root="1">'
                    + (state.tree.length ? renderRows(state.tree) : '<div class="w-nav-tree-empty">' + esc(labels.empty || '') + '</div>')
                    + '<div class="w-nav-tree-drop-end" data-drop-end="1">' + esc(labels.drop_append || '拖放到此处添加') + '</div>'
                    + '</div></div></div>'
                    + renderDetail();
                bind();
                if (state.detailPath) initMediaImagePicker(mount);
            }

            function parsePath(str) {
                return String(str || '').split('.').filter(Boolean).map(function (n) { return parseInt(n, 10); });
            }

            function bindDropSurface(el, getTargetPath, getMode) {
                if (!el) {
                    return;
                }
                el.addEventListener('dragover', function (ev) {
                    ev.preventDefault();
                    ev.dataTransfer.dropEffect = 'move';
                    markDropTarget(el, typeof getMode === 'function' ? getMode(ev) : getMode);
                });
                el.addEventListener('dragleave', function (ev) {
                    if (el.contains(ev.relatedTarget)) {
                        return;
                    }
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-child', 'is-drop-target');
                });
                el.addEventListener('drop', function (ev) {
                    var mode = typeof getMode === 'function' ? getMode(ev) : getMode;
                    handleTreeDrop(ev, typeof getTargetPath === 'function' ? getTargetPath(ev) : getTargetPath, mode);
                });
            }

            function bind() {
                var dragStarted = false;

                var addCustom = q(mount, '.w-nav-tree-add-custom');
                if (addCustom) {
                    addCustom.addEventListener('click', function () {
                        state.tree.push(cloneNode({ name: labels.tag_custom || '自定义', url: '#', tag: 'custom' }, 'custom'));
                        persist();
                        state.detailPath = String(state.tree.length - 1);
                        render();
                    });
                }

                function openDetail(pathStr) {
                    state.detailPath = pathStr;
                    render();
                }

                qa(mount, '.w-nav-tree-candidate').forEach(function (el) {
                    el.addEventListener('dragstart', function (ev) {
                        dragStarted = true;
                        var source = el.getAttribute('data-source');
                        var idx = parseInt(el.getAttribute('data-index'), 10);
                        ev.dataTransfer.setData('application/x-w-nav-candidate', JSON.stringify({ source: source, index: idx }));
                        ev.dataTransfer.effectAllowed = 'copy';
                    });
                    el.addEventListener('dragend', function () {
                        setTimeout(function () { dragStarted = false; }, 0);
                    });
                    el.addEventListener('click', function (ev) {
                        if (dragStarted || ev.target.closest('.w-nav-tree-candidate-add')) {
                            return;
                        }
                        addCandidateToTree(el.getAttribute('data-source'), parseInt(el.getAttribute('data-index'), 10));
                    });
                });

                qa(mount, '.w-nav-tree-candidate-add').forEach(function (btn) {
                    btn.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        addCandidateToTree(btn.getAttribute('data-source'), parseInt(btn.getAttribute('data-index'), 10));
                    });
                });

                qa(mount, '.w-nav-tree-row').forEach(function (el) {
                    el.addEventListener('dragstart', function (ev) {
                        if (ev.target && ev.target.closest && ev.target.closest('button')) {
                            ev.preventDefault();
                            return;
                        }
                        ev.stopPropagation();
                        ev.dataTransfer.setData('application/x-w-nav-row', el.getAttribute('data-path') || '');
                        ev.dataTransfer.effectAllowed = 'move';
                    });
                    el.addEventListener('dragend', function () {
                        clearDropMarkers(mount);
                    });
                    bindDropSurface(el, function () {
                        return parsePath(el.getAttribute('data-path'));
                    }, function (ev) {
                        return resolveDropMode(el, ev);
                    });
                });

                qa(mount, '.w-nav-tree-children').forEach(function (el) {
                    bindDropSurface(el, function () {
                        return parsePath(el.getAttribute('data-parent-path') || '');
                    }, 'child');
                });

                qa(mount, '.w-nav-tree-children-empty').forEach(function (el) {
                    var parentEl = el.closest('.w-nav-tree-children');
                    bindDropSurface(el, function () {
                        return parentEl ? parsePath(parentEl.getAttribute('data-parent-path') || '') : [];
                    }, 'child');
                });

                bindDropSurface(q(mount, '.w-nav-tree-drop-end'), function () {
                    return [];
                }, 'append-root');

                bindDropSurface(q(mount, '.w-nav-tree-empty'), function () {
                    return [];
                }, 'append-root');

                qa(mount, '.w-nav-tree-name').forEach(function (el) {
                    el.addEventListener('click', function () {
                        var row = el.closest('.w-nav-tree-row');
                        if (!row) return;
                        openDetail(row.getAttribute('data-path'));
                    });
                });

                qa(mount, '.w-nav-tree-remove').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        removeAt(parsePath(btn.getAttribute('data-path')));
                        persist();
                        render();
                    });
                });

                qa(mount, '.w-nav-tree-detail').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        openDetail(btn.getAttribute('data-path'));
                    });
                });

                var saveBtn = q(mount, '[data-detail-save]');
                var cancelBtn = q(mount, '[data-detail-cancel]');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', function () {
                        state.detailPath = null;
                        render();
                    });
                }
                if (saveBtn && state.detailPath) {
                    saveBtn.addEventListener('click', function () {
                        var hit = getAt(parsePath(state.detailPath));
                        if (!hit.node) {
                            state.detailPath = null;
                            render();
                            return;
                        }
                        qa(mount, '[data-detail-field]').forEach(function (input) {
                            var field = input.getAttribute('data-detail-field');
                            if (field === 'image') {
                                var raw = input.value || '';
                                var imageNode = parseFileImageNode(raw);
                                hit.node[field] = imageNode || raw;
                                return;
                            }
                            hit.node[field] = input.value;
                        });
                        var i18n = ensureNodeI18n(hit.node);
                        i18n.name = {};
                        i18n.description = {};
                        qa(mount, '[data-i18n-field]').forEach(function (input) {
                            var field = input.getAttribute('data-i18n-field');
                            var locale = input.getAttribute('data-locale');
                            if (!field || !locale) return;
                            var val = String(input.value || '').trim();
                            if (val !== '') i18n[field][locale] = val;
                        });
                        if (i18n.name.zh_Hans_CN) hit.node.name = i18n.name.zh_Hans_CN;
                        if (i18n.description.zh_Hans_CN) hit.node.description = i18n.description.zh_Hans_CN;
                        if (!Object.keys(i18n.name).length && !Object.keys(i18n.description).length) {
                            delete hit.node.i18n;
                        }
                        if (!hit.node.description) delete hit.node.description;
                        if (!hit.node.image) delete hit.node.image;
                        if (!hit.node.ref) delete hit.node.ref;
                        state.detailPath = null;
                        persist();
                        render();
                    });
                }
                var detailDialog = q(mount, '.w-nav-tree-detail-dialog');
                if (detailDialog) {
                    detailDialog.addEventListener('click', function (ev) {
                        if (ev.target === detailDialog) {
                            state.detailPath = null;
                            render();
                        }
                    });
                }
            }

            // Ensure initial hidden value matches boot tree
            persist();
            render();
        });
    }

    function initArrayEditors(container) {
        qa(container, '.w-param-array').forEach(function (wrapper) {
            if (wrapper.dataset.wParamInited) return;
            wrapper.dataset.wParamInited = '1';
            var fieldId = wrapper.getAttribute('data-field-id');
            var key = wrapper.getAttribute('data-key') || '';
            var minItems = parseInt(wrapper.getAttribute('data-min-items'), 10) || 0;
            var maxItemsAttr = wrapper.getAttribute('data-max-items');
            var maxItems = maxItemsAttr === '' || maxItemsAttr === null ? null : parseInt(maxItemsAttr, 10);
            var itemsEl = q(wrapper, '.w-param-array-items');
            var hiddenInput = doc.getElementById(fieldId);
            var addBtn = q(wrapper, '.w-param-array-add');
            var template = doc.getElementById(fieldId + '_template');
            var schemaEl = doc.getElementById(fieldId + '_schema');
            var itemSchema = [];
            if (schemaEl && schemaEl.textContent) {
                try { itemSchema = JSON.parse(schemaEl.textContent); } catch (e) {}
            }

            function getItems() {
                if (!hiddenInput || !hiddenInput.value) return [];
                try { return JSON.parse(hiddenInput.value); } catch (e) { return []; }
            }
            function setItems(items) {
                if (!hiddenInput) return;
                hiddenInput.value = JSON.stringify(items);
                updateAddButton();
                var countEl = q(wrapper, '.w-param-array-count');
                if (countEl && maxItems !== null) countEl.textContent = items.length + ' / ' + maxItems;
            }
            function updateAddButton() {
                var n = getItems().length;
                var disabled = maxItems !== null && n >= maxItems;
                if (addBtn) addBtn.disabled = disabled;
                var addWithMediaBtnEl = q(wrapper, '.w-param-array-add-with-media');
                if (addWithMediaBtnEl) addWithMediaBtnEl.disabled = disabled;
            }
            function collectItemFromNode(itemEl) {
                var idx = itemEl.getAttribute('data-index');
                if (idx === '__INDEX__') return null;
                if (itemSchema && Object.keys(itemSchema).length > 0) {
                    var obj = {};
                    qa(itemEl, 'input[data-field], select[data-field], textarea[data-field]').forEach(function (input) {
                        var field = input.getAttribute('data-field');
                        var v = input.value;
                        if (input.type === 'checkbox') v = input.checked;
                        else {
                            var imageNode = parseFileImageNode(v);
                            if (imageNode) v = imageNode;
                        }
                        obj[field] = v;
                    });
                    return obj;
                }
                var input = q(itemEl, '.w-param-array-item-input, input[type="text"]');
                return input ? input.value : '';
            }
            function buildItemHtml(index, item) {
                if (!template) return '';
                var html = template.innerHTML.replace(/\bdata-index="__INDEX__"/g, 'data-index="' + index + '"').replace(/__INDEX__/g, String(index));
                if (itemSchema && Object.keys(itemSchema).length > 0) {
                    Object.keys(itemSchema).forEach(function (fieldKey) {
                        var def = itemSchema[fieldKey];
                        var val = item && item[fieldKey] !== undefined ? item[fieldKey] : (def.default || '');
                        var sel = 'input[data-field="' + fieldKey + '"], select[data-field="' + fieldKey + '"], textarea[data-field="' + fieldKey + '"]';
                        var el = doc.createElement('div');
                        el.innerHTML = html;
                        var fieldEl = el.querySelector(sel);
                        if (fieldEl) {
                            if (fieldEl.tagName === 'INPUT' && fieldEl.type === 'checkbox') fieldEl.checked = !!val;
                            else {
                                var imageNode = parseFileImageNode(val);
                                fieldEl.value = imageNode ? JSON.stringify(imageNode) : val;
                            }
                        }
                        html = el.innerHTML;
                    });
                } else {
                    var singleInput = html.indexOf('data-index="' + index + '"');
                    if (singleInput !== -1) {
                        var inp = doc.createElement('div');
                        inp.innerHTML = html;
                        var i = inp.querySelector('input[type="text"], .w-param-array-item-input');
                        if (i) i.value = typeof item === 'object' ? '' : String(item);
                        html = inp.innerHTML;
                    }
                }
                return html;
            }
            function createItemElement(index, item) {
                var host = doc.createElement('div');
                host.innerHTML = buildItemHtml(index, item).trim();
                var itemElement = host.firstElementChild;
                return itemElement && itemElement.classList.contains('w-param-array-item')
                    ? itemElement
                    : null;
            }
            function addItem() {
                var items = getItems();
                if (maxItems !== null && items.length >= maxItems) return;
                items.push(itemSchema && Object.keys(itemSchema).length > 0 ? {} : '');
                setItems(items);
                var div = createItemElement(items.length - 1, items[items.length - 1]);
                if (!div) {
                    items.pop();
                    setItems(items);
                    return;
                }
                var removeBtn = q(div, '.w-param-array-remove');
                if (removeBtn) removeBtn.addEventListener('click', function () { removeItem(div); });
                itemsEl.appendChild(div);
                qa(div, 'input[type="hidden"][data-preview]').forEach(function (input) {
                    updateMediaImagePreview(input);
                });
                initMediaImagePicker(div);
                var empty = q(wrapper, '.w-param-array-empty');
                if (empty) empty.hidden = true;
            }
            function addItemWithImage(imageFieldKey, imageValue, previewUrl) {
                var imageNode = parseFileImageNode(imageValue);
                if (!imageNode || !imageFieldKey) return;
                var items = getItems();
                if (maxItems !== null && items.length >= maxItems) return;
                var newItem = {};
                if (itemSchema && Object.keys(itemSchema).length > 0) {
                    Object.keys(itemSchema).forEach(function (fk) {
                        newItem[fk] = fk === imageFieldKey ? imageNode : (itemSchema[fk].default !== undefined ? itemSchema[fk].default : '');
                    });
                } else {
                    newItem = imageNode;
                }
                items.push(newItem);
                setItems(items);
                var div = createItemElement(items.length - 1, newItem);
                if (!div) {
                    items.pop();
                    setItems(items);
                    return;
                }
                var removeBtn = q(div, '.w-param-array-remove');
                if (removeBtn) removeBtn.addEventListener('click', function () { removeItem(div); });
                itemsEl.appendChild(div);
                qa(div, 'input[type="hidden"][data-preview]').forEach(function (input) {
                    if (previewUrl) {
                        input.dataset.previewUrl = previewUrl;
                        input.setAttribute('data-preview-url', previewUrl);
                    }
                    updateMediaImagePreview(input);
                });
                initMediaImagePicker(div);
                var empty = q(wrapper, '.w-param-array-empty');
                if (empty) empty.hidden = true;
                hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            function removeItem(itemEl) {
                var items = getItems();
                var idx = parseInt(itemEl.getAttribute('data-index'), 10);
                if (isNaN(idx) || idx < 0) return;
                items.splice(idx, 1);
                itemEl.remove();
                reindexItems(wrapper);
                setItems(items);
                if (items.length === 0) {
                    var empty = q(wrapper, '.w-param-array-empty');
                    if (empty) empty.hidden = false;
                }
            }
            function reindexArrayItemIdentity(itemEl, newIndex) {
                var nestedIdentity = q(itemEl, '[data-array-index]');
                var indexedInput = q(itemEl, '[data-index]');
                var oldIndex = nestedIdentity
                    ? nestedIdentity.getAttribute('data-array-index')
                    : (indexedInput ? indexedInput.getAttribute('data-index') : itemEl.getAttribute('data-index'));
                var arrayKey = nestedIdentity ? (nestedIdentity.getAttribute('data-array-key') || '') : '';
                var oldFieldPrefix = arrayKey && oldIndex !== null ? arrayKey + '.' + oldIndex + '.' : '';
                var newFieldPrefix = arrayKey ? arrayKey + '.' + newIndex + '.' : '';
                var oldIdToken = oldIndex !== null ? '_' + oldIndex + '_' : '';
                var newIdToken = '_' + newIndex + '_';

                itemEl.setAttribute('data-index', String(newIndex));
                qa(itemEl, '[data-index]').forEach(function (node) {
                    node.setAttribute('data-index', String(newIndex));
                });
                qa(itemEl, '[data-array-index]').forEach(function (node) {
                    node.setAttribute('data-array-index', String(newIndex));
                });
                if (oldFieldPrefix) {
                    qa(itemEl, '[data-field]').forEach(function (node) {
                        var field = node.getAttribute('data-field') || '';
                        if (field.indexOf(oldFieldPrefix) === 0) {
                            node.setAttribute('data-field', newFieldPrefix + field.slice(oldFieldPrefix.length));
                        }
                    });
                }
                if (oldIdToken && oldIdToken !== newIdToken) {
                    qa(itemEl, '[id], [for], [data-target], [data-preview], [aria-controls]').forEach(function (node) {
                        ['id', 'for', 'data-target', 'data-preview', 'aria-controls'].forEach(function (attribute) {
                            var value = node.getAttribute(attribute);
                            if (value && value.indexOf(oldIdToken) !== -1) {
                                node.setAttribute(attribute, value.split(oldIdToken).join(newIdToken));
                            }
                        });
                    });
                }
            }
            function reindexItems(wrap) {
                var itemEls = qa(wrap, '.w-param-array-item');
                for (var j = 0; j < itemEls.length; j++) {
                    reindexArrayItemIdentity(itemEls[j], j);
                }
            }
            function syncFromDom() {
                var itemEls = qa(wrapper, '.w-param-array-item');
                var items = [];
                for (var k = 0; k < itemEls.length; k++) {
                    var it = collectItemFromNode(itemEls[k]);
                    if (it !== null) items.push(it);
                }
                setItems(items);
            }
            function notifyArrayValueChanged() {
                if (!hiddenInput) return;
                hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (addBtn) addBtn.addEventListener('click', addItem);
            var addWithMediaBtn = q(wrapper, '.w-param-array-add-with-media');
            if (addWithMediaBtn && !addWithMediaBtn.dataset.wParamAddWithMediaInited) {
                addWithMediaBtn.dataset.wParamAddWithMediaInited = '1';
                addWithMediaBtn.addEventListener('click', function () {
                    if (maxItems !== null && getItems().length >= maxItems) return;
                    var imageFieldKey = addWithMediaBtn.getAttribute('data-image-field') || 'image';
                    var defaultDir = addWithMediaBtn.getAttribute('data-default-dir') || 'banner';
                    var recommendW = addWithMediaBtn.getAttribute('data-recommend-w') || '';
                    var recommendH = addWithMediaBtn.getAttribute('data-recommend-h') || '';
                    var themeEl = doc.getElementById('themeEditor');
                    var baseUrl = (themeEl && themeEl.getAttribute('data-file-manager-connector-base')) || '';
                    if (!baseUrl) return;
                    var tempId = 'w-param-add-media-temp-' + (fieldId.replace(/[^a-z0-9_-]/gi, '_')) + '-' + Date.now();
                    var tempInput = doc.createElement('input');
                    tempInput.type = 'hidden';
                    tempInput.id = tempId;
                    tempInput.setAttribute('data-preview', tempId + '_preview');
                    tempInput.className = 'w-visually-hidden';
                    doc.body.appendChild(tempInput);
                    var closeId = 'w-param-media-close-' + tempId.replace(/[^a-z0-9_-]/gi, '_');
                    var params = ['path=' + encodeURIComponent(defaultDir), 'target=' + encodeURIComponent(tempId), 'close=' + encodeURIComponent(closeId), 'ext=jpg,png,gif,webp', 'multi=1', 'usage=1', 'locale_code=' + encodeURIComponent(resolvePickerLocale(themeEl))];
                    if (recommendW) params.push('recommend_width=' + encodeURIComponent(recommendW));
                    if (recommendH) params.push('recommend_height=' + encodeURIComponent(recommendH));
                    var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
                    var opened = openMediaManagerDialog({
                        targetId: tempId,
                        closeId: closeId,
                        url: url,
                        title: '\u6279\u91cf\u9009\u62e9\u5a92\u4f53',
                        onSelect: function (value, files) {
                            (files || []).forEach(function (file) {
                                var selectedValue = selectedFileImageValue(file);
                                if (!selectedValue) return;
                                addItemWithImage(imageFieldKey, selectedValue, selectedMediaPreviewUrl(file));
                            });
                            tempInput.value = '';
                            delete tempInput.dataset.previewUrl;
                        },
                        onClose: function () {
                            var selectedValue = (tempInput.value || '').trim();
                            if (selectedValue) addItemWithImage(imageFieldKey, selectedValue, tempInput.dataset.previewUrl || '');
                            if (tempInput.parentNode) tempInput.parentNode.removeChild(tempInput);
                        }
                    });
                    if (!opened && tempInput.parentNode) tempInput.parentNode.removeChild(tempInput);
                });
            }
            qa(wrapper, '.w-param-array-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var item = btn.closest('.w-param-array-item');
                    if (item) removeItem(item);
                });
            });
            qa(wrapper, 'input[type="hidden"][data-preview]').forEach(function (input) {
                updateMediaImagePreview(input);
            });
            if (itemsEl && !itemsEl.dataset.wParamReorderInited) {
                itemsEl.dataset.wParamReorderInited = '1';
                itemsEl.addEventListener('weline:ui:reorder-list:change', function (event) {
                    if (event.target !== itemsEl) return;
                    reindexItems(wrapper);
                    syncFromDom();
                    notifyArrayValueChanged();
                });
            }
            wrapper.addEventListener('change', syncFromDom);
            wrapper.addEventListener('input', syncFromDom);
            updateAddButton();
        });
    }

    function initRangeSliders(container) {
        qa(container, '.w-param-range').forEach(function (wrapper) {
            var slider = q(wrapper, 'input[type="range"]');
            var input = q(wrapper, '.w-param-range-input, input[type="number"]');
            var label = q(wrapper, '.w-param-range-label');
            if (!slider) return;
            function syncToInput() {
                var v = slider.value;
                if (input) input.value = v;
                if (label) label.textContent = v;
            }
            function syncToSlider() {
                var v = parseFloat(input.value);
                if (!isNaN(v)) slider.value = v;
            }
            slider.addEventListener('input', syncToInput);
            if (input) input.addEventListener('input', syncToSlider);
            syncToInput();
        });
    }

    function initDatetimeShortcuts(container) {
        qa(container, '.w-param-datetime').forEach(function (wrapper) {
            qa(wrapper, '[data-action="today"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) input.value = new Date().toISOString().slice(0, 10);
                });
            });
            qa(wrapper, '[data-action="tomorrow"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) {
                        var d = new Date();
                        d.setDate(d.getDate() + 1);
                        input.value = d.toISOString().slice(0, 10);
                    }
                });
            });
            qa(wrapper, '[data-action="next_week"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) {
                        var d = new Date();
                        d.setDate(d.getDate() + 7);
                        input.value = d.toISOString().slice(0, 10);
                    }
                });
            });
            qa(wrapper, '.w-param-datetime-clear').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) input.value = '';
                });
            });
        });
    }

    function initColorPickers(container) {
        qa(container, '.w-param-color').forEach(function (wrapper) {
            var picker = q(wrapper, '.w-param-form-control-color, input[type="color"]');
            var textInput = q(wrapper, 'input[type="text"]');
            var transparentButtons = qa(wrapper, '.w-param-btn-transparent');
            function syncTransparentState() {
                var active = textInput && textInput.value.trim().toLowerCase() === 'transparent';
                transparentButtons.forEach(function (btn) {
                    btn.dataset.state = active ? 'active' : 'idle';
                });
            }
            if (picker && textInput) {
                picker.addEventListener('input', function () {
                    textInput.value = picker.value;
                    syncTransparentState();
                });
                textInput.addEventListener('input', function () {
                    var raw = textInput.value.trim();
                    if (/^var\(\s*--/i.test(raw)) {
                        syncTransparentState();
                        return;
                    }
                    if (/^#[0-9a-fA-F]{6}$/.test(raw)) picker.value = raw;
                    syncTransparentState();
                });
            }
            transparentButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) {
                        input.value = 'transparent';
                        syncTransparentState();
                    }
                });
            });
            qa(wrapper, '.w-param-color-preset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var color = btn.getAttribute('data-color');
                    var input = doc.getElementById(targetId);
                    var pickerEl = doc.getElementById(targetId + '_picker');
                    if (input) input.value = color;
                    if (pickerEl && /^#[0-9a-fA-F]{6}$/.test(color)) pickerEl.value = color;
                    syncTransparentState();
                });
            });
            syncTransparentState();
        });
    }

    function initImagePreview(container) {
        qa(container, '.w-param-image').forEach(function (wrapper) {
            var urlInput = q(wrapper, 'input[type="text"][data-preview], input[type="text"]');
            var previewId = urlInput ? urlInput.getAttribute('data-preview') : null;
            var preview = previewId ? doc.getElementById(previewId) : q(wrapper, '.w-param-image-preview');
            if (urlInput && preview) {
            urlInput.addEventListener('input', function () {
                var val = urlInput.value.trim();
                var inner = q(preview, 'img');
                var placeholder = q(preview, '.w-param-image-placeholder');
                if (val) {
                    if (!inner) {
                        inner = doc.createElement('img');
                        inner.alt = 'preview';
                        preview.insertBefore(inner, placeholder);
                    }
                    inner.src = val;
                    preview.classList.add('w-param-has-image');
                    if (placeholder) placeholder.hidden = true;
                } else {
                    preview.classList.remove('w-param-has-image');
                    if (inner) inner.remove();
                    if (placeholder) placeholder.hidden = false;
                }
            });
            }
            qa(wrapper, '.w-param-image-clear').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    var prev = doc.getElementById(targetId + '_preview');
                    if (input) input.value = '';
                    if (prev) {
                        var img = q(prev, 'img');
                        if (img) img.remove();
                        prev.classList.remove('w-param-has-image');
                        var placeholder = q(prev, '.w-param-image-placeholder');
                        if (placeholder) placeholder.hidden = false;
                    }
                });
            });
        });
    }

    function run() {
        initForms();
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) initForms(node);
                    });
                });
            });
            observer.observe(doc.body, { childList: true, subtree: true });
        }
    }
    ready(run);
    if (typeof window !== 'undefined') {
        window.Weline = window.Weline || {};
        window.Weline.Widget = window.Weline.Widget || {};
        window.Weline.Widget.Params = Object.assign(window.Weline.Widget.Params || {}, {
            mount: initForms,
            mountMedia: function (root) {
                initMediaImagePicker(root || doc);
            },
        });
    }
})();

(function () {
    'use strict';

    var doc = document;
    var state = {
        open: false,
        context: null,
        target: null,
        nodeMap: new Map(),
        contextProviders: [],
        contextSelections: {},
        iframeClickBound: false,
    };

    var KNOWN_TYPES = [
        'content', 'banner', 'carousel', 'slider', 'footer', 'header', 'navigation',
        'search', 'social', 'newsletter', 'card', 'form', 'list', 'grid', 'product',
        'category', 'faq', 'testimonial', 'container'
    ];

    function ready(fn) {
        if (doc.readyState !== 'loading') fn();
        else doc.addEventListener('DOMContentLoaded', fn);
    }

    function getUi() {
        return window.Weline && window.Weline.UI ? window.Weline.UI : null;
    }

    function aiIconHtml(name, size) {
        var ui = getUi();
        if (!ui || !ui.icon || typeof ui.icon.create !== 'function') return '';
        var icon = ui.icon.create(name, { size: size || 'sm' });
        return icon ? icon.outerHTML : '';
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value || ''));
        }
        return String(value || '').replace(/["\\]/g, '\\$&');
    }

    function normalizeCode(value) {
        return String(value || '').trim().toLowerCase().replace(/[^a-z0-9_*\-]+/g, '_').replace(/^_+|_+$/g, '');
    }

    function normalizeCodeList(value) {
        var items = [];
        if (Array.isArray(value)) {
            value.forEach(function (item) {
                if (Array.isArray(item)) items = items.concat(normalizeCodeList(item));
                else if (item && typeof item === 'object') items = items.concat(normalizeCodeList(Object.values(item)));
                else items.push(item);
            });
        } else if (value && typeof value === 'object') {
            items = items.concat(Object.keys(value), normalizeCodeList(Object.values(value)));
        } else if (typeof value === 'string') {
            items = value.split(/[\s,;|]+/);
        } else if (value !== null && value !== undefined) {
            items.push(value);
        }
        return Array.from(new Set(items.map(normalizeCode).filter(Boolean)));
    }

    function ensureAiContextProviderApi() {
        window.Weline = window.Weline || {};
        window.Weline.Widget = window.Weline.Widget || {};
        var api = window.Weline.Widget.AI = window.Weline.Widget.AI || {};
        if (!Array.isArray(api.contextProviders)) {
            api.contextProviders = [];
        }
        if (typeof api.registerContextProvider !== 'function') {
            api.registerContextProvider = function (provider) {
                if (!provider || typeof provider !== 'object') return null;
                var id = normalizeCode(provider.id || provider.code || provider.name || '');
                if (!id) return null;
                var existingIndex = api.contextProviders.findIndex(function (item) {
                    return normalizeCode(item.id || item.code || item.name || '') === id;
                });
                var normalized = Object.assign({}, provider, { id: id });
                if (existingIndex >= 0) {
                    api.contextProviders.splice(existingIndex, 1, normalized);
                } else {
                    api.contextProviders.push(normalized);
                }
                window.dispatchEvent(new CustomEvent('weline-widget-ai-context-provider-change', { detail: { provider: normalized } }));
                return normalized;
            };
        }
        api.getContextProviders = function () {
            return api.contextProviders.slice();
        };
        return api;
    }

    function getContextProviders() {
        return ensureAiContextProviderApi().getContextProviders()
            .map(function (provider) {
                var id = normalizeCode(provider.id || provider.code || provider.name || '');
                return id ? Object.assign({}, provider, { id: id }) : null;
            })
            .filter(Boolean);
    }

    function refreshContextProviders() {
        state.contextProviders = getContextProviders();
        state.contextProviders.forEach(function (provider) {
            if (!Object.prototype.hasOwnProperty.call(state.contextSelections, provider.id)) {
                state.contextSelections[provider.id] = provider.defaultSelected !== false;
            }
        });
    }

    async function collectSelectedContextInjections() {
        refreshContextProviders();
        var selected = state.contextProviders.filter(function (provider) {
            return state.contextSelections[provider.id] !== false;
        });
        var injections = [];
        for (var i = 0; i < selected.length; i++) {
            var provider = selected[i];
            var payload = null;
            try {
                if (typeof provider.getContext === 'function') {
                    payload = await provider.getContext();
                } else if (provider.context !== undefined) {
                    payload = provider.context;
                }
            } catch (error) {
                console.warn('[Widget AI] context provider failed:', provider.id, error);
                payload = { error: error.message || 'context provider failed' };
            }
            injections.push({
                id: provider.id,
                label: provider.label || provider.name || provider.id,
                description: provider.description || '',
                optional: provider.optional !== false,
                data: payload || {},
            });
        }
        return injections;
    }

    function getThemeAdapter() {
        return window.Weline && window.Weline.Theme ? window.Weline.Theme.Editor || null : null;
    }

    function getPlacementContext() {
        var adapter = getThemeAdapter();
        if (!adapter || typeof adapter.getWidgetPlacementContext !== 'function') {
            return null;
        }
        return adapter.getWidgetPlacementContext();
    }

    function findSlot(context, slotId) {
        if (!context || !slotId) return null;
        return (context.slots || []).find(function (slot) { return String(slot.id || slot.slot_id || '') === String(slotId); }) || null;
    }

    function markAiWidgets(root) {
        root = root || doc;
        root.querySelectorAll('.widget-item[data-widget-code]').forEach(function (item) {
            var code = item.getAttribute('data-widget-code') || '';
            if (!/^ai[_-]/.test(code) || item.querySelector('.w-ai-badge')) return;
            var title = item.querySelector('.widget-preview-title, .widget-name') || item;
            var badge = doc.createElement('span');
            badge.className = 'w-ai-badge';
            badge.textContent = 'AI';
            title.appendChild(badge);
        });
    }

    function installButton() {
        if (doc.getElementById('wAiWidgetButton')) return;
        var panel = doc.getElementById('widgetPanel');
        if (!panel) return;
        var header = panel.querySelector('.panel-header, .widget-panel-header') || panel;
        var button = doc.createElement('button');
        button.type = 'button';
        button.id = 'wAiWidgetButton';
        button.className = 'w-button w-ai-widget-btn';
        button.dataset.variant = 'soft';
        button.dataset.tone = 'primary';
        button.innerHTML = aiIconHtml('sparkles') + '<span>AI 生成</span>';
        button.addEventListener('click', openPanel);
        header.appendChild(button);
    }

    function refreshContext() {
        refreshContextProviders();
        state.context = getPlacementContext();
        if (!state.context) {
            state.target = null;
            return;
        }
        if (!state.target) {
            state.target = state.context.selected_target || null;
        } else if (state.context.selected_target && !state.manualTarget) {
            state.target = state.context.selected_target;
        }
    }

    function targetLabel(target) {
        if (!target) return '未选择';
        if (target.type === 'widget') {
            var anchor = target.anchor || {};
            return (anchor.area || target.area || '') + ' > ' + (anchor.slot_id || target.slot_id || '') + ' > ' + (anchor.widget_name || anchor.widget_code || target.anchor_layout_id || '');
        }
        var slot = target.slot || findSlot(state.context, target.slot_id) || {};
        return (target.area || slot.area || '') + ' > ' + (slot.name || target.slot_id || slot.id || '');
    }

    function inferTypesForTarget(target) {
        var slot = (target && (target.slot || findSlot(state.context, target.slot_id))) || {};
        var accept = normalizeCodeList(slot.accept || target?.accept || []);
        var area = normalizeCode(target?.area || slot.area || '');
        var inferred = [];
        accept.forEach(function (code) {
            if (KNOWN_TYPES.indexOf(code) !== -1) inferred.push(code);
            KNOWN_TYPES.forEach(function (type) {
                if (code.indexOf(type) !== -1 && inferred.indexOf(type) === -1) inferred.push(type);
            });
        });
        if (accept.indexOf('*') !== -1 || inferred.length === 0) {
            if (area === 'footer') inferred.push('content', 'newsletter', 'social', 'footer', 'container');
            else if (area === 'header') inferred.push('navigation', 'search', 'header', 'container');
            else inferred.push('content', 'banner', 'card', 'container');
        }
        return Array.from(new Set(inferred.filter(function (type) { return KNOWN_TYPES.indexOf(type) !== -1; })));
    }

    function modeOptionsForTarget(target) {
        if (!target) return [{ value: 'into_slot', label: '放入 slot' }];
        if (target.type === 'widget') {
            var options = [
                { value: 'after', label: '作为后一个兄弟' },
                { value: 'before', label: '作为前一个兄弟' },
                { value: 'replace', label: '替换当前部件' }
            ];
            if (target.anchor && target.anchor.inner_slots && target.anchor.inner_slots.length) {
                options.push({ value: 'inside', label: '放入当前容器内部 slot' });
            }
            return options;
        }
        if (target.parent_anchor_layout_id) {
            return [{ value: 'inside', label: '放入当前容器内部 slot' }];
        }
        return [{ value: 'into_slot', label: '放入 slot' }];
    }

    function hasPlacementTarget(target) {
        return !!(target && (target.slot_id || target.parent_slot_id || target.anchor_layout_id));
    }

    function renderTreeNode(node, level, parentAnchor) {
        if (!node || !state.nodeMap) return '';
        var safeLevel = Math.max(0, Math.min(20, parseInt(level, 10) || 0));
        var id = 'n' + state.nodeMap.size;
        var target = null;
        var nextParentAnchor = parentAnchor;
        if (node.type === 'slot') {
            target = {
                type: 'slot',
                area: node.area || node.slot?.area || '',
                slot_id: node.slot?.id || node.id,
                slot: node.slot || null,
                insert_mode: parentAnchor ? 'inside' : 'into_slot',
                parent_anchor_layout_id: parentAnchor ? parentAnchor.layout_id : null,
            };
        } else if (node.type === 'widget') {
            target = {
                type: 'widget',
                area: node.area || node.anchor?.area || '',
                slot_id: node.anchor?.slot_id || '',
                anchor_layout_id: node.anchor?.layout_id || '',
                anchor: node.anchor || null,
                insert_mode: 'after',
            };
            nextParentAnchor = node.anchor || parentAnchor;
        }
        if (target) state.nodeMap.set(id, target);
        var active = target && state.target && (
            (target.slot_id && target.slot_id === state.target.slot_id && target.parent_anchor_layout_id === state.target.parent_anchor_layout_id)
            || (target.anchor_layout_id && target.anchor_layout_id === state.target.anchor_layout_id)
        );
        var html = '<button type="button" class="w-ai-tree-row ' + (active ? 'active' : '') + '" data-node-id="' + id + '" style="--w-ai-tree-level:' + safeLevel + '">';
        html += '<span class="w-ai-tree-indent"></span><span class="w-ai-tree-type">' + escapeHtml(node.type || '') + '</span><span>' + escapeHtml(node.label || node.id || '') + '</span></button>';
        (node.children || []).forEach(function (child) {
            html += renderTreeNode(child, level + 1, nextParentAnchor);
        });
        return html;
    }

    function renderContextOptions(panel) {
        var container = panel.querySelector('[data-ai-context-options]');
        if (!container) return;
        refreshContextProviders();
        if (!state.contextProviders.length) {
            container.innerHTML = '<div class="w-ai-widget-muted">No context providers. AI will only use the prompt.</div>';
            return;
        }
        container.innerHTML = state.contextProviders.map(function (provider) {
            var checked = state.contextSelections[provider.id] !== false;
            return [
                '<label class="w-ai-context-option">',
                '<input type="checkbox" data-ai-context-provider="' + escapeHtml(provider.id) + '"' + (checked ? ' checked' : '') + '>',
                '<span><strong>' + escapeHtml(provider.label || provider.name || provider.id) + '</strong>',
                '<span>' + escapeHtml(provider.description || 'Optional AI reference context') + '</span></span>',
                '</label>'
            ].join('');
        }).join('');
        container.querySelectorAll('[data-ai-context-provider]').forEach(function (input) {
            input.addEventListener('change', function () {
                state.contextSelections[input.getAttribute('data-ai-context-provider')] = input.checked;
            });
        });
    }

    function renderPanel() {
        var panel = doc.getElementById('wAiWidgetPanel');
        if (!panel) return;
        var context = state.context || {};
        var types = inferTypesForTarget(state.target);
        var modes = modeOptionsForTarget(state.target);
        var currentMode = state.target?.insert_mode || modes[0]?.value || 'into_slot';
        var slot = state.target ? (state.target.slot || findSlot(context, state.target.slot_id) || {}) : {};
        var accept = normalizeCodeList(slot.accept || state.target?.accept || []);
        state.nodeMap = new Map();

        panel.querySelector('[data-ai-target-summary]').textContent = targetLabel(state.target);
        panel.querySelector('[data-ai-target-protocol]').textContent = accept.length ? ('协议：' + accept.join(', ')) : '协议：未声明，可生成通用或容器内容';
        panel.querySelector('[data-ai-tree]').innerHTML = context.slot_tree ? renderTreeNode(context.slot_tree, 0, null) : '<div class="w-ai-widget-muted">暂无 slot</div>';
        renderContextOptions(panel);
        panel.querySelectorAll('.w-ai-tree-row').forEach(function (row) {
            row.addEventListener('click', function () {
                var selected = state.nodeMap.get(row.getAttribute('data-node-id'));
                if (!selected) return;
                state.target = selected;
                state.manualTarget = true;
                renderPanel();
            });
        });

        var modeSelect = panel.querySelector('[data-ai-insert-mode]');
        modeSelect.innerHTML = modes.map(function (mode) {
            return '<option value="' + escapeHtml(mode.value) + '"' + (mode.value === currentMode ? ' selected' : '') + '>' + escapeHtml(mode.label) + '</option>';
        }).join('');
        modeSelect.onchange = function () {
            if (!state.target) state.target = {};
            state.target.insert_mode = modeSelect.value;
        };

        var typeSelect = panel.querySelector('[data-ai-widget-type]');
        typeSelect.innerHTML = types.map(function (type) {
            return '<option value="' + escapeHtml(type) + '">' + escapeHtml(type) + '</option>';
        }).join('');
    }

    function bindVisualSelectionRefresh() {
        if (state.iframeClickBound) return;
        state.iframeClickBound = true;
        var refreshLater = function () {
            if (!state.open) return;
            window.setTimeout(function () {
                state.manualTarget = false;
                refreshContext();
                renderPanel();
            }, 120);
        };
        doc.addEventListener('click', function (e) {
            if (!state.open) return;
            if (e.target.closest('#previewViewStructure, #previewViewPreview')) refreshLater();
        }, true);
        window.setInterval(function () {
            if (!state.open) return;
            var frame = doc.querySelector('#previewFrame, iframe[name="previewFrame"]');
            var frameDoc = null;
            try { frameDoc = frame?.contentDocument || frame?.contentWindow?.document || null; } catch (e) {}
            if (frameDoc && !frameDoc.__wAiWidgetSelectionBound) {
                frameDoc.__wAiWidgetSelectionBound = true;
                frameDoc.addEventListener('click', refreshLater, true);
            }
        }, 900);
    }

    function openPanel() {
        var ui = getUi();
        if (!ui) return;
        var existing = doc.getElementById('wAiWidgetPanel');
        if (existing) {
            existing.focus({ preventScroll: true });
            return;
        }
        refreshContext();
        state.open = true;
        state.manualTarget = false;
        var panel = doc.createElement('dialog');
        panel.className = 'w-dialog w-ai-widget-panel';
        panel.id = 'wAiWidgetPanel';
        panel.dataset.wComponent = 'dialog';
        panel.dataset.state = 'closed';
        panel.dataset.size = 'lg';
        panel.dataset.wClosable = 'true';
        panel.dataset.wBackdrop = 'dismissible';
        panel.setAttribute('aria-labelledby', 'wAiWidgetTitle');
        panel.innerHTML = [
            '<header class="w-dialog__header w-ai-widget-header"><h2 class="w-dialog__title w-ai-widget-title" id="wAiWidgetTitle">' + aiIconHtml('sparkles') + '<span>AI 生成 Widget</span></h2><button type="button" class="w-button w-ai-widget-close" data-tone="quiet" data-size="sm" data-ai-close aria-label="关闭">' + aiIconHtml('close') + '</button></header>',
            '<div class="w-dialog__body w-ai-widget-body">',
            '<div class="w-ai-widget-section"><div class="w-ai-widget-section-title">位置选择器</div><div class="w-ai-widget-target"><strong data-ai-target-summary></strong><div class="w-ai-widget-muted" data-ai-target-protocol></div></div><div class="w-ai-widget-tree" data-ai-tree></div></div>',
            '<div class="w-ai-widget-section"><div class="w-ai-widget-section-title">生成配置</div><div class="w-ai-widget-form">',
            '<div class="w-ai-widget-field"><label>插入方式</label><select data-ai-insert-mode></select></div>',
            '<div class="w-ai-widget-field"><label>部件类型</label><select data-ai-widget-type></select></div>',
            '<div class="w-ai-widget-field full"><label>参考上下文</label><div class="w-ai-context-options" data-ai-context-options></div></div>',
            '<div class="w-ai-widget-field full"><label>生成要求</label><textarea data-ai-prompt placeholder="例如：在页脚生成一个品牌社交链接区，包含微信、抖音、YouTube 和邮箱订阅入口"></textarea></div>',
            '</div><div class="w-ai-widget-actions"><span class="w-ai-widget-muted" data-ai-status>生成后会保存为普通 Widget 并自动放入目标位置</span><button type="button" class="w-button w-ai-widget-generate" data-tone="primary" data-ai-generate>生成并放入</button></div></div>',
            '</div>'
        ].join('');
        doc.body.appendChild(panel);
        ui.mount(panel);
        panel.querySelector('[data-ai-close]').addEventListener('click', closePanel, { once: true });
        panel.querySelector('[data-ai-generate]').addEventListener('click', generateWidget);
        panel.addEventListener('weline:ui:dialog:close', function () {
            state.open = false;
            ui.unmount(panel);
            panel.remove();
        }, { once: true });
        bindVisualSelectionRefresh();
        renderPanel();
        if (!ui.dialog.open(panel)) {
            state.open = false;
            ui.unmount(panel);
            panel.remove();
        }
    }

    function closePanel() {
        var panel = doc.getElementById('wAiWidgetPanel');
        if (!panel) {
            state.open = false;
            return;
        }
        var ui = getUi();
        if (!ui || !ui.dialog.close(panel, 'close')) {
            state.open = false;
            if (ui) ui.unmount(panel);
            panel.remove();
        }
    }

    async function buildGenerationContext() {
        var context = state.context || {};
        var target = state.target || context.selected_target || {};
        var slot = target.slot || findSlot(context, target.slot_id) || {};
        return {
            area: target.area || slot.area || '',
            slot: slot,
            page_type: context.page_type || '',
            layout_type: context.layout_type || '',
            layout_option: context.layout_option || '',
            editor_area: context.editor_area || 'frontend',
            selected_target: target,
            context_injections: await collectSelectedContextInjections(),
        };
    }

    async function generateWidget() {
        var panel = doc.getElementById('wAiWidgetPanel');
        if (!panel) return;
        var prompt = (panel.querySelector('[data-ai-prompt]').value || '').trim();
        var status = panel.querySelector('[data-ai-status]');
        var button = panel.querySelector('[data-ai-generate]');
        if (!prompt) {
            status.textContent = '请填写生成要求';
            return;
        }
        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
            status.textContent = 'Weline.Api 尚未就绪';
            return;
        }
        var placementTarget = hasPlacementTarget(state.target) ? state.target : null;
        if (placementTarget) {
            state.target.insert_mode = panel.querySelector('[data-ai-insert-mode]').value || state.target.insert_mode || 'into_slot';
        }
        var desiredType = panel.querySelector('[data-ai-widget-type]').value || '';
        button.disabled = true;
        status.textContent = '正在生成 Widget...';
        try {
            var WidgetApi = await window.Weline.Api.resource('widget');
            var response = await WidgetApi.generateAiWidget({
                prompt: prompt,
                desired_type: desiredType,
                generation_context: await buildGenerationContext(),
                placement_target: placementTarget || {}
            }, { requestTimeoutMs: 180000 });
            var data = response && response.data && response.data.widget ? response.data : response;
            if (!data || data.success === false || !data.widget) {
                throw new Error((data && (data.message || data.error)) || 'AI Widget 生成失败');
            }
            addWidgetToLibrary(data.widget);
            if (!placementTarget) {
                status.textContent = '已生成并保存为普通 Widget';
                window.setTimeout(closePanel, 900);
                return;
            }
            status.textContent = '已生成，正在放入布局...';
            var adapter = getThemeAdapter();
            if (!adapter || typeof adapter.placeWidgetFromProvider !== 'function') {
                status.textContent = '已生成并保存为普通 Widget，当前页面没有可用放置适配器';
                window.setTimeout(closePanel, 1200);
                return;
            }
            var placed = await adapter.placeWidgetFromProvider(data.widget, data.placement_target || placementTarget);
            if (!placed || placed.success === false) {
                throw new Error((placed && placed.message) || '生成成功，但放入布局失败');
            }
            status.textContent = '已生成并放入目标位置';
            window.setTimeout(closePanel, 900);
        } catch (err) {
            console.error('[Widget AI] generate failed:', err);
            status.textContent = err.message || '生成失败';
        } finally {
            button.disabled = false;
            markAiWidgets();
        }
    }

    function slotCodes(slots) {
        if (!slots) return [];
        if (Array.isArray(slots)) return slots;
        if (typeof slots === 'object') return Object.keys(slots);
        return String(slots).split(',');
    }

    function addWidgetToLibrary(widget) {
        var list = doc.getElementById('widgetList');
        if (!list || !widget) return;
        var type = widget.type || 'content';
        var group = list.querySelector('.widget-group[data-type="' + cssEscape(type) + '"]');
        if (!group) {
            group = doc.createElement('div');
            group.className = 'widget-group';
            group.setAttribute('data-type', type);
            group.dataset.state = 'open';
            group.innerHTML = '<button type="button" class="widget-group-header" aria-expanded="true"><span class="w-theme-editor-toggle-icon">' + aiIconHtml('chevron-down') + '</span><span>' + escapeHtml(type) + '</span><span class="widget-count">0</span></button><div class="widget-group-content"></div>';
            group.querySelector('.widget-group-header').addEventListener('click', function () {
                var opening = group.dataset.state === 'closed';
                group.dataset.state = opening ? 'open' : 'closed';
                group.querySelector('.widget-group-header').setAttribute('aria-expanded', opening ? 'true' : 'false');
                var groupContent = group.querySelector('.widget-group-content');
                if (groupContent) groupContent.hidden = !opening;
            });
            list.insertBefore(group, list.firstChild);
        }
        var content = group.querySelector('.widget-group-content') || group;
        var existing = content.querySelector('.widget-item[data-widget-code="' + cssEscape(widget.code) + '"]');
        if (existing) existing.remove();
        var item = doc.createElement('div');
        item.className = 'widget-item draggable' + (widget.is_container ? ' widget-container' : '') + (widget.exclusive ? ' widget-exclusive' : '');
        item.draggable = true;
        item.setAttribute('data-widget-code', widget.code || '');
        item.setAttribute('data-widget-module', widget.module || 'Weline_Widget');
        item.setAttribute('data-widget-type', widget.type || '');
        item.setAttribute('data-widget-name', widget.name || widget.code || '');
        item.setAttribute('data-widget-position', JSON.stringify(widget.position || []));
        item.setAttribute('data-widget-compatible', widget.compatible === false ? '0' : '1');
        item.setAttribute('data-widget-slot', widget.slot || '');
        item.setAttribute('data-widget-exclusive', widget.exclusive ? '1' : '0');
        item.setAttribute('data-widget-supports', normalizeCodeList(widget.supports || []).join(','));
        item.setAttribute('data-widget-slots', normalizeCodeList(slotCodes(widget.slots)).join(','));
        item.setAttribute('data-widget-page-layouts', JSON.stringify(widget.page_layouts || ['*']));
        item.setAttribute('data-widget-is-container', widget.is_container ? '1' : '0');
        item.innerHTML = '<div class="widget-preview"><div class="widget-preview-canvas"><div class="w-ai-widget-placeholder">AI 部件</div></div><div class="widget-preview-overlay"><div class="widget-preview-title-row w-ai-widget-preview-title-row"><div class="widget-preview-title">' + escapeHtml(widget.name || widget.code || '') + '<span class="w-ai-badge">AI</span></div><button type="button" class="w-button w-theme-editor-preview-component w-ai-widget-preview-button" data-tone="neutral" data-variant="outline" data-size="sm" title="预览" aria-label="预览 ' + escapeHtml(widget.name || widget.code || '') + '" data-widget-module="' + escapeHtml(widget.module || 'Weline_Widget') + '" data-widget-code="' + escapeHtml(widget.code || '') + '" data-widget-name="' + escapeHtml(widget.name || widget.code || '') + '">' + aiIconHtml('eye') + '</button></div><div class="widget-preview-desc">' + escapeHtml(widget.description || '') + '</div></div></div>';
        content.insertBefore(item, content.firstChild);
        var count = group.querySelector('.widget-count');
        if (count) count.textContent = String(content.querySelectorAll('.widget-item').length);
        var adapter = getThemeAdapter();
        if (adapter && typeof adapter.registerWidgetLibraryItem === 'function') {
            adapter.registerWidgetLibraryItem(item);
        }
    }

    function init() {
        ensureAiContextProviderApi();
        if (!doc.getElementById('widgetPanel')) return;
        installButton();
        markAiWidgets();
        if (typeof MutationObserver !== 'undefined') {
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    mutation.addedNodes.forEach(function (node) {
                        if (node.nodeType === 1) markAiWidgets(node);
                    });
                });
            });
            observer.observe(doc.getElementById('widgetPanel'), { childList: true, subtree: true });
        }
    }

    ensureAiContextProviderApi();
    window.addEventListener('weline-widget-ai-context-provider-change', function () {
        refreshContextProviders();
        if (state.open) renderPanel();
        installButton();
    });
    ready(init);
})();
