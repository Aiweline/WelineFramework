const configElement = document.querySelector('[data-api-docs-config]');
const config = configElement ? JSON.parse(configElement.textContent || '{}') : {};
const root = document.querySelector('[data-api-root]');
const listRoot = document.querySelector('[data-api-list]');
const detailRoot = document.querySelector('[data-api-detail]');
const testRoot = document.querySelector('[data-api-test]');
const workspace = document.querySelector('[data-api-workspace]');
const searchInput = document.querySelector('[data-api-search]');
const themeSelect = document.querySelector('[data-api-theme]');
const productionSwitch = document.querySelector('[data-api-production]');
const liveStatus = document.querySelector('[data-api-live-status]');
const text = config.text || {};
const storageKeys = {
    token: 'api_doc_access_token',
    refresh: 'api_doc_refresh_token',
    user: 'api_doc_user',
    backendToken: 'api_doc_backend_access_token',
    backendRefresh: 'api_doc_backend_refresh_token',
    backendUser: 'api_doc_backend_user',
    sandbox: 'api_doc_sandbox_key',
    production: 'api_doc_production_mode',
    locale: 'api_doc_locale',
    currency: 'api_doc_currency',
    i18nMode: 'api_doc_i18n_mode',
    area: 'api_doc_api_type',
    sidebar: 'api_doc_sidebar_size',
    testRatio: 'api_doc_test_ratio',
    collapsed: 'api_doc_sidebar_collapsed'
};

function t(key, fallback) {
    return String(text[key] || fallback || key);
}

function readStore(key, fallback = '') {
    try {
        const value = window.localStorage.getItem(key);
        return value === null ? fallback : value;
    } catch (_error) {
        return fallback;
    }
}

function writeStore(key, value) {
    try {
        window.localStorage.setItem(key, String(value));
    } catch (_error) {
    }
}

function removeStore(key) {
    try {
        window.localStorage.removeItem(key);
    } catch (_error) {
    }
}

function create(tag, options = {}, children = []) {
    const element = document.createElement(tag);
    if (options.className) element.className = options.className;
    if (options.text !== undefined) element.textContent = String(options.text);
    Object.entries(options.attrs || {}).forEach(([name, value]) => {
        if (value !== undefined && value !== null && value !== false) element.setAttribute(name, value === true ? '' : String(value));
    });
    Object.entries(options.dataset || {}).forEach(([name, value]) => {
        if (value !== undefined && value !== null) element.dataset[name] = String(value);
    });
    const queue = Array.isArray(children) ? children.flat(Infinity) : [children];
    queue.forEach((child) => {
        if (child instanceof Node) element.append(child);
        else if (child !== undefined && child !== null && child !== false) element.append(document.createTextNode(String(child)));
    });
    return element;
}

function icon(name, size = 'sm') {
    try {
        const value = window.Weline?.UI?.icon?.create(name, {size});
        if (value instanceof Node) return value;
    } catch (_error) {
    }
    return create('span', {className: 'w-icon', attrs: {'aria-hidden': 'true'}, dataset: {icon: name}});
}

function button(label, action, tone = 'neutral', iconName = '') {
    const children = [];
    if (iconName) children.push(icon(iconName));
    children.push(create('span', {text: label}));
    return create('button', {
        className: 'w-button',
        attrs: {type: 'button'},
        dataset: {apiAction: action, tone}
    }, children);
}

function toast(message, tone = 'info') {
    const value = String(message || '');
    if (liveStatus) liveStatus.textContent = value;
    const api = window.Weline?.UI?.toast;
    if (api?.show) api.show(value, {tone});
}

function emptyState(message, iconName = 'code') {
    return create('div', {className: 'w-empty'}, [
        icon(iconName, 'lg'),
        create('p', {text: message})
    ]);
}

function stringify(value) {
    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch (_error) {
            return value;
        }
    }
    try {
        return JSON.stringify(value, null, 2);
    } catch (_error) {
        return String(value ?? '');
    }
}

function codeBlock(value, language = 'json') {
    return create('pre', {className: 'w-api-code'}, [
        create('code', {text: stringify(value), dataset: {language}})
    ]);
}

function copyControl(label, value) {
    const control = button(label, 'copy', 'quiet', 'copy');
    control.dataset.copyValue = String(value ?? '');
    return control;
}

function methodBadge(value) {
    const method = String(value || 'GET').toUpperCase();
    return create('span', {
        className: 'w-api-docs__method',
        text: method,
        dataset: {method}
    });
}

function section(title, className = '') {
    const element = create('section', {className: 'w-api-detail__section ' + className});
    element.append(create('h2', {text: title}));
    return element;
}

function flattenApis(source) {
    const rows = [];
    Object.entries(source || {}).forEach(([moduleName, versions]) => {
        Object.entries(versions || {}).forEach(([version, classes]) => {
            Object.entries(classes || {}).forEach(([className, methods]) => {
                (Array.isArray(methods) ? methods : []).forEach((api) => {
                    rows.push(Object.assign({}, api, {moduleName, version, className}));
                });
            });
        });
    });
    return rows;
}

const apis = flattenApis(config.apis);
const selectedFromConfig = apis.find((api) => String(api.id || '') === String(config.selectedApiId || ''));
const state = {
    area: selectedFromConfig?.route?.is_backend ? 'backend' : readStore(storageKeys.area, 'frontend'),
    selectedId: String(config.selectedApiId || ''),
    query: '',
    locale: readStore(storageKeys.locale, String(config.currentLocale || 'zh_Hans_CN')),
    currency: readStore(storageKeys.currency, String(config.currentCurrency || 'CNY')),
    i18nMode: readStore(storageKeys.i18nMode, 'path'),
    production: readStore(storageKeys.production, 'false') === 'true',
    sandbox: readStore(storageKeys.sandbox, ''),
    lastResponseText: ''
};

function selectedApi() {
    return apis.find((api) => String(api.id || '') === state.selectedId) || null;
}

function apiLabel(api) {
    return String(api.document?.summary || api.method || api.route?.path || api.id || '');
}

function apiSearchText(api) {
    return [
        api.moduleName,
        config.moduleDisplayNames?.[api.moduleName],
        api.version,
        api.className,
        api.method,
        api.route?.method,
        api.route?.path,
        api.document?.summary,
        api.document?.description,
        ...(api.parameters || []).flatMap((param) => [param?.name, param?.description, param?.source])
    ].filter(Boolean).join(' ').toLocaleLowerCase();
}

function matches(api) {
    const backend = Boolean(api.route?.is_backend);
    if ((state.area === 'backend') !== backend) return false;
    const query = state.query.trim().toLocaleLowerCase();
    return !query || apiSearchText(api).includes(query);
}

function groupedApis(rows) {
    const modules = new Map();
    rows.forEach((api) => {
        if (!modules.has(api.moduleName)) modules.set(api.moduleName, new Map());
        const versions = modules.get(api.moduleName);
        if (!versions.has(api.version)) versions.set(api.version, new Map());
        const classes = versions.get(api.version);
        if (!classes.has(api.className)) classes.set(api.className, []);
        classes.get(api.className).push(api);
    });
    return modules;
}

function containsSelected(items) {
    return items.some((api) => String(api.id || '') === state.selectedId);
}

