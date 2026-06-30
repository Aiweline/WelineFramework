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

    function normalizeIconClassName(value) {
        value = (value || '').trim().replace(/\s+/g, ' ');
        if (!value) return '';
        var tokens = value.split(' ');
        if (tokens.length > 8) return '';
        for (var i = 0; i < tokens.length; i++) {
            if (!/^[A-Za-z0-9_-]{1,64}$/.test(tokens[i])) return '';
        }
        return tokens.join(' ');
    }

    function renderIconPreview(previewDisplay, value) {
        if (!previewDisplay) return;
        while (previewDisplay.firstChild) {
            previewDisplay.removeChild(previewDisplay.firstChild);
        }
        var icon = doc.createElement('i');
        var className = normalizeIconClassName(value);
        var tokens = className ? className.split(' ') : ['w-param-placeholder-icon'];
        tokens.forEach(function (token) {
            icon.classList.add(token);
        });
        previewDisplay.appendChild(icon);
    }

    function initForms(root) {
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
            initRangeSliders(forms[i]);
            initIconPickers(forms[i]);
            initDatetimeShortcuts(forms[i]);
            initColorPickers(forms[i]);
            initImagePreview(forms[i]);
            initMediaImagePicker(forms[i]);
            initGroupToggles(forms[i]);
        }
    }

    function updateMediaImagePreview(input) {
        if (!input) return;
        var previewId = input.getAttribute('data-preview');
        var preview = previewId ? doc.getElementById(previewId) : doc.getElementById(input.id + '_preview');
        if (!preview) return;
        var val = (input.value || '').trim();
        var inner = q(preview, 'img');
        var placeholder = q(preview, '.w-param-image-placeholder');
        var mediaWrap = preview.closest('.w-param-media-image');
        var actions = q(preview, '.w-param-image-actions');
        var clearBtn = actions ? q(actions, '.w-param-image-clear') : null;
        if (val) {
            if (!inner) {
                inner = doc.createElement('img');
                inner.alt = 'preview';
                preview.insertBefore(inner, placeholder || preview.firstChild);
            }
            inner.src = val;
            preview.classList.add('w-param-has-image');
            if (placeholder) placeholder.style.display = 'none';
            if (actions && !clearBtn) {
                clearBtn = doc.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'w-param-btn w-param-btn-sm w-param-btn-outline-danger w-param-image-clear';
                clearBtn.setAttribute('data-target', input.id);
                clearBtn.textContent = '×';
                actions.appendChild(clearBtn);
            }
        } else {
            preview.classList.remove('w-param-has-image');
            if (inner) inner.remove();
            if (placeholder) placeholder.style.display = '';
            if (clearBtn) clearBtn.remove();
        }
        if (mediaWrap) {
            initMediaImagePicker(mediaWrap);
        }
    }

    function getSelectedMediaValue(files) {
        if (!files || !files.length) return '';
        var file = files[0] || {};
        return String(file.url || file.path || file.thumb || file.name || '').trim();
    }

    function getSelectedMediaValues(files) {
        var values = [];
        (files || []).forEach(function (file) {
            var value = String((file && (file.url || file.path || file.thumb || file.name)) || '').trim();
            if (value) values.push(value);
        });
        return values;
    }

    function dispatchMediaInputChange(input) {
        if (!input) return;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function bindMediaManagerMessages(targetId, onSelect, onCancel) {
        function isCurrentTarget(data) {
            return !data.target || data.target === targetId;
        }

        function handleMessage(e) {
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
                var params = ['path=' + encodeURIComponent(defaultDir), 'target=' + encodeURIComponent(targetId), 'close=' + encodeURIComponent(closeId), 'ext=jpg,png,gif,webp'];
                if (recommendW) params.push('recommend_width=' + encodeURIComponent(recommendW));
                if (recommendH) params.push('recommend_height=' + encodeURIComponent(recommendH));
                var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
                var overlay = doc.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;';
                var box = doc.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.2);width:90%;max-width:900px;height:80vh;display:flex;flex-direction:column;z-index:9999;';
                var header = doc.createElement('div');
                header.style.cssText = 'padding:8px 12px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;justify-content:space-between;';
                var closeBtn = doc.createElement('button');
                closeBtn.type = 'button';
                closeBtn.id = closeId;
                closeBtn.textContent = '\u5173\u95ED';
                closeBtn.style.cssText = 'padding:4px 12px;cursor:pointer;border:1px solid #dee2e6;border-radius:4px;background:#fff;';
                header.appendChild(closeBtn);
                box.appendChild(header);
                var iframe = doc.createElement('iframe');
                iframe.src = url;
                iframe.style.cssText = 'flex:1;width:100%;border:none;';
                box.appendChild(iframe);
                overlay.appendChild(box);
                var removeMessageHandler = null;
                var closed = false;
                function closeModal() {
                    if (closed) return;
                    closed = true;
                    if (removeMessageHandler) removeMessageHandler();
                    var input = doc.getElementById(targetId);
                    if (input) {
                        updateMediaImagePreview(input);
                        dispatchMediaInputChange(input);
                    }
                    overlay.remove();
                }
                removeMessageHandler = bindMediaManagerMessages(targetId, function (value) {
                    var input = doc.getElementById(targetId);
                    if (!input) return;
                    input.value = value;
                    closeModal();
                }, closeModal);
                closeBtn.addEventListener('click', closeModal);
                overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
                doc.body.appendChild(overlay);
            });
        });
        qa(container, '.w-param-media-image .w-param-image-clear').forEach(function (btn) {
            if (btn.dataset.wParamMediaClearInited) return;
            btn.dataset.wParamMediaClearInited = '1';
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-target');
                var input = doc.getElementById(targetId);
                var preview = doc.getElementById(targetId + '_preview');
                if (input) input.value = '';
                if (preview) {
                    var img = q(preview, 'img');
                    if (img) img.remove();
                    preview.classList.remove('w-param-has-image');
                    var placeholder = q(preview, '.w-param-image-placeholder');
                    if (placeholder) placeholder.style.display = '';
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
            title.addEventListener('click', function () {
                var group = title.closest('.w-param-group');
                if (group) group.classList.toggle('w-param-collapsed');
            });
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
                            else fieldEl.value = val;
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
            function addItem() {
                var items = getItems();
                if (maxItems !== null && items.length >= maxItems) return;
                items.push(itemSchema && Object.keys(itemSchema).length > 0 ? {} : '');
                setItems(items);
                var div = doc.createElement('div');
                div.className = 'w-param-array-item';
                div.setAttribute('data-index', String(items.length - 1));
                div.innerHTML = buildItemHtml(items.length - 1, items[items.length - 1]);
                var removeBtn = q(div, '.w-param-array-remove');
                if (removeBtn) removeBtn.addEventListener('click', function () { removeItem(div); });
                itemsEl.appendChild(div);
                qa(div, 'input[type="hidden"][data-preview]').forEach(function (input) {
                    updateMediaImagePreview(input);
                });
                initMediaImagePicker(div);
                var empty = q(wrapper, '.w-param-array-empty');
                if (empty) empty.style.display = 'none';
            }
            function addItemWithImage(imageFieldKey, imageUrl) {
                if (!imageUrl || !imageFieldKey) return;
                var items = getItems();
                if (maxItems !== null && items.length >= maxItems) return;
                var newItem = {};
                if (itemSchema && Object.keys(itemSchema).length > 0) {
                    Object.keys(itemSchema).forEach(function (fk) {
                        newItem[fk] = fk === imageFieldKey ? imageUrl : (itemSchema[fk].default !== undefined ? itemSchema[fk].default : '');
                    });
                } else {
                    newItem = imageUrl;
                }
                items.push(newItem);
                setItems(items);
                var div = doc.createElement('div');
                div.className = 'w-param-array-item';
                div.setAttribute('data-index', String(items.length - 1));
                div.innerHTML = buildItemHtml(items.length - 1, newItem);
                var removeBtn = q(div, '.w-param-array-remove');
                if (removeBtn) removeBtn.addEventListener('click', function () { removeItem(div); });
                itemsEl.appendChild(div);
                qa(div, 'input[type="hidden"][data-preview]').forEach(function (input) {
                    updateMediaImagePreview(input);
                });
                initMediaImagePicker(div);
                var empty = q(wrapper, '.w-param-array-empty');
                if (empty) empty.style.display = 'none';
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
                    if (empty) empty.style.display = '';
                }
            }
            function reindexItems(wrap) {
                var itemEls = qa(wrap, '.w-param-array-item');
                for (var j = 0; j < itemEls.length; j++) {
                    itemEls[j].setAttribute('data-index', String(j));
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
                    tempInput.style.cssText = 'position:absolute;left:-9999px;';
                    doc.body.appendChild(tempInput);
                    var closeId = 'w-param-media-close-' + tempId.replace(/[^a-z0-9_-]/gi, '_');
                    var params = ['path=' + encodeURIComponent(defaultDir), 'target=' + encodeURIComponent(tempId), 'close=' + encodeURIComponent(closeId), 'ext=jpg,png,gif,webp', 'multi=1'];
                    if (recommendW) params.push('recommend_width=' + encodeURIComponent(recommendW));
                    if (recommendH) params.push('recommend_height=' + encodeURIComponent(recommendH));
                    var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + params.join('&');
                    var overlay = doc.createElement('div');
                    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;';
                    var box = doc.createElement('div');
                    box.style.cssText = 'background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.2);width:90%;max-width:900px;height:80vh;display:flex;flex-direction:column;z-index:9999;';
                    var header = doc.createElement('div');
                    header.style.cssText = 'padding:8px 12px;border-bottom:1px solid #dee2e6;display:flex;align-items:center;justify-content:space-between;';
                    var closeBtn = doc.createElement('button');
                    closeBtn.type = 'button';
                    closeBtn.id = closeId;
                    closeBtn.textContent = '\u5173\u95ED';
                    closeBtn.style.cssText = 'padding:4px 12px;cursor:pointer;border:1px solid #dee2e6;border-radius:4px;background:#fff;';
                    header.appendChild(closeBtn);
                    box.appendChild(header);
                    var iframe = doc.createElement('iframe');
                    iframe.src = url;
                    iframe.style.cssText = 'flex:1;width:100%;border:none;';
                    box.appendChild(iframe);
                    overlay.appendChild(box);
                    var removeMessageHandler = null;
                    var closed = false;
                    function closeModal() {
                        if (closed) return;
                        closed = true;
                        if (removeMessageHandler) removeMessageHandler();
                        var selectedUrl = (tempInput.value || '').trim();
                        if (selectedUrl) addItemWithImage(imageFieldKey, selectedUrl);
                        overlay.remove();
                        if (tempInput.parentNode) tempInput.parentNode.removeChild(tempInput);
                    }
                    removeMessageHandler = bindMediaManagerMessages(tempId, function (value, files) {
                        var selectedUrls = getSelectedMediaValues(files);
                        if (!selectedUrls.length && value) selectedUrls = [value];
                        selectedUrls.forEach(function (selectedUrl) {
                            addItemWithImage(imageFieldKey, selectedUrl);
                        });
                        tempInput.value = '';
                        closeModal();
                    }, closeModal);
                    closeBtn.addEventListener('click', closeModal);
                    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
                    doc.body.appendChild(overlay);
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

    function initIconPickers(container) {
        qa(container, '.w-param-icon').forEach(function (wrapper) {
            if (wrapper.dataset.wParamInited) return;
            wrapper.dataset.wParamInited = '1';
            var trigger = q(wrapper, '.w-param-icon-trigger');
            var panel = q(wrapper, '.w-param-icon-panel');
            var hiddenInput = q(wrapper, 'input[type="hidden"]');
            var previewDisplay = q(wrapper, '.w-param-icon-preview-display');
            var searchInput = panel ? q(panel, '.w-param-icon-search input, input') : null;
            var customInput = panel ? q(panel, '.w-param-icon-custom input') : null;
            var applyBtn = panel ? q(panel, '.w-param-icon-apply') : null;
            var clearBtn = q(wrapper, '.w-param-icon-clear');

            function setValue(val) {
                val = normalizeIconClassName(val);
                if (hiddenInput) hiddenInput.value = val;
                renderIconPreview(previewDisplay, val);
                if (panel) panel.style.display = 'none';
            }
            if (trigger && panel) {
                trigger.addEventListener('click', function () {
                    panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
                });
                doc.addEventListener('click', function (e) {
                    if (!wrapper.contains(e.target)) panel.style.display = 'none';
                });
            }
            qa(wrapper, '.w-param-icon-item').forEach(function (item) {
                item.addEventListener('click', function () {
                    var icon = item.getAttribute('data-icon');
                    qa(wrapper, '.w-param-icon-item').forEach(function (i) { i.classList.remove('w-param-selected'); });
                    item.classList.add('w-param-selected');
                    setValue(icon);
                });
            });
            if (searchInput && panel) {
                searchInput.addEventListener('input', function () {
                    var term = searchInput.value.toLowerCase();
                    qa(panel, '.w-param-icon-item').forEach(function (el) {
                        var icon = (el.getAttribute('data-icon') || '').toLowerCase();
                        el.style.display = term === '' || icon.indexOf(term) !== -1 ? '' : 'none';
                    });
                });
            }
            if (applyBtn && customInput) {
                applyBtn.addEventListener('click', function () { setValue(customInput.value); });
            }
            if (clearBtn) clearBtn.addEventListener('click', function () { setValue(''); });
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
            if (picker && textInput) {
                picker.addEventListener('input', function () { textInput.value = picker.value; });
                textInput.addEventListener('input', function () {
                    if (/^#[0-9a-fA-F]{6}$/.test(textInput.value)) picker.value = textInput.value;
                });
            }
            qa(wrapper, '.w-param-btn-transparent').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = doc.getElementById(targetId);
                    if (input) {
                        input.value = 'transparent';
                        btn.classList.add('active');
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
                });
            });
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
                    if (placeholder) placeholder.style.display = 'none';
                } else {
                    preview.classList.remove('w-param-has-image');
                    if (inner) inner.remove();
                    if (placeholder) placeholder.style.display = '';
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
                        if (placeholder) placeholder.style.display = '';
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
        window.WidgetParamTypesInit = initForms;
        window.WidgetParamTypesInitMedia = function (root) {
            initMediaImagePicker(root || doc);
        };
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
        if (!Array.isArray(window.WelineWidgetAiContextProviders)) {
            window.WelineWidgetAiContextProviders = [];
        }
        if (typeof window.WelineRegisterWidgetAiContextProvider !== 'function') {
            window.WelineRegisterWidgetAiContextProvider = function (provider) {
                if (!provider || typeof provider !== 'object') return null;
                var id = normalizeCode(provider.id || provider.code || provider.name || '');
                if (!id) return null;
                var existingIndex = window.WelineWidgetAiContextProviders.findIndex(function (item) {
                    return normalizeCode(item.id || item.code || item.name || '') === id;
                });
                var normalized = Object.assign({}, provider, { id: id });
                if (existingIndex >= 0) {
                    window.WelineWidgetAiContextProviders.splice(existingIndex, 1, normalized);
                } else {
                    window.WelineWidgetAiContextProviders.push(normalized);
                }
                window.dispatchEvent(new CustomEvent('weline-widget-ai-context-provider-change', { detail: { provider: normalized } }));
                return normalized;
            };
        }
        window.Weline = window.Weline || {};
        window.Weline.WidgetAi = window.Weline.WidgetAi || {};
        window.Weline.WidgetAi.registerContextProvider = window.WelineRegisterWidgetAiContextProvider;
        window.Weline.WidgetAi.getContextProviders = function () {
            return window.WelineWidgetAiContextProviders.slice();
        };
    }

    function getContextProviders() {
        ensureAiContextProviderApi();
        return window.WelineWidgetAiContextProviders
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
        return window.ThemeEditor || null;
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

    function installStyles() {
        if (doc.getElementById('w-ai-widget-style')) return;
        var style = doc.createElement('style');
        style.id = 'w-ai-widget-style';
        style.textContent = [
            '.w-ai-widget-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #d8d6ff;background:#f5f3ff;color:#4f46e5;border-radius:6px;padding:6px 10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .18s ease;}',
            '.w-ai-widget-btn:hover{background:#ece9ff;border-color:#b8b3ff;}',
            '.w-ai-widget-overlay{position:fixed;inset:0;background:rgba(15,23,42,.42);z-index:100200;display:flex;align-items:center;justify-content:center;padding:24px;}',
            '.w-ai-widget-panel{width:min(980px,96vw);height:min(760px,92vh);background:#fff;border-radius:8px;box-shadow:0 18px 60px rgba(15,23,42,.28);display:flex;flex-direction:column;overflow:hidden;}',
            '.w-ai-widget-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #edf0f5;}',
            '.w-ai-widget-title{font-size:16px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;}',
            '.w-ai-widget-close{border:0;background:#f3f4f6;color:#64748b;border-radius:6px;width:32px;height:32px;cursor:pointer;}',
            '.w-ai-widget-body{display:grid;grid-template-columns:minmax(260px,340px) 1fr;gap:16px;padding:16px;min-height:0;flex:1;background:#f8fafc;}',
            '.w-ai-widget-section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;min-height:0;}',
            '.w-ai-widget-section-title{padding:11px 12px;border-bottom:1px solid #edf0f5;font-size:13px;font-weight:700;color:#374151;}',
            '.w-ai-widget-target{padding:12px;font-size:13px;color:#111827;line-height:1.5;}',
            '.w-ai-widget-tree{padding:8px;overflow:auto;max-height:520px;}',
            '.w-ai-tree-row{display:flex;align-items:center;gap:8px;width:100%;border:0;background:transparent;text-align:left;border-radius:6px;padding:7px 8px;font-size:12px;color:#374151;cursor:pointer;}',
            '.w-ai-tree-row:hover{background:#f3f4f6;}',
            '.w-ai-tree-row.active{background:#eef2ff;color:#4338ca;font-weight:700;}',
            '.w-ai-tree-indent{display:inline-block;width:calc(var(--level,0) * 14px);flex:0 0 calc(var(--level,0) * 14px);}',
            '.w-ai-tree-type{font-size:10px;color:#64748b;border:1px solid #e5e7eb;border-radius:999px;padding:1px 6px;background:#fff;}',
            '.w-ai-widget-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px;}',
            '.w-ai-widget-field{display:flex;flex-direction:column;gap:6px;}',
            '.w-ai-widget-field.full{grid-column:1/-1;}',
            '.w-ai-widget-field label{font-size:12px;font-weight:700;color:#374151;}',
            '.w-ai-widget-field select,.w-ai-widget-field textarea,.w-ai-widget-field input{border:1px solid #dbe2ea;border-radius:6px;padding:9px 10px;font-size:13px;outline:none;background:#fff;}',
            '.w-ai-widget-field textarea{min-height:150px;resize:vertical;line-height:1.5;}',
            '.w-ai-widget-field select:focus,.w-ai-widget-field textarea:focus,.w-ai-widget-field input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12);}',
            '.w-ai-context-options{display:grid;grid-template-columns:1fr;gap:8px;border:1px solid #e5e7eb;border-radius:6px;padding:8px;background:#f8fafc;}',
            '.w-ai-context-option{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#374151;}',
            '.w-ai-context-option input{margin-top:2px;flex:0 0 auto;}',
            '.w-ai-context-option strong{display:block;font-size:12px;color:#111827;}',
            '.w-ai-context-option span{display:block;color:#64748b;line-height:1.35;}',
            '.w-ai-widget-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border-top:1px solid #edf0f5;background:#fff;}',
            '.w-ai-widget-generate{border:0;background:#6d5df6;color:#fff;border-radius:6px;padding:10px 16px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px rgba(109,93,246,.22);}',
            '.w-ai-widget-generate[disabled]{opacity:.6;cursor:not-allowed;box-shadow:none;}',
            '.w-ai-widget-muted{font-size:12px;color:#64748b;}',
            '.w-ai-badge{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe;font-size:10px;font-weight:800;padding:1px 6px;margin-left:6px;vertical-align:middle;}',
            '.w-ai-widget-placeholder{height:90px;display:flex;align-items:center;justify-content:center;background:#eef2ff;color:#4f46e5;font-weight:800;border-radius:6px;}',
            '@media(max-width:760px){.w-ai-widget-body{grid-template-columns:1fr}.w-ai-widget-form{grid-template-columns:1fr}.w-ai-widget-panel{height:94vh}}'
        ].join('\n');
        doc.head.appendChild(style);
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
        button.className = 'w-ai-widget-btn';
        button.innerHTML = '<i class="ri-sparkling-line"></i><span>AI 生成</span>';
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
        var html = '<button type="button" class="w-ai-tree-row ' + (active ? 'active' : '') + '" data-node-id="' + id + '" style="--level:' + level + '">';
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
        installStyles();
        refreshContext();
        state.open = true;
        state.manualTarget = false;
        var overlay = doc.createElement('div');
        overlay.className = 'w-ai-widget-overlay';
        overlay.id = 'wAiWidgetOverlay';
        overlay.innerHTML = [
            '<div class="w-ai-widget-panel" id="wAiWidgetPanel">',
            '<div class="w-ai-widget-header"><div class="w-ai-widget-title"><i class="ri-sparkling-line"></i><span>AI 生成 Widget</span></div><button type="button" class="w-ai-widget-close" data-ai-close>×</button></div>',
            '<div class="w-ai-widget-body">',
            '<div class="w-ai-widget-section"><div class="w-ai-widget-section-title">位置选择器</div><div class="w-ai-widget-target"><strong data-ai-target-summary></strong><div class="w-ai-widget-muted" data-ai-target-protocol></div></div><div class="w-ai-widget-tree" data-ai-tree></div></div>',
            '<div class="w-ai-widget-section"><div class="w-ai-widget-section-title">生成配置</div><div class="w-ai-widget-form">',
            '<div class="w-ai-widget-field"><label>插入方式</label><select data-ai-insert-mode></select></div>',
            '<div class="w-ai-widget-field"><label>部件类型</label><select data-ai-widget-type></select></div>',
            '<div class="w-ai-widget-field full"><label>参考上下文</label><div class="w-ai-context-options" data-ai-context-options></div></div>',
            '<div class="w-ai-widget-field full"><label>生成要求</label><textarea data-ai-prompt placeholder="例如：在页脚生成一个品牌社交链接区，包含微信、抖音、YouTube 和邮箱订阅入口"></textarea></div>',
            '</div><div class="w-ai-widget-actions"><span class="w-ai-widget-muted" data-ai-status>生成后会保存为普通 Widget 并自动放入目标位置</span><button type="button" class="w-ai-widget-generate" data-ai-generate>生成并放入</button></div></div>',
            '</div></div>'
        ].join('');
        doc.body.appendChild(overlay);
        overlay.querySelector('[data-ai-close]').addEventListener('click', closePanel);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) closePanel(); });
        overlay.querySelector('[data-ai-generate]').addEventListener('click', generateWidget);
        bindVisualSelectionRefresh();
        renderPanel();
    }

    function closePanel() {
        state.open = false;
        var overlay = doc.getElementById('wAiWidgetOverlay');
        if (overlay) overlay.remove();
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
            group.innerHTML = '<div class="widget-group-header" data-toggle="collapse"><i class="ri-arrow-down-s-line toggle-icon"></i><span>' + escapeHtml(type) + '</span><span class="widget-count">0</span></div><div class="widget-group-content"></div>';
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
        item.innerHTML = '<div class="widget-preview"><div class="widget-preview-canvas"><div class="w-ai-widget-placeholder">AI 部件</div></div><div class="widget-preview-overlay"><div class="widget-preview-title-row d-flex align-items-center justify-content-between gap-2"><div class="widget-preview-title">' + escapeHtml(widget.name || widget.code || '') + '<span class="w-ai-badge">AI</span></div><button type="button" class="btn btn-sm btn-outline-secondary btn-preview-component flex-shrink-0" title="预览" data-widget-module="' + escapeHtml(widget.module || 'Weline_Widget') + '" data-widget-code="' + escapeHtml(widget.code || '') + '" data-widget-name="' + escapeHtml(widget.name || widget.code || '') + '"><i class="ri-eye-line"></i></button></div><div class="widget-preview-desc">' + escapeHtml(widget.description || '') + '</div></div></div>';
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
        installStyles();
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
