(function () {
    'use strict';

    var root = document.querySelector('[data-product-admin]');
    if (!root) {
        return;
    }

    var stateElement = document.getElementById('product-admin-state');
    var state = {};
    if (stateElement) {
        try {
            state = JSON.parse(stateElement.textContent || '{}');
        } catch (error) {
            state = {};
        }
    }

    var apiPromise = null;

    function api() {
        if (!apiPromise) {
            apiPromise = Promise.resolve().then(function () {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error('商品后台 API 尚未加载，请刷新页面后重试');
                }
                return window.Weline.Api.resource('product_admin');
            });
        }
        return apiPromise;
    }

    function businessResult(value) {
        var current = value;
        for (var depth = 0; depth < 3; depth += 1) {
            if (!current || typeof current !== 'object' || !current.data || typeof current.data !== 'object') {
                break;
            }
            if (Object.prototype.hasOwnProperty.call(current, 'error_code')
                || Object.prototype.hasOwnProperty.call(current, 'snapshot')
                || Object.prototype.hasOwnProperty.call(current, 'items')
                || Object.prototype.hasOwnProperty.call(current, 'context')) {
                break;
            }
            current = current.data;
        }
        return current || {};
    }

    function call(operation, params) {
        return api().then(function (resource) {
            if (!resource || typeof resource[operation] !== 'function') {
                throw new Error('商品后台 API 不支持操作：' + operation);
            }
            return resource[operation](params || {}, {
                keepBusinessResult: true,
                silent: true
            });
        }).then(businessResult);
    }

    function notify(tone, message) {
        var text = String(message || '');
        var toast = window.Weline && window.Weline.UI ? window.Weline.UI.toast : null;
        if (toast && typeof toast[tone] === 'function') {
            toast[tone](text);
            return;
        }
        var status = document.getElementById('product-editor-status');
        if (status) {
            status.textContent = text;
            status.setAttribute('data-tone', tone === 'error' ? 'danger' : tone);
        }
    }

    function messageFrom(error, fallback) {
        if (error && error.message) {
            return String(error.message);
        }
        return fallback;
    }

    function setBusy(buttons, busy) {
        buttons.forEach(function (button) {
            if (!button) {
                return;
            }
            button.disabled = busy;
            button.setAttribute('aria-busy', busy ? 'true' : 'false');
        });
    }

    function selectedStoreIds(scope) {
        return Array.prototype.map.call(
            scope.querySelectorAll('input[name="store_ids[]"]:checked'),
            function (input) {
                return parseInt(input.value, 10);
            }
        ).filter(function (value) {
            return Number.isInteger(value) && value > 0;
        });
    }

    function parseJsonField(element, expected, label) {
        var raw = element ? String(element.value || '').trim() : '';
        if (raw === '') {
            return expected === 'array' ? [] : {};
        }
        var value;
        try {
            value = JSON.parse(raw);
        } catch (error) {
            throw new Error(label + '不是有效 JSON');
        }
        if (expected === 'array' && !Array.isArray(value)) {
            throw new Error(label + '必须是 JSON 数组');
        }
        if (expected === 'object' && (Array.isArray(value) || !value || typeof value !== 'object')) {
            throw new Error(label + '必须是 JSON 对象');
        }
        return value;
    }

    function parseProviderFieldValue(field) {
        var input = field.querySelector('[data-provider-input]');
        var code = String(field.getAttribute('data-provider-code') || '');
        var label = String(field.getAttribute('data-provider-label') || code || '类型字段');
        var type = String(field.getAttribute('data-provider-type') || 'string');
        var required = field.getAttribute('data-provider-required') === '1';
        if (!input || code === '') {
            throw new Error('Provider 字段定义不完整');
        }

        if (type === 'boolean') {
            return String(input.value) === '1';
        }
        if (type === 'multiselect') {
            var values = Array.prototype.map.call(input.options, function (option) {
                return option.selected ? String(option.value) : null;
            }).filter(function (value) {
                return value !== null;
            });
            if (required && values.length === 0) {
                throw new Error(label + '至少选择一项');
            }
            return values;
        }

        var raw = String(input.value || '').trim();
        if (required && raw === '') {
            throw new Error(label + '不能为空');
        }
        if (type === 'integer') {
            if (raw === '') {
                return null;
            }
            var integerValue = Number(raw);
            if (!/^-?\d+$/.test(raw) || !Number.isSafeInteger(integerValue)) {
                throw new Error(label + '必须是安全整数');
            }
            return integerValue;
        }
        if (type === 'decimal') {
            if (raw === '') {
                return null;
            }
            var decimalValue = Number(raw);
            if (!Number.isFinite(decimalValue)) {
                throw new Error(label + '必须是有效数字');
            }
            return decimalValue;
        }
        if (type === 'json') {
            if (raw === '') {
                return null;
            }
            try {
                return JSON.parse(raw);
            } catch (error) {
                throw new Error(label + '不是有效 JSON');
            }
        }
        return raw;
    }

    function collectProviderConfiguration(rootNode) {
        var advancedInput = rootNode.querySelector('[data-provider-unknown]');
        var provider_unknown_fields = parseJsonField(
            advancedInput,
            'object',
            '扩展类型配置'
        );
        var configuration = Object.assign(Object.create(null), provider_unknown_fields);
        rootNode.querySelectorAll('[data-provider-field]').forEach(function (field) {
            var code = String(field.getAttribute('data-provider-code') || '');
            if (code === '') {
                throw new Error('Provider 字段编码不能为空');
            }
            configuration[code] = parseProviderFieldValue(field);
        });
        return configuration;
    }

    function randomHex(bytes) {
        var values = new Uint8Array(bytes);
        window.crypto.getRandomValues(values);
        return Array.prototype.map.call(values, function (value) {
            return value.toString(16).padStart(2, '0');
        }).join('');
    }

    function requestHash(action, payload) {
        if (!window.crypto || !window.crypto.subtle || typeof window.TextEncoder !== 'function') {
            return Promise.reject(new Error('当前浏览器不支持安全请求哈希，请升级浏览器'));
        }
        var body = JSON.stringify({
            action: action,
            payload: payload,
            at: Date.now(),
            nonce: randomHex(16)
        });
        return window.crypto.subtle.digest('SHA-256', new window.TextEncoder().encode(body))
            .then(function (buffer) {
                return Array.prototype.map.call(new Uint8Array(buffer), function (value) {
                    return value.toString(16).padStart(2, '0');
                }).join('');
            });
    }

    function commandEnvelope(action, websiteId, productUuid, expectedVersion, payload) {
        return requestHash(action, payload).then(function (hash) {
            return {
                action: action,
                website_id: websiteId,
                global_product_uuid: productUuid || null,
                expected_version: expectedVersion === null ? null : expectedVersion,
                request_hash: hash,
                payload: payload
            };
        });
    }

    function executeCommand(action, payload, button) {
        var snapshot = state.snapshot || {};
        var identity = snapshot.identity || {};
        var product = snapshot.product || {};
        var buttons = Array.prototype.slice.call(root.querySelectorAll(
            '[data-product-command], [data-product-governance], #product-editor-form button[type="submit"]'
        ));
        setBusy(buttons, true);
        return commandEnvelope(
            action,
            parseInt(state.website_id, 10) || 0,
            identity.global_product_uuid || null,
            identity.version === undefined ? null : parseInt(identity.version, 10),
            Object.assign({
                local_version: parseInt(product.publish_version, 10) || 0
            }, payload || {})
        ).then(function (command) {
            return call('command', {command: command});
        }).then(function (result) {
            if (!result.success) {
                var failure = new Error(result.message || '商品操作失败');
                failure.result = result;
                throw failure;
            }
            notify('success', result.message || '操作成功');
            return result;
        }).finally(function () {
            setBusy(buttons, false);
            if (button) {
                button.blur();
            }
        });
    }

    function initializeCatalog() {
        var createForm = document.getElementById('product-create-form');
        var typeInput = document.getElementById('product-create-type');
        var axesField = document.getElementById('product-create-axes-field');

        function updateAxesVisibility() {
            var option = typeInput && typeInput.options[typeInput.selectedIndex];
            var supportsVariants = option && option.getAttribute('data-supports-variants') === '1';
            if (axesField) {
                axesField.hidden = !supportsVariants;
            }
        }

        if (typeInput) {
            typeInput.addEventListener('change', updateAxesVisibility);
            updateAxesVisibility();
        }

        if (createForm) {
            createForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!createForm.reportValidity()) {
                    return;
                }
                var submit = createForm.querySelector('button[type="submit"]');
                var payload;
                try {
                    var type = String(typeInput.value || 'simple');
                    payload = {
                        product_type: type,
                        name: String(document.getElementById('product-create-name').value || '').trim(),
                        sku: String(document.getElementById('product-create-sku').value || '').trim().toUpperCase(),
                        currency: 'CNY',
                        store_ids: selectedStoreIds(createForm)
                    };
                    if (type === 'configurable') {
                        payload.sku_prefix = payload.sku;
                        payload.axes = parseJsonField(
                            document.getElementById('product-create-axes'),
                            'array',
                            '规格轴'
                        );
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '请检查新建商品信息'));
                    return;
                }

                setBusy([submit], true);
                commandEnvelope(
                    'create',
                    parseInt(state.website_id, 10) || 0,
                    null,
                    null,
                    payload
                ).then(function (command) {
                    return call('command', {command: command});
                }).then(function (result) {
                    if (!result.success) {
                        throw new Error(result.message || '创建商品失败');
                    }
                    var identity = result.data && result.data.identity ? result.data.identity : {};
                    var uuid = identity.global_product_uuid || '';
                    if (!uuid) {
                        throw new Error('商品已创建，但返回结果缺少商品身份');
                    }
                    var url = new URL(state.edit_url, window.location.origin);
                    url.searchParams.set('website_id', String(state.website_id || 0));
                    url.searchParams.set('global_product_uuid', uuid);
                    window.location.assign(url.toString());
                }).catch(function (error) {
                    notify('error', messageFrom(error, '创建商品失败'));
                    setBusy([submit], false);
                });
            });
        }

        root.querySelectorAll('[data-product-action="refresh"]').forEach(function (button) {
            button.addEventListener('click', function () {
                window.location.reload();
            });
        });
    }

    function normalizeAttributeRows(rows) {
        return rows.map(function (row) {
            if (!row || typeof row !== 'object' || Array.isArray(row)) {
                throw new Error('属性行必须是对象');
            }
            var normalized = Object.assign({}, row);
            if (!normalized.scope_state) {
                if (normalized.inherit) {
                    normalized.scope_state = 'inherit';
                } else if (normalized.cleared) {
                    normalized.scope_state = 'cleared';
                } else {
                    normalized.scope_state = 'explicit';
                }
            }
            delete normalized.explicit;
            delete normalized.cleared;
            delete normalized.inherit;
            return normalized;
        });
    }

    function rawUrlEncode(value) {
        return encodeURIComponent(String(value)).replace(/[!'()*]/g, function (character) {
            return '%' + character.charCodeAt(0).toString(16).toUpperCase();
        });
    }

    function variantCombinationKey(combination) {
        return Object.keys(combination).sort().map(function (axis) {
            return rawUrlEncode(axis) + '=' + rawUrlEncode(combination[axis]);
        }).join('|');
    }

    function variantAxes(matrix) {
        var seenAxes = {};
        return Array.prototype.map.call(
            matrix.querySelectorAll('[data-product-variant-axis]'),
            function (row) {
                var codeInput = row.querySelector('[data-variant-axis-code]');
                var optionInput = row.querySelector('[data-variant-axis-options]');
                var code = String(codeInput ? codeInput.value : '').trim().toLowerCase();
                if (!/^[a-z][a-z0-9_]{0,63}$/.test(code)) {
                    throw new Error('规格轴代码必须以字母开头，只能包含小写字母、数字和下划线');
                }
                if (seenAxes[code]) {
                    throw new Error('规格轴代码不能重复：' + code);
                }
                seenAxes[code] = true;
                var seenOptions = {};
                var options = String(optionInput ? optionInput.value : '').split(/[\n,]+/)
                    .map(function (value) {
                        return value.trim();
                    })
                    .filter(function (value) {
                        return value !== '';
                    })
                    .map(function (value) {
                        var identity = value.toLowerCase();
                        if (value.length > 128 || seenOptions[identity]) {
                            throw new Error('规格值不能为空、重复或超过 128 个字符：' + value);
                        }
                        seenOptions[identity] = true;
                        return {value: value, label: value};
                    });
                if (options.length === 0) {
                    throw new Error('每个规格轴至少需要一个规格值');
                }
                return {
                    code: code,
                    label: code,
                    options: options
                };
            }
        );
    }

    function buildVariantCombinations(axes) {
        if (!Array.isArray(axes) || axes.length === 0) {
            throw new Error('多规格商品至少需要一个规格轴');
        }
        var combinations = [{}];
        axes.forEach(function (axis) {
            var next = [];
            combinations.forEach(function (combination) {
                axis.options.forEach(function (option) {
                    var candidate = Object.assign({}, combination);
                    candidate[axis.code] = String(option.value);
                    next.push(candidate);
                    if (next.length > 10000) {
                        throw new Error('规格组合不能超过 10000 个');
                    }
                });
            });
            combinations = next;
        });
        return combinations.map(function (combination) {
            return {
                combination: combination,
                combination_key: variantCombinationKey(combination)
            };
        });
    }

    function fallbackVariantPart(value) {
        var hash = 2166136261;
        String(value).split('').forEach(function (character) {
            hash ^= character.charCodeAt(0);
            hash = Math.imul(hash, 16777619);
        });
        return 'V' + (hash >>> 0).toString(16).toUpperCase().padStart(8, '0');
    }

    function generatedVariantSku(prefix, combination, axes) {
        var parts = [prefix];
        axes.forEach(function (axis) {
            var value = String(combination[axis.code] || '');
            var part = value.toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            parts.push(part || fallbackVariantPart(value));
        });
        var sku = parts.join('-');
        if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(sku)) {
            throw new Error('生成的 SKU 不合法或超过 128 个字符，请缩短 SKU 前缀或规格值');
        }
        return sku;
    }

    function readVariantRow(row) {
        var combination = {};
        try {
            combination = JSON.parse(row.getAttribute('data-combination') || '{}');
        } catch (error) {
            throw new Error('规格组合数据损坏，请重新生成矩阵');
        }
        var skuInput = row.querySelector('[data-variant-sku]');
        var priceInput = row.querySelector('[data-variant-price]');
        var priceRaw = String(priceInput ? priceInput.value : '').trim();
        var value = {
            combination: combination,
            combination_key: variantCombinationKey(combination),
            sku: String(skuInput ? skuInput.value : '').trim(),
            global_offer_uuid: String(row.getAttribute('data-global-offer-uuid') || ''),
            offer_version: parseInt(row.getAttribute('data-offer-version') || '0', 10) || 0,
            identity_version: parseInt(row.getAttribute('data-identity-version') || '0', 10) || 0,
            status: String(row.getAttribute('data-existing-status') || 'new'),
            scope_state: priceRaw === '' ? 'cleared' : 'explicit'
        };
        if (priceRaw !== '') {
            value.amount_minor = parseInt(priceRaw, 10);
            if (!Number.isInteger(value.amount_minor) || value.amount_minor < 0) {
                throw new Error('基础价必须是大于或等于零的整数');
            }
        }
        return value;
    }

    function variantRowsByKey(matrix) {
        var result = {};
        var snapshot = state.snapshot && state.snapshot.offer_matrix
            ? state.snapshot.offer_matrix
            : {};
        (snapshot.rows || []).forEach(function (row) {
            if (row && row.combination_key) {
                result[String(row.combination_key)] = Object.assign({}, row);
            }
        });
        matrix.querySelectorAll('[data-variant-offer-row]').forEach(function (row) {
            var value = readVariantRow(row);
            result[value.combination_key] = value;
        });
        return result;
    }

    function appendVariantCell(row, child) {
        var cell = document.createElement('td');
        if (typeof child === 'string') {
            cell.textContent = child;
        } else {
            cell.appendChild(child);
        }
        row.appendChild(cell);
    }

    function renderVariantImpact(matrix, desiredKeys) {
        var target = matrix.querySelector('[data-product-variant-impact]');
        if (!target) {
            return;
        }
        target.replaceChildren();
        var snapshot = state.snapshot && state.snapshot.offer_matrix
            ? state.snapshot.offer_matrix
            : {};
        var removed = (snapshot.rows || []).filter(function (row) {
            return row && row.combination_key
                && !desiredKeys[String(row.combination_key)]
                && ['disabled', 'archived'].indexOf(String(row.status || '')) === -1;
        });
        if (removed.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'w-text';
            empty.setAttribute('data-tone', 'muted');
            empty.textContent = '当前变更不会停用已有 Offer。';
            target.appendChild(empty);
            return;
        }
        var notice = document.createElement('div');
        notice.className = 'w-alert';
        notice.setAttribute('data-tone', 'warning');
        notice.textContent = '保存后将停用 ' + removed.length + ' 个不再属于矩阵的 Offer，SKU 仍会永久保留。';
        target.appendChild(notice);
        var list = document.createElement('ul');
        removed.forEach(function (row) {
            var item = document.createElement('li');
            item.textContent = String(row.sku || '') + ' · ' + String(row.combination_key || '');
            list.appendChild(item);
        });
        target.appendChild(list);
    }

    function renderVariantRows(matrix, combinations, axes, prefix) {
        var body = matrix.querySelector('[data-product-variant-rows]');
        if (!body) {
            return;
        }
        var existing = variantRowsByKey(matrix);
        var desiredKeys = {};
        body.replaceChildren();
        combinations.forEach(function (generated) {
            var key = generated.combination_key;
            var value = existing[key] || {};
            desiredKeys[key] = true;
            var row = document.createElement('tr');
            row.setAttribute('data-variant-offer-row', '');
            row.setAttribute('data-combination', JSON.stringify(generated.combination));
            row.setAttribute('data-combination-key', key);
            row.setAttribute('data-global-offer-uuid', String(value.global_offer_uuid || ''));
            row.setAttribute('data-offer-version', String(value.offer_version || 0));
            row.setAttribute('data-identity-version', String(value.identity_version || 0));
            row.setAttribute('data-existing-status', String(value.status || 'new'));

            appendVariantCell(row, Object.keys(generated.combination).map(function (axis) {
                return axis + '=' + generated.combination[axis];
            }).join(' / '));

            var sku = document.createElement('input');
            sku.className = 'w-input';
            sku.setAttribute('data-variant-sku', '');
            sku.required = true;
            sku.value = String(value.sku || generatedVariantSku(prefix, generated.combination, axes));
            appendVariantCell(row, sku);

            var status = document.createElement('span');
            status.className = 'w-status';
            status.setAttribute('data-status', String(value.status || 'draft'));
            status.textContent = value.global_offer_uuid ? String(value.status || 'draft') : 'new';
            appendVariantCell(row, status);

            var price = document.createElement('input');
            price.className = 'w-input';
            price.type = 'number';
            price.min = '0';
            price.step = '1';
            price.setAttribute('data-variant-price', '');
            if (value.global_offer_uuid) {
                price.setAttribute('data-offer-price', String(value.global_offer_uuid));
            }
            price.value = value.scope_state === 'explicit'
                && value.amount_minor !== undefined
                && value.amount_minor !== null
                ? String(value.amount_minor)
                : '';
            price.placeholder = '未配置';
            appendVariantCell(row, price);
            body.appendChild(row);
        });
        renderVariantImpact(matrix, desiredKeys);
        var status = matrix.querySelector('[data-product-variant-status]');
        if (status) {
            status.textContent = '已生成 ' + combinations.length + ' 个可购买 Offer 组合。';
        }
    }

    function collectOfferMatrix() {
        var matrix = root.querySelector('[data-product-offer-matrix]');
        if (!matrix || matrix.getAttribute('data-can-edit-structure') !== '1') {
            return null;
        }
        var axes = variantAxes(matrix);
        var prefixInput = matrix.querySelector('[data-product-variant-sku-prefix]');
        var prefix = String(prefixInput ? prefixInput.value : '').trim();
        if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(prefix)) {
            throw new Error('SKU 前缀必须由字母、数字、点、下划线或横线组成');
        }
        var expected = buildVariantCombinations(axes);
        var expectedByKey = {};
        expected.forEach(function (row) {
            expectedByKey[row.combination_key] = row;
        });
        var rowsByKey = {};
        matrix.querySelectorAll('[data-variant-offer-row]').forEach(function (row) {
            var value = readVariantRow(row);
            if (expectedByKey[value.combination_key]) {
                rowsByKey[value.combination_key] = value;
            }
        });
        var seenSkus = {};
        var rows = expected.map(function (generated) {
            var row = rowsByKey[generated.combination_key];
            if (!row) {
                throw new Error('规格轴已变化，请先点击“重新生成矩阵”');
            }
            if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(row.sku)) {
                throw new Error('每个 Offer 都需要合法且不超过 128 个字符的 SKU');
            }
            var skuIdentity = row.sku.toLowerCase();
            if (seenSkus[skuIdentity]) {
                throw new Error('Offer SKU 不能重复：' + row.sku);
            }
            seenSkus[skuIdentity] = true;
            row.combination = generated.combination;
            row.combination_key = generated.combination_key;
            if (!row.global_offer_uuid) {
                delete row.global_offer_uuid;
                delete row.offer_version;
                delete row.identity_version;
                delete row.status;
            }
            return row;
        });
        return {
            axes: axes,
            sku_prefix: prefix,
            currency: String(document.getElementById('product-edit-currency').value || 'CNY')
                .trim()
                .toUpperCase(),
            rows: rows
        };
    }

    function createVariantAxisEditor(matrix) {
        var row = document.createElement('div');
        row.className = 'w-product-variant__axis';
        row.setAttribute('data-product-variant-axis', '');

        var code = document.createElement('input');
        code.className = 'w-input';
        code.placeholder = '例如 color';
        code.setAttribute('data-variant-axis-code', '');
        row.appendChild(code);

        var options = document.createElement('input');
        options.className = 'w-input';
        options.placeholder = '例如 red, blue, green';
        options.setAttribute('data-variant-axis-options', '');
        row.appendChild(options);

        var remove = document.createElement('button');
        remove.className = 'w-button';
        remove.type = 'button';
        remove.setAttribute('data-product-variant-axis-remove', '');
        remove.textContent = '移除';
        row.appendChild(remove);
        return row;
    }

    function initializeVariantMatrix() {
        var matrix = root.querySelector('[data-product-offer-matrix]');
        if (!matrix || matrix.getAttribute('data-can-edit-structure') !== '1') {
            return;
        }
        var axesRoot = matrix.querySelector('[data-product-variant-axes]');
        var add = matrix.querySelector('[data-product-variant-axis-add]');
        var generate = matrix.querySelector('[data-product-variant-generate]');
        if (add && axesRoot) {
            add.addEventListener('click', function () {
                axesRoot.appendChild(createVariantAxisEditor(matrix));
            });
        }
        if (axesRoot) {
            axesRoot.addEventListener('click', function (event) {
                var remove = event.target.closest('[data-product-variant-axis-remove]');
                if (remove) {
                    remove.closest('[data-product-variant-axis]').remove();
                }
            });
        }
        if (generate) {
            generate.addEventListener('click', function () {
                try {
                    var axes = variantAxes(matrix);
                    var prefixInput = matrix.querySelector('[data-product-variant-sku-prefix]');
                    var prefix = String(prefixInput ? prefixInput.value : '').trim();
                    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(prefix)) {
                        throw new Error('请填写合法 SKU 前缀');
                    }
                    renderVariantRows(matrix, buildVariantCombinations(axes), axes, prefix);
                } catch (error) {
                    notify('error', messageFrom(error, '规格矩阵生成失败'));
                }
            });
        }
        try {
            var initialAxes = variantAxes(matrix);
            var desired = {};
            buildVariantCombinations(initialAxes).forEach(function (row) {
                desired[row.combination_key] = true;
            });
            renderVariantImpact(matrix, desired);
        } catch (error) {
            var status = matrix.querySelector('[data-product-variant-status]');
            if (status) {
                status.textContent = messageFrom(error, '请完善规格轴');
            }
        }
    }

    function collectCategoryAssignments() {
        var container = root.querySelector('[data-product-category-assignments]');
        if (!container) {
            return [];
        }
        return Array.prototype.map.call(
            container.querySelectorAll('[data-product-category-row]'),
            function (row, index) {
                var checkbox = row.querySelector('[data-product-category-id]');
                if (!checkbox || !checkbox.checked) {
                    return null;
                }
                var categoryId = parseInt(checkbox.getAttribute('data-product-category-id') || '0', 10);
                var positionInput = row.querySelector('[data-product-category-position]');
                var position = parseInt(positionInput ? positionInput.value : String(index), 10);
                if (!Number.isInteger(categoryId) || categoryId < 1
                    || !Number.isInteger(position) || position < 0
                ) {
                    throw new Error('分类与排序数据无效');
                }
                return {
                    category_id: categoryId,
                    scope_state: 'explicit',
                    selected: true,
                    position: position
                };
            }
        ).filter(Boolean);
    }

    function collectStoreCategoryOverrides() {
        var container = root.querySelector('[data-product-store-category-overrides]');
        if (!container) {
            return [];
        }
        return Array.prototype.map.call(
            container.querySelectorAll('[data-product-store-category-row]'),
            function (row) {
                var stateInput = row.querySelector('[data-product-category-override-state]');
                var scopeState = String(stateInput ? stateInput.value : 'inherit');
                if (scopeState === 'inherit') {
                    return null;
                }
                var storeId = parseInt(row.getAttribute('data-store-id') || '0', 10);
                var categoryId = parseInt(row.getAttribute('data-category-id') || '0', 10);
                var positionInput = row.querySelector('[data-product-category-override-position]');
                var position = parseInt(positionInput ? positionInput.value : '0', 10);
                if (storeId < 1 || categoryId < 1 || !Number.isInteger(position) || position < 0) {
                    throw new Error('Store 分类覆盖数据无效');
                }
                return {
                    store_id: storeId,
                    category_id: categoryId,
                    scope_state: 'explicit',
                    selected: scopeState === 'include',
                    position: position
                };
            }
        ).filter(Boolean);
    }

    function collectMediaAssignments() {
        var container = root.querySelector('[data-product-media-assignments]');
        if (!container) {
            return [];
        }
        var seen = {};
        return Array.prototype.map.call(
            container.querySelectorAll('[data-product-media-row]'),
            function (row, index) {
                var assetId = String(row.getAttribute('data-asset-id') || '').trim().toLowerCase();
                if (!/^[a-f0-9-]{36}$/.test(assetId) || seen[assetId]) {
                    throw new Error('媒体资源身份无效或重复');
                }
                seen[assetId] = true;
                var roleInput = row.querySelector('[data-product-media-role]');
                var role = String(roleInput ? roleInput.value : 'gallery');
                var positionInput = row.querySelector('[data-product-media-position]');
                var position = parseInt(positionInput ? positionInput.value : String(index), 10);
                if ((role !== 'main' && role !== 'gallery')
                    || !Number.isInteger(position) || position < 0
                ) {
                    throw new Error('媒体角色或排序无效');
                }
                return {
                    asset_id: assetId,
                    role: role,
                    hidden: false,
                    scope_state: 'explicit',
                    position: position
                };
            }
        );
    }

    function collectStoreMediaOverrides() {
        var container = root.querySelector('[data-product-store-media-overrides]');
        if (!container) {
            return [];
        }
        return Array.prototype.map.call(
            container.querySelectorAll('[data-product-store-media-row]'),
            function (row) {
                var stateInput = row.querySelector('[data-product-media-override-state]');
                var scopeState = String(stateInput ? stateInput.value : 'inherit');
                if (scopeState === 'inherit') {
                    return null;
                }
                var storeId = parseInt(row.getAttribute('data-store-id') || '0', 10);
                var assetId = String(row.getAttribute('data-asset-id') || '').trim().toLowerCase();
                var roleInput = row.querySelector('[data-product-media-override-role]');
                var role = String(roleInput ? roleInput.value : 'gallery');
                var positionInput = row.querySelector('[data-product-media-override-position]');
                var position = parseInt(positionInput ? positionInput.value : '0', 10);
                if (storeId < 1 || !/^[a-f0-9-]{36}$/.test(assetId)
                    || (role !== 'main' && role !== 'gallery')
                    || !Number.isInteger(position) || position < 0
                ) {
                    throw new Error('Store 媒体覆盖数据无效');
                }
                return {
                    store_id: storeId,
                    asset_id: assetId,
                    scope_state: 'explicit',
                    hidden: scopeState === 'hide',
                    role: role,
                    position: position
                };
            }
        ).filter(Boolean);
    }

    function safePickerPreview(raw) {
        var value = String(raw || '').trim();
        if (value === '') {
            return '';
        }
        if (/^data:image\//i.test(value) || /^blob:/i.test(value)) {
            return value;
        }
        try {
            var url = new URL(value, window.location.href);
            return url.origin === window.location.origin ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function appendProductMediaRow(file) {
        var body = root.querySelector('[data-product-media-rows]');
        if (!body) {
            return;
        }
        var assetId = String(file.asset_id || '').trim().toLowerCase();
        var mimeType = String(file.mime || '').trim().toLowerCase();
        if (!/^[a-f0-9-]{36}$/.test(assetId) || mimeType.indexOf('image/') !== 0) {
            throw new Error('媒体库返回的图片资源无效');
        }
        if (body.querySelector('[data-asset-id="' + assetId + '"]')) {
            notify('warning', '该图片已经在商品媒体中');
            return;
        }

        var row = document.createElement('tr');
        row.setAttribute('data-product-media-row', '');
        row.setAttribute('data-asset-id', assetId);
        row.setAttribute('data-mime-type', mimeType);

        var assetCell = document.createElement('td');
        var preview = safePickerPreview(file.editor_preview_url);
        if (preview !== '') {
            var image = document.createElement('img');
            image.className = 'w-product-media-thumb';
            image.src = preview;
            image.alt = String(file.display_name || '');
            assetCell.appendChild(image);
        }
        var name = document.createElement('strong');
        name.className = 'w-product-asset-name';
        name.textContent = String(file.display_name || assetId);
        assetCell.appendChild(name);
        var meta = document.createElement('small');
        meta.textContent = mimeType + ' · ' + assetId;
        assetCell.appendChild(meta);
        row.appendChild(assetCell);

        var roleCell = document.createElement('td');
        var role = document.createElement('select');
        role.className = 'w-select';
        role.setAttribute('data-product-media-role', '');
        [['gallery', '图集'], ['main', '主图']].forEach(function (optionData) {
            var option = document.createElement('option');
            option.value = optionData[0];
            option.textContent = optionData[1];
            role.appendChild(option);
        });
        roleCell.appendChild(role);
        row.appendChild(roleCell);

        var positionCell = document.createElement('td');
        var position = document.createElement('input');
        position.className = 'w-input';
        position.type = 'number';
        position.min = '0';
        position.step = '1';
        position.value = String(body.querySelectorAll('[data-product-media-row]').length);
        position.setAttribute('data-product-media-position', '');
        positionCell.appendChild(position);
        row.appendChild(positionCell);

        var actionCell = document.createElement('td');
        var remove = document.createElement('button');
        remove.className = 'w-button';
        remove.type = 'button';
        remove.setAttribute('data-tone', 'danger');
        remove.setAttribute('data-product-media-remove', '');
        remove.textContent = '移除';
        actionCell.appendChild(remove);
        row.appendChild(actionCell);
        body.appendChild(row);
        updateMediaEmptyState();
    }

    function updateMediaEmptyState() {
        var empty = root.querySelector('[data-product-media-empty]');
        var count = root.querySelectorAll('[data-product-media-row]').length;
        if (empty) {
            empty.hidden = count > 0;
        }
    }

    function setMediaOverrideState(row) {
        var stateInput = row.querySelector('[data-product-media-override-state]');
        var inherited = !stateInput || stateInput.value === 'inherit';
        row.querySelectorAll('[data-product-media-override-role], [data-product-media-override-position]')
            .forEach(function (input) {
                input.disabled = inherited;
            });
        row.setAttribute('data-scope-state', inherited ? 'inherit' : 'explicit');
    }

    function initializeTaxonomyMedia() {
        var categorySearch = root.querySelector('[data-product-category-search]');
        if (categorySearch) {
            categorySearch.addEventListener('input', function () {
                var needle = String(categorySearch.value || '').trim().toLowerCase();
                root.querySelectorAll('[data-product-category-row]').forEach(function (row) {
                    row.hidden = needle !== ''
                        && String(row.getAttribute('data-search-text') || '').indexOf(needle) === -1;
                });
            });
        }

        root.querySelectorAll('[data-product-store-media-row]').forEach(function (row) {
            var stateInput = row.querySelector('[data-product-media-override-state]');
            if (stateInput) {
                stateInput.addEventListener('change', function () {
                    setMediaOverrideState(row);
                });
            }
            setMediaOverrideState(row);
        });

        var mediaBody = root.querySelector('[data-product-media-rows]');
        if (mediaBody) {
            mediaBody.addEventListener('click', function (event) {
                var target = event.target instanceof Element
                    ? event.target.closest('[data-product-media-remove]')
                    : null;
                if (!target) {
                    return;
                }
                var row = target.closest('[data-product-media-row]');
                if (row) {
                    row.remove();
                    updateMediaEmptyState();
                }
            });
        }

        var dialog = root.querySelector('[data-product-media-picker-dialog]');
        var frame = root.querySelector('[data-product-media-picker-frame]');
        var open = root.querySelector('[data-product-media-picker-open]');
        var close = root.querySelector('[data-product-media-picker-close]');

        function closePicker() {
            if (!dialog) {
                return;
            }
            if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
                && typeof window.Weline.UI.dialog.close === 'function'
            ) {
                window.Weline.UI.dialog.close(dialog, 'product-media');
            } else if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            }
        }

        if (open && dialog && frame) {
            open.addEventListener('click', function () {
                try {
                    var pickerUrl = new URL(frame.getAttribute('data-src') || '', window.location.href);
                    if (pickerUrl.origin !== window.location.origin) {
                        throw new Error('媒体选择器必须与后台同源');
                    }
                    if (frame.src !== pickerUrl.href) {
                        frame.src = pickerUrl.href;
                    }
                    if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
                        && typeof window.Weline.UI.dialog.open === 'function'
                    ) {
                        window.Weline.UI.dialog.open(dialog, {trigger: 'product-media'});
                    } else if (typeof dialog.showModal === 'function' && !dialog.open) {
                        dialog.showModal();
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '媒体选择器不可用'));
                }
            });
        }
        if (close) {
            close.addEventListener('click', closePicker);
        }

        window.addEventListener('message', function (event) {
            if (!frame || event.source !== frame.contentWindow
                || event.origin !== window.location.origin
                || !event.data || typeof event.data !== 'object'
                || String(event.data.target || '') !== 'product-media-picker'
            ) {
                return;
            }
            if (event.data.type === 'weline-media-manager-cancel') {
                closePicker();
                return;
            }
            if (event.data.type !== 'weline-media-manager-select'
                || !Array.isArray(event.data.files)
            ) {
                return;
            }
            try {
                event.data.files.forEach(appendProductMediaRow);
                closePicker();
            } catch (error) {
                notify('error', messageFrom(error, '添加商品图片失败'));
            }
        });
        updateMediaEmptyState();
    }

    function collectInventoryRows(rootNode) {
        var selectedStores = {};
        rootNode.querySelectorAll('input[name="store_ids[]"]:checked').forEach(function (input) {
            selectedStores[parseInt(input.value, 10) || 0] = true;
        });
        return Array.prototype.reduce.call(
            rootNode.querySelectorAll('[data-inventory-row]'),
            function (rows, row) {
                var storeId = parseInt(row.getAttribute('data-store-id'), 10) || 0;
                var offerId = parseInt(row.getAttribute('data-offer-id'), 10) || 0;
                var input = row.querySelector('[data-inventory-on-hand]');
                if (!selectedStores[storeId] || !input || input.disabled) {
                    return rows;
                }
                var raw = String(input.value || '').trim();
                var onHandMinor = Number(raw);
                if (!/^\d+$/.test(raw)
                    || !Number.isSafeInteger(onHandMinor)
                    || onHandMinor < 0
                ) {
                    throw new Error('现货数量必须是大于或等于零的整数');
                }
                rows.push({
                    store_id: storeId,
                    offer_id: offerId,
                    global_offer_uuid: row.getAttribute('data-global-offer-uuid') || '',
                    on_hand_minor: onHandMinor
                });
                return rows;
            },
            []
        );
    }

    function editorPayload() {
        var form = document.getElementById('product-editor-form');
        var currency = String(document.getElementById('product-edit-currency').value || 'CNY')
            .trim()
            .toUpperCase();
        var payload = {
            name: String(document.getElementById('product-edit-name').value || '').trim(),
            locale: String(document.getElementById('product-edit-locale').value || '').trim(),
            currency: currency,
            attributes: collectVisualAttributes(),
            category_assignments: collectCategoryAssignments(),
            media_assignments: collectMediaAssignments(),
            store_category_overrides: collectStoreCategoryOverrides(),
            store_media_overrides: collectStoreMediaOverrides(),
            store_ids: selectedStoreIds(form),
            inventory: collectInventoryRows(root),
            type_configuration: collectProviderConfiguration(root),
            offer_matrix: collectOfferMatrix()
        };
        var priceSelector = payload.offer_matrix
            ? '[data-offer-price]:not([data-variant-price])'
            : '[data-offer-price]';
        payload.prices = Array.prototype.map.call(
            root.querySelectorAll(priceSelector),
            function (input) {
                var raw = String(input.value || '').trim();
                var row = {
                    global_offer_uuid: input.getAttribute('data-offer-price') || '',
                    store_id: 0,
                    currency: currency
                };
                if (raw === '') {
                    row.scope_state = 'cleared';
                } else {
                    row.scope_state = 'explicit';
                    row.amount_minor = parseInt(raw, 10);
                    if (!Number.isInteger(row.amount_minor) || row.amount_minor < 0) {
                        throw new Error('基础价必须是大于或等于零的整数');
                    }
                }
                return row;
            }
        );
        if (payload.offer_matrix === null) {
            delete payload.offer_matrix;
        }
        return payload;
    }


    function attributeRowKey(row) {
        return [
            String(row.entity_type || 'product'),
            String(parseInt(row.entity_id || 0, 10) || 0),
            String(parseInt(row.store_id || 0, 10) || 0),
            String(row.locale || ''),
            String(row.attribute_code || '')
        ].join('|');
    }

    function fieldAttributeKey(field) {
        return [
            String(field.getAttribute('data-entity-type') || 'product'),
            String(parseInt(field.getAttribute('data-entity-id') || '0', 10) || 0),
            String(parseInt(field.getAttribute('data-store-id') || '0', 10) || 0),
            String(field.getAttribute('data-locale') || ''),
            String(field.getAttribute('data-attribute-code') || '')
        ].join('|');
    }

    function visualAttributeValue(field) {
        var input = field.querySelector('[data-eav-input]');
        var valueType = String(field.getAttribute('data-value-type') || 'string');
        if (!input) {
            return null;
        }
        if (valueType === 'boolean') {
            return Boolean(input.checked);
        }
        if (valueType === 'multiselect') {
            return Array.prototype.map.call(input.options, function (option) {
                return option.selected ? String(option.value) : null;
            }).filter(function (value) {
                return value !== null;
            });
        }
        var raw = String(input.value || '');
        if (valueType === 'number') {
            return raw.trim() === '' ? null : raw.trim();
        }
        if (valueType === 'json') {
            if (raw.trim() === '') {
                return null;
            }
            try {
                return JSON.parse(raw);
            } catch (error) {
                throw new Error('属性 ' + field.getAttribute('data-attribute-code') + ' 的 JSON 格式不正确');
            }
        }
        return raw;
    }

    function visualAttributeRow(field, existingByKey) {
        var key = fieldAttributeKey(field);
        var existing = existingByKey[key] || null;
        var state = String(field.querySelector('[data-eav-state]').value || 'explicit');
        if (state === 'inherit' && !existing && field.getAttribute('data-existing-row') !== '1') {
            return null;
        }
        var row = Object.assign({}, existing || {}, {
            entity_type: String(field.getAttribute('data-entity-type') || 'product'),
            entity_id: parseInt(field.getAttribute('data-entity-id') || '0', 10) || 0,
            store_id: parseInt(field.getAttribute('data-store-id') || '0', 10) || 0,
            locale: String(field.getAttribute('data-locale') || ''),
            attribute_code: String(field.getAttribute('data-attribute-code') || ''),
            value_type: String(field.getAttribute('data-value-type') || 'string'),
            scope_state: state,
            cleared: state === 'cleared'
        });
        row.value = state === 'explicit' ? visualAttributeValue(field) : null;
        return row;
    }

    function mergeAdvancedAttributeRows(advancedRows, fieldNodes) {
        var existingByKey = {};
        var visualKeys = {};
        advancedRows.forEach(function (row) {
            existingByKey[attributeRowKey(row)] = row;
        });
        var visualRows = Array.prototype.map.call(fieldNodes, function (field) {
            var row = visualAttributeRow(field, existingByKey);
            if (row) {
                visualKeys[attributeRowKey(row)] = true;
            }
            return row;
        }).filter(Boolean);
        return advancedRows.filter(function (row) {
            return !visualKeys[attributeRowKey(row)];
        }).concat(visualRows);
    }

    function collectVisualAttributes() {
        var advancedInput = document.getElementById('product-edit-attributes');
        var advancedRows = normalizeAttributeRows(parseJsonField(advancedInput, 'array', '商品属性'));
        var root = document.getElementById('product-eav-editor');
        if (!root) {
            return advancedRows;
        }
        var activePanel = root.querySelector('[data-eav-set-panel]:not([hidden])');
        var fields = activePanel ? activePanel.querySelectorAll('[data-eav-field]') : [];
        var merged = normalizeAttributeRows(mergeAdvancedAttributeRows(advancedRows, fields));
        if (advancedInput) {
            advancedInput.value = JSON.stringify(merged, null, 2);
        }
        return merged;
    }

    function updateEavFieldState(field) {
        var state = field.querySelector('[data-eav-state]');
        var input = field.querySelector('[data-eav-input]');
        var explicit = !state || state.value === 'explicit';
        if (input) {
            input.disabled = !explicit;
            input.setAttribute('aria-disabled', explicit ? 'false' : 'true');
        }
        field.setAttribute('data-scope-state', state ? state.value : 'explicit');
    }

    function initializeEavEditor() {
        var root = document.getElementById('product-eav-editor');
        if (!root) {
            return;
        }
        var selector = root.querySelector('[data-eav-set-selector]');
        var panels = root.querySelectorAll('[data-eav-set-panel]');
        var showSelectedSet = function () {
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = Boolean(selector) && panel.getAttribute('data-eav-set-panel') !== selector.value;
            });
        };
        if (selector) {
            selector.addEventListener('change', showSelectedSet);
            showSelectedSet();
        }
        Array.prototype.forEach.call(root.querySelectorAll('[data-eav-field]'), function (field) {
            var state = field.querySelector('[data-eav-state]');
            if (state) {
                state.addEventListener('change', function () {
                    updateEavFieldState(field);
                });
            }
            updateEavFieldState(field);
        });
    }

    function appendDiagnosticAlert(parent, item, tone) {
        var alert = document.createElement('div');
        alert.className = 'w-alert';
        alert.setAttribute('data-tone', tone);
        var code = document.createElement('strong');
        code.textContent = String(item.code || '');
        alert.appendChild(code);
        alert.appendChild(document.createTextNode(' ' + String(item.message || '')));
        if (item.path) {
            var path = document.createElement('small');
            path.textContent = String(item.path);
            alert.appendChild(path);
        }
        parent.appendChild(alert);
    }

    function renderDiagnosticGroup(group) {
        var section = document.createElement('section');
        section.className = 'w-product-diagnostic-group';
        section.setAttribute('data-diagnostic-group', '');
        section.setAttribute('data-severity', String(group.severity || 'ready'));
        var header = document.createElement('header');
        header.className = 'w-product-diagnostic-group__header';
        var title = document.createElement('h3');
        title.textContent = [group.store_label, group.locale, group.currency, group.offer_label]
            .filter(Boolean).join(' · ') || '商品级诊断';
        var summary = document.createElement('span');
        summary.textContent = String((group.errors || []).length)
            + ' 项阻断 · ' + String((group.warnings || []).length) + ' 项提醒';
        header.appendChild(title);
        header.appendChild(summary);
        section.appendChild(header);
        var issues = document.createElement('div');
        issues.className = 'w-product-diagnostic-group__issues';
        (group.errors || []).forEach(function (item) {
            appendDiagnosticAlert(issues, item, 'danger');
        });
        (group.warnings || []).forEach(function (item) {
            appendDiagnosticAlert(issues, item, 'warning');
        });
        section.appendChild(issues);
        return section;
    }

    function renderDiagnostics(diagnostics) {
        var target = document.getElementById('product-diagnostics');
        if (!target) {
            return;
        }
        target.replaceChildren();
        if (Array.isArray(diagnostics.groups) && diagnostics.groups.length > 0) {
            diagnostics.groups.forEach(function (group) {
                target.appendChild(renderDiagnosticGroup(group || {}));
            });
        } else {
            (diagnostics.errors || []).forEach(function (item) {
                appendDiagnosticAlert(target, item, 'danger');
            });
            (diagnostics.warnings || []).forEach(function (item) {
                appendDiagnosticAlert(target, item, 'warning');
            });
            if (diagnostics.valid) {
                appendDiagnosticAlert(target, {
                    code: 'ready',
                    message: '当前数据通过 Provider 发布校验。'
                }, 'success');
            }
        }
        var diagnosticsTab = root.querySelector('[data-product-tab="diagnostics"]');
        if (diagnosticsTab) {
            diagnosticsTab.click();
        }
    }

    function initializeBusinessReadOnly() {
        if (root.getAttribute('data-edit-business') === '1') {
            return;
        }
        root.setAttribute('aria-readonly', 'true');
        root.querySelectorAll('#product-editor-form input, #product-editor-form textarea, '
            + '#product-editor-form select, #product-editor-form button').forEach(function (control) {
            var tag = String(control.tagName || '').toLowerCase();
            var type = String(control.getAttribute('type') || '').toLowerCase();
            if ((tag === 'input' && ['checkbox', 'radio', 'file', 'submit', 'button'].indexOf(type) === -1)
                || tag === 'textarea'
            ) {
                control.readOnly = true;
                control.setAttribute('aria-readonly', 'true');
                return;
            }
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });
    }

    function initializeGovernance() {
        var websiteSelect = document.getElementById('product-governance-website');
        var transferInput = document.getElementById('product-governance-transfer-uuid');
        var transferOutput = document.getElementById('product-governance-transfer-result');

        root.querySelectorAll('[data-product-governance]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = String(button.getAttribute('data-governance-action') || '');
                var payload = {};
                try {
                    if (action === 'share' || action === 'transfer_initiate') {
                        var targetWebsiteId = parseInt(websiteSelect ? websiteSelect.value : '', 10);
                        var currentWebsiteId = parseInt(state.website_id, 10) || 0;
                        if (!Number.isInteger(targetWebsiteId)
                            || targetWebsiteId < 0
                            || targetWebsiteId === currentWebsiteId
                        ) {
                            throw new Error('请选择与当前站点不同的目标 Website');
                        }
                        payload.target_website_id = targetWebsiteId;
                        if (action === 'share') {
                            payload.allowed = button.getAttribute('data-allowed') !== '0';
                        }
                    } else if (action === 'transfer_confirm') {
                        var transferUuid = String(transferInput ? transferInput.value : '').trim();
                        if (transferUuid === '') {
                            throw new Error('请输入待确认的转让编号');
                        }
                        payload.transfer_uuid = transferUuid;
                    } else {
                        throw new Error('未知的跨站治理操作');
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '请检查跨站治理信息'));
                    return;
                }

                var prompt = action === 'share'
                    ? (payload.allowed
                        ? '允许目标 Website 复制当前商品？'
                        : '撤销后不会删除目标站已有商品，确认撤销后续复制授权？')
                    : (action === 'transfer_initiate'
                        ? '归属转让需要目标 Website 再次确认，确认发起？'
                        : '确认接收后，商品结构管理权将转移到当前 Website，是否继续？');
                if (!window.confirm(prompt)) {
                    return;
                }

                executeCommand(action, payload, button).then(function (result) {
                    if (action === 'transfer_initiate') {
                        var uuid = String(
                            result.data && result.data.transfer_uuid
                                ? result.data.transfer_uuid
                                : ''
                        );
                        if (transferOutput) {
                            transferOutput.textContent = uuid || '转让已发起，请查看审计记录';
                        }
                        if (transferInput && uuid) {
                            transferInput.value = uuid;
                        }
                        notify('success', uuid
                            ? '转让已发起，请将编号交给目标 Website 确认'
                            : '转让已发起');
                        return;
                    }
                    window.location.reload();
                }).catch(function (error) {
                    notify('error', messageFrom(error, '跨站治理操作失败'));
                });
            });
        });
    }

    function initializeEditor() {
        initializeEavEditor();
        initializeVariantMatrix();
        initializeTaxonomyMedia();
        initializeBusinessReadOnly();
        initializeGovernance();
        root.querySelectorAll('[data-product-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var selected = tab.getAttribute('data-product-tab');
                root.querySelectorAll('[data-product-tab]').forEach(function (candidate) {
                    if (candidate === tab) {
                        candidate.setAttribute('aria-current', 'page');
                    } else {
                        candidate.removeAttribute('aria-current');
                    }
                });
                root.querySelectorAll('[data-product-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-product-panel') !== selected;
                });
            });
        });

        var form = document.getElementById('product-editor-form');
        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!form.reportValidity()) {
                    return;
                }
                var payload;
                try {
                    payload = editorPayload();
                } catch (error) {
                    notify('error', messageFrom(error, '请检查编辑内容'));
                    return;
                }
                executeCommand('save', payload, form.querySelector('button[type="submit"]'))
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(function (error) {
                        notify('error', messageFrom(error, '保存商品失败'));
                    });
            });
        }

        root.querySelectorAll('[data-product-command]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.getAttribute('data-product-command') || 'validate';
                var payload = {
                    locale: String(document.getElementById('product-edit-locale').value || '').trim(),
                    currency: String(document.getElementById('product-edit-currency').value || 'CNY').trim().toUpperCase()
                };
                if (action === 'publish' || action === 'disable' || action === 'archive') {
                    var prompt = action === 'archive'
                        ? '归档后运营端不能再恢复为可售状态，确认归档？'
                        : '确认执行当前商品操作？';
                    if (!window.confirm(prompt)) {
                        return;
                    }
                }
                executeCommand(action, payload, button).then(function (result) {
                    if (action === 'validate') {
                        renderDiagnostics((result.data || {}).diagnostics || {});
                        return;
                    }
                    window.location.reload();
                }).catch(function (error) {
                    var result = error && error.result ? error.result : {};
                    var diagnostics = result.data && result.data.diagnostics
                        ? result.data.diagnostics
                        : null;
                    if (diagnostics) {
                        renderDiagnostics(diagnostics);
                    }
                    notify('error', messageFrom(error, '商品操作失败'));
                });
            });
        });
    }

    if (root.getAttribute('data-product-admin') === 'catalog') {
        initializeCatalog();
    }
    if (root.getAttribute('data-product-admin') === 'editor') {
        initializeEditor();
    }
}());
