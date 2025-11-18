/**
 * Weline Account Module
 * 账户管理模块 - 支持前端用户（session）和API用户（token）的登录状态管理
 * 
 * 可被 theme.js 按需加载的账户模块
 */
(function (window) {
    'use strict';

    // 加载Token存储管理器（如果可用）
    let TokenStorage = null;
    if (window.WelineTokenStorage) {
        TokenStorage = window.WelineTokenStorage;
    } else {
        // 尝试加载token存储模块
        const loadTokenStorage = () => {
            return new Promise((resolve) => {
                if (window.WelineTokenStorage) {
                    TokenStorage = window.WelineTokenStorage;
                    resolve();
                    return;
                }
                // 如果未加载，尝试动态加载
                const script = document.createElement('script');
                script.src = window.Weline?.staticResourceResolver?.resolve('Weline_Frontend::js/weline-api-token-storage.js') || '/static/Weline_Frontend/js/weline-api-token-storage.js';
                script.onload = () => {
                    if (window.WelineTokenStorage) {
                        TokenStorage = window.WelineTokenStorage;
                    }
                    resolve();
                };
                script.onerror = () => {
                    console.warn('[WelineApi.Account] Token存储模块加载失败，将使用localStorage');
                    resolve();
                };
                document.head.appendChild(script);
            });
        };
        // 延迟加载，不阻塞初始化
        loadTokenStorage();
    }

    // 获取全局配置
    const getGlobalConfig = () => {
        if (window.Weline && window.Weline.config) {
            return window.Weline.config;
        }
        if (window.__WelineThemeConfig) {
            return window.__WelineThemeConfig;
        }
        if (window.WelineConfig) {
            console.warn('[WelineApi.Account] window.WelineConfig 已废弃，请依赖 Theme.js 注入配置。');
            return window.WelineConfig;
        }
        return {};
    };

    // 获取账户配置
    const getAccountConfig = () => {
        const globalConfig = getGlobalConfig();
        if (globalConfig.account) {
            return globalConfig.account;
        }
        if (window.WelineApiConfig && window.WelineApiConfig.account) {
            console.warn('[WelineApi.Account] window.WelineApiConfig.account 已废弃，请使用 Theme.js 注入配置。');
            return window.WelineApiConfig.account;
        }
        return {};
    };

    /**
     * Account管理类
     * 支持前端用户（session）和API用户（token）的登录状态管理
     */
    class AccountManager {
        constructor(config) {
            // 从全局配置获取令牌刷新周期
            const globalConfig = getGlobalConfig();
            const apiConfig = globalConfig.api || {};
            const accountConfig = globalConfig.account || {};
            
            // URL 解析辅助函数
            const resolveUrl = (path, type = null) => {
                if (!path) return '';
                if (/^https?:\/\//i.test(path)) {
                    return path;
                }
                
                // 尝试使用 Weline.Url.resolve
                if (window.Weline && window.Weline.Url && typeof window.Weline.Url.resolve === 'function') {
                    try {
                        return window.Weline.Url.resolve(path, type ? { type } : {});
                    } catch (e) {
                        // 如果解析失败，使用原始路径
                    }
                }
                
                // Fallback: 直接返回绝对路径
                const normalizedOrigin = window.location.origin.replace(/\/+$/, '');
                const cleanPath = path.startsWith('/') ? path.slice(1) : path;
                return normalizedOrigin + '/' + cleanPath;
            };
            
            // 默认配置
            const defaultConfig = {
                frontendLoginUrl: '/frontend/account/login',
                frontendLogoutUrl: '/frontend/account/logout',
                frontendCheckLoginUrl: '/frontend/account/check-login',
                apiLoginUrl: '/api/rest/v1/auth/login',
                apiLogoutUrl: '/api/rest/v1/auth/logout',
                apiCheckLoginUrl: '/api/rest/v1/auth/check',
                apiRefreshUrl: '/api/rest/v1/auth/refresh',
                backendApiLoginUrl: '/api/rest/v1/backend/auth/login',
                backendApiLogoutUrl: '/api/rest/v1/backend/auth/logout',
                backendApiCheckLoginUrl: '/api/rest/v1/backend/auth/check',
                backendApiRefreshUrl: '/api/rest/v1/backend/auth/refresh',
                frontendTokenKey: 'weline_frontend_session',
                apiTokenKey: 'weline_api_access_token',
                apiRefreshTokenKey: 'weline_api_refresh_token',
                backendApiTokenKey: 'weline_backend_api_token',
                backendApiRefreshTokenKey: 'weline_backend_api_refresh_token',
                apiUserKey: 'weline_api_user',
                backendApiUserKey: 'weline_backend_api_user',
                // 令牌刷新配置（从全局配置读取）
                tokenRefreshPeriod: accountConfig.tokenRefreshPeriod || apiConfig.tokenRefreshPeriod || 300,
                tokenRefreshBeforeExpire: accountConfig.tokenRefreshBeforeExpire || apiConfig.tokenRefreshBeforeExpire || 300,
            };
            
            // 合并配置（外部传入的 config 优先）
            this.config = Object.assign({}, defaultConfig, accountConfig, config || {});
            
            // 自动解析 API URL（如果未在外部配置中解析）
            // 前端 API URL 使用 frontendApi 类型解析
            if (!this.config._urlsResolved) {
                this.config.apiLoginUrl = resolveUrl(this.config.apiLoginUrl, 'frontendApi');
                this.config.apiLogoutUrl = resolveUrl(this.config.apiLogoutUrl, 'frontendApi');
                this.config.apiCheckLoginUrl = resolveUrl(this.config.apiCheckLoginUrl, 'frontendApi');
                this.config.apiRefreshUrl = resolveUrl(this.config.apiRefreshUrl, 'frontendApi');
                
                // 后端 API URL 使用 backendApi 类型解析
                this.config.backendApiLoginUrl = resolveUrl(this.config.backendApiLoginUrl, 'backendApi');
                this.config.backendApiLogoutUrl = resolveUrl(this.config.backendApiLogoutUrl, 'backendApi');
                this.config.backendApiCheckLoginUrl = resolveUrl(this.config.backendApiCheckLoginUrl, 'backendApi');
                this.config.backendApiRefreshUrl = resolveUrl(this.config.backendApiRefreshUrl, 'backendApi');
                
                // 标记 URL 已解析
                this.config._urlsResolved = true;
            }

            this.frontendUser = null;
            this.apiUser = null;
            this.backendApiUser = null;
            this._checkingFrontend = false;
            this._checkingApi = false;
            this._checkingBackendApi = false;
            this._refreshTimers = new Map(); // 存储刷新定时器
        }

        /**
         * 检查前端用户登录状态（基于session）
         * @returns {Promise<{isLogin: boolean, user: object|null}>}
         */
        async checkFrontendUserLogin() {
            if (this._checkingFrontend) {
                return { isLogin: false, user: null };
            }

            this._checkingFrontend = true;
            try {
                // 尝试从session检查登录状态
                const response = await fetch(this.config.frontendCheckLoginUrl || '/frontend/account/check-login', {
                    method: 'GET',
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json',
                    }
                });

                if (response.ok) {
                    const result = await response.json();
                    if (result.success || (result.code === 200 && result.data && result.data.logged_in)) {
                        this.frontendUser = result.data?.user || result.user || null;
                        return { isLogin: true, user: this.frontendUser };
                    }
                }

                this.frontendUser = null;
                return { isLogin: false, user: null };
            } catch (error) {
                console.warn('[WelineApi.Account] 检查前端登录状态失败:', error);
                return { isLogin: false, user: null };
            } finally {
                this._checkingFrontend = false;
            }
        }

        /**
         * 检查前端API用户登录状态（基于token和过期时间）
         * @returns {Promise<{isLogin: boolean, user: object|null, expiresAt: number|null}>}
         */
        async checkFrontendApiLogin() {
            if (this._checkingApi) {
                return { isLogin: false, user: null, expiresAt: null };
            }

            this._checkingApi = true;
            try {
                // 优先从IndexedDB获取
                let tokenData = null;
                if (TokenStorage) {
                    try {
                        tokenData = await TokenStorage.getToken(this.config.apiTokenKey);
                        if (tokenData) {
                            // 检查是否过期
                            if (tokenData.expiresAt && tokenData.expiresAt < Date.now()) {
                                // 已过期，尝试刷新
                                const refreshed = await this._refreshToken('frontendApi');
                                if (refreshed) {
                                    tokenData = await TokenStorage.getToken(this.config.apiTokenKey);
                                } else {
                                    // 刷新失败，清除
                                    await this._clearApiToken('frontendApi');
                                    return { isLogin: false, user: null, expiresAt: null };
                                }
                            }
                            
                            if (tokenData) {
                                this.apiUser = tokenData.user;
                                return { 
                                    isLogin: true, 
                                    user: this.apiUser,
                                    expiresAt: tokenData.expiresAt
                                };
                            }
                        }
                    } catch (e) {
                        console.warn('[WelineApi.Account] IndexedDB读取失败，使用localStorage:', e);
                    }
                }

                // 回退到localStorage
                const token = localStorage.getItem(this.config.apiTokenKey);
                if (!token) {
                    this.apiUser = null;
                    return { isLogin: false, user: null, expiresAt: null };
                }

                // 检查过期时间
                const expiresAtStr = localStorage.getItem(this.config.apiTokenKey + '_expires_at');
                if (expiresAtStr) {
                    const expiresAt = parseInt(expiresAtStr, 10);
                    if (expiresAt < Date.now()) {
                        // 已过期，尝试刷新
                        const refreshed = await this._refreshToken('frontendApi');
                        if (!refreshed) {
                            await this._clearApiToken('frontendApi');
                            return { isLogin: false, user: null, expiresAt: null };
                        }
                    }
                }

                // 尝试从localStorage获取用户信息
                const userStr = localStorage.getItem(this.config.apiUserKey);
                if (userStr) {
                    try {
                        this.apiUser = JSON.parse(userStr);
                        return { 
                            isLogin: true, 
                            user: this.apiUser,
                            expiresAt: expiresAtStr ? parseInt(expiresAtStr, 10) : null
                        };
                    } catch (e) {
                        // JSON解析失败，清除无效数据
                        await this._clearApiToken('frontendApi');
                    }
                }

                // 如果没有用户信息，尝试调用检查接口验证token
                if (this.config.apiCheckLoginUrl) {
                    const response = await fetch(this.config.apiCheckLoginUrl, {
                        method: 'GET',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        }
                    });

                    if (response.ok) {
                        const result = await response.json();
                        if (result.code === 200 && result.data && result.data.logged_in) {
                            this.apiUser = result.data.user || null;
                            if (this.apiUser) {
                                localStorage.setItem(this.config.apiUserKey, JSON.stringify(this.apiUser));
                            }
                            return { 
                                isLogin: true, 
                                user: this.apiUser,
                                expiresAt: expiresAtStr ? parseInt(expiresAtStr, 10) : null
                            };
                        }
                    }
                }

                // Token无效，清除
                await this._clearApiToken('frontendApi');
                return { isLogin: false, user: null, expiresAt: null };
            } catch (error) {
                console.warn('[WelineApi.Account] 检查API登录状态失败:', error);
                return { isLogin: false, user: null, expiresAt: null };
            } finally {
                this._checkingApi = false;
            }
        }

        /**
         * 检查后端API用户登录状态（基于token和过期时间）
         * @returns {Promise<{isLogin: boolean, user: object|null, expiresAt: number|null}>}
         */
        async checkBackendApiLogin() {
            if (this._checkingBackendApi) {
                return { isLogin: false, user: null, expiresAt: null };
            }

            this._checkingBackendApi = true;
            try {
                // 优先从IndexedDB获取
                let tokenData = null;
                if (TokenStorage) {
                    try {
                        tokenData = await TokenStorage.getToken(this.config.backendApiTokenKey);
                        if (tokenData) {
                            // 检查是否过期
                            if (tokenData.expiresAt && tokenData.expiresAt < Date.now()) {
                                // 已过期，尝试刷新
                                const refreshed = await this._refreshToken('backendApi');
                                if (refreshed) {
                                    tokenData = await TokenStorage.getToken(this.config.backendApiTokenKey);
                                } else {
                                    // 刷新失败，清除
                                    await this._clearApiToken('backendApi');
                                    return { isLogin: false, user: null, expiresAt: null };
                                }
                            }
                            
                            if (tokenData) {
                                this.backendApiUser = tokenData.user;
                                return { 
                                    isLogin: true, 
                                    user: this.backendApiUser,
                                    expiresAt: tokenData.expiresAt
                                };
                            }
                        }
                    } catch (e) {
                        console.warn('[WelineApi.Account] IndexedDB读取失败，使用localStorage:', e);
                    }
                }

                // 回退到localStorage
                const token = localStorage.getItem(this.config.backendApiTokenKey);
                if (!token) {
                    this.backendApiUser = null;
                    return { isLogin: false, user: null, expiresAt: null };
                }

                // 检查过期时间
                const expiresAtStr = localStorage.getItem(this.config.backendApiTokenKey + '_expires_at');
                if (expiresAtStr) {
                    const expiresAt = parseInt(expiresAtStr, 10);
                    if (expiresAt < Date.now()) {
                        // 已过期，尝试刷新
                        const refreshed = await this._refreshToken('backendApi');
                        if (!refreshed) {
                            await this._clearApiToken('backendApi');
                            return { isLogin: false, user: null, expiresAt: null };
                        }
                    }
                }

                // 尝试从localStorage获取用户信息
                const userStr = localStorage.getItem(this.config.backendApiUserKey);
                if (userStr) {
                    try {
                        this.backendApiUser = JSON.parse(userStr);
                        return { 
                            isLogin: true, 
                            user: this.backendApiUser,
                            expiresAt: expiresAtStr ? parseInt(expiresAtStr, 10) : null
                        };
                    } catch (e) {
                        // JSON解析失败，清除无效数据
                        await this._clearApiToken('backendApi');
                    }
                }

                // 如果没有用户信息，尝试调用检查接口验证token
                if (this.config.backendApiCheckLoginUrl) {
                    const response = await fetch(this.config.backendApiCheckLoginUrl, {
                        method: 'GET',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        }
                    });

                    if (response.ok) {
                        const result = await response.json();
                        if (result.code === 200 && result.data && result.data.logged_in) {
                            this.backendApiUser = result.data.user || null;
                            if (this.backendApiUser) {
                                localStorage.setItem(this.config.backendApiUserKey, JSON.stringify(this.backendApiUser));
                            }
                            return { 
                                isLogin: true, 
                                user: this.backendApiUser,
                                expiresAt: expiresAtStr ? parseInt(expiresAtStr, 10) : null
                            };
                        }
                    }
                }

                // Token无效，清除
                await this._clearApiToken('backendApi');
                return { isLogin: false, user: null, expiresAt: null };
            } catch (error) {
                console.warn('[WelineApi.Account] 检查后端API登录状态失败:', error);
                return { isLogin: false, user: null, expiresAt: null };
            } finally {
                this._checkingBackendApi = false;
            }
        }

        /**
         * 前端用户登录（基于session）
         * @param {string} username 用户名
         * @param {string} password 密码
         * @param {number} rememberDuration 记住我时长（秒），0表示不记住
         * @returns {Promise<{success: boolean, message: string, user: object|null, redirect: string|null}>}
         */
        async frontendUserLogin(username, password, rememberDuration = 0) {
            try {
                const response = await fetch(this.config.frontendLoginUrl, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        username,
                        password,
                        remember_duration: rememberDuration
                    })
                });

                const result = await response.json();

                if (result.success || (result.code === 200 && result.data)) {
                    this.frontendUser = result.data?.user || result.user || null;
                    window.dispatchEvent(new CustomEvent('weline:account:frontend:login', {
                        detail: { user: this.frontendUser }
                    }));
                    return {
                        success: true,
                        message: result.message || '登录成功',
                        user: this.frontendUser,
                        redirect: result.redirect || result.data?.redirect || null
                    };
                }

                return {
                    success: false,
                    message: result.message || result.msg || '登录失败',
                    user: null,
                    redirect: null
                };
            } catch (error) {
                console.error('[WelineApi.Account] 前端登录失败:', error);
                return {
                    success: false,
                    message: '登录请求失败：' + error.message,
                    user: null,
                    redirect: null
                };
            }
        }

        /**
         * 前端API用户登录（基于token）
         * @param {string} username 用户名
         * @param {string} password 密码
         * @param {number} expireTime Token过期时间（时间戳），0表示使用默认
         * @returns {Promise<{success: boolean, message: string, user: object|null, token: string|null}>}
         */
        async frontendApiLogin(username, password, expireTime = 0) {
            try {
                const response = await fetch(this.config.apiLoginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        username,
                        password,
                        expire_time: expireTime
                    })
                });

                const result = await response.json();

                if (result.code === 200 && result.data) {
                    const token = result.data.access_token || result.data.token;
                    const refreshToken = result.data.refresh_token;
                    const expiresIn = result.data.expires_in || result.data.expiresIn || 3600; // 默认1小时
                    this.apiUser = result.data.user || null;

                    if (token) {
                        // 优先使用IndexedDB存储（更安全）
                        if (TokenStorage) {
                            try {
                                await TokenStorage.saveToken(
                                    this.config.apiTokenKey,
                                    token,
                                    refreshToken,
                                    expiresIn,
                                    this.apiUser,
                                    'frontendApi'
                                );
                            } catch (e) {
                                console.warn('[WelineApi.Account] IndexedDB存储失败，使用localStorage:', e);
                            }
                        }
                        
                        // 同时保存到localStorage（向后兼容）
                        localStorage.setItem(this.config.apiTokenKey, token);
                        if (refreshToken) {
                            localStorage.setItem(this.config.apiRefreshTokenKey, refreshToken);
                        }
                        if (this.apiUser) {
                            localStorage.setItem(this.config.apiUserKey, JSON.stringify(this.apiUser));
                        }
                        // 保存过期时间戳
                        const expiresAt = Date.now() + (expiresIn * 1000);
                        localStorage.setItem(this.config.apiTokenKey + '_expires_at', expiresAt.toString());

                        // 启动自动刷新
                        this._startTokenRefresh('frontendApi', expiresIn, refreshToken);

                        window.dispatchEvent(new CustomEvent('weline:account:api:login', {
                            detail: { 
                                user: this.apiUser, 
                                token: token,
                                expiresIn: expiresIn,
                                expiresAt: expiresAt
                            }
                        }));

                        return {
                            success: true,
                            message: result.msg || result.message || '登录成功',
                            user: this.apiUser,
                            token: token,
                            expiresIn: expiresIn,
                            expiresAt: expiresAt
                        };
                    }
                }

                return {
                    success: false,
                    message: result.msg || result.message || '登录失败',
                    user: null,
                    token: null
                };
            } catch (error) {
                console.error('[WelineApi.Account] API登录失败:', error);
                return {
                    success: false,
                    message: '登录请求失败：' + error.message,
                    user: null,
                    token: null
                };
            }
        }

        /**
         * 后端API用户登录（基于token）
         * @param {string} username 用户名
         * @param {string} password 密码
         * @param {number} expireTime Token过期时间（时间戳），0表示使用默认
         * @returns {Promise<{success: boolean, message: string, user: object|null, token: string|null}>}
         */
        async backendApiLogin(username, password, expireTime = 0) {
            try {
                const response = await fetch(this.config.backendApiLoginUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        username,
                        password,
                        expire_time: expireTime
                    })
                });

                const result = await response.json();

                if (result.code === 200 && result.data) {
                    const token = result.data.token || result.data.access_token;
                    const refreshToken = result.data.refresh_token;
                    const expiresIn = result.data.expires_in || result.data.expiresIn || 3600; // 默认1小时
                    this.backendApiUser = result.data.user || null;

                    if (token) {
                        // 优先使用IndexedDB存储（更安全）
                        if (TokenStorage) {
                            try {
                                await TokenStorage.saveToken(
                                    this.config.backendApiTokenKey,
                                    token,
                                    refreshToken,
                                    expiresIn,
                                    this.backendApiUser,
                                    'backendApi'
                                );
                            } catch (e) {
                                console.warn('[WelineApi.Account] IndexedDB存储失败，使用localStorage:', e);
                            }
                        }
                        
                        // 同时保存到localStorage（向后兼容）
                        localStorage.setItem(this.config.backendApiTokenKey, token);
                        if (refreshToken) {
                            localStorage.setItem(this.config.backendApiRefreshTokenKey, refreshToken);
                        }
                        if (this.backendApiUser) {
                            localStorage.setItem(this.config.backendApiUserKey, JSON.stringify(this.backendApiUser));
                        }
                        // 保存过期时间戳
                        const expiresAt = Date.now() + (expiresIn * 1000);
                        localStorage.setItem(this.config.backendApiTokenKey + '_expires_at', expiresAt.toString());

                        // 启动自动刷新
                        this._startTokenRefresh('backendApi', expiresIn, refreshToken);

                        window.dispatchEvent(new CustomEvent('weline:account:backend-api:login', {
                            detail: { 
                                user: this.backendApiUser, 
                                token: token,
                                expiresIn: expiresIn,
                                expiresAt: expiresAt
                            }
                        }));

                        return {
                            success: true,
                            message: result.msg || result.message || '登录成功',
                            user: this.backendApiUser,
                            token: token,
                            expiresIn: expiresIn,
                            expiresAt: expiresAt
                        };
                    }
                }

                return {
                    success: false,
                    message: result.msg || result.message || '登录失败',
                    user: null,
                    token: null
                };
            } catch (error) {
                console.error('[WelineApi.Account] 后端API登录失败:', error);
                return {
                    success: false,
                    message: '登录请求失败：' + error.message,
                    user: null,
                    token: null
                };
            }
        }

        /**
         * 前端用户登出
         * @returns {Promise<{success: boolean, message: string}>}
         */
        async frontendUserLogout() {
            try {
                const response = await fetch(this.config.frontendLogoutUrl || '/frontend/account/logout', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json',
                    }
                });

                this.frontendUser = null;
                window.dispatchEvent(new CustomEvent('weline:account:frontend:logout'));

                return {
                    success: true,
                    message: '登出成功'
                };
            } catch (error) {
                console.error('[WelineApi.Account] 前端登出失败:', error);
                // 即使请求失败，也清除本地状态
                this.frontendUser = null;
                window.dispatchEvent(new CustomEvent('weline:account:frontend:logout'));
                return {
                    success: true,
                    message: '登出成功（本地状态已清除）'
                };
            }
        }

        /**
         * 前端API用户登出
         * @returns {Promise<{success: boolean, message: string}>}
         */
        async frontendApiLogout() {
            try {
                const token = localStorage.getItem(this.config.apiTokenKey);
                if (token && this.config.apiLogoutUrl) {
                    await fetch(this.config.apiLogoutUrl, {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        }
                    });
                }
            } catch (error) {
                console.warn('[WelineApi.Account] API登出请求失败:', error);
            } finally {
                // 清除令牌（包括IndexedDB和localStorage）
                await this._clearApiToken('frontendApi');
                window.dispatchEvent(new CustomEvent('weline:account:api:logout'));
            }

            return {
                success: true,
                message: '登出成功'
            };
        }

        /**
         * 后端API用户登出
         * @returns {Promise<{success: boolean, message: string}>}
         */
        async backendApiLogout() {
            try {
                const token = localStorage.getItem(this.config.backendApiTokenKey);
                if (token && this.config.backendApiLogoutUrl) {
                    await fetch(this.config.backendApiLogoutUrl, {
                        method: 'POST',
                        headers: {
                            'Authorization': `Bearer ${token}`,
                            'Accept': 'application/json',
                        }
                    });
                }
            } catch (error) {
                console.warn('[WelineApi.Account] 后端API登出请求失败:', error);
            } finally {
                // 清除令牌（包括IndexedDB和localStorage）
                await this._clearApiToken('backendApi');
                window.dispatchEvent(new CustomEvent('weline:account:backend-api:logout'));
            }

            return {
                success: true,
                message: '登出成功'
            };
        }

        /**
         * 获取前端用户信息（如果已登录）
         * @returns {object|null}
         */
        getFrontendUser() {
            return this.frontendUser;
        }

        /**
         * 获取前端API用户信息（如果已登录）
         * @returns {object|null}
         */
        getFrontendApiUser() {
            return this.apiUser;
        }

        /**
         * 获取后端API用户信息（如果已登录）
         * @returns {object|null}
         */
        getBackendApiUser() {
            return this.backendApiUser;
        }

        /**
         * 获取前端API token（如果已登录）
         * @returns {string|null}
         */
        getFrontendApiToken() {
            return localStorage.getItem(this.config.apiTokenKey);
        }

        /**
         * 获取后端API token（如果已登录）
         * @returns {string|null}
         */
        getBackendApiToken() {
            return localStorage.getItem(this.config.backendApiTokenKey);
        }

        /**
         * 启动令牌自动刷新
         * @param {string} type 令牌类型（frontendApi|backendApi）
         * @param {number} expiresIn 过期时间（秒）
         * @param {string} refreshToken 刷新令牌
         */
        _startTokenRefresh(type, expiresIn, refreshToken) {
            if (!refreshToken) {
                return; // 没有刷新令牌，不启动自动刷新
            }

            // 清除旧的定时器
            const timerKey = type;
            if (this._refreshTimers.has(timerKey)) {
                clearTimeout(this._refreshTimers.get(timerKey));
            }

            // 从配置中获取刷新周期和过期前刷新时间
            const globalConfig = getGlobalConfig();
            const apiConfig = globalConfig.api || {};
            const refreshPeriod = this.config.tokenRefreshPeriod || apiConfig.tokenRefreshPeriod || 300; // 默认5分钟
            const refreshBeforeExpire = this.config.tokenRefreshBeforeExpire || apiConfig.tokenRefreshBeforeExpire || 60; // 默认过期前60秒
            
            // 计算刷新延迟：确保在过期前刷新
            // 1. 如果过期时间足够长，使用刷新周期
            // 2. 如果过期时间较短，确保在过期前指定时间刷新
            // 3. 取两者较小值，但至少要在过期前刷新
            const refreshByPeriod = refreshPeriod * 1000;
            const refreshByExpire = Math.max(0, (expiresIn - refreshBeforeExpire) * 1000);
            
            // 如果过期时间太短（小于刷新周期+过期前时间），则使用过期前时间的一半
            let refreshDelay;
            if (expiresIn * 1000 < refreshByPeriod + refreshBeforeExpire * 1000) {
                // 过期时间太短，在过期前一半时间刷新
                refreshDelay = Math.max(1000, (expiresIn * 1000) / 2);
            } else {
                // 正常情况：取刷新周期和过期前刷新时间的较小值
                refreshDelay = Math.min(refreshByPeriod, refreshByExpire || refreshByPeriod);
            }
            
            const timer = setTimeout(async () => {
                try {
                    await this._refreshToken(type);
                    // 刷新成功后，重新启动定时器
                    const tokenData = type === 'frontendApi' 
                        ? await TokenStorage?.getToken(this.config.apiTokenKey)
                        : await TokenStorage?.getToken(this.config.backendApiTokenKey);
                    if (tokenData && tokenData.refreshToken) {
                        const newExpiresIn = tokenData.expiresAt 
                            ? Math.floor((tokenData.expiresAt - Date.now()) / 1000)
                            : expiresIn;
                        this._startTokenRefresh(type, newExpiresIn, tokenData.refreshToken);
                    }
                } catch (error) {
                    console.error(`[WelineApi.Account] ${type}令牌自动刷新失败:`, error);
                }
            }, refreshDelay);

            this._refreshTimers.set(timerKey, timer);
        }

        /**
         * 刷新令牌
         * @param {string} type 令牌类型（frontendApi|backendApi）
         * @returns {Promise<boolean>} 是否刷新成功
         */
        async _refreshToken(type) {
            try {
                const isFrontend = type === 'frontendApi';
                const refreshTokenKey = isFrontend 
                    ? this.config.apiRefreshTokenKey 
                    : this.config.backendApiRefreshTokenKey;
                const refreshUrl = isFrontend 
                    ? this.config.apiRefreshUrl 
                    : this.config.backendApiRefreshUrl;
                const tokenKey = isFrontend 
                    ? this.config.apiTokenKey 
                    : this.config.backendApiTokenKey;

                if (!refreshUrl) {
                    console.warn(`[WelineApi.Account] ${type}未配置刷新URL`);
                    return false;
                }

                // 获取刷新令牌
                let refreshToken = null;
                if (TokenStorage) {
                    try {
                        const tokenData = await TokenStorage.getToken(tokenKey);
                        refreshToken = tokenData?.refreshToken;
                    } catch (e) {
                        // IndexedDB获取失败，尝试localStorage
                    }
                }
                
                if (!refreshToken) {
                    refreshToken = localStorage.getItem(refreshTokenKey);
                }

                if (!refreshToken) {
                    return false;
                }

                // 调用刷新接口（在worker中执行，更安全）
                const response = await fetch(refreshUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        refresh_token: refreshToken
                    })
                });

                const result = await response.json();

                if (result.code === 200 && result.data) {
                    const newToken = result.data.access_token || result.data.token;
                    const newRefreshToken = result.data.refresh_token || refreshToken;
                    const expiresIn = result.data.expires_in || result.data.expiresIn || 3600;

                    if (newToken) {
                        // 更新存储
                        if (TokenStorage) {
                            try {
                                const tokenData = await TokenStorage.getToken(tokenKey);
                                if (tokenData) {
                                    await TokenStorage.updateToken(tokenKey, newToken, expiresIn);
                                    if (newRefreshToken !== refreshToken) {
                                        // 如果返回了新的刷新令牌，需要更新
                                        await TokenStorage.saveToken(
                                            tokenKey,
                                            newToken,
                                            newRefreshToken,
                                            expiresIn,
                                            tokenData.user,
                                            type
                                        );
                                    }
                                }
                            } catch (e) {
                                console.warn(`[WelineApi.Account] IndexedDB更新失败:`, e);
                            }
                        }

                        // 更新localStorage
                        localStorage.setItem(tokenKey, newToken);
                        if (newRefreshToken !== refreshToken) {
                            localStorage.setItem(refreshTokenKey, newRefreshToken);
                        }
                        const expiresAt = Date.now() + (expiresIn * 1000);
                        localStorage.setItem(tokenKey + '_expires_at', expiresAt.toString());

                        // 触发刷新事件
                        window.dispatchEvent(new CustomEvent(`weline:account:${type}:token-refreshed`, {
                            detail: { 
                                token: newToken,
                                expiresIn: expiresIn,
                                expiresAt: expiresAt
                            }
                        }));

                        return true;
                    }
                }

                return false;
            } catch (error) {
                console.error(`[WelineApi.Account] ${type}令牌刷新失败:`, error);
                return false;
            }
        }

        /**
         * 清除API令牌
         * @param {string} type 令牌类型（frontendApi|backendApi）
         */
        async _clearApiToken(type) {
            const isFrontend = type === 'frontendApi';
            const tokenKey = isFrontend 
                ? this.config.apiTokenKey 
                : this.config.backendApiTokenKey;
            const refreshTokenKey = isFrontend 
                ? this.config.apiRefreshTokenKey 
                : this.config.backendApiRefreshTokenKey;
            const userKey = isFrontend 
                ? this.config.apiUserKey 
                : this.config.backendApiUserKey;

            // 清除IndexedDB
            if (TokenStorage) {
                try {
                    await TokenStorage.deleteToken(tokenKey);
                } catch (e) {
                    // 忽略错误
                }
            }

            // 清除localStorage
            localStorage.removeItem(tokenKey);
            localStorage.removeItem(refreshTokenKey);
            localStorage.removeItem(userKey);
            localStorage.removeItem(tokenKey + '_expires_at');

            // 清除定时器
            if (this._refreshTimers.has(type)) {
                clearTimeout(this._refreshTimers.get(type));
                this._refreshTimers.delete(type);
            }

            // 清除内存中的用户信息
            if (isFrontend) {
                this.apiUser = null;
            } else {
                this.backendApiUser = null;
            }
        }
    }

    // 创建实例并导出模块接口
    const accountConfig = getAccountConfig();
    const accountManager = new AccountManager(accountConfig);

    const AccountModule = {
        _instance: accountManager,
        // 前端用户（session）
        checkFrontendUserLogin: () => accountManager.checkFrontendUserLogin(),
        frontendUserLogin: (username, password, rememberDuration) => accountManager.frontendUserLogin(username, password, rememberDuration),
        frontendUserLogout: () => accountManager.frontendUserLogout(),
        getFrontendUser: () => accountManager.getFrontendUser(),
        // 前端API用户（token）
        checkFrontendApiLogin: () => accountManager.checkFrontendApiLogin(),
        frontendApiLogin: (username, password, expireTime) => accountManager.frontendApiLogin(username, password, expireTime),
        frontendApiLogout: () => accountManager.frontendApiLogout(),
        getFrontendApiUser: () => accountManager.getFrontendApiUser(),
        getFrontendApiToken: () => accountManager.getFrontendApiToken(),
        // 后端API用户（token）
        checkBackendApiLogin: () => accountManager.checkBackendApiLogin(),
        backendApiLogin: (username, password, expireTime) => accountManager.backendApiLogin(username, password, expireTime),
        backendApiLogout: () => accountManager.backendApiLogout(),
        getBackendApiUser: () => accountManager.getBackendApiUser(),
        getBackendApiToken: () => accountManager.getBackendApiToken(),
    };

    // 兼容旧方法命名
    AccountModule.checkFrontendLogin = (...args) => {
        console.warn('[WelineAccountModule] checkFrontendLogin 已弃用，请使用 checkFrontendUserLogin');
        return AccountModule.checkFrontendUserLogin(...args);
    };
    AccountModule.frontendLogin = (...args) => {
        console.warn('[WelineAccountModule] frontendLogin 已弃用，请使用 frontendUserLogin');
        return AccountModule.frontendUserLogin(...args);
    };
    AccountModule.frontendLogout = (...args) => {
        console.warn('[WelineAccountModule] frontendLogout 已弃用，请使用 frontendUserLogout');
        return AccountModule.frontendUserLogout(...args);
    };
    AccountModule.checkApiLogin = (...args) => {
        console.warn('[WelineAccountModule] checkApiLogin 已弃用，请使用 checkFrontendApiLogin');
        return AccountModule.checkFrontendApiLogin(...args);
    };
    AccountModule.apiLogin = (...args) => {
        console.warn('[WelineAccountModule] apiLogin 已弃用，请使用 frontendApiLogin');
        return AccountModule.frontendApiLogin(...args);
    };
    AccountModule.apiLogout = (...args) => {
        console.warn('[WelineAccountModule] apiLogout 已弃用，请使用 frontendApiLogout');
        return AccountModule.frontendApiLogout(...args);
    };
    AccountModule.getApiUser = (...args) => {
        console.warn('[WelineAccountModule] getApiUser 已弃用，请使用 getFrontendApiUser');
        return AccountModule.getFrontendApiUser(...args);
    };
    AccountModule.getApiToken = (...args) => {
        console.warn('[WelineAccountModule] getApiToken 已弃用，请使用 getFrontendApiToken');
        return AccountModule.getFrontendApiToken(...args);
    };

    // 挂载到全局，供 theme.js 加载
    window.WelineAccountModule = AccountModule;
})(window);