function renderList() {
    if (!listRoot) return;
    const filtered = apis.filter(matches);
    if (!filtered.length) {
        listRoot.replaceChildren(emptyState(t('empty', '没有匹配的 API 接口'), 'search'));
        return;
    }
    const fragment = document.createDocumentFragment();
    groupedApis(filtered).forEach((versions, moduleName) => {
        const moduleItems = [];
        versions.forEach((classes) => classes.forEach((items) => moduleItems.push(...items)));
        const moduleDetails = create('details', {className: 'w-api-tree__module'});
        moduleDetails.open = Boolean(state.query) || containsSelected(moduleItems) || versions.size < 3;
        const moduleSummary = create('summary', {}, [
            create('span', {text: String(config.moduleDisplayNames?.[moduleName] || moduleName)}),
            create('span', {className: 'w-badge', text: moduleItems.length, dataset: {tone: 'quiet'}})
        ]);
        moduleDetails.append(moduleSummary);
        versions.forEach((classes, version) => {
            const versionItems = [];
            classes.forEach((items) => versionItems.push(...items));
            const versionDetails = create('details', {className: 'w-api-tree__version'});
            versionDetails.open = Boolean(state.query) || containsSelected(versionItems) || classes.size < 3;
            versionDetails.append(create('summary', {text: version}));
            classes.forEach((items, className) => {
                const classDetails = create('details', {className: 'w-api-tree__class'});
                classDetails.open = Boolean(state.query) || containsSelected(items);
                classDetails.append(create('summary', {text: String(className).split('\\').pop() || className}));
                const list = create('ul', {className: 'w-api-tree__list'});
                items.forEach((api) => {
                    const trigger = create('button', {
                        className: 'w-api-docs__item',
                        attrs: {type: 'button', 'aria-current': String(api.id || '') === state.selectedId ? 'true' : 'false'},
                        dataset: {apiId: String(api.id || '')}
                    }, [
                        methodBadge(api.route?.method),
                        create('span', {className: 'w-api-docs__item-copy'}, [
                            create('span', {className: 'w-api-docs__item-title', text: apiLabel(api)}),
                            create('code', {className: 'w-api-docs__item-path', text: String(api.route?.path || '')})
                        ])
                    ]);
                    list.append(create('li', {}, [trigger]));
                });
                classDetails.append(list);
                versionDetails.append(classDetails);
            });
            moduleDetails.append(versionDetails);
        });
        fragment.append(moduleDetails);
    });
    listRoot.replaceChildren(fragment);
}

function updateAreaTabs() {
    document.querySelectorAll('[data-api-area]').forEach((tab) => {
        tab.setAttribute('aria-selected', tab.dataset.apiArea === state.area ? 'true' : 'false');
    });
}

function updateUrl(apiId, replace = false) {
    const url = new URL(window.location.href);
    if (apiId) url.searchParams.set('api_id', apiId);
    else url.searchParams.delete('api_id');
    const method = replace ? 'replaceState' : 'pushState';
    window.history[method]({apiId}, '', url);
}

function selectApi(api, options = {}) {
    if (!api) return;
    state.selectedId = String(api.id || '');
    state.area = api.route?.is_backend ? 'backend' : 'frontend';
    writeStore(storageKeys.area, state.area);
    updateAreaTabs();
    renderList();
    renderDetail(api);
    renderTest(api);
    updateLoginButton();
    if (options.history !== false) updateUrl(state.selectedId, Boolean(options.replace));
    if (options.focus && window.matchMedia('(max-width: 900px)').matches) {
        detailRoot?.focus?.({preventScroll: true});
        detailRoot?.scrollIntoView?.({block: 'start', behavior: 'smooth'});
    }
}

function metadataBadge(value) {
    return create('span', {className: 'w-badge', text: String(value), dataset: {tone: 'neutral'}});
}

function parameterSource(value) {
    const labels = {
        method_signature: '方法签名参数',
        POST: 'POST 参数',
        GET: 'GET 参数',
        URL: 'URL 参数',
        HEADER: '请求头',
        BODY: 'Body 参数',
        AUTH_BEARER: 'Authorization',
        HEADER_X_API_TOKEN: 'X-API-Token',
        request: '请求参数'
    };
    return labels[value] || String(value || '');
}

function table(headers, rows) {
    const tableElement = create('table', {className: 'w-table'});
    const headRow = create('tr');
    headers.forEach((label) => headRow.append(create('th', {text: label, attrs: {scope: 'col'}})));
    tableElement.append(create('thead', {}, [headRow]));
    const body = create('tbody');
    rows.forEach((values) => {
        const row = create('tr');
        values.forEach((value) => {
            const cell = create('td');
            if (value instanceof Node) cell.append(value);
            else cell.textContent = String(value ?? '');
            row.append(cell);
        });
        body.append(row);
    });
    tableElement.append(body);
    return create('div', {className: 'w-table-wrap'}, [tableElement]);
}

function isWorker(api) {
    return Boolean(api && (api.frontend_worker || api.worker || api.example?.frontend_worker));
}

function isSdk(api) {
    const example = api?.example || {};
    return Boolean(api && (api.binquery || example.package || example.download || example.install || example.protocol));
}

function workerDescriptor(api) {
    const example = api?.example || {};
    const worker = api?.worker || {};
    return {
        provider: String(worker.provider || example.provider || ''),
        operation: String(worker.operation || example.operation || ''),
        module: String(api?.module || example.module || '')
    };
}

function sampleValue(api, parameter) {
    const samples = api?.example?.sample_params;
    if (samples && typeof samples === 'object' && !Array.isArray(samples) && Object.prototype.hasOwnProperty.call(samples, parameter.name)) {
        return samples[parameter.name];
    }
    if (Object.prototype.hasOwnProperty.call(parameter, 'example')) return parameter.example;
    if (Object.prototype.hasOwnProperty.call(parameter, 'default')) return parameter.default;
    const name = String(parameter.name || '').toLowerCase();
    const type = String(parameter.type || '').toLowerCase();
    if (name.includes('email')) return 'customer@example.com';
    if (name.includes('password')) return 'password123';
    if (name === 'username' || name === 'login') return 'customer@example.com';
    if (name.includes('firstname') || name.includes('first_name')) return 'Jane';
    if (name.includes('lastname') || name.includes('last_name')) return 'Doe';
    if (name.includes('token')) return 'token-value';
    if (name === 'code') return '123456';
    if (type === 'int' || type === 'integer') return 1;
    if (['float', 'double', 'number'].includes(type)) return 1;
    if (type === 'bool' || type === 'boolean') return true;
    if (type === 'array') return [];
    if (type === 'object') return {};
    return parameter.required ? 'value' : '';
}

function workerPayload(api) {
    const sample = api?.example?.sample_params;
    if (sample && typeof sample === 'object' && !Array.isArray(sample)) return Object.assign({}, sample);
    const payload = {};
    (api?.parameters || []).forEach((parameter) => {
        if (!parameter?.name) return;
        const value = sampleValue(api, parameter);
        if (value !== '' || parameter.required) payload[parameter.name] = value;
    });
    return payload;
}

function workerCall(api, payload = workerPayload(api)) {
    const descriptor = workerDescriptor(api);
    const accessor = /^[A-Za-z_$][\w$]*$/.test(descriptor.operation)
        ? '.' + descriptor.operation
        : '[' + JSON.stringify(descriptor.operation) + ']';
    return 'const Api = await Weline.Api.resource(' + JSON.stringify(descriptor.provider) + ');\n'
        + 'await Api' + accessor + '(' + JSON.stringify(payload || {}, null, 2) + ');';
}

function safeActionUrl(value) {
    try {
        const url = new URL(String(value || ''), window.location.origin);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
    } catch (_error) {
        return '';
    }
}

