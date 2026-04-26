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
        if (val) {
            if (!inner) {
                inner = doc.createElement('img');
                inner.alt = 'preview';
                preview.insertBefore(inner, placeholder || preview.firstChild);
            }
            inner.src = val;
            preview.classList.add('w-param-has-image');
            if (placeholder) placeholder.style.display = 'none';
        } else {
            preview.classList.remove('w-param-has-image');
            if (inner) inner.remove();
            if (placeholder) placeholder.style.display = '';
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
                    qa(itemEl, '[data-field]').forEach(function (input) {
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
                        var sel = '[data-field="' + fieldKey + '"]';
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
                initMediaImagePicker(wrapper);
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
                initMediaImagePicker(wrapper);
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
                val = (val || '').trim();
                if (hiddenInput) hiddenInput.value = val;
                if (previewDisplay) {
                    previewDisplay.innerHTML = val ? '<i class="' + val.replace(/"/g, '&quot;') + '"></i>' : '<i class="w-param-placeholder-icon"></i>';
                }
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
    if (typeof window !== 'undefined') window.WidgetParamTypesInit = initForms;
})();
