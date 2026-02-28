/**
 * Weline Framework - 统一后端JS入口（轻量级，按需加载）
 * 
 * 重要说明：
 * 1. 必须通过 Weline.declare() 声明模块，方便PHP解析翻译词
 * 2. 只有声明且立即加载的模块才会立即加载，其他按需加载
 * 3. 所有异步操作不阻塞JS单线程
 * 4. 配置信息由 Theme.js 自动从 window.__WelineThemeConfig 获取（由PHP在head.phtml中初始化）
 * 
 * 使用方式：
 *   Weline.declare('api'); // 声明模块，按需加载
 *   Weline.declare('account', true); // 声明并立即加载
 *   Weline.declare('search', true, 'path', null, { loadOrder: 'last' }); // 声明并延迟立即加载（DOMContentLoaded后）
 *   Weline.load('api'); // 立即加载模块
 *   Weline.Api.request(url, options); // 使用模块（自动按需加载）
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
        if (window.WelineConfig) {
            console.warn('[Weline] window.WelineConfig 已废弃，请改用 Theme.js 注入配置。');
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
     *   - 输入：Weline_Admin::js/theme.js
     *   - 输出：/Weline/Admin/view/statics/js/theme.js
     * 
     * 生产模式（PROD）：
     *   - 输入：Weline_Admin::js/theme.js
     *   - 输出：/static/Weline/Admin/js/theme.js
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
                        // 开发模式：/Weline/Module/view/statics/path/to/file.js
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

    // 保持向后兼容的别名
    /**
     * 模块配置管理器（按需加载，不阻塞）
     */
    class ModuleConfigManager {
        constructor() {
            this.config = null;
            this.loaded = false;
            this.loading = null;
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
                // 解析模块配置URL，确保在开发/生产模式下都能正确加载
                let modulesConfigUrl = runtimeConfig.modulesConfigUrl;
                if (!modulesConfigUrl) {
                    if (isDev) {
                        modulesConfigUrl = '/Weline/Backend/view/statics/base/weline.modules.js';
                    } else {
                        modulesConfigUrl = '/static/Weline/Backend/base/weline.modules.js';
                    }
                } else {
                    modulesConfigUrl = StaticResourceResolver.resolve(modulesConfigUrl);
                }
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
                        // 使用静态资源解析器确保路径正确
                        let modulesBaseUrl = runtimeConfig.modulesBaseUrl;
                        if (!modulesBaseUrl) {
                            // 如果没有配置，根据环境生成默认路径
                            if (isDev) {
                                modulesBaseUrl = '/Weline/Backend/view/statics/js/weline-api';
                            } else {
                                modulesBaseUrl = '/static/Weline/Backend/js/weline-api';
                            }
                        } else {
                            // 如果配置了路径，使用静态资源解析器解析
                            modulesBaseUrl = StaticResourceResolver.resolve(modulesBaseUrl);
                        }
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
                    if (globalVarName && window[globalVarName]) {
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
                'url-backend': 'WelineUrlBackendModule',
                'url-frontend': 'WelineUrlFrontendModule',
            };
            if (nameMap[moduleName]) {
                return nameMap[moduleName];
            }
            // 将连字符转换为驼峰命名：url-backend -> UrlBackend
            const camelCaseName = moduleName
                .split('-')
                .map((part, index) => part.charAt(0).toUpperCase() + part.slice(1))
                .join('');
            return `Weline${camelCaseName}Module`;
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
                    console.warn('[Weline] window.WelineConfig 已废弃，请使用 window.Weline.config');
                    warnedGet = true;
                }
                return runtimeConfig;
            },
            set(value) {
                if (!warnedSet && window.console) {
                    console.warn('[Weline] window.WelineConfig 设置方式已废弃，请使用 Weline.Theme.applyConfig(...)');
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
            let proxy = null;

            proxy = new Proxy({}, {
                get: (target, prop) => {
                    // 防止递归：检查 window[globalVarName] 是否是 Proxy 本身
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy && typeof windowObj === 'object' && windowObj !== null) {
                        if (typeof windowObj[prop] !== 'undefined') {
                            return windowObj[prop];
                        }
                    }

                    if (loadingPromise) {
                        return loadingPromise.then(() => {
                            const realObj = window[globalVarName];
                            if (realObj && realObj !== proxy) {
                                return realObj[prop];
                            }
                            return undefined;
                        });
                    }

                    if (loaded) {
                        return undefined;
                    }

                    loadingPromise = this.loadOnDemand(moduleName).then(() => {
                        loaded = true;
                        const realObj = window[globalVarName];
                        if (realObj && realObj !== proxy) {
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
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy) {
                        windowObj[prop] = value;
                        return true;
                    }
                    target[prop] = value;
                    return true;
                },
                has: (target, prop) => {
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy) {
                        return prop in windowObj;
                    }
                    return prop in target;
                },
                ownKeys: (target) => {
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy) {
                        return Object.keys(windowObj);
                    }
                    return Object.keys(target);
                },
                construct: (target, args) => {
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy && typeof windowObj === 'function') {
                        return new windowObj(...args);
                    }
                    return this.loadOnDemand(moduleName).then(() => {
                        const realObj = window[globalVarName];
                        if (realObj && realObj !== proxy && typeof realObj === 'function') {
                            return new realObj(...args);
                        }
                        throw new Error(`[Weline] ${globalVarName} 不是一个构造函数`);
                    });
                },
                apply: (target, thisArg, args) => {
                    const windowObj = window[globalVarName];
                    if (windowObj && windowObj !== proxy && typeof windowObj === 'function') {
                        return windowObj.apply(thisArg, args);
                    }
                    return this.loadOnDemand(moduleName).then(() => {
                        const realObj = window[globalVarName];
                        if (realObj && realObj !== proxy && typeof realObj === 'function') {
                            return realObj.apply(thisArg, args);
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
                const area = options.area || 'backend';
                const queryConfig = runtimeConfig.query || {};
                const frontendUrl = queryConfig.frontendUrl || '/api/framework/query';
                const backendUrl = queryConfig.backendUrl || '/api_admin/framework/query';
                const endpoint = area === 'frontend' ? frontendUrl : backendUrl;
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
         * Message 消息模块 - 发送系统通知
         */
        Message: {
            /**
             * 发送系统通知
             * @param {string} topic 消息主题（如 domain_expiring, system_info）
             * @param {string} type 消息类型：info/success/warning/error/urgent
             * @param {string} title 消息标题
             * @param {string} content 消息内容
             * @param {object} options 可选参数
             * @returns {Promise<object>}
             */
            send: async (topic, type, title, content, options = {}) => {
                const msgConfig = runtimeConfig.message || {};
                const endpoint = msgConfig.backendUrl || '/api_admin/backend/notification/send';

                const response = await Weline.Api.request(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        topic: topic,
                        type: type,
                        title: title,
                        content: content,
                        priority: options.priority || null,
                        metadata: options.metadata || {},
                        icon: options.icon || 'ri-notification-line',
                        notify_users: options.notifyUsers || [],
                    })
                });

                if (!response || response.code !== 200) {
                    throw new Error((response && response.msg) ? response.msg : __('发送通知失败'));
                }
                return response;
            }
        },

        /**
         * i18n 国际化对象（从PHP初始化）
         */
        i18n: {
            currentLang: runtimeConfig.currentLang || 'zh_Hans_CN',
            dictionary: runtimeConfig.i18n?.dictionary || {},

            setDictionary: (dict) => {
                Weline.i18n.dictionary = dict || {};
            },

            translate: (key, params = {}) => {
                let text = Weline.i18n.dictionary[key] || key;
                Object.keys(params).forEach(paramKey => {
                    text = text.replace(new RegExp(`%{${paramKey}}`, 'g'), params[paramKey]);
                });
                return text;
            },

            switchLang: async (lang) => {
                localStorage.setItem('weline_user_lang', lang);
                // 使用 url-backend 模块的 select_language 函数（如果已加载）
                if (typeof window.select_language === 'function') {
                    window.select_language(lang);
                } else if (typeof window.urlWithLang === 'function') {
                    const langUrl = window.urlWithLang(window.location.pathname, lang);
                    window.location.href = langUrl + window.location.search;
                } else {
                    // 降级方案：使用 URL 参数
                    const url = new URL(window.location.href);
                    url.searchParams.set('lang', lang);
                    window.location.href = url.toString();
                }
            },
        },

        /**
         * 货币和语言切换对象
         */
        Locale: {
            currentCurrency: runtimeConfig.currentCurrency || 'CNY',
            currentLang: runtimeConfig.currentLang || 'zh_Hans_CN',

            switchCurrency: async (currency) => {
                localStorage.setItem('weline_user_currency', currency);
                // 使用 url-backend 模块的 select_currency 函数（如果已加载）
                if (typeof window.select_currency === 'function') {
                    window.select_currency(currency);
                } else if (typeof window.urlWithCurrency === 'function') {
                    const currencyUrl = window.urlWithCurrency(window.location.pathname, currency);
                    window.location.href = currencyUrl + window.location.search;
                } else {
                    // 降级方案：使用 URL 参数
                    const url = new URL(window.location.href);
                    url.searchParams.set('currency', currency);
                    window.location.href = url.toString();
                }
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
         * 侧边栏管理器（后端专用）
         */
        Sidebar: {
            init: function () {
                const toggleBtn = document.querySelector('[data-sidebar-toggle]');
                const sidebar = document.querySelector('.backend-sidebar');

                if (toggleBtn && sidebar) {
                    toggleBtn.addEventListener('click', function () {
                        sidebar.classList.toggle('collapsed');
                        localStorage.setItem('backend-sidebar-collapsed', sidebar.classList.contains('collapsed'));
                    });

                    const savedState = localStorage.getItem('backend-sidebar-collapsed');
                    if (savedState === 'true') {
                        sidebar.classList.add('collapsed');
                    }
                }
            }
        }
    };

    // 挂载到全局
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
     * 初始化侧边栏（延迟执行，不阻塞）
     */
    (function initSidebar() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                Weline.Sidebar.init();
            });
        } else {
            setTimeout(() => {
                Weline.Sidebar.init();
            }, 0);
        }
    })();

    /**
     * 监听postMessage消息，支持从父窗口切换主题色系（用于主题预览）
     */
    (function initThemeColorMessageListener() {
        window.addEventListener('message', function(event) {
            // 安全检查：只接受来自同源的消息（在预览场景中，父窗口和iframe是同源的）
            if (event.data && event.data.type === 'switchThemeColor') {
                const themeColor = event.data.themeColor;
                if (themeColor) {
                    // 后端使用setThemeConfig函数切换主题色系
                    if (typeof window.setThemeConfig === 'function') {
                        const themeMode = themeColor === 'dark' ? 'dark' : 'light';
                        window.setThemeConfig({
                            layouts: {
                                'data-topbar': themeMode,
                                'data-sidebar': themeMode,
                            },
                            'theme-mode-switch': themeMode,
                        }, false); // false表示不重新加载页面
                        
                        // 发送确认消息回父窗口
                        if (event.source && event.source !== window) {
                            event.source.postMessage({
                                type: 'themeColorSwitched',
                                themeColor: themeColor
                            }, '*');
                        }
                    } else {
                        // 如果setThemeConfig不存在，尝试直接设置data属性
                        document.documentElement.setAttribute('data-topbar', themeColor === 'dark' ? 'dark' : 'light');
                        document.documentElement.setAttribute('data-sidebar', themeColor === 'dark' ? 'dark' : 'light');
                        
                        // 发送确认消息回父窗口
                        if (event.source && event.source !== window) {
                            event.source.postMessage({
                                type: 'themeColorSwitched',
                                themeColor: themeColor
                            }, '*');
                        }
                    }
                }
            }
        });
    })();

    // ========== 声明并加载 URL 模块 ==========
    // 后端使用 url-backend 模块
    (function loadUrlModule() {
        const config = window.__WelineThemeConfig || {};
        const area = config.theme?.area || config.area || 'backend';
        
        if (window.Weline && window.Weline.declare) {
            Weline.declare('url-backend', true, 'Weline_Framework::js/url-backend.js');
        } else if (window.Weline && window.Weline.load) {
            // 如果 declare 不可用，直接加载
            window.Weline.load('url-backend', 'Weline_Framework::js/url-backend.js');
        }
    })();

    // ========== 全局 w_msg 函数 ==========
    /**
     * 发送系统通知的全局快捷函数
     * @param {string} topic 消息主题
     * @param {string} type 消息类型：info/success/warning/error/urgent
     * @param {string} title 消息标题
     * @param {string} content 消息内容
     * @param {object} options 可选参数
     * @returns {Promise<object>}
     */
    window.w_msg = function (topic, type, title, content, options = {}) {
        return Weline.Message.send(topic, type, title, content, options);
    };

})(window, document);