function renderSdk(api, article) {
    if (!isWorker(api) && !isSdk(api)) return;
    const example = api.example || {};
    const actions = [];
    const add = (label, url, download = false) => {
        const href = safeActionUrl(url);
        if (href && !actions.some((item) => item.href === href)) actions.push({label, href, download});
    };
    if (Array.isArray(example.downloads)) {
        example.downloads.forEach((item) => item?.url && add(String(item.label || t('sdkDownload')), item.url, true));
    }
    if (example.download_url) add(t('sdkDownload'), example.download_url, true);
    if (isWorker(api)) {
        add('PHP SDK', '/dev/tool/docs/api/sdk-download?sdk=php', true);
        add('JS SDK', '/dev/tool/docs/api/sdk-download?sdk=js', true);
        add(t('guide'), '/dev/tool/docs/api/sdk-guide?doc=sdk');
    }
    if (example.guide_url) add(t('guide'), example.guide_url);
    if (actions.length) {
        const sdkSection = section(t('sdkDownload', 'SDK 下载'));
        const cluster = create('div', {className: 'w-cluster'});
        actions.forEach((action) => {
            const link = create('a', {
                className: 'w-button',
                text: action.label,
                attrs: {href: action.href, target: '_blank', rel: 'noopener', download: action.download || undefined},
                dataset: {tone: action.download ? 'primary' : 'neutral'}
            });
            cluster.append(link);
        });
        sdkSection.append(cluster);
        article.append(sdkSection);
    }
    const rows = [
        [t('package'), example.package],
        [t('downloadLocation'), example.download],
        [t('installCommand'), example.install],
        [t('document'), example.docs],
        ['Content-Type', example.content_type],
        [t('protocol'), example.protocol],
        [t('derivedPath'), example.derived_path],
        [t('authSource'), example.api_key_source],
        [t('authType'), example.api_key_type],
        [t('authTtl'), example.api_key_ttl],
        [t('refreshTtl'), example.refresh_token_ttl],
        ['Scope', example.scope],
        [t('externalOperations'), example.external_operation_count]
    ].filter((row) => row[1] !== undefined && row[1] !== null && String(row[1]).trim() !== '');
    if (rows.length) {
        const info = section(t('sdkInfo', 'SDK 信息'));
        info.append(table([t('field'), t('description')], rows.map(([label, value]) => [label, create('code', {text: String(value)})])));
        article.append(info);
    }
}

