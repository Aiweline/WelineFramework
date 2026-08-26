/* Weline UI source: js/datatable-common.js */
const Weline = window.Weline = window.Weline || {};

function translate(message, values = []) {
    const parameters = Array.isArray(values)
        ? Object.fromEntries(values.map((value, index) => [index + 1, value]))
        : (values || {});
    if (typeof window.__ === 'function') {
        return window.__(message, parameters);
    }
    return Object.entries(parameters).reduce(
        (result, [key, value]) => result.replaceAll(`%{${key}}`, String(value)),
        String(message),
    );
}

function parseConfig(element) {
    try {
        const value = JSON.parse(element.getAttribute('data-w-config') || '{}');
        return value && typeof value === 'object' ? value : {};
    } catch (error) {
        console.error('Invalid Weline DataTable configuration.', error);
        return {};
    }
}

function isSuccessful(response) {
    if (!response || typeof response !== 'object') return false;
    if ('success' in response) return response.success === true;
    if ('error' in response && response.error === true) return false;
    if ('code' in response) return response.code === 200 || response.code === '200';
    return true;
}

function responsePayload(response) {
    return response && typeof response.data === 'object' && response.data !== null
        ? response.data
        : (response || {});
}

function responseMessage(response, fallback) {
    return String(response?.message || response?.msg || fallback);
}

function normalizeOptions(options) {
    if (Array.isArray(options)) {
        return options.map((option) => {
            if (option && typeof option === 'object') {
                const value = String(option.value ?? option.id ?? '');
                return {value, label: String(option.label ?? option.name ?? value)};
            }
            return {value: String(option), label: String(option)};
        });
    }
    if (typeof options !== 'string' || options.trim() === '') return [];
    return options.split(',').map((pair) => {
        const [value, label] = pair.split(':', 2).map((part) => part.trim());
        return {value, label: label || value};
    });
}

function normalizeField(field) {
    const value = field && typeof field === 'object' ? field : {};
    const name = String(value.name || '');
    return {
        ...value,
        name,
        label: String(value.label || name),
        type: String(value.type || 'text').toLowerCase(),
        options: normalizeOptions(value.options),
        visible: value.visible !== false && value.visible !== 'false',
        searchable: value.searchable !== false && value.searchable !== 'false',
        sortable: value.sortable === true || value.sortable === 'true',
        editable: value.editable === true || value.editable === 'true',
    };
}

function mergeFields(primary, secondary) {
    const map = new Map();
    for (const field of [...primary, ...secondary].map(normalizeField)) {
        if (!field.name) continue;
        map.set(field.name, {...(map.get(field.name) || {}), ...field});
    }
    return [...map.values()];
}

function fieldMap(fields) {
    return new Map(fields.map((field) => [field.name, field]));
}

function parseFieldElements(elements) {
    return [...elements].map((element) => {
        try {
            return normalizeField(JSON.parse(element.getAttribute('data-w-field') || '{}'));
        } catch (_error) {
            return normalizeField({
                name: element.getAttribute('data-field') || '',
                label: element.textContent?.trim() || '',
                sortable: element.getAttribute('data-sortable') === 'true',
            });
        }
    }).filter((field) => field.name);
}

function icon(name, size = 'sm') {
    const element = document.createElement('w-icon');
    element.setAttribute('name', name);
    element.setAttribute('size', size);
    return element;
}

function button(label, options = {}) {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = options.className || 'w-button';
    if (options.tone) element.dataset.tone = options.tone;
    if (options.size) element.dataset.size = options.size;
    if (options.action) element.dataset.wDatatableAction = options.action;
    if (options.formAction) element.dataset.wDatatableFormAction = options.formAction;
    if (options.icon) element.append(icon(options.icon, options.iconSize || 'sm'));
    const text = document.createElement('span');
    text.textContent = label;
    element.append(text);
    return element;
}

function valueFor(row, name) {
    if (row && Object.prototype.hasOwnProperty.call(row, name)) return row[name];
    let value = row;
    for (const segment of String(name).split('.')) {
        if (!value || typeof value !== 'object' || !(segment in value)) return null;
        value = value[segment];
    }
    return value;
}

function safeImageUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') return '';
    try {
        const url = new URL(value, window.location.origin);
        return ['http:', 'https:', 'blob:', 'data:'].includes(url.protocol) ? url.href : '';
    } catch (_error) {
        return '';
    }
}

