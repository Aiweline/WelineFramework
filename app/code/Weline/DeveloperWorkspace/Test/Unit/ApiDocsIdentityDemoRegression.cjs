'use strict';

// 执行：node app/code/Weline/DeveloperWorkspace/Test/Unit/ApiDocsIdentityDemoRegression.cjs
// 运行正式脚本的行为函数；仅替换页面节点和网络，不替代真实浏览器验收。
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const {test} = require('node:test');

const sourcePath = path.resolve(__dirname, '../../view/statics/js/api-docs.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const normalize = value => JSON.parse(JSON.stringify(value));

function extract(name) {
    const match = source.match(new RegExp('^(?:async )?function ' + name + '\\b[\\s\\S]*?(?=^(?:async )?function |$(?![\\s\\S]))', 'm'));
    assert.ok(match, '正式文档脚本缺少函数：' + name);
    return match[0];
}

function runtime(names, bindings, extra = '') {
    const context = vm.createContext({URL, crypto: webcrypto, t: (key, fallback) => fallback || key, ...bindings});
    vm.runInContext(names.map(extract).join('\n') + extra, context, {filename: sourcePath});
    return context;
}

test('Demo 保留数字零、false 和多语言字段，回填零版本', () => {
    const context = runtime(['demoValue', 'demoCapture'], {});
    const values = {sku: 'DEMO-1', locale: 'en_US', version: 0, enabled: false, translations: {zh_Hans_CN: {name: '中文保留'}}};
    const request = context.demoValue({
        sku: {$field: 'sku'}, locale: {$field: 'locale'}, version: {$field: 'version'},
        enabled: {$field: 'enabled'}, translations: {$field: 'translations'}, omitted: {$field: 'missing'}
    }, values);
    assert.deepEqual(normalize(request), values);
    const captured = {};
    context.demoCapture({id: 'data.id', local_version: 'data.version'}, {data: {id: 'demo-record', version: 0}}, captured);
    assert.deepEqual(normalize(captured), {id: 'demo-record', local_version: 0});
    assert.equal(context.demoValue({expected_version: {$field: 'local_version'}}, captured).expected_version, 0);
});

test('Demo 字段解析保留类型并拒绝已有契约中的无效整数和 JSON', () => {
    const context = runtime(['demoFieldValue'], {});
    assert.equal(context.demoFieldValue({type: 'integer', name: 'version'}, '0'), 0);
    assert.equal(context.demoFieldValue({type: 'boolean', name: 'enabled'}, 'false'), false);
    assert.deepEqual(normalize(context.demoFieldValue({type: 'json', name: 'translations'}, '{"en_US":{"name":"English"}}')), {en_US: {name: 'English'}});
    assert.equal(context.demoFieldValue({type: 'number', name: 'price'}, ''), undefined);
    assert.throws(() => context.demoFieldValue({type: 'integer', name: 'version'}, '1.5'));
    assert.throws(() => context.demoFieldValue({type: 'json', name: 'translations'}, '{bad'));
});

test('Demo locale 可输入文档列表之外的语言，显式 options 仍为选择框', () => {
    const nodes = [];
    const context = runtime(['demoField', 'demoFieldValue'], {
        config: {availableLocales: [{code: 'zh_Hans_CN', name: '中文'}]},
        state: {demoId: 'locale-input'},
        create(tag, options = {}, children = []) {
            const element = {
                tag, ...options, attrs: {...options.attrs}, children: [...children],
                append(...items) {this.children.push(...items);},
                setAttribute(name, value) {this.attrs[name] = value;},
                getAttribute(name) {return this.attrs[name];}
            };
            nodes.push(element);
            return element;
        }
    });
    const field = {name: 'locale', type: 'locale', label: '语言'};
    context.demoField(field, 'en_US');
    const input = nodes.find(element => element.tag === 'input' && element.attrs.name === 'demo.locale');
    assert.ok(input, 'locale 必须允许输入，不能被文档译文列表限定为 select');
    const suggestions = nodes.find(element => element.tag === 'datalist');
    assert.ok(suggestions, '文档语言应作为 datalist 建议');
    assert.equal(input.attrs.list, suggestions.attrs.id || suggestions.id);
    assert.ok(input.attrs.list, '语言输入应绑定其建议列表');
    assert.equal(input.value, 'en_US');
    assert.equal(context.demoFieldValue(field, input.value), 'en_US');
    nodes.length = 0;
    context.demoField({...field, options: [{value: 'fr_FR', label: 'French'}]}, 'fr_FR');
    const select = nodes.find(element => element.tag === 'select');
    assert.ok(select, '描述符显式 options 保留选择框');
    assert.equal(select.value, 'fr_FR');
});

// 两个接口类共同完成创建、回读、发布、再回读；不依赖任何业务模块描述。
const recordClass = 'Example\\Api\\Records';
const publicationClass = 'Example\\Api\\Publication';
const descriptor = {
    id: 'record-workflow',
    actions: [{
        id: 'create_publish',
        steps: [
            {api: {class: recordClass, method: 'postCreate'}, request: {payload: {sku: {$field: 'sku'}, name: {$field: 'name'}}, translations: {$field: 'translations'}}, capture: {resource_id: 'data.id', local_version: 'data.version'}},
            {api: {class: recordClass, method: 'getDetail'}, request: {id: {$field: 'resource_id'}}, capture: {local_version: 'data.version'}},
            {api: {class: publicationClass, method: 'postPublish'}, request: {resource_id: {$field: 'resource_id'}, expected_version: {$field: 'local_version'}, payload: {local_version: {$field: 'local_version'}}}, capture: {local_version: 'data.version'}},
            {api: {class: recordClass, method: 'getDetail'}, request: {id: {$field: 'resource_id'}}, capture: {local_version: 'data.version'}}
        ]
    }]
};
const fixtureApis = [
    {class: recordClass, method: 'postCreate', route: {method: 'POST', path: '/api/records/create'}},
    {class: recordClass, method: 'getDetail', route: {method: 'GET', path: '/api/records/detail'}},
    {class: publicationClass, method: 'postPublish', route: {method: 'POST', path: '/api/publication/publish'}}
];

async function exerciseFlow({failure = false, signedIn = true} = {}) {
    const calls = [];
    let logins = 0;
    const session = {
        values: {sku: 'DEMO-FLOW', name: '中文', translations: {en_US: {name: 'English'}}},
        drafts: {local_version: 'stale draft'}, history: [], busy: false, error: ''
    };
    const context = runtime(['demoValue', 'demoCapture', 'demoApi', 'demoRequest', 'runDemo'], {
        selectedApi: () => ({demo: descriptor}), demoDefinition: api => api.demo,
        demoSession: () => session, readDemoFields: () => session.values, apis: fixtureApis,
        state: {demoId: descriptor.id, production: true}, window: {location: {origin: 'https://example.test'}},
        buildRestUrl: value => value,
        testRoot: {querySelector: () => ({reportValidity: () => true}), replaceChildren() {}},
        renderDemo() {}, toast() {}, restAuth: () => ({required: true, token: signedIn ? 'test-only-placeholder' : ''}),
        openLogin() {logins++;},
        async sendHttp(url, options) {
            calls.push({url, options});
            if (failure && calls.length === 2) return {ok: false, status: 403, body: {success: false, message: 'Scope denied'}};
            const version = calls.length >= 3 ? 1 : 0;
            return {ok: true, status: calls.length === 1 ? 201 : 200, body: {success: true, data: {id: 'demo-record', version}}};
        }
    });
    await context.runDemo('create_publish');
    return {calls, session, logins};
}

test('跨 API Demo 按回填顺序传递身份与版本，再回读新版本', async () => {
    const {calls, session} = await exerciseFlow();
    assert.deepEqual(calls.map(call => new URL(call.url).pathname), ['/api/records/create', '/api/records/detail', '/api/publication/publish', '/api/records/detail']);
    assert.deepEqual(JSON.parse(calls[0].options.body).translations, {en_US: {name: 'English'}});
    assert.equal(new URL(calls[1].url).searchParams.get('id'), 'demo-record');
    assert.equal(calls[1].options.body, undefined);
    assert.deepEqual(JSON.parse(calls[2].options.body), {resource_id: 'demo-record', expected_version: 0, payload: {local_version: 0}});
    assert.equal(session.values.local_version, 1);
    assert.equal(session.drafts.local_version, undefined);
    assert.equal(session.history.length, 4);
    assert.equal(session.error, '');
    assert.equal(session.busy, false);
});

test('Demo 首次失败停止后续步骤，保留成功回填与失败响应', async () => {
    const {calls, session} = await exerciseFlow({failure: true});
    assert.equal(calls.length, 2);
    assert.equal(session.values.resource_id, 'demo-record');
    assert.equal(session.values.local_version, 0);
    assert.equal(session.history[1].status, 403);
    assert.equal(session.error, 'Scope denied');
    assert.equal(session.busy, false);
});

test('Demo 未登录时打开登录框且不发送请求', async () => {
    const {calls, session, logins} = await exerciseFlow({signedIn: false});
    assert.equal(logins, 1);
    assert.equal(calls.length, 0);
    assert.equal(session.history.length, 0);
    assert.equal(session.busy, false);
});

const clickStart = source.indexOf("document.addEventListener('click', async (event) => {");
const clickEnd = source.indexOf("document.addEventListener('change', async (event) => {", clickStart);
assert.ok(clickStart >= 0 && clickEnd > clickStart, '正式脚本的点击事件入口不可用');
const clickHandler = source.slice(clickStart, clickEnd);
const node = () => ({append() {}, querySelector: () => ({value: '', append() {}})});

async function exerciseRest(manualHeaders, storedToken) {
    const calls = [];
    let logins = 0;
    let action;
    let click;
    const api = {
        route: {method: 'POST', path: '/api/records/create'}, acl: {class: 'Example::records'},
        example: {body: {payload: {name: '中文', version: 0}, translations: {en_US: {name: 'English'}}}}
    };
    const rawBody = JSON.stringify(api.example.body);
    const context = runtime(['restAuth', 'renderRestTest', 'runRest', 'sendHttp'], {
        performance, config: {availableLocales: [], availableCurrencies: []},
        storageKeys: {token: 'frontend', backendToken: 'backend'},
        state: {locale: 'zh_Hans_CN', currency: 'CNY', i18nMode: 'path', sandbox: '', production: true},
        window: {location: {origin: 'https://example.test'}},
        document: {addEventListener(name, callback) {if (name === 'click') click = callback;}},
        selectedApi: () => api, readStore: () => storedToken,
        readPairs: kind => kind === 'headers' ? manualHeaders : [],
        testRoot: {querySelector(selector) {
            return {
                '[data-rest-method]': {value: 'POST'},
                '[data-rest-url]': {value: 'https://example.test/api/records/create'},
                '[data-rest-body]': {value: rawBody}, '[data-api-send-button]': {disabled: false}
            }[selector] || null;
        }},
        create: node, section: node, selectField: node, pairEditor: node, button: node, responseShell: node,
        headerPairs: () => [], parameterPairs: () => [], stringify: JSON.stringify,
        buildRestUrl: value => 'https://example.test' + value,
        testHeader(_api, chosen) {action = chosen; return node();},
        toast() {}, setBusy() {}, normalizeTransport: value => value, renderResponse() {},
        openLogin() {logins++;},
        async fetch(url, options) {
            calls.push({url, options});
            return {
                ok: true, status: 201, statusText: 'Created', text: async () => '{"success":true}',
                headers: {get: () => 'application/json', forEach() {}}
            };
        }
    }, clickHandler);
    context.renderRestTest(api);
    await click({target: {closest: selector => selector === '[data-api-action]' ? {dataset: {apiAction: action}} : null}});
    return {calls, logins, action, rawBody};
}

const manual = [{key: 'Authorization', value: 'Bearer manual-test-placeholder'}];

test('登录使用 Auth 文档的真实路径并保留可配置 API 区域和响应令牌', async () => {
    for (const area of ['api', 'custom-api']) {
        const calls = [];
        const stored = new Map();
        const expectedPath = '/' + area + '/api/api/rest/v1/auth/login';
        const submit = {disabled: false};
        let resets = 0;
        const form = {reportValidity: () => true, querySelector: () => submit, closest: () => ({}), reset() {resets++;}};
        const context = runtime(['login', 'authKeys', 'buildRestUrl', 'normalizeTransport', 'sendHttp'], {
            config: {apiArea: area, apiAdminArea: 'api_admin'},
            state: {area: 'frontend', i18nMode: 'path', currency: 'CNY', locale: 'zh_Hans_CN'},
            apis: [{class: 'Weline\\Api\\Api\\Rest\\V1\\Auth', method: 'postLogin', route: {path: expectedPath, is_backend: false}}],
            selectedApi: () => fixtureApis[0], window: {location: {origin: 'https://example.test'}},
            storageKeys: {token: 'frontend-token', refresh: 'frontend-refresh', user: 'frontend-user'},
            FormData: class {entries() {return [['username', 'test-user'], ['password', 'test-only-placeholder']];}},
            normalizeHeaders: headers => headers,
            writeStore(key, value) {stored.set(key, value);}, closeDialog() {}, updateLoginButton() {}, renderTest() {}, toast() {},
            async fetch(url, options) {
                calls.push({url, options});
                return {ok: true, status: 200, text: async () => JSON.stringify({code: 200, data: {access_token: 'test-token', refresh_token: 'test-refresh', user: {id: 1}}}), headers: {get: () => 'application/json', forEach() {}}};
            }
        });
        await context.login({preventDefault() {}, currentTarget: form});
        assert.equal(calls.length, 1);
        assert.equal(new URL(calls[0].url).pathname, expectedPath);
        assert.equal(new URL(context.buildRestUrl('/weline_product/rest/v1/products/create')).pathname,
            '/' + area + '/weline_product/rest/v1/products/create');
        assert.equal(calls[0].options.method, 'POST');
        assert.deepEqual(JSON.parse(calls[0].options.body), {username: 'test-user', password: 'test-only-placeholder'});
        assert.equal(stored.get('frontend-token'), 'test-token');
        assert.equal(stored.get('frontend-refresh'), 'test-refresh');
        assert.equal(resets, 1);
        assert.equal(submit.disabled, false);
    }
});

for (const [label, headers, storedToken, expectedHeader] of [
    ['未存储令牌时可以手工授权发送', manual, '', manual[0].value],
    ['没有凭据时保留登录路径', [], '', null],
    ['未手工授权时使用存储令牌', [], 'stored-test-placeholder', 'Bearer stored-test-placeholder'],
    ['手工 Authorization 优先于存储令牌', manual, 'stored-test-placeholder', manual[0].value]
]) {
    test('REST：' + label, async () => {
        const {calls, logins, action, rawBody} = await exerciseRest(headers, storedToken);
        assert.equal(action, 'run-rest');
        assert.equal(calls.length, expectedHeader === null ? 0 : 1);
        assert.equal(logins, expectedHeader === null ? 1 : 0);
        if (expectedHeader !== null) {
            assert.equal(calls[0].options.headers.Authorization, expectedHeader);
            assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
            assert.equal(calls[0].options.body, rawBody);
            assert.equal(calls[0].options.method, 'POST');
        }
    });
}
