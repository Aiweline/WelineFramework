/**
 * Weline Framework - 统一前端JS入口
 * 
 * 所有功能模块按需加载，页面只需引入此文件
 * 使用方式：
 *   const CartApi = await Weline.Api.resource('cart')
 *   await CartApi.add({ product_id, qty })
 *   Weline.Account.frontendLogin(username, password)
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.Weline && window.Weline.__initialized) {
        return;
    }

    /**
     * 默认配置
     */
    const defaultConfig = {
        baseUrl: window.location.origin,
        modulesBaseUrl: '/static/Weline_Frontend/js/weline-api',
        assetVersion: 'dev',
        // 模块配置
        api: {
            workerUrl: null,
            useCredentials: 'same-origin',
            cartFlagStorageKey: 'weline_cart_has_items',
            cartProbeSessionKey: 'weline_cart_probe_done',
            cartCountCookieKey: 'weline_cart_item_count',
            cartStatusUrl: null,
            cartStatusResolver: null,
            autoRequests: [],
            autoEnableOnCartClickSelector: '[data-weline-cart-trigger]',
            maintenanceHandler: null,
        },
        account: {
            frontendTokenKey: 'weline_frontend_session',
            apiTokenKey: 'weline_api_access_token',
            apiRefreshTokenKey: 'weline_api_refresh_token',
            backendApiTokenKey: 'weline_backend_api_token',
            backendApiRefreshTokenKey: 'weline_backend_api_refresh_token',
            apiUserKey: 'weline_api_user',
            backendApiUserKey: 'weline_backend_api_user',
        },
    };

    // 合并用户配置
    const runtimeConfig = Object.assign(
        {},
        defaultConfig,
        (window.Weline && window.Weline.config) || {}
    );

    function writeCookieValue(key, value, expiry = 365, options = {}) {
        const normalizedOptions = Object.assign({ path: '/' }, options || {});
        if (typeof window.setCookie === 'function') {
            window.setCookie(key, value, expiry, normalizedOptions);
            return;
        }
        const expires = new Date();
        expires.setTime(expires.getTime() + (expiry * 24 * 60 * 60 * 1000));
        let cookieString = key + '=' + encodeURIComponent(value) + ';expires=' + expires.toUTCString();
        Object.keys(normalizedOptions).forEach((optionKey) => {
            cookieString += ';' + optionKey + '=' + normalizedOptions[optionKey];
        });
        document.cookie = cookieString;
    }

    function writeCanonicalLocalStorage(canonicalKey, legacyKeys, value) {
        try {
            if (!window.localStorage) {
                return;
            }
            localStorage.setItem(canonicalKey, value);
            legacyKeys.forEach((key) => {
                if (key && key !== canonicalKey) {
                    localStorage.removeItem(key);
                }
            });
        } catch (error) {
            // localStorage can be unavailable in privacy modes.
        }
    }

    function persistLangPreference(lang) {
        writeCanonicalLocalStorage('weline_user_lang', ['weline_user_lang', 'api_doc_locale', 'WELINE_USER_LANG'], lang);
        writeCookieValue('WELINE_USER_LANG', lang, 365);
    }

    function persistCurrencyPreference(currency) {
        writeCanonicalLocalStorage('weline_user_currency', ['weline_user_currency', 'api_doc_currency', 'WELINE_USER_CURRENCY'], currency);
        writeCookieValue('WELINE_USER_CURRENCY', currency, 365);
    }

    /**
     * 模块加载器
     */
    class ModuleLoader {
        constructor() {
            this.loadedModules = new Map();
            this.loadingModules = new Map();
            /** @type {Set<string>} 路径启发式已认领（优先于属性加载） */
            this.pathHeuristicModules = new Set();
        }

        /**
         * 模块基础 URL（由 Frontend head 注入 runtimeConfig.modulesBaseUrl）
         */
        getModulesBaseUrl() {
            if (runtimeConfig.modulesBaseUrl) {
                return runtimeConfig.modulesBaseUrl;
            }
            throw new Error('[Weline.ModuleLoader] modulesBaseUrl 未配置，请在 Frontend 模块的 head 模板中配置 modulesBaseUrl');
        }

        getScriptUrl(url) {
            const isDev = runtimeConfig.debug ||
                window.DEV ||
                window.location.hostname === 'localhost' ||
                window.location.hostname === '127.0.0.1';
            if (!isDev) {
                return url;
            }
            if (url.indexOf('_weline_dev=') !== -1) {
                return url;
            }
            const assetVersion = encodeURIComponent(String(
                runtimeConfig.assetVersion ||
                runtimeConfig.deployVersion ||
                runtimeConfig.deploy_version ||
                'dev'
            ));
            const separator = url.indexOf('?') === -1 ? '?' : '&';
            return `${url}${separator}_weline_dev=${assetVersion}`;
        }

        /**
         * Resolve Weline_Module::path or absolute/http paths (Theme StaticResourceResolver 对齐).
         */
        resolveStaticPath(modulePath) {
            if (!modulePath || typeof modulePath !== 'string') {
                return modulePath;
            }
            if (modulePath.indexOf('http://') === 0 || modulePath.indexOf('https://') === 0 || modulePath.charAt(0) === '/') {
                return modulePath;
            }
            if (modulePath.indexOf('::') === -1) {
                return modulePath;
            }
            const parts = modulePath.split('::');
            if (parts.length !== 2) {
                return modulePath;
            }
            const moduleName = parts[0].trim();
            const filePath = parts[1].trim().replace(/^\/+/, '');
            const modulePathParts = moduleName.split('_');
            const vendorName = modulePathParts[0];
            const moduleNamePart = modulePathParts.slice(1).join('_');
            const normalizedModuleName = `${vendorName}/${moduleNamePart}`;
            const isDev = runtimeConfig.debug ||
                window.DEV ||
                window.location.hostname === 'localhost' ||
                window.location.hostname === '127.0.0.1';
            if (isDev) {
                return `/${normalizedModuleName}/view/statics/${filePath}`;
            }
            return `/static/${normalizedModuleName}/${filePath}`;
        }

        ensureModulesConfig() {
            if (this._modulesConfigPromise) {
                return this._modulesConfigPromise;
            }
            const url = runtimeConfig.modulesConfigUrl;
            if (!url) {
                this._modulesConfigPromise = Promise.resolve();
                return this._modulesConfigPromise;
            }
            if (window.WelineModulesConfig && window.WelineModulesConfig.__welineMerged === true) {
                this._modulesConfigPromise = Promise.resolve();
                return this._modulesConfigPromise;
            }
            this._modulesConfigPromise = new Promise((resolve) => {
                const script = document.createElement('script');
                script.src = this.getScriptUrl(url);
                script.async = true;
                script.onload = () => {
                    if (window.WelineModulesConfig) {
                        window.WelineModulesConfig.__welineMerged = true;
                    }
                    resolve();
                };
                script.onerror = () => resolve();
                document.head.appendChild(script);
            });
            return this._modulesConfigPromise;
        }

        lookupModuleConfig(moduleName) {
            const cfg = window.WelineModulesConfig || {};
            const aliases = cfg.moduleAliases || {};
            const modules = cfg.modules || {};
            const resolvedName = aliases[moduleName] || moduleName;
            return {
                resolvedName: resolvedName,
                moduleConfig: modules[resolvedName] || modules[moduleName] || null,
            };
        }

        isGlobalModuleReady(globalVarName, requireFullGlobal = false) {
            if (!globalVarName || !window[globalVarName]) {
                return false;
            }
            if (!requireFullGlobal) {
                return true;
            }
            if (window[globalVarName].__full !== true) {
                return false;
            }
            if (globalVarName === 'WelineApiModule') {
                const requiredMethods = ['request', 'get', 'post', 'call', 'graph', 'stream', 'resource'];
                return requiredMethods.every(method => typeof window[globalVarName][method] === 'function');
            }
            return true;
        }

        /**
         * 加载模块
         * @param {string} moduleName 模块名称
         * @param {string} modulePath 模块路径（可选，默认从 modules 配置或 modulesBaseUrl）
         * @returns {Promise<any>}
         */
        async loadModule(moduleName, modulePath = null) {
            if (this.loadedModules.has(moduleName)) {
                return this.loadedModules.get(moduleName);
            }
            if (this.loadingModules.has(moduleName)) {
                return this.loadingModules.get(moduleName);
            }

            // 先同步登记 loading，再 await 配置，避免路径启发式与属性加载并发双插 script
            const loadPromise = (async () => {
                await this.ensureModulesConfig();

                if (this.loadedModules.has(moduleName)) {
                    return this.loadedModules.get(moduleName);
                }

                const lookup = this.lookupModuleConfig(moduleName);
                const moduleConfig = lookup.moduleConfig;
                let path = modulePath;
                let globalVarName = this.getGlobalVarName(moduleName);

                if (moduleConfig) {
                    // 显式 null = 无全局变量校验（Worker / 纯 IIFE 部件脚本）
                    if (Object.prototype.hasOwnProperty.call(moduleConfig, 'globalVar')) {
                        globalVarName = moduleConfig.globalVar;
                    }
                }

                let paths = [];
                if (path) {
                    paths = [this.resolveStaticPath(path)];
                } else if (moduleConfig && Array.isArray(moduleConfig.paths) && moduleConfig.paths.length > 0) {
                    paths = moduleConfig.paths.map((entry) => this.resolveStaticPath(entry));
                } else if (moduleConfig && typeof moduleConfig.paths === 'string') {
                    paths = [this.resolveStaticPath(moduleConfig.paths)];
                } else {
                    const modulesBaseUrl = this.getModulesBaseUrl();
                    // 回退路径按调用名（api/account）拼，不按别名后的注册名
                    if (moduleName === 'api') {
                        paths = [modulesBaseUrl + '.js'];
                    } else if (moduleName === 'account') {
                        paths = [modulesBaseUrl + '-account.js'];
                    } else {
                        paths = [modulesBaseUrl + '-' + moduleName + '.js'];
                    }
                }

                const requiresFullGlobal = globalVarName === 'WelineApiModule'
                    || globalVarName === 'WelineAccountModule'
                    || globalVarName === 'WelineTokenStorage';
                if (this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                    const module = window[globalVarName];
                    this.loadedModules.set(moduleName, module);
                    return module;
                }

                const appendScript = (scriptPath, validateGlobal) => new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = this.getScriptUrl(scriptPath);
                    script.async = true;
                    // 同源不强制 CORS；跨域再设 anonymous
                    try {
                        const resolved = new URL(script.src, window.location.href);
                        if (resolved.origin !== window.location.origin) {
                            script.crossOrigin = 'anonymous';
                        }
                    } catch (_error) {
                    }

                    script.onload = () => {
                        if (validateGlobal && globalVarName && !this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                            reject(new Error('[Weline] ' + __('模块 %{1} 加载失败：未找到 %{2}', { 1: moduleName, 2: globalVarName })));
                            return;
                        }
                        resolve();
                    };
                    script.onerror = () => {
                        reject(new Error('[Weline] ' + __('模块 %{1} 加载失败：无法加载 %{2}', { 1: moduleName, 2: scriptPath })));
                    };
                    document.head.appendChild(script);
                });

                for (let index = 0; index < paths.length; index += 1) {
                    await appendScript(paths[index], index === paths.length - 1);
                }

                const module = globalVarName ? window[globalVarName] : true;
                this.loadedModules.set(moduleName, module);
                return module;
            })().finally(() => {
                this.loadingModules.delete(moduleName);
            });

            this.loadingModules.set(moduleName, loadPromise);
            return loadPromise;
        }

        /**
         * 获取模块的全局变量名
         * @param {string} moduleName 模块名称
         * @returns {string}
         */
        getGlobalVarName(moduleName) {
            const nameMap = {
                'api': 'WelineApiModule',
                'account': 'WelineAccountModule',
                'welineApi': 'WelineApiModule',
                'welineApiAccount': 'WelineAccountModule',
                'welineApiTokenStorage': 'WelineTokenStorage',
                'dom': 'WelineDomModule',
                'welineDom': 'WelineDomModule',
                'cart': 'WelineCartPurchaseActions',
                'wishlist': 'WelineWishlistModule',
                'customerAccount': 'WelineCustomerAccount',
                'customerLogout': 'WelineCustomerLogout',
                'miniCartExtras': 'WelineMiniCartExtras',
                'miniCartIcon': 'WelineMiniCartIcon',
                'comparePage': 'WelineComparePage',
                'compareShopper': 'WelineCompareShopper',
                'productReviews': 'WelineReviewProductWidget',
                'customerService': 'CustomerServiceWidget',
                'currency': 'WelineCurrency',
            };
            return nameMap[moduleName] || `Weline${moduleName.charAt(0).toUpperCase() + moduleName.slice(1)}Module`;
        }

        /**
         * 检查模块是否已加载
         * @param {string} moduleName 模块名称
         * @returns {boolean}
         */
        isModuleLoaded(moduleName) {
            return this.loadedModules.has(moduleName);
        }

        /**
         * 模块是否正在加载（含路径启发式已认领）
         * @param {string} moduleName
         * @returns {boolean}
         */
        isModuleLoading(moduleName) {
            return this.loadingModules.has(moduleName);
        }

        /**
         * 路径启发式认领模块：属性加载通道应跳过
         * @param {string|string[]} modules
         */
        markPathHeuristic(modules) {
            const list = Array.isArray(modules) ? modules : [modules];
            list.forEach((name) => {
                const key = String(name || '').trim();
                if (key) {
                    this.pathHeuristicModules.add(key);
                }
            });
        }

        /**
         * 是否已由路径启发式认领（优先级高于 data-weline-load）
         * @param {string} moduleName
         * @returns {boolean}
         */
        isPathHeuristic(moduleName) {
            return this.pathHeuristicModules.has(moduleName);
        }

        /**
         * 属性加载是否应跳过（已加载 / 加载中 / 路径启发式已认领）
         * @param {string} moduleName
         * @returns {boolean}
         */
        shouldSkipAttributeLoad(moduleName) {
            return this.isModuleLoaded(moduleName)
                || this.isModuleLoading(moduleName)
                || this.isPathHeuristic(moduleName);
        }
    }

    const moduleLoader = new ModuleLoader();

    /**
     * 翻译函数辅助方法（简化调用）
     * @param {string} key 翻译键
     * @param {Object} params 参数对象
     * @returns {string} 翻译后的文本
     */
    const __ = (key, params = {}) => {
        // 如果 Weline 对象已存在，使用其翻译方法
        if (window.Weline && window.Weline.i18n && window.Weline.i18n.translate) {
            return window.Weline.i18n.translate(key, params);
        }
        // 否则返回原始键（Weline 对象初始化后会设置字典）
        return key;
    };

    /**
     * Async 503 modal + simple wait-gift: same document URL only.
     * Issue token while waiting; after recovery reload same URL; redeem if url still matches.
     */
    (function installMaintenanceWaitGiftHandler() {
        let modalVisible = false;
        let recoveryTimer = 0;
        const WAIT_STORAGE_KEY = 'weline_mw_wait_gift';
        const endpoints = {
            issue: '/maintenance/frontend/wait-gift/issue',
            redeem: '/maintenance/frontend/wait-gift/redeem',
            wave: '/maintenance/frontend/wait-gift/wave',
        };

        function pageKey(href) {
            try {
                const url = new URL(href || window.location.href);
                return url.pathname + url.search;
            } catch (e) {
                return String(href || '');
            }
        }

        function readWaitGift() {
            try {
                const raw = sessionStorage.getItem(WAIT_STORAGE_KEY);
                if (!raw) {
                    return null;
                }
                const data = JSON.parse(raw);
                if (!data || !data.token || !data.url) {
                    return null;
                }
                return { token: String(data.token), url: String(data.url) };
            } catch (e) {
                return null;
            }
        }

        function storeWaitGift(token) {
            const value = String(token || '');
            try {
                if (!value) {
                    sessionStorage.removeItem(WAIT_STORAGE_KEY);
                    return;
                }
                sessionStorage.setItem(WAIT_STORAGE_KEY, JSON.stringify({
                    token: value,
                    url: pageKey(),
                }));
            } catch (e) {
                // ignore
            }
        }

        function clearWaitGift() {
            storeWaitGift('');
        }

        function postJson(url, body) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(body || {}),
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                return { ok: response.ok, status: response.status, data: data || {} };
            });
        }

        function applyCouponBestEffort(code) {
            const normalized = String(code || '').trim().toUpperCase();
            if (!normalized) {
                return Promise.resolve();
            }
            const expiresAt = Date.now() + (7 * 24 * 3600 * 1000);
            const meta = {
                source: 'maintenance_wait_gift',
                expires_at: expiresAt,
            };
            const publish = () => {
                if (window.WelineCart && typeof window.WelineCart.dispatchApplyCoupon === 'function') {
                    window.WelineCart.dispatchApplyCoupon(normalized, meta);
                    return Promise.resolve();
                }
                try {
                    localStorage.setItem('weline.cart.pending_coupon', JSON.stringify({
                        coupon_code: normalized,
                        source: meta.source,
                        issued_at: Date.now(),
                        expires_at: expiresAt,
                    }));
                } catch (e) {
                    // ignore
                }
                window.dispatchEvent(new CustomEvent('weline:cart:apply-coupon', {
                    detail: {
                        coupon_code: normalized,
                        source: meta.source,
                        expires_at: expiresAt,
                    },
                }));
                return Promise.resolve();
            };
            // Ensure万能购物车 cart.js is present to listen/apply.
            if (window.Weline && typeof window.Weline.preLoad === 'function') {
                return window.Weline.preLoad('cart').then(publish).catch(publish);
            }
            return publish();
        }

        function tryRedeemSameUrlToken() {
            // Maintenance document itself must not redeem.
            if (document.documentElement.getAttribute('data-w-wait-gift') === '1') {
                return;
            }
            const stored = readWaitGift();
            if (!stored) {
                return;
            }
            if (stored.url !== pageKey()) {
                clearWaitGift();
                return;
            }
            postJson(endpoints.redeem, { token: stored.token }).then((result) => {
                clearWaitGift();
                if (result.ok && result.data && result.data.success && result.data.coupon_code) {
                    return applyCouponBestEffort(String(result.data.coupon_code));
                }
                return null;
            }).catch(() => {
                clearWaitGift();
            });
        }

        function closeModal(leave) {
            const overlay = document.getElementById('weline-maintenance-wait-modal');
            if (overlay) {
                overlay.remove();
            }
            modalVisible = false;
            window.clearTimeout(recoveryTimer);
            recoveryTimer = 0;
            if (leave) {
                // Left the wait flow / will navigate away → cannot claim.
                clearWaitGift();
            }
        }

        function scheduleRecoveryReload() {
            window.clearTimeout(recoveryTimer);
            recoveryTimer = window.setTimeout(() => {
                recoveryTimer = 0;
                if (document.hidden) {
                    scheduleRecoveryReload();
                    return;
                }
                fetch(window.location.pathname + window.location.search, {
                    method: 'HEAD',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    redirect: 'manual',
                    headers: { Accept: 'text/html,*/*;q=0.8', 'X-Maintenance-Recovery-Check': '1' },
                }).then((response) => {
                    const recovered = response.type === 'opaqueredirect'
                        || (response.status !== 503 && response.status !== 0 && response.status !== 500
                            && response.status !== 502 && response.status !== 504);
                    if (recovered) {
                        window.location.reload();
                        return;
                    }
                    scheduleRecoveryReload();
                }).catch(() => scheduleRecoveryReload());
            }, 5000);
        }

        function issueOnCurrentPage() {
            const existing = readWaitGift();
            const body = existing && existing.url === pageKey() ? { token: existing.token } : {};
            return postJson(endpoints.issue, body).then((result) => {
                if (result.data && result.data.success && result.data.token) {
                    storeWaitGift(result.data.token);
                }
            }).catch(() => undefined);
        }

        function truthyFlag(value) {
            return value === true || value === 1 || value === '1' || value === 'true';
        }

        function collectMaintenanceBags(ctx) {
            const bags = [];
            const push = (value) => {
                if (value && typeof value === 'object') {
                    bags.push(value);
                }
            };
            push(ctx);
            push(ctx && ctx.data);
            push(ctx && ctx.payload);
            push(ctx && ctx.payload && ctx.payload.data);
            push(ctx && ctx.error && ctx.error.response);
            push(ctx && ctx.error && ctx.error.response && ctx.error.response.data);
            push(ctx && ctx.error && ctx.error.response && ctx.error.response.data && ctx.error.response.data.data);
            return bags;
        }

        function resolveMaintenanceMeta(ctx) {
            const bags = collectMaintenanceBags(ctx);
            let waitGift = false;
            let systemVersion = '';
            let themeVersion = '';
            let message = '';
            bags.forEach((bag) => {
                if (truthyFlag(bag.wait_gift_enabled)) {
                    waitGift = true;
                }
                if (!systemVersion && bag.system_version) {
                    systemVersion = String(bag.system_version);
                }
                if (!themeVersion && bag.theme_version) {
                    themeVersion = String(bag.theme_version);
                }
                if (!message && bag.message) {
                    message = String(bag.message);
                }
            });
            if (!message && ctx && ctx.error && ctx.error.message) {
                message = String(ctx.error.message);
            }
            if (!waitGift && /礼金|gift|compensation/i.test(message)) {
                waitGift = true;
            }
            return { waitGift, systemVersion, themeVersion, message };
        }

        function giftPanelHtml() {
            return [
                '<div data-w-mw-gift style="margin:0 0 16px;padding:12px 14px;text-align:left;background:#fff8e7;border:1px solid #f0c14b;border-radius:8px;">',
                '<div style="font-size:13px;font-weight:700;color:#0f1111;margin:0 0 6px;">' + __('维护补偿礼金') + '</div>',
                '<p style="margin:0 0 6px;line-height:1.55;font-size:13px;color:#0f1111;">'
                    + __('不过也要恭喜您——若您耐心等到升级完成，我们将发放补偿礼金。请先不要关闭。')
                    + '</p>',
                '<p style="margin:0;font-size:12px;color:#565959;">'
                    + __('恢复后约 10 分钟内自动领取，请勿关闭本页。')
                    + '</p>',
                '</div>',
            ].join('');
        }

        function applyGiftUi(card, waitBtn) {
            if (!card || card.querySelector('[data-w-mw-gift]')) {
                return;
            }
            const body = card.querySelector('[data-w-mw-body]');
            if (body) {
                body.textContent = __('非常抱歉，给您带来不便。');
            }
            const slot = card.querySelector('[data-w-mw-gift-slot]');
            if (slot) {
                slot.innerHTML = giftPanelHtml();
            }
            if (waitBtn) {
                waitBtn.disabled = false;
                waitBtn.textContent = __('耐心等待');
            }
        }

        function fetchWaveGiftEnabled() {
            return fetch(endpoints.wave, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                return !!(data && truthyFlag(data.wait_gift_enabled));
            }).catch(() => false);
        }

        function showModal(ctx) {
            if (modalVisible) {
                return;
            }
            modalVisible = true;
            const meta = resolveMaintenanceMeta(ctx || {});
            let waitGift = !!meta.waitGift;
            const systemVersion = String(meta.systemVersion || '');
            const themeVersion = String(meta.themeVersion || '');
            const overlay = document.createElement('div');
            overlay.id = 'weline-maintenance-wait-modal';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(15,17,17,0.55);padding:20px;';
            const card = document.createElement('div');
            card.style.cssText = 'max-width:460px;width:100%;overflow:hidden;background:#fff;border:1px solid #d5d9d9;border-radius:8px;box-shadow:0 4px 14px rgba(15,17,17,0.18);font-family:\"Amazon Ember\",Arial,\"PingFang SC\",\"Hiragino Sans GB\",\"Microsoft YaHei\",sans-serif;color:#0f1111;';
            card.innerHTML = [
                '<div style="background:linear-gradient(180deg,#131921 0%,#232f3e 100%);padding:14px 18px;color:#fff;text-align:left;">',
                '<div style="font-size:15px;font-weight:700;letter-spacing:0.01em;">Weline</div>',
                '<div style="width:3.2rem;height:0.35rem;margin-top:4px;border:2px solid #ff9900;border-top:0;border-radius:0 0 2rem 2rem;" aria-hidden="true"></div>',
                '</div>',
                '<div style="padding:22px 20px 20px;text-align:center;">',
                '<h2 style="margin:0 0 10px;font-size:1.25rem;font-weight:700;line-height:1.3;color:#0f1111;">'
                    + __('抱歉，网站正在升级维护')
                    + '</h2>',
                '<p data-w-mw-body style="margin:0 0 12px;line-height:1.6;color:#565959;font-size:14px;">'
                    + __('非常抱歉，给您带来不便。')
                    + '</p>',
                '<div data-w-mw-gift-slot>',
                waitGift
                    ? giftPanelHtml()
                    : '<p data-w-mw-plain style="margin:0 0 14px;line-height:1.6;color:#565959;font-size:14px;">'
                        + __('请稍候片刻，恢复后即可继续。')
                        + '</p>',
                '</div>',
                (systemVersion || themeVersion)
                    ? '<p style="margin:0 0 16px;font-size:12px;color:#565959;">SYS '
                        + systemVersion
                        + ' · Theme '
                        + themeVersion
                        + '</p>'
                    : '',
                '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">',
                '<button type="button" data-w-mw-leave style="min-height:2.4rem;border:1px solid #d5d9d9;border-radius:20px;padding:8px 18px;background:linear-gradient(180deg,#fff 0%,#f7fafa 100%);color:#0f1111;cursor:pointer;font-size:14px;font-family:inherit;box-shadow:0 1px 0 rgba(213,217,217,0.55);">'
                    + __('稍后再来')
                    + '</button>',
                '<button type="button" data-w-mw-wait style="min-height:2.4rem;border:1px solid #fcd200;border-radius:20px;padding:8px 18px;background:linear-gradient(180deg,#ffe566 0%,#ffd814 55%,#f0c14b 100%);color:#0f1111;cursor:pointer;font-size:14px;font-weight:500;font-family:inherit;box-shadow:0 2px 0 rgba(213,217,217,0.5);">'
                    + __('耐心等待')
                    + '</button>',
                '</div>',
                '</div>',
            ].join('');
            overlay.appendChild(card);
            document.body.appendChild(overlay);

            const waitBtn = card.querySelector('[data-w-mw-wait]');
            const leaveBtn = card.querySelector('[data-w-mw-leave]');
            if (waitBtn) {
                waitBtn.addEventListener('click', () => {
                    if (!waitGift) {
                        return;
                    }
                    issueOnCurrentPage().then(() => {
                        waitBtn.textContent = __('正在等待恢复…');
                        waitBtn.disabled = true;
                        scheduleRecoveryReload();
                    });
                });
            }
            if (leaveBtn) {
                leaveBtn.addEventListener('click', () => closeModal(true));
            }

            const startWaitFlow = () => {
                if (waitGift) {
                    issueOnCurrentPage().then(() => scheduleRecoveryReload());
                } else {
                    scheduleRecoveryReload();
                }
            };

            if (waitGift) {
                startWaitFlow();
                return;
            }

            // Payload may omit wait_gift_enabled (protocol wrapper). Reconcile from wave API.
            fetchWaveGiftEnabled().then((enabled) => {
                if (!enabled || !document.body.contains(overlay)) {
                    startWaitFlow();
                    return;
                }
                waitGift = true;
                applyGiftUi(card, waitBtn);
                startWaitFlow();
            });
        }

        runtimeConfig.api = runtimeConfig.api || {};
        if (typeof runtimeConfig.api.maintenanceHandler !== 'function') {
            runtimeConfig.api.maintenanceHandler = function maintenanceHandler(ctx) {
                showModal(ctx && typeof ctx === 'object' ? ctx : {});
            };
        }

        // After recovery reload on the same URL, claim once.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', tryRedeemSameUrlToken);
        } else {
            tryRedeemSameUrlToken();
        }
    })();

    /**
     * Weline 主对象
     */
    const Weline = {
        __initialized: true,
        __version: '1.0.0',
        config: runtimeConfig,
        loader: moduleLoader,

        /**
         * 预加载模块
         * @param {string|string[]} modules 模块名称或模块名称数组
         * @returns {Promise<void|void[]>} 返回Promise，如果传入数组则返回Promise数组
         * 
         * @example
         * // 预加载单个模块
         * await Weline.preLoad('account');
         * 
         * @example
         * // 预加载多个模块
         * await Weline.preLoad(['api', 'account']);
         * 
         * @example
         * // 预加载多个模块（不等待）
         * Weline.preLoad(['api', 'account']).catch(() => {});
         */
        preLoad: async (modules) => {
            if (!modules) {
                return Promise.resolve();
            }

            // 如果是字符串，转换为数组
            const moduleList = Array.isArray(modules) ? modules : [modules];

            // 加载所有模块
            const loadPromises = moduleList.map(moduleName => {
                return moduleLoader.loadModule(moduleName).catch((error) => {
                    console.warn(`[Weline] ${__('预加载模块 %{1} 失败', { 1: moduleName })}:`, error.message);
                    // 不抛出错误，允许其他模块继续加载
                    return null;
                });
            });

            // 如果只有一个模块，返回单个Promise结果
            if (moduleList.length === 1) {
                return loadPromises[0];
            }

            // 多个模块，返回Promise.all
            return Promise.all(loadPromises);
        },

        /**
         * Api 模块代理（stub；weline-api.js 加载后会替换为同步 ApiModule）
         */
        Api: {
            __fallback: true,
            call: async (provider, operation, params, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.call(provider, operation, params, options);
            },
            graph: async (graph, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.graph(graph, options);
            },
            stream: async (channel, params, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.stream(channel, params, options);
            },
            resource: async (provider, optionalMap) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.resource(provider, optionalMap);
            },
            /**
             * 发送请求
             */
            request: async (url, options) => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.request(url, options);
            },
            markCartActive: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.markCartActive();
            },
            markCartEmpty: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.markCartEmpty();
            },
            enableAutoRequests: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.enableAutoRequests();
            },
            disableAutoRequests: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.disableAutoRequests();
            },
            getClient: async () => {
                const ApiModule = await moduleLoader.loadModule('api');
                return ApiModule.getClient();
            },
        },

        /**
         * 按需加载模块（返回真实模块，如 WelineApiModule）。
         * 用法：Weline.load('api').then(api => api.resource('consent').status({}))
         */
        load: (moduleName, modulePath = null) => moduleLoader.loadModule(moduleName, modulePath),

        /**
         * 声明模块（主题规范：部件/布局必须 declare 或 data-weline-load，禁止直引 script）。
         * @param {string|string[]} moduleNames
         * @param {boolean|string|string[]} loadImmediatelyOrCustomPath
         * @param {string|string[]|null} customPath
         */
        declare: async (moduleNames, loadImmediatelyOrCustomPath = false, customPath = null) => {
            if (!moduleNames) {
                return;
            }
            let loadImmediately = false;
            let path = customPath;
            if (typeof loadImmediatelyOrCustomPath === 'boolean') {
                loadImmediately = loadImmediatelyOrCustomPath;
            } else if (
                typeof loadImmediatelyOrCustomPath === 'string'
                || Array.isArray(loadImmediatelyOrCustomPath)
            ) {
                path = loadImmediatelyOrCustomPath;
            }
            if (!loadImmediately) {
                return;
            }
            const list = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            await Promise.all(list.map((name) => moduleLoader.loadModule(name, path).catch(() => null)));
        },

        /**
         * i18n 国际化对象
         */
        i18n: {
            /**
             * 当前语言
             */
            currentLang: runtimeConfig.currentLang || 'zh_Hans_CN',

            /**
             * 翻译字典
             */
            dictionary: {},

            /**
             * 设置翻译字典
             * @param {Object} dict 翻译字典
             */
            setDictionary: (dict) => {
                Weline.i18n.dictionary = dict || {};
            },

            /**
             * 翻译文本
             * @param {string} key 翻译键
             * @param {Object} params 参数对象
             * @returns {string} 翻译后的文本
             */
            translate: (key, params = {}) => {
                let text = Weline.i18n.dictionary[key] || key;
                // 替换参数
                Object.keys(params).forEach(paramKey => {
                    text = text.replace(new RegExp(`%{${paramKey}}`, 'g'), params[paramKey]);
                });
                return text;
            },

            /**
             * 切换语言
             * @param {string} lang 语言代码
             * @returns {Promise<void>}
             */
            switchLang: async (lang) => {
                // 保存语言偏好
                persistLangPreference(lang);
                // 重新加载页面
                const url = new URL(window.location.href);
                url.searchParams.set('lang', lang);
                window.location.href = url.toString();
            },
        },

        /**
         * 货币和语言切换对象
         */
        Locale: {
            /**
             * 当前货币
             */
            currentCurrency: runtimeConfig.currentCurrency || 'CNY',

            /**
             * 当前语言
             */
            currentLang: runtimeConfig.currentLang || 'zh_Hans_CN',

            /**
             * 切换货币
             * @param {string} currency 货币代码
             * @returns {Promise<void>}
             */
            switchCurrency: async (currency) => {
                // 保存货币偏好
                persistCurrencyPreference(currency);
                // 重新加载页面
                const url = new URL(window.location.href);
                url.searchParams.set('currency', currency);
                window.location.href = url.toString();
            },

            /**
             * 切换语言
             * @param {string} lang 语言代码
             * @returns {Promise<void>}
             */
            switchLang: async (lang) => {
                return Weline.i18n.switchLang(lang);
            },
        },

        /**
         * Account 模块代理
         */
        Account: {
            // 前端用户（session）
            checkFrontendUserLogin: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.checkFrontendUserLogin();
            },
            frontendUserLogin: async (username, password, rememberDuration) => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendUserLogin(username, password, rememberDuration);
            },
            frontendUserLogout: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendUserLogout();
            },
            /**
             * FPC / auth redirect: one-shot header chrome refresh on w_auth=0|1.
             * Prefer widget data-weline-load="api,account" so this module is already present.
             */
            handleAuthRefreshSignal: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                if (AccountModule && typeof AccountModule.handleAuthRefreshSignal === 'function') {
                    return AccountModule.handleAuthRefreshSignal();
                }
                return { handled: false, reason: 'unsupported' };
            },
            getFrontendUser: () => {
                // 同步方法，如果未加载则返回null
                const globalVarName = moduleLoader.getGlobalVarName('account');
                if (window[globalVarName] && window[globalVarName]._instance) {
                    return window[globalVarName]._instance.getFrontendUser();
                }
                return null;
            },
            // API用户（token）
            checkFrontendApiLogin: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.checkFrontendApiLogin();
            },
            frontendApiLogin: async (username, password, expireTime) => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendApiLogin(username, password, expireTime);
            },
            frontendApiLogout: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendApiLogout();
            },
            getFrontendApiUser: () => {
                const globalVarName = moduleLoader.getGlobalVarName('account');
                if (window[globalVarName] && window[globalVarName]._instance) {
                    return window[globalVarName]._instance.getFrontendApiUser();
                }
                return null;
            },
            getFrontendApiToken: () => {
                // 直接从localStorage读取，不需要加载模块
                const apiTokenKey = runtimeConfig.account?.apiTokenKey || 'weline_api_access_token';
                return localStorage.getItem(apiTokenKey);
            },
            // 后端API用户（token）
            checkBackendApiLogin: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.checkBackendApiLogin();
            },
            backendApiLogin: async (username, password, expireTime) => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.backendApiLogin(username, password, expireTime);
            },
            backendApiLogout: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.backendApiLogout();
            },
            getBackendApiUser: () => {
                const globalVarName = moduleLoader.getGlobalVarName('account');
                if (window[globalVarName] && window[globalVarName]._instance) {
                    return window[globalVarName]._instance.getBackendApiUser();
                }
                return null;
            },
            getBackendApiToken: () => {
                const backendApiTokenKey = runtimeConfig.account?.backendApiTokenKey || 'weline_backend_api_token';
                return localStorage.getItem(backendApiTokenKey);
            },
        },
    };

    // 挂载到全局
    window.Weline = Weline;

    // 将翻译函数也挂载到 Weline 对象上，方便后续更新
    Weline.__ = __;

    /**
     * 根据页面路径自动预加载模块
     */
    /**
     * 路径启发式预加载（仅少数路由提前拉 api/account；与部件 data-weline-load 无关）
     * 首页等一般页：部件模块由下方 autoLoadDataAttributes 扫描属性加载。
     */
    (function autoPreLoadModules() {
        const pathname = window.location.pathname;
        const isDev = runtimeConfig.debug ||
            (typeof DEV !== 'undefined' && DEV) ||
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1' ||
            window.location.search.includes('debug=1');

        let modulesToLoad = null;
        let reason = '';

        // 登录/注册页面：预加载账户模块
        if (pathname.includes('/account/login') || pathname.includes('/account/register')) {
            modulesToLoad = 'account';
            reason = __('登录/注册页面');
        }
        // 用户中心/账户相关页面：预加载账户和API模块
        else if (pathname.includes('/account')) {
            modulesToLoad = ['api', 'account'];
            reason = __('用户中心/账户相关页面');
        }
        // API相关页面：预加载API模块
        else if (pathname.includes('/api') || pathname.includes('/rest/')) {
            modulesToLoad = 'api';
            reason = __('API相关页面');
        }
        // 购物车相关页面：预加载API模块（用于购物车功能）
        else if (pathname.includes('/cart') || pathname.includes('/checkout')) {
            modulesToLoad = 'api';
            reason = __('购物车相关页面');
        }

        // 如果有需要预加载的模块
        if (modulesToLoad) {
            const moduleList = Array.isArray(modulesToLoad) ? modulesToLoad : [modulesToLoad];
            // 路径启发式优先：先认领，属性通道见 shouldSkipAttributeLoad 后跳过
            moduleLoader.markPathHeuristic(moduleList);

            // 开发模式下输出提示
            if (isDev) {
                console.log(
                    `%c[Weline] ${__('路径启发式预加载')}`,
                    'color: #4CAF50; font-weight: bold; font-size: 12px;',
                    '\n',
                    `${__('页面')}: ${pathname}`,
                    `\n${__('原因')}: ${reason}`,
                    `\n${__('模块')}: ${moduleList.join(', ')}`,
                    `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                );
            }

            // 执行预加载
            Weline.preLoad(modulesToLoad)
                .then(() => {
                    if (isDev) {
                        console.log(
                            `%c[Weline] ${__('路径启发式预加载完成')}`,
                            'color: #2196F3; font-weight: bold; font-size: 12px;',
                            `\n${__('模块')}: ${moduleList.join(', ')}`,
                            `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                        );
                    }
                })
                .catch((error) => {
                    if (isDev) {
                        console.warn(
                            `%c[Weline] ${__('路径启发式预加载失败')}`,
                            'color: #FF9800; font-weight: bold; font-size: 12px;',
                            `\n${__('模块')}: ${moduleList.join(', ')}`,
                            `\n${__('错误')}: ${error.message}`,
                            `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                        );
                    }
                });
        } else if (isDev) {
            // 开发模式：说明本页不走路径启发式；部件仍可能有 data-weline-load
            console.log(
                `%c[Weline] ${__('路径启发式：本页无匹配路由（部件模块见 data-weline-load）')}`,
                'color: #9E9E9E; font-size: 11px;',
                `\n${__('页面')}: ${pathname}`
            );
        }
    })();

    /**
     * Dom 微核按需探测：仅当出现动态标记属性时 load('dom')。
     * 标记：data-weline-when / data-weline-on；显式 data-weline-load="dom" 仍走属性加载通道。
     * 探测层只负责加载，不实现 Observer 业务逻辑。
     */
    (function autoLoadDomCore() {
        const MARKER_SELECTOR = '[data-weline-when],[data-weline-on]';
        let ensurePromise = null;
        let discoveryObserver = null;

        function elementDeclaresDomLoad(el) {
            if (!el || el.nodeType !== 1 || typeof el.getAttribute !== 'function') {
                return false;
            }
            const raw = el.getAttribute('data-weline-load') || '';
            return /(^|,)\s*dom\s*(,|$)/i.test(raw);
        }

        function subtreeNeedsDom(root) {
            if (!root) {
                return false;
            }
            if (root.nodeType === 1) {
                if (root.matches && root.matches(MARKER_SELECTOR)) {
                    return true;
                }
                if (elementDeclaresDomLoad(root)) {
                    return true;
                }
            }
            if (typeof root.querySelector === 'function' && root.querySelector(MARKER_SELECTOR)) {
                return true;
            }
            if (typeof root.querySelectorAll === 'function') {
                const loads = root.querySelectorAll('[data-weline-load]');
                for (let i = 0; i < loads.length; i++) {
                    if (elementDeclaresDomLoad(loads[i])) {
                        return true;
                    }
                }
            }
            return false;
        }

        function stopDiscovery() {
            if (discoveryObserver) {
                discoveryObserver.disconnect();
                discoveryObserver = null;
            }
        }

        function ensureDom() {
            if (window.Weline && window.Weline.Dom && window.Weline.Dom.__full) {
                stopDiscovery();
                return Promise.resolve(window.Weline.Dom);
            }
            if (ensurePromise) {
                return ensurePromise;
            }
            ensurePromise = Promise.resolve()
                .then(() => (Weline.load ? Weline.load('dom') : moduleLoader.loadModule('dom')))
                .then((mod) => {
                    stopDiscovery();
                    return mod;
                })
                .catch((error) => {
                    ensurePromise = null;
                    console.warn(`[Weline] ${__('按需加载 Dom 微核失败')}:`, error && error.message ? error.message : error);
                    return null;
                });
            return ensurePromise;
        }

        document.addEventListener('weline:dom:ready', () => {
            stopDiscovery();
        }, { once: true });

        function scan(root) {
            if (subtreeNeedsDom(root || document)) {
                ensureDom();
                return true;
            }
            return false;
        }

        function startMarkerDiscovery() {
            if (typeof MutationObserver !== 'function') {
                return;
            }
            discoveryObserver = new MutationObserver((records) => {
                if (ensurePromise || (window.Weline && window.Weline.Dom && window.Weline.Dom.__full)) {
                    stopDiscovery();
                    return;
                }
                for (let i = 0; i < records.length; i++) {
                    const record = records[i];
                    if (record.type === 'attributes') {
                        const t = record.target;
                        if (t && t.nodeType === 1 && (
                            (t.matches && t.matches(MARKER_SELECTOR)) || elementDeclaresDomLoad(t)
                        )) {
                            ensureDom();
                            return;
                        }
                    }
                    const nodes = record.addedNodes;
                    for (let j = 0; j < nodes.length; j++) {
                        if (subtreeNeedsDom(nodes[j])) {
                            ensureDom();
                            return;
                        }
                    }
                }
            });
            const rootEl = document.documentElement || document.body;
            if (rootEl) {
                discoveryObserver.observe(rootEl, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['data-weline-when', 'data-weline-on', 'data-weline-load'],
                });
            }
        }

        const boot = () => {
            if (window.Weline && window.Weline.Dom && window.Weline.Dom.__full) {
                return;
            }
            if (scan(document)) {
                return;
            }
            // 首屏无标记：轻量发现晚到标记；模块一旦加载即 disconnect
            startMarkerDiscovery();
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, { once: true });
        } else {
            setTimeout(boot, 0);
        }
    })();

    /**
     * 部件/布局 data-weline-load / data-weline-declare（主题前端 JS 模块硬约束）
     */
    (function autoLoadDataAttributes() {
        const isDev = runtimeConfig.debug ||
            (typeof DEV !== 'undefined' && DEV) ||
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1' ||
            window.location.search.includes('debug=1');

        function splitModules(raw) {
            return String(raw || '')
                .split(',')
                .map((name) => name.trim())
                .filter((name) => name);
        }

        function process() {
            const declareNames = new Set();
            const loadNames = new Set();
            const skippedNames = new Set();

            document.querySelectorAll('[data-weline-declare]').forEach((el) => {
                const modules = splitModules(el.getAttribute('data-weline-declare'));
                modules.forEach((name) => declareNames.add(name));
                if (modules.length === 0) {
                    return;
                }
                Weline.declare(modules, false);
            });

            document.querySelectorAll('[data-weline-load]').forEach((el) => {
                const modules = splitModules(el.getAttribute('data-weline-load'));
                if (modules.length === 0) {
                    return;
                }
                // 路径启发式优先：已认领 / 已加载 / 加载中的模块属性通道不再发起加载
                const toLoad = modules.filter((name) => {
                    if (moduleLoader.shouldSkipAttributeLoad(name)) {
                        skippedNames.add(name);
                        return false;
                    }
                    loadNames.add(name);
                    return true;
                });
                if (toLoad.length === 0) {
                    return;
                }
                Weline.load
                    ? Promise.all(toLoad.map((name) => Weline.load(name).catch((error) => {
                        el.dispatchEvent(new CustomEvent('weline-modules-error', {
                            detail: { modules: toLoad, error: error, element: el },
                        }));
                        return null;
                    }))).then(() => {
                        el.dispatchEvent(new CustomEvent('weline-modules-loaded', {
                            detail: { modules: toLoad, element: el },
                        }));
                    })
                    : null;
            });

            if (isDev) {
                const loadList = Array.from(loadNames);
                const declareList = Array.from(declareNames);
                const skippedList = Array.from(skippedNames);
                if (loadList.length || declareList.length || skippedList.length) {
                    console.log(
                        `%c[Weline] ${__('部件属性模块加载')}`,
                        'color: #4CAF50; font-weight: bold; font-size: 12px;',
                        '\n',
                        `${__('页面')}: ${window.location.pathname}`,
                        loadList.length ? `\n${__('立即加载')}: ${loadList.join(', ')}` : '',
                        skippedList.length ? `\n${__('已跳过（路径启发式/已加载）')}: ${skippedList.join(', ')}` : '',
                        declareList.length ? `\n${__('仅声明')}: ${declareList.join(', ')}` : '',
                        `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                    );
                } else {
                    console.log(
                        `%c[Weline] ${__('本页 DOM 无 data-weline-load / data-weline-declare')}`,
                        'color: #9E9E9E; font-size: 11px;',
                        `\n${__('页面')}: ${window.location.pathname}`
                    );
                }
            }
        }

        const run = () => {
            moduleLoader.ensureModulesConfig().then(process);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run, { once: true });
        } else {
            setTimeout(run, 0);
        }
    })();

    /**
     * A server-issued Scope bootstrap is short-lived and one-time. Load the
     * official API module as soon as the complete document (including the
     * response-injected meta marker) is available, even on pages that do not
     * otherwise preload business APIs.
     */
    (function preLoadScopeBootstrapApi() {
        const preload = () => {
            if (!document.querySelector('meta[name="weline-worker-scope-bootstrap"]')) {
                return;
            }
            Weline.preLoad('api').then((apiModule) => {
                if (apiModule && typeof apiModule.bootstrapScope === 'function') {
                    return apiModule.bootstrapScope();
                }
                return null;
            }).catch(() => {
                // The API module emits a structured scope-bootstrap failure
                // event; the loader must not replace the page with a fallback.
            });
        };

        if (document.querySelector('meta[name="weline-worker-scope-bootstrap"]')) {
            preload();
        } else if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', preload, { once: true });
        } else {
            setTimeout(preload, 0);
        }
    })();

})(window, document);