function buildRestUrl(path, isBackend = false, settings = {}) {
    const value = String(path || '').trim();
    if (!value) return '';
    if (/^https?:\/\//i.test(value)) return value;
    const apiArea = String(config.apiArea || 'api').replace(/^\/+|\/+$/g, '');
    const adminArea = String(config.apiAdminArea || 'api_admin').replace(/^\/+|\/+$/g, '');
    const area = isBackend ? adminArea : apiArea;
    let segments = value.replace(/^\/+/, '').split('/').filter(Boolean);
    if (segments[0] === area) {
        if (!isBackend && /^[A-Z]{3}$/.test(segments[1] || '') && isLocaleSegment(segments[2])) segments = segments.slice(3);
        else if (segments[1] !== 'rest') segments = segments.slice(1);
    } else if (!isBackend && /^[A-Z]{3}$/.test(segments[0] || '') && isLocaleSegment(segments[1])) {
        segments = segments.slice(2);
    }
    const directRest = segments[0] === 'rest' || segments[1] === 'rest';
    let pathname;
    if (directRest) pathname = '/' + area + '/' + segments.join('/');
    else if (isBackend) pathname = '/' + area + '/' + segments.join('/');
    else if ((settings.mode || state.i18nMode) === 'path') {
        pathname = '/' + area + '/' + encodeURIComponent(settings.currency || state.currency) + '/'
            + encodeURIComponent(settings.locale || state.locale) + '/' + segments.join('/');
    } else {
        pathname = '/' + area + '/' + segments.join('/');
    }
    const url = new URL(pathname, window.location.origin);
    if (!isBackend && (settings.mode || state.i18nMode) === 'param') {
        url.searchParams.set('locale', settings.locale || state.locale);
        url.searchParams.set('currency', settings.currency || state.currency);
    }
    return url.href;
}

function isLocaleSegment(value) {
    return /^[a-z]{2}_[A-Z]{2}$/.test(value || '') || /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/.test(value || '');
}

function exampleCall(api) {
    if (isWorker(api)) return workerCall(api);
    if (isSdk(api)) return String(api.example?.code || api.example?.install || api.example?.docs || 'https://{domain}/bin/query');
    return buildRestUrl(api.example?.path || api.route?.path || '', Boolean(api.route?.is_backend));
}

function renderExamples(api, article) {
    const example = api.example;
    if (!example || typeof example !== 'object') return;
    const wrapper = section(t('examples', '调用示例'));
    const items = [];
    const call = exampleCall(api);
    if (call) items.push([isWorker(api) || isSdk(api) ? t('copyCall') : t('copyPath'), call, isWorker(api) || isSdk(api) ? 'javascript' : 'text']);
    const headers = example.Headers || example.headers;
    if (headers) items.push([t('headers'), headers, 'json']);
    if (example.body !== undefined || example.Body !== undefined) items.push([t('copyBody'), example.body ?? example.Body, 'json']);
    if (example.response !== undefined || example.Response !== undefined) items.push([t('copyResponse'), example.response ?? example.Response, 'json']);
    items.forEach(([label, value, language]) => {
        const row = create('div', {className: 'w-api-example'});
        const heading = create('div', {className: 'w-api-example__header w-cluster', attrs: {'data-justify': 'between'}}, [
            create('strong', {text: label}),
            copyControl(label, stringify(value))
        ]);
        row.append(heading, codeBlock(value, language));
        wrapper.append(row);
    });
    article.append(wrapper);
}

function renderDetail(api) {
    if (!detailRoot) return;
    if (!api) {
        detailRoot.replaceChildren(emptyState(t('chooseApi'), 'code'));
        return;
    }
    const article = create('article', {className: 'w-api-detail w-stack', attrs: {tabindex: '-1'}, dataset: {selectedApiId: String(api.id || '')}});
    const heading = create('header', {className: 'w-api-detail__heading w-stack'});
    heading.append(create('div', {className: 'w-cluster'}, [
        methodBadge(api.route?.method),
        create('h1', {className: 'w-api-detail__path', text: String(api.route?.path || api.method || 'API')})
    ]));
    if (api.document?.summary) heading.append(create('p', {className: 'w-api-detail__summary', text: api.document.summary}));
    const meta = create('div', {className: 'w-cluster'});
    [api.moduleName, api.version, api.className, api.method].filter(Boolean).forEach((value) => meta.append(metadataBadge(value)));
    if (api.document?.deprecated) meta.append(create('span', {className: 'w-badge', text: t('deprecated'), dataset: {tone: 'danger'}}));
    heading.append(meta);
    article.append(heading);
    if (state.production && !isWorker(api) && !isSdk(api)) {
        article.append(create('div', {className: 'w-alert', dataset: {tone: 'warning'}}, [
            icon('warning'),
            create('strong', {text: t('productionWarning')})
        ]));
    }
    if (api.document?.description) {
        const description = section(t('interfaceDescription'));
        description.append(create('p', {text: api.document.description}));
        article.append(description);
    }
    renderSdk(api, article);
    if (Array.isArray(api.parameters) && api.parameters.length) {
        const parameters = section(t('parameters'));
        const rows = api.parameters.map((parameter) => {
            let description = String(parameter.description || '');
            if (parameter.default !== undefined && parameter.default !== null) {
                description += (description ? ' · ' : '') + t('defaultValue') + ': ' + stringify(parameter.default);
            }
            return [
                create('code', {text: String(parameter.name || '')}),
                String(parameter.type || ''),
                parameter.required ? t('yes') : t('no'),
                parameterSource(parameter.source),
                description
            ];
        });
        parameters.append(table([t('field'), t('type'), t('required'), t('source'), t('description')], rows));
        article.append(parameters);
    }
    if (api.responses && typeof api.responses === 'object' && Object.keys(api.responses).length) {
        const responses = section(t('responses'));
        const rows = Object.entries(api.responses).map(([code, definition]) => [
            create('code', {text: code}),
            String(definition?.type || ''),
            String(definition?.description || '')
        ]);
        responses.append(table([t('statusCode'), t('type'), t('description')], rows));
        article.append(responses);
    }
    renderExamples(api, article);
    detailRoot.replaceChildren(article);
}

function responseShell() {
    const container = create('section', {className: 'w-api-response w-stack', attrs: {hidden: true}, dataset: {apiResponse: ''}});
    container.append(create('div', {className: 'w-api-response__header w-cluster', attrs: {'data-justify': 'between'}}, [
        create('h3', {text: t('responseInfo')}),
        create('div', {className: 'w-cluster'}, [
            button(t('copy'), 'copy-response', 'quiet', 'copy'),
            button(t('format'), 'format-response', 'quiet', 'code')
        ])
    ]));
    container.append(create('div', {className: 'w-api-response__meta', dataset: {apiResponseMeta: ''}}));
    const headerDetails = create('details', {className: 'w-disclosure'});
    headerDetails.append(create('summary', {text: t('responseHeaders')}));
    headerDetails.append(create('div', {className: 'w-table-wrap', dataset: {apiResponseHeaders: ''}}));
    container.append(headerDetails);
    container.append(create('div', {className: 'w-api-response__body'}, [
        create('h4', {text: t('responseBody')}),
        create('pre', {className: 'w-api-code'}, [create('code', {dataset: {apiResponseBody: ''}})])
    ]));
    return container;
}

function testHeader(api, action, label) {
    const heading = create('header', {className: 'w-api-test__header w-cluster', attrs: {'data-justify': 'between', 'data-align': 'center'}});
    heading.append(create('div', {className: 'w-stack'}, [
        create('h2', {text: t('onlineTest')}),
        create('p', {text: t('editAndSend')})
    ]));
    const status = create('span', {className: 'w-api-status', attrs: {hidden: true}, dataset: {apiRequestStatus: ''}});
    const actionButton = button(label, action, 'primary', action === 'open-login' ? 'lock' : 'play');
    actionButton.dataset.apiSendButton = '';
    heading.append(create('div', {className: 'w-cluster'}, [status, actionButton]));
    return heading;
}

function workerControl(parameter, value) {
    const type = String(parameter.type || 'string').toLowerCase();
    const common = {
        className: 'w-input',
        attrs: {'aria-required': parameter.required ? 'true' : 'false'},
        dataset: {
            workerParam: '',
            paramName: String(parameter.name || ''),
            paramType: type,
            paramRequired: parameter.required ? '1' : '0'
        }
    };
    let control;
    if (type === 'bool' || type === 'boolean') {
        control = create('select', common, [
            !parameter.required ? create('option', {text: '—', attrs: {value: ''}}) : null,
            create('option', {text: 'true', attrs: {value: 'true'}}),
            create('option', {text: 'false', attrs: {value: 'false'}})
        ]);
        control.value = value === '' ? '' : String(Boolean(value));
    } else if (type === 'array' || type === 'object' || (value && typeof value === 'object')) {
        control = create('textarea', Object.assign({}, common, {className: 'w-textarea'}));
        control.rows = 3;
        control.value = stringify(value);
    } else {
        common.attrs.type = ['int', 'integer', 'float', 'double', 'number'].includes(type) ? 'number' : 'text';
        if (['float', 'double', 'number'].includes(type)) common.attrs.step = 'any';
        control = create('input', common);
        control.value = value === undefined || value === null ? '' : String(value);
    }
    control.dataset.initialValue = control.value;
    return control;
}

function renderWorkerTest(api) {
    const descriptor = workerDescriptor(api);
    const article = create('div', {className: 'w-api-test w-stack'});
    article.append(testHeader(api, 'run-worker', t('send')));
    const target = section(t('workerTarget'), 'w-api-test__section');
    target.append(create('dl', {className: 'w-api-target-grid'}, [
        create('div', {}, [create('dt', {text: t('provider')}), create('dd', {}, [create('code', {text: descriptor.provider})])]),
        create('div', {}, [create('dt', {text: t('operation')}), create('dd', {}, [create('code', {text: descriptor.operation})])]),
        create('div', {}, [create('dt', {text: 'Module'}), create('dd', {}, [create('code', {text: descriptor.module})])])
    ]));
    article.append(target);
    const payload = workerPayload(api);
    const parameterSection = section(t('workerParams'), 'w-api-test__section');
    const editor = create('div', {className: 'w-api-worker-fields'});
    const parameters = (api.parameters || []).filter((parameter) => parameter?.name);
    if (!parameters.length) editor.append(create('p', {className: 'w-text', text: t('noParams'), dataset: {tone: 'muted'}}));
    parameters.forEach((parameter) => {
        const value = Object.prototype.hasOwnProperty.call(payload, parameter.name) ? payload[parameter.name] : sampleValue(api, parameter);
        const label = create('label', {className: 'w-field'});
        label.append(create('span', {className: 'w-field__label'}, [
            create('code', {text: parameter.name}),
            parameter.required ? create('span', {className: 'w-api-required', text: '*'}) : null,
            create('small', {text: String(parameter.type || 'string')})
        ]));
        label.append(workerControl(parameter, value));
        if (parameter.description) label.append(create('small', {className: 'w-field__help', text: parameter.description}));
        editor.append(label);
    });
    parameterSection.append(editor);
    const rawField = create('label', {className: 'w-field'}, [
        create('span', {className: 'w-field__label', text: t('rawJson')}),
        create('textarea', {className: 'w-textarea', attrs: {rows: '9'}, dataset: {workerRaw: ''}})
    ]);
    rawField.querySelector('textarea').value = JSON.stringify(payload, null, 2);
    parameterSection.append(rawField);
    parameterSection.append(create('div', {className: 'w-api-call-preview'}, [
        create('strong', {text: t('callPreview')}),
        codeBlock(workerCall(api, payload), 'javascript')
    ]));
    article.append(parameterSection, responseShell());
    return article;
}

function examplePairs(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return [];
    return Object.entries(value).map(([key, item]) => ({key, value: typeof item === 'string' ? item : stringify(item)}));
}

function parameterPairs(api) {
    const pairs = examplePairs(api.example?.request_parameters);
    const known = new Set(pairs.map((pair) => pair.key));
    (api.parameters || []).forEach((parameter) => {
        const source = String(parameter.source || '').toUpperCase();
        if (!parameter.name || known.has(parameter.name) || !['GET', 'URL', 'REQUEST'].includes(source)) return;
        pairs.push({key: parameter.name, value: String(sampleValue(api, parameter) ?? '')});
    });
    return pairs;
}

function headerPairs(api, method) {
    const pairs = examplePairs(api.example?.Headers || api.example?.headers);
    if (['POST', 'PUT', 'PATCH'].includes(method) && !pairs.some((pair) => pair.key.toLowerCase() === 'content-type')) {
        pairs.push({key: 'Content-Type', value: 'application/json'});
    }
    return pairs;
}

function pairRow(kind, pair = {}) {
    return create('div', {className: 'w-api-pair'}, [
        create('input', {
            className: 'w-input',
            attrs: {type: 'text', placeholder: kind === 'headers' ? t('headerName') : t('paramName')},
            dataset: {pairKey: ''}
        }),
        create('input', {
            className: 'w-input',
            attrs: {type: 'text', placeholder: kind === 'headers' ? t('headerValue') : t('paramValue')},
            dataset: {pairValue: ''}
        }),
        button(t('close'), 'remove-pair', 'quiet', 'close')
    ]);
}

function fillPairRow(row, pair) {
    row.querySelector('[data-pair-key]').value = String(pair?.key || '');
    row.querySelector('[data-pair-value]').value = String(pair?.value ?? '');
    return row;
}

function pairEditor(kind, pairs) {
    const container = create('div', {className: 'w-api-pairs', dataset: {pairEditor: kind}});
    const rows = create('div', {className: 'w-api-pairs__rows', dataset: {pairRows: ''}});
    pairs.forEach((pair) => rows.append(fillPairRow(pairRow(kind), pair)));
    container.append(rows, button(kind === 'headers' ? t('addHeader') : t('addParam'), 'add-pair', 'neutral', 'plus'));
    return container;
}

function restAuth(api) {
    const backend = Boolean(api.route?.is_backend);
    return {
        backend,
        required: Boolean(api.acl && (api.acl.class || api.acl.method)),
        token: readStore(backend ? storageKeys.backendToken : storageKeys.token, '')
    };
}

function renderRestTest(api) {
    const method = String(api.route?.method || api.example?.method || 'GET').toUpperCase();
    const auth = restAuth(api);
    const action = auth.required && !auth.token ? 'open-login' : 'run-rest';
    const article = create('div', {className: 'w-api-test w-stack'});
    article.append(testHeader(api, action, auth.required && !auth.token ? t('loginToSend') : t('send')));

    const environment = create('details', {className: 'w-disclosure w-api-test__section'});
    environment.open = true;
    environment.append(create('summary', {text: t('i18nSettings')}));
    const controls = create('div', {className: 'w-api-settings-grid'});
    const mode = create('fieldset', {className: 'w-field w-api-mode'}, [
        create('legend', {className: 'w-field__label', text: t('i18nSettings')})
    ]);
    [['path', t('pathMode')], ['param', t('paramMode')]].forEach(([value, label]) => {
        const input = create('input', {attrs: {type: 'radio', name: 'api-i18n-mode', value}, dataset: {apiI18nMode: ''}});
        input.checked = state.i18nMode === value;
        mode.append(create('label', {className: 'w-radio'}, [input, create('span', {text: label})]));
    });
    controls.append(mode, selectField(t('language'), config.availableLocales, state.locale, 'restLocale'), selectField(t('currency'), config.availableCurrencies, state.currency, 'restCurrency'));
    const sandboxField = create('label', {className: 'w-field'}, [
        create('span', {className: 'w-field__label', text: t('sandboxKey')}),
        create('input', {className: 'w-input', attrs: {type: 'text'}, dataset: {apiSandbox: ''}})
    ]);
    sandboxField.querySelector('input').value = state.sandbox;
    controls.append(sandboxField);
    environment.append(controls);
    article.append(environment);

    const request = section(t('requestConfig'), 'w-api-test__section');
    const methodSelect = create('select', {className: 'w-select', dataset: {restMethod: ''}});
    ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'].forEach((value) => {
        methodSelect.append(create('option', {text: value, attrs: {value}}));
    });
    methodSelect.value = method;
    const urlInput = create('input', {className: 'w-input', attrs: {type: 'url'}, dataset: {restUrl: ''}});
    urlInput.value = buildRestUrl(api.example?.path || api.route?.path, Boolean(api.route?.is_backend));
    request.append(create('div', {className: 'w-api-request-line'}, [
        create('label', {className: 'w-field'}, [create('span', {className: 'w-field__label', text: t('method')}), methodSelect]),
        create('label', {className: 'w-field'}, [create('span', {className: 'w-field__label', text: t('url')}), urlInput])
    ]));

    const querySection = create('div', {className: 'w-api-request-group'}, [
        create('div', {className: 'w-api-request-group__header w-cluster', attrs: {'data-justify': 'between'}}, [
            create('h3', {text: t('queryParams')}),
            button(t('importExample'), 'import-params', 'quiet', 'download')
        ]),
        pairEditor('params', parameterPairs(api))
    ]);
    const headersSection = create('div', {className: 'w-api-request-group'}, [
        create('div', {className: 'w-api-request-group__header w-cluster', attrs: {'data-justify': 'between'}}, [
            create('h3', {text: t('headers')}),
            button(t('importExample'), 'import-headers', 'quiet', 'download')
        ]),
        pairEditor('headers', headerPairs(api, method))
    ]);
    const bodyValue = api.example?.body ?? api.example?.Body ?? {};
    const bodyField = create('div', {className: 'w-api-request-group', dataset: {restBodyGroup: ''}}, [
        create('div', {className: 'w-api-request-group__header w-cluster', attrs: {'data-justify': 'between'}}, [
            create('h3', {text: t('body')}),
            button(t('importExample'), 'import-body', 'quiet', 'download')
        ]),
        create('textarea', {className: 'w-textarea', attrs: {rows: '10', placeholder: t('jsonBody')}, dataset: {restBody: ''}})
    ]);
    bodyField.querySelector('textarea').value = stringify(bodyValue);
    bodyField.hidden = !['POST', 'PUT', 'PATCH'].includes(method);
    request.append(querySection, headersSection, bodyField);
    article.append(request, responseShell());
    return article;
}

