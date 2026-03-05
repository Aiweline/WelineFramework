/**
 * Weline Framework - 统一前端JS入口（轻量级，按需加载）
 * 
 * 重要说明：
 * 1. 必须通过 Weline.declare() 声明模块，方便PHP解析翻译词
 * 2. 只有声明且立即加载的模块才会立即加载，其他按需加载
 * 3. 所有异步操作不阻塞JS单线程
 * 4. 配置信息由 Theme.js 从 window.__WelineThemeConfig 获取（由PHP在head.phtml中初始化）
 * 
 * 使用方式：
 *   Weline.declare('api'); // 声明模块，按需加载
 *   Weline.declare('account', true); // 声明并立即加载
 *   Weline.declare('search', true, 'path', null, { loadOrder: 'last' }); // 声明并延迟立即加载（DOMContentLoaded后）
 *   Weline.load('api'); // 立即加载模块
 *   Weline.Api.request(url, options); // 使用模块（自动按需加载）
 *   Weline.Theme.switch('dark'); // 主题切换
 * 
 * 延迟立即加载：
 *   需要「最后再加载」的立即模块，可通过以下方式延迟到 DOMContentLoaded 后执行：
 *   1. 在 script 标签上加 data-load-order="last"（或 "defer"）
 *   2. 传入 options.loadOrder: 'last'（显式参数优先于 script 属性）
 */
