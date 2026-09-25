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
    var createWizardAdvancing = false;
    var createWizardToggleGuard = false;
    var createMediaHydrating = false;
    var createVariantPreviewTimer = 0;

    function appendCreatePickerIdentity(pickerUrl, kind, field) {
        var helper = window.Weline && window.Weline.MediaIdentityPicker;
        if (!helper || !pickerUrl) {
            return pickerUrl;
        }
        var ambient = helper.ambientFrom(root);
        var code = String(ambient.code || 'draft').trim() || 'draft';
        var codeSelector = String(root.getAttribute('data-media-code-input') || '').trim();
        if (codeSelector) {
            var codeInput = root.querySelector(codeSelector);
            if (codeInput && 'value' in codeInput) {
                var typed = String(codeInput.value || '').trim().replace(/:/g, '_');
                if (typed !== '') {
                    code = typed;
                }
            }
        }
        var identity = helper.buildIdentity({
            root: ambient.root || 'product',
            code: code,
            scope: ambient.scope || null,
            kind: kind || ambient.kind || 'media',
            field: field || ambient.field || kind || 'media',
        });
        if (!identity) {
            return pickerUrl;
        }
        root.__mediaIdentity = identity;
        return helper.appendIdentityParams(pickerUrl, identity);
    }

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
                || Object.prototype.hasOwnProperty.call(current, 'context')
                || Object.prototype.hasOwnProperty.call(current, 'available')
                || Object.prototype.hasOwnProperty.call(current, 'options')
                || Object.prototype.hasOwnProperty.call(current, 'schedules')) {
                break;
            }
            current = current.data;
        }
        return current || {};
    }

    function openProductMediaDialog(dialog, options) {
        if (!(dialog instanceof HTMLDialogElement)) {
            return false;
        }
        var opened = false;
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.open === 'function'
        ) {
            opened = !!window.Weline.UI.dialog.open(dialog, options || {});
        }
        if (!opened && typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
            opened = !!dialog.open;
        }
        return opened;
    }

    function closeProductMediaDialog(dialog, returnValue) {
        if (!(dialog instanceof HTMLDialogElement)) {
            return false;
        }
        var closed = false;
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.close === 'function'
        ) {
            closed = !!window.Weline.UI.dialog.close(dialog, returnValue);
        }
        if (!closed && typeof dialog.close === 'function' && dialog.open) {
            dialog.close(String(returnValue == null ? '' : returnValue));
            closed = !dialog.open;
        }
        return closed;
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

    function confirmTheme(message, options) {
        options = options || {};
        if (window.Weline && window.Weline.UI && window.Weline.UI.dialog
            && typeof window.Weline.UI.dialog.confirm === 'function'
        ) {
            return Promise.resolve(window.Weline.UI.dialog.confirm(String(message || ''), options))
                .then(Boolean);
        }
        var fallback = String(options.title || '')
            + (options.title && message ? '\n\n' : '')
            + String(message || '');
        return Promise.resolve(window.confirm(fallback));
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
            return Number.isInteger(value) && value >= 0;
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

    var PRODUCT_CREATE_DRAFT_SCOPE = 'product_create_draft';
    var PRODUCT_CREATE_DRAFT_STORAGE_KEY = 'weline_scope_data.v2';
    var PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY = 'weline.product.create.wizard_step';

    function readCreateWizardStep() {
        try {
            var raw = String(window.localStorage.getItem(PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY) || '').trim();
            if (raw !== '' && /^[a-z][a-z0-9_-]*$/i.test(raw)) {
                return raw;
            }
        } catch (_error) {
        }
        return '';
    }

    function writeCreateWizardStep(stepId) {
        var normalized = String(stepId || '').trim();
        try {
            if (normalized === '') {
                window.localStorage.removeItem(PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY);
                return;
            }
            window.localStorage.setItem(PRODUCT_CREATE_WIZARD_STEP_STORAGE_KEY, normalized);
        } catch (_error) {
        }
    }

    function clearCreateWizardStep() {
        writeCreateWizardStep('');
    }

    function rememberCreateWizardStep(stepId) {
        var normalized = String(stepId || '').trim();
        if (normalized === '') {
            return;
        }
        var known = getCreateWizardSteps().some(function (step) {
            return step.id === normalized;
        });
        if (!known) {
            // Step may be temporarily hidden (e.g. before attribute set); still remember.
            var all = document.querySelectorAll('#product-create-form .w-product-create__step[data-create-step]');
            known = Array.prototype.some.call(all, function (step) {
                return String(step.getAttribute('data-create-step') || '').trim() === normalized;
            });
        }
        if (known) {
            writeCreateWizardStep(normalized);
        }
    }

    function unlockCreateWizardStepsBefore(targetStepId) {
        var target = String(targetStepId || '').trim();
        if (target === '') {
            return;
        }
        var steps = getCreateWizardSteps();
        for (var index = 0; index < steps.length; index += 1) {
            var stepId = steps[index].id;
            if (stepId === target) {
                break;
            }
            var button = getCreateWizardStepContinueButton(stepId);
            if (!button) {
                continue;
            }
            if (requiresCreateStepManualConfirm(stepId)) {
                if (isCreateStepContentReady(stepId)) {
                    button.setAttribute('data-step-confirmed', '1');
                }
                continue;
            }
            if (isCreateStepOptionalContinue(stepId) || isCreateStepContentReady(stepId)) {
                button.setAttribute('data-step-confirmed', '1');
            }
        }
    }

    function forceConfirmPreviousStepsForResume(targetStepId) {
        // Refresh clears data-step-confirmed; reaching a later step means user already passed
        // previous「下一步」gates — restore confirms so resume is not bounced to basics.
        getPreviousCreateWizardStepIds(targetStepId).forEach(function (stepId) {
            var button = getCreateWizardStepContinueButton(stepId);
            if (button) {
                button.setAttribute('data-step-confirmed', '1');
            }
        });
    }

    function resumeCreateWizardStep(options) {
        options = options || {};
        updateCreateWizardVisibility();
        var savedStepId = readCreateWizardStep();
        var steps = getCreateWizardSteps();
        var fallback = steps.length > 0 ? steps[0].id : 'basics';

        if (savedStepId === '') {
            if (fallback !== '') {
                openCreateWizardStep(fallback, {silent: true, remember: false});
            }
            return false;
        }

        var targetElement = getCreateWizardStepElement(savedStepId);
        // Type/set draft may not be hydrated yet — variant-axes etc. stay hidden.
        // Never rewrite remembered step to basics while waiting.
        if (!targetElement || targetElement.hidden) {
            if (fallback !== '' && fallback !== savedStepId) {
                openCreateWizardStep(fallback, {silent: true, remember: false});
            }
            return false;
        }

        unlockCreateWizardStepsBefore(savedStepId);
        forceConfirmPreviousStepsForResume(savedStepId);
        var opened = openCreateWizardStep(savedStepId, {
            silent: options.silent !== false,
            remember: false,
            force: true
        });
        if (opened) {
            rememberCreateWizardStep(savedStepId);
            return true;
        }
        return false;
    }

    function productCreateDraftEndpoint() {
        var mount = document.querySelector('[data-w-component="scope-persistence"][data-w-scope-container="product-create-form"]');
        return mount ? String(mount.getAttribute('data-w-scope-url') || '').trim() : '';
    }

    function clearProductCreateDraftLocal() {
        try {
            var raw = window.localStorage.getItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY) || '{}';
            var store = JSON.parse(raw);
            if (!store || typeof store !== 'object' || Array.isArray(store)) {
                return;
            }
            if (!Object.prototype.hasOwnProperty.call(store, PRODUCT_CREATE_DRAFT_SCOPE)) {
                return;
            }
            delete store[PRODUCT_CREATE_DRAFT_SCOPE];
            window.localStorage.setItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY, JSON.stringify(store));
        } catch (_error) {
        }
    }

    function clearProductCreateDraftRemote() {
        var endpoint = productCreateDraftEndpoint();
        if (endpoint === '' || !window.Weline || !window.Weline.Api || typeof window.Weline.Api.post !== 'function') {
            return Promise.resolve();
        }
        return window.Weline.Api.post(endpoint, {
            scope: PRODUCT_CREATE_DRAFT_SCOPE,
            data: {
                product_type: '',
                name: '',
                sku: '',
                spu: '',
                brand_id: '',
                supplier_id: '',
                supplier_product_url: '',
                supplier_sku: '',
                supplier_currency: '',
                supplier_unit_price: '',
                supplier_moq: '',
                supplier_lead_time_days: '',
                supplier_notes: '',
                barcode: '',
                status: 'draft',
                visibility: 'catalog_search',
                quote_only: '',
                price: '',
                cost: '',
                currency: 'CNY',
                stock: '',
                weight: '',
                length: '',
                width: '',
                height: '',
                shipping_profile_code: '',
                shipping_hazard_class: '',
                short_description: '',
                description: '',
                slug: '',
                meta_name: '',
                meta_description: '',
                meta_keywords: '',
                media_assignments_json: '',
                attribute_set_id: '',
                attribute_set: '',
                attribute_set_label: '',
                create_eav_attributes_json: '',
                create_variant_axes_json: '',
                create_variant_option_images_json: ''
            }
        }).catch(function () {
            return null;
        });
    }

    function readProductCreateDraftData() {
        try {
            var raw = window.localStorage.getItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY) || '{}';
            var store = JSON.parse(raw);
            var entry = store && store[PRODUCT_CREATE_DRAFT_SCOPE];
            if (entry && entry.data && typeof entry.data === 'object' && !Array.isArray(entry.data)) {
                return entry.data;
            }
        } catch (_error) {
        }
        return {};
    }

    function patchProductCreateDraftLocal(partial) {
        if (!partial || typeof partial !== 'object') {
            return;
        }
        try {
            var raw = window.localStorage.getItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY) || '{}';
            var store = JSON.parse(raw);
            if (!store || typeof store !== 'object' || Array.isArray(store)) {
                store = {};
            }
            store[PRODUCT_CREATE_DRAFT_SCOPE] = store[PRODUCT_CREATE_DRAFT_SCOPE] || {data: {}, hasChange: false};
            if (!store[PRODUCT_CREATE_DRAFT_SCOPE].data
                || typeof store[PRODUCT_CREATE_DRAFT_SCOPE].data !== 'object'
                || Array.isArray(store[PRODUCT_CREATE_DRAFT_SCOPE].data)
            ) {
                store[PRODUCT_CREATE_DRAFT_SCOPE].data = {};
            }
            Object.keys(partial).forEach(function (key) {
                store[PRODUCT_CREATE_DRAFT_SCOPE].data[key] = partial[key] == null ? '' : String(partial[key]);
            });
            store[PRODUCT_CREATE_DRAFT_SCOPE].hasChange = true;
            window.localStorage.setItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY, JSON.stringify(store));
        } catch (_error) {
        }
    }

    function isHydrateableFormControl(field) {
        return field instanceof HTMLInputElement
            || field instanceof HTMLTextAreaElement
            || field instanceof HTMLSelectElement;
    }

    function applyFormControlDraftValue(field, next) {
        if (field instanceof HTMLInputElement
            && (field.type === 'checkbox' || field.type === 'radio')
        ) {
            var shouldCheck = next === '1'
                || next === 'true'
                || (next !== '' && next === String(field.value || ''));
            if (field.type === 'checkbox' && (next === '' || next === '0' || next === 'false')) {
                shouldCheck = false;
            }
            if (field.checked === shouldCheck) {
                return false;
            }
            field.checked = shouldCheck;
            return true;
        }
        if (field.value === next) {
            return false;
        }
        field.value = next;
        return true;
    }

    function writeScopedDraftField(field, value, options) {
        options = options || {};
        if (!isHydrateableFormControl(field)) {
            return;
        }
        var next = value == null ? '' : String(value);
        var changed = applyFormControlDraftValue(field, next);
        // Always emit change so scope-persistence (event=change) persists even when server
        // defaults already matched a previous draft value.
        if (changed || options.forceEvent === true) {
            field.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }

    function forceHydrateScopedHiddenFromDraft(fieldId, draftKey) {
        var field = document.getElementById(fieldId);
        if (!isHydrateableFormControl(field)) {
            return;
        }
        var draft = readProductCreateDraftData();
        if (!Object.prototype.hasOwnProperty.call(draft, draftKey)) {
            return;
        }
        var next = draft[draftKey] == null ? '' : String(draft[draftKey]);
        // Server defaults / selected="draft" block scope-persistence empty-only apply;
        // always prefer local draft until create success clears it. HTMLSelectElement included.
        applyFormControlDraftValue(field, next);
    }

    function syncCreateSubmitButtonLabel() {
        var statusInput = document.getElementById('product-create-status');
        var submit = document.querySelector('#product-create-form button.w-product-create__submit[type="submit"]');
        if (!(submit instanceof HTMLButtonElement)) {
            return;
        }
        var status = statusInput ? String(statusInput.value || 'draft').trim() : 'draft';
        var published = status === 'published';
        var draftLabel = String(submit.getAttribute('data-label-draft') || '创建草稿并继续编辑');
        var publishedLabel = String(submit.getAttribute('data-label-published') || '创建并立即发布');
        submit.textContent = published ? publishedLabel : draftLabel;
    }

    function diagnosticsMessage(diagnostics, fallback) {
        if (!diagnostics || typeof diagnostics !== 'object') {
            return fallback;
        }
        var errors = Array.isArray(diagnostics.errors) ? diagnostics.errors : [];
        var messages = [];
        errors.forEach(function (item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            var msg = String(item.message || '').trim();
            if (msg === '' || messages.indexOf(msg) !== -1) {
                return;
            }
            messages.push(msg);
        });
        if (messages.length === 0) {
            return fallback;
        }
        return messages.slice(0, 3).join('；') + (messages.length > 3 ? '…' : '');
    }

    function forceHydrateCreateBasicsFromDraft() {
        // scope-persistence only fills empty fields after async GET; local draft must
        // win early so resumeCreateWizardStep can pass the basics gate on refresh.
        // status defaults to draft in HTML — must force-overwrite from draft (published).
        forceHydrateScopedHiddenFromDraft('product-create-type', 'product_type');
        forceHydrateScopedHiddenFromDraft('product-create-status', 'status');
        forceHydrateScopedHiddenFromDraft('product-create-name', 'name');
        forceHydrateScopedHiddenFromDraft('product-create-sku', 'sku');
        forceHydrateScopedHiddenFromDraft('product-create-spu', 'spu');
        forceHydrateScopedHiddenFromDraft('product-create-price', 'price');
        forceHydrateScopedHiddenFromDraft('product-create-cost', 'cost');
        forceHydrateScopedHiddenFromDraft('product-create-stock', 'stock');
        forceHydrateScopedHiddenFromDraft('product-create-currency', 'currency');
        forceHydrateScopedHiddenFromDraft('product-create-visibility', 'visibility');
        forceHydrateScopedHiddenFromDraft('product-create-barcode', 'barcode');
        forceHydrateScopedHiddenFromDraft('product-create-quote-only', 'quote_only');
        forceHydrateScopedHiddenFromDraft('product-create-short-description', 'short_description');
        forceHydrateScopedHiddenFromDraft('product-create-description', 'description');
        forceHydrateScopedHiddenFromDraft('product-create-slug', 'slug');
        forceHydrateScopedHiddenFromDraft('product-create-meta-name', 'meta_name');
        forceHydrateScopedHiddenFromDraft('product-create-meta-description', 'meta_description');
        forceHydrateScopedHiddenFromDraft('product-create-meta-keywords', 'meta_keywords');
        forceHydrateScopedHiddenFromDraft('product-create-weight', 'weight');
        forceHydrateScopedHiddenFromDraft('product-create-length', 'length');
        forceHydrateScopedHiddenFromDraft('product-create-width', 'width');
        forceHydrateScopedHiddenFromDraft('product-create-height', 'height');
        syncCreateSubmitButtonLabel();
    }

    function forceHydrateCategoriesFromDraft() {
        var draft = readProductCreateDraftData();
        var next = '';
        if (Object.prototype.hasOwnProperty.call(draft, 'category_ids')) {
            next = draft.category_ids == null ? '' : String(draft.category_ids);
        }
        var hidden = document.getElementById('product-create-categories');
        if (next === '' && hidden instanceof HTMLInputElement) {
            // scope-persistence 可能已写入 hidden，但芯片尚未水合。
            next = String(hidden.value || '');
        }
        if (next === '') {
            return;
        }
        var api = window.WelineCatalogCategorySelect
            && window.WelineCatalogCategorySelect['product-create-categories'];
        if (api && typeof api.getValue === 'function' && typeof api.setValue === 'function') {
            var current = String(api.getValue() || '');
            var chipCount = document.querySelectorAll(
                '#product-create-categories_chips .weline-catalog-category-select-chip-label'
            ).length;
            if (current === '' || chipCount === 0 || current !== next) {
                var applied = api.setValue(next, {silent: true});
                var cleaned = applied == null ? String(api.getValue() || '') : String(applied);
                if (cleaned !== next) {
                    patchProductCreateDraftLocal({category_ids: cleaned});
                }
            }
            return;
        }
        if (hidden instanceof HTMLInputElement) {
            if (hidden.value !== next) {
                hidden.value = next;
            }
            try {
                hidden.dispatchEvent(new Event('input', {bubbles: true}));
            } catch (_error) {
            }
        }
    }

    function forceHydrateAttributeSetFromDraft() {
        forceHydrateScopedHiddenFromDraft('product-create-attribute-set-id', 'attribute_set_id');
        forceHydrateScopedHiddenFromDraft('product-create-attribute-set-code', 'attribute_set');
        forceHydrateScopedHiddenFromDraft('product-create-attribute-set-name', 'attribute_set_label');
        syncCreateAttributeSetChipFromFields();
    }

    function forceHydrateCreateEavDraftFieldsFromDraft() {
        forceHydrateScopedHiddenFromDraft('product-create-eav-attributes-json', 'create_eav_attributes_json');
        forceHydrateScopedHiddenFromDraft('product-create-variant-axes-json', 'create_variant_axes_json');
        forceHydrateScopedHiddenFromDraft(
            'product-create-variant-option-images-json',
            'create_variant_option_images_json'
        );
        forceHydrateScopedHiddenFromDraft('product-create-media-json', 'media_assignments_json');
    }

    function parseCreateDraftJson(raw, fallback) {
        if (raw == null || String(raw).trim() === '') {
            return fallback;
        }
        try {
            var parsed = JSON.parse(String(raw));
            return parsed == null ? fallback : parsed;
        } catch (_error) {
            return fallback;
        }
    }

    function applyValueToCreateEavField(field, draftRow) {
        if (!field || !draftRow || typeof draftRow !== 'object') {
            return;
        }
        var input = field.querySelector('[data-eav-input]');
        var state = field.querySelector('[data-eav-state]');
        var valueType = String(field.getAttribute('data-value-type') || 'string');
        if (state && draftRow.scope_state) {
            state.value = String(draftRow.scope_state);
        }
        if (!input) {
            return;
        }
        var value = draftRow.value;
        if (valueType === 'boolean') {
            input.checked = Boolean(value);
            return;
        }
        if (valueType === 'multiselect' && input instanceof HTMLSelectElement) {
            var selected = Array.isArray(value) ? value.map(String) : [];
            Array.prototype.forEach.call(input.options, function (option) {
                option.selected = selected.indexOf(String(option.value)) >= 0;
            });
            return;
        }
        if (valueType === 'json') {
            input.value = value == null || value === ''
                ? ''
                : (typeof value === 'string' ? value : JSON.stringify(value, null, 2));
            return;
        }
        input.value = value == null ? '' : String(value);
    }

    function flushProductCreateDraftRemoteNow() {
        var endpoint = productCreateDraftEndpoint();
        if (endpoint === '' || !window.Weline || !window.Weline.Api || typeof window.Weline.Api.post !== 'function') {
            return Promise.resolve();
        }
        var draft = readProductCreateDraftData();
        if (!draft || typeof draft !== 'object' || Array.isArray(draft) || Object.keys(draft).length === 0) {
            return Promise.resolve();
        }
        return window.Weline.Api.post(endpoint, {
            scope: PRODUCT_CREATE_DRAFT_SCOPE,
            data: draft
        }).then(function () {
            try {
                var raw = window.localStorage.getItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY) || '{}';
                var store = JSON.parse(raw);
                if (store && store[PRODUCT_CREATE_DRAFT_SCOPE]) {
                    store[PRODUCT_CREATE_DRAFT_SCOPE].hasChange = false;
                    window.localStorage.setItem(PRODUCT_CREATE_DRAFT_STORAGE_KEY, JSON.stringify(store));
                }
            } catch (_error) {
            }
            return null;
        }).catch(function () {
            return null;
        });
    }

    var createEavDraftFlushTimer = 0;
    function scheduleFlushProductCreateDraftRemote() {
        window.clearTimeout(createEavDraftFlushTimer);
        createEavDraftFlushTimer = window.setTimeout(function () {
            flushProductCreateDraftRemoteNow();
        }, 400);
    }

    function persistCreateEavDraft(options) {
        options = options || {};
        var attributes = {};
        // Visible step roots only (see getCreateEavFieldRoots).
        queryCreateEavFields('[data-eav-field]:not([data-create-variant-axis])').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim();
            if (code === '') {
                return;
            }
            try {
                var row = visualAttributeRow(field, {});
                if (!row) {
                    return;
                }
                attributes[code] = {
                    value: row.value,
                    value_type: row.value_type,
                    scope_state: row.scope_state
                };
            } catch (_error) {
            }
        });
        var axes = {};
        queryCreateEavFields('[data-create-variant-axis]').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim().toLowerCase();
            if (code === '') {
                return;
            }
            var selected = [];
            field.querySelectorAll('[data-create-variant-option]:checked').forEach(function (input) {
                selected.push(String(input.value || ''));
            });
            axes[code] = selected;
        });
        var images = {};
        document.querySelectorAll('#product-create-form [data-create-variant-option-image]').forEach(function (input) {
            var axisCode = String(input.getAttribute('data-axis-code') || '').trim().toLowerCase();
            var optionValue = String(input.getAttribute('data-option-value') || '');
            var imageUrl = String(input.value || '').trim();
            if (axisCode === '' || optionValue === '' || imageUrl === '') {
                return;
            }
            images[axisCode + '::' + optionValue] = imageUrl;
        });
        var attributesJson = JSON.stringify(attributes);
        var axesJson = JSON.stringify(axes);
        var imagesJson = JSON.stringify(images);
        writeScopedDraftField(
            document.getElementById('product-create-eav-attributes-json'),
            attributesJson,
            {forceEvent: true}
        );
        writeScopedDraftField(
            document.getElementById('product-create-variant-axes-json'),
            axesJson,
            {forceEvent: true}
        );
        writeScopedDraftField(
            document.getElementById('product-create-variant-option-images-json'),
            imagesJson,
            {forceEvent: true}
        );
        patchProductCreateDraftLocal({
            create_eav_attributes_json: attributesJson,
            create_variant_axes_json: axesJson,
            create_variant_option_images_json: imagesJson
        });
        if (options.flush !== false) {
            scheduleFlushProductCreateDraftRemote();
        }
    }

    var createEavDraftPersistTimer = 0;
    function schedulePersistCreateEavDraft() {
        window.clearTimeout(createEavDraftPersistTimer);
        createEavDraftPersistTimer = window.setTimeout(function () {
            persistCreateEavDraft({flush: true});
        }, 150);
    }

    function isCreateEavDraftEventTarget(target) {
        if (!(target instanceof Element)) {
            return false;
        }
        if (!target.closest('#product-create-form')) {
            return false;
        }
        return Boolean(
            target.closest('[data-eav-field]')
            || target.hasAttribute('data-create-variant-option')
            || target.hasAttribute('data-create-variant-option-image')
            || target.hasAttribute('data-eav-input')
        );
    }

    function onCreateEavDraftDomEvent(event) {
        if (!isCreateEavDraftEventTarget(event.target)) {
            return;
        }
        window.__welineCreateEavUserEdited = true;
        if (event.target.closest('[data-create-step="variant-axes"]')
            || event.target.hasAttribute('data-create-variant-option')
        ) {
            rememberCreateWizardStep('variant-axes');
        } else if (event.target.closest('[data-create-step="product-attributes"]')) {
            rememberCreateWizardStep('product-attributes');
        } else if (event.target.closest('[data-create-step="variant-values"]')) {
            rememberCreateWizardStep('variant-values');
        }
        schedulePersistCreateEavDraft();
    }

    function bindCreateEavDraftFieldNodes(rootNode) {
        var inputs = [];
        if (rootNode instanceof Element) {
            if (rootNode.matches('[data-eav-input], [data-create-variant-option], [data-create-variant-option-image]')) {
                inputs.push(rootNode);
            }
            rootNode.querySelectorAll(
                '[data-eav-input], [data-create-variant-option], [data-create-variant-option-image]'
            ).forEach(function (input) {
                inputs.push(input);
            });
        } else {
            document.querySelectorAll(
                '#product-create-product-attributes [data-eav-input],'
                + '#product-create-variant-axes [data-eav-input],'
                + '#product-create-product-attributes [data-create-variant-option],'
                + '#product-create-variant-axes [data-create-variant-option],'
                + '#product-create-form [data-create-variant-option-image]'
            ).forEach(function (input) {
                inputs.push(input);
            });
        }
        inputs.forEach(function (input) {
            if (!(input instanceof Element) || input.getAttribute('data-create-eav-draft-node-bound') === '1') {
                return;
            }
            input.setAttribute('data-create-eav-draft-node-bound', '1');
            input.addEventListener('input', onCreateEavDraftDomEvent);
            input.addEventListener('change', onCreateEavDraftDomEvent);
        });
    }

    function bindCreateEavDraftPersistence() {
        if (window.__welineCreateEavDraftDocBound === true) {
            bindCreateEavDraftFieldNodes();
            return;
        }
        window.__welineCreateEavDraftDocBound = true;
        // Capture on document so dynamically rendered attribute fields always hit,
        // even if form listeners were attached before innerHTML replace.
        document.addEventListener('input', onCreateEavDraftDomEvent, true);
        document.addEventListener('change', onCreateEavDraftDomEvent, true);
        window.addEventListener('pagehide', function () {
            persistCreateEavDraft({flush: false});
            flushProductCreateDraftRemoteNow();
        });
        bindCreateEavDraftFieldNodes();
    }

    function applyCreateEavDraft() {
        var attributesField = document.getElementById('product-create-eav-attributes-json');
        var axesField = document.getElementById('product-create-variant-axes-json');
        var imagesField = document.getElementById('product-create-variant-option-images-json');
        var draft = readProductCreateDraftData();
        var attributes = parseCreateDraftJson(
            (attributesField && attributesField.value) || draft.create_eav_attributes_json,
            {}
        );
        var axes = parseCreateDraftJson(
            (axesField && axesField.value) || draft.create_variant_axes_json,
            {}
        );
        var images = parseCreateDraftJson(
            (imagesField && imagesField.value) || draft.create_variant_option_images_json,
            {}
        );
        if (!attributes || typeof attributes !== 'object' || Array.isArray(attributes)) {
            attributes = {};
        }
        if (!axes || typeof axes !== 'object' || Array.isArray(axes)) {
            axes = {};
        }
        if (!images || typeof images !== 'object' || Array.isArray(images)) {
            images = {};
        }

        queryCreateEavFields('[data-eav-field]:not([data-create-variant-axis])').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim();
            if (code !== '' && Object.prototype.hasOwnProperty.call(attributes, code)) {
                applyValueToCreateEavField(field, attributes[code]);
            }
        });

        queryCreateEavFields('[data-create-variant-axis]').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim().toLowerCase();
            var selected = Object.prototype.hasOwnProperty.call(axes, code) ? axes[code] : null;
            if (!Array.isArray(selected)) {
                return;
            }
            var selectedMap = {};
            selected.forEach(function (value) {
                selectedMap[String(value)] = true;
            });
            field.querySelectorAll('[data-create-variant-option]').forEach(function (input) {
                input.checked = Boolean(selectedMap[String(input.value || '')]);
            });
        });

        window.__welineCreateVariantOptionImageDraft = images;
        renderCreateVariantValueConfig();
        renderCreateVariantPreview(false);
        window.__welineCreateVariantOptionImageDraft = null;
        bindCreateEavDraftFieldNodes();
    }

    function syncCreateAttributeSetChipFromFields() {
        var createSetLabel = document.getElementById('product-create-attribute-set-label');
        var createSetName = document.getElementById('product-create-attribute-set-name');
        var createSetCode = document.getElementById('product-create-attribute-set-code');
        var createSetId = document.getElementById('product-create-attribute-set-id');
        if (!(createSetLabel instanceof HTMLElement)) {
            return;
        }
        var text = String(
            (createSetName && createSetName.value)
            || (createSetCode && createSetCode.value)
            || (createSetId && createSetId.value)
            || ''
        ).trim();
        createSetLabel.textContent = text || '未选择属性集';
        createSetLabel.classList.toggle('is-selected', text !== '');
        createSetLabel.classList.toggle('is-empty', text === '');
        createSetLabel.setAttribute('data-selected', text !== '' ? '1' : '0');
    }

    function createEavFieldsLookBlank() {
        var filled = false;
        queryCreateEavFields('[data-eav-field]:not([data-create-variant-axis])').forEach(function (field) {
            if (filled) {
                return;
            }
            try {
                var row = visualAttributeRow(field, {});
                if (!row) {
                    return;
                }
                var value = row.value;
                if (value === null || value === undefined || value === '') {
                    return;
                }
                if (Array.isArray(value) && value.length === 0) {
                    return;
                }
                if (typeof value === 'boolean' && value === false) {
                    return;
                }
                filled = true;
            } catch (_error) {
            }
        });
        if (filled) {
            return false;
        }
        if (document.querySelector('#product-create-form [data-create-variant-option]:checked')) {
            return false;
        }
        if (document.querySelector('#product-create-form [data-create-variant-option-image][value]:not([value=""])')) {
            return false;
        }
        return true;
    }

    function createEavDraftHasValues() {
        var attributesField = document.getElementById('product-create-eav-attributes-json');
        var axesField = document.getElementById('product-create-variant-axes-json');
        var imagesField = document.getElementById('product-create-variant-option-images-json');
        var draft = readProductCreateDraftData();
        var attributes = parseCreateDraftJson(
            (attributesField && attributesField.value) || draft.create_eav_attributes_json,
            {}
        );
        var axes = parseCreateDraftJson(
            (axesField && axesField.value) || draft.create_variant_axes_json,
            {}
        );
        var images = parseCreateDraftJson(
            (imagesField && imagesField.value) || draft.create_variant_option_images_json,
            {}
        );
        if (attributes && typeof attributes === 'object' && !Array.isArray(attributes)
            && Object.keys(attributes).length > 0
        ) {
            return true;
        }
        if (axes && typeof axes === 'object' && !Array.isArray(axes)
            && Object.keys(axes).some(function (code) {
                return Array.isArray(axes[code]) && axes[code].length > 0;
            })
        ) {
            return true;
        }
        return Boolean(
            images && typeof images === 'object' && !Array.isArray(images)
            && Object.keys(images).length > 0
        );
    }

    function shouldApplyCreateEavDraft() {
        // Page-load / late remote draft: apply when UI is empty OR draft JSON exists and
        // the user has not typed yet. warranty_months defaultSelected must not block backfill.
        if (createEavFieldsLookBlank()) {
            return true;
        }
        return createEavDraftHasValues() && !window.__welineCreateEavUserEdited;
    }

    function hydrateProductCreateDraftUi() {
        forceHydrateCreateBasicsFromDraft();
        forceHydrateCategoriesFromDraft();
        forceHydrateAttributeSetFromDraft();
        forceHydrateCreateEavDraftFieldsFromDraft();
        forceHydrateScopedHiddenFromDraft('product-create-supplier', 'supplier_id');
        forceHydrateScopedHiddenFromDraft('product-create-brand', 'brand_id');
        var supplierApi = getCatalogSelectApi('product-create-supplier');
        var brandApi = getCatalogSelectApi('product-create-brand');
        if (supplierApi && typeof supplierApi.setValue === 'function') {
            var supplierHidden = document.getElementById('product-create-supplier');
            supplierApi.setValue(supplierHidden ? supplierHidden.value : '', { silent: true });
        }
        syncCreateSupplierBrandCascade({ silent: true });
        if (brandApi && typeof brandApi.setValue === 'function') {
            var brandHidden = document.getElementById('product-create-brand');
            brandApi.setValue(brandHidden ? brandHidden.value : '', { silent: true, keepUnknown: false });
        }
        syncCreateAttributeSetChipFromFields();
        hydrateCreateMediaFromJson();
        var setIdInput = document.getElementById('product-create-attribute-set-id');
        var setCodeInput = document.getElementById('product-create-attribute-set-code');
        var setId = setIdInput ? String(setIdInput.value || '').trim() : '';
        var setCode = setCodeInput ? String(setCodeInput.value || '').trim() : '';
        if (setId === '' && setCode !== '') {
            setId = setCode;
        }
        if (setId !== '') {
            var productRoot = document.getElementById('product-create-product-attributes');
            var variantRoot = document.getElementById('product-create-variant-axes');
            var hasFields = Boolean(
                (productRoot && productRoot.querySelector('[data-eav-field]'))
                || (variantRoot && variantRoot.querySelector('[data-eav-field]'))
            );
            var renderedSetKey = '';
            var sampleField = (productRoot && productRoot.querySelector('[data-eav-field]'))
                || (variantRoot && variantRoot.querySelector('[data-eav-field]'));
            if (sampleField) {
                renderedSetKey = String(sampleField.getAttribute('data-set-id') || '').trim();
            }
            var setMismatch = hasFields
                && renderedSetKey !== ''
                && renderedSetKey !== setId
                && renderedSetKey !== setCode;
            if (!hasFields || setMismatch) {
                renderCreateEavFields(setId);
                applyCreateEavDraft();
                window.__welineCreateEavDraftApplied = true;
            } else if (shouldApplyCreateEavDraft()) {
                // Late scope/remote draft: apply empty UI or pending draft without clobbering typing.
                applyCreateEavDraft();
                window.__welineCreateEavDraftApplied = true;
            }
        }
        hydrateCreateSeoFromDraft();
        syncCreateSupplierOfferPanel();
        updateCreateWizardVisibility();
        resumeCreateWizardStep({silent: true});
    }

    /**
     * Segment for product URL handle: lowercase kebab only (no p- prefix).
     * Final assembled slug must still start with a letter (storefront router).
     */
    function slugifyProductHandleSegment(value) {
        if (window.Weline && Weline.Cms && Weline.Cms.PageEditor
            && typeof Weline.Cms.PageEditor.slugifyEnglish === 'function'
        ) {
            var fromCms = String(Weline.Cms.PageEditor.slugifyEnglish(value) || '').trim();
            if (fromCms !== '' && fromCms !== 'page') {
                return fromCms.replace(/^-+|-+$/g, '').slice(0, 255);
            }
        }
        return String(value || '')
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 255);
    }

    function slugifyProductHandle(value) {
        var slug = slugifyProductHandleSegment(value);
        if (slug === '' || !/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(slug)) {
            return '';
        }
        return slug;
    }

    function resolveCreateSlugFromBasics() {
        var nameInput = document.getElementById('product-create-name');
        var skuInput = document.getElementById('product-create-sku');
        var namePart = slugifyProductHandleSegment(nameInput ? nameInput.value : '');
        var skuPart = slugifyProductHandleSegment(skuInput ? skuInput.value : '');
        var parts = [];
        if (namePart !== '') {
            parts.push(namePart);
        }
        if (skuPart !== '' && skuPart !== namePart) {
            parts.push(skuPart);
        }
        var slug = parts.join('-').replace(/-+/g, '-').replace(/^-+|-+$/g, '').slice(0, 255);
        if (slug === '' || !/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(slug)) {
            return '';
        }
        return slug;
    }

    function syncCreateSeoTitleFromBasics() {
        var nameInput = document.getElementById('product-create-name');
        var metaNameInput = document.getElementById('product-create-meta-name');
        if (!(metaNameInput instanceof HTMLInputElement)) {
            return;
        }
        if (metaNameInput.dataset.touched === '1') {
            return;
        }
        metaNameInput.value = nameInput ? String(nameInput.value || '').trim() : '';
    }

    function syncCreateSlugFromBasics() {
        var slugInput = document.getElementById('product-create-slug');
        if (!(slugInput instanceof HTMLInputElement)) {
            return;
        }
        if (slugInput.dataset.touched === '1') {
            return;
        }
        slugInput.value = resolveCreateSlugFromBasics();
        scheduleCreateSlugAvailabilityCheck();
    }

    function hydrateCreateSeoFromDraft() {
        var nameInput = document.getElementById('product-create-name');
        var metaNameInput = document.getElementById('product-create-meta-name');
        var slugInput = document.getElementById('product-create-slug');
        var name = nameInput ? String(nameInput.value || '').trim() : '';
        var expectedSlug = resolveCreateSlugFromBasics();

        if (metaNameInput instanceof HTMLInputElement) {
            var currentMeta = String(metaNameInput.value || '').trim();
            if (currentMeta !== '' && currentMeta !== name) {
                metaNameInput.dataset.touched = '1';
            } else {
                syncCreateSeoTitleFromBasics();
            }
        }

        if (slugInput instanceof HTMLInputElement) {
            var currentSlug = String(slugInput.value || '').trim().toLowerCase();
            if (currentSlug !== '' && currentSlug !== expectedSlug) {
                slugInput.dataset.touched = '1';
            } else {
                syncCreateSlugFromBasics();
            }
        }
        scheduleCreateSlugAvailabilityCheck();
    }

    var createSlugCheckTimer = 0;
    var createSlugCheckSerial = 0;
    var createSlugAvailability = {slug: '', status: 'idle', available: true, reason: 'empty'};

    function setCreateSlugAvailabilityUi(status, message) {
        var slugInput = document.getElementById('product-create-slug');
        var statusEl = document.querySelector('[data-product-create-slug-status]');
        if (!(slugInput instanceof HTMLInputElement)) {
            return;
        }
        var invalid = status === 'taken' || status === 'invalid';
        slugInput.setAttribute('aria-invalid', invalid ? 'true' : 'false');
        slugInput.classList.toggle('is-invalid', invalid);
        if (statusEl instanceof HTMLElement) {
            statusEl.hidden = !message;
            statusEl.textContent = message || '';
            statusEl.setAttribute('data-tone', invalid ? 'danger' : (status === 'ok' ? 'success' : 'muted'));
        }
    }

    function scheduleCreateSlugAvailabilityCheck() {
        window.clearTimeout(createSlugCheckTimer);
        createSlugCheckTimer = window.setTimeout(function () {
            checkCreateSlugAvailability();
        }, 220);
    }

    function checkCreateSlugAvailability() {
        var slugInput = document.getElementById('product-create-slug');
        if (!(slugInput instanceof HTMLInputElement)) {
            return Promise.resolve(true);
        }
        var slug = String(slugInput.value || '').trim().toLowerCase();
        if (slug === '') {
            createSlugAvailability = {slug: '', status: 'empty', available: true, reason: 'empty'};
            setCreateSlugAvailabilityUi('empty', '');
            return Promise.resolve(true);
        }
        if (!/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/.test(slug)) {
            createSlugAvailability = {slug: slug, status: 'invalid', available: false, reason: 'invalid'};
            setCreateSlugAvailabilityUi('invalid', 'URL Handle 格式无效（需小写字母开头，仅字母数字与连字符）');
            return Promise.resolve(false);
        }

        var requestSerial = ++createSlugCheckSerial;
        createSlugAvailability = {slug: slug, status: 'checking', available: false, reason: 'checking'};
        setCreateSlugAvailabilityUi('checking', '正在检查可用性…');

        return api().then(function (resource) {
            return resource.checkSlug({
                website_id: parseInt(state.website_id, 10) || 0,
                slug: slug
            }, {keepBusinessResult: true, silent: true});
        }).then(function (raw) {
            if (requestSerial !== createSlugCheckSerial) {
                return createSlugAvailability.available;
            }
            var result = businessResult(raw);
            var available = result && result.available === true;
            var reason = String(result && result.reason ? result.reason : (available ? 'ok' : 'taken'));
            createSlugAvailability = {
                slug: slug,
                status: available ? 'ok' : reason,
                available: available,
                reason: reason
            };
            if (available) {
                setCreateSlugAvailabilityUi('ok', 'Handle 可用');
            } else if (reason === 'invalid') {
                setCreateSlugAvailabilityUi('invalid', 'URL Handle 格式无效');
            } else {
                setCreateSlugAvailabilityUi('taken', '该 Handle 已被占用，请更换');
            }
            return available;
        }).catch(function () {
            if (requestSerial !== createSlugCheckSerial) {
                return createSlugAvailability.available;
            }
            createSlugAvailability = {slug: slug, status: 'error', available: false, reason: 'error'};
            setCreateSlugAvailabilityUi('taken', '无法校验 Handle，请稍后重试');
            return false;
        });
    }

    function ensureCreateSlugAvailableForSubmit() {
        var slugInput = document.getElementById('product-create-slug');
        var slug = slugInput instanceof HTMLInputElement ? String(slugInput.value || '').trim().toLowerCase() : '';
        if (slug === '') {
            return Promise.resolve(true);
        }
        if (createSlugAvailability.slug === slug
            && (createSlugAvailability.status === 'ok' || createSlugAvailability.status === 'taken' || createSlugAvailability.status === 'invalid')
        ) {
            return Promise.resolve(createSlugAvailability.available === true);
        }
        return checkCreateSlugAvailability();
    }

    function getCatalogSelectApi(id) {
        var bag = window.WelineProductCatalogSelect;
        if (!bag || typeof bag !== 'object') {
            return null;
        }
        return bag[id] || null;
    }

    function readSupplierBrandMap() {
        var el = document.getElementById('product-create-supplier-brand-map');
        if (!(el instanceof HTMLElement)) {
            return {};
        }
        try {
            var parsed = JSON.parse(el.textContent || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (_e) {
            return {};
        }
    }

    function syncCreateSupplierBrandCascade(opts) {
        opts = opts || {};
        var silent = opts.silent === true;
        var supplierApi = getCatalogSelectApi('product-create-supplier');
        var brandApi = getCatalogSelectApi('product-create-brand');
        if (!brandApi || typeof brandApi.setAllowedValues !== 'function') {
            return;
        }
        var supplierId = supplierApi && typeof supplierApi.getValue === 'function'
            ? String(supplierApi.getValue() || '').trim()
            : '';
        if (supplierId === '' || supplierId === '0') {
            brandApi.setAllowedValues([]);
            if (typeof brandApi.clear === 'function') {
                brandApi.clear({ silent: true });
            }
            syncCreateSupplierOfferPanel();
            return;
        }
        var map = readSupplierBrandMap();
        var allowed = Array.isArray(map[supplierId]) ? map[supplierId].slice() : [];
        brandApi.setAllowedValues(allowed);
        syncCreateSupplierOfferPanel();
    }

    function syncCreateSupplierOfferPanel() {
        var supplierApi = getCatalogSelectApi('product-create-supplier');
        var offerPanel = document.getElementById('product-create-supplier-offer');
        if (!(offerPanel instanceof HTMLElement)) {
            return;
        }
        var supplierId = supplierApi && typeof supplierApi.getValue === 'function'
            ? parseInt(String(supplierApi.getValue() || '').trim(), 10)
            : 0;
        var hasSupplier = Number.isInteger(supplierId) && supplierId > 0;
        offerPanel.hidden = !hasSupplier;
        if (!hasSupplier) {
            return;
        }
        var currencyInput = document.getElementById('product-create-supplier-currency');
        if (!(currencyInput instanceof HTMLInputElement) || String(currencyInput.value || '').trim() !== '') {
            return;
        }
        var createSuppliers = Array.isArray(state.creation_context && state.creation_context.suppliers)
            ? state.creation_context.suppliers
            : [];
        createSuppliers.forEach(function (row) {
            if (!row || typeof row !== 'object') {
                return;
            }
            if (String(row.supplier_id || '') !== String(supplierId)) {
                return;
            }
            var defaultCurrency = String(row.default_currency || '').trim();
            if (defaultCurrency !== '') {
                currencyInput.value = defaultCurrency;
            }
        });
    }

    function bindCreateSupplierOfferPanel() {
        var supplierHidden = document.getElementById('product-create-supplier');
        if (!(supplierHidden instanceof HTMLInputElement)
            || supplierHidden.getAttribute('data-supplier-offer-bound') === '1'
        ) {
            syncCreateSupplierBrandCascade({ silent: true });
            return;
        }
        supplierHidden.setAttribute('data-supplier-offer-bound', '1');
        supplierHidden.addEventListener('change', function () {
            syncCreateSupplierBrandCascade();
        });
        syncCreateSupplierBrandCascade({ silent: true });
    }

    function bindCreateSlugAutoGenerate() {
        var nameInput = document.getElementById('product-create-name');
        var skuInput = document.getElementById('product-create-sku');
        var slugInput = document.getElementById('product-create-slug');
        var metaNameInput = document.getElementById('product-create-meta-name');
        if (!(slugInput instanceof HTMLInputElement) || slugInput.getAttribute('data-auto-slug-bound') === '1') {
            return;
        }
        slugInput.setAttribute('data-auto-slug-bound', '1');
        slugInput.addEventListener('input', function () {
            slugInput.dataset.touched = '1';
            var cleaned = slugifyProductHandleSegment(slugInput.value);
            if (cleaned !== '' || String(slugInput.value || '').trim() === '') {
                slugInput.value = cleaned;
            }
            scheduleCreateSlugAvailabilityCheck();
        });
        if (metaNameInput instanceof HTMLInputElement) {
            metaNameInput.addEventListener('input', function () {
                metaNameInput.dataset.touched = '1';
            });
        }
        function onBasicsIdentityInput() {
            syncCreateSeoTitleFromBasics();
            syncCreateSlugFromBasics();
        }
        if (nameInput) {
            nameInput.addEventListener('input', onBasicsIdentityInput);
        }
        if (skuInput) {
            skuInput.addEventListener('input', function () {
                syncCreateSlugFromBasics();
            });
        }
        syncCreateSeoTitleFromBasics();
        syncCreateSlugFromBasics();
    }

    function collectCreateMediaAssignments() {
        var body = document.querySelector('#product-create-form [data-product-create-media-rows]');
        if (!body) {
            return [];
        }
        var seen = {};
        return Array.prototype.map.call(
            body.querySelectorAll('[data-product-create-media-row]'),
            function (row, index) {
                var assetId = String(row.getAttribute('data-asset-id') || '').trim().toLowerCase();
                if (!/^[a-f0-9-]{36}$/.test(assetId) || seen[assetId]) {
                    throw new Error('媒体资源身份无效或重复');
                }
                seen[assetId] = true;
                var roleInput = row.querySelector('[data-product-create-media-role]');
                var role = String(roleInput ? roleInput.value : 'gallery');
                var positionInput = row.querySelector('[data-product-create-media-position]');
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
                    position: position,
                    mime_type: String(row.getAttribute('data-mime-type') || ''),
                    preview_url: String(row.getAttribute('data-preview-url') || ''),
                    display_name: String(row.getAttribute('data-display-name') || '')
                };
            }
        );
    }

    function syncCreateMediaJson(options) {
        options = options || {};
        var input = document.getElementById('product-create-media-json');
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        var rows;
        try {
            rows = collectCreateMediaAssignments();
        } catch (_error) {
            rows = [];
        }
        var next = rows.length ? JSON.stringify(rows) : '';
        if (input.value === next) {
            return;
        }
        input.value = next;
        // hydrate / silent sync must not fan out into scope-persistence + MutationObserver storms
        if (options.silent === true || createMediaHydrating) {
            return;
        }
        input.dispatchEvent(new Event('change', {bubbles: true}));
        patchProductCreateDraftLocal({media_assignments_json: next});
    }

    function updateCreateMediaEmptyState() {
        var empty = document.querySelector('#product-create-form [data-product-create-media-empty]');
        var body = document.querySelector('#product-create-form [data-product-create-media-rows]');
        if (!empty || !body) {
            return;
        }
        empty.hidden = body.querySelectorAll('[data-product-create-media-row]').length > 0;
    }

    function appendCreateMediaRow(file, preferredRole) {
        var body = document.querySelector('#product-create-form [data-product-create-media-rows]');
        if (!body) {
            return;
        }
        var assetId = String(file.asset_id || '').trim().toLowerCase();
        var mimeType = String(file.mime || file.mime_type || '').trim().toLowerCase();
        if (!/^[a-f0-9-]{36}$/.test(assetId) || (mimeType !== '' && mimeType.indexOf('image/') !== 0)) {
            throw new Error('媒体库返回的图片资源无效');
        }
        if (body.querySelector('[data-asset-id="' + assetId + '"]')) {
            notify('warning', '该图片已经在商品媒体中');
            return;
        }

        var rowCount = body.querySelectorAll('[data-product-create-media-row]').length;
        var roleValue = preferredRole || (rowCount === 0 ? 'main' : 'gallery');
        var preview = resolveMediaPickerFileUrl(file) || String(file.preview_url || '');
        var displayName = String(file.display_name || assetId);

        var row = document.createElement('tr');
        row.setAttribute('data-product-create-media-row', '');
        row.setAttribute('data-asset-id', assetId);
        row.setAttribute('data-mime-type', mimeType);
        row.setAttribute('data-preview-url', preview);
        row.setAttribute('data-display-name', displayName);

        var assetCell = document.createElement('td');
        if (preview !== '') {
            var image = document.createElement('img');
            image.className = 'w-product-media-thumb';
            image.src = preview;
            image.alt = displayName;
            assetCell.appendChild(image);
        }
        var name = document.createElement('strong');
        name.className = 'w-product-asset-name';
        name.textContent = displayName;
        assetCell.appendChild(name);
        var meta = document.createElement('small');
        meta.textContent = (mimeType || 'image') + ' · ' + assetId;
        assetCell.appendChild(meta);
        row.appendChild(assetCell);

        var roleCell = document.createElement('td');
        var role = document.createElement('select');
        role.className = 'w-select';
        role.setAttribute('data-product-create-media-role', '');
        [['gallery', '图集'], ['main', '主图']].forEach(function (optionData) {
            var option = document.createElement('option');
            option.value = optionData[0];
            option.textContent = optionData[1];
            if (optionData[0] === roleValue) {
                option.selected = true;
            }
            role.appendChild(option);
        });
        role.addEventListener('change', syncCreateMediaJson);
        roleCell.appendChild(role);
        row.appendChild(roleCell);

        var positionCell = document.createElement('td');
        var position = document.createElement('input');
        position.className = 'w-input';
        position.type = 'number';
        position.min = '0';
        position.step = '1';
        position.value = String(file.position != null ? file.position : rowCount);
        position.setAttribute('data-product-create-media-position', '');
        position.addEventListener('change', syncCreateMediaJson);
        position.addEventListener('input', syncCreateMediaJson);
        positionCell.appendChild(position);
        row.appendChild(positionCell);

        var actionCell = document.createElement('td');
        var remove = document.createElement('button');
        remove.className = 'w-button';
        remove.type = 'button';
        remove.setAttribute('data-tone', 'danger');
        remove.setAttribute('data-product-create-media-remove', '');
        remove.textContent = '移除';
        actionCell.appendChild(remove);
        row.appendChild(actionCell);

        body.appendChild(row);
        updateCreateMediaEmptyState();
        syncCreateMediaJson();
    }

    function hydrateCreateMediaFromJson() {
        if (createMediaHydrating) {
            return;
        }
        var input = document.getElementById('product-create-media-json');
        var body = document.querySelector('#product-create-form [data-product-create-media-rows]');
        if (!(input instanceof HTMLInputElement) || !body) {
            return;
        }
        createMediaHydrating = true;
        try {
            var raw = String(input.value || '').trim();
            var rows = [];
            if (raw !== '') {
                try {
                    var parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        rows = parsed;
                    }
                } catch (_error) {
                    rows = [];
                }
            }
            body.innerHTML = '';
            rows.forEach(function (row) {
                if (!row || typeof row !== 'object') {
                    return;
                }
                try {
                    appendCreateMediaRow({
                        asset_id: row.asset_id,
                        mime: row.mime_type || 'image/jpeg',
                        mime_type: row.mime_type || 'image/jpeg',
                        preview_url: row.preview_url || '',
                        display_name: row.display_name || row.asset_id,
                        position: row.position
                    }, row.role === 'main' ? 'main' : 'gallery');
                } catch (_error) {
                }
            });
            syncCreateMediaJson({silent: true});
            updateCreateMediaEmptyState();
        } finally {
            createMediaHydrating = false;
        }
    }

    function initializeCreateMedia() {
        var mediaBody = document.querySelector('#product-create-form [data-product-create-media-rows]');
        if (mediaBody && !mediaBody.getAttribute('data-create-media-bound')) {
            mediaBody.setAttribute('data-create-media-bound', '1');
            mediaBody.addEventListener('click', function (event) {
                var target = event.target instanceof Element
                    ? event.target.closest('[data-product-create-media-remove]')
                    : null;
                if (!target) {
                    return;
                }
                var row = target.closest('[data-product-create-media-row]');
                if (row) {
                    row.remove();
                    updateCreateMediaEmptyState();
                    syncCreateMediaJson();
                }
            });
        }

        var dialog = document.querySelector('[data-product-create-media-picker-dialog]');
        var frame = document.querySelector('[data-product-create-media-picker-frame]');
        var open = document.querySelector('[data-product-create-media-picker-open]');
        var close = document.querySelector('[data-product-create-media-picker-close]');

        function closePicker() {
            if (!dialog) {
                return;
            }
            closeProductMediaDialog(dialog, 'product-create-media');
        }

        if (open && dialog && frame && !open.getAttribute('data-create-media-bound')) {
            open.setAttribute('data-create-media-bound', '1');
            open.addEventListener('click', function () {
                try {
                    var pickerUrl = new URL(frame.getAttribute('data-src') || '', window.location.href);
                    if (pickerUrl.origin !== window.location.origin) {
                        throw new Error('媒体选择器必须与后台同源');
                    }
                    pickerUrl = appendCreatePickerIdentity(pickerUrl, 'media', 'media');
                    frame.src = pickerUrl.href;
                    if (!openProductMediaDialog(dialog, {trigger: 'product-create-media'})) {
                        throw new Error('媒体选择器不可用');
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '媒体选择器不可用'));
                }
            });
        }
        if (close && !close.getAttribute('data-create-media-bound')) {
            close.setAttribute('data-create-media-bound', '1');
            close.addEventListener('click', closePicker);
        }

        if (!window.__welineProductCreateMediaPickerBound) {
            window.__welineProductCreateMediaPickerBound = true;
            window.addEventListener('message', function (event) {
                if (!frame || event.source !== frame.contentWindow
                    || event.origin !== window.location.origin
                    || !event.data || typeof event.data !== 'object'
                    || String(event.data.target || '') !== 'product-create-media-picker'
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
                    event.data.files.forEach(function (file) {
                        appendCreateMediaRow(file);
                    });
                    closePicker();
                } catch (error) {
                    notify('error', messageFrom(error, '添加商品图片失败'));
                }
            });
        }
        updateCreateMediaEmptyState();
    }

    function initializeCatalog() {
        var createForm = document.getElementById('product-create-form');
        var typeInput = document.getElementById('product-create-type');
        var variantPanel = document.getElementById('product-create-variant-panel');
        var skuInput = document.getElementById('product-create-sku');

        function updateAxesVisibility() {
            updateCreateWizardVisibility();
        }

        if (typeInput) {
            typeInput.addEventListener('change', function () {
                updateAxesVisibility();
                var setIdInput = document.getElementById('product-create-attribute-set-id');
                var setCodeInput = document.getElementById('product-create-attribute-set-code');
                var setId = setIdInput ? String(setIdInput.value || '').trim() : '';
                if (setId === '' && setCodeInput) {
                    setId = String(setCodeInput.value || '').trim();
                }
                if (setId !== '') {
                    window.__welineCreateEavDraftApplied = false;
                    renderCreateEavFields(setId);
                    applyCreateEavDraft();
                    window.__welineCreateEavDraftApplied = true;
                }
            });
            updateAxesVisibility();
        }
        if (skuInput && !skuInput.getAttribute('data-variant-preview-bound')) {
            skuInput.addEventListener('input', function () {
                window.clearTimeout(createVariantPreviewTimer);
                createVariantPreviewTimer = window.setTimeout(function () {
                    renderCreateVariantPreview(false);
                }, 200);
            });
            skuInput.setAttribute('data-variant-preview-bound', '1');
        }

        // scope-persistence restores async via GET; re-sync chip / EAV / media / wizard gates.
        // Local draft force-hydrate covers server defaults + late remote apply races.
        bindCreateSlugAutoGenerate();
        bindCreateEavDraftPersistence();
        bindCreateSupplierOfferPanel();
        window.setTimeout(hydrateProductCreateDraftUi, 0);
        window.setTimeout(hydrateProductCreateDraftUi, 400);
        window.setTimeout(hydrateProductCreateDraftUi, 1200);
        initializeCreateMedia();
        window.WelineProductAdmin = window.WelineProductAdmin || {};
        window.WelineProductAdmin.syncCreateSupplierBrandCascade = syncCreateSupplierBrandCascade;
        window.WelineProductAdmin.syncCreateSupplierOfferPanel = syncCreateSupplierOfferPanel;

        if (createForm) {
            createForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!validateCreateEavFields()) {
                    return;
                }
                if (!createForm.reportValidity()) {
                    return;
                }
                var submit = createForm.querySelector('button[type="submit"]');
                var payload;
                try {
                    var type = String(typeInput.value || 'simple');
                    var slugEl = document.getElementById('product-create-slug');
                    if (slugEl instanceof HTMLInputElement
                        && String(slugEl.value || '').trim() === ''
                        && slugEl.dataset.touched !== '1'
                    ) {
                        syncCreateSlugFromBasics();
                    }
                    payload = {
                        product_type: type,
                        name: String(document.getElementById('product-create-name').value || '').trim(),
                        sku: String(document.getElementById('product-create-sku').value || '').trim().toUpperCase(),
                        currency: String((document.getElementById('product-create-currency') || {}).value || 'CNY').trim().toUpperCase() || 'CNY',
                        store_ids: selectedStoreIds(createForm)
                    };
                    var statusInput = document.getElementById('product-create-status');
                    var createStatus = statusInput ? String(statusInput.value || 'draft').trim() : 'draft';
                    payload.status = createStatus === 'published' ? 'published' : 'draft';
                    var quoteOnly = document.getElementById('product-create-quote-only');
                    if (quoteOnly && quoteOnly.checked) {
                        payload.quote_only = '1';
                    }
                    var priceInput = document.getElementById('product-create-price');
                    var priceRaw = priceInput ? String(priceInput.value || '').trim() : '';
                    if (priceRaw !== '') {
                        var priceYuan = Number(priceRaw);
                        if (!Number.isFinite(priceYuan) || priceYuan < 0) {
                            throw new Error('请输入有效售价');
                        }
                        payload.price_minor = Math.round(priceYuan * 100);
                    } else if (payload.status === 'published' && !payload.quote_only) {
                        throw new Error('选择「创建后立即发布」时请填写售价（或勾选仅询价）');
                    }
                    var costInput = document.getElementById('product-create-cost');
                    var costRaw = costInput ? String(costInput.value || '').trim() : '';
                    if (costRaw !== '') {
                        var costYuan = Number(costRaw);
                        if (!Number.isFinite(costYuan) || costYuan < 0) {
                            throw new Error('请输入有效成本');
                        }
                        payload.cost = costYuan;
                    }
                    var stockInput = document.getElementById('product-create-stock');
                    var stockRaw = stockInput ? String(stockInput.value || '').trim() : '';
                    if (stockRaw !== '') {
                        if (!/^\d+$/.test(stockRaw)) {
                            throw new Error('请输入有效库存（非负整数）');
                        }
                        payload.stock = parseInt(stockRaw, 10);
                    }
                    ['spu', 'barcode', 'visibility', 'short_description', 'description', 'slug', 'meta_name', 'meta_description', 'meta_keywords'].forEach(function (code) {
                        var el = document.getElementById('product-create-' + code.replace(/_/g, '-'));
                        if (!el) {
                            return;
                        }
                        var text = String(el.value || '').trim();
                        if (text !== '') {
                            payload[code] = text;
                        }
                    });
                    var brandSelect = document.getElementById('product-create-brand');
                    if (brandSelect) {
                        var brandId = parseInt(String(brandSelect.value || '').trim(), 10);
                        if (Number.isInteger(brandId) && brandId > 0) {
                            payload.brand_id = brandId;
                            var brandName = '';
                            var brandCode = '';
                            var createBrands = Array.isArray(state.creation_context && state.creation_context.brands)
                                ? state.creation_context.brands
                                : [];
                            createBrands.forEach(function (row) {
                                if (!row || typeof row !== 'object') {
                                    return;
                                }
                                if (String(row.brand_id || '') !== String(brandId)) {
                                    return;
                                }
                                brandName = String(row.name || '').trim();
                                brandCode = String(row.code || '').trim();
                            });
                            if (brandName === '') {
                                var brandChip = document.querySelector(
                                    '#product-create-brand_chips .weline-product-catalog-select-chip-label'
                                );
                                if (brandChip) {
                                    brandName = String(brandChip.textContent || '').trim();
                                }
                            }
                            if (brandName !== '') {
                                payload.brand = brandName;
                            }
                            if (brandCode !== '') {
                                payload.brand_code = brandCode;
                            }
                        }
                    }
                    var supplierSelect = document.getElementById('product-create-supplier');
                    if (supplierSelect) {
                        var supplierId = parseInt(String(supplierSelect.value || '').trim(), 10);
                        if (Number.isInteger(supplierId) && supplierId > 0) {
                            payload.supplier_id = supplierId;
                            var supplierProductUrl = String(
                                (document.getElementById('product-create-supplier-product-url') || {}).value || ''
                            ).trim();
                            if (supplierProductUrl !== '') {
                                payload.supplier_product_url = supplierProductUrl;
                            }
                            var supplierSku = String(
                                (document.getElementById('product-create-supplier-sku') || {}).value || ''
                            ).trim();
                            if (supplierSku !== '') {
                                payload.supplier_sku = supplierSku;
                            }
                            var supplierCurrency = String(
                                (document.getElementById('product-create-supplier-currency') || {}).value || ''
                            ).trim().toUpperCase();
                            if (supplierCurrency !== '') {
                                payload.supplier_currency = supplierCurrency;
                            }
                            var supplierUnitRaw = String(
                                (document.getElementById('product-create-supplier-unit-price') || {}).value || ''
                            ).trim();
                            if (supplierUnitRaw !== '') {
                                var supplierUnitYuan = Number(supplierUnitRaw);
                                if (!Number.isFinite(supplierUnitYuan) || supplierUnitYuan < 0) {
                                    throw new Error('请输入有效供货价');
                                }
                                payload.supplier_unit_price_minor = Math.round(supplierUnitYuan * 100);
                            }
                            var supplierMoqRaw = String(
                                (document.getElementById('product-create-supplier-moq') || {}).value || ''
                            ).trim();
                            if (supplierMoqRaw !== '') {
                                if (!/^\d+$/.test(supplierMoqRaw)) {
                                    throw new Error('请输入有效起订量');
                                }
                                payload.supplier_moq = parseInt(supplierMoqRaw, 10);
                            }
                            var supplierLeadRaw = String(
                                (document.getElementById('product-create-supplier-lead-time') || {}).value || ''
                            ).trim();
                            if (supplierLeadRaw !== '') {
                                if (!/^\d+$/.test(supplierLeadRaw)) {
                                    throw new Error('请输入有效交期');
                                }
                                payload.supplier_lead_time_days = parseInt(supplierLeadRaw, 10);
                            }
                            var supplierNotes = String(
                                (document.getElementById('product-create-supplier-notes') || {}).value || ''
                            ).trim();
                            if (supplierNotes !== '') {
                                payload.supplier_notes = supplierNotes;
                            }
                        }
                    }
                    ['weight', 'length', 'width', 'height'].forEach(function (code) {
                        var el = document.getElementById('product-create-' + code);
                        var raw = el ? String(el.value || '').trim() : '';
                        if (raw === '') {
                            return;
                        }
                        var num = Number(raw);
                        if (!Number.isFinite(num) || num < 0) {
                            throw new Error('请输入有效' + code);
                        }
                        payload[code] = num;
                    });
                    var shippingProfileEl = document.getElementById('product-create-shipping-profile');
                    if (shippingProfileEl) {
                        payload.shipping_profile_code = String(shippingProfileEl.value || '').trim();
                    }
                    var shippingHazardEl = document.getElementById('product-create-shipping-hazard');
                    if (shippingHazardEl) {
                        payload.shipping_hazard_class = String(shippingHazardEl.value || '').trim();
                    }
                    var createFreeShipEl = document.getElementById('product-create-is-free-shipping');
                    if (createFreeShipEl) {
                        payload.is_free_shipping = !!createFreeShipEl.checked;
                    }
                    var createFreeShipMinEl = document.getElementById('product-create-free-shipping-min-amount');
                    if (createFreeShipMinEl) {
                        var createMinRaw = String(createFreeShipMinEl.value || '').trim();
                        payload.free_shipping_min_amount = createMinRaw === '' ? 0 : Number(createMinRaw);
                        if (!Number.isFinite(payload.free_shipping_min_amount) || payload.free_shipping_min_amount < 0) {
                            throw new Error('请输入有效本品免邮额度');
                        }
                    }
                    var categoryIds = [];
                    var categorySelect = window.WelineCatalogCategorySelect
                        && window.WelineCatalogCategorySelect['product-create-categories'];
                    if (categorySelect && typeof categorySelect.getValues === 'function') {
                        categoryIds = categorySelect.getValues().map(function (raw) {
                            return parseInt(String(raw || ''), 10) || 0;
                        }).filter(function (id) { return id > 0; });
                    } else {
                        var categoryHidden = document.getElementById('product-create-categories');
                        if (categoryHidden) {
                            categoryIds = String(categoryHidden.value || '').split(',').map(function (raw) {
                                return parseInt(String(raw || '').trim(), 10) || 0;
                            }).filter(function (id) { return id > 0; });
                        } else {
                            categoryIds = Array.prototype.map.call(
                                createForm.querySelectorAll('[data-product-create-category-id]:checked'),
                                function (input) {
                                    return parseInt(input.value, 10) || 0;
                                }
                            ).filter(function (id) { return id > 0; });
                        }
                    }
                    if (categoryIds.length) {
                        payload.category_assignments = categoryIds.map(function (categoryId, index) {
                            return {
                                category_id: categoryId,
                                selected: true,
                                scope_state: 'explicit',
                                position: index
                            };
                        });
                    }
                    var createMedia = collectCreateMediaAssignments();
                    if (createMedia.length) {
                        payload.media_assignments = createMedia;
                    }
                    var setCodeInput = document.getElementById('product-create-attribute-set-code');
                    var setLabelInput = document.getElementById('product-create-attribute-set-name');
                    if (setCodeInput && String(setCodeInput.value || '').trim() !== '') {
                        payload.attribute_set = String(setCodeInput.value || '').trim();
                        if (setLabelInput && String(setLabelInput.value || '').trim() !== '') {
                            payload.attribute_set_label = String(setLabelInput.value || '').trim();
                        }
                        var setIdInput = document.getElementById('product-create-attribute-set-id');
                        if (setIdInput && String(setIdInput.value || '').trim() !== '') {
                            payload.attribute_set_id = String(setIdInput.value || '').trim();
                        }
                    }
                    var createAttributes = collectCreateAttributes();
                    createAttributes = mergeWholesaleSellingModeFlag(createAttributes || []);
                    if (createAttributes.length) {
                        payload.attributes = createAttributes;
                    }
                    if (type === 'configurable') {
                        if (!validateCreateVariantAxes()) {
                            return;
                        }
                        var variantAxes = collectCreateVariantAxes();
                        payload.sku_prefix = payload.sku;
                        payload.axes = variantAxes.map(function (axis) {
                            return {
                                code: axis.code,
                                label: axis.label,
                                options: axis.options.map(function (option) {
                                    var normalized = {
                                        value: option.value,
                                        label: option.label
                                    };
                                    if (option.swatch_color) {
                                        normalized.swatch_color = option.swatch_color;
                                        normalized.swatch = option.swatch_color;
                                    }
                                    if (option.swatch_image) {
                                        normalized.swatch_image = option.swatch_image;
                                    }
                                    return normalized;
                                })
                            };
                        });
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '请检查新建商品信息'));
                    return;
                }

                setBusy([submit], true);
                ensureCreateSlugAvailableForSubmit().then(function (slugOk) {
                    if (!slugOk) {
                        setBusy([submit], false);
                        notify('error', '前台 URL Handle 不可用或格式无效，请更换后再提交');
                        var focusSlug = document.getElementById('product-create-slug');
                        if (focusSlug instanceof HTMLInputElement) {
                            focusSlug.focus();
                        }
                        return null;
                    }
                    var createStatusIntent = payload.status === 'published' ? 'published' : 'draft';
                    return commandEnvelope(
                        'create',
                        parseInt(state.website_id, 10) || 0,
                        null,
                        null,
                        payload
                    ).then(function (command) {
                        return call('command', {command: command}).then(function (result) {
                            return {result: result, createStatusIntent: createStatusIntent};
                        });
                    });
                }).then(function (bundle) {
                    if (!bundle) {
                        return;
                    }
                    var result = bundle.result;
                    var createStatusIntent = bundle.createStatusIntent;
                    if (!result.success) {
                        throw new Error(result.message || '创建商品失败');
                    }
                    var identity = result.data && result.data.identity ? result.data.identity : {};
                    var uuid = identity.global_product_uuid || '';
                    if (!uuid) {
                        throw new Error('商品已创建，但返回结果缺少商品身份');
                    }
                    // Clear draft only after create(+publish) fully succeeds — keep form recoverable on publish validation failure.
                    var afterCreate = Promise.resolve(result);
                    if (createStatusIntent === 'published') {
                        afterCreate = afterCreate.then(function () {
                            return commandEnvelope(
                                'publish',
                                parseInt(state.website_id, 10) || 0,
                                uuid,
                                identity.version === undefined ? null : parseInt(identity.version, 10),
                                {local_version: 0}
                            ).then(function (command) {
                                return call('command', {command: command});
                            }).then(function (publishResult) {
                                if (!publishResult.success) {
                                    var diagnostics = publishResult.data && publishResult.data.diagnostics
                                        ? publishResult.data.diagnostics
                                        : null;
                                    var detail = diagnosticsMessage(
                                        diagnostics,
                                        publishResult.message || '商品已创建，但发布失败，请在编辑页重试发布'
                                    );
                                    var publishError = new Error(detail);
                                    publishError.result = publishResult;
                                    throw publishError;
                                }
                                return publishResult;
                            });
                        });
                    }
                    return afterCreate.then(function () {
                        clearProductCreateDraftLocal();
                        clearCreateWizardStep();
                        window.__welineCreateEavUserEdited = false;
                        window.__welineCreateEavDraftApplied = false;
                        return clearProductCreateDraftRemote();
                    }).then(function () {
                        var url = new URL(state.edit_url, window.location.origin);
                        url.searchParams.set('website_id', String(state.website_id || 0));
                        url.searchParams.set('global_product_uuid', uuid);
                        window.location.assign(url.toString());
                    });
                }).catch(function (error) {
                    var failed = error && error.result ? error.result : null;
                    var diagnostics = failed && failed.data && failed.data.diagnostics
                        ? failed.data.diagnostics
                        : null;
                    notify(
                        'error',
                        diagnosticsMessage(diagnostics, messageFrom(error, '创建商品失败'))
                    );
                    setBusy([submit], false);
                });
            });

            var createStatusSelect = document.getElementById('product-create-status');
            if (createStatusSelect) {
                createStatusSelect.addEventListener('change', syncCreateSubmitButtonLabel);
            }
            syncCreateSubmitButtonLabel();
        }

        root.querySelectorAll('[data-product-action="refresh"]').forEach(function (button) {
            button.addEventListener('click', function () {
                window.location.reload();
            });
        });

        initializeCatalogBulkSelection();
        initializeCatalogCreateFocusLayout();
        initializeEavStructureDrawer();
        initializeCreateWizard();
        initializeCreateVariantImagePicker();

        var initialSetIdInput = document.getElementById('product-create-attribute-set-id');
        var initialSetCodeInput = document.getElementById('product-create-attribute-set-code');
        var initialSetId = initialSetIdInput ? String(initialSetIdInput.value || '').trim() : '';
        if (initialSetId === '' && initialSetCodeInput) {
            initialSetId = String(initialSetCodeInput.value || '').trim();
        }
        if (initialSetId !== '') {
            renderCreateEavFields(initialSetId);
        }
    }

    var PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY = 'weline.product.catalog.panel_mode';

    function readCatalogPanelMode() {
        try {
            var raw = String(window.localStorage.getItem(PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY) || '').trim();
            if (raw === 'create' || raw === 'list') {
                return raw;
            }
        } catch (_error) {
        }
        return 'balanced';
    }

    function writeCatalogPanelMode(mode) {
        try {
            if (mode === 'create' || mode === 'list') {
                window.localStorage.setItem(PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY, mode);
                return;
            }
            window.localStorage.removeItem(PRODUCT_CATALOG_PANEL_MODE_STORAGE_KEY);
        } catch (_error) {
        }
    }

    function initializeCatalogCreateFocusLayout() {
        var grid = root.querySelector('[data-product-admin-grid]') || root.querySelector('.w-product-admin__grid');
        var createPanel = root.querySelector('[data-product-create-panel]') || root.querySelector('.w-product-create');
        var listPanel = root.querySelector('[data-product-list-panel]') || root.querySelector('.w-product-list');
        if (!grid || !createPanel) {
            return;
        }

        var pinnedMode = readCatalogPanelMode();
        // If the create wizard has a remembered step, pin create so refresh lands on the work site.
        if (pinnedMode !== 'create' && readCreateWizardStep() !== '') {
            pinnedMode = 'create';
            writeCatalogPanelMode(pinnedMode);
        }

        function updateFocusButtons() {
            root.querySelectorAll('[data-product-panel-focus]').forEach(function (button) {
                var target = button.getAttribute('data-product-panel-focus');
                var active = (target === 'create' && pinnedMode === 'create')
                    || (target === 'list' && pinnedMode === 'list');
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function applyCatalogPanelMode() {
            var createFocused = pinnedMode === 'create';
            var listFocused = pinnedMode === 'list';

            grid.classList.toggle('is-create-focused', createFocused);
            grid.classList.toggle('is-create-pinned', createFocused);
            grid.classList.toggle('is-list-focused', listFocused);
            grid.classList.toggle('is-list-pinned', listFocused);

            createPanel.classList.toggle('is-focused', createFocused);
            if (listPanel) {
                listPanel.classList.toggle('is-focused', listFocused);
            }
            root.classList.toggle('is-create-focused', createFocused);
            root.classList.toggle('is-list-focused', listFocused);

            updateFocusButtons();
        }

        function setCatalogPanelMode(mode, options) {
            options = options || {};
            pinnedMode = mode === 'list' ? 'list' : (mode === 'create' ? 'create' : 'balanced');
            writeCatalogPanelMode(pinnedMode);
            applyCatalogPanelMode();

            if (options.skipScroll) {
                return;
            }

            if (pinnedMode === 'create') {
                createPanel.scrollIntoView({block: 'nearest', behavior: 'smooth'});
                resumeCreateWizardStep({silent: true});
            } else if (pinnedMode === 'list' && listPanel) {
                listPanel.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }
        }

        root.querySelectorAll('[data-product-panel-focus]').forEach(function (button) {
            button.addEventListener('click', function () {
                var target = button.getAttribute('data-product-panel-focus');
                setCatalogPanelMode(target === 'list' ? 'list' : 'create');
            });
        });

        // Restore last pinned create/list mode across refresh until the user switches.
        applyCatalogPanelMode();
        if (pinnedMode === 'create') {
            resumeCreateWizardStep({silent: true});
        }
    }

    function initializeCatalogBulkSelection() {
        var datatableRoot = document.getElementById('w-datatable-product-catalog-list');
        if (!datatableRoot) {
            return;
        }

        var bulkBar = root.querySelector('[data-product-bulk-bar]');
        var bulkSummary = root.querySelector('[data-product-bulk-summary]');
        var bulkDelete = root.querySelector('[data-product-bulk-action="archive"]');
        var localRowsEl = document.getElementById('product-catalog-local-rows');
        var rowIndex = {};

        function rebuildRowIndex() {
            rowIndex = {};
            if (!localRowsEl) {
                return;
            }
            try {
                var rows = JSON.parse(localRowsEl.textContent || '[]');
                if (!Array.isArray(rows)) {
                    return;
                }
                rows.forEach(function (row) {
                    if (!row || typeof row !== 'object') {
                        return;
                    }
                    var uuid = String(row.global_product_uuid || '');
                    if (uuid !== '') {
                        rowIndex[uuid] = row;
                    }
                });
            } catch (error) {
                rowIndex = {};
            }
        }

        rebuildRowIndex();

        function rowPayload(uuid) {
            var row = rowIndex[uuid] || {};
            return {
                global_product_uuid: uuid,
                product_id: parseInt(row.product_id, 10) || 0,
                identity_version: parseInt(row.identity_version, 10) || 0,
                local_version: parseInt(row.local_version, 10) || 0,
            };
        }

        function selectedUuids() {
            return Array.prototype.map.call(
                datatableRoot.querySelectorAll('.w-datatable__row-select:checked'),
                function (checkbox) {
                    return checkbox instanceof HTMLInputElement ? String(checkbox.value || '') : '';
                },
            ).filter(function (uuid) {
                return uuid !== '';
            });
        }

        function getSelection() {
            return selectedUuids().map(rowPayload);
        }

        function updateBulkUi() {
            var items = getSelection();
            var count = items.length;
            if (bulkBar instanceof HTMLElement) {
                bulkBar.hidden = count === 0;
            }
            if (bulkSummary instanceof HTMLElement) {
                bulkSummary.textContent = count > 0
                    ? ('已选择 ' + count + ' 个商品')
                    : '';
            }
            if (bulkDelete instanceof HTMLButtonElement) {
                bulkDelete.disabled = count === 0;
            }
            document.dispatchEvent(new CustomEvent('weline:product:catalog:selection-change', {
                bubbles: true,
                detail: {
                    count: count,
                    items: items,
                    website_id: parseInt(state.website_id, 10) || 0,
                },
            }));
        }

        function bulkArchive() {
            var items = getSelection();
            if (items.length === 0) {
                return;
            }
            confirmTheme(
                '按治理规则将归档（非物理删除），归档后不可再编辑。',
                {
                    title: '确定删除选中的 ' + items.length + ' 个商品吗？',
                    tone: 'warning',
                    dangerous: true,
                    confirmTone: 'danger',
                    confirmLabel: '确定',
                    cancelLabel: '取消',
                }
            ).then(function (ok) {
                if (!ok) {
                    return;
                }
                setBusy([bulkDelete], true);
                requestHash('bulk:archive', {
                    website_id: parseInt(state.website_id, 10) || 0,
                    items: items.map(function (item) { return item.global_product_uuid; }),
                }).then(function (baseHash) {
                    return call('bulkCommand', {
                        website_id: parseInt(state.website_id, 10) || 0,
                        action: 'archive',
                        base_request_hash: baseHash,
                        items: items.map(function (item) {
                            return {
                                global_product_uuid: item.global_product_uuid,
                                product_id: item.product_id,
                                expected_version: item.identity_version,
                                local_version: item.local_version,
                            };
                        }),
                    });
                }).then(function (result) {
                    if (!result.success) {
                        throw new Error(result.message || '批量删除失败');
                    }
                    var data = result.data || {};
                    var failed = parseInt(data.failed, 10) || 0;
                    var succeeded = parseInt(data.succeeded, 10) || 0;
                    if (failed > 0 && succeeded === 0) {
                        throw new Error(result.message || '批量删除失败');
                    }
                    if (failed > 0) {
                        notify('warning', result.message || ('部分删除完成：成功 ' + succeeded + '，失败 ' + failed));
                    } else {
                        notify('success', result.message || '批量删除完成');
                    }
                    window.location.reload();
                }).catch(function (error) {
                    notify('error', messageFrom(error, '批量删除失败'));
                }).finally(function () {
                    setBusy([bulkDelete], false);
                });
            });
        }

        datatableRoot.addEventListener('change', function (event) {
            if (!(event.target instanceof HTMLInputElement)) {
                return;
            }
            if (event.target.matches('[data-w-datatable-select-all]')
                || event.target.classList.contains('w-datatable__row-select')
            ) {
                updateBulkUi();
            }
        });

        if (bulkDelete instanceof HTMLButtonElement) {
            bulkDelete.addEventListener('click', bulkArchive);
        }

        window.WelineProductAdminCatalog = {
            getSelection: getSelection,
            getWebsiteId: function () {
                return parseInt(state.website_id, 10) || 0;
            },
        };

        updateBulkUi();
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

    function catalogAttributeByCode(code) {
        var normalized = String(code || '').trim().toLowerCase();
        if (!normalized) {
            return null;
        }
        var sets = catalogSetsFromPayload(
            (state.snapshot && state.snapshot.attribute_catalog)
                || (state.creation_context && state.creation_context.attribute_catalog)
                || []
        );
        for (var setIndex = 0; setIndex < sets.length; setIndex += 1) {
            var set = sets[setIndex];
            var groups = Array.isArray(set && set.groups) ? set.groups : [];
            for (var groupIndex = 0; groupIndex < groups.length; groupIndex += 1) {
                var attributes = Array.isArray(groups[groupIndex] && groups[groupIndex].attributes)
                    ? groups[groupIndex].attributes
                    : [];
                for (var attributeIndex = 0; attributeIndex < attributes.length; attributeIndex += 1) {
                    var attribute = attributes[attributeIndex];
                    var attributeCode = String(
                        (attribute && (attribute.code || attribute.attribute_code)) || ''
                    ).trim().toLowerCase();
                    if (attributeCode === normalized) {
                        return attribute;
                    }
                }
            }
        }
        return null;
    }

    function normalizeVariantAxisOptionMeta(option, optionIndex) {
        if (option && typeof option === 'object' && !Array.isArray(option)) {
            var value = String(option.value || option.code || option.option_id || '').trim();
            var label = String(option.label || option.name || value).trim();
            var swatchColor = String(option.swatch_color || option.swatch || '').trim();
            var swatchImage = String(option.swatch_image || '').trim();
            return {
                value: value,
                label: label !== '' ? label : value,
                swatch_color: swatchColor,
                swatch_image: swatchImage
            };
        }
        var scalar = String(option == null ? '' : option).trim();
        return {
            value: scalar,
            label: scalar,
            swatch_color: '',
            swatch_image: '',
            option_index: optionIndex
        };
    }

    function buildVariantAxisOptionPool(code, selectedOptions) {
        var pool = [];
        var seen = {};
        var attribute = catalogAttributeByCode(code);
        var catalogOptions = Array.isArray(attribute && attribute.options) ? attribute.options : [];
        catalogOptions.forEach(function (option, optionIndex) {
            var normalized = normalizeVariantAxisOptionMeta(option, optionIndex);
            if (!normalized.value || seen[normalized.value.toLowerCase()]) {
                return;
            }
            seen[normalized.value.toLowerCase()] = true;
            pool.push(normalized);
        });
        (selectedOptions || []).forEach(function (option, optionIndex) {
            var normalized = normalizeVariantAxisOptionMeta(option, optionIndex);
            if (!normalized.value || seen[normalized.value.toLowerCase()]) {
                return;
            }
            seen[normalized.value.toLowerCase()] = true;
            pool.push(normalized);
        });
        return pool;
    }

    function buildVariantAxisOptionChipHtml(option, checked, editable, axisCode, optionIndex) {
        var normalized = normalizeVariantAxisOptionMeta(option, optionIndex);
        var chipId = 'product-edit-axis-' + String(axisCode || 'axis') + '-' + String(optionIndex);
        chipId = chipId.replace(/[^a-zA-Z0-9_-]+/g, '-');
        var swatchHtml = normalized.swatch_image !== ''
            ? '<img class="w-product-create__axis-chip-image" src="' + escapeHtml(normalized.swatch_image) + '" alt="">'
            : (normalized.swatch_color !== ''
                ? '<span class="w-product-create__axis-chip-swatch" style="background:'
                    + escapeHtml(normalized.swatch_color) + '"></span>'
                : '');
        return '<label class="w-product-create__axis-chip" for="' + escapeHtml(chipId) + '">'
            + '<input type="checkbox" id="' + escapeHtml(chipId) + '" data-variant-axis-option value="'
            + escapeHtml(normalized.value) + '" data-option-label="' + escapeHtml(normalized.label) + '"'
            + ' data-option-code="' + escapeHtml(normalized.value) + '"'
            + (normalized.swatch_color !== ''
                ? ' data-option-swatch-color="' + escapeHtml(normalized.swatch_color) + '"'
                : '')
            + (normalized.swatch_image !== ''
                ? ' data-option-swatch-image="' + escapeHtml(normalized.swatch_image) + '"'
                : '')
            + (checked ? ' checked' : '')
            + (editable ? '' : ' disabled') + '>'
            + swatchHtml + '<span>' + escapeHtml(normalized.label) + '</span></label>';
    }

    function selectedVariantAxisOptions(row) {
        return Array.prototype.map.call(
            row.querySelectorAll('[data-variant-axis-option]:checked'),
            function (input) {
                return {
                    value: String(input.value || ''),
                    label: String(input.getAttribute('data-option-label') || input.value || ''),
                    swatch_color: String(input.getAttribute('data-option-swatch-color') || '').trim(),
                    swatch_image: String(input.getAttribute('data-option-swatch-image') || '').trim()
                };
            }
        ).filter(function (option) {
            return option.value !== '';
        });
    }


    function axisClampLimitPx(clamp) {
        var raw = getComputedStyle(clamp).getPropertyValue('--product-axis-clamp-max').trim();
        var probe = document.createElement('div');
        probe.style.cssText = 'position:absolute;visibility:hidden;height:' + (raw || '5.75rem');
        clamp.appendChild(probe);
        var px = probe.getBoundingClientRect().height;
        probe.remove();
        return px > 0 ? px : 92;
    }

    function refreshVariantAxisOptionClamp(clamp) {
        if (!clamp) {
            return;
        }
        var body = clamp.querySelector('.w-product-variant__axis-clamp-body');
        var more = clamp.querySelector('[data-variant-axis-option-more]');
        if (!body || !more) {
            return;
        }
        var labelMore = more.getAttribute('data-label-more') || '展示更多';
        var labelLess = more.getAttribute('data-label-less') || '收起';
        var overflow = body.scrollHeight > axisClampLimitPx(clamp) + 2;
        var collapsed = clamp.getAttribute('data-collapsed') !== '0';
        if (!overflow) {
            clamp.classList.remove('is-overflow');
            clamp.setAttribute('data-collapsed', '1');
            more.hidden = true;
            more.textContent = labelMore;
            more.setAttribute('aria-expanded', 'false');
            return;
        }
        clamp.classList.add('is-overflow');
        more.hidden = false;
        if (collapsed) {
            clamp.setAttribute('data-collapsed', '1');
            more.textContent = labelMore;
            more.setAttribute('aria-expanded', 'false');
        } else {
            more.textContent = labelLess;
            more.setAttribute('aria-expanded', 'true');
        }
    }

    function setVariantAxisOptionCollapsed(clamp, collapsed) {
        if (!clamp) {
            return;
        }
        var more = clamp.querySelector('[data-variant-axis-option-more]');
        var labelMore = more ? (more.getAttribute('data-label-more') || '展示更多') : '展示更多';
        var labelLess = more ? (more.getAttribute('data-label-less') || '收起') : '收起';
        clamp.setAttribute('data-collapsed', collapsed ? '1' : '0');
        if (more) {
            more.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            more.textContent = collapsed ? labelMore : labelLess;
        }
        if (!collapsed) {
            clamp.classList.add('is-overflow');
            if (more) {
                more.hidden = false;
            }
            return;
        }
        refreshVariantAxisOptionClamp(clamp);
    }

    function bindVariantAxisOptionClamp(row) {
        var clamp = row ? row.querySelector('[data-variant-axis-option-clamp]') : null;
        if (!clamp || clamp.getAttribute('data-clamp-bound') === '1') {
            return clamp;
        }
        clamp.setAttribute('data-clamp-bound', '1');
        var more = clamp.querySelector('[data-variant-axis-option-more]');
        var body = clamp.querySelector('.w-product-variant__axis-clamp-body');
        if (more) {
            more.addEventListener('click', function () {
                setVariantAxisOptionCollapsed(clamp, clamp.getAttribute('data-collapsed') === '0');
            });
        }
        if (typeof ResizeObserver === 'function' && body) {
            var frame = 0;
            var observer = new ResizeObserver(function () {
                if (frame) {
                    return;
                }
                frame = window.requestAnimationFrame(function () {
                    frame = 0;
                    refreshVariantAxisOptionClamp(clamp);
                });
            });
            observer.observe(body);
        }
        refreshVariantAxisOptionClamp(clamp);
        return clamp;
    }

    function refreshVariantAxisOptionClampForRow(row) {
        var clamp = bindVariantAxisOptionClamp(row);
        refreshVariantAxisOptionClamp(clamp);
    }

    function renderVariantAxisOptionGrid(row, code, selectedOptions, editable) {
        var grid = row.querySelector('[data-variant-axis-option-grid]');
        if (!grid) {
            return;
        }
        var selectedMap = {};
        (selectedOptions || []).forEach(function (option) {
            var normalized = normalizeVariantAxisOptionMeta(option);
            if (normalized.value) {
                selectedMap[normalized.value.toLowerCase()] = normalized;
            }
        });
        var pool = buildVariantAxisOptionPool(code, selectedOptions || []);
        grid.innerHTML = pool.map(function (option, optionIndex) {
            var checked = Boolean(selectedMap[String(option.value).toLowerCase()]);
            return buildVariantAxisOptionChipHtml(option, checked, editable, code, optionIndex);
        }).join('');
        refreshVariantAxisOptionClampForRow(row);
    }

    function buildVariantCombinationChips(combination, axes) {
        var wrap = document.createElement('div');
        wrap.className = 'w-product-variant__combo-chips';
        Object.keys(combination || {}).forEach(function (axisCode) {
            var value = String(combination[axisCode] || '');
            var axis = (axes || []).find(function (item) {
                return item.code === axisCode;
            });
            var option = axis && Array.isArray(axis.options)
                ? axis.options.find(function (item) {
                    return String(item.value) === value;
                })
                : null;
            var catalog = catalogAttributeByCode(axisCode);
            var catalogOptions = Array.isArray(catalog && catalog.options) ? catalog.options : [];
            var catalogMatch = catalogOptions.map(function (item, optionIndex) {
                return normalizeVariantAxisOptionMeta(item, optionIndex);
            }).find(function (normalized) {
                return normalized.value === value;
            });
            if (!option) {
                option = catalogMatch || {value: value, label: value};
            } else if (catalogMatch) {
                var currentLabel = String(option.label || option.value || '');
                if (currentLabel === '' || currentLabel === value) {
                    option = Object.assign({}, option, {
                        label: catalogMatch.label || currentLabel || value,
                        swatch_color: option.swatch_color || catalogMatch.swatch_color,
                        swatch_image: option.swatch_image || catalogMatch.swatch_image
                    });
                } else {
                    option = Object.assign({}, option, {
                        swatch_color: option.swatch_color || catalogMatch.swatch_color,
                        swatch_image: option.swatch_image || catalogMatch.swatch_image
                    });
                }
            }
            var chip = document.createElement('span');
            chip.className = 'w-product-variant__combo-chip';
            chip.title = axisCode + '=' + value;
            var label = String((option && (option.label || option.value)) || value);
            var swatchColor = String((option && (option.swatch_color || option.swatch)) || '').trim();
            var swatchImage = String((option && option.swatch_image) || '').trim();
            if (swatchImage !== '') {
                var image = document.createElement('img');
                image.className = 'w-product-create__axis-chip-image';
                image.src = swatchImage;
                image.alt = '';
                chip.appendChild(image);
            } else if (swatchColor !== '') {
                var swatch = document.createElement('span');
                swatch.className = 'w-product-create__axis-chip-swatch';
                swatch.style.background = swatchColor;
                chip.appendChild(swatch);
            }
            var text = document.createElement('span');
            text.textContent = label;
            chip.appendChild(text);
            wrap.appendChild(chip);
        });
        return wrap;
    }

    function variantAxes(matrix) {
        var seenAxes = {};
        return Array.prototype.map.call(
            matrix.querySelectorAll('[data-product-variant-axis]'),
            function (row) {
                var codeInput = row.querySelector('[data-variant-axis-code]');
                var code = String(codeInput ? codeInput.value : '').trim().toLowerCase();
                if (!/^[a-z][a-z0-9_]{0,63}$/.test(code)) {
                    throw new Error('规格轴代码必须以字母开头，只能包含小写字母、数字和下划线');
                }
                if (seenAxes[code]) {
                    throw new Error('规格轴代码不能重复：' + code);
                }
                seenAxes[code] = true;
                var seenOptions = {};
                var options = selectedVariantAxisOptions(row).map(function (option) {
                    var value = String(option.value || '').trim();
                    var identity = value.toLowerCase();
                    if (value === '' || value.length > 128 || seenOptions[identity]) {
                        throw new Error('规格值不能为空、重复或超过 128 个字符：' + value);
                    }
                    seenOptions[identity] = true;
                    var normalized = {
                        value: value,
                        label: String(option.label || value)
                    };
                    if (option.swatch_color) {
                        normalized.swatch_color = option.swatch_color;
                        normalized.swatch = option.swatch_color;
                    }
                    if (option.swatch_image) {
                        normalized.swatch_image = option.swatch_image;
                    }
                    return normalized;
                });
                if (options.length === 0) {
                    throw new Error('每个规格轴至少需要一个规格值');
                }
                var attribute = catalogAttributeByCode(code);
                var labelNode = row.querySelector('[data-variant-axis-attr-label], .w-product-variant__axis-attr-label');
                var axisLabel = String((labelNode && labelNode.textContent) || '').trim();
                return {
                    code: code,
                    label: axisLabel || String((attribute && (attribute.name || attribute.label)) || code),
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

    function compactVariantSkuPart(value) {
        var part = String(value).toUpperCase().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        if (!part) {
            return fallbackVariantPart(value);
        }
        if (part.length <= 12) {
            return part;
        }
        return part.slice(0, 7).replace(/-+$/g, '') + '-' + fallbackVariantPart(value).slice(-4);
    }

    function generatedVariantSku(prefix, combination, axes) {
        var parts = [prefix];
        axes.forEach(function (axis) {
            var value = String(combination[axis.code] || '');
            parts.push(compactVariantSkuPart(value));
        });
        var sku = parts.join('-');
        if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(sku)) {
            throw new Error('生成的 SKU 不合法或超过 128 个字符，请缩短 SKU 前缀或规格值');
        }
        return sku;
    }

    function variantImagePlaceholderUrl(matrix) {
        var root = matrix || document.querySelector('[data-product-offer-matrix]');
        return String((root && root.getAttribute('data-variant-image-placeholder')) || '').trim();
    }

    function buildVariantOfferImageCell(existing, combination, axes, matrix) {
        var cell = document.createElement('td');
        cell.className = 'w-product-variant__image-cell';
        cell.setAttribute('data-variant-offer-image-cell', '');
        var imageUrl = String((existing && existing.image_url) || '').trim();
        var isPlaceholder = !!(existing && (
            existing.image_is_placeholder === true
            || existing.image_is_placeholder === 1
            || existing.image_is_placeholder === '1'
        ));
        if (imageUrl === '' || isPlaceholder) {
            var preview = resolveCombinationPreview(combination, axes);
            var previewUrl = String(preview.image || '').trim();
            if (previewUrl !== '') {
                imageUrl = previewUrl;
                isPlaceholder = false;
            }
        }
        if (imageUrl === '') {
            imageUrl = variantImagePlaceholderUrl(matrix);
            isPlaceholder = true;
        }
        var image = document.createElement('img');
        image.className = 'w-product-create__variant-preview-thumb'
            + (isPlaceholder ? ' is-placeholder' : '');
        image.setAttribute('data-variant-offer-image', '');
        if (isPlaceholder) {
            image.setAttribute('data-variant-offer-image-placeholder', '');
        }
        image.src = imageUrl;
        image.alt = '';
        cell.appendChild(image);
        return cell;
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
            scope_state: priceRaw === '' ? 'cleared' : 'explicit',
            image_url: String(row.getAttribute('data-variant-image-url') || '').trim(),
            image_is_placeholder: String(row.getAttribute('data-variant-image-is-placeholder') || '') === '1',
            image_asset_id: String(row.getAttribute('data-variant-image-asset-id') || '').trim()
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
            row.setAttribute('data-variant-image-url', String(value.image_url || ''));
            row.setAttribute(
                'data-variant-image-is-placeholder',
                value.image_is_placeholder === true
                    || value.image_is_placeholder === 1
                    || value.image_is_placeholder === '1'
                    ? '1'
                    : '0'
            );
            row.setAttribute('data-variant-image-asset-id', String(value.image_asset_id || ''));

            appendVariantCell(row, buildVariantOfferImageCell(value, generated.combination, axes, matrix));

            appendVariantCell(row, buildVariantCombinationChips(generated.combination, axes));

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

    function listCatalogVariantAxisChoices(matrix) {
        var used = {};
        matrix.querySelectorAll('[data-variant-axis-code]').forEach(function (input) {
            var code = String(input.value || '').trim().toLowerCase();
            if (code) {
                used[code] = true;
            }
        });
        var choices = [];
        var sets = catalogSetsFromPayload(
            (state.snapshot && state.snapshot.attribute_catalog)
                || (state.creation_context && state.creation_context.attribute_catalog)
                || []
        );
        sets.forEach(function (set) {
            var groups = Array.isArray(set && set.groups) ? set.groups : [];
            groups.forEach(function (group) {
                var attributes = Array.isArray(group && group.attributes) ? group.attributes : [];
                attributes.forEach(function (attribute) {
                    var code = String((attribute && (attribute.code || attribute.attribute_code)) || '')
                        .trim()
                        .toLowerCase();
                    if (!code || used[code] || !/^[a-z][a-z0-9_]{0,63}$/.test(code)) {
                        return;
                    }
                    var options = Array.isArray(attribute.options) ? attribute.options : [];
                    if (options.length === 0) {
                        return;
                    }
                    choices.push({
                        code: code,
                        label: String(attribute.name || attribute.label || code)
                    });
                    used[code] = true;
                });
            });
        });
        choices.sort(function (left, right) {
            return String(left.label).localeCompare(String(right.label), 'zh');
        });
        return choices;
    }

    function bindVariantAxisAttrShell(row, matrix, editable) {
        var hidden = row.querySelector('[data-variant-axis-code]');
        var labelEl = row.querySelector('[data-variant-axis-attr-label]');
        var codeEl = row.querySelector('[data-variant-axis-attr-code]');
        var select = row.querySelector('[data-variant-axis-code-select]');
        if (!hidden || !select) {
            return;
        }
        select.addEventListener('change', function () {
            var code = String(select.value || '').trim().toLowerCase();
            var option = select.options[select.selectedIndex];
            var label = String((option && option.textContent) || code).trim();
            if (!code) {
                return;
            }
            hidden.value = code;
            if (labelEl) {
                labelEl.textContent = label || code;
            }
            if (codeEl) {
                codeEl.textContent = code;
                codeEl.hidden = !code || String(label).toLowerCase() === code;
            }
            select.hidden = true;
            renderVariantAxisOptionGrid(row, code, selectedVariantAxisOptions(row), editable);
        });
    }

    function createVariantAxisEditor(matrix) {
        var editable = matrix.getAttribute('data-can-edit-structure') === '1';
        var row = document.createElement('div');
        row.className = 'w-product-variant__axis';
        row.setAttribute('data-product-variant-axis', '');

        var attr = document.createElement('div');
        attr.className = 'w-product-variant__axis-attr';
        attr.setAttribute('data-variant-axis-attr', '');

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.setAttribute('data-variant-axis-code', '');
        attr.appendChild(hidden);

        var labelEl = document.createElement('span');
        labelEl.className = 'w-product-variant__axis-attr-label';
        labelEl.setAttribute('data-variant-axis-attr-label', '');
        labelEl.textContent = '选择属性';
        attr.appendChild(labelEl);

        var codeEl = document.createElement('code');
        codeEl.className = 'w-product-variant__axis-attr-code';
        codeEl.setAttribute('data-variant-axis-attr-code', '');
        codeEl.hidden = true;
        attr.appendChild(codeEl);

        if (editable) {
            var select = document.createElement('select');
            select.className = 'w-select';
            select.setAttribute('data-variant-axis-code-select', '');
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '选择规格属性';
            select.appendChild(placeholder);
            listCatalogVariantAxisChoices(matrix).forEach(function (choice) {
                var option = document.createElement('option');
                option.value = choice.code;
                option.textContent = choice.label;
                select.appendChild(option);
            });
            attr.appendChild(select);
        }
        row.appendChild(attr);

        var optionsWrap = document.createElement('div');
        optionsWrap.className = 'w-product-variant__axis-options';
        var labels = {
            more: String(matrix.getAttribute('data-label-axis-more') || '展示更多'),
            less: String(matrix.getAttribute('data-label-axis-less') || '收起')
        };
        var clamp = document.createElement('div');
        clamp.className = 'w-product-variant__axis-clamp';
        clamp.setAttribute('data-variant-axis-option-clamp', '');
        clamp.setAttribute('data-collapsed', '1');
        clamp.setAttribute('data-testid', 'product-variant-axis-clamp');
        var clampBody = document.createElement('div');
        clampBody.className = 'w-product-variant__axis-clamp-body';
        var grid = document.createElement('div');
        grid.className = 'w-product-create__axis-chip-grid';
        grid.setAttribute('data-variant-axis-option-grid', '');
        clampBody.appendChild(grid);
        clamp.appendChild(clampBody);
        var moreBtn = document.createElement('button');
        moreBtn.type = 'button';
        moreBtn.className = 'w-product-variant__axis-more';
        moreBtn.setAttribute('data-variant-axis-option-more', '');
        moreBtn.setAttribute('data-testid', 'product-variant-axis-more');
        moreBtn.setAttribute('data-label-more', labels.more);
        moreBtn.setAttribute('data-label-less', labels.less);
        moreBtn.hidden = true;
        moreBtn.setAttribute('aria-expanded', 'false');
        moreBtn.textContent = labels.more;
        clamp.appendChild(moreBtn);
        optionsWrap.appendChild(clamp);
        if (editable) {
            var custom = document.createElement('input');
            custom.className = 'w-input';
            custom.setAttribute('data-variant-axis-option-custom', '');
            custom.placeholder = '自定义规格值，回车填写属性项';
            optionsWrap.appendChild(custom);
        }
        var help = document.createElement('span');
        help.className = 'w-field__help';
        help.textContent = '回车填写属性项（颜色/图片等随属性能力显示）；勾选规格值生成矩阵。';
        optionsWrap.appendChild(help);
        row.appendChild(optionsWrap);

        var remove = document.createElement('button');
        remove.className = 'w-button';
        remove.type = 'button';
        remove.setAttribute('data-product-variant-axis-remove', '');
        remove.textContent = '移除';
        remove.disabled = !editable;
        row.appendChild(remove);
        bindVariantAxisAttrShell(row, matrix, editable);
        return row;
    }

    var variantOptionDialogContext = null;
    var variantOptionValueSyncedFromLabel = true;

    function normalizeVariantOptionHexColor(raw) {
        var value = String(raw || '').trim();
        if (value === '') {
            return '';
        }
        if (/^#[0-9a-fA-F]{3}$/.test(value)) {
            return '#' + value[1] + value[1] + value[2] + value[2] + value[3] + value[3];
        }
        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
            return value.toLowerCase();
        }
        if (/^[0-9a-fA-F]{6}$/.test(value)) {
            return ('#' + value).toLowerCase();
        }
        return '';
    }

    function syncVariantOptionImagePreview(dialog) {
        if (!dialog) {
            return;
        }
        var imageInput = dialog.querySelector('[data-product-variant-option-swatch-image]');
        var preview = dialog.querySelector('[data-product-variant-option-swatch-image-preview]');
        var clearBtn = dialog.querySelector('[data-product-variant-option-image-clear]');
        var url = String(imageInput ? imageInput.value : '').trim();
        if (preview) {
            if (url !== '') {
                preview.src = url;
                preview.hidden = false;
            } else {
                preview.removeAttribute('src');
                preview.hidden = true;
            }
        }
        if (clearBtn) {
            clearBtn.hidden = url === '';
        }
    }

    function closeVariantAxisOptionDialog() {
        var dialog = root.querySelector('[data-product-variant-option-dialog]');
        variantOptionDialogContext = null;
        variantOptionValueSyncedFromLabel = true;
        if (dialog) {
            closeProductMediaDialog(dialog, 'product-variant-option');
        }
    }

    function openVariantAxisOptionDialog(row, draftLabel) {
        var dialog = root.querySelector('[data-product-variant-option-dialog]');
        var form = root.querySelector('[data-product-variant-option-form]');
        if (!dialog || !form || !row) {
            return;
        }
        var codeInput = row.querySelector('[data-variant-axis-code]');
        var code = String(codeInput ? codeInput.value : '').trim().toLowerCase();
        if (code === '') {
            notify('warning', '请先选择规格属性');
            return;
        }
        var axisLabelEl = row.querySelector('[data-variant-axis-attr-label]');
        var axisLabel = String(axisLabelEl ? axisLabelEl.textContent : code).trim() || code;
        var attribute = catalogAttributeByCode(code);
        var caps = attributeSwatchCapabilities(attribute);
        if (code === 'color' || code === 'style_type') {
            caps = {
                color: true,
                image: true,
                text: false
            };
        }
        var hint = dialog.querySelector('[data-product-variant-option-axis-hint]');
        var labelInput = dialog.querySelector('[data-product-variant-option-label]');
        var valueInput = dialog.querySelector('[data-product-variant-option-value]');
        var colorField = dialog.querySelector('[data-product-variant-option-color-field]');
        var colorInput = dialog.querySelector('[data-product-variant-option-swatch-color]');
        var colorText = dialog.querySelector('[data-product-variant-option-swatch-color-text]');
        var imageField = dialog.querySelector('[data-product-variant-option-image-field]');
        var imageInput = dialog.querySelector('[data-product-variant-option-swatch-image]');
        var draft = String(draftLabel || '').trim();

        variantOptionDialogContext = {
            row: row,
            code: code,
            caps: caps
        };
        if (hint) {
            hint.textContent = '属性：' + axisLabel + '（' + code + '）';
        }
        if (labelInput) {
            labelInput.value = draft;
        }
        if (valueInput) {
            valueInput.value = draft;
        }
        variantOptionValueSyncedFromLabel = true;
        if (colorField) {
            colorField.hidden = !caps.color;
        }
        if (imageField) {
            imageField.hidden = !caps.image;
        }
        if (colorInput) {
            colorInput.value = '#cccccc';
        }
        if (colorText) {
            colorText.value = caps.color ? '#cccccc' : '';
        }
        if (imageInput) {
            imageInput.value = '';
        }
        syncVariantOptionImagePreview(dialog);
        if (!openProductMediaDialog(dialog, {trigger: 'product-variant-option'})) {
            notify('error', '规格值填写弹窗不可用');
            variantOptionDialogContext = null;
            return;
        }
        window.setTimeout(function () {
            if (labelInput) {
                labelInput.focus();
                labelInput.select();
            }
        }, 0);
    }

    function commitVariantAxisOptionDialog(event) {
        if (event) {
            event.preventDefault();
        }
        var context = variantOptionDialogContext;
        var dialog = root.querySelector('[data-product-variant-option-dialog]');
        if (!context || !context.row || !dialog) {
            return;
        }
        var labelInput = dialog.querySelector('[data-product-variant-option-label]');
        var valueInput = dialog.querySelector('[data-product-variant-option-value]');
        var colorText = dialog.querySelector('[data-product-variant-option-swatch-color-text]');
        var colorInput = dialog.querySelector('[data-product-variant-option-swatch-color]');
        var imageInput = dialog.querySelector('[data-product-variant-option-swatch-image]');
        var label = String(labelInput ? labelInput.value : '').trim();
        var value = String(valueInput ? valueInput.value : '').trim();
        if (label === '') {
            notify('warning', '请填写显示名称');
            if (labelInput) {
                labelInput.focus();
            }
            return;
        }
        if (value === '') {
            value = label;
        }
        if (value.length > 128) {
            notify('warning', '规格值不能超过 128 个字符');
            return;
        }
        var caps = context.caps || {color: false, image: false, text: true};
        var swatchColor = '';
        if (caps.color) {
            swatchColor = normalizeVariantOptionHexColor(
                (colorText && colorText.value) || (colorInput && colorInput.value) || ''
            );
            if (swatchColor === '' && colorInput && colorInput.value) {
                swatchColor = normalizeVariantOptionHexColor(colorInput.value);
            }
        }
        var swatchImage = caps.image
            ? String(imageInput ? imageInput.value : '').trim()
            : '';
        var selected = selectedVariantAxisOptions(context.row);
        var identity = value.toLowerCase();
        var exists = selected.some(function (option) {
            return String(option.value).toLowerCase() === identity;
        });
        if (exists) {
            selected = selected.map(function (option) {
                if (String(option.value).toLowerCase() !== identity) {
                    return option;
                }
                return {
                    value: value,
                    label: label,
                    swatch_color: swatchColor,
                    swatch_image: swatchImage
                };
            });
        } else {
            selected.push({
                value: value,
                label: label,
                swatch_color: swatchColor,
                swatch_image: swatchImage
            });
        }
        renderVariantAxisOptionGrid(context.row, context.code, selected, true);
        var custom = context.row.querySelector('[data-variant-axis-option-custom]');
        if (custom) {
            custom.value = '';
        }
        closeVariantAxisOptionDialog();
    }

    function initializeVariantAxisOptionDialog() {
        var dialog = root.querySelector('[data-product-variant-option-dialog]');
        var form = root.querySelector('[data-product-variant-option-form]');
        if (!dialog || !form || dialog.getAttribute('data-variant-option-bound') === '1') {
            return;
        }
        dialog.setAttribute('data-variant-option-bound', '1');

        var labelInput = dialog.querySelector('[data-product-variant-option-label]');
        var valueInput = dialog.querySelector('[data-product-variant-option-value]');
        var colorInput = dialog.querySelector('[data-product-variant-option-swatch-color]');
        var colorText = dialog.querySelector('[data-product-variant-option-swatch-color-text]');
        var imageDialog = root.querySelector('[data-product-variant-option-image-dialog]');
        var imageFrame = root.querySelector('[data-product-variant-option-image-frame]');
        var imageOpen = dialog.querySelector('[data-product-variant-option-image-open]');
        var imageClear = dialog.querySelector('[data-product-variant-option-image-clear]');
        var imageClose = root.querySelector('[data-product-variant-option-image-close]');

        form.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229) {
                return;
            }
            var target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            if (target.matches('[data-product-variant-option-image-open], [data-product-variant-option-cancel]')) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            commitVariantAxisOptionDialog(event);
        });
        var confirmBtn = dialog.querySelector('[data-product-variant-option-confirm]');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function (event) {
                event.preventDefault();
                commitVariantAxisOptionDialog(event);
            });
        }
        dialog.querySelectorAll('[data-product-variant-option-cancel]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                closeVariantAxisOptionDialog();
            });
        });
        if (labelInput && valueInput) {
            labelInput.addEventListener('input', function () {
                if (!variantOptionValueSyncedFromLabel) {
                    return;
                }
                valueInput.value = labelInput.value;
            });
            valueInput.addEventListener('input', function () {
                variantOptionValueSyncedFromLabel = String(valueInput.value || '')
                    === String(labelInput.value || '');
            });
        }
        if (colorInput && colorText) {
            colorInput.addEventListener('input', function () {
                colorText.value = String(colorInput.value || '').toLowerCase();
            });
            colorText.addEventListener('change', function () {
                var hex = normalizeVariantOptionHexColor(colorText.value);
                if (hex !== '') {
                    colorText.value = hex;
                    colorInput.value = hex;
                }
            });
        }
        if (imageOpen && imageDialog && imageFrame) {
            imageOpen.addEventListener('click', function (event) {
                event.preventDefault();
                try {
                    var pickerUrl = new URL(imageFrame.getAttribute('data-src') || '', window.location.href);
                    if (pickerUrl.origin !== window.location.origin) {
                        throw new Error('媒体选择器必须与后台同源');
                    }
                    if (imageFrame.src !== pickerUrl.href) {
                        imageFrame.src = pickerUrl.href;
                    }
                    if (!openProductMediaDialog(imageDialog, {trigger: 'product-variant-option-image'})) {
                        throw new Error('媒体选择器不可用');
                    }
                } catch (error) {
                    notify('error', messageFrom(error, '媒体选择器不可用'));
                }
            });
        }
        if (imageClear) {
            imageClear.addEventListener('click', function (event) {
                event.preventDefault();
                var imageInput = dialog.querySelector('[data-product-variant-option-swatch-image]');
                if (imageInput) {
                    imageInput.value = '';
                }
                syncVariantOptionImagePreview(dialog);
            });
        }
        if (imageClose && imageDialog) {
            imageClose.addEventListener('click', function () {
                closeProductMediaDialog(imageDialog, 'product-variant-option-image');
            });
        }
        if (imageFrame) {
            window.addEventListener('message', function (event) {
                if (!imageFrame || event.source !== imageFrame.contentWindow
                    || event.origin !== window.location.origin
                    || !event.data || typeof event.data !== 'object'
                    || String(event.data.target || '') !== 'product-variant-option-image'
                ) {
                    return;
                }
                if (event.data.type === 'weline-media-manager-cancel') {
                    if (imageDialog) {
                        closeProductMediaDialog(imageDialog, 'product-variant-option-image');
                    }
                    return;
                }
                if (event.data.type !== 'weline-media-manager-select'
                    || !Array.isArray(event.data.files)
                    || event.data.files.length === 0
                ) {
                    return;
                }
                var url = resolveMediaPickerFileUrl(event.data.files[0] || {});
                if (url === '') {
                    notify('error', '未能获取所选图片地址，请重试或换一张图片。');
                    if (imageDialog) {
                        closeProductMediaDialog(imageDialog, 'product-variant-option-image');
                    }
                    return;
                }
                var imageInput = dialog.querySelector('[data-product-variant-option-swatch-image]');
                if (imageInput) {
                    imageInput.value = url;
                }
                syncVariantOptionImagePreview(dialog);
                if (imageDialog) {
                    closeProductMediaDialog(imageDialog, 'product-variant-option-image');
                }
            });
        }
    }

    function initializeVariantMatrix() {
        var matrix = root.querySelector('[data-product-offer-matrix]');
        if (!matrix || matrix.getAttribute('data-can-edit-structure') !== '1') {
            return;
        }
        var axesRoot = matrix.querySelector('[data-product-variant-axes]');
        var add = matrix.querySelector('[data-product-variant-axis-add]');
        var generate = matrix.querySelector('[data-product-variant-generate]');
        matrix.querySelectorAll('[data-product-variant-axis]').forEach(function (row) {
            bindVariantAxisOptionClamp(row);
        });
        if (add && axesRoot) {
            add.addEventListener('click', function () {
                var row = createVariantAxisEditor(matrix);
                axesRoot.appendChild(row);
                bindVariantAxisOptionClamp(row);
            });
        }
        if (axesRoot) {
            axesRoot.addEventListener('click', function (event) {
                var remove = event.target.closest('[data-product-variant-axis-remove]');
                if (remove) {
                    remove.closest('[data-product-variant-axis]').remove();
                }
            });
            axesRoot.addEventListener('change', function (event) {
                var select = event.target.closest('[data-variant-axis-code-select]');
                if (!select) {
                    return;
                }
                var row = select.closest('[data-product-variant-axis]');
                if (!row) {
                    return;
                }
                var codeInput = row.querySelector('[data-variant-axis-code]');
                var selected = selectedVariantAxisOptions(row);
                renderVariantAxisOptionGrid(
                    row,
                    String((codeInput && codeInput.value) || select.value || '').trim().toLowerCase(),
                    selected,
                    true
                );
            });
            axesRoot.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229) {
                    return;
                }
                var custom = event.target.closest('[data-variant-axis-option-custom]');
                if (!custom) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                var row = custom.closest('[data-product-variant-axis]');
                if (!row) {
                    return;
                }
                openVariantAxisOptionDialog(row, String(custom.value || '').trim());
            });
        }
        initializeVariantAxisOptionDialog();
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
        var categoryIds = [];
        var categorySelect = window.WelineCatalogCategorySelect
            && window.WelineCatalogCategorySelect['product-edit-categories'];
        if (categorySelect && typeof categorySelect.getValues === 'function') {
            categoryIds = categorySelect.getValues().map(function (raw) {
                return parseInt(String(raw || ''), 10) || 0;
            }).filter(function (id) { return id > 0; });
        } else {
            var categoryHidden = document.getElementById('product-edit-categories');
            if (categoryHidden) {
                categoryIds = String(categoryHidden.value || '').split(',').map(function (raw) {
                    return parseInt(String(raw || '').trim(), 10) || 0;
                }).filter(function (id) { return id > 0; });
            }
        }
        var positionMap = {};
        try {
            positionMap = JSON.parse(
                String(container.getAttribute('data-product-category-position-map') || '{}')
            ) || {};
        } catch (error) {
            positionMap = {};
        }
        return categoryIds.map(function (categoryId, index) {
            var mapped = parseInt(String(positionMap[String(categoryId)] ?? ''), 10);
            var position = Number.isInteger(mapped) && mapped >= 0 ? mapped : index;
            return {
                category_id: categoryId,
                scope_state: 'explicit',
                selected: true,
                position: position
            };
        });
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
        var rows = Array.prototype.map.call(
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
                    combination_key: '',
                    hidden: false,
                    scope_state: 'explicit',
                    position: position
                };
            }
        );

        Array.prototype.forEach.call(
            root.querySelectorAll('[data-product-video-row]'),
            function (row, index) {
                var assetId = String(row.getAttribute('data-asset-id') || '').trim().toLowerCase();
                var externalUrl = String(row.getAttribute('data-external-url') || '').trim();
                var path = String(row.getAttribute('data-path') || '').trim();
                var positionInput = row.querySelector('[data-product-video-position]');
                var position = parseInt(positionInput ? positionInput.value : String(index), 10);
                if (!Number.isInteger(position) || position < 0) {
                    throw new Error('视频排序无效');
                }
                if (/^[a-f0-9-]{36}$/.test(assetId)) {
                    if (seen[assetId]) {
                        throw new Error('视频资源身份重复');
                    }
                    seen[assetId] = true;
                    rows.push({
                        asset_id: assetId,
                        role: 'video',
                        combination_key: '',
                        hidden: false,
                        scope_state: 'explicit',
                        position: position
                    });
                    return;
                }
                if (externalUrl === '' && path === '') {
                    throw new Error('视频来源无效');
                }
                var identity = externalUrl || path;
                if (seen['video:' + identity]) {
                    throw new Error('视频链接重复');
                }
                seen['video:' + identity] = true;
                var payload = {
                    asset_id: '',
                    role: 'video',
                    combination_key: '',
                    hidden: false,
                    scope_state: 'explicit',
                    position: position
                };
                if (externalUrl !== '') {
                    payload.external_url = externalUrl;
                }
                if (path !== '') {
                    payload.path = path;
                }
                rows.push(payload);
            }
        );

        var preserveNode = root.querySelector('[data-product-variant-media-preserve]');
        if (preserveNode) {
            var preserved = [];
            try {
                preserved = JSON.parse(preserveNode.textContent || '[]');
            } catch (error) {
                preserved = [];
            }
            if (Array.isArray(preserved)) {
                preserved.forEach(function (item, index) {
                    if (!item || typeof item !== 'object') {
                        return;
                    }
                    var assetId = String(item.asset_id || '').trim().toLowerCase();
                    var combinationKey = String(item.combination_key || '').trim();
                    if (!/^[a-f0-9-]{36}$/.test(assetId)
                        || combinationKey === ''
                        || seen[assetId + '\0' + combinationKey]
                    ) {
                        return;
                    }
                    seen[assetId + '\0' + combinationKey] = true;
                    rows.push({
                        asset_id: assetId,
                        role: 'variant',
                        combination_key: combinationKey,
                        hidden: Boolean(item.hidden),
                        scope_state: String(item.scope_state || 'explicit'),
                        position: Number.isInteger(item.position) ? item.position : (1000 + index)
                    });
                });
            }
        }
        return rows;
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

    function resolveMediaPickerFileUrl(file) {
        if (!file || typeof file !== 'object') {
            return '';
        }
        var candidates = [
            file.url,
            file.path,
            file.display_url,
            file.editor_preview_url,
            file.thumb
        ];
        for (var index = 0; index < candidates.length; index += 1) {
            var preview = safePickerPreview(candidates[index]);
            if (preview !== '') {
                return preview;
            }
        }
        return '';
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
        row.setAttribute('data-asset-visibility', String(file.asset_visibility || file.visibility || 'public'));

        var assetCell = document.createElement('td');
        assetCell.className = 'w-product-media-asset';
        var openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.className = 'w-product-media-asset-open';
        openBtn.setAttribute('data-product-media-asset-open', '');
        openBtn.title = '在文件管理器中编辑';
        var preview = resolveMediaPickerFileUrl(file) || safePickerPreview(file.preview_url);
        row.setAttribute('data-preview-url', preview);
        if (preview !== '') {
            var image = document.createElement('img');
            image.className = 'w-product-media-thumb';
            image.src = preview;
            image.alt = String(file.display_name || '');
            image.loading = 'lazy';
            image.decoding = 'async';
            openBtn.appendChild(image);
        }
        var metaWrap = document.createElement('span');
        metaWrap.className = 'w-product-media-asset__meta';
        var name = document.createElement('strong');
        name.className = 'w-product-asset-name';
        name.textContent = String(file.display_name || assetId);
        metaWrap.appendChild(name);
        var meta = document.createElement('small');
        meta.textContent = mimeType + ' · ' + assetId;
        metaWrap.appendChild(meta);
        openBtn.appendChild(metaWrap);
        assetCell.appendChild(openBtn);
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
                var openTarget = event.target instanceof Element
                    ? event.target.closest('[data-product-media-asset-open]')
                    : null;
                if (openTarget) {
                    event.preventDefault();
                    var openRow = openTarget.closest('[data-product-media-row]');
                    var focusAssetId = openRow ? String(openRow.getAttribute('data-asset-id') || '').trim().toLowerCase() : '';
                    if (focusAssetId) {
                        openProductMediaPicker({assetId: focusAssetId});
                    }
                    return;
                }
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
            closeProductMediaDialog(dialog, 'product-media');
        }

        function openProductMediaPicker(options) {
            options = options || {};
            if (!dialog || !frame) {
                throw new Error('媒体选择器不可用');
            }
            var focusAssetId = String(options.assetId || '').trim().toLowerCase();
            var pickerUrl = new URL(frame.getAttribute('data-src') || '', window.location.href);
            if (pickerUrl.origin !== window.location.origin) {
                throw new Error('媒体选择器必须与后台同源');
            }
            if (focusAssetId) {
                pickerUrl.searchParams.set('asset_id', focusAssetId);
            } else {
                pickerUrl.searchParams.delete('asset_id');
            }
            var helper = window.Weline && window.Weline.MediaIdentityPicker;
            if (helper) {
                var ambient = helper.ambientFrom(root);
                var identity = helper.buildIdentity(Object.assign({}, ambient, { kind: ambient.kind || 'media' }));
                if (identity) {
                    pickerUrl = helper.appendIdentityParams(pickerUrl, identity);
                    root.__mediaIdentity = identity;
                } else if (ambient.code && ambient.root) {
                    throw new Error('媒体身份不完整：需要 window.w_scope 或显式 identity');
                }
            }
            frame.src = pickerUrl.href;
            if (!openProductMediaDialog(dialog, {trigger: 'product-media'})) {
                throw new Error('媒体选择器不可用');
            }
        }

        if (open && dialog && frame) {
            open.addEventListener('click', function () {
                try {
                    openProductMediaPicker();
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
                var helper = window.Weline && window.Weline.MediaIdentityPicker;
                if (helper && root.__mediaIdentity) {
                    var ambient = helper.ambientFrom(root);
                    helper.bindSelection(root.__mediaIdentity, event.data.files, {
                        bindUrl: ambient.bindUrl,
                        ownerType: ambient.ownerType || 'product',
                        ownerId: ambient.ownerId || root.__mediaIdentity.code,
                        ownerVersion: ambient.ownerVersion || 1,
                        refMode: ambient.refMode || 'multi',
                    });
                }
                closePicker();
            } catch (error) {
                notify('error', messageFrom(error, '添加商品图片失败'));
            }
        });
        updateMediaEmptyState();
        initializeProductVideoPanel();
    }

    function updateVideoEmptyState() {
        var empty = root.querySelector('[data-product-video-empty]');
        var count = root.querySelectorAll('[data-product-video-row]').length;
        if (empty) {
            empty.hidden = count > 0;
        }
    }

    function youtubePosterFromUrl(url) {
        var match = String(url || '').match(
            /(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/i
        );
        return match ? ('https://i.ytimg.com/vi/' + match[1] + '/hqdefault.jpg') : '';
    }

    function appendProductVideoRow(payload) {
        var body = root.querySelector('[data-product-video-rows]');
        if (!body) {
            return;
        }
        var assetId = String(payload.asset_id || '').trim().toLowerCase();
        var externalUrl = String(payload.external_url || '').trim();
        var path = String(payload.path || '').trim();
        var mimeType = String(payload.mime_type || '').trim().toLowerCase();
        var provider = String(payload.provider || '').trim().toLowerCase();
        var preview = String(payload.preview_url || '').trim();
        if (/^[a-f0-9-]{36}$/.test(assetId)) {
            if (mimeType && mimeType.indexOf('video/') !== 0) {
                throw new Error('请选择视频文件');
            }
            if (body.querySelector('[data-asset-id="' + assetId + '"]')) {
                notify('warning', '该视频已经添加');
                return;
            }
            provider = provider || 'file';
        } else if (externalUrl !== '') {
            if (Array.prototype.some.call(body.querySelectorAll('[data-product-video-row]'), function (existing) {
                return String(existing.getAttribute('data-external-url') || '') === externalUrl;
            })) {
                notify('warning', '该视频链接已经添加');
                return;
            }
            if (preview === '') {
                preview = youtubePosterFromUrl(externalUrl);
            }
            provider = provider || (preview ? 'youtube' : 'link');
        } else {
            throw new Error('视频来源无效');
        }

        var row = document.createElement('tr');
        row.setAttribute('data-product-video-row', '');
        row.setAttribute('data-asset-id', assetId);
        row.setAttribute('data-external-url', externalUrl);
        row.setAttribute('data-path', path);
        row.setAttribute('data-mime-type', mimeType);
        row.setAttribute('data-provider', provider);
        row.setAttribute('data-preview-url', preview);

        var previewCell = document.createElement('td');
        previewCell.className = 'w-product-media-asset';
        if (preview !== '') {
            var image = document.createElement('img');
            image.className = 'w-product-media-thumb';
            image.src = preview;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            previewCell.appendChild(image);
        } else {
            var badge = document.createElement('span');
            badge.className = 'w-product-video-badge';
            badge.textContent = '视频';
            previewCell.appendChild(badge);
        }
        row.appendChild(previewCell);

        var sourceCell = document.createElement('td');
        var strong = document.createElement('strong');
        strong.textContent = provider || (assetId ? 'file' : 'link');
        sourceCell.appendChild(strong);
        var small = document.createElement('small');
        small.className = 'w-product-video-source';
        small.textContent = externalUrl || assetId || path;
        sourceCell.appendChild(small);
        row.appendChild(sourceCell);

        var positionCell = document.createElement('td');
        var position = document.createElement('input');
        position.className = 'w-input';
        position.type = 'number';
        position.min = '0';
        position.step = '1';
        position.value = String(body.querySelectorAll('[data-product-video-row]').length);
        position.setAttribute('data-product-video-position', '');
        positionCell.appendChild(position);
        row.appendChild(positionCell);

        var actionCell = document.createElement('td');
        var remove = document.createElement('button');
        remove.className = 'w-button';
        remove.type = 'button';
        remove.setAttribute('data-tone', 'danger');
        remove.setAttribute('data-product-video-remove', '');
        remove.textContent = '移除';
        actionCell.appendChild(remove);
        row.appendChild(actionCell);
        body.appendChild(row);
        updateVideoEmptyState();
    }

    function initializeProductVideoPanel() {
        var panel = root.querySelector('[data-product-video-panel]');
        if (!panel) {
            return;
        }
        var videoBody = root.querySelector('[data-product-video-rows]');
        if (videoBody) {
            videoBody.addEventListener('click', function (event) {
                var target = event.target instanceof Element
                    ? event.target.closest('[data-product-video-remove]')
                    : null;
                if (!target) {
                    return;
                }
                var row = target.closest('[data-product-video-row]');
                if (row) {
                    row.remove();
                    updateVideoEmptyState();
                }
            });
        }

        var urlInput = root.querySelector('[data-product-video-url-input]');
        var urlAdd = root.querySelector('[data-product-video-url-add]');
        if (urlAdd && urlInput) {
            urlAdd.addEventListener('click', function () {
                try {
                    var raw = String(urlInput.value || '').trim();
                    if (raw === '') {
                        throw new Error('请粘贴视频链接或 iframe 代码');
                    }
                    var iframeMatch = raw.match(/<iframe\b[^>]*\bsrc\s*=\s*['"]([^'"]+)['"]/i);
                    var externalUrl = iframeMatch ? iframeMatch[1] : raw;
                    appendProductVideoRow({
                        external_url: externalUrl,
                        preview_url: youtubePosterFromUrl(externalUrl)
                    });
                    urlInput.value = '';
                } catch (error) {
                    notify('error', messageFrom(error, '添加视频链接失败'));
                }
            });
        }

        var dialog = root.querySelector('[data-product-video-picker-dialog]');
        var frame = root.querySelector('[data-product-video-picker-frame]');
        var open = root.querySelector('[data-product-video-picker-open]');
        var close = root.querySelector('[data-product-video-picker-close]');

        function closeVideoPicker() {
            if (!dialog) {
                return;
            }
            closeProductMediaDialog(dialog, 'product-video');
        }

        function openProductVideoPicker() {
            if (!dialog || !frame) {
                throw new Error('视频选择器不可用');
            }
            var pickerUrl = new URL(frame.getAttribute('data-src') || '', window.location.href);
            if (pickerUrl.origin !== window.location.origin) {
                throw new Error('视频选择器必须与后台同源');
            }
            frame.src = pickerUrl.href;
            if (!openProductMediaDialog(dialog, {trigger: 'product-video'})) {
                throw new Error('视频选择器不可用');
            }
        }

        if (open && dialog && frame) {
            open.addEventListener('click', function () {
                try {
                    openProductVideoPicker();
                } catch (error) {
                    notify('error', messageFrom(error, '视频选择器不可用'));
                }
            });
        }
        if (close) {
            close.addEventListener('click', closeVideoPicker);
        }

        window.addEventListener('message', function (event) {
            if (!frame || event.source !== frame.contentWindow
                || event.origin !== window.location.origin
                || !event.data || typeof event.data !== 'object'
                || String(event.data.target || '') !== 'product-video-picker'
            ) {
                return;
            }
            if (event.data.type === 'weline-media-manager-cancel') {
                closeVideoPicker();
                return;
            }
            if (event.data.type !== 'weline-media-manager-select'
                || !Array.isArray(event.data.files)
            ) {
                return;
            }
            try {
                event.data.files.forEach(function (file) {
                    appendProductVideoRow({
                        asset_id: file.asset_id,
                        mime_type: file.mime,
                        preview_url: resolveMediaPickerFileUrl(file) || safePickerPreview(file.preview_url),
                        provider: 'file'
                    });
                });
                closeVideoPicker();
            } catch (error) {
                notify('error', messageFrom(error, '添加商品视频失败'));
            }
        });
        updateVideoEmptyState();
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

    function persistProductDescriptionAssets(html) {
        html = String(html || '');
        if (html === '') {
            return '';
        }
        var map = {};
        var mapNode = document.getElementById('product-edit-description-asset-map');
        if (mapNode) {
            try {
                var mapText = String(mapNode.textContent || '{}');
                if (mapText.indexOf('&quot;') !== -1) {
                    mapText = mapText
                        .replace(/&quot;/g, '"')
                        .replace(/&amp;/g, '&')
                        .replace(/&lt;/g, '<')
                        .replace(/&gt;/g, '>');
                }
                var parsed = JSON.parse(mapText);
                if (parsed && typeof parsed === 'object') {
                    map = parsed;
                }
            } catch (error) {
                map = {};
            }
        }
        if (html.indexOf('data-weline-asset-id') === -1 && Object.keys(map).length === 0) {
            return html;
        }
        try {
            var doc = new DOMParser().parseFromString(
                '<div id="weline-product-admin-description-root">' + html + '</div>',
                'text/html'
            );
            var root = doc.getElementById('weline-product-admin-description-root');
            if (!root) {
                return html;
            }
            root.querySelectorAll('img').forEach(function (img) {
                var assetId = String(img.getAttribute('data-weline-asset-id') || '').trim();
                if (!assetId) {
                    var src = String(img.getAttribute('src') || '').trim();
                    if (Object.prototype.hasOwnProperty.call(map, src)) {
                        assetId = String(map[src] || '').trim();
                    }
                }
                if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(assetId)) {
                    return;
                }
                img.setAttribute('src', 'asset://' + assetId);
                img.removeAttribute('data-weline-asset-id');
            });
            return String(root.innerHTML || '').trim();
        } catch (error) {
            return html;
        }
    }

    function syncProductDescriptionEditorSource() {
        var source = document.getElementById('product-edit-description');
        if (!source) {
            return '';
        }
        var editable = root.querySelector(
            '.w-product-description-editor .ck-editor__editable'
        );
        if (editable && typeof editable.innerHTML === 'string') {
            // CKEditor 已同步到 textarea；再读一次 DOM 兜底未触发 change:data 的瞬间。
            source.dispatchEvent(new Event('input', {bubbles: true}));
        }
        return persistProductDescriptionAssets(String(source.value || '').trim());
    }

    function editorPayload() {
        var form = document.getElementById('product-editor-form');
        var currency = String(document.getElementById('product-edit-currency').value || 'CNY')
            .trim()
            .toUpperCase();
        var shortDescriptionInput = document.getElementById('product-edit-short-description');
        var metaNameInput = document.getElementById('product-edit-meta-name');
        var metaDescriptionInput = document.getElementById('product-edit-meta-description');
        var metaKeywordsInput = document.getElementById('product-edit-meta-keywords');
        var payload = {
            name: String(document.getElementById('product-edit-name').value || '').trim(),
            locale: String(document.getElementById('product-edit-locale').value || '').trim(),
            currency: currency,
            short_description: shortDescriptionInput
                ? String(shortDescriptionInput.value || '').trim()
                : '',
            description: syncProductDescriptionEditorSource(),
            meta_name: metaNameInput ? String(metaNameInput.value || '').trim() : '',
            meta_description: metaDescriptionInput
                ? String(metaDescriptionInput.value || '').trim()
                : '',
            meta_keywords: metaKeywordsInput
                ? String(metaKeywordsInput.value || '').trim()
                : '',
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
        var shippingProfileEl = document.getElementById('product-edit-shipping-profile');
        if (shippingProfileEl) {
            payload.shipping_profile_code = String(shippingProfileEl.value || '').trim();
        }
        var shippingHazardEl = document.getElementById('product-edit-shipping-hazard');
        if (shippingHazardEl) {
            payload.shipping_hazard_class = String(shippingHazardEl.value || '').trim();
        }
        var editFreeShipEl = document.getElementById('product-edit-is-free-shipping');
        if (editFreeShipEl) {
            payload.is_free_shipping = !!editFreeShipEl.checked;
        }
        var editFreeShipMinEl = document.getElementById('product-edit-free-shipping-min-amount');
        if (editFreeShipMinEl) {
            var editMinRaw = String(editFreeShipMinEl.value || '').trim();
            payload.free_shipping_min_amount = editMinRaw === '' ? 0 : Number(editMinRaw);
            if (!Number.isFinite(payload.free_shipping_min_amount) || payload.free_shipping_min_amount < 0) {
                throw new Error('请输入有效本品免邮额度');
            }
        }
        payload.attributes = mergeWholesaleSellingModeFlag(payload.attributes || []);
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

    function mergeWholesaleSellingModeFlag(rows) {
        var list = Array.isArray(rows) ? rows.slice() : [];
        var input = document.querySelector('[data-b2b-wholesale-canonical="1"]')
            || document.querySelector(
                '#product-create-selling-mode-tob, #product-edit-selling-mode-tob-canonical, [data-testid="b2b-product-wholesale-enabled"]'
            );
        if (!input) {
            return list;
        }
        var code = String(input.getAttribute('data-attribute-code') || 'selling_mode_tob').trim();
        if (code === '') {
            code = 'selling_mode_tob';
        }
        var enabled = !!input.checked;
        var entityId = parseInt(String(
            (document.querySelector('[data-product-id]') || {}).getAttribute
                ? document.querySelector('[data-product-id]').getAttribute('data-product-id')
                : '0'
        ) || '0', 10) || 0;
        var eavRoot = document.getElementById('product-eav-editor');
        if (eavRoot && entityId <= 0) {
            entityId = parseInt(String(eavRoot.getAttribute('data-product-id') || '0'), 10) || 0;
        }
        var filtered = list.filter(function (row) {
            return !(row && String(row.attribute_code || '') === code
                && String(row.entity_type || 'product') === 'product'
                && parseInt(row.store_id || 0, 10) === 0
                && String(row.locale || '') === '');
        });
        filtered.push({
            entity_type: 'product',
            entity_id: entityId,
            store_id: 0,
            locale: '',
            attribute_code: code,
            value_type: 'boolean',
            scope_state: 'explicit',
            cleared: false,
            value: enabled
        });
        return filtered;
    }

    function syncWholesaleCheckboxes(source) {
        var canonical = document.querySelector('[data-b2b-wholesale-canonical="1"]');
        if (!canonical) {
            return;
        }
        var checked = source ? !!source.checked : !!canonical.checked;
        if (source !== canonical) {
            canonical.checked = checked;
        }
        document.querySelectorAll('[data-b2b-wholesale-mirror="1"]').forEach(function (el) {
            if (el !== source) {
                el.checked = checked;
            }
        });
    }

    function markB2bTiersDirty(dirty) {
        var section = document.getElementById('b2b-product-offers-wholesale');
        if (!section) {
            return;
        }
        section.setAttribute('data-b2b-tiers-dirty', dirty ? '1' : '0');
        var hint = section.querySelector('[data-testid="b2b-product-tiers-dirty-hint"]');
        if (hint) {
            hint.hidden = !dirty;
        }
    }

    function collectB2bTierRows() {
        var rows = [];
        var section = document.getElementById('b2b-product-offers-wholesale');
        if (!section) {
            return rows;
        }
        section.querySelectorAll('[data-b2b-tier-row]').forEach(function (tr) {
            var skuInput = tr.querySelector('[data-b2b-tier-sku]');
            var minInput = tr.querySelector('[data-b2b-tier-min-qty]');
            var amountInput = tr.querySelector('[data-b2b-tier-amount]');
            if (!skuInput || !minInput || !amountInput) {
                return;
            }
            var sku = String(skuInput.value || '').trim();
            var amountRaw = String(amountInput.value || '').trim();
            if (sku === '' || amountRaw === '') {
                return;
            }
            var minQty = parseInt(String(minInput.value || '0'), 10);
            var amount = parseInt(amountRaw, 10);
            if (!Number.isInteger(minQty) || minQty < 1) {
                throw new Error('起订量必须是 ≥ 1 的整数');
            }
            if (!Number.isInteger(amount) || amount < 0) {
                throw new Error('批发价（分）必须是 ≥ 0 的整数');
            }
            rows.push({sku: sku, min_qty: minQty, amount_minor: amount});
        });
        return rows;
    }

    function saveB2bProductSkuTiers() {
        var section = document.getElementById('b2b-product-offers-wholesale');
        if (!section) {
            return Promise.resolve(null);
        }
        var url = String(section.getAttribute('data-b2b-save-url') || '').trim();
        if (url === '') {
            return Promise.reject(new Error('阶梯价保存地址不可用'));
        }
        var groupEl = document.getElementById('b2b-product-tier-group');
        var groupId = groupEl
            ? String(groupEl.value || '').trim()
            : String(section.getAttribute('data-b2b-group-id') || '').trim();
        var body = new URLSearchParams();
        body.set('website_id', String(section.getAttribute('data-b2b-website-id') || '0'));
        body.set('product_id', String(section.getAttribute('data-b2b-product-id') || '0'));
        body.set('group_id', groupId);
        body.set('expected_version', String(section.getAttribute('data-b2b-list-version') || '0'));
        var tiers = collectB2bTierRows();
        body.set('tiers_json', JSON.stringify(tiers));
        var headers = {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'};
        if (window.site && window.site.csrf_token) {
            headers['X-CSRF-TOKEN'] = String(window.site.csrf_token);
            body.set('form_key', String(window.site.csrf_token));
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json || json.ok !== true) {
                    var err = new Error((json && (json.error || json.message)) || '保存阶梯价失败');
                    err.payload = json;
                    throw err;
                }
                var meta = (json.tiers && typeof json.tiers === 'object') ? json.tiers : {};
                if (meta.version !== undefined) {
                    section.setAttribute('data-b2b-list-version', String(meta.version || 0));
                }
                if (meta.list_id) {
                    section.setAttribute('data-b2b-list-id', String(meta.list_id));
                }
                markB2bTiersDirty(false);
                return json;
            });
        });
    }

    function saveB2bTiersIfDirty() {
        var section = document.getElementById('b2b-product-offers-wholesale');
        if (!section || section.getAttribute('data-b2b-tiers-dirty') !== '1') {
            return Promise.resolve(null);
        }
        return saveB2bProductSkuTiers();
    }

    function initB2bWholesaleOffersUi() {
        var section = document.getElementById('b2b-product-offers-wholesale');
        document.querySelectorAll('[data-b2b-wholesale-canonical="1"], [data-b2b-wholesale-mirror="1"]').forEach(function (input) {
            input.addEventListener('change', function () {
                syncWholesaleCheckboxes(input);
            });
        });
        syncWholesaleCheckboxes(null);
        if (!section) {
            return;
        }
        section.addEventListener('input', function (event) {
            if (event.target && (
                event.target.hasAttribute('data-b2b-tier-min-qty')
                || event.target.hasAttribute('data-b2b-tier-amount')
            )) {
                markB2bTiersDirty(true);
            }
        });
        section.addEventListener('change', function (event) {
            if (event.target && event.target.id === 'b2b-product-tier-group') {
                var groupId = String(event.target.value || '').trim();
                var url = new URL(window.location.href);
                if (groupId) {
                    url.searchParams.set('b2b_group_id', groupId);
                } else {
                    url.searchParams.delete('b2b_group_id');
                }
                if (section.getAttribute('data-b2b-tiers-dirty') === '1') {
                    if (!window.confirm('切换客户组将丢弃未保存的阶梯改动，是否继续？')) {
                        event.target.value = String(section.getAttribute('data-b2b-group-id') || '');
                        return;
                    }
                }
                window.location.href = url.toString();
            }
        });
        section.querySelectorAll('[data-b2b-tier-add]').forEach(function (button) {
            button.addEventListener('click', function () {
                var sku = String(button.getAttribute('data-b2b-tier-add') || '').trim();
                if (sku === '') {
                    return;
                }
                var actions = section.querySelector('[data-b2b-tier-sku-actions][data-sku="' + sku.replace(/"/g, '\\"') + '"]');
                if (!actions || !actions.parentNode) {
                    return;
                }
                var tr = document.createElement('tr');
                tr.setAttribute('data-b2b-tier-row', '1');
                tr.setAttribute('data-sku', sku);
                tr.innerHTML = ''
                    + '<td><input type="hidden" data-b2b-tier-sku value="' + escapeHtml(sku) + '"></td>'
                    + '<td><input class="w-input" type="number" min="1" step="1" data-b2b-tier-min-qty value="1"></td>'
                    + '<td><input class="w-input" type="number" min="0" step="1" data-b2b-tier-amount value="" placeholder="分"></td>'
                    + '<td><button class="w-button" type="button" data-tone="neutral" data-variant="ghost" data-size="sm" data-b2b-tier-remove>删除</button></td>';
                actions.parentNode.insertBefore(tr, actions);
                markB2bTiersDirty(true);
            });
        });
        section.addEventListener('click', function (event) {
            var remove = event.target && event.target.closest
                ? event.target.closest('[data-b2b-tier-remove]')
                : null;
            if (!remove) {
                return;
            }
            var row = remove.closest('[data-b2b-tier-row]');
            if (!row) {
                return;
            }
            var sku = String(row.getAttribute('data-sku') || '');
            var siblings = section.querySelectorAll('[data-b2b-tier-row][data-sku="' + sku.replace(/"/g, '\\"') + '"]');
            if (siblings.length <= 1) {
                var amount = row.querySelector('[data-b2b-tier-amount]');
                if (amount) {
                    amount.value = '';
                }
            } else {
                row.remove();
            }
            markB2bTiersDirty(true);
        });
        var saveBtn = section.querySelector('[data-b2b-tiers-save]');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveB2bProductSkuTiers()
                    .then(function () {
                        notify('success', '阶梯价已保存');
                    })
                    .catch(function (error) {
                        notify('error', messageFrom(error, '保存阶梯价失败'));
                    });
            });
        }
        window.addEventListener('beforeunload', function (event) {
            if (section.getAttribute('data-b2b-tiers-dirty') === '1') {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    var CREATE_EAV_SKIP_CODES = {
        attribute_set: true,
        attribute_set_label: true,
        brand: true,
        brand_code: true,
        name: true,
        type_configuration: true
    };
    var createVariantImageTarget = null;

    function getCreateEavFieldRoots() {
        var roots = [];
        // Never include #product-create-eav-editor: it mirrors the same fields empty and
        // last-write-wins in persistCreateEavDraft, wiping draft values on every change.
        ['product-create-variant-axes', 'product-create-product-attributes'].forEach(function (id) {
            var node = document.getElementById(id);
            if (node) {
                roots.push(node);
            }
        });
        return roots;
    }

    function queryCreateEavFields(selector) {
        var query = selector || '[data-eav-field]';
        var nodes = [];
        getCreateEavFieldRoots().forEach(function (root) {
            root.querySelectorAll(query).forEach(function (field) {
                nodes.push(field);
            });
        });
        return nodes;
    }

    function attributeSwatchCapabilities(attribute) {
        var caps = attribute && attribute.swatch_capabilities;
        if (caps && typeof caps === 'object') {
            return {
                color: Boolean(caps.color),
                image: Boolean(caps.image),
                text: Boolean(caps.text)
            };
        }
        var options = Array.isArray(attribute && attribute.options) ? attribute.options : [];
        var hasColor = options.some(function (option) {
            return String(option.swatch_color || option.swatch || '').trim() !== '';
        });
        var hasImage = options.some(function (option) {
            return String(option.swatch_image || '').trim() !== '';
        });
        var code = String((attribute && (attribute.code || attribute.attribute_code)) || '').toLowerCase();
        return {
            color: hasColor,
            image: hasImage || hasColor || code === 'color' || code === 'style_type',
            text: !hasColor && !hasImage
        };
    }

    function normalizeCreateOptionMeta(option, optionIndex) {
        return option && typeof option === 'object'
            ? option
            : {value: option, label: option, code: option, option_id: optionIndex};
    }

    function readVariantOptionImageInput(axisCode, optionValue) {
        var selector = '[data-create-variant-option-image][data-axis-code="'
            + axisCode + '"][data-option-value="' + optionValue + '"]';
        var input = document.querySelector(selector);
        var fromInput = input ? String(input.value || '').trim() : '';
        if (fromInput !== '') {
            return fromInput;
        }
        var draft = window.__welineCreateVariantOptionImageDraft;
        if (draft && typeof draft === 'object') {
            var key = String(axisCode || '').trim().toLowerCase() + '::' + String(optionValue || '');
            var fromDraft = draft[key];
            if (fromDraft == null && Object.prototype.hasOwnProperty.call(draft, String(axisCode) + '::' + String(optionValue))) {
                fromDraft = draft[String(axisCode) + '::' + String(optionValue)];
            }
            if (fromDraft != null && String(fromDraft).trim() !== '') {
                return String(fromDraft).trim();
            }
        }
        return '';
    }

    function resolveVariantOptionMeta(axisCode, optionValue, attribute) {
        var options = Array.isArray(attribute && attribute.options) ? attribute.options : [];
        var match = null;
        options.forEach(function (option, optionIndex) {
            var normalized = normalizeCreateOptionMeta(option, optionIndex);
            var candidates = [
                normalized.code,
                normalized.value,
                normalized.option_id
            ].map(function (value) {
                return String(value == null ? '' : value).trim();
            }).filter(Boolean);
            if (candidates.indexOf(String(optionValue)) >= 0) {
                match = normalized;
            }
        });
        var swatchColor = match
            ? String(match.swatch_color || match.swatch || '').trim()
            : '';
        var swatchImage = readVariantOptionImageInput(axisCode, optionValue);
        if (swatchImage === '' && match) {
            swatchImage = String(match.swatch_image || '').trim();
        }
        return {
            swatch_color: swatchColor,
            swatch_image: swatchImage
        };
    }

    function resolveCombinationPreview(combination, axes) {
        var previewImage = '';
        var previewColor = '';
        Object.keys(combination).some(function (axisCode) {
            var axis = axes.find(function (item) {
                return item.code === axisCode;
            });
            if (!axis) {
                return false;
            }
            var option = axis.options.find(function (item) {
                return item.value === combination[axisCode];
            });
            if (!option) {
                return false;
            }
            if (String(option.swatch_image || '').trim() !== '') {
                previewImage = String(option.swatch_image).trim();
                return true;
            }
            if (String(option.swatch_color || '').trim() !== '') {
                previewColor = String(option.swatch_color).trim();
            }
            return false;
        });
        return {image: previewImage, color: previewColor};
    }

    function updateCreateWizardVisibility() {
        var supportsVariants = createProductTypeSupportsVariants();
        var setFlow = document.getElementById('product-create-set-flow');
        var setCodeInput = document.getElementById('product-create-attribute-set-code');
        var setIdInput = document.getElementById('product-create-attribute-set-id');
        var hasSet = Boolean(
            (setCodeInput && String(setCodeInput.value || '').trim() !== '')
            || (setIdInput && String(setIdInput.value || '').trim() !== '')
        );
        if (setFlow) {
            setFlow.hidden = !hasSet;
        }
        document.querySelectorAll('[data-configurable-only]').forEach(function (step) {
            step.hidden = !supportsVariants || !hasSet;
        });
        var productStepNumber = document.querySelector('[data-create-step-number="product"]');
        var publishStepNumber = document.querySelector('[data-create-step-number="publish"]');
        if (productStepNumber) {
            productStepNumber.textContent = '3';
        }
        if (publishStepNumber) {
            publishStepNumber.textContent = supportsVariants && hasSet ? '7' : (hasSet ? '4' : '3');
        }
        var variantPanel = document.getElementById('product-create-variant-panel');
        if (variantPanel) {
            variantPanel.hidden = true;
        }
        // Visibility-only: do not rebuild variant value/preview DOM on every gate refresh.
    }

    function getCreateWizardSteps() {
        var steps = [];
        document.querySelectorAll('#product-create-form .w-product-create__step').forEach(function (step) {
            if (step.hidden) {
                return;
            }
            var stepId = String(step.getAttribute('data-create-step') || '').trim();
            if (stepId !== '') {
                steps.push({id: stepId, element: step});
            }
        });
        return steps;
    }

    function getCreateWizardStepElement(stepId) {
        return document.querySelector('#product-create-form .w-product-create__step[data-create-step="' + stepId + '"]');
    }

    function getPreviousCreateWizardStepIds(stepId) {
        var previous = [];
        getCreateWizardSteps().some(function (step) {
            if (step.id === stepId) {
                return true;
            }
            previous.push(step.id);
            return false;
        });
        return previous;
    }

    function getFirstIncompletePreviousCreateWizardStepId(stepId) {
        var blockedStepId = '';
        getPreviousCreateWizardStepIds(stepId).some(function (previousStepId) {
            if (!isCreateStepComplete(previousStepId)) {
                blockedStepId = previousStepId;
                return true;
            }
            return false;
        });
        return blockedStepId;
    }

    function openCreateWizardStep(stepId, options) {
        options = options || {};
        var normalizedStepId = String(stepId || '').trim();
        if (normalizedStepId === '') {
            return false;
        }
        var remember = options.remember !== false;
        var force = options.force === true;
        if (!force) {
            var blockedStepId = getFirstIncompletePreviousCreateWizardStepId(normalizedStepId);
            if (blockedStepId !== '') {
                if (options.silent !== true) {
                    notify('warning', getCreateStepIncompleteMessage(blockedStepId));
                }
                var blockedElement = getCreateWizardStepElement(blockedStepId);
                if (blockedElement) {
                    createWizardToggleGuard = true;
                    try {
                        getCreateWizardSteps().forEach(function (step) {
                            step.element.open = step.element === blockedElement;
                        });
                    } finally {
                        createWizardToggleGuard = false;
                    }
                    blockedElement.scrollIntoView({block: 'nearest', behavior: 'smooth'});
                    if (remember) {
                        rememberCreateWizardStep(blockedStepId);
                    }
                }
                return false;
            }
        }
        var targetElement = getCreateWizardStepElement(normalizedStepId);
        if (!targetElement || targetElement.hidden) {
            return false;
        }
        var opened = false;
        createWizardToggleGuard = true;
        try {
            document.querySelectorAll('#product-create-form .w-product-create__step').forEach(function (step) {
                var isTarget = String(step.getAttribute('data-create-step') || '').trim() === normalizedStepId;
                step.open = isTarget;
                if (isTarget) {
                    opened = true;
                    step.scrollIntoView({block: 'nearest', behavior: 'smooth'});
                }
            });
        } finally {
            createWizardToggleGuard = false;
        }
        if (opened && remember) {
            rememberCreateWizardStep(normalizedStepId);
        }
        return opened;
    }

    function getNextCreateWizardStepId(stepId) {
        var steps = getCreateWizardSteps();
        for (var index = 0; index < steps.length - 1; index += 1) {
            if (steps[index].id === stepId) {
                return steps[index + 1].id;
            }
        }
        return '';
    }

    function isCreateEavFieldEmpty(field) {
        var input = field.querySelector('[data-eav-input]');
        var valueType = String(field.getAttribute('data-value-type') || 'string');
        if (valueType === 'boolean') {
            return false;
        }
        if (valueType === 'variant-axis') {
            return field.querySelectorAll('[data-create-variant-option]:checked').length === 0;
        }
        if (valueType === 'multiselect') {
            return !input || Array.prototype.filter.call(input.options, function (option) {
                return option.selected;
            }).length === 0;
        }
        if (valueType === 'select') {
            return !input || String(input.value || '').trim() === '';
        }
        return !input || String(input.value || '').trim() === '';
    }

    function requiresCreateStepManualConfirm(stepId) {
        return stepId === 'attribute-set' || stepId === 'variant-axes';
    }

    function getCreateWizardStepContinueButton(stepId) {
        return document.querySelector('[data-create-step-continue="' + stepId + '"]');
    }

    function resetCreateWizardStepConfirm(stepId) {
        var button = getCreateWizardStepContinueButton(stepId);
        if (button) {
            button.removeAttribute('data-step-confirmed');
        }
    }

    function isCreateStepContentReady(stepId) {
        if (stepId === 'basics') {
            var typeInput = document.getElementById('product-create-type');
            var nameInput = document.getElementById('product-create-name');
            var skuInput = document.getElementById('product-create-sku');
            return Boolean(typeInput && String(typeInput.value || '').trim() !== '')
                && Boolean(nameInput && String(nameInput.value || '').trim() !== '')
                && Boolean(skuInput && String(skuInput.value || '').trim() !== '');
        }
        if (stepId === 'attribute-set') {
            var setCodeInput = document.getElementById('product-create-attribute-set-code');
            var setIdInput = document.getElementById('product-create-attribute-set-id');
            return Boolean(
                (setCodeInput && String(setCodeInput.value || '').trim() !== '')
                || (setIdInput && String(setIdInput.value || '').trim() !== '')
            );
        }
        if (stepId === 'variant-axes') {
            if (!createProductTypeSupportsVariants()) {
                return true;
            }
            try {
                return collectCreateVariantAxes().length > 0;
            } catch (error) {
                return false;
            }
        }
        if (stepId === 'variant-values') {
            if (!createProductTypeSupportsVariants()) {
                return true;
            }
            return Boolean(document.querySelector('[data-create-variant-option-image][value]:not([value=""])'));
        }
        if (stepId === 'product-attributes') {
            var setKey = getCreateSelectedAttributeSetId();
            var expectedProductAttrs = countExpectedCreateProductAttributeFields(setKey);
            var productRoot = document.getElementById('product-create-product-attributes');
            var renderedProductFields = productRoot
                ? productRoot.querySelectorAll('[data-eav-field]')
                : [];
            if (expectedProductAttrs > 0 && renderedProductFields.length === 0) {
                return false;
            }
            var requiredFields = [];
            Array.prototype.forEach.call(renderedProductFields, function (field) {
                if (field.getAttribute('data-required') === '1'
                    && !field.hasAttribute('data-create-variant-axis')) {
                    requiredFields.push(field);
                }
            });
            if (requiredFields.length === 0) {
                return true;
            }
            return requiredFields.every(function (field) {
                return !isCreateEavFieldEmpty(field);
            });
        }
        if (stepId === 'variant-preview') {
            if (!createProductTypeSupportsVariants()) {
                return true;
            }
            try {
                return collectCreateVariantAxes().length > 0;
            } catch (error) {
                return false;
            }
        }
        return false;
    }

    function isCreateStepComplete(stepId) {
        if (requiresCreateStepManualConfirm(stepId)) {
            if (!isCreateStepContentReady(stepId)) {
                return false;
            }
            var manualContinueButton = getCreateWizardStepContinueButton(stepId);
            return Boolean(manualContinueButton && manualContinueButton.getAttribute('data-step-confirmed') === '1');
        }
        if (stepId === 'basics') {
            return isCreateStepContentReady(stepId);
        }
        if (stepId === 'variant-values') {
            if (!createProductTypeSupportsVariants()) {
                return true;
            }
            // Image upload alone must not complete this step; user clicks「下一步」.
            var continueButton = getCreateWizardStepContinueButton('variant-values');
            return Boolean(continueButton && continueButton.getAttribute('data-step-confirmed') === '1');
        }
        if (stepId === 'product-attributes') {
            return isCreateStepContentReady(stepId);
        }
        if (stepId === 'variant-preview') {
            var previewContinue = getCreateWizardStepContinueButton('variant-preview');
            return Boolean(previewContinue && previewContinue.getAttribute('data-step-confirmed') === '1');
        }
        return false;
    }

    function getCreateStepIncompleteMessage(stepId) {
        var messages = {
            'basics': '请先填写产品类型、名称和 SKU。',
            'attribute-set': '请先选择属性集，并点击「下一步」确认。',
            'variant-axes': '请至少勾选一个规格值，并点击「下一步」确认。',
            'variant-values': '请先完成规格值预览图步骤，或点击该步的「下一步」继续。',
            'product-attributes': '请先按属性组填写必填商品属性。',
            'variant-preview': '请先确认子规格预览，或点击该步的「下一步」继续。'
        };
        return messages[stepId] || '请先完成上一步。';
    }

    function isCreateStepOptionalContinue(stepId) {
        return stepId === 'variant-values'
            || stepId === 'variant-preview';
    }

    function maybeAdvanceCreateWizard(completedStepId, userInitiated) {
        if (createWizardAdvancing || !userInitiated || !completedStepId) {
            return;
        }
        if (!isCreateStepComplete(completedStepId)) {
            return;
        }
        createWizardAdvancing = true;
        try {
            var currentStepId = completedStepId;
            var guard = 0;
            while (guard < 6) {
                guard += 1;
                var nextStepId = getNextCreateWizardStepId(currentStepId);
                if (nextStepId === '') {
                    return;
                }
                var nextElement = getCreateWizardStepElement(nextStepId);
                if (nextElement && nextElement.open && isCreateStepComplete(nextStepId)) {
                    currentStepId = nextStepId;
                    continue;
                }
                if (!openCreateWizardStep(nextStepId, {silent: false})) {
                    return;
                }
                if (!isCreateStepComplete(nextStepId)) {
                    return;
                }
                currentStepId = nextStepId;
            }
        } finally {
            createWizardAdvancing = false;
        }
    }

    function bindCreateWizardStepContinueButtons() {
        document.querySelectorAll('[data-create-step-continue]').forEach(function (button) {
            if (button.getAttribute('data-create-step-continue-bound') === '1') {
                return;
            }
            button.setAttribute('data-create-step-continue-bound', '1');
            button.addEventListener('click', function () {
                var stepId = String(button.getAttribute('data-create-step-continue') || '').trim();
                if (stepId === '') {
                    return;
                }
                if (stepId === 'product-attributes' || stepId === 'variant-axes' || stepId === 'variant-values') {
                    persistCreateEavDraft();
                }
                var blockedStepId = getFirstIncompletePreviousCreateWizardStepId(stepId);
                if (blockedStepId !== '') {
                    notify('warning', getCreateStepIncompleteMessage(blockedStepId));
                    openCreateWizardStep(blockedStepId, {silent: true});
                    return;
                }
                if (requiresCreateStepManualConfirm(stepId)) {
                    if (!isCreateStepContentReady(stepId)) {
                        notify('warning', getCreateStepIncompleteMessage(stepId));
                        return;
                    }
                    button.setAttribute('data-step-confirmed', '1');
                    maybeAdvanceCreateWizard(stepId, true);
                    return;
                }
                if (!isCreateStepComplete(stepId)) {
                    if (isCreateStepOptionalContinue(stepId)) {
                        button.setAttribute('data-step-confirmed', '1');
                    } else {
                        notify('warning', getCreateStepIncompleteMessage(stepId));
                        return;
                    }
                } else {
                    button.setAttribute('data-step-confirmed', '1');
                }
                maybeAdvanceCreateWizard(stepId, true);
            });
        });
    }

    function bindCreateWizardBasicsAdvance() {
        // Intentionally empty: field input/change must NOT auto-advance the wizard.
        // Filling type/name/sku used to call maybeAdvanceCreateWizard on every change,
        // yanking the user out of basics and cascading EAV/variant redraws (felt like a hang).
        // Advancement is only via data-create-step-continue="basics".
    }

    function bindCreateWizardProductAttributesAdvance() {
        var root = document.getElementById('product-create-product-attributes');
        if (!root || root.getAttribute('data-create-wizard-bound') === '1') {
            return;
        }
        root.setAttribute('data-create-wizard-bound', '1');
        // No input/change auto-advance — only the step「下一步」button advances.
    }

    function initializeCreateWizard() {
        var wizard = document.getElementById('product-create-wizard');
        if (!wizard) {
            return;
        }
        document.querySelectorAll('#product-create-form .w-product-create__step').forEach(function (step) {
            if (step.getAttribute('data-create-wizard-toggle-bound') === '1') {
                return;
            }
            step.setAttribute('data-create-wizard-toggle-bound', '1');
            step.addEventListener('toggle', function () {
                if (createWizardToggleGuard || !step.open) {
                    return;
                }
                var stepId = String(step.getAttribute('data-create-step') || '').trim();
                var blockedStepId = getFirstIncompletePreviousCreateWizardStepId(stepId);
                createWizardToggleGuard = true;
                try {
                    if (blockedStepId !== '') {
                        step.open = false;
                        notify('warning', getCreateStepIncompleteMessage(blockedStepId));
                        openCreateWizardStep(blockedStepId, {silent: true});
                        return;
                    }
                    document.querySelectorAll('#product-create-form .w-product-create__step').forEach(function (peer) {
                        if (peer !== step) {
                            peer.open = false;
                        }
                    });
                    rememberCreateWizardStep(stepId);
                } finally {
                    createWizardToggleGuard = false;
                }
            });
        });
        bindCreateWizardStepContinueButtons();
        bindCreateWizardBasicsAdvance();
        bindCreateWizardProductAttributesAdvance();
    }

    function initializeCreateVariantImagePicker() {
        var dialog = document.querySelector('[data-product-create-variant-image-dialog]');
        var frame = document.querySelector('[data-product-create-variant-image-frame]');
        var close = document.querySelector('[data-product-create-variant-image-close]');
        if (!dialog || !frame) {
            return;
        }

        function closePicker() {
            createVariantImageTarget = null;
            closeProductMediaDialog(dialog, 'product-create-variant-image');
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target instanceof Element
                ? event.target.closest('[data-create-variant-option-image-open]')
                : null;
            if (!trigger) {
                return;
            }
            event.preventDefault();
            createVariantImageTarget = trigger;
            try {
                var pickerUrl = new URL(frame.getAttribute('data-src') || '', window.location.href);
                if (pickerUrl.origin !== window.location.origin) {
                    throw new Error('媒体选择器必须与后台同源');
                }
                pickerUrl = appendCreatePickerIdentity(pickerUrl, 'variant', 'variant_image');
                frame.src = pickerUrl.href;
                if (!openProductMediaDialog(dialog, {trigger: 'product-create-variant-image'})) {
                    throw new Error('媒体选择器不可用');
                }
            } catch (error) {
                notify('error', messageFrom(error, '媒体选择器不可用'));
            }
        });

        if (close) {
            close.addEventListener('click', closePicker);
        }

        window.addEventListener('message', function (event) {
            if (!createVariantImageTarget || event.source !== frame.contentWindow
                || event.origin !== window.location.origin
                || !event.data || typeof event.data !== 'object'
                || String(event.data.target || '') !== 'product-create-variant-image'
            ) {
                return;
            }
            if (event.data.type === 'weline-media-manager-cancel') {
                closePicker();
                return;
            }
            if (event.data.type !== 'weline-media-manager-select'
                || !Array.isArray(event.data.files)
                || event.data.files.length === 0
            ) {
                return;
            }
            var file = event.data.files[0] || {};
            var url = resolveMediaPickerFileUrl(file);
            if (url === '') {
                notify('error', '未能获取所选图片地址，请重试或换一张图片。');
                closePicker();
                return;
            }
            var axisCode = String(createVariantImageTarget.getAttribute('data-axis-code') || '');
            var optionValue = String(createVariantImageTarget.getAttribute('data-option-value') || '');
            var hidden = document.querySelector(
                '[data-create-variant-option-image][data-axis-code="' + axisCode + '"][data-option-value="' + optionValue + '"]'
            );
            if (hidden) {
                hidden.value = url;
                hidden.dispatchEvent(new Event('change', {bubbles: true}));
            }
            // Selecting a preview image must stay on this step; only「下一步」advances.
            renderCreateVariantValueConfig();
            renderCreateVariantPreview(false);
            schedulePersistCreateEavDraft();
            closePicker();
        });
    }

    function findAttributeSetById(setId, catalog) {
        var normalized = String(setId || '').trim();
        if (normalized === '') {
            return null;
        }
        var match = null;
        catalogSetsFromPayload(catalog).forEach(function (attributeSet) {
            if (!attributeSet || typeof attributeSet !== 'object' || match) {
                return;
            }
            var candidates = [
                attributeSet.attribute_set_id,
                attributeSet.set_id,
                attributeSet.id,
                attributeSet.code
            ].map(function (value) {
                return String(value == null ? '' : value).trim();
            }).filter(Boolean);
            if (candidates.indexOf(normalized) >= 0) {
                match = attributeSet;
            }
        });
        return match;
    }

    function resolveEavControlType(attribute) {
        var valueType = String(
            attribute.value_type || attribute.input_type || attribute.frontend_input || ''
        ).toLowerCase();
        if (['number', 'integer', 'int', 'decimal', 'float'].indexOf(valueType) >= 0) {
            return 'number';
        }
        if (['boolean', 'bool', 'toggle'].indexOf(valueType) >= 0) {
            return 'boolean';
        }
        if (['date', 'datetime'].indexOf(valueType) >= 0) {
            return 'date';
        }
        if (valueType === 'select' || valueType === 'dropdown') {
            return 'select';
        }
        if (['multiselect', 'multi_select', 'multiple'].indexOf(valueType) >= 0) {
            return 'multiselect';
        }
        if (valueType === 'json') {
            return 'json';
        }
        if (attribute.multiple || attribute.data_is_multiple) {
            return 'multiselect';
        }
        var hasOption = Boolean(attribute.has_option || attribute.data_has_option);
        var element = String(attribute.element || '').toLowerCase();
        var typeCode = String(attribute.type_code || '').toLowerCase();
        if (hasOption || element === 'select' || element === 'radio') {
            return 'select';
        }
        if (['number', 'integer', 'int', 'decimal', 'float', 'numeric'].indexOf(typeCode) >= 0) {
            return 'number';
        }
        if (['bool', 'boolean'].indexOf(typeCode) >= 0 || element === 'checkbox') {
            return 'boolean';
        }
        if (['date', 'datetime', 'timestamp', 'time'].indexOf(typeCode) >= 0) {
            return 'date';
        }
        if (['json', 'array', 'serialized'].indexOf(typeCode) >= 0) {
            return 'json';
        }
        return 'string';
    }

    function createProductTypeSupportsVariants() {
        var typeInput = document.getElementById('product-create-type');
        var option = typeInput && typeInput.options[typeInput.selectedIndex];
        return Boolean(option && option.getAttribute('data-supports-variants') === '1');
    }

    function isCreateVariantAxisGroup(group) {
        if (!group || typeof group !== 'object') {
            return false;
        }
        var code = String(group.code || group.group_code || '').toLowerCase();
        return code === 'hanfu_variants' || code.endsWith('_variants');
    }

    function resolveCreateAttributeCatalog() {
        if (state.creation_context && state.creation_context.attribute_catalog) {
            return state.creation_context.attribute_catalog;
        }
        if (state.snapshot && state.snapshot.attribute_catalog) {
            return state.snapshot.attribute_catalog;
        }
        return null;
    }

    function getCreateSelectedAttributeSetId() {
        var setIdInput = document.getElementById('product-create-attribute-set-id');
        var setCodeInput = document.getElementById('product-create-attribute-set-code');
        var setId = setIdInput ? String(setIdInput.value || '').trim() : '';
        if (setId !== '') {
            return setId;
        }
        return setCodeInput ? String(setCodeInput.value || '').trim() : '';
    }

    function countExpectedCreateProductAttributeFields(setId) {
        var catalog = resolveCreateAttributeCatalog();
        var attributeSet = findAttributeSetById(setId, catalog);
        if (!attributeSet) {
            return 0;
        }
        var supportsVariants = createProductTypeSupportsVariants();
        var count = 0;
        (Array.isArray(attributeSet.groups) ? attributeSet.groups : []).forEach(function (group) {
            if (!group || typeof group !== 'object') {
                return;
            }
            if (isCreateVariantAxisGroup(group) && supportsVariants) {
                return;
            }
            (Array.isArray(group.attributes) ? group.attributes : []).forEach(function (attribute) {
                if (!attribute || typeof attribute !== 'object') {
                    return;
                }
                var code = String(attribute.attribute_code || attribute.code || '');
                if (!code || CREATE_EAV_SKIP_CODES[code]) {
                    return;
                }
                count += 1;
            });
        });
        return count;
    }

    function buildCreateEavFieldHelp(controlType) {
        if (controlType === 'multiselect') {
            return '<p class="w-field__help"><lang>可多选，按住 Ctrl / Cmd 点选多个值。</lang></p>';
        }
        if (controlType === 'json') {
            return '<p class="w-field__help"><lang>请填写合法 JSON。</lang></p>';
        }
        return '';
    }

    function buildCreateVariantAxisFieldHtml(attribute, setId, groupId, attributeIndex) {
        var code = String(attribute.attribute_code || attribute.code || '');
        if (!code || CREATE_EAV_SKIP_CODES[code]) {
            return '';
        }
        var label = String(attribute.label || attribute.name || code);
        var options = Array.isArray(attribute.options) ? attribute.options : [];
        var required = Boolean(attribute.required || attribute.is_required);
        var chips = options.map(function (option, optionIndex) {
            var normalized = normalizeCreateOptionMeta(option, optionIndex);
            var optionValue = String(normalized.code || normalized.value || normalized.option_id || optionIndex);
            var optionLabel = String(normalized.label || normalized.name || normalized.code || optionValue);
            var swatch = String(normalized.swatch_color || normalized.swatch || '').trim();
            var swatchImage = String(normalized.swatch_image || '').trim();
            var chipId = 'product-create-axis-' + code + '-' + optionIndex;
            var swatchHtml = swatchImage !== ''
                ? '<img class="w-product-create__axis-chip-image" src="' + escapeHtml(swatchImage) + '" alt="">'
                : (swatch !== ''
                    ? '<span class="w-product-create__axis-chip-swatch" style="background:' + escapeHtml(swatch) + '"></span>'
                    : '');
            return '<label class="w-product-create__axis-chip" for="' + escapeHtml(chipId) + '">'
                + '<input type="checkbox" id="' + escapeHtml(chipId) + '" data-create-variant-option value="'
                + escapeHtml(optionValue) + '" data-option-label="' + escapeHtml(optionLabel) + '"'
                + ' data-option-code="' + escapeHtml(optionValue) + '"'
                + (swatch !== '' ? ' data-option-swatch-color="' + escapeHtml(swatch) + '"' : '')
                + '>'
                + swatchHtml + '<span>' + escapeHtml(optionLabel) + '</span></label>';
        }).join('');
        return '<div class="w-product-eav__field w-product-create__variant-axis" data-eav-field data-create-variant-axis'
            + ' data-set-id="' + escapeHtml(String(setId)) + '"'
            + ' data-attribute-code="' + escapeHtml(code) + '" data-axis-label="' + escapeHtml(label) + '"'
            + ' data-value-type="variant-axis" data-required="' + (required ? '1' : '0') + '"'
            + ' data-entity-type="product" data-entity-id="0" data-store-id="0" data-locale="" data-existing-row="0">'
            + '<div class="w-product-eav__field-heading"><span class="w-field__label">' + escapeHtml(label)
            + (required ? ' *' : '') + '</span><code>' + escapeHtml(code) + '</code></div>'
            + '<input type="hidden" data-eav-state value="explicit">'
            + '<div class="w-product-create__axis-chip-grid" data-eav-input>' + chips + '</div>'
            + '<p class="w-field__help"><lang>多规格商品可多选，将按笛卡尔积生成子 SKU。</lang></p></div>';
    }

    function bindCreateVariantPreview(editor) {
        if (!editor) {
            return;
        }
        editor.querySelectorAll('[data-create-variant-option]').forEach(function (input) {
            input.addEventListener('change', function () {
                resetCreateWizardStepConfirm('variant-axes');
                renderCreateVariantValueConfig();
                renderCreateVariantPreview(false);
            });
        });
    }

    function collectCreateVariantAxes() {
        if (!createProductTypeSupportsVariants()) {
            return [];
        }
        var axes = [];
        queryCreateEavFields('[data-create-variant-axis]').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim().toLowerCase();
            var axisLabel = String(field.getAttribute('data-axis-label') || code);
            var options = [];
            field.querySelectorAll('[data-create-variant-option]:checked').forEach(function (input) {
                var optionValue = String(input.value || '');
                var optionLabel = String(input.getAttribute('data-option-label') || optionValue);
                var option = {
                    code: code,
                    value: optionValue,
                    label: optionLabel
                };
                var swatchColor = String(input.getAttribute('data-option-swatch-color') || '').trim();
                var swatchImage = readVariantOptionImageInput(code, optionValue);
                if (swatchColor !== '') {
                    option.swatch_color = swatchColor;
                    option.swatch = swatchColor;
                }
                if (swatchImage !== '') {
                    option.swatch_image = swatchImage;
                }
                options.push(option);
            });
            if (options.length > 0) {
                axes.push({
                    code: code,
                    label: axisLabel,
                    options: options
                });
            }
        });
        return axes;
    }

    function validateCreateVariantAxes() {
        if (!createProductTypeSupportsVariants()) {
            return true;
        }
        try {
            var axes = collectCreateVariantAxes();
            if (axes.length === 0) {
                notify('error', '多规格商品请至少在规格维度中选择一个规格值');
                return false;
            }
            buildVariantCombinations(axes);
            return true;
        } catch (error) {
            notify('error', messageFrom(error, '规格组合无效'));
            return false;
        }
    }

    function renderCreateVariantValueConfig() {
        var target = document.getElementById('product-create-variant-values');
        if (!target || !createProductTypeSupportsVariants()) {
            if (target) {
                target.innerHTML = '';
            }
            return;
        }
        var sections = [];
        queryCreateEavFields('[data-create-variant-axis]').forEach(function (field) {
            var code = String(field.getAttribute('data-attribute-code') || '').trim().toLowerCase();
            var axisLabel = String(field.getAttribute('data-axis-label') || code);
            var selected = [];
            field.querySelectorAll('[data-create-variant-option]:checked').forEach(function (input) {
                selected.push({
                    value: String(input.value || ''),
                    label: String(input.getAttribute('data-option-label') || input.value || ''),
                    swatchColor: String(input.getAttribute('data-option-swatch-color') || '').trim()
                });
            });
            if (selected.length === 0) {
                return;
            }
            var rows = selected.map(function (option) {
                var imageValue = readVariantOptionImageInput(code, option.value);
                var swatchHtml = imageValue !== ''
                    ? '<img class="w-product-create__variant-value-preview" data-create-variant-option-image-preview src="'
                        + escapeHtml(imageValue) + '" alt="">'
                    : (option.swatchColor !== ''
                        ? '<span class="w-product-create__axis-chip-swatch" style="background:'
                            + escapeHtml(option.swatchColor) + '"></span>'
                        : '<span class="w-text" data-tone="muted">—</span>');
                return '<div class="w-product-create__variant-value-row">'
                    + '<div class="w-product-create__variant-value-label">' + swatchHtml
                    + '<span>' + escapeHtml(option.label) + '</span></div>'
                    + '<div class="w-product-create__variant-value-actions">'
                    + '<input type="hidden" data-create-variant-option-image data-axis-code="' + escapeHtml(code)
                    + '" data-option-value="' + escapeHtml(option.value) + '" value="' + escapeHtml(imageValue) + '">'
                    + '<button class="w-button" type="button" data-variant="outline" data-size="sm"'
                    + ' data-create-variant-option-image-open data-axis-code="' + escapeHtml(code)
                    + '" data-option-value="' + escapeHtml(option.value) + '"><lang>上传图片</lang></button>'
                    + (imageValue !== ''
                        ? '<button class="w-button" type="button" data-variant="ghost" data-size="sm" data-tone="danger"'
                            + ' data-create-variant-option-image-clear data-axis-code="' + escapeHtml(code)
                            + '" data-option-value="' + escapeHtml(option.value) + '"><lang>清除</lang></button>'
                        : '')
                    + '</div></div>';
            }).join('');
            sections.push('<section class="w-product-create__variant-value-axis" data-variant-value-axis="'
                + escapeHtml(code) + '"><div class="w-field__label">' + escapeHtml(axisLabel) + '</div>'
                + '<div class="w-product-create__variant-value-grid">' + rows + '</div></section>');
        });
        if (sections.length === 0) {
            target.innerHTML = '<p class="w-field__help"><lang>请先在「规格维度」中勾选规格值，再为图板类规格上传预览图。</lang></p>';
            return;
        }
        target.innerHTML = sections.join('');
        target.querySelectorAll('[data-create-variant-option-image-clear]').forEach(function (button) {
            button.addEventListener('click', function () {
                var axisCode = String(button.getAttribute('data-axis-code') || '');
                var optionValue = String(button.getAttribute('data-option-value') || '');
                var hidden = document.querySelector(
                    '[data-create-variant-option-image][data-axis-code="' + axisCode + '"][data-option-value="' + optionValue + '"]'
                );
                if (hidden) {
                    hidden.value = '';
                    hidden.dispatchEvent(new Event('change', {bubbles: true}));
                }
                renderCreateVariantValueConfig();
                renderCreateVariantPreview(false);
                schedulePersistCreateEavDraft();
            });
        });
    }

    function renderCreateVariantPreview(userInitiated) {
        var panel = document.getElementById('product-create-variant-preview');
        if (!panel) {
            return;
        }
        if (!createProductTypeSupportsVariants()) {
            panel.innerHTML = '';
            return;
        }
        try {
            var axes = collectCreateVariantAxes();
            if (axes.length === 0) {
                panel.innerHTML = '<p class="w-field__help"><lang>请在规格维度中至少选择一个规格值，系统将按笛卡尔积生成子 SKU。</lang></p>';
                return;
            }
            var combinations = buildVariantCombinations(axes);
            var skuPrefix = String(document.getElementById('product-create-sku').value || '').trim().toUpperCase();
            var previewPrefix = skuPrefix !== '' ? skuPrefix : 'SKU';
            var rows = combinations.map(function (generated) {
                var sku = generatedVariantSku(previewPrefix, generated.combination, axes);
                var labels = Object.keys(generated.combination).map(function (axisCode) {
                    var axis = axes.find(function (item) {
                        return item.code === axisCode;
                    });
                    var option = axis && axis.options
                        ? axis.options.find(function (item) {
                            return item.value === generated.combination[axisCode];
                        })
                        : null;
                    var optionLabel = option ? option.label : generated.combination[axisCode];
                    return (axis ? axis.label : axisCode) + '=' + optionLabel;
                }).join(' · ');
                var preview = resolveCombinationPreview(generated.combination, axes);
                var previewHtml = preview.image !== ''
                    ? '<img class="w-product-create__variant-preview-thumb" src="' + escapeHtml(preview.image) + '" alt="">'
                    : (preview.color !== ''
                        ? '<span class="w-product-create__variant-preview-swatch" style="background:'
                            + escapeHtml(preview.color) + '"></span>'
                        : '<span class="w-text" data-tone="muted">—</span>');
                var skuHtml = skuPrefix !== ''
                    ? '<code>' + escapeHtml(sku) + '</code>'
                    : '<code title="填写 SKU 前缀后将生成正式 SKU">' + escapeHtml(sku) + '</code>';
                return '<tr><td>' + previewHtml + '</td><td>' + escapeHtml(labels) + '</td><td>' + skuHtml + '</td></tr>';
            }).join('');
            panel.innerHTML = '<div class="w-stack" data-gap="sm"><div class="w-cluster" data-align="center" data-gap="sm">'
                + '<span class="w-badge" data-tone="info">' + combinations.length + ' <lang>个子规格</lang></span>'
                + (skuPrefix === ''
                    ? '<span class="w-text" data-tone="muted" data-size="sm"><lang>填写 SKU 前缀后可预览正式 SKU</lang></span>'
                    : '')
                + '</div><div class="w-product-create__variant-preview-scroll w-table-wrap"><table class="w-table w-table--compact"><thead><tr>'
                + '<th><lang>预览</lang></th><th><lang>组合</lang></th><th><lang>SKU 预览</lang></th></tr></thead><tbody>'
                + rows + '</tbody></table></div></div>';
        } catch (error) {
            panel.innerHTML = '<div class="w-alert" data-tone="warning">' + escapeHtml(messageFrom(error, '规格组合无效')) + '</div>';
        }
    }

    function buildCreateEavFieldHtml(attribute, setId, groupId, attributeIndex) {
        var code = String(attribute.attribute_code || attribute.code || '');
        if (!code || CREATE_EAV_SKIP_CODES[code]) {
            return '';
        }
        var label = String(attribute.label || attribute.name || code);
        var controlType = resolveEavControlType(attribute);
        var fieldId = 'product-create-eav-' + String(setId + '-' + groupId + '-' + code + '-' + attributeIndex)
            .replace(/[^a-zA-Z0-9_-]+/g, '-');
        var options = Array.isArray(attribute.options) ? attribute.options : [];
        var required = Boolean(attribute.required || attribute.is_required);
        var defaultSelected = '';
        if (code === 'warranty_months' && controlType === 'select') {
            defaultSelected = '12';
        }
        var inputHtml = '';
        if (controlType === 'boolean') {
            inputHtml = '<label class="w-product-eav__checkbox" for="' + escapeHtml(fieldId) + '">'
                + '<input id="' + escapeHtml(fieldId) + '" type="checkbox" data-eav-input>'
                + '<span>启用</span></label>';
        } else if (controlType === 'select' || controlType === 'multiselect') {
            inputHtml = '<select class="w-select" id="' + escapeHtml(fieldId) + '" data-eav-input'
                + (controlType === 'multiselect' ? ' multiple size="5"' : '')
                + (required && controlType === 'select' ? ' required' : '') + '>';
            if (controlType === 'select') {
                inputHtml += '<option value="">请选择</option>';
            }
            options.forEach(function (option, optionIndex) {
                var normalized = option && typeof option === 'object'
                    ? option
                    : {value: option, label: option};
                var optionValue = String(normalized.value || normalized.option_id || normalized.code || optionIndex);
                var optionLabel = String(normalized.label || normalized.name || normalized.code || optionValue);
                var selected = defaultSelected !== '' && optionValue === defaultSelected ? ' selected' : '';
                inputHtml += '<option value="' + escapeHtml(optionValue) + '"' + selected + '>'
                    + escapeHtml(optionLabel) + '</option>';
            });
            inputHtml += '</select>';
        } else if (controlType === 'json') {
            inputHtml = '<textarea class="w-textarea w-code-input" id="' + escapeHtml(fieldId)
                + '" rows="4" data-eav-input' + (required ? ' required' : '') + '></textarea>';
        } else {
            inputHtml = '<input class="w-input" id="' + escapeHtml(fieldId) + '" type="'
                + (controlType === 'number' ? 'number' : (controlType === 'date' ? 'date' : 'text')) + '"'
                + ' data-eav-input' + (controlType === 'number' ? ' step="any"' : '')
                + (required ? ' required' : '') + '>';
        }
        return '<div class="w-product-eav__field" data-eav-field data-attribute-code="' + escapeHtml(code)
            + '" data-set-id="' + escapeHtml(String(setId)) + '"'
            + ' data-value-type="' + escapeHtml(controlType) + '" data-entity-type="product"'
            + ' data-entity-id="0" data-store-id="0" data-locale="" data-existing-row="0"'
            + ' data-required="' + (required ? '1' : '0') + '">'
            + '<div class="w-product-eav__field-heading"><label class="w-field__label" for="'
            + escapeHtml(fieldId) + '">' + escapeHtml(label) + (required ? ' *' : '')
            + '</label><code>' + escapeHtml(code) + '</code></div>'
            + '<input type="hidden" data-eav-state value="explicit">'
            + inputHtml + buildCreateEavFieldHelp(controlType) + '</div>';
    }

    function sortEavGroups(groups) {
        return groups.slice().sort(function (left, right) {
            var leftOrder = parseInt(left && (left.sort_order || left.sortOrder), 10);
            var rightOrder = parseInt(right && (right.sort_order || right.sortOrder), 10);
            if (!isFinite(leftOrder)) {
                leftOrder = 0;
            }
            if (!isFinite(rightOrder)) {
                rightOrder = 0;
            }
            if (leftOrder !== rightOrder) {
                return leftOrder - rightOrder;
            }
            var leftLabel = String(left && (left.label || left.name || left.code || ''));
            var rightLabel = String(right && (right.label || right.name || right.code || ''));
            return leftLabel.localeCompare(rightLabel, 'zh-Hans-CN');
        });
    }

    function renderCreateEavFields(setId) {
        var shell = document.getElementById('product-create-eav-fields');
        var editor = document.getElementById('product-create-eav-editor');
        var variantAxesRoot = document.getElementById('product-create-variant-axes');
        var productAttributesRoot = document.getElementById('product-create-product-attributes');
        if (!shell || !editor || !variantAxesRoot || !productAttributesRoot) {
            return;
        }
        var catalog = resolveCreateAttributeCatalog();
        var attributeSet = findAttributeSetById(setId, catalog);
        if (!attributeSet) {
            shell.hidden = true;
            editor.innerHTML = '';
            variantAxesRoot.innerHTML = '';
            productAttributesRoot.innerHTML = '<p class="w-field__help" data-testid="product-create-product-attributes-empty">'
                + '<lang>未找到属性集目录，请重新选择属性集。</lang></p>';
            updateCreateWizardVisibility();
            return;
        }
        var setKey = String(
            attributeSet.attribute_set_id || attributeSet.set_id || attributeSet.id || attributeSet.code || setId
        );
        var groups = sortEavGroups(Array.isArray(attributeSet.groups) ? attributeSet.groups : []);
        var variantAxesHtml = '';
        var productAttributesHtml = '';
        groups.forEach(function (group, groupIndex) {
            if (!group || typeof group !== 'object') {
                return;
            }
            var groupId = String(group.attribute_group_id || group.group_id || group.id || group.code || groupIndex);
            var groupLabel = String(group.label || group.name || group.code || groupId);
            var groupAttributes = Array.isArray(group.attributes) ? group.attributes : [];
            var variantGroup = isCreateVariantAxisGroup(group);
            var groupHtml = '';
            groupAttributes.forEach(function (attribute, attributeIndex) {
                if (!attribute || typeof attribute !== 'object') {
                    return;
                }
                if (variantGroup && createProductTypeSupportsVariants()) {
                    groupHtml += buildCreateVariantAxisFieldHtml(attribute, setKey, groupId, attributeIndex);
                } else {
                    groupHtml += buildCreateEavFieldHtml(attribute, setKey, groupId, attributeIndex);
                }
            });
            if (groupHtml === '') {
                return;
            }
            var groupHint = '';
            if (variantGroup && createProductTypeSupportsVariants()) {
                groupHint = '<p class="w-field__help w-product-create__variant-group-hint">'
                    + '<lang>多选规格值后将按笛卡尔积生成子 SKU。</lang></p>';
            }
            var fieldset = '<fieldset class="w-product-eav__group" data-eav-group="'
                + escapeHtml(groupId) + '" data-eav-group-code="' + escapeHtml(String(group.code || group.group_code || ''))
                + '"><legend>' + escapeHtml(groupLabel) + '</legend>' + groupHint
                + '<div class="w-product-eav__grid">' + groupHtml + '</div></fieldset>';
            if (variantGroup && createProductTypeSupportsVariants()) {
                variantAxesHtml += fieldset;
            } else {
                productAttributesHtml += fieldset;
            }
        });
        variantAxesRoot.innerHTML = variantAxesHtml;
        productAttributesRoot.innerHTML = productAttributesHtml !== ''
            ? productAttributesHtml
            : '<p class="w-field__help" data-testid="product-create-product-attributes-empty">'
                + '<lang>当前属性集没有可填写的商品属性（非规格组）。</lang></p>';
        // Keep legacy editor node empty — do not mirror fields (would overwrite draft persist).
        editor.innerHTML = '';
        shell.hidden = variantAxesHtml === '' && productAttributesHtml === '';
        bindCreateVariantPreview(variantAxesRoot);
        bindCreateEavDraftPersistence();
        bindCreateEavDraftFieldNodes(productAttributesRoot);
        bindCreateEavDraftFieldNodes(variantAxesRoot);
        document.querySelectorAll('[data-create-step-continue]').forEach(function (button) {
            button.removeAttribute('data-step-confirmed');
        });
        bindCreateWizardStepContinueButtons();
        bindCreateWizardProductAttributesAdvance();
        updateCreateWizardVisibility();
        renderCreateVariantValueConfig();
        renderCreateVariantPreview(false);
    }

    function collectCreateAttributes() {
        // Only the visible product-attributes step — hidden #product-create-eav-editor mirrors
        // the same fields and would duplicate rows (product_attribute_duplicate on create).
        var fieldNodes = document.querySelectorAll('#product-create-product-attributes [data-eav-field]');
        return normalizeAttributeRows(Array.prototype.map.call(
            fieldNodes,
            function (field) {
                if (createProductTypeSupportsVariants() && field.hasAttribute('data-create-variant-axis')) {
                    return null;
                }
                var row = visualAttributeRow(field, {});
                if (row) {
                    row.is_required = field.getAttribute('data-required') === '1';
                    // Create fields use data-entity-id="0"; omit so server binds to new productId.
                    if (!row.entity_id || parseInt(row.entity_id, 10) <= 0) {
                        delete row.entity_id;
                    }
                }
                return row;
            }
        ).filter(function (row) {
            if (!row) {
                return false;
            }
            if (row.is_required) {
                return true;
            }
            var value = row.value;
            if (value === null || value === undefined || value === '') {
                return false;
            }
            if (Array.isArray(value) && value.length === 0) {
                return false;
            }
            return true;
        }));
    }

    function validateCreateEavFields() {
        var setFlow = document.getElementById('product-create-set-flow');
        if (setFlow && setFlow.hidden) {
            return true;
        }
        var productRoot = document.getElementById('product-create-product-attributes');
        if (!productRoot || !productRoot.querySelector('[data-eav-field]')) {
            return true;
        }
        var valid = true;
        var firstInvalidInput = null;
        queryCreateEavFields('[data-eav-field][data-required="1"]').forEach(function (field) {
            var input = field.querySelector('[data-eav-input]');
            var valueType = String(field.getAttribute('data-value-type') || 'string');
            var empty = false;
            if (valueType === 'boolean') {
                empty = false;
            } else if (valueType === 'variant-axis') {
                empty = field.querySelectorAll('[data-create-variant-option]:checked').length === 0;
            } else if (valueType === 'multiselect') {
                empty = !input || Array.prototype.filter.call(input.options, function (option) {
                    return option.selected;
                }).length === 0;
            } else if (valueType === 'select') {
                empty = !input || String(input.value || '').trim() === '';
            } else {
                empty = !input || String(input.value || '').trim() === '';
            }
            field.classList.toggle('is-invalid', empty);
            if (empty) {
                valid = false;
                if (!firstInvalidInput && input) {
                    firstInvalidInput = input;
                }
            }
        });
        if (!valid) {
            notify('error', '请填写属性集中标记为必填的属性');
            if (firstInvalidInput && typeof firstInvalidInput.focus === 'function') {
                firstInvalidInput.focus();
            }
        }
        return valid;
    }

    function catalogSetsFromPayload(catalog) {
        if (Array.isArray(catalog)) {
            return catalog;
        }
        if (catalog && typeof catalog === 'object') {
            return catalog.sets || catalog.attribute_sets || [];
        }
        return [];
    }

    function attributeRowsByCodeFromList(rows) {
        var map = {};
        (rows || []).forEach(function (row) {
            if (!row || typeof row !== 'object') {
                return;
            }
            if (String(row.entity_type || 'product') !== 'product') {
                return;
            }
            if (parseInt(row.store_id, 10) || 0) {
                return;
            }
            if (String(row.locale || '') !== '') {
                return;
            }
            var code = String(row.attribute_code || '');
            if (code !== '' && !map[code]) {
                map[code] = row;
            }
        });
        return map;
    }

    function collectEavFieldValues(root) {
        var preserved = {};
        if (!root) {
            return preserved;
        }
        root.querySelectorAll('[data-eav-field]').forEach(function (field) {
            var row = visualAttributeRow(field, {});
            if (row && row.attribute_code) {
                preserved[row.attribute_code] = row;
            }
        });
        return preserved;
    }

    function buildEavFieldHtml(attribute, setId, groupId, attributeIndex, existingRow, productEntityId) {
        var code = String(attribute.attribute_code || attribute.code || '');
        if (!code || CREATE_EAV_SKIP_CODES[code]) {
            return '';
        }
        var label = String(attribute.label || attribute.name || code);
        var controlType = resolveEavControlType(attribute);
        var fieldValue = existingRow ? existingRow.value : null;
        var scopeState = String(existingRow && existingRow.scope_state
            ? existingRow.scope_state
            : (existingRow && existingRow.cleared ? 'cleared' : 'explicit'));
        if (['explicit', 'cleared', 'inherit'].indexOf(scopeState) < 0) {
            scopeState = 'explicit';
        }
        var fieldId = 'product-eav-' + String(setId + '-' + groupId + '-' + code + '-' + attributeIndex)
            .replace(/[^a-zA-Z0-9_-]+/g, '-');
        var options = Array.isArray(attribute.options) ? attribute.options : [];
        var selectedValues = Array.isArray(fieldValue)
            ? fieldValue.map(String)
            : [String(fieldValue == null ? '' : fieldValue)];
        var inputDisabled = scopeState !== 'explicit';
        var required = Boolean(attribute.required || attribute.is_required);
        var inputHtml = '';
        if (controlType === 'boolean') {
            inputHtml = '<label class="w-product-eav__checkbox" for="' + escapeHtml(fieldId) + '">'
                + '<input id="' + escapeHtml(fieldId) + '" type="checkbox" data-eav-input'
                + (fieldValue ? ' checked' : '')
                + (inputDisabled ? ' disabled' : '')
                + '><span>启用</span></label>';
        } else if (controlType === 'select' || controlType === 'multiselect') {
            inputHtml = '<select class="w-select" id="' + escapeHtml(fieldId) + '" data-eav-input'
                + (controlType === 'multiselect' ? ' multiple size="5"' : '')
                + (inputDisabled ? ' disabled' : '') + '>';
            if (controlType === 'select') {
                inputHtml += '<option value="">请选择</option>';
            }
            options.forEach(function (option, optionIndex) {
                var normalized = option && typeof option === 'object'
                    ? option
                    : {value: option, label: option};
                var optionValue = String(normalized.value || normalized.option_id || normalized.code || optionIndex);
                var optionLabel = String(normalized.label || normalized.name || normalized.code || optionValue);
                inputHtml += '<option value="' + escapeHtml(optionValue) + '"'
                    + (selectedValues.indexOf(optionValue) >= 0 ? ' selected' : '')
                    + '>' + escapeHtml(optionLabel) + '</option>';
            });
            inputHtml += '</select>';
        } else if (controlType === 'json') {
            var jsonValue = fieldValue;
            if (Array.isArray(jsonValue) || (jsonValue && typeof jsonValue === 'object')) {
                jsonValue = JSON.stringify(jsonValue, null, 0);
            }
            inputHtml = '<textarea class="w-textarea w-code-input" id="' + escapeHtml(fieldId)
                + '" rows="5" data-eav-input' + (inputDisabled ? ' disabled' : '') + '>'
                + escapeHtml(jsonValue == null ? '' : jsonValue) + '</textarea>';
        } else {
            inputHtml = '<input class="w-input" id="' + escapeHtml(fieldId) + '" type="'
                + (controlType === 'number' ? 'number' : (controlType === 'date' ? 'date' : 'text')) + '"'
                + ' value="' + escapeHtml(fieldValue == null ? '' : fieldValue) + '" data-eav-input'
                + (controlType === 'number' ? ' step="any"' : '')
                + (inputDisabled ? ' disabled' : '') + '>';
        }
        return '<div class="w-product-eav__field" data-eav-field data-attribute-code="' + escapeHtml(code)
            + '" data-value-type="' + escapeHtml(controlType) + '" data-entity-type="'
            + escapeHtml(existingRow && existingRow.entity_type ? existingRow.entity_type : 'product')
            + '" data-entity-id="' + escapeHtml(existingRow && existingRow.entity_id ? existingRow.entity_id : productEntityId)
            + '" data-store-id="' + escapeHtml(existingRow && existingRow.store_id != null ? existingRow.store_id : 0)
            + '" data-locale="' + escapeHtml(existingRow && existingRow.locale ? existingRow.locale : '')
            + '" data-existing-row="' + (existingRow ? '1' : '0') + '" data-required="' + (required ? '1' : '0') + '">'
            + '<div class="w-product-eav__field-heading"><label class="w-field__label" for="'
            + escapeHtml(fieldId) + '">' + escapeHtml(label) + (required ? ' *' : '')
            + '</label><code>' + escapeHtml(code) + '</code></div>'
            + '<select class="w-select w-product-eav__state" data-eav-state aria-label="' + escapeHtml(label) + ' 取值方式">'
            + '<option value="explicit"' + (scopeState === 'explicit' ? ' selected' : '') + '>本站填写</option>'
            + '<option value="cleared"' + (scopeState === 'cleared' ? ' selected' : '') + '>明确清空</option>'
            + '<option value="inherit"' + (scopeState === 'inherit' ? ' selected' : '') + '>继承默认</option>'
            + '</select>' + inputHtml
            + '<p class="w-field__help" data-eav-state-help>本站填写会保存当前值；清空和继承不会把零值误判为缺失。</p></div>';
    }

    function buildProductEavEditorSections(catalogPayload, preservedValues) {
        var sets = catalogSetsFromPayload(catalogPayload.attribute_catalog);
        var selectedSetId = String(catalogPayload.selected_attribute_set_id || '0');
        var lockAttributeSet = Boolean(catalogPayload.lock_attribute_set);
        var productEntityId = parseInt(catalogPayload.product_id, 10) || 0;
        var rowsByCode = attributeRowsByCodeFromList(catalogPayload.attributes || []);
        Object.keys(preservedValues || {}).forEach(function (code) {
            rowsByCode[code] = preservedValues[code];
        });
        if (!sets.length) {
            return '<div class="w-alert" data-tone="warning">当前没有可用属性集。请先在 EAV 属性管理中启用属性定义。</div>';
        }
        var html = '<div class="w-product-eav__toolbar"><div class="w-field">'
            + '<label class="w-field__label" for="product-attribute-set">属性集</label>'
            + '<select class="w-select" id="product-attribute-set" data-eav-set-selector'
            + (lockAttributeSet ? ' disabled' : '') + '>';
        sets.forEach(function (attributeSet, setIndex) {
            if (!attributeSet || typeof attributeSet !== 'object') {
                return;
            }
            var setId = String(attributeSet.attribute_set_id || attributeSet.set_id || attributeSet.id || attributeSet.code || setIndex);
            var setLabel = String(attributeSet.label || attributeSet.name || attributeSet.code || setId);
            html += '<option value="' + escapeHtml(setId) + '"'
                + (setId === selectedSetId ? ' selected' : '') + '>' + escapeHtml(setLabel) + '</option>';
        });
        html += '</select></div><p class="w-field__help">'
            + (lockAttributeSet
                ? '编辑中的商品不可更换属性集，仍可在当前集下添加属性组/属性，或使用自由属性。'
                : '切换属性集只改变当前可视化区域；其他属性行仍由 EAV 按原属性集保留。')
            + '</p></div>';
        sets.forEach(function (attributeSet, setIndex) {
            if (!attributeSet || typeof attributeSet !== 'object') {
                return;
            }
            var setId = String(attributeSet.attribute_set_id || attributeSet.set_id || attributeSet.id || attributeSet.code || setIndex);
            var groups = Array.isArray(attributeSet.groups) ? attributeSet.groups : [];
            html += '<div class="w-product-eav__set" data-eav-set-panel="' + escapeHtml(setId) + '"'
                + (setId === selectedSetId ? '' : ' hidden') + '>';
            groups.forEach(function (group, groupIndex) {
                if (!group || typeof group !== 'object') {
                    return;
                }
                var groupId = String(group.attribute_group_id || group.group_id || group.id || group.code || groupIndex);
                var groupLabel = String(group.label || group.name || group.code || groupId);
                var groupAttributes = Array.isArray(group.attributes) ? group.attributes : [];
                html += '<fieldset class="w-product-eav__group"><legend>' + escapeHtml(groupLabel) + '</legend><div class="w-product-eav__grid">';
                groupAttributes.forEach(function (attribute, attributeIndex) {
                    if (!attribute || typeof attribute !== 'object') {
                        return;
                    }
                    var code = String(attribute.attribute_code || attribute.code || '');
                    html += buildEavFieldHtml(
                        attribute,
                        setId,
                        groupId,
                        attributeIndex,
                        rowsByCode[code] || null,
                        productEntityId
                    );
                });
                html += '</div></fieldset>';
            });
            html += '</div>';
        });
        return html;
    }

    function currentGlobalProductUuid() {
        var identity = state.snapshot && state.snapshot.identity ? state.snapshot.identity : {};
        return String(identity.global_product_uuid || '');
    }

    function resolveTargetGroupIdForCurrentSet(catalogPayload) {
        var selector = document.querySelector('[data-eav-set-selector]');
        var setId = selector ? String(selector.value || '') : '';
        var sets = catalogSetsFromPayload(
            catalogPayload && catalogPayload.attribute_catalog
                ? catalogPayload.attribute_catalog
                : (state.snapshot ? state.snapshot.attribute_catalog : [])
        );
        var targetSet = null;
        sets.forEach(function (set) {
            if (!set || typeof set !== 'object') {
                return;
            }
            var candidate = String(set.attribute_set_id || set.set_id || set.id || set.code || '');
            if (candidate === setId) {
                targetSet = set;
            }
        });
        if (!targetSet && sets.length > 0) {
            targetSet = sets[0];
        }
        if (!targetSet || !Array.isArray(targetSet.groups) || !targetSet.groups.length) {
            return 0;
        }
        var group = targetSet.groups[0];
        return parseInt(group.attribute_group_id || group.group_id || group.id || 0, 10) || 0;
    }

    function normalizeEavBusinessResponse(response) {
        var current = response;
        for (var depth = 0; depth < 3; depth += 1) {
            if (!current || typeof current !== 'object') {
                break;
            }
            if (Object.prototype.hasOwnProperty.call(current, 'success')) {
                return current;
            }
            if (!current.data || typeof current.data !== 'object') {
                break;
            }
            current = current.data;
        }
        return current && typeof current === 'object' ? current : {success: true, data: current};
    }

    function eavAdminPost(action, params) {
        return window.Weline.load('api').then(function (api) {
            return api.resource('eav_admin');
        }).then(function (resource) {
            var values = new URLSearchParams();
            Object.keys(params || {}).forEach(function (key) {
                if (params[key] !== undefined && params[key] !== null) {
                    values.set(key, String(params[key]));
                }
            });
            values.set('entity_code', 'product');
            return resource.adminRequest({
                url: 'eav/backend/manager/' + action,
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: values.toString()
            });
        }).then(normalizeEavBusinessResponse);
    }

    function batchMoveAttributesToGroup(attributes, groupId) {
        return attributes.reduce(function (chain, attribute) {
            return chain.then(function () {
                var attributeId = parseInt(attribute.attributeId || attribute.id || 0, 10);
                if (!attributeId || !groupId) {
                    return Promise.resolve();
                }
                return eavAdminPost('move', {
                    type: 'attribute',
                    id: attributeId,
                    target_type: 'group',
                    target_id: groupId
                }).then(function (business) {
                    if (business && business.success === false) {
                        throw new Error(business.message || '移动属性失败');
                    }
                });
            });
        }, Promise.resolve());
    }

    function refreshProductAttributeCatalog() {
        var root = document.getElementById('product-eav-editor');
        if (!root) {
            return Promise.resolve();
        }
        var uuid = currentGlobalProductUuid();
        if (!uuid) {
            return Promise.resolve();
        }
        var preservedValues = collectEavFieldValues(root);
        var advanced = root.querySelector('.w-product-eav__advanced');
        return call('attributeCatalog', {
            website_id: parseInt(state.website_id, 10) || 0,
            global_product_uuid: uuid
        }).then(function (result) {
            var catalogPayload = result.catalog || result;
            if (!catalogPayload || typeof catalogPayload !== 'object') {
                throw new Error('属性目录响应无效');
            }
            if (state.snapshot) {
                state.snapshot.attribute_catalog = catalogPayload.attribute_catalog;
                state.snapshot.attributes = catalogPayload.attributes || state.snapshot.attributes;
            }
            var sections = buildProductEavEditorSections(catalogPayload, preservedValues);
            Array.prototype.slice.call(root.children).forEach(function (child) {
                if (child === advanced) {
                    return;
                }
                root.removeChild(child);
            });
            var wrapper = document.createElement('div');
            wrapper.innerHTML = sections;
            while (wrapper.firstChild) {
                root.insertBefore(wrapper.firstChild, advanced);
            }
            initializeEavEditor();
        });
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

    function initializeEavStructureDrawer() {
        var drawer = document.querySelector('[data-product-eav-drawer]');
        if (!drawer) {
            return;
        }
        var title = drawer.querySelector('[data-product-eav-drawer-title]');
        var panels = drawer.querySelectorAll('[data-product-eav-drawer-panel]');
        var titles = {
            'select-set': '选择属性集',
            'select-attribute': '选择属性',
            'manage-set': '管理属性结构',
            'manage-free': '自由属性',
        };
        var openDrawer = function (mode) {
            Array.prototype.forEach.call(panels, function (panel) {
                panel.hidden = panel.getAttribute('data-product-eav-drawer-panel') !== mode;
            });
            if (title) {
                title.textContent = titles[mode] || titles['manage-set'];
            }
            drawer.hidden = false;
            document.body.classList.add('w-product-eav-drawer-open');
        };
        var closeDrawer = function () {
            drawer.hidden = true;
            document.body.classList.remove('w-product-eav-drawer-open');
        };
        Array.prototype.forEach.call(document.querySelectorAll('[data-product-eav-drawer-open]'), function (button) {
            button.addEventListener('click', function () {
                openDrawer(button.getAttribute('data-product-eav-drawer-open') || 'manage-set');
            });
        });
        Array.prototype.forEach.call(drawer.querySelectorAll('[data-product-eav-drawer-close]'), function (button) {
            button.addEventListener('click', closeDrawer);
        });
        document.addEventListener('weline:eav:select-set', function (event) {
            var detail = event.detail || {};
            var setId = detail.setId ? String(detail.setId) : '';
            var setCode = detail.code ? String(detail.code) : '';
            var setName = detail.name ? String(detail.name) : setCode;
            var createSetId = document.getElementById('product-create-attribute-set-id');
            var createSetCode = document.getElementById('product-create-attribute-set-code');
            var createSetLabel = document.getElementById('product-create-attribute-set-label');
            var createSetName = document.getElementById('product-create-attribute-set-name');
            function syncCreateAttributeSetChip(label) {
                if (!(createSetLabel instanceof HTMLElement)) {
                    return;
                }
                var text = String(label || '').trim();
                createSetLabel.textContent = text || '未选择属性集';
                createSetLabel.classList.toggle('is-selected', text !== '');
                createSetLabel.classList.toggle('is-empty', text === '');
                createSetLabel.setAttribute('data-selected', text !== '' ? '1' : '0');
            }
            if (createSetCode) {
                if (setId !== '' && createSetId) {
                    writeScopedDraftField(createSetId, setId, {forceEvent: true});
                }
                writeScopedDraftField(createSetCode, setCode, {forceEvent: true});
                if (createSetName) {
                    writeScopedDraftField(createSetName, setName, {forceEvent: true});
                }
                patchProductCreateDraftLocal({
                    attribute_set_id: setId,
                    attribute_set: setCode,
                    attribute_set_label: setName,
                    create_eav_attributes_json: '',
                    create_variant_axes_json: '',
                    create_variant_option_images_json: ''
                });
                writeScopedDraftField(document.getElementById('product-create-eav-attributes-json'), '');
                writeScopedDraftField(document.getElementById('product-create-variant-axes-json'), '');
                writeScopedDraftField(document.getElementById('product-create-variant-option-images-json'), '');
                syncCreateAttributeSetChip(setName || setCode || setId);
                window.__welineCreateEavDraftApplied = false;
                window.__welineCreateEavUserEdited = false;
                renderCreateEavFields(setId || setCode);
                updateCreateWizardVisibility();
                resetCreateWizardStepConfirm('attribute-set');
                closeDrawer();
                return;
            }
            var selector = document.querySelector('[data-eav-set-selector]');
            if (selector && setId !== '') {
                selector.value = setId;
                selector.dispatchEvent(new Event('change', {bubbles: true}));
            }
            closeDrawer();
        });
        document.addEventListener('weline:eav:select-cancel', function () {
            closeDrawer();
        });
        document.addEventListener('weline:eav:select-attribute', function (event) {
            var attributes = event.detail && Array.isArray(event.detail.attributes) ? event.detail.attributes : [];
            if (!attributes.length) {
                return;
            }
            var groupId = resolveTargetGroupIdForCurrentSet();
            if (!groupId) {
                notify('error', '当前属性集没有可用的属性组，请先在 EAV 中创建属性组');
                return;
            }
            batchMoveAttributesToGroup(attributes, groupId).then(function () {
                notify('success', '已将 ' + String(attributes.length) + ' 个属性加入当前属性集');
                closeDrawer();
                return refreshProductAttributeCatalog();
            }).then(function () {
                document.querySelectorAll('[data-w-component="eav-manager"]').forEach(function (manager) {
                    manager.dispatchEvent(new CustomEvent('weline:eav:reload-tree', {bubbles: true}));
                });
            }).catch(function (error) {
                notify('error', messageFrom(error, '批量加入属性失败'));
            });
        });
        document.addEventListener('weline:eav:structure-changed', function () {
            refreshProductAttributeCatalog().catch(function (error) {
                notify('error', messageFrom(error, '刷新属性目录失败'));
            });
            document.querySelectorAll('[data-w-component="eav-manager"]').forEach(function (manager) {
                manager.dispatchEvent(new CustomEvent('weline:eav:reload-tree', {bubbles: true}));
            });
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
        initializeEavStructureDrawer();
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
                if (selected === 'offers') {
                    // 面板从 hidden 切到可见后才有高度，需重算规格轴 chip 折叠。
                    root.querySelectorAll('[data-product-variant-axis]').forEach(function (row) {
                        refreshVariantAxisOptionClampForRow(row);
                    });
                }
                if (selected === 'basic') {
                    // 详情 WYSIWYG 在 hidden 面板内首次挂载可能失败/无高度；切到可见后 remount。
                    window.setTimeout(function () {
                        var host = root.querySelector('.w-product-description-editor');
                        if (host && window.Weline && window.Weline.UI) {
                            if (typeof window.Weline.UI.unmount === 'function') {
                                window.Weline.UI.unmount(host);
                            }
                            window.Weline.UI.mount(host);
                        }
                        window.dispatchEvent(new Event('resize'));
                    }, 0);
                }
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
                var submitButton = form.querySelector('button[type="submit"]');
                saveB2bTiersIfDirty()
                    .then(function () {
                        return executeCommand('save', payload, submitButton);
                    })
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(function (error) {
                        notify('error', messageFrom(error, '保存商品失败'));
                    });
            });
        }

        initB2bWholesaleOffersUi();

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

    function initializeProductLayoutPanel() {
        var panel = root.querySelector('[data-product-layout-panel]');
        if (!panel) {
            return;
        }
        var productId = parseInt(panel.getAttribute('data-product-id') || '0', 10) || 0;
        var websiteId = parseInt(panel.getAttribute('data-website-id') || '0', 10) || 0;
        var editorBase = String(panel.getAttribute('data-theme-editor-base') || '').trim();
        var optionSelect = panel.querySelector('[data-product-layout-option]');
        var optionCards = panel.querySelector('[data-product-layout-option-cards]');
        var cloneSelect = panel.querySelector('[data-product-layout-clone-from]');
        var scheduleOption = panel.querySelector('[data-product-layout-schedule-option]');
        var scheduleBody = panel.querySelector('[data-product-layout-schedule-body]');
        var effectiveText = panel.querySelector('[data-product-layout-effective-text]');
        var createDialog = panel.querySelector('[data-product-layout-create-dialog]');
        var scheduleDialog = panel.querySelector('[data-product-layout-schedule-dialog]');
        var optionsCache = [];
        var fallbackOptions = [
            { value: 'default', label: '默认' },
            { value: 'festival', label: '节日活动' },
            { value: 'custom', label: '自定义' }
        ];

        function normalizeLayoutOptions(raw) {
            var list = Array.isArray(raw) ? raw : [];
            var byValue = {};
            fallbackOptions.concat(list).forEach(function (opt) {
                if (!opt || typeof opt !== 'object') {
                    return;
                }
                var value = String(opt.value || '').trim();
                if (!value) {
                    return;
                }
                byValue[value] = {
                    value: value,
                    label: String(opt.label || value).trim() || value
                };
            });
            return Object.keys(byValue).sort().map(function (key) {
                return byValue[key];
            });
        }

        function extractLayoutPayload(raw) {
            var current = businessResult(raw) || {};
            if (Array.isArray(current.options)) {
                return current;
            }
            if (current.data && typeof current.data === 'object' && Array.isArray(current.data.options)) {
                return current.data;
            }
            return current;
        }

        function fillOptionSelects(options) {
            optionsCache = normalizeLayoutOptions(options);
            [optionSelect, cloneSelect, scheduleOption].forEach(function (select) {
                if (!select) {
                    return;
                }
                var previous = String(select.value || '');
                select.innerHTML = '';
                optionsCache.forEach(function (opt) {
                    var option = document.createElement('option');
                    option.value = opt.value;
                    option.textContent = opt.label + ' (' + opt.value + ')';
                    select.appendChild(option);
                });
                if (previous && optionsCache.some(function (opt) { return opt.value === previous; })) {
                    select.value = previous;
                } else if (optionsCache.length) {
                    select.value = optionsCache.some(function (opt) { return opt.value === 'default'; })
                        ? 'default'
                        : optionsCache[0].value;
                }
            });
            if (optionCards) {
                optionCards.innerHTML = '';
                optionsCache.forEach(function (opt) {
                    var card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'w-button';
                    card.textContent = opt.label + ' · ' + opt.value;
                    card.addEventListener('click', function () {
                        if (optionSelect) {
                            optionSelect.value = opt.value;
                        }
                        var explicit = panel.querySelector('input[data-product-layout-mode][value="explicit"]');
                        if (explicit) {
                            explicit.checked = true;
                        }
                    });
                    optionCards.appendChild(card);
                });
            }
        }

        function openEditor(layoutOption) {
            if (!editorBase || !layoutOption) {
                notify('error', '缺少可视化编辑入口');
                return;
            }
            var url = new URL(editorBase, window.location.origin);
            url.searchParams.set('page_type', 'product');
            url.searchParams.set('layout_type', 'product');
            url.searchParams.set('layout_option', layoutOption);
            url.searchParams.set('lock_layout', '1');
            url.searchParams.set('lock_layout_context', '1');
            url.searchParams.set('lock_source', 'product');
            url.searchParams.set('product_layout_mode', '1');
            url.searchParams.set('hide_chrome_editing', '1');
            if (productId > 0) {
                url.searchParams.set('virtual_target_type', 'product');
                url.searchParams.set('virtual_target_id', String(productId));
                url.searchParams.set('layout_lock_target_type', 'product');
                url.searchParams.set('layout_lock_target_id', String(productId));
                url.searchParams.set('theme_layout_target_type', 'product');
                url.searchParams.set('theme_layout_target_id', String(productId));
            }
            if (websiteId > 0) {
                url.searchParams.set('website_id', String(websiteId));
            }
            window.open(url.toString(), '_blank', 'noopener');
        }

        function renderSchedules(schedules) {
            if (!scheduleBody) {
                return;
            }
            scheduleBody.innerHTML = '';
            if (!schedules || !schedules.length) {
                scheduleBody.innerHTML = '<tr><td colspan="6" data-tone="muted">暂无定时</td></tr>';
                return;
            }
            schedules.forEach(function (row) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td></td><td></td><td></td><td></td><td></td><td></td>';
                tr.cells[0].textContent = row.name || '';
                tr.cells[1].textContent = row.layout_option || '';
                tr.cells[2].textContent = row.starts_at || '';
                tr.cells[3].textContent = row.ends_at || '';
                tr.cells[4].textContent = String(row.priority || 0);
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'w-button';
                del.textContent = '删除';
                del.addEventListener('click', function () {
                    if (!window.confirm('确认删除该定时计划？')) {
                        return;
                    }
                    api().then(function (client) {
                        return client.execute('deleteProductLayoutSchedule', { schedule_id: row.schedule_id });
                    }).then(function () {
                        return refresh();
                    }).catch(function (error) {
                        notify('error', messageFrom(error, '删除定时失败'));
                    });
                });
                tr.cells[5].appendChild(del);
                scheduleBody.appendChild(tr);
            });
        }

        function refresh() {
            // Seed immediately so clone_from is never an empty unusable select.
            if (!optionsCache.length) {
                fillOptionSelects([]);
            }
            return api().then(function (client) {
                var layoutsPromise = client.execute('listProductLayouts', {
                    website_id: websiteId,
                    product_id: productId
                }).catch(function (error) {
                    return { __error: error, options: [] };
                });
                var schedulesPromise = productId > 0
                    ? client.execute('listProductLayoutSchedules', {
                        target_type: 'product',
                        target_id: productId
                    }).catch(function () {
                        return { schedules: [] };
                    })
                    : Promise.resolve({ schedules: [] });
                return Promise.all([layoutsPromise, schedulesPromise]);
            }).then(function (pair) {
                var layouts = extractLayoutPayload(pair[0]) || {};
                var schedules = businessResult(pair[1]) || {};
                fillOptionSelects(layouts.options || []);
                var resolved = layouts.resolved || {};
                var selection = layouts.selection || null;
                if (effectiveText) {
                    if (pair[0] && pair[0].__error) {
                        effectiveText.textContent = messageFrom(pair[0].__error, '布局信息加载失败')
                            + '（克隆自已回退本地选项）';
                    } else {
                        effectiveText.textContent = 'option: ' + (resolved.layout_option || 'default')
                            + ' · 来源: ' + (resolved.source || 'file')
                            + (resolved.schedule_id ? (' · 定时#' + resolved.schedule_id) : '');
                    }
                }
                var inherit = panel.querySelector('input[data-product-layout-mode][value="inherit"]');
                var explicit = panel.querySelector('input[data-product-layout-mode][value="explicit"]');
                if (selection && selection.layout_option) {
                    if (explicit) {
                        explicit.checked = true;
                    }
                    if (optionSelect) {
                        optionSelect.value = selection.layout_option;
                    }
                } else if (inherit) {
                    inherit.checked = true;
                }
                renderSchedules(schedules.schedules || []);
            }).catch(function (error) {
                fillOptionSelects(optionsCache);
                if (effectiveText) {
                    effectiveText.textContent = messageFrom(error, '布局信息加载失败');
                }
            });
        }

        panel.querySelector('[data-product-layout-create]')?.addEventListener('click', function () {
            var openCreateDialog = function () {
                if (!cloneSelect || !cloneSelect.options || !cloneSelect.options.length) {
                    fillOptionSelects(optionsCache);
                }
                if (createDialog && typeof createDialog.showModal === 'function') {
                    createDialog.showModal();
                }
            };
            refresh().then(openCreateDialog).catch(openCreateDialog);
        });
        createDialog?.querySelector('[data-product-layout-create-cancel]')?.addEventListener('click', function () {
            if (createDialog && typeof createDialog.close === 'function') {
                createDialog.close();
            }
        });
        createDialog?.querySelector('[data-product-layout-create-submit]')?.addEventListener('click', function () {
            var codeInput = createDialog.querySelector('[data-product-layout-create-code]');
            var nameInput = createDialog.querySelector('[data-product-layout-create-name]');
            var layoutOption = String(codeInput && codeInput.value || '').trim();
            var name = String(nameInput && nameInput.value || '').trim();
            var cloneFrom = String(cloneSelect && cloneSelect.value || 'default').trim() || 'default';
            if (!layoutOption) {
                notify('error', '请填写 layout_option 代码');
                return;
            }
            if (codeInput && typeof codeInput.checkValidity === 'function' && !codeInput.checkValidity()) {
                codeInput.reportValidity();
                return;
            }
            api().then(function (client) {
                return client.execute('createProductLayout', {
                    layout_option: layoutOption,
                    name: name,
                    clone_from: cloneFrom
                });
            }).then(function (result) {
                var data = businessResult(result) || result;
                if (data && data.success === false) {
                    throw new Error(data.message || '创建布局失败');
                }
                if (createDialog && typeof createDialog.close === 'function') {
                    createDialog.close();
                }
                notify('success', '布局已创建');
                return refresh().then(function () {
                    openEditor(data.layout_option || layoutOption);
                });
            }).catch(function (error) {
                notify('error', messageFrom(error, '创建布局失败'));
            });
        });
        panel.querySelector('[data-product-layout-open-editor]')?.addEventListener('click', function () {
            openEditor(optionSelect ? optionSelect.value : 'default');
        });
        panel.querySelector('[data-product-layout-save-selection]')?.addEventListener('click', function () {
            var mode = panel.querySelector('input[data-product-layout-mode]:checked');
            if (!mode || mode.value === 'inherit') {
                api().then(function (client) {
                    return client.execute('deleteProductLayoutSelection', {
                        target_type: 'product',
                        target_id: productId
                    });
                }).then(function () {
                    notify('success', '已恢复继承');
                    return refresh();
                }).catch(function (error) {
                    notify('error', messageFrom(error, '清除选择失败'));
                });
                return;
            }
            api().then(function (client) {
                return client.execute('saveProductLayoutSelection', {
                    target_type: 'product',
                    target_id: productId,
                    layout_option: optionSelect ? optionSelect.value : 'default'
                });
            }).then(function () {
                notify('success', '布局选择已保存');
                return refresh();
            }).catch(function (error) {
                notify('error', messageFrom(error, '保存布局选择失败'));
            });
        });
        panel.querySelector('[data-product-layout-clear-selection]')?.addEventListener('click', function () {
            api().then(function (client) {
                return client.execute('deleteProductLayoutSelection', {
                    target_type: 'product',
                    target_id: productId
                });
            }).then(function () {
                notify('success', '已清除专属选择');
                return refresh();
            }).catch(function (error) {
                notify('error', messageFrom(error, '清除失败'));
            });
        });
        panel.querySelector('[data-product-layout-schedule-add]')?.addEventListener('click', function () {
            if (scheduleDialog && typeof scheduleDialog.showModal === 'function') {
                var idInput = scheduleDialog.querySelector('[data-product-layout-schedule-id]');
                var nameInput = scheduleDialog.querySelector('[data-product-layout-schedule-name]');
                var priorityInput = scheduleDialog.querySelector('[data-product-layout-schedule-priority]');
                if (idInput) {
                    idInput.value = '0';
                }
                if (nameInput) {
                    nameInput.value = '';
                }
                if (priorityInput) {
                    priorityInput.value = '10';
                }
                scheduleDialog.showModal();
            }
        });
        scheduleDialog?.querySelector('[data-product-layout-schedule-cancel]')?.addEventListener('click', function () {
            if (scheduleDialog && typeof scheduleDialog.close === 'function') {
                scheduleDialog.close();
            }
        });
        scheduleDialog?.querySelector('[data-product-layout-schedule-submit]')?.addEventListener('click', function () {
            var idInput = scheduleDialog.querySelector('[data-product-layout-schedule-id]');
            var nameInput = scheduleDialog.querySelector('[data-product-layout-schedule-name]');
            var startsInput = scheduleDialog.querySelector('[data-product-layout-schedule-starts]');
            var endsInput = scheduleDialog.querySelector('[data-product-layout-schedule-ends]');
            var priorityInput = scheduleDialog.querySelector('[data-product-layout-schedule-priority]');
            var name = String(nameInput && nameInput.value || '').trim();
            var startsAt = String(startsInput && startsInput.value || '').trim();
            var endsAt = String(endsInput && endsInput.value || '').trim();
            if (!name || !startsAt || !endsAt) {
                notify('error', '请完整填写定时名称与起止时间');
                return;
            }
            api().then(function (client) {
                return client.execute('saveProductLayoutSchedule', {
                    schedule_id: parseInt(idInput && idInput.value || '0', 10) || 0,
                    name: name,
                    target_type: 'product',
                    target_id: productId,
                    layout_option: String(scheduleOption && scheduleOption.value || '').trim(),
                    starts_at: startsAt,
                    ends_at: endsAt,
                    priority: parseInt(priorityInput && priorityInput.value || '0', 10) || 0,
                    website_id: websiteId,
                    status: 'enabled'
                });
            }).then(function () {
                if (scheduleDialog && typeof scheduleDialog.close === 'function') {
                    scheduleDialog.close();
                }
                notify('success', '定时计划已保存');
                return refresh();
            }).catch(function (error) {
                notify('error', messageFrom(error, '保存定时失败'));
            });
        });

        root.querySelectorAll('[data-product-tab="layout"]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                refresh();
            });
        });
        refresh();
    }

    if (root.getAttribute('data-product-admin') === 'catalog') {
        initializeCatalog();
    }
    if (root.getAttribute('data-product-admin') === 'editor') {
        initializeEditor();
        initializeProductLayoutPanel();
    }
}());