function selectField(label, values, selected, datasetKey) {
    const select = create('select', {className: 'w-select', dataset: {[datasetKey]: ''}});
    (Array.isArray(values) ? values : []).forEach((item) => {
        const code = String(item?.code || item || '');
        if (!code) return;
        const name = String(item?.name || '');
        select.append(create('option', {text: name ? code + ' - ' + name : code, attrs: {value: code}}));
    });
    if (!select.options.length && selected) select.append(create('option', {text: selected, attrs: {value: selected}}));
    select.value = selected;
    return create('label', {className: 'w-field'}, [
        create('span', {className: 'w-field__label', text: label}),
        select
    ]);
}

function renderTest(api) {
    if (!testRoot) return;
    if (!api) {
        testRoot.replaceChildren(emptyState(t('chooseApi'), 'play'));
        return;
    }
    if (isSdk(api) && !isWorker(api)) {
        const box = create('div', {className: 'w-api-test w-stack'});
        box.append(create('h2', {text: t('sdkInfo')}));
        box.append(create('p', {text: t('guide')}));
        testRoot.replaceChildren(box);
        return;
    }
    testRoot.replaceChildren(isWorker(api) ? renderWorkerTest(api) : renderRestTest(api));
}

function parseWorkerValue(control) {
    const raw = String(control.value ?? '');
    const type = control.dataset.paramType || 'string';
    const required = control.dataset.paramRequired === '1';
    const name = control.dataset.paramName || '';
    if (!required && raw.trim() === '') return undefined;
    if (required && raw.trim() === '') throw new Error(t('requiredMissing') + ': ' + name);
    if (type === 'int' || type === 'integer') {
        const value = Number.parseInt(raw, 10);
        if (Number.isNaN(value)) throw new Error(t('invalidInteger') + ': ' + name);
        return value;
    }
    if (['float', 'double', 'number'].includes(type)) {
        const value = Number.parseFloat(raw);
        if (Number.isNaN(value)) throw new Error(t('invalidNumber') + ': ' + name);
        return value;
    }
    if (type === 'bool' || type === 'boolean') {
        if (raw === 'true') return true;
        if (raw === 'false') return false;
        throw new Error(t('invalidBoolean') + ': ' + name);
    }
    if (type === 'array' || type === 'object') {
        try {
            return JSON.parse(raw || (type === 'array' ? '[]' : '{}'));
        } catch (_error) {
            throw new Error(t('invalidJson') + ': ' + name);
        }
    }
    return raw;
}

function normalizeExclusiveWorkerParams(api, payload, editedName = '') {
    const descriptor = workerDescriptor(api);
    if (descriptor.provider !== 'query_help' || descriptor.operation !== 'provider') return payload;
    if (!payload.provider || !payload.module) return payload;
    const normalized = Object.assign({}, payload);
    if (editedName === 'module') delete normalized.provider;
    else delete normalized.module;
    return normalized;
}

function readWorkerControls(api, editedName = '') {
    const controls = [...testRoot.querySelectorAll('[data-worker-param]')];
    if (!controls.length) {
        const raw = testRoot.querySelector('[data-worker-raw]')?.value.trim() || '{}';
        const payload = JSON.parse(raw);
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error(t('invalidJson'));
        return payload;
    }
    const payload = {};
    controls.forEach((control) => {
        const value = parseWorkerValue(control);
        if (value !== undefined) payload[control.dataset.paramName] = value;
    });
    return normalizeExclusiveWorkerParams(api, payload, editedName);
}

function syncWorkerPreview(editedName = '') {
    const api = selectedApi();
    if (!api || !isWorker(api)) return;
    try {
        const payload = readWorkerControls(api, editedName);
        const raw = testRoot.querySelector('[data-worker-raw]');
        if (raw) raw.value = JSON.stringify(payload, null, 2);
        const preview = testRoot.querySelector('.w-api-call-preview code');
        if (preview) preview.textContent = workerCall(api, payload);
    } catch (error) {
        toast(error.message || String(error), 'danger');
    }
}