(function (window, document) {
    'use strict';

    // 防止重复初始化
    if (window.Weline && window.Weline.__initialized) {
        return;
    }

    // 检查必要的配置（必须由PHP在head.phtml中初始化）
    const defaultConfig = {
        env: {
            WELINE_ENV: 'PROD',
            DEV: false,
            PROD: true
        },
        baseUrl: window.location.origin,
        currentLang: 'zh_Hans_CN',
        currentCurrency: 'CNY',
        debug: false,
        modulesBaseUrl: '',
        modulesConfigUrl: '',
        modulesConfig: null,
        api: {},
        account: {},
        site: {},
        theme: {},
        i18n: {
            currentLang: 'zh_Hans_CN',
            dictionary: {},
            apiUrl: '/i18n/frontend/word/get-translations'
        }
    };

    const runtimeConfig = {};

    function deepMerge(target, source) {
        if (!source || typeof source !== 'object') {
            return target;
        }
        Object.keys(source).forEach((key) => {
            const value = source[key];
            if (value && typeof value === 'object' && !Array.isArray(value)) {
                if (!target[key] || typeof target[key] !== 'object') {
                    target[key] = {};
                }
                deepMerge(target[key], value);
            } else {
                target[key] = value;
            }
        });
        return target;
    }

    let envApplied = false;
    function applyEnv(config) {
        if (envApplied) {
            return;
        }
        const env = config?.env || {};
        if (typeof env.WELINE_ENV !== 'undefined') {
            window.WELINE_ENV = env.WELINE_ENV;
        }
        if (typeof env.DEV !== 'undefined') {
            window.DEV = env.DEV;
        }
        if (typeof env.PROD !== 'undefined') {
            window.PROD = env.PROD;
        }
        envApplied = true;
    }

    function consumeBootstrapConfig() {
        if (window.__WelineThemeConfig) {
            const config = window.__WelineThemeConfig;
            delete window.__WelineThemeConfig;
            return config;
        }
        // 兼容旧的 window.WelineConfig，但引导用户迁移到新的配置方式
        if (window.WelineConfig) {
            console.warn('[Weline] window.WelineConfig 已废弃，请在 head 模板中设置 window.__WelineThemeConfig 或使用 Weline.Theme.applyConfig(...) 注入配置。');
            return window.WelineConfig;
        }
        return {};
    }

    function mergeConfig(config) {
        if (!config || typeof config !== 'object') {
            return;
        }
        if (config.env) {
            applyEnv(config);
        }
        deepMerge(runtimeConfig, config);
    }

    deepMerge(runtimeConfig, defaultConfig);
    mergeConfig(consumeBootstrapConfig());

    const urlLocalStorageKeys = {
        currency: ['weline_user_currency', 'api_doc_currency', 'WELINE_USER_CURRENCY'],
        locale: ['weline_user_lang', 'api_doc_locale', 'WELINE_USER_LANG']
    };

    const localePattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/;

    function safeLocalStorageGet(key) {
        try {
            return window.localStorage ? localStorage.getItem(key) : null;
        } catch (error) {
            return null;
        }
    }

    function isValidCurrency(value) {
        return /^[A-Z]{3}$/.test((value || '').toUpperCase());
    }

    function isValidLocale(value) {
        return localePattern.test(value || '');
    }

    function trimSlashes(value) {
        if (!value) {
            return '';
        }
        return value.replace(/^\/+|\/+$/g, '');
    }

    function getUrlConfig() {
        return runtimeConfig.url || {};
    }

    function getOrigin(overrideOrigin) {
        const config = getUrlConfig();
        const origin = overrideOrigin || config.origin || runtimeConfig.baseUrl || window.location.origin || '';
        return origin.replace(/\/+$/, '');
    }

    function detectCurrencyFallback() {
        const params = new URLSearchParams(window.location.search);
        const paramCurrency = params.get('currency');
        if (paramCurrency && isValidCurrency(paramCurrency)) {
            return paramCurrency.toUpperCase();
        }

        const pathParts = window.location.pathname.split('/').filter(Boolean);
        for (let i = 0; i < pathParts.length; i++) {
            const part = pathParts[i];
            if (isValidCurrency(part)) {
                return part.toUpperCase();
            }
        }

        for (const key of urlLocalStorageKeys.currency) {
            const value = safeLocalStorageGet(key);
            if (value && isValidCurrency(value)) {
                return value.toUpperCase();
            }
        }

        const config = getUrlConfig();
        const fallback = config.defaultCurrency || runtimeConfig.currentCurrency || 'CNY';
        return fallback.toUpperCase();
    }

    function detectLocaleFallback() {
        const params = new URLSearchParams(window.location.search);
        const paramLocale = params.get('locale');
        if (paramLocale && isValidLocale(paramLocale)) {
            return paramLocale;
        }

        const pathParts = window.location.pathname.split('/').filter(Boolean);
        for (let i = 0; i < pathParts.length; i++) {
            const part = pathParts[i];
            if (isValidLocale(part)) {
                return part;
            }
        }

        for (const key of urlLocalStorageKeys.locale) {
            const value = safeLocalStorageGet(key);
            if (value && isValidLocale(value)) {
                return value;
            }
        }

        const config = getUrlConfig();
        return config.defaultLocale || runtimeConfig.currentLang || 'zh_Hans_CN';
    }

    function stripApiSegments(path, { area, stripCurrency = true, stripLocale = true } = {}) {
        const normalizedArea = (area || '').toLowerCase();
        const segments = (path || '').split('/').filter(Boolean);
        const filtered = [];
        let areaRemoved = false;
        let currencyRemoved = false;
        let localeRemoved = false;

        for (let i = 0; i < segments.length; i++) {
            const segment = segments[i];
            const lower = segment.toLowerCase();
            if (!areaRemoved && normalizedArea && lower === normalizedArea) {
                areaRemoved = true;
                continue;
            }
            if (stripCurrency && !currencyRemoved && isValidCurrency(segment)) {
                currencyRemoved = true;
                continue;
            }
            if (stripLocale && !localeRemoved && isValidLocale(segment)) {
                localeRemoved = true;
                continue;
            }
            filtered.push(segment);
        }
        return filtered;
    }

    function ensureFrontendApiPath(path, options = {}) {
        const config = getUrlConfig();
        const apiArea = trimSlashes(options.apiArea || config.apiArea || 'api');
        const normalizedArea = apiArea.toLowerCase();
        const segments = stripApiSegments(path, { area: normalizedArea });
        const currency = options.currency && isValidCurrency(options.currency)
            ? options.currency.toUpperCase()
            : detectCurrencyFallback();
        const locale = options.locale && isValidLocale(options.locale)
            ? options.locale
            : detectLocaleFallback();
        return [apiArea, currency, locale, ...segments].join('/');
    }

    function ensureBackendApiPath(path, options = {}) {
        const config = getUrlConfig();
        const apiArea = trimSlashes(options.apiArea || config.apiAdminArea || 'api_admin');
        const normalizedArea = apiArea.toLowerCase();
        const segments = stripApiSegments(path, {
            area: normalizedArea,
            stripCurrency: false,
            stripLocale: false
        });
        return [apiArea, ...segments].join('/');
    }

    function resolvePathValue(path) {
        if (!path) {
            return '';
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        if (path.startsWith('//')) {
            return window.location.protocol + path;
        }
        if (!path.startsWith('/')) {
            return '/' + path.replace(/^\/+/, '');
        }
        return path;
    }

    const UrlHelper = {
        get config() {
            return getUrlConfig();
        },
        getOrigin: getOrigin,
        isValidCurrency,
        isValidLocale,
        detectCurrency: detectCurrencyFallback,
        detectLocale: detectLocaleFallback,
        ensureFrontendApiPath(path, options = {}) {
            return ensureFrontendApiPath(path, options);
        },
        ensureBackendApiPath(path, options = {}) {
            return ensureBackendApiPath(path, options);
        },
        resolve(path, options = {}) {
            if (!path) {
                return '';
            }
            if (/^https?:\/\//i.test(path)) {
                return path;
            }

            const resolvedPath = resolvePathValue(path);
            const type = (options.type || '').toLowerCase();
            const origin = getOrigin(options.origin);

            if (type === 'frontendapi' || type === 'frontend-api') {
                const clean = trimSlashes(resolvedPath);
                const ensured = ensureFrontendApiPath(clean, options);
                return origin + '/' + ensured;
            }

            if (type === 'backendapi' || type === 'backend-api') {
                const clean = trimSlashes(resolvedPath);
                const ensured = ensureBackendApiPath(clean, options);
                return origin + '/' + ensured;
            }

            return origin + resolvedPath;
        },
        frontend(path, options = {}) {
            return this.resolve(path, Object.assign({ type: 'frontend' }, options));
        },
        frontendApi(path, options = {}) {
            return this.resolve(path, Object.assign({ type: 'frontendApi' }, options));
        },
        backendApi(path, options = {}) {
            return this.resolve(path, Object.assign({ type: 'backendApi' }, options));
        },
    };

    if (typeof window.WELINE_ENV === 'undefined') {
        window.WELINE_ENV = runtimeConfig.env?.WELINE_ENV || (window.DEV ? 'DEV' : 'PROD');
    }
    if (typeof window.DEV === 'undefined') {
        window.DEV = window.WELINE_ENV === 'DEV';
    }
    if (typeof window.PROD === 'undefined') {
        window.PROD = window.WELINE_ENV === 'PROD';
    }
    const isDev = window.DEV || false;

    /**
     * 静态资源路径解析器
     * 根据开发/生产模式将 Weline_Module::path/to/file.js 转换为实际URL
     * 
     * 开发模式（DEV）：
     *   - 输入：Weline_Frontend::js/weline-api.js
     *   - 输出：/Weline/Frontend/view/statics/js/weline-api.js
     * 
     * 生产模式（PROD）：
     *   - 输入：Weline_Frontend::js/weline-api.js
     *   - 输出：/static/Weline/Frontend/js/weline-api.js
     *   - 注意：模块名从 Weline_Module 转换为 Weline/Module
     */
    class StaticResourceResolver {
        /**
         * 将模块路径转换为实际URL
         * @param {string} modulePath - 模块路径，格式：Weline_Module::path/to/file.js 或 /absolute/path
         * @returns {string} 实际URL
         */
        static resolve(modulePath) {
            // 已经是完整URL，直接返回
            if (modulePath.startsWith('http://') || modulePath.startsWith('https://')) {
                return modulePath;
            }

            // 已经是绝对路径，直接返回
            if (modulePath.startsWith('/')) {
                return modulePath;
            }

            // 解析模块路径格式：Weline_Module::path/to/file.js
            if (modulePath.includes('::')) {
                const parts = modulePath.split('::');
                if (parts.length === 2) {
                    const moduleName = parts[0].trim();
                    let filePath = parts[1].trim().replace(/^\/+/, '');

                    // 根据环境选择不同的路径格式
                    if (isDev) {
                        // 开发模式：/Weline/Module/view/statics/path/to/file.js（直接映射，不暴露app/code）
                        const modulePathParts = moduleName.split('_');
                        const vendorName = modulePathParts[0];
                        const moduleNamePart = modulePathParts.slice(1).join('_');
                        const normalizedModuleName = `${vendorName}/${moduleNamePart}`;
                        return `/${normalizedModuleName}/view/statics/${filePath}`;
                    } else {
                        // 生产模式：/static/Weline/Module/path/to/file.js
                        // 将 Weline_Module 转换为 Weline/Module
                        const modulePathParts = moduleName.split('_');
                        const vendorName = modulePathParts[0];
                        const moduleNamePart = modulePathParts.slice(1).join('_');
                        const normalizedModuleName = `${vendorName}/${moduleNamePart}`;
                        return `/static/${normalizedModuleName}/${filePath}`;
                    }
                }
            }

            // 无法解析，返回原路径
            return modulePath;
        }

        /**
         * 批量解析路径
         * @param {string[]} paths - 路径数组
         * @returns {string[]} 解析后的URL数组
         */
        static resolveAll(paths) {
            if (!Array.isArray(paths)) {
                return [this.resolve(paths)];
            }
            return paths.map(path => this.resolve(path));
        }
    }

    /**
     * 模块配置管理器（按需加载，不阻塞）
     */
    class ModuleConfigManager {
        constructor() {
            this.config = null;
            this.loaded = false;
            this.loading = null;
        }

        /**
         * 获取模块配置 URL（从全局配置读取）
         */
        getModulesConfigUrl() {
            // 从全局配置读取（由 Frontend 模块的 head 模板配置）
            if (runtimeConfig.modulesConfigUrl) {
                return runtimeConfig.modulesConfigUrl;
            }

            // 尝试从全局变量读取 (兼容性处理)
            if (window.modulesConfigUrl) {
                return window.modulesConfigUrl;
            }

            // 如果未配置，抛出错误提示
            throw new Error('[Weline.ModuleConfigManager] modulesConfigUrl 未配置，请在 Frontend 模块的 head 模板中配置 modulesConfigUrl');
        }

        async loadConfig() {
            if (this.loaded && this.config) {
                return this.config;
            }
            if (this.loading) {
                return this.loading;
            }

            this.loading = new Promise((resolve) => {
                // 优先使用已存在的配置
                if (runtimeConfig.modulesConfig) {
                    this.config = runtimeConfig.modulesConfig;
                    this.loaded = true;
                    this.loading = null;
                    resolve(this.config);
                    return;
                }

                if (window.WelineModulesConfig && window.WelineModulesConfig.modules) {
                    this.config = {
                        modules: window.WelineModulesConfig.modules || {},
                        moduleAliases: window.WelineModulesConfig.moduleAliases || {}
                    };
                    this.loaded = true;
                    this.loading = null;
                    resolve(this.config);
                    return;
                }

                // 异步加载模块配置文件（不阻塞）
                const script = document.createElement('script');
                // 自动获取模块配置 URL
                const modulesConfigUrl = this.getModulesConfigUrl();
                script.src = modulesConfigUrl;
                script.async = true; // 异步加载，不阻塞

                script.onload = () => {
                    setTimeout(() => {
                        if (window.WelineModulesConfig && window.WelineModulesConfig.modules) {
                            this.config = {
                                modules: window.WelineModulesConfig.modules || {},
                                moduleAliases: window.WelineModulesConfig.moduleAliases || {}
                            };
                        } else {
                            this.config = { modules: {}, moduleAliases: {} };
                            if (isDev) {
                                console.warn('[Weline] 加载模块配置失败：未找到 WelineModulesConfig');
                            }
                        }
                        this.loaded = true;
                        this.loading = null;
                        resolve(this.config);
                    }, 10);
                };

                script.onerror = () => {
                    this.config = { modules: {}, moduleAliases: {} };
                    this.loaded = true;
                    this.loading = null;
                    if (isDev) {
                        console.warn('[Weline] 加载模块配置失败，使用空配置');
                    }
                    resolve(this.config);
                };

                document.head.appendChild(script);
            });

            return this.loading;
        }

        async getModuleConfig(moduleName) {
            await this.loadConfig();
            if (this.config.moduleAliases && this.config.moduleAliases[moduleName]) {
                moduleName = this.config.moduleAliases[moduleName];
            }
            return this.config.modules && this.config.modules[moduleName] ? this.config.modules[moduleName] : null;
        }
    }

    const moduleConfigManager = new ModuleConfigManager();

    /**
     * 模块加载器（按需加载，不阻塞）
     */
    class ModuleLoader {
        constructor() {
            this.loadedModules = new Map();
            this.loadingModules = new Map();
        }

        /**
         * 获取模块基础 URL（从全局配置读取）
         */
        getModulesBaseUrl() {
            // 从全局配置读取（由 Frontend 模块的 head 模板配置）
            if (runtimeConfig.modulesBaseUrl) {
                return runtimeConfig.modulesBaseUrl;
            }

            // 如果未配置，抛出错误提示
            throw new Error('[Weline.ModuleLoader] modulesBaseUrl 未配置，请在 Frontend 模块的 head 模板中配置 modulesBaseUrl');
        }

        loadScript(url) {
            return new Promise((resolve, reject) => {
                const existingScript = document.querySelector(`script[src="${url}"]`);
                if (existingScript) {
                    resolve();
                    return;
                }

                const script = document.createElement('script');
                script.src = url;
                script.async = true;
                script.crossOrigin = 'anonymous';

                script.onload = () => resolve();
                script.onerror = () => reject(new Error(`[Weline] 脚本加载失败: ${url}`));

                document.head.appendChild(script);
            });
        }

        async loadWithFallback(paths, globalVarName = null) {
            if (!paths || paths.length === 0) {
                throw new Error('[Weline] 没有可用的路径');
            }

            let lastError = null;
            for (const path of paths) {
                try {
                    await this.loadScript(path);
                    if (globalVarName) {
                        await new Promise(resolve => setTimeout(resolve, 50));
                        if (window[globalVarName]) {
                            return;
                        }
                    } else {
                        return;
                    }
                } catch (error) {
                    lastError = error;
                    continue;
                }
            }
            throw lastError || new Error('[Weline] 所有路径加载失败');
        }

        async loadModule(moduleName, modulePath = null) {
            if (this.loadedModules.has(moduleName)) {
                return this.loadedModules.get(moduleName);
            }

            if (this.loadingModules.has(moduleName)) {
                return this.loadingModules.get(moduleName);
            }

            const loadPromise = (async () => {
                try {
                    const moduleConfig = await moduleConfigManager.getModuleConfig(moduleName);
                    let paths = [];
                    let globalVarName = null;

                    if (modulePath) {
                        paths = Array.isArray(modulePath) ? modulePath : [modulePath];
                        paths = StaticResourceResolver.resolveAll(paths);
                    } else if (moduleConfig && moduleConfig.paths) {
                        paths = StaticResourceResolver.resolveAll(moduleConfig.paths);
                        globalVarName = moduleConfig.globalVar || null;
                    } else {
                        // 默认路径（Weline内部模块）
                        // 自动获取模块基础 URL
                        const modulesBaseUrl = this.getModulesBaseUrl();

                        if (moduleName === 'api') {
                            paths = [`${modulesBaseUrl}.js`];
                            globalVarName = 'WelineApiModule';
                        } else if (moduleName === 'account') {
                            paths = [`${modulesBaseUrl}-account.js`];
                            globalVarName = 'WelineAccountModule';
                        } else {
                            paths = [`${modulesBaseUrl}-${moduleName}.js`];
                            globalVarName = this.getGlobalVarName(moduleName);
                        }
                    }

                    if (!globalVarName) {
                        globalVarName = moduleConfig?.globalVar || this.getGlobalVarName(moduleName);
                    }

                    // 检查是否已加载
                    if (globalVarName && window[globalVarName] && window[globalVarName].__full !== false) {
                        const module = window[globalVarName];
                        this.loadedModules.set(moduleName, module);
                        this.loadingModules.delete(moduleName);
                        return module;
                    }

                    // 加载脚本
                    await this.loadWithFallback(paths, globalVarName);

                    // 验证加载结果
                    if (globalVarName) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        if (window[globalVarName]) {
                            const module = window[globalVarName];
                            this.loadedModules.set(moduleName, module);
                            this.loadingModules.delete(moduleName);
                            return module;
                        } else {
                            throw new Error(`[Weline] 模块 ${moduleName} 加载失败：未找到全局变量 ${globalVarName}`);
                        }
                    } else {
                        this.loadedModules.set(moduleName, true);
                        this.loadingModules.delete(moduleName);
                        return true;
                    }
                } catch (error) {
                    this.loadingModules.delete(moduleName);
                    throw error;
                }
            })();

            this.loadingModules.set(moduleName, loadPromise);
            return loadPromise;
        }

        getGlobalVarName(moduleName) {
            const nameMap = {
                'api': 'WelineApiModule',
                'account': 'WelineAccountModule',
            };
            return nameMap[moduleName] || `Weline${moduleName.charAt(0).toUpperCase() + moduleName.slice(1)}Module`;
        }

        isModuleLoaded(moduleName) {
            return this.loadedModules.has(moduleName);
        }
    }

    const moduleLoader = new ModuleLoader();

    function setupDeprecatedConfigAlias() {
        if (Object.getOwnPropertyDescriptor(window, 'WelineConfig')) {
            return;
        }
        let warnedGet = false;
        let warnedSet = false;
        Object.defineProperty(window, 'WelineConfig', {
            configurable: true,
            get() {
                if (!warnedGet && window.console) {
                    console.warn('[Weline] window.WelineConfig 已废弃，请改用 Weline.config 或 Weline.Theme.applyConfig(...) 注入配置。');
                    warnedGet = true;
                }
                return runtimeConfig;
            },
            set(value) {
                if (!warnedSet && window.console) {
                    console.warn('[Weline] window.WelineConfig 设置方式已废弃，请改用 Weline.Theme.applyConfig(...) 注入配置。');
                    warnedSet = true;
                }
                mergeConfig(value);
            }
        });
    }

    /**
     * 模块声明管理器（必须使用，方便PHP解析翻译词）
     */
    class ModuleDeclarer {
        constructor() {
            this.declaredModules = new Map();
            this.proxies = new Map();
        }

        /**
         * 声明模块（必须使用，方便PHP解析翻译词）
         * @param {string|string[]} moduleNames 模块名称或模块名称数组
         * @param {string|string[]} customPath 自定义路径（可选）
         */
        declare(moduleNames, customPath = null) {
            const moduleList = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            moduleList.forEach(moduleName => {
                if (!this.declaredModules.has(moduleName)) {
                    this.declaredModules.set(moduleName, {
                        name: moduleName,
                        customPath: customPath,
                        loaded: false,
                        promise: null
                    });
                }
            });
        }

        isDeclared(moduleName) {
            return this.declaredModules.has(moduleName);
        }

        async loadOnDemand(moduleName) {
            if (!this.declaredModules.has(moduleName)) {
                return null;
            }

            const moduleInfo = this.declaredModules.get(moduleName);
            if (moduleInfo.loaded) {
                return moduleInfo.module;
            }
            if (moduleInfo.promise) {
                return moduleInfo.promise;
            }

            moduleInfo.promise = (async () => {
                try {
                    const module = await moduleLoader.loadModule(moduleName, moduleInfo.customPath);
                    moduleInfo.loaded = true;
                    moduleInfo.module = module;
                    return module;
                } catch (error) {
                    moduleInfo.promise = null;
                    throw error;
                }
            })();

            return moduleInfo.promise;
        }

        createProxy(globalVarName, moduleName) {
            if (this.proxies.has(globalVarName)) {
                return this.proxies.get(globalVarName);
            }

            let loadingPromise = null;
            let loaded = false;

            const proxy = new Proxy({}, {
                get: (target, prop) => {
                    if (window[globalVarName] && typeof window[globalVarName] === 'object' && window[globalVarName] !== null) {
                        const realObj = window[globalVarName];
                        if (realObj && typeof realObj[prop] !== 'undefined') {
                            return realObj[prop];
                        }
                    }

                    if (loadingPromise) {
                        return loadingPromise.then(() => {
                            const realObj = window[globalVarName];
                            return realObj ? realObj[prop] : undefined;
                        });
                    }

                    if (loaded) {
                        return undefined;
                    }

                    loadingPromise = this.loadOnDemand(moduleName).then(() => {
                        loaded = true;
                        const realObj = window[globalVarName];
                        if (realObj) {
                            Object.assign(target, realObj);
                            return realObj[prop];
                        }
                        return undefined;
                    }).catch(error => {
                        loadingPromise = null;
                        if (isDev) {
                            console.error(`[Weline] 模块 ${moduleName} 按需加载失败:`, error);
                        }
                        throw error;
                    });

                    return loadingPromise;
                },
                set: (target, prop, value) => {
                    if (window[globalVarName]) {
                        window[globalVarName][prop] = value;
                        return true;
                    }
                    target[prop] = value;
                    return true;
                },
                has: (target, prop) => {
                    if (window[globalVarName]) {
                        return prop in window[globalVarName];
                    }
                    return prop in target;
                },
                ownKeys: (target) => {
                    if (window[globalVarName]) {
                        return Object.keys(window[globalVarName]);
                    }
                    return Object.keys(target);
                },
                construct: (target, args) => {
                    if (window[globalVarName] && typeof window[globalVarName] === 'function') {
                        return new window[globalVarName](...args);
                    }
                    return this.loadOnDemand(moduleName).then(() => {
                        if (window[globalVarName] && typeof window[globalVarName] === 'function') {
                            return new window[globalVarName](...args);
                        }
                        throw new Error(`[Weline] ${globalVarName} 不是一个构造函数`);
                    });
                },
                apply: (target, thisArg, args) => {
                    if (window[globalVarName] && typeof window[globalVarName] === 'function') {
                        return window[globalVarName].apply(thisArg, args);
                    }
                    return this.loadOnDemand(moduleName).then(() => {
                        if (window[globalVarName] && typeof window[globalVarName] === 'function') {
                            return window[globalVarName].apply(thisArg, args);
                        }
                        throw new Error(`[Weline] ${globalVarName} 不是一个函数`);
                    });
                }
            });

            this.proxies.set(globalVarName, proxy);
            return proxy;
        }

        async setupProxies() {
            for (const [moduleName, moduleInfo] of this.declaredModules) {
                const moduleConfig = await moduleConfigManager.getModuleConfig(moduleName);
                const globalVarName = moduleConfig?.globalVar || moduleLoader.getGlobalVarName(moduleName);

                if (!window[globalVarName]) {
                    const proxy = this.createProxy(globalVarName, moduleName);
                    Object.defineProperty(window, globalVarName, {
                        value: proxy,
                        writable: true,
                        configurable: true
                    });
                }
            }
        }
    }

    const moduleDeclarer = new ModuleDeclarer();

    /**
     * 延迟立即加载队列
     * 用于存储需要在 DOMContentLoaded 后才执行的「立即加载」声明
     * 每项格式：{ moduleNames, actualCustomPath, callback }
     */
    const immediateLoadDeferredQueue = [];

    /**
     * 执行延迟立即加载队列
     * 并行启动所有队列项的加载（不顺序 await）
     */
    function flushImmediateLoadDeferredQueue() {
        // 取出当前队列快照并清空队列
        const snapshot = immediateLoadDeferredQueue.splice(0, immediateLoadDeferredQueue.length);
        if (snapshot.length === 0) {
            return;
        }

        // 并行启动所有队列项的加载
        snapshot.forEach(item => {
            const { moduleNames, actualCustomPath, callback } = item;
            const moduleList = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            
            Promise.all(moduleList.map(moduleName => {
                return moduleLoader.loadModule(moduleName, actualCustomPath).catch(error => {
                    if (isDev) {
                        console.warn(`[Weline] 延迟立即加载模块 ${moduleName} 失败:`, error.message);
                    }
                    throw error;
                });
            })).then(() => {
                if (callback) {
                    try {
                        callback();
                    } catch (error) {
                        if (isDev) {
                            console.error('[Weline] 延迟加载模块回调执行失败:', error);
                        }
                    }
                }
            }).catch(() => {
                // 静默失败，继续处理其他队列项
            });
        });
    }

    /**
     * Weline 主对象（轻量级，只包含基础功能）
     */
    const Weline = {
        __initialized: true,
        __version: '1.0.0',
        config: runtimeConfig,
        Url: UrlHelper,
        getConfig: () => runtimeConfig,
        applyConfig: mergeConfig,
        loader: moduleLoader,
        configManager: moduleConfigManager,
        staticResourceResolver: StaticResourceResolver, // 静态资源解析器
        declarer: moduleDeclarer,

        /**
         * 加载JS模块（立即加载）
         * @param {string|string[]} moduleNames 模块名称或模块名称数组
         * @param {string|string[]|Function} customPathOrCallback 自定义路径或回调函数
         * @param {Function} callback 回调函数
         * @returns {Promise<any|any[]>}
         */
        load: async (moduleNames, customPathOrCallback = null, callback = null) => {
            if (!moduleNames) {
                return Promise.resolve();
            }

            let actualCustomPath = null;
            let actualCallback = null;

            if (typeof customPathOrCallback === 'function') {
                actualCallback = customPathOrCallback;
            } else {
                actualCustomPath = customPathOrCallback;
                actualCallback = callback;
            }

            const moduleList = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            const loadPromises = moduleList.map(moduleName => {
                return moduleLoader.loadModule(moduleName, actualCustomPath).catch((error) => {
                    if (isDev) {
                        console.warn(`[Weline] 加载模块 ${moduleName} 失败:`, error.message);
                    }
                    throw error;
                });
            });

            const loadResult = moduleList.length === 1 ? loadPromises[0] : Promise.all(loadPromises);

            if (actualCallback) {
                loadResult.then(() => {
                    try {
                        actualCallback();
                    } catch (error) {
                        if (isDev) {
                            console.error('[Weline] 模块加载回调执行失败:', error);
                        }
                    }
                }).catch(() => {
                    // 静默失败
                });
            }

            return loadResult;
        },

        /**
         * 声明模块（必须使用，方便PHP解析翻译词）
         * @param {string|string[]} moduleNames 模块名称或模块名称数组
         * @param {boolean|string|string[]|Function} loadImmediatelyOrCustomPathOrCallback 是否立即加载、自定义路径或回调函数
         * @param {string|string[]|Function|boolean} customPathOrCallbackOrLoadImmediately 自定义路径、回调函数或是否立即加载
         * @param {Function|boolean|string|string[]} callbackOrLoadImmediatelyOrCustomPath 回调函数、是否立即加载或自定义路径
         * @param {Object} options 可选配置，如 { loadOrder: 'last' } 表示延迟到 DOMContentLoaded 后加载
         * @returns {Promise<void>}
         */
        declare: async (moduleNames, loadImmediatelyOrCustomPathOrCallback = false, customPathOrCallbackOrLoadImmediately = null, callbackOrLoadImmediatelyOrCustomPath = null, options = null) => {
            if (!moduleNames) {
                return;
            }

            let loadImmediately = false;
            let actualCustomPath = null;
            let callback = null;

            // 提取回调函数
            const args = [loadImmediatelyOrCustomPathOrCallback, customPathOrCallbackOrLoadImmediately, callbackOrLoadImmediatelyOrCustomPath];
            for (let i = 0; i < args.length; i++) {
                if (typeof args[i] === 'function') {
                    callback = args[i];
                    break;
                }
            }

            // 解析其他参数
            if (typeof loadImmediatelyOrCustomPathOrCallback === 'boolean') {
                loadImmediately = loadImmediatelyOrCustomPathOrCallback;
                if (typeof customPathOrCallbackOrLoadImmediately === 'string' || Array.isArray(customPathOrCallbackOrLoadImmediately)) {
                    actualCustomPath = customPathOrCallbackOrLoadImmediately;
                }
            } else if (typeof loadImmediatelyOrCustomPathOrCallback === 'string' || Array.isArray(loadImmediatelyOrCustomPathOrCallback)) {
                actualCustomPath = loadImmediatelyOrCustomPathOrCallback;
                if (typeof customPathOrCallbackOrLoadImmediately === 'boolean') {
                    loadImmediately = customPathOrCallbackOrLoadImmediately;
                }
            }

            // 声明模块（必须，方便PHP解析）
            moduleDeclarer.declare(moduleNames, actualCustomPath);

            // 异步设置代理（不阻塞）
            setTimeout(async () => {
                await moduleDeclarer.setupProxies();
            }, 0);

            // 如果设置了立即加载，则异步加载（不阻塞）
            if (loadImmediately) {
                // 判断是否需要延迟加载：显式参数优先于 script 标签属性
                let shouldDefer = false;
                
                // 1. 先检查显式参数 options.loadOrder
                if (options && typeof options === 'object') {
                    const loadOrder = options.loadOrder;
                    if (loadOrder === 'last' || loadOrder === 'defer') {
                        shouldDefer = true;
                    }
                }
                
                // 2. 若未通过显式参数指定，再检查当前 script 标签的 data-load-order 属性
                if (!shouldDefer && document.currentScript) {
                    const scriptLoadOrder = document.currentScript.getAttribute('data-load-order');
                    if (scriptLoadOrder === 'last' || scriptLoadOrder === 'defer') {
                        shouldDefer = true;
                    }
                }

                if (shouldDefer) {
                    // 延迟加载：入队，等待 DOMContentLoaded 后执行
                    immediateLoadDeferredQueue.push({
                        moduleNames: moduleNames,
                        actualCustomPath: actualCustomPath,
                        callback: callback
                    });
                    return;
                }

                // 立即加载（不延迟）
                const moduleList = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
                Promise.all(moduleList.map(moduleName => {
                    return moduleLoader.loadModule(moduleName, actualCustomPath).catch(error => {
                        if (isDev) {
                            console.warn(`[Weline] 声明时立即加载模块 ${moduleName} 失败:`, error.message);
                        }
                        throw error;
                    });
                })).then(() => {
                    if (callback) {
                        try {
                            callback();
                        } catch (error) {
                            if (isDev) {
                                console.error('[Weline] 模块加载回调执行失败:', error);
                            }
                        }
                    }
                }).catch(() => {
                    // 静默失败
                });
            }
        },

        /**
         * 使用模块（确保已加载，如果未加载则立即加载）
         * @param {string} moduleName 模块名称
         * @returns {Promise<any>}
         */
        use: async (moduleName) => {
            if (moduleDeclarer.isDeclared(moduleName)) {
                return await moduleDeclarer.loadOnDemand(moduleName);
            }
            return await moduleLoader.loadModule(moduleName);
        },

        /**
         * Api 模块代理（按需加载）
         */
        Api: {
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

        Query: {
            request: async (provider, operation, params = {}, options = {}) => {
                const area = options.area || 'frontend';
                const queryConfig = runtimeConfig.query || {};
                const frontendUrl = queryConfig.frontendUrl || '/api/framework/query';
                const backendUrl = queryConfig.backendUrl || '/api_admin/framework/query';
                const endpoint = area === 'backend' ? backendUrl : frontendUrl;
                const response = await Weline.Api.request(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        provider: provider,
                        operation: operation,
                        params: params
                    })
                });
                if (!response || response.code !== 200) {
                    throw new Error((response && response.msg) ? response.msg : __('查询失败'));
                }
                return response.data;
            }
        },

        /**
         * i18n 国际化对象（代理到 i18n 模块）
         */
        i18n: (function () {
            // 创建一个代理对象，按需加载 i18n 模块
            const i18nProxy = {
                get currentLang() {
                    // 优先从 i18n 模块获取
                    if (window.WelineI18n && window.WelineI18n.currentLang) {
                        return window.WelineI18n.currentLang;
                    }
                    // 降级：从配置获取
                    const config = runtimeConfig.i18n || {};
                    return runtimeConfig.currentLang || config.currentLang || 'zh_Hans_CN';
                },
                get dictionary() {
                    // 优先从 i18n 模块获取
                    if (window.WelineI18n && window.WelineI18n.dictionary) {
                        return window.WelineI18n.dictionary;
                    }
                    // 降级：从配置获取
                    return runtimeConfig.i18n?.dictionary || {};
                },
                get apiUrl() {
                    // 优先从 i18n 模块获取
                    if (window.WelineI18n && window.WelineI18n.apiUrl) {
                        return window.WelineI18n.apiUrl;
                    }
                    // 降级：从配置获取
                    const area = window.location.pathname?.indexOf('/admin') === 0 || window.location.pathname?.indexOf('/backend') === 0 ? 'backend' : 'frontend';
                    return runtimeConfig.i18n?.apiUrl || `/i18n/${area}/word/get-translations`;
                },
                setDictionary: async (dict) => {
                    // 优先使用 i18n 模块的方法
                    if (window.WelineI18n && typeof window.WelineI18n.setDictionary === 'function') {
                        return window.WelineI18n.setDictionary(dict);
                    }
                    // 降级：直接设置到配置
                    if (!runtimeConfig.i18n) {
                        runtimeConfig.i18n = {};
                    }
                    runtimeConfig.i18n.dictionary = dict || {};
                },
                translate: async (key, params = {}) => {
                    // 优先使用 i18n 模块的方法
                    if (window.WelineI18n && typeof window.WelineI18n.translate === 'function') {
                        return window.WelineI18n.translate(key, params);
                    }
                    // 降级：从配置字典翻译
                    const dict = i18nProxy.dictionary;
                    let text = dict[key] || key;
                    Object.keys(params).forEach(paramKey => {
                        text = text.replace(new RegExp(`%{${paramKey}}`, 'g'), params[paramKey]);
                    });
                    return text;
                },
                switchLang: async (lang) => {
                    // 优先使用 i18n 模块的方法
                    if (window.WelineI18n && typeof window.WelineI18n.switchLang === 'function') {
                        return window.WelineI18n.switchLang(lang);
                    }
                    // 降级：使用基本 URL 参数切换
                    localStorage.setItem('weline_user_lang', lang);
                    const url = new URL(window.location.href);
                    url.searchParams.set('lang', lang);
                    window.location.href = url.toString();
                },
                loadDictionary: async function () {
                    // 优先使用 i18n 模块的方法
                    if (window.WelineI18n && typeof window.WelineI18n.loadDictionary === 'function') {
                        return window.WelineI18n.loadDictionary();
                    }
                    // 降级：返回当前字典
                    return i18nProxy.dictionary;
                },
            };
            return i18nProxy;
        })(),

        /**
         * 货币和语言切换对象（代理到各自模块）
         */
        Locale: {
            get currentCurrency() {
                // 优先从 currency 模块获取
                if (window.WelineCurrency && typeof window.WelineCurrency.getCurrentCurrency === 'function') {
                    return window.WelineCurrency.getCurrentCurrency();
                }
                // 降级：从配置获取
                return runtimeConfig.currentCurrency || 'CNY';
            },
            get currentLang() {
                // 优先从 i18n 模块获取
                if (window.WelineI18n && typeof window.WelineI18n.getCurrentLang === 'function') {
                    return window.WelineI18n.getCurrentLang();
                }
                // 降级：从配置获取
                return runtimeConfig.currentLang || 'zh_Hans_CN';
            },
            switchCurrency: async (currency) => {
                // 优先使用 currency 模块的方法
                if (window.WelineCurrency && typeof window.WelineCurrency.switchCurrency === 'function') {
                    return window.WelineCurrency.switchCurrency(currency);
                }
                // 降级：使用基本 URL 参数切换
                localStorage.setItem('weline_user_currency', currency);
                const url = new URL(window.location.href);
                url.searchParams.set('currency', currency);
                window.location.href = url.toString();
            },
            switchLang: async (lang) => {
                return Weline.i18n.switchLang(lang);
            },
        },

        /**
         * Account 模块代理（按需加载）
         */
        Account: {
            checkFrontendLogin: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.checkFrontendLogin();
            },
            frontendLogin: async (username, password, rememberDuration) => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendLogin(username, password, rememberDuration);
            },
            frontendLogout: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.frontendLogout();
            },
            getFrontendUser: () => {
                const globalVarName = moduleLoader.getGlobalVarName('account');
                if (window[globalVarName] && window[globalVarName]._instance) {
                    return window[globalVarName]._instance.getFrontendUser();
                }
                return null;
            },
            checkApiLogin: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.checkApiLogin();
            },
            apiLogin: async (username, password, expireTime) => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.apiLogin(username, password, expireTime);
            },
            apiLogout: async () => {
                const AccountModule = await moduleLoader.loadModule('account');
                return AccountModule.apiLogout();
            },
            getApiUser: () => {
                const globalVarName = moduleLoader.getGlobalVarName('account');
                if (window[globalVarName] && window[globalVarName]._instance) {
                    return window[globalVarName]._instance.getApiUser();
                }
                return null;
            },
            getApiToken: () => {
                const apiTokenKey = runtimeConfig.account?.apiTokenKey || 'weline_api_access_token';
                return localStorage.getItem(apiTokenKey);
            },
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

        /**
         * 主题管理器（前端专用）
         */
        Theme: {
            current: 'light',
            themes: runtimeConfig.theme?.available || ['light'], // 从配置中获取可用主题列表

            switch: function (theme) {
                // 初始化时从配置中更新主题列表
                if (!this.themes || this.themes.length === 0 || this.themes.length === 1 && this.themes[0] === 'light') {
                    this.themes = runtimeConfig.theme?.available || ['light'];
                }

                if (!this.themes.includes(theme)) {
                    if (isDev) {
                        console.warn(`[Theme] 未知主题: ${theme}，可用主题: ${this.themes.join(', ')}`);
                    }
                    return;
                }

                document.documentElement.setAttribute('data-theme', theme);
                localStorage.setItem('weline-theme', theme);
                this.current = theme;

                const event = new CustomEvent('themechange', {
                    detail: { theme: theme }
                });
                document.dispatchEvent(event);
            },

            init: function () {
                // 确保主题列表已初始化
                if (!this.themes || this.themes.length === 0) {
                    this.themes = runtimeConfig.theme?.available || ['light'];
                }

                const savedTheme = localStorage.getItem('weline-theme');
                let systemTheme = 'light';
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    systemTheme = 'dark';
                }

                // 检查保存的主题是否在可用列表中
                let theme = savedTheme;
                if (theme && !this.themes.includes(theme)) {
                    // 如果保存的主题不可用，清除它并使用系统主题
                    localStorage.removeItem('weline-theme');
                    theme = null;
                }

                // 如果没有保存的主题，使用系统主题，但要确保系统主题在可用列表中
                if (!theme) {
                    theme = this.themes.includes(systemTheme) ? systemTheme : (this.themes[0] || 'light');
                }

                this.switch(theme);

                if (window.matchMedia) {
                    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                        if (!localStorage.getItem('weline-theme')) {
                            // 只有在没有手动保存主题时才跟随系统主题，但要确保系统主题在可用列表中
                            const newSystemTheme = e.matches ? 'dark' : 'light';
                            const validTheme = this.themes.includes(newSystemTheme) ? newSystemTheme : (this.themes[0] || 'light');
                            this.switch(validTheme);
                        }
                    });
                }
            },

            getCurrent: function () {
                return this.current;
            },

            isDark: function () {
                return this.current === 'dark';
            },

            applyConfig: function (config) {
                mergeConfig(config);
            },

            bootstrap: function (config) {
                mergeConfig(config);
                return this;
            }
        },

        /**
         * 主题工具函数
         */
        ThemeUtils: {
            getVariable: function (varName) {
                return getComputedStyle(document.documentElement)
                    .getPropertyValue(varName).trim();
            },
            setVariable: function (varName, value) {
                document.documentElement.style.setProperty(varName, value);
            }
        },

        /**
         * 通用中间件注册机制
         * 
         * 设计原则：
         * - Theme 只提供「机制」，不关心具体业务模块
         * - 中间件通过命名空间前缀区分归属，如：
         *   - 'WeShop_Product::price::shipping'
         *   - 'Weline_Checkout::totals::tax'
         * - 具体模块在自己加载时，按前缀从这里取出自己的中间件
         */
        Middleware: (function () {
            const _entries = [];

            return {
                /**
                 * 注册中间件
                 * 
                 * @param {string} name - 唯一名称（建议带命名空间前缀）
                 * @param {Function|Object} handler - 中间件实现（函数或描述对象，如WASM接口）
                 * @param {Object} options - 附加信息：{ priority, source, name, meta }
                 */
                register: function (name, handler, options = {}) {
                    if (typeof name !== 'string' || !name) {
                        console.error('[Weline.Middleware] name 必须是非空字符串');
                        return;
                    }
                    if (typeof handler !== 'function' && (typeof handler !== 'object' || handler === null)) {
                        console.error('[Weline.Middleware] handler 必须是函数或对象', { received: typeof handler });
                        return;
                    }

                    const entry = {
                        name: name,
                        handler: handler,
                        priority: options.priority ?? 100,
                        source: options.source || null,
                        displayName: options.name || name,
                        meta: options.meta || {},
                    };

                    _entries.push(entry);

                    if (window.DEV) {
                        console.log('[Weline.Middleware] 注册中间件:', entry);
                    }
                },

                /**
                 * 取消注册
                 */
                unregister: function (name, filter) {
                    for (let i = _entries.length - 1; i >= 0; i--) {
                        const e = _entries[i];
                        const matchByName = !name || e.name === name;
                        const matchByFilter = typeof filter === 'function' ? filter(e) : true;
                        if (matchByName && matchByFilter) {
                            _entries.splice(i, 1);
                            if (window.DEV) {
                                console.log('[Weline.Middleware] 移除中间件:', e);
                            }
                        }
                    }
                },

                /**
                 * 获取全部中间件
                 */
                getAll: function () {
                    return _entries.slice();
                },

                /**
                 * 按前缀筛选中间件
                 * 
                 * @param {string} prefix - 如 'WeShop_Product::price::'
                 */
                getByPrefix: function (prefix) {
                    if (!prefix) return this.getAll();
                    return _entries.filter(e => typeof e.name === 'string' && e.name.indexOf(prefix) === 0);
                }
            };
        })()
    };

    // 挂载到全局
    (function normalizeRuntimeConfig() {
        if (!runtimeConfig.account) {
            return;
        }
        // 前端主题只解析前台和前端API的URL，后端APIURL由后端theme.js负责
        const mappings = [
            { key: 'frontendLoginUrl', type: 'frontend' },
            { key: 'frontendLogoutUrl', type: 'frontend' },
            { key: 'frontendCheckLoginUrl', type: 'frontend' },
            { key: 'apiLoginUrl', type: 'frontendApi' },
            { key: 'apiLogoutUrl', type: 'frontendApi' },
            { key: 'apiCheckLoginUrl', type: 'frontendApi' },
            { key: 'apiRefreshUrl', type: 'frontendApi' },
        ];
        mappings.forEach(({ key, type }) => {
            const value = runtimeConfig.account[key];
            if (!value) {
                return;
            }
            runtimeConfig.account[key] = UrlHelper.resolve(value, { type });
        });
    })();

    window.Weline = Weline;
    window.w_query = function (provider, operation, params = {}, options = {}) {
        return Weline.Query.request(provider, operation, params, options);
    };
    window.w_providerQuery = window.w_query;
    setupDeprecatedConfigAlias();

    /**
     * 注册延迟立即加载队列的 flush 时机
     * - 若 DOM 尚未加载完成，在 DOMContentLoaded 后执行
     * - 若 DOM 已加载完成，使用 setTimeout(flush, 0) 推迟到下一个事件循环执行
     */
    (function registerDeferredLoadFlush() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', flushImmediateLoadDeferredQueue, { once: true });
        } else {
            // DOM 已加载完成，使用 setTimeout 避免在初始化栈上加重负担
            setTimeout(flushImmediateLoadDeferredQueue, 0);
        }
    })();

    /**
     * 监听postMessage消息，支持从父窗口切换主题色系（用于主题预览）
     */
    (function initThemeColorMessageListener() {
        window.addEventListener('message', function (event) {
            // 安全检查：只接受来自同源的消息（在预览场景中，父窗口和iframe是同源的）
            if (event.data && event.data.type === 'switchThemeColor') {
                const themeColor = event.data.themeColor;
                if (themeColor && Weline.Theme && typeof Weline.Theme.switch === 'function') {
                    Weline.Theme.switch(themeColor);
                    // 发送确认消息回父窗口
                    if (event.source && event.source !== window) {
                        event.source.postMessage({
                            type: 'themeColorSwitched',
                            themeColor: themeColor
                        }, '*');
                    }
                }
            }
        });
    })();

    /**
     * 自动处理 data-weline-load 属性（异步，不阻塞）
     */
    (function autoLoadDataAttributes() {
        function processDataAttributes() {
            const elements = document.querySelectorAll('[data-weline-load]');
            elements.forEach(function (el) {
                const moduleNames = el.getAttribute('data-weline-load');
                if (!moduleNames) return;

                const modules = moduleNames.split(',').map(name => name.trim()).filter(name => name);
                if (modules.length === 0) return;

                // 异步加载，不阻塞
                Weline.load(modules)
                    .then(function () {
                        el.dispatchEvent(new CustomEvent('weline-modules-loaded', {
                            detail: { modules: modules, element: el }
                        }));
                    })
                    .catch(function (error) {
                        el.dispatchEvent(new CustomEvent('weline-modules-error', {
                            detail: { modules: modules, error: error, element: el }
                        }));
                    });
            });
        }

        // 延迟执行，不阻塞
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', processDataAttributes);
        } else {
            setTimeout(processDataAttributes, 0);
        }
    })();

    /**
     * 初始化主题（延迟执行，不阻塞）
     */
    (function initTheme() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                Weline.Theme.init();
            });
        } else {
            setTimeout(() => {
                Weline.Theme.init();
            }, 0);
        }
    })();

    /**
     * Header 功能模块
     * 处理下拉菜单、搜索建议、键盘导航等
     */
    (function initHeaderFeatures() {
        function HeaderManager() {
            this.activeDropdown = null;
            this.searchSuggestions = null;
            this.searchInput = null;
            this.init();
        }

        HeaderManager.prototype = {
            init: function () {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => this.setup());
                } else {
                    this.setup();
                }
            },

            setup: function () {
                this.setupAccountDropdown();
                this.setupKeyboardNavigation();
                this.setupClickOutside();
            },

            // 账户下拉菜单
            setupAccountDropdown: function () {
                const accountElement = document.querySelector('.header-account');
                if (!accountElement) return;

                const dropdown = accountElement.querySelector('.account-dropdown');
                const trigger = accountElement.querySelector('.action-link') || accountElement;

                // 点击/hover 切换
                const toggleDropdown = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.closeAllDropdowns();
                    if (accountElement.classList.contains('active')) {
                        this.closeDropdown(accountElement);
                    } else {
                        this.openDropdown(accountElement);
                    }
                };

                trigger.addEventListener('click', toggleDropdown);

                // 键盘支持
                trigger.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleDropdown(e);
                    } else if (e.key === 'Escape') {
                        this.closeDropdown(accountElement);
                    }
                });

                // 菜单项键盘导航
                const menuItems = dropdown.querySelectorAll('a[role="menuitem"]');
                menuItems.forEach((item, index) => {
                    item.addEventListener('keydown', (e) => {
                        if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            const next = menuItems[index + 1] || menuItems[0];
                            next?.focus();
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            const prev = menuItems[index - 1] || menuItems[menuItems.length - 1];
                            prev?.focus();
                        } else if (e.key === 'Escape') {
                            this.closeDropdown(accountElement);
                            trigger.focus();
                        }
                    });
                });
            },

            // 键盘导航
            setupKeyboardNavigation: function () {
                // ESC 关闭所有下拉菜单
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        this.closeAllDropdowns();
                    }
                });
            },

            // 点击外部关闭
            setupClickOutside: function () {
                document.addEventListener('click', (e) => {
                    // 关闭账户下拉菜单
                    if (!e.target.closest('.header-account') &&
                        !e.target.closest('.account-dropdown')) {
                        this.closeAllDropdowns();
                    }
                });
            },

            // 打开下拉菜单
            openDropdown: function (element) {
                this.closeAllDropdowns();
                element.classList.add('active');
                const trigger = element.querySelector('[aria-expanded]');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'true');
                }
                this.activeDropdown = element;
            },

            // 关闭下拉菜单
            closeDropdown: function (element) {
                element.classList.remove('active');
                const trigger = element.querySelector('[aria-expanded]');
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
                if (this.activeDropdown === element) {
                    this.activeDropdown = null;
                }
            },

            // 关闭所有下拉菜单
            closeAllDropdowns: function () {
                document.querySelectorAll('.header-account.active').forEach(el => {
                    this.closeDropdown(el);
                });
            }
        };

        // 初始化
        new HeaderManager();
    })();

    /**
     * 侧边栏菜单管理（全部分类）
     */
    (function initCategoriesSidebar() {
        function CategoriesSidebarManager() {
            this.sidebar = null;
            this.overlay = null;
            this.hamburgerBtn = null;
            this.closeBtn = null;
            this.isOpen = false;
            this.init();
        }

        CategoriesSidebarManager.prototype = {
            init: function () {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => this.setup());
                } else {
                    this.setup();
                }
            },

            setup: function () {
                this.sidebar = document.getElementById('categories-sidebar');
                this.overlay = document.getElementById('categories-sidebar-overlay');
                this.hamburgerBtn = document.getElementById('hamburger-menu');
                this.closeBtn = document.getElementById('categories-sidebar-close');

                if (!this.sidebar || !this.overlay) return;

                // 汉堡菜单按钮点击 - 使用防误触机制
                if (this.hamburgerBtn) {
                    // 检查是否已经有其他监听器（避免与 default.phtml 中的实现冲突）
                    // 如果按钮已经有 data-hamburger-handler 标记，说明已经有其他处理器
                    if (this.hamburgerBtn.dataset.hamburgerHandler) {
                        return; // 不重复添加监听器
                    }

                    // 标记已处理
                    this.hamburgerBtn.dataset.hamburgerHandler = 'true';

                    let isProcessing = false;
                    let lastClickTime = 0;
                    let mouseDownPos = null;
                    let mouseDownTime = 0;

                    // 记录鼠标按下位置和时间
                    this.hamburgerBtn.addEventListener('mousedown', (e) => {
                        mouseDownPos = { x: e.clientX, y: e.clientY };
                        mouseDownTime = Date.now();
                    }, true);

                    // 触摸设备支持
                    this.hamburgerBtn.addEventListener('touchstart', (e) => {
                        const touch = e.touches[0];
                        mouseDownPos = { x: touch.clientX, y: touch.clientY };
                        mouseDownTime = Date.now();
                    }, true);

                    this.hamburgerBtn.addEventListener('touchend', (e) => {
                        if (!mouseDownPos) return;

                        const touch = e.changedTouches[0];
                        const moveDistance = Math.sqrt(
                            Math.pow(touch.clientX - mouseDownPos.x, 2) +
                            Math.pow(touch.clientY - mouseDownPos.y, 2)
                        );

                        // 触摸移动距离超过 10px，认为是滑动
                        if (moveDistance > 10) {
                            mouseDownPos = null;
                            mouseDownTime = 0;
                            return;
                        }

                        // 模拟点击事件
                        const clickEvent = new MouseEvent('click', {
                            bubbles: true,
                            cancelable: true,
                            clientX: touch.clientX,
                            clientY: touch.clientY
                        });
                        this.hamburgerBtn.dispatchEvent(clickEvent);
                    }, true);

                    // 点击处理
                    this.hamburgerBtn.addEventListener('click', (e) => {
                        // 防止重复触发
                        const now = Date.now();
                        if (isProcessing || (now - lastClickTime < 300)) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return;
                        }

                        // 检测是否为误触：如果鼠标移动距离过大，可能是滑动操作
                        if (mouseDownPos) {
                            const moveDistance = Math.sqrt(
                                Math.pow(e.clientX - mouseDownPos.x, 2) +
                                Math.pow(e.clientY - mouseDownPos.y, 2)
                            );
                            // 如果移动距离超过 5px，认为是滑动操作，不触发
                            if (moveDistance > 5) {
                                mouseDownPos = null;
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                return;
                            }
                        }

                        // 检测点击持续时间：如果按下时间过短（< 50ms），可能是误触
                        if (mouseDownTime && (now - mouseDownTime < 50)) {
                            mouseDownPos = null;
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            return;
                        }

                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();

                        isProcessing = true;
                        lastClickTime = now;
                        mouseDownPos = null;
                        mouseDownTime = 0;

                        this.toggle();

                        // 延迟后重置标志，防止快速连续点击
                        setTimeout(() => {
                            isProcessing = false;
                        }, 300);
                    }, true);
                }

                // 关闭按钮
                if (this.closeBtn) {
                    this.closeBtn.addEventListener('click', () => this.close());
                }

                // 点击遮罩层关闭
                this.overlay.addEventListener('click', () => this.close());

                // ESC 键关闭
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && this.isOpen) {
                        this.close();
                    }
                });

                // 侧边栏菜单项点击展开/收起
                this.setupSidebarMenuItems();
            },

            setupSidebarMenuItems: function () {
                const menuItems = this.sidebar?.querySelectorAll('.sidebar-category-item.has-children > .sidebar-category-link');
                if (!menuItems) return;

                menuItems.forEach(item => {
                    item.addEventListener('click', (e) => {
                        const parentItem = item.closest('.sidebar-category-item');
                        if (!parentItem) return;

                        // 如果有子菜单，切换展开/收起
                        if (parentItem.classList.contains('has-children')) {
                            e.preventDefault();
                            const isActive = parentItem.classList.contains('active');

                            // 关闭同级其他项
                            const siblings = Array.from(parentItem.parentElement.children);
                            siblings.forEach(sibling => {
                                if (sibling !== parentItem) {
                                    sibling.classList.remove('active');
                                }
                            });

                            // 切换当前项
                            if (isActive) {
                                parentItem.classList.remove('active');
                            } else {
                                parentItem.classList.add('active');
                            }
                        }
                    });
                });
            },

            open: function () {
                if (this.isOpen) return;

                this.isOpen = true;
                this.sidebar?.classList.add('active');
                this.overlay?.classList.add('active');
                this.sidebar?.setAttribute('aria-hidden', 'false');
                this.overlay?.setAttribute('aria-hidden', 'false');

                if (this.hamburgerBtn) {
                    this.hamburgerBtn.setAttribute('aria-expanded', 'true');
                    this.hamburgerBtn.classList.add('active');
                }

                // 防止背景滚动
                document.body.style.overflow = 'hidden';

                // 聚焦到侧边栏
                setTimeout(() => {
                    this.sidebar?.focus();
                }, 100);
            },

            close: function () {
                if (!this.isOpen) return;

                this.isOpen = false;
                this.sidebar?.classList.remove('active');
                this.overlay?.classList.remove('active');
                this.sidebar?.setAttribute('aria-hidden', 'true');
                this.overlay?.setAttribute('aria-hidden', 'true');

                if (this.hamburgerBtn) {
                    this.hamburgerBtn.setAttribute('aria-expanded', 'false');
                    this.hamburgerBtn.classList.remove('active');
                }

                // 恢复背景滚动
                document.body.style.overflow = '';

                // 聚焦回汉堡菜单按钮
                this.hamburgerBtn?.focus();
            },

            toggle: function () {
                if (this.isOpen) {
                    this.close();
                } else {
                    this.open();
                }
            }
        };

        // 初始化
        new CategoriesSidebarManager();
    })();

    // ========== 配置初始化（从head partial移除的内联JS逻辑） ==========
    (function () {
        // 初始化环境变量
        if (window.__WelineThemeConfig && window.__WelineThemeConfig.env) {
            const env = window.__WelineThemeConfig.env;
            if (typeof env.WELINE_ENV !== 'undefined') {
                window.WELINE_ENV = window.WELINE_ENV || env.WELINE_ENV;
            }
            if (typeof env.DEV !== 'undefined') {
                window.DEV = window.DEV !== undefined ? window.DEV : env.DEV;
            }
            if (typeof env.PROD !== 'undefined') {
                window.PROD = window.PROD !== undefined ? window.PROD : env.PROD;
            }
        }

        // 主题初始化（在Weline对象初始化后执行）
        function initTheme() {
            if (window.Weline && window.Weline.Theme) {
                // 初始化主题
                window.Weline.Theme.init();

                // 触发主题初始化完成事件
                window.dispatchEvent(new CustomEvent('weline:theme:initialized', {
                    detail: {
                        theme: window.Weline.Theme.getCurrent(),
                        config: (window.Weline && window.Weline.config) || null
                    }
                }));
            } else {
                // 如果Weline未加载，延迟重试
                setTimeout(initTheme, 100);
            }
        }

        // DOM加载完成后初始化
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initTheme);
        } else {
            setTimeout(initTheme, 0);
        }
    })();

    // ========== 初始化语言切换器 ==========
    (function initLanguageSwitcher() {
        /**
         * 获取当前语言代码
         */
        function getCurrentLang() {
            // 从 Cookie 获取
            if (typeof window.getCookie === 'function') {
                const cookieLang = window.getCookie('WELINE_USER_LANG');
                if (cookieLang) {
                    return cookieLang;
                }
            }

            // 从 URL 参数获取
            const urlParams = new URLSearchParams(window.location.search);
            const urlLang = urlParams.get('lang');
            if (urlLang) {
                return urlLang;
            }

            // 从 URL 路径获取（如 /zh_Hans_CN/...）
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            for (const part of pathParts) {
                if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                    return part;
                }
            }

            // 从配置获取
            const config = window.__WelineThemeConfig || {};
            return config.currentLang || config.i18n?.currentLang || (window.site && window.site.lang) || 'zh_Hans_CN';
        }

        /**
         * 将语言代码转换为显示标识
         */
        function getLangDisplay(langCode) {
            if (!langCode) {
                return 'ZH';
            }
            const parts = langCode.split('_');
            if (parts.length >= 2) {
                const lang = parts[0].toUpperCase();
                const region = parts[1].toUpperCase();
                if (lang === 'ZH') {
                    if (region === 'HANT') {
                        return 'TW';
                    }
                    return 'ZH';
                }
                return lang.substring(0, 2);
            }
            return langCode.substring(0, 2).toUpperCase();
        }

        /**
         * 更新当前语言显示
         */
        function updateCurrentLanguageDisplay() {
            const currentLang = getCurrentLang();
            const langDisplay = getLangDisplay(currentLang);

            // 更新 current-language 元素
            const currentLangElements = document.querySelectorAll('[data-i18n-switcher] .current-language');
            currentLangElements.forEach(el => {
                el.textContent = langDisplay;
            });

            // 更新 active 状态
            const languageSwitchers = document.querySelectorAll('[data-i18n-switcher]');
            languageSwitchers.forEach(languageSwitcher => {
                const languageOptions = languageSwitcher.querySelectorAll('[data-language-option], .language-option, a[data-lang]');
                languageOptions.forEach(option => {
                    const langCode = option.getAttribute('data-lang') || option.dataset.lang;
                    if (langCode === currentLang) {
                        option.classList.add('active');
                    } else {
                        option.classList.remove('active');
                    }
                });
            });
        }

        /**
         * 更新语言切换器链接
         */
        function updateLanguageSwitcherLinks() {
            const languageSwitchers = document.querySelectorAll('[data-i18n-switcher]');
            if (languageSwitchers.length === 0) {
                return;
            }

            const currentPath = window.location.pathname + window.location.search;
            const config = window.__WelineThemeConfig || {};

            // 获取当前货币（用于保持货币）
            let currentCurrency = '';
            const pathParts = currentPath.split('?')[0].split('/').filter(Boolean);
            for (const part of pathParts) {
                if (/^[A-Z]{3}$/.test(part)) {
                    currentCurrency = part;
                    break;
                }
            }
            if (!currentCurrency) {
                currentCurrency = (config.currentCurrency || 'CNY').toUpperCase();
            }

            languageSwitchers.forEach(languageSwitcher => {
                const languageOptions = languageSwitcher.querySelectorAll('[data-language-option], .language-option, a[data-lang]');
                languageOptions.forEach(option => {
                    const langCode = option.getAttribute('data-lang') || option.dataset.lang;
                    if (!langCode) {
                        return;
                    }

                    let langUrl = '';

                    // 使用 window.urlWithLang 生成带语言的 URL（框架推荐的路径格式，会自动保持货币）
                    if (typeof window.urlWithLang === 'function') {
                        langUrl = window.urlWithLang(currentPath, langCode);
                    } else if (typeof window.inject_path === 'function') {
                        // 使用 inject_path（会自动保持货币）
                        const pathOnly = currentPath.split('?')[0];
                        const search = currentPath.includes('?') ? currentPath.split('?')[1] : '';
                        langUrl = window.inject_path(pathOnly, langCode, 'lang') + (search ? '?' + search : '');
                    } else {
                        // 降级方案：手动构建路径格式的 URL（需要手动保持货币）
                        const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
                        const currencyPattern = /^[A-Z]{3}$/;
                        const filteredParts = pathParts.filter(part => !langPattern.test(part) && !currencyPattern.test(part));
                        const cleanPath = '/' + filteredParts.join('/');
                        const search = currentPath.includes('?') ? currentPath.split('?')[1] : '';
                        langUrl = '/' + currentCurrency + '/' + langCode + cleanPath + (search ? '?' + search : '');
                    }

                    if (langUrl) {
                        option.setAttribute('href', langUrl);
                    }
                });
            });

            updateCurrentLanguageDisplay();
        }

        /**
         * 切换语言
         */
        function switchLang(lang) {
            if (!lang) {
                return;
            }

            // 优先使用 select_language 函数（来自 url-frontend 模块，会自动保持货币）
            if (typeof window.select_language === 'function') {
                window.select_language(lang);
                return;
            }

            // 次优先使用 urlWithLang 函数
            if (typeof window.urlWithLang === 'function') {
                const currentPath = window.location.pathname + window.location.search;
                const langUrl = window.urlWithLang(currentPath, lang);
                localStorage.setItem('weline_user_lang', lang);
                if (typeof window.setCookie === 'function') {
                    window.setCookie('WELINE_USER_LANG', lang, 365);
                }
                window.location.href = langUrl;
                return;
            }

            // 降级方案：使用 inject_path
            if (typeof window.inject_path === 'function') {
                const langUrl = window.inject_path(window.location.pathname, lang, 'lang') + window.location.search;
                localStorage.setItem('weline_user_lang', lang);
                if (typeof window.setCookie === 'function') {
                    window.setCookie('WELINE_USER_LANG', lang, 365);
                }
                window.location.href = langUrl;
                return;
            }

            // 最后的降级方案：手动构建 URL
            let currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(Boolean);
            let currentCurrency = '';
            for (const part of pathParts) {
                if (/^[A-Z]{3}$/.test(part)) {
                    currentCurrency = part;
                    break;
                }
            }
            if (!currentCurrency) {
                const config = window.__WelineThemeConfig || {};
                currentCurrency = (config.currentCurrency || 'CNY').toUpperCase();
            }

            const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
            const currencyPattern = /^[A-Z]{3}$/;
            const filteredParts = pathParts.filter(part => !langPattern.test(part) && !currencyPattern.test(part));
            const cleanPath = '/' + filteredParts.join('/');
            const langUrl = '/' + currentCurrency + '/' + lang + cleanPath + window.location.search;

            localStorage.setItem('weline_user_lang', lang);
            if (typeof window.setCookie === 'function') {
                window.setCookie('WELINE_USER_LANG', lang, 365);
            }
            window.location.href = langUrl;
        }

        // 等待 URL 模块加载
        function waitForUrlModule() {
            if (typeof window.urlWithLang === 'function' || typeof window.url === 'function') {
                updateLanguageSwitcherLinks();
                return;
            }

            // 延迟重试
            setTimeout(() => {
                updateLanguageSwitcherLinks();
            }, 100);
        }

        // 等待 DOM 加载
        function initAfterDOMReady() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    setTimeout(waitForUrlModule, 100);
                });
            } else {
                setTimeout(waitForUrlModule, 100);
            }
        }

        initAfterDOMReady();

        // 监听语言切换事件
        document.addEventListener('click', function (e) {
            const languageSwitcher = e.target.closest('[data-i18n-switcher]');
            if (!languageSwitcher) {
                return;
            }

            const langOption = e.target.closest('[data-language-option], .language-option, a[data-lang]');
            if (langOption) {
                const langCode = langOption.getAttribute('data-lang') || langOption.dataset.lang;
                if (langCode) {
                    e.preventDefault();
                    e.stopPropagation();
                    switchLang(langCode);
                }
            }
        });

        // 监听 URL 变化
        let lastUrl = window.location.href;
        setInterval(() => {
            if (window.location.href !== lastUrl) {
                lastUrl = window.location.href;
                updateCurrentLanguageDisplay();
            }
        }, 500);
    })();

    // ========== 初始化货币切换器 ==========
    (function initCurrencySwitcher() {
        /**
         * 获取当前货币代码
         */
        function getCurrentCurrency() {
            // 从 Cookie 获取
            if (typeof window.getCookie === 'function') {
                const cookieCurrency = window.getCookie('WELINE_USER_CURRENCY');
                if (cookieCurrency) {
                    return cookieCurrency.toUpperCase();
                }
            }

            // 从 URL 参数获取
            const urlParams = new URLSearchParams(window.location.search);
            const urlCurrency = urlParams.get('currency');
            if (urlCurrency) {
                return urlCurrency.toUpperCase();
            }

            // 从 URL 路径获取（如 /CNY/...）
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            for (const part of pathParts) {
                if (/^[A-Z]{3}$/.test(part)) {
                    return part.toUpperCase();
                }
            }

            // 从配置获取
            const config = window.__WelineThemeConfig || {};
            return (config.currentCurrency || (window.site && window.site.currency) || 'CNY').toUpperCase();
        }

        /**
         * 更新当前货币显示
         */
        function updateCurrentCurrencyDisplay() {
            const currentCurrency = getCurrentCurrency();

            // 更新 current-currency 元素
            const currentCurrencyElements = document.querySelectorAll('[data-currency-switcher] .current-currency');
            currentCurrencyElements.forEach(el => {
                el.textContent = currentCurrency;
            });

            // 更新 active 状态
            const currencySwitchers = document.querySelectorAll('[data-currency-switcher]');
            currencySwitchers.forEach(currencySwitcher => {
                const currencyOptions = currencySwitcher.querySelectorAll('[data-currency-option], .currency-option, a[data-currency]');
                currencyOptions.forEach(option => {
                    const currencyCode = (option.getAttribute('data-currency') || option.dataset.currency || '').toUpperCase();
                    if (currencyCode === currentCurrency) {
                        option.classList.add('active');
                    } else {
                        option.classList.remove('active');
                    }
                });
            });
        }

        /**
         * 更新货币切换器链接
         */
        function updateCurrencySwitcherLinks() {
            const currencySwitchers = document.querySelectorAll('[data-currency-switcher]');
            if (currencySwitchers.length === 0) {
                return;
            }

            const currentPath = window.location.pathname + window.location.search;
            const config = window.__WelineThemeConfig || {};

            // 获取当前语言（用于保持语言）
            let currentLang = '';
            const pathParts = currentPath.split('?')[0].split('/').filter(Boolean);
            for (const part of pathParts) {
                if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                    currentLang = part;
                    break;
                }
            }
            if (!currentLang) {
                currentLang = config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN';
            }

            currencySwitchers.forEach(currencySwitcher => {
                const currencyOptions = currencySwitcher.querySelectorAll('[data-currency-option], .currency-option, a[data-currency]');
                currencyOptions.forEach(option => {
                    const currencyCode = (option.getAttribute('data-currency') || option.dataset.currency || '').toUpperCase();
                    if (!currencyCode) {
                        return;
                    }

                    let currencyUrl = '';

                    // 使用 window.urlWithCurrency 生成带货币的 URL（框架推荐的路径格式，会自动保持语言）
                    if (typeof window.urlWithCurrency === 'function') {
                        currencyUrl = window.urlWithCurrency(currentPath, currencyCode);
                    } else if (typeof window.inject_path === 'function') {
                        // 使用 inject_path（会自动保持语言）
                        const pathOnly = currentPath.split('?')[0];
                        const search = currentPath.includes('?') ? currentPath.split('?')[1] : '';
                        currencyUrl = window.inject_path(pathOnly, currencyCode, 'currency') + (search ? '?' + search : '');
                    } else {
                        // 降级方案：手动构建路径格式的 URL（需要手动保持语言）
                        const currencyPattern = /^[A-Z]{3}$/;
                        const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
                        const filteredParts = pathParts.filter(part => !currencyPattern.test(part) && !langPattern.test(part));
                        const cleanPath = '/' + filteredParts.join('/');
                        const search = currentPath.includes('?') ? currentPath.split('?')[1] : '';
                        currencyUrl = '/' + currencyCode + '/' + currentLang + cleanPath + (search ? '?' + search : '');
                    }

                    if (currencyUrl) {
                        option.setAttribute('href', currencyUrl);
                    }
                });
            });

            updateCurrentCurrencyDisplay();
        }

        /**
         * 切换货币
         */
        function switchCurrency(currency) {
            if (!currency) {
                return;
            }

            currency = currency.toUpperCase();

            // 优先使用 select_currency 函数（来自 url-frontend 模块，会自动保持语言）
            if (typeof window.select_currency === 'function') {
                window.select_currency(currency);
                return;
            }

            // 次优先使用 urlWithCurrency 函数
            if (typeof window.urlWithCurrency === 'function') {
                const currentPath = window.location.pathname + window.location.search;
                const currencyUrl = window.urlWithCurrency(currentPath, currency);
                localStorage.setItem('weline_user_currency', currency);
                if (typeof window.setCookie === 'function') {
                    window.setCookie('WELINE_USER_CURRENCY', currency, 365);
                }
                window.location.href = currencyUrl;
                return;
            }

            // 降级方案：使用 inject_path
            if (typeof window.inject_path === 'function') {
                const currencyUrl = window.inject_path(window.location.pathname, currency, 'currency') + window.location.search;
                localStorage.setItem('weline_user_currency', currency);
                if (typeof window.setCookie === 'function') {
                    window.setCookie('WELINE_USER_CURRENCY', currency, 365);
                }
                window.location.href = currencyUrl;
                return;
            }

            // 最后的降级方案：手动构建 URL
            let currentPath = window.location.pathname;
            const pathParts = currentPath.split('/').filter(Boolean);
            let currentLang = '';
            for (const part of pathParts) {
                if (/^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i.test(part)) {
                    currentLang = part;
                    break;
                }
            }
            if (!currentLang) {
                const config = window.__WelineThemeConfig || {};
                currentLang = config.currentLang || config.i18n?.currentLang || 'zh_Hans_CN';
            }

            const currencyPattern = /^[A-Z]{3}$/;
            const langPattern = /^[a-z]{2}_[A-Z][a-z]+(_[A-Z]{2})?$/i;
            const filteredParts = pathParts.filter(part => !currencyPattern.test(part) && !langPattern.test(part));
            const cleanPath = '/' + filteredParts.join('/');
            const currencyUrl = '/' + currency + '/' + currentLang + cleanPath + window.location.search;

            localStorage.setItem('weline_user_currency', currency);
            if (typeof window.setCookie === 'function') {
                window.setCookie('WELINE_USER_CURRENCY', currency, 365);
            }
            window.location.href = currencyUrl;
        }

        // 等待 URL 模块加载
        function waitForUrlModule() {
            if (typeof window.urlWithCurrency === 'function' || typeof window.url === 'function') {
                updateCurrencySwitcherLinks();
                return;
            }

            // 延迟重试
            setTimeout(() => {
                updateCurrencySwitcherLinks();
            }, 100);
        }

        // 等待 DOM 加载
        function initAfterDOMReady() {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    setTimeout(waitForUrlModule, 100);
                });
            } else {
                setTimeout(waitForUrlModule, 100);
            }
        }

        initAfterDOMReady();

        // 监听货币切换事件
        document.addEventListener('click', function (e) {
            const currencySwitcher = e.target.closest('[data-currency-switcher]');
            if (!currencySwitcher) {
                return;
            }

            const currencyOption = e.target.closest('[data-currency-option], .currency-option, a[data-currency]');
            if (currencyOption) {
                const currencyCode = (currencyOption.getAttribute('data-currency') || currencyOption.dataset.currency || '').toUpperCase();
                if (currencyCode) {
                    e.preventDefault();
                    e.stopPropagation();
                    switchCurrency(currencyCode);
                }
            }
        });

        // 监听 URL 变化
        let lastUrl = window.location.href;
        setInterval(() => {
            if (window.location.href !== lastUrl) {
                lastUrl = window.location.href;
                updateCurrentCurrencyDisplay();
            }
        }, 500);
    })();

    // ========== 全局 HTTP 错误处理拦截器 ==========
    /**
     * 全局友好错误提示
     * 错误信息从后端 JSON 响应中获取（已支持多语言）
     * 
     * JSON 响应格式：
     * {
     *   "error": true,
     *   "code": 500,
     *   "title": "服务器错误",        // 可选，后端翻译
     *   "message": "服务器内部错误",   // 后端翻译
     *   "icon": "⚠️",                 // 可选
     *   "retry_after": 15             // 可选，自动重试秒数
     * }
     */
    (function initGlobalErrorHandler() {
        const STATUS_ICONS = {
            500: '⚠️', 502: '🔄', 503: '🔧', 504: '⏱️', 0: '📡'
        };

        let errorOverlayVisible = false;
        let autoRetryTimer = null;

        /**
         * 显示错误覆盖层
         * @param {Object} options - 错误信息对象
         * @param {number} options.status - HTTP 状态码
         * @param {string} options.title - 错误标题（后端已翻译）
         * @param {string} options.message - 错误消息（后端已翻译）
         * @param {string} options.icon - 图标（可选）
         * @param {number} options.retryAfter - 自动重试秒数（可选）
         */
        function showErrorOverlay(options) {
            if (errorOverlayVisible) {
                return;
            }

            const statusCode = options.status || 500;
            const title = options.title || `HTTP ${statusCode}`;
            const message = options.message || '';
            const icon = options.icon || STATUS_ICONS[statusCode] || STATUS_ICONS[500];
            const retryAfter = options.retryAfter || (statusCode === 502 || statusCode === 503 ? 15 : 0);
            const retryBtnText = options.retryBtnText || __('立即重试');
            const closeBtnText = options.closeBtnText || __('关闭');

            errorOverlayVisible = true;

            const overlay = document.createElement('div');
            overlay.id = 'weline-error-overlay';
            overlay.style.cssText = `
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0, 0, 0, 0.75); display: flex; align-items: center;
                justify-content: center; z-index: 99999;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            `;

            const content = document.createElement('div');
            content.style.cssText = `
                background: var(--color-card-bg, #fff); border-radius: 12px; padding: 32px;
                max-width: 480px; width: 90%; text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            `;

            const iconEl = document.createElement('div');
            iconEl.style.cssText = 'font-size: 56px; margin-bottom: 16px;';
            iconEl.textContent = icon;

            const titleEl = document.createElement('h2');
            titleEl.style.cssText = `
                font-size: 22px; font-weight: 600; color: var(--color-text-primary, #1f2937);
                margin: 0 0 12px 0;
            `;
            titleEl.textContent = title;

            const messageEl = document.createElement('p');
            messageEl.style.cssText = `
                font-size: 15px; color: var(--color-text-secondary, #6b7280);
                margin: 0 0 8px 0; line-height: 1.6;
            `;
            messageEl.textContent = message;

            const statusEl = document.createElement('p');
            statusEl.style.cssText = `
                font-size: 13px; color: var(--color-text-tertiary, #9ca3af);
                margin: 0 0 24px 0;
            `;
            statusEl.textContent = statusCode > 0 ? `HTTP ${statusCode}` : '';

            const countdownEl = document.createElement('div');
            countdownEl.id = 'weline-error-countdown';
            countdownEl.style.cssText = `
                font-size: 13px; color: var(--color-text-tertiary, #9ca3af);
                margin-bottom: 20px;
            `;

            const btnContainer = document.createElement('div');
            btnContainer.style.cssText = 'display: flex; gap: 12px; justify-content: center;';

            const retryBtn = document.createElement('button');
            retryBtn.style.cssText = `
                background: var(--color-primary, #3b82f6); border: none; border-radius: 8px;
                padding: 10px 20px; font-size: 14px; color: #fff; cursor: pointer; transition: all 0.2s;
            `;
            retryBtn.textContent = retryBtnText;
            retryBtn.onclick = () => {
                closeErrorOverlay();
                window.location.reload();
            };

            const closeBtn = document.createElement('button');
            closeBtn.style.cssText = `
                background: var(--color-card-bg-hover, #f3f4f6); border: none; border-radius: 8px;
                padding: 10px 20px; font-size: 14px; color: var(--color-text-secondary, #6b7280);
                cursor: pointer; transition: all 0.2s;
            `;
            closeBtn.textContent = closeBtnText;
            closeBtn.onclick = closeErrorOverlay;

            btnContainer.appendChild(retryBtn);
            btnContainer.appendChild(closeBtn);

            content.appendChild(iconEl);
            content.appendChild(titleEl);
            content.appendChild(messageEl);
            content.appendChild(statusEl);
            content.appendChild(countdownEl);
            content.appendChild(btnContainer);
            overlay.appendChild(content);
            document.body.appendChild(overlay);

            if (retryAfter > 0) {
                startAutoRetry(countdownEl, retryAfter);
            }
        }

        function closeErrorOverlay() {
            const overlay = document.getElementById('weline-error-overlay');
            if (overlay) {
                overlay.remove();
            }
            errorOverlayVisible = false;
            if (autoRetryTimer) {
                clearInterval(autoRetryTimer);
                autoRetryTimer = null;
            }
        }

        function startAutoRetry(countdownEl, retryAfter) {
            let countdown = retryAfter;

            const updateCountdown = () => {
                countdownEl.textContent = __('将在 %{seconds} 秒后自动重试...', { seconds: countdown });
            };

            updateCountdown();

            autoRetryTimer = setInterval(() => {
                countdown--;
                if (countdown <= 0) {
                    closeErrorOverlay();
                    window.location.reload();
                } else {
                    updateCountdown();
                }
            }, 1000);
        }

        /**
         * 从 Response 解析错误信息
         */
        async function parseErrorFromResponse(response) {
            const contentType = response.headers.get('content-type') || '';
            const options = {
                status: response.status,
                title: '',
                message: '',
                icon: '',
                retryAfter: 0
            };

            if (contentType.includes('application/json')) {
                try {
                    const json = await response.clone().json();
                    options.title = json.title || json.error_title || '';
                    options.message = json.message || json.msg || json.error || '';
                    options.icon = json.icon || '';
                    options.retryAfter = json.retry_after || 0;
                } catch (e) {
                    // JSON 解析失败
                }
            }

            return options;
        }

        const originalFetch = window.fetch;
        window.fetch = function (...args) {
            return originalFetch.apply(this, args)
                .then(async response => {
                    if (response.status >= 500 && response.status < 600) {
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            const errorOptions = await parseErrorFromResponse(response);
                            if (errorOptions.message) {
                                showErrorOverlay(errorOptions);
                            }
                        } else if (contentType.includes('text/html')) {
                            showErrorOverlay({
                                status: response.status,
                                title: __('服务器错误'),
                                message: __('服务器内部错误，请稍后重试。')
                            });
                        }
                    }
                    return response;
                })
                .catch(error => {
                    if (error.name === 'TypeError' && error.message.includes('fetch')) {
                        showErrorOverlay({
                            status: 0,
                            title: __('网络错误'),
                            message: __('无法连接到服务器，请检查网络连接。')
                        });
                    }
                    throw error;
                });
        };

        window.addEventListener('weline:http:error', (event) => {
            const detail = event.detail || {};
            showErrorOverlay({
                status: detail.status || detail.code || 500,
                title: detail.title || '',
                message: detail.message || detail.msg || '',
                icon: detail.icon || '',
                retryAfter: detail.retry_after || detail.retryAfter || 0
            });
        });

        window.WelineErrorHandler = {
            show: showErrorOverlay,
            close: closeErrorOverlay,
        };
    })();

})(window, document);
