'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../../view/statics/js/i18n.js');
const source = fs.readFileSync(sourcePath, 'utf8');

function bootI18n({pathname, search = '', hash = '', cookie = '', documentLang = '', dataLang = ''}) {
    const storage = new Map();
    const sessionStorageMap = new Map();
    let reloadCount = 0;
    const listeners = new Map();
    const docAttrs = {
        lang: documentLang === undefined || documentLang === null ? 'ru-RU' : documentLang,
        'data-lang': dataLang === undefined || dataLang === null ? '' : dataLang,
    };

    const localStorage = {
        getItem(key) {
            return storage.has(key) ? storage.get(key) : null;
        },
        setItem(key, value) {
            storage.set(String(key), String(value));
        },
        removeItem(key) {
            storage.delete(String(key));
        },
    };
    const sessionStorage = {
        getItem(key) {
            return sessionStorageMap.has(key) ? sessionStorageMap.get(key) : null;
        },
        setItem(key, value) {
            sessionStorageMap.set(String(key), String(value));
        },
        removeItem(key) {
            sessionStorageMap.delete(String(key));
        },
    };
    const location = {
        origin: 'https://p05113ef3.weline.test:9976',
        pathname,
        search,
        hash,
        href: `https://p05113ef3.weline.test:9976${pathname}${search}${hash}`,
        reload() {
            reloadCount += 1;
        },
        assign(url) {
            this.href = String(url);
        },
        replace(url) {
            this.href = String(url);
        },
    };
    const document = {
        readyState: 'loading',
        cookie: cookie || '',
        documentElement: {
            lang: docAttrs.lang,
            outerHTML: '<html><head></head><body></body></html>',
            getAttribute(name) {
                const key = String(name || '');
                return Object.prototype.hasOwnProperty.call(docAttrs, key) ? String(docAttrs[key] || '') : null;
            },
            setAttribute(name, value) {
                docAttrs[String(name)] = String(value);
                if (String(name) === 'lang') {
                    this.lang = String(value);
                }
            },
        },
        body: {
            innerHTML: '',
        },
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        querySelector() {
            return null;
        },
        querySelectorAll() {
            return [];
        },
    };
    const window = {
        document,
        location,
        localStorage,
        sessionStorage,
        site: {},
        DEV: false,
        __WelineThemeConfig: {
            area: 'backend',
            backendKey: 'jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH',
            currentCurrency: 'CNY',
            defaultCurrency: 'CNY',
            currentLang: 'ru_RU',
            defaultLang: 'en_US',
        },
        addEventListener(type, listener) {
            listeners.set(`window:${type}`, listener);
        },
        dispatchEvent() {},
    };
    window.window = window;
    window.self = window;

    const context = vm.createContext({
        window,
        document,
        location,
        localStorage,
        sessionStorage,
        navigator: {language: 'ru-RU'},
        console,
        URL,
        URLSearchParams,
        Intl,
        setTimeout(fn) {
            if (typeof fn === 'function') {
                fn();
            }
            return 1;
        },
        clearTimeout() {},
        setInterval() {
            return 1;
        },
        clearInterval() {},
        MutationObserver: class {
            observe() {}
            disconnect() {}
        },
        CustomEvent: class {
            constructor(type, init = {}) {
                this.type = type;
                this.detail = init.detail;
            }
        },
    });

    vm.runInContext(source, context, {filename: sourcePath});
    assert.equal(typeof window.WelineI18n?.switchLang, 'function');

    return {
        api: window.WelineI18n,
        location,
        storage,
        getReloadCount: () => reloadCount,
    };
}

test('switchLang navigates the server-rendered authoritative href without rebuilding it', async () => {
    const runtime = bootI18n({
        pathname: '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/ru_RU/cms/backend/page/edit',
        search: '?page_id=6',
        hash: '#title',
    });
    const authoritativeHref = '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/en_US/cms/backend/page/edit?page_id=6#title';

    await runtime.api.switchLang('en_US', authoritativeHref);

    assert.equal(
        runtime.location.href,
        `https://p05113ef3.weline.test:9976${authoritativeHref}`
    );
    assert.equal(runtime.storage.get('weline_user_lang'), 'en_US');
    assert.equal(runtime.getReloadCount(), 0);
});

test('switchLang reloads when the authoritative href is the current URL', async () => {
    const pathname = '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/en_US/cms/backend/page/edit';
    const search = '?page_id=6';
    const hash = '#title';
    const runtime = bootI18n({pathname, search, hash});
    const authoritativeHref = `${pathname}${search}${hash}`;

    await runtime.api.switchLang('en_US', authoritativeHref);

    assert.equal(runtime.getReloadCount(), 1);
    assert.equal(runtime.storage.get('weline_user_lang'), 'en_US');
});

test('getCurrentLang prefers document data-lang over Cookie (theme preview)', () => {
    const runtime = bootI18n({
        pathname: '/theme/frontend/theme-preview/content',
        search: '?locale=en_US&editor_mode=1',
        cookie: 'WELINE_USER_LANG=zh_Hans_CN',
        dataLang: 'en_US',
        documentLang: 'en-US',
    });

    assert.equal(runtime.api.getCurrentLang(), 'en_US');
});

test('getCurrentLang prefers query locale when document lang is absent', () => {
    const runtime = bootI18n({
        pathname: '/theme/frontend/theme-preview/content',
        search: '?locale=en_US&editor_mode=1',
        cookie: 'WELINE_USER_LANG=zh_Hans_CN',
        dataLang: '',
        documentLang: '',
    });

    assert.equal(runtime.api.getCurrentLang(), 'en_US');
});

test('getCurrentLang still prefers path language over Cookie and document', () => {
    const runtime = bootI18n({
        pathname: '/zh_Hans_CN/products',
        search: '?locale=en_US',
        cookie: 'WELINE_USER_LANG=en_US',
        dataLang: 'en_US',
        documentLang: 'en-US',
    });

    assert.equal(runtime.api.getCurrentLang(), 'zh_Hans_CN');
});