function syncWorkerFromRaw() {
    const api = selectedApi();
    const raw = testRoot.querySelector('[data-worker-raw]');
    if (!api || !raw) return;
    try {
        const payload = JSON.parse(raw.value.trim() || '{}');
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) throw new Error(t('invalidJson'));
        testRoot.querySelectorAll('[data-worker-param]').forEach((control) => {
            const name = control.dataset.paramName;
            if (!Object.prototype.hasOwnProperty.call(payload, name)) control.value = '';
            else {
                const value = payload[name];
                control.value = value && typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
            }
        });
        const preview = testRoot.querySelector('.w-api-call-preview code');
        if (preview) preview.textContent = workerCall(api, payload);
        raw.removeAttribute('aria-invalid');
    } catch (_error) {
        raw.setAttribute('aria-invalid', 'true');
    }
}

let apiRuntimePromise = null;

async function apiRuntime() {
    if (apiRuntimePromise) return apiRuntimePromise;
    apiRuntimePromise = (async () => {
        const existing = window.Weline?.Api;
        if (existing?.resource) return existing;
        if (typeof window.Weline?.load === 'function') {
            const loaded = await window.Weline.load('api');
            const candidates = [window.Weline?.Api, loaded?.Api, loaded?.default, loaded];
            const api = candidates.find((candidate) => candidate && typeof candidate.resource === 'function');
            if (api) return api;
        }
        throw new Error(t('runtimeUnavailable'));
    })().catch((error) => {
        apiRuntimePromise = null;
        throw error;
    });
    return apiRuntimePromise;
}

function setBusy(busy, label = '') {
    const trigger = testRoot?.querySelector('[data-api-send-button]');
    const status = testRoot?.querySelector('[data-api-request-status]');
    if (trigger) {
        trigger.disabled = busy;
        trigger.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    if (status) {
        status.hidden = !label;
        status.textContent = label;
        status.dataset.state = busy ? 'loading' : '';
    }
}

function normalizeHeaders(value) {
    if (!value) return {};
    if (typeof value.forEach === 'function') {
        const headers = {};
        value.forEach((item, key) => {
            headers[key] = item;
        });
        return headers;
    }
    return typeof value === 'object' ? value : {};
}

function normalizeTransport(result, error = null) {
    const source = error?.response || result || {};
    const body = source.body !== undefined ? source.body : (source.data !== undefined ? source.data : source);
    const code = Number(body?.code);
    const status = Number(source.status || (code >= 100 && code <= 599 ? code : (error ? 500 : 200)));
    const ok = source.ok !== undefined ? Boolean(source.ok) : (!error && status < 400 && body?.success !== false && !(code >= 400));
    return {
        ok,
        status,
        statusText: String(source.statusText || (ok ? 'OK' : t('requestFailed'))),
        headers: normalizeHeaders(source.headers),
        body,
        message: String(body?.msg || body?.message || error?.message || '')
    };
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), sizes.length - 1);
    return Math.round(bytes / Math.pow(1024, index) * 100) / 100 + ' ' + sizes[index];
}

function isInternalHeader(name) {
    const key = String(name || '').toLowerCase();
    return ['connection', 'server', 'server-timing', 'x-powered-by', 'x-weline-route-hint', 'x-wls-process-time'].includes(key)
        || key.startsWith('x-wls-performance-');
}

function renderResponse(transport, duration) {
    const response = testRoot.querySelector('[data-api-response]');
    if (!response) return;
    const bodyText = stringify(transport.body);
    state.lastResponseText = bodyText;
    response.hidden = false;
    const meta = response.querySelector('[data-api-response-meta]');
    const code = transport.body?.code;
    const message = transport.body?.msg || transport.body?.message;
    const values = [
        [t('statusCode'), transport.status + ' ' + transport.statusText, transport.ok ? 'success' : 'danger'],
        code !== undefined ? [t('responseCode'), code, 'neutral'] : null,
        message ? [t('responseMessage'), message, 'neutral'] : null,
        [t('requestTime'), duration + 'ms', 'neutral'],
        [t('responseSize'), formatBytes(new TextEncoder().encode(bodyText).length), 'neutral']
    ].filter(Boolean);
    meta.replaceChildren(...values.map(([label, value, tone]) => create('div', {className: 'w-api-response__metric'}, [
        create('span', {text: label}),
        create('strong', {text: value, dataset: {tone}})
    ])));
    const headerRoot = response.querySelector('[data-api-response-headers]');
    const visible = [];
    const internal = [];
    Object.entries(transport.headers).forEach((entry) => (isInternalHeader(entry[0]) ? internal : visible).push(entry));
    headerRoot.replaceChildren();
    if (visible.length) headerRoot.append(table([t('headerName'), t('headerValue')], visible));
    if (internal.length) {
        const details = create('details', {className: 'w-api-internal-headers'});
        details.append(create('summary', {text: internal.length + ' ' + t('hiddenInternalHeaders')}));
        details.append(table([t('headerName'), t('headerValue')], internal));
        headerRoot.append(details);
    }
    if (!visible.length && !internal.length) headerRoot.append(emptyState(t('responseHeaders'), 'list'));
    const body = response.querySelector('[data-api-response-body]');
    body.textContent = bodyText;
    setBusy(false, String(transport.status));
    const status = testRoot.querySelector('[data-api-request-status]');
    if (status) status.dataset.state = transport.ok ? 'success' : 'danger';
}

async function runWorker() {
    const api = selectedApi();
    if (!api) return;
    setBusy(true, t('requesting'));
    const started = performance.now();
    try {
        const payload = readWorkerControls(api);
        const descriptor = workerDescriptor(api);
        const runtime = await apiRuntime();
        const resource = await Promise.resolve(runtime.resource(descriptor.provider));
        if (!resource || typeof resource[descriptor.operation] !== 'function') throw new Error(t('operationUnavailable') + ': ' + descriptor.provider + '.' + descriptor.operation);
        const result = await resource[descriptor.operation](payload, {silent: true});
        renderResponse({
            ok: true,
            status: 200,
            statusText: 'WORKER',
            headers: {
                'x-weline-api': 'frontend-worker',
                'x-weline-provider': descriptor.provider,
                'x-weline-operation': descriptor.operation
            },
            body: result
        }, Math.round(performance.now() - started));
    } catch (error) {
        renderResponse(normalizeTransport(null, error), Math.round(performance.now() - started));
    } finally {
        const trigger = testRoot.querySelector('[data-api-send-button]');
        if (trigger) trigger.disabled = false;
    }
}

function readPairs(kind) {
    const editor = testRoot.querySelector('[data-pair-editor="' + kind + '"]');
    if (!editor) return [];
    return [...editor.querySelectorAll('.w-api-pair')].map((row) => ({
        key: row.querySelector('[data-pair-key]')?.value.trim() || '',
        value: row.querySelector('[data-pair-value]')?.value || ''
    })).filter((pair) => pair.key);
}

async function runRest() {
    const api = selectedApi();
    if (!api) return;
    const method = testRoot.querySelector('[data-rest-method]')?.value || 'GET';
    const rawUrl = testRoot.querySelector('[data-rest-url]')?.value || '';
    let url;
    try {
        url = new URL(rawUrl, window.location.origin);
    } catch (_error) {
        toast(t('url'), 'danger');
        return;
    }
    readPairs('params').forEach((pair) => {
        if (pair.value !== '') url.searchParams.set(pair.key, pair.value);
    });
    if (!state.production && state.sandbox) url.searchParams.set('sandbox', state.sandbox);
    const headers = {};
    readPairs('headers').forEach((pair) => {
        headers[pair.key] = pair.value;
    });
    const auth = restAuth(api);
    if (auth.token && !Object.keys(headers).some((key) => key.toLowerCase() === 'authorization')) {
        headers.Authorization = 'Bearer ' + auth.token;
    }
    let body;
    if (['POST', 'PUT', 'PATCH'].includes(method)) {
        const rawBody = testRoot.querySelector('[data-rest-body]')?.value.trim() || '';
        if (rawBody) {
            try {
                JSON.parse(rawBody);
                body = rawBody;
            } catch (_error) {
                toast(t('invalidJson'), 'danger');
                return;
            }
        }
    }
    setBusy(true, t('requesting'));
    const started = performance.now();
    try {
        const runtime = await apiRuntime();
        if (typeof runtime.request !== 'function') throw new Error(t('runtimeUnavailable'));
        const result = await runtime.request(url.href, {method, headers, body, silent: true});
        renderResponse(normalizeTransport(result), Math.round(performance.now() - started));
    } catch (error) {
        renderResponse(normalizeTransport(null, error), Math.round(performance.now() - started));
    } finally {
        const trigger = testRoot.querySelector('[data-api-send-button]');
        if (trigger) trigger.disabled = false;
    }
}

