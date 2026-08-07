'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../../view/statics/js/i18n.js');
const source = fs.readFileSync(sourcePath, 'utf8');

function bootI18n({pathname, search = '', hash = ''}) {
    const storage = new Map();
    const sessionStorageMap = new Map();
    let reloadCount = 0;
    const listeners = new Map();

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
        cookie: '',
        documentElement: {
            lang: 'ru-RU',
            outerHTML: '<html><head></head><body></body></html>',
            setAttribute() {},
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
