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
        (window.Weline && window.Weline.config) || window.__WelineThemeConfig || window.WelineConfig || {}
    );

    /**
     * 模块加载器
     */
    class ModuleLoader {
        constructor() {
            this.loadedModules = new Map();
            this.loadingModules = new Map();
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
         * @param {string} modulePath 模块路径（可选，默认从modulesBaseUrl加载）
         * @returns {Promise<any>}
         */
        async loadModule(moduleName, modulePath = null) {
            // 如果已加载，直接返回
            if (this.loadedModules.has(moduleName)) {
                return this.loadedModules.get(moduleName);
            }

            // 如果正在加载，等待加载完成
            if (this.loadingModules.has(moduleName)) {
                return this.loadingModules.get(moduleName);
            }

            // 开始加载
            const loadPromise = new Promise((resolve, reject) => {
                // 确定模块路径
                // api -> weline-api.js, account -> weline-api-account.js
                let path = modulePath;
                if (!path) {
                    if (moduleName === 'api') {
                        path = `${runtimeConfig.modulesBaseUrl}.js`;
                    } else if (moduleName === 'account') {
                        path = `${runtimeConfig.modulesBaseUrl}-account.js`;
                    } else {
                        path = `${runtimeConfig.modulesBaseUrl}-${moduleName}.js`;
                    }
                }

                // 检查是否已经通过script标签加载（通过检查全局变量）
                const globalVarName = this.getGlobalVarName(moduleName);
                const requiresFullGlobal = globalVarName === 'WelineApiModule'
                    || globalVarName === 'WelineAccountModule'
                    || globalVarName === 'WelineTokenStorage';
                if (this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                    const module = window[globalVarName];
                    this.loadedModules.set(moduleName, module);
                    this.loadingModules.delete(moduleName);
                    resolve(module);
                    return;
                }

                // 动态加载脚本
                const script = document.createElement('script');
                script.src = this.getScriptUrl(path);
                script.async = true;
                script.crossOrigin = 'anonymous';

                script.onload = () => {
                    // 检查是否成功加载
                    if (this.isGlobalModuleReady(globalVarName, requiresFullGlobal)) {
                        const module = window[globalVarName];
                        this.loadedModules.set(moduleName, module);
                        this.loadingModules.delete(moduleName);
                        resolve(module);
                    } else {
                        this.loadingModules.delete(moduleName);
                        reject(new Error(`[Weline] ${__('模块 %{1} 加载失败：未找到 %{2}', { 1: moduleName, 2: globalVarName })}`));
                    }
                };

                script.onerror = () => {
                    this.loadingModules.delete(moduleName);
                    reject(new Error(`[Weline] ${__('模块 %{1} 加载失败：无法加载 %{2}', { 1: moduleName, 2: path })}`));
                };

                document.head.appendChild(script);
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
            // 将模块名转换为全局变量名
            // 例如: 'api' -> 'WelineApiModule', 'account' -> 'WelineAccountModule'
            const nameMap = {
                'api': 'WelineApiModule',
                'account': 'WelineAccountModule',
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
         * Api 模块代理
         */
        Api: {
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
                localStorage.setItem('weline_user_lang', lang);
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
                localStorage.setItem('weline_user_currency', currency);
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

    // Account 旧方法命名兼容
    const accountLegacyMap = {
        checkFrontendLogin: 'checkFrontendUserLogin',
        frontendLogin: 'frontendUserLogin',
        frontendLogout: 'frontendUserLogout',
        checkApiLogin: 'checkFrontendApiLogin',
        apiLogin: 'frontendApiLogin',
        apiLogout: 'frontendApiLogout',
        getApiUser: 'getFrontendApiUser',
        getApiToken: 'getFrontendApiToken',
    };
    Object.entries(accountLegacyMap).forEach(([oldName, newName]) => {
        if (typeof Weline.Account[newName] === 'function') {
            Weline.Account[oldName] = (...args) => {
                if (runtimeConfig.debug) {
                    console.warn(`[Weline] ${__('Weline.Account.%{1} 已弃用，请使用 %{2}', { 1: oldName, 2: newName })}`);
                }
                return Weline.Account[newName](...args);
            };
        }
    });

    // 兼容旧版本（可选）
    if (!window.WelineApi) {
        window.WelineApi = {
            call: (provider, operation, params, options) => Weline.Api.call(provider, operation, params, options),
            graph: (graph, options) => Weline.Api.graph(graph, options),
            stream: (channel, params, options) => Weline.Api.stream(channel, params, options),
            resource: (provider, optionalMap) => Weline.Api.resource(provider, optionalMap),
            request: () => Promise.reject(new Error('[WelineApi] direct request(url) is disabled. Use Weline.Api.resource()/call()/graph()/stream().')),
            account: Weline.Account,
        };
    }

    /**
     * 根据页面路径自动预加载模块
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

            // 开发模式下输出提示
            if (isDev) {
                console.log(
                    `%c[Weline] ${__('自动预加载模块')}`,
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
                            `%c[Weline] ${__('模块预加载完成')}`,
                            'color: #2196F3; font-weight: bold; font-size: 12px;',
                            `\n${__('模块')}: ${moduleList.join(', ')}`,
                            `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                        );
                    }
                })
                .catch((error) => {
                    if (isDev) {
                        console.warn(
                            `%c[Weline] ${__('模块预加载失败')}`,
                            'color: #FF9800; font-weight: bold; font-size: 12px;',
                            `\n${__('模块')}: ${moduleList.join(', ')}`,
                            `\n${__('错误')}: ${error.message}`,
                            `\n${__('时间')}: ${new Date().toLocaleTimeString()}`
                        );
                    }
                });
        } else if (isDev) {
            // 开发模式下，即使没有预加载也提示
            console.log(
                `%c[Weline] ${__('当前页面无需预加载模块')}`,
                'color: #9E9E9E; font-size: 11px;',
                `\n${__('页面')}: ${pathname}`
            );
        }
    })();

})(window, document);