function replacePairs(kind, pairs) {
    const editor = testRoot.querySelector('[data-pair-editor="' + kind + '"]');
    const rows = editor?.querySelector('[data-pair-rows]');
    if (!rows) return;
    rows.replaceChildren(...pairs.map((pair) => fillPairRow(pairRow(kind), pair)));
}

function updateRestUrl() {
    const api = selectedApi();
    const input = testRoot.querySelector('[data-rest-url]');
    if (!api || !input) return;
    const locale = testRoot.querySelector('[data-rest-locale]')?.value || state.locale;
    const currency = testRoot.querySelector('[data-rest-currency]')?.value || state.currency;
    const mode = testRoot.querySelector('[data-api-i18n-mode]:checked')?.value || state.i18nMode;
    state.i18nMode = mode;
    writeStore(storageKeys.i18nMode, mode);
    input.value = buildRestUrl(api.example?.path || api.route?.path, Boolean(api.route?.is_backend), {locale, currency, mode});
}

async function copyText(value) {
    try {
        await navigator.clipboard.writeText(String(value || ''));
        toast(t('copySuccess'), 'success');
    } catch (_error) {
        toast(t('copyFailed'), 'danger');
    }
}

function openDialog(dialog) {
    if (!dialog) return;
    dialog.hidden = false;
    if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
    else dialog.setAttribute('open', '');
}

function closeDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
}

let confirmResolver = null;

function confirmAction(title, message, actionLabel) {
    const dialog = document.querySelector('[data-api-dialog="confirm"]');
    if (!dialog) return Promise.resolve(false);
    if (confirmResolver) confirmResolver(false);
    dialog.querySelector('[data-api-confirm-title]').textContent = title;
    dialog.querySelector('[data-api-confirm-message]').textContent = message;
    dialog.querySelector('[data-api-confirm-submit]').textContent = actionLabel || t('confirm');
    openDialog(dialog);
    return new Promise((resolve) => {
        confirmResolver = resolve;
    });
}

function finishConfirm(value) {
    const dialog = document.querySelector('[data-api-dialog="confirm"]');
    closeDialog(dialog);
    if (confirmResolver) {
        const resolve = confirmResolver;
        confirmResolver = null;
        resolve(Boolean(value));
    }
}

function authKeys(backend) {
    return backend
        ? {token: storageKeys.backendToken, refresh: storageKeys.backendRefresh, user: storageKeys.backendUser}
        : {token: storageKeys.token, refresh: storageKeys.refresh, user: storageKeys.user};
}

function authUser(backend) {
    try {
        return JSON.parse(readStore(authKeys(backend).user, '{}')) || {};
    } catch (_error) {
        return {};
    }
}

function updateLoginButton() {
    const control = document.querySelector('[data-api-login-button]');
    const label = document.querySelector('[data-api-login-label]');
    if (!control || !label) return;
    const backend = selectedApi()?.route?.is_backend || state.area === 'backend';
    const keys = authKeys(backend);
    const token = readStore(keys.token, '');
    const user = authUser(backend);
    if (token) {
        label.textContent = String(user.username || user.name || user.email || t('logout'));
        control.dataset.apiAction = 'logout';
        control.dataset.tone = 'neutral';
    } else {
        label.textContent = t('login');
        control.dataset.apiAction = 'open-login';
        control.dataset.tone = 'primary';
    }
}

function openLogin() {
    const backend = selectedApi()?.route?.is_backend || state.area === 'backend';
    const dialog = document.querySelector('[data-api-dialog="login"]');
    dialog.querySelector('[data-api-login-title]').textContent = backend ? t('loginBackendTitle') : t('loginFrontendTitle');
    openDialog(dialog);
    dialog.querySelector('[name="username"]')?.focus();
}

async function login(event) {
    event.preventDefault();
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true;
    const backend = selectedApi()?.route?.is_backend || state.area === 'backend';
    const payload = Object.fromEntries(new FormData(form).entries());
    const path = backend ? 'api/rest/v1/backend/auth/login' : 'api/rest/v1/auth/login';
    try {
        const runtime = await apiRuntime();
        if (typeof runtime.request !== 'function') throw new Error(t('runtimeUnavailable'));
        const result = await runtime.request(buildRestUrl(path, backend), {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            silent: true
        });
        const transport = normalizeTransport(result);
        const business = transport.body || {};
        const data = business.data || {};
        if (!transport.ok || (business.code !== undefined && Number(business.code) >= 400)) {
            throw new Error(business.msg || business.message || t('loginFailed'));
        }
        const keys = authKeys(backend);
        writeStore(keys.token, data.token || data.access_token || '');
        if (data.refresh_token) writeStore(keys.refresh, data.refresh_token);
        writeStore(keys.user, JSON.stringify(data.user || {}));
        closeDialog(form.closest('dialog'));
        form.reset();
        updateLoginButton();
        const api = selectedApi();
        if (api) renderTest(api);
        toast(t('loginSuccess'), 'success');
    } catch (error) {
        toast(t('loginFailed') + ': ' + (error.message || String(error)), 'danger');
    } finally {
        submit.disabled = false;
    }
}

async function logout() {
    if (!await confirmAction(t('logoutTitle'), t('logoutMessage'), t('logout'))) return;
    const backend = selectedApi()?.route?.is_backend || state.area === 'backend';
    const keys = authKeys(backend);
    removeStore(keys.token);
    removeStore(keys.refresh);
    removeStore(keys.user);
    updateLoginButton();
    const api = selectedApi();
    if (api) renderTest(api);
    toast(t('loggedOut'), 'success');
}

function applyTheme(preference) {
    const value = ['system', 'light', 'dark'].includes(preference) ? preference : 'system';
    const resolved = value === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : value;
    document.documentElement.dataset.themePreference = value;
    document.documentElement.dataset.theme = resolved;
    document.documentElement.style.colorScheme = resolved;
    if (window.Weline?.Theme?.switch) window.Weline.Theme.switch(value);
    else writeStore('weline-theme', value);
}

function applySavedLayout() {
    const sidebarSize = Number(readStore(storageKeys.sidebar, ''));
    const testRatio = Number(readStore(storageKeys.testRatio, ''));
    if (sidebarSize >= 240 && sidebarSize <= 640) root?.style.setProperty('--w-api-sidebar-size', sidebarSize + 'px');
    if (testRatio >= 30 && testRatio <= 65) workspace?.style.setProperty('--w-api-test-size', testRatio + '%');
    root.dataset.sidebarCollapsed = readStore(storageKeys.collapsed, 'false') === 'true' ? 'true' : 'false';
    updateSidebarButton();
}

function updateSidebarButton() {
    const control = document.querySelector('[data-api-action="toggle-sidebar"]');
    if (!control) return;
    const collapsed = root.dataset.sidebarCollapsed === 'true';
    const label = collapsed ? t('expandSidebar') : t('collapseSidebar');
    control.setAttribute('aria-label', label);
    control.title = label;
    control.replaceChildren(icon(collapsed ? 'chevron-right' : 'chevron-left'));
}

function resizeSidebar(clientX) {
    const bounds = root.getBoundingClientRect();
    const value = Math.max(240, Math.min(640, clientX - bounds.left));
    root.style.setProperty('--w-api-sidebar-size', value + 'px');
    writeStore(storageKeys.sidebar, Math.round(value));
}