const IMAGE_PLACEHOLDER = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
    + '<rect width="96" height="96" rx="8" fill="#e5e7eb"/>'
    + '<rect x="28" y="30" width="40" height="32" rx="4" fill="#9ca3af" opacity="0.55"/>'
    + '<circle cx="48" cy="24" r="8" fill="#9ca3af" opacity="0.35"/>'
    + '</svg>',
);

function imagePlaceholder() {
    return IMAGE_PLACEHOLDER;
}

function safeCssLength(value) {
    const raw = String(value || '').trim();
    if (/^\d+$/.test(raw)) return `${raw}px`;
    return /^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw|ch))$/.test(raw) ? raw : '';
}

function fieldValueNode(value, field) {
    if (field.type === 'image') {
        const placeholder = imagePlaceholder();
        const src = safeImageUrl(String(value || '')) || placeholder;
        const image = document.createElement('img');
        image.src = src;
        image.alt = field.label || '';
        image.loading = 'lazy';
        image.decoding = 'async';
        image.width = 48;
        image.height = 48;
        image.setAttribute(
            'data-testid',
            src === placeholder
                ? (field.name === 'main_image' ? 'product-main-image-placeholder' : 'datatable-image-placeholder')
                : (field.name === 'main_image' ? 'product-main-image' : 'datatable-image'),
        );
        image.dataset.imagePlaceholder = placeholder;
        image.style.objectFit = 'cover';
        image.style.borderRadius = '4px';
        image.style.display = 'block';
        image.style.background = 'var(--weline-color-surface-muted,#f3f4f6)';
        image.addEventListener('error', () => {
            if (image.dataset.fallbackApplied === '1') return;
            image.dataset.fallbackApplied = '1';
            image.src = placeholder;
            image.setAttribute(
                'data-testid',
                field.name === 'main_image' ? 'product-main-image-placeholder' : 'datatable-image-placeholder',
            );
        });
        return image;
    }
    if (typeof value === 'boolean') {
        const badge = document.createElement('span');
        badge.className = 'w-badge';
        badge.dataset.tone = value ? 'success' : 'neutral';
        badge.textContent = value ? translate('是') : translate('否');
        return badge;
    }
    const text = document.createElement('span');
    if (value && typeof value === 'object') {
        text.textContent = JSON.stringify(value);
    } else {
        text.textContent = value == null ? '' : String(value);
    }
    return text;
}

async function request(config, operation, payload = {}) {
    if (!Weline.Api || typeof Weline.Api.resource !== 'function') {
        throw new Error(translate('Weline.Api 尚未就绪。'));
    }
    const provider = String(config.apiProvider || 'datatable');
    const resource = await Weline.Api.resource(provider);
    const method = String(config.operations?.[operation] || operation);
    if (!resource || typeof resource[method] !== 'function') {
        throw new Error(translate('资源操作不可用：%{1}.%{2}', [provider, method]));
    }
    const response = await resource[method](payload, {silent: true});
    if (!isSuccessful(response)) {
        throw new Error(responseMessage(response, translate('请求失败。')));
    }
    return response;
}

function normalizePagination(payload, state) {
    const raw = payload.pagination || {};
    const pageSize = Math.max(1, Number(raw.pageSize ?? raw.limit ?? payload.pageSize ?? state.pageSize) || 20);
    const total = Math.max(0, Number(raw.total ?? payload.total ?? 0) || 0);
    const page = Math.max(1, Number(raw.page ?? payload.page ?? state.page) || 1);
    const pages = Math.max(1, Number(raw.lastPage ?? payload.pages) || Math.ceil(total / pageSize) || 1);
    return {page: Math.min(page, pages), pageSize, total, pages};
}

function downloadPayload(payload, format) {
    const body = payload.body ?? payload.content ?? '';
    const contentType = String(payload.content_type || (format === 'json' ? 'application/json' : 'text/csv'));
    const extension = format === 'json' ? 'json' : (format === 'excel' ? 'xlsx' : 'csv');
    const proposed = String(payload.filename || `datatable-export.${extension}`);
    const filename = proposed.replace(/[^A-Za-z0-9._-]+/g, '-') || `datatable-export.${extension}`;
    const url = URL.createObjectURL(new Blob([body], {type: contentType}));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.append(link);
    link.click();
    link.remove();
    queueMicrotask(() => URL.revokeObjectURL(url));
}

export {
    button,
    downloadPayload,
    fieldMap,
    fieldValueNode,
    imagePlaceholder,
    mergeFields,
    normalizeField,
    normalizePagination,
    parseConfig,
    parseFieldElements,
    request,
    responsePayload,
    safeCssLength,
    safeImageUrl,
    translate,
    valueFor,
};