function resizePanels(clientX) {
    const bounds = workspace.getBoundingClientRect();
    const ratio = Math.max(30, Math.min(65, (bounds.right - clientX) / bounds.width * 100));
    workspace.style.setProperty('--w-api-test-size', ratio + '%');
    writeStore(storageKeys.testRatio, ratio.toFixed(2));
}

function setupResizer(handle) {
    const kind = handle.dataset.apiResizer;
    let active = false;
    const apply = (clientX) => kind === 'sidebar' ? resizeSidebar(clientX) : resizePanels(clientX);
    handle.addEventListener('pointerdown', (event) => {
        if (window.matchMedia('(max-width: 900px)').matches) return;
        active = true;
        handle.setPointerCapture(event.pointerId);
        handle.dataset.state = 'dragging';
        event.preventDefault();
    });
    handle.addEventListener('pointermove', (event) => {
        if (active) apply(event.clientX);
    });
    const stop = (event) => {
        if (!active) return;
        active = false;
        handle.dataset.state = '';
        if (handle.hasPointerCapture(event.pointerId)) handle.releasePointerCapture(event.pointerId);
    };
    handle.addEventListener('pointerup', stop);
    handle.addEventListener('pointercancel', stop);
    handle.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        const delta = event.key === 'ArrowLeft' ? -16 : 16;
        if (kind === 'sidebar') {
            const size = parseFloat(getComputedStyle(root).getPropertyValue('--w-api-sidebar-size')) || 360;
            resizeSidebar(root.getBoundingClientRect().left + size + delta);
        } else {
            const bounds = workspace.getBoundingClientRect();
            const ratio = parseFloat(getComputedStyle(workspace).getPropertyValue('--w-api-test-size')) || 42;
            const nextX = bounds.right - bounds.width * (ratio - delta / bounds.width * 100) / 100;
            resizePanels(nextX);
        }
        event.preventDefault();
    });
}

document.addEventListener('click', async (event) => {
    const apiTrigger = event.target.closest('[data-api-id]');
    if (apiTrigger) {
        const api = apis.find((item) => String(item.id || '') === apiTrigger.dataset.apiId);
        if (api) selectApi(api, {focus: true});
        return;
    }
    const trigger = event.target.closest('[data-api-action]');
    if (!trigger) return;
    const action = trigger.dataset.apiAction;
    if (action === 'copy') await copyText(trigger.dataset.copyValue || '');
    else if (action === 'run-worker') await runWorker();
    else if (action === 'run-rest') await runRest();
    else if (action === 'copy-response') await copyText(state.lastResponseText);
    else if (action === 'format-response') {
        const output = testRoot.querySelector('[data-api-response-body]');
        try {
            state.lastResponseText = JSON.stringify(JSON.parse(state.lastResponseText), null, 2);
            if (output) output.textContent = state.lastResponseText;
        } catch (_error) {
            toast(t('invalidJson'), 'danger');
        }
    } else if (action === 'add-pair') {
        const editor = trigger.closest('[data-pair-editor]');
        editor?.querySelector('[data-pair-rows]')?.append(pairRow(editor.dataset.pairEditor));
    } else if (action === 'remove-pair') trigger.closest('.w-api-pair')?.remove();
    else if (action === 'import-params') replacePairs('params', parameterPairs(selectedApi()));
    else if (action === 'import-headers') replacePairs('headers', headerPairs(selectedApi(), testRoot.querySelector('[data-rest-method]')?.value || 'GET'));
    else if (action === 'import-body') {
        const body = testRoot.querySelector('[data-rest-body]');
        if (body) body.value = stringify(selectedApi()?.example?.body ?? selectedApi()?.example?.Body ?? {});
    } else if (action === 'toggle-sidebar') {
        root.dataset.sidebarCollapsed = root.dataset.sidebarCollapsed === 'true' ? 'false' : 'true';
        writeStore(storageKeys.collapsed, root.dataset.sidebarCollapsed);
        updateSidebarButton();
    } else if (action === 'open-login') openLogin();
    else if (action === 'logout') await logout();
    else if (action === 'close-dialog') closeDialog(trigger.closest('dialog'));
    else if (action === 'confirm-result') finishConfirm(trigger.dataset.confirmValue === 'true');
});

document.addEventListener('change', async (event) => {
    const target = event.target;
    if (target.matches('[data-api-area]')) return;
    if (target.matches('[data-api-theme]')) applyTheme(target.value);
    else if (target.matches('[data-api-production]')) {
        if (target.checked && !state.production) {
            const confirmed = await confirmAction(t('productionTitle'), t('productionMessage'), t('continueEnable'));
            if (!confirmed) {
                target.checked = false;
                return;
            }
        }
        state.production = target.checked;
        writeStore(storageKeys.production, state.production);
        const api = selectedApi();
        if (api) {
            renderDetail(api);
            renderTest(api);
        }
    } else if (target.matches('[data-rest-method]')) {
        const group = testRoot.querySelector('[data-rest-body-group]');
        if (group) group.hidden = !['POST', 'PUT', 'PATCH'].includes(target.value);
    } else if (target.matches('[data-api-i18n-mode], [data-rest-locale], [data-rest-currency]')) updateRestUrl();
});

document.querySelectorAll('[data-api-area]').forEach((tab) => {
    tab.addEventListener('click', () => {
        state.area = tab.dataset.apiArea;
        writeStore(storageKeys.area, state.area);
        const current = selectedApi();
        if (current && Boolean(current.route?.is_backend) !== (state.area === 'backend')) {
            state.selectedId = '';
            updateUrl('', false);
            renderDetail(null);
            renderTest(null);
        }
        updateAreaTabs();
        renderList();
        updateLoginButton();
    });
});

let searchTimer = 0;
searchInput?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        state.query = searchInput.value;
        renderList();
    }, 180);
});

testRoot?.addEventListener('input', (event) => {
    const target = event.target;
    if (target.matches('[data-worker-param]')) syncWorkerPreview(target.dataset.paramName || '');
    else if (target.matches('[data-worker-raw]')) syncWorkerFromRaw();
    else if (target.matches('[data-api-sandbox]')) {
        state.sandbox = target.value.trim();
        writeStore(storageKeys.sandbox, state.sandbox);
    }
});

document.querySelector('[data-api-login-form]')?.addEventListener('submit', login);
document.querySelectorAll('dialog[data-api-dialog]').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            if (dialog.dataset.apiDialog === 'confirm') finishConfirm(false);
            else closeDialog(dialog);
        }
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (dialog.dataset.apiDialog === 'confirm') finishConfirm(false);
        else closeDialog(dialog);
    });
});

window.addEventListener('popstate', () => {
    const apiId = new URL(window.location.href).searchParams.get('api_id') || '';
    const api = apis.find((item) => String(item.id || '') === apiId);
    if (api) selectApi(api, {history: false});
    else {
        state.selectedId = '';
        renderList();
        renderDetail(null);
        renderTest(null);
    }
});

document.querySelectorAll('[data-api-resizer]').forEach(setupResizer);
applySavedLayout();
state.area = ['frontend', 'backend'].includes(state.area) ? state.area : 'frontend';
state.locale = String(config.currentLocale || state.locale);
state.currency = String(config.currentCurrency || state.currency);
productionSwitch.checked = state.production;
if (themeSelect) {
    themeSelect.value = document.documentElement.dataset.themePreference || readStore('weline-theme', 'system');
    applyTheme(themeSelect.value);
}
updateAreaTabs();
renderList();
updateLoginButton();
if (selectedFromConfig) selectApi(selectedFromConfig, {history: false, replace: true});
else if (config.error) {
    detailRoot?.replaceChildren(emptyState(config.error, 'warning'));
    testRoot?.replaceChildren(emptyState(t('chooseApi'), 'play'));
} else {
    renderDetail(null);
    renderTest(null);
}
