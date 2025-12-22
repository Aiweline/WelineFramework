/**
 * Weline Geo定位模块
 * 
 * 提供浏览器定位和IP定位功能
 * 支持位置缓存、错误处理和自动降级
 * 
 * @author Aiweline
 * @version 1.0.0
 */
(function(window) {
    'use strict';

    // 位置缓存
    const positionCache = {
        browser: null,
        ip: null,
        browserTimestamp: null,
        ipTimestamp: null,
        cacheDuration: 5 * 60 * 1000 // 5分钟缓存
    };

    // 监听器管理
    const watchers = new Map();
    let watchIdCounter = 0;

    /**
     * 翻译函数（使用Weline i18n系统）
     * @param {string} text - 要翻译的文本
     * @param {string|Object} params - 参数（可选）
     * @returns {string} 翻译后的文本
     */
    function __(text, params) {
        // 如果Weline i18n可用，使用Weline的翻译函数
        if (window.Weline && window.Weline.i18n && typeof window.Weline.i18n.__ === 'function') {
            return window.Weline.i18n.__(text, params);
        }
        // 如果全局__函数可用，使用全局函数
        if (typeof window.__ === 'function') {
            return window.__(text, params);
        }
        // 降级：如果有参数，进行简单的占位符替换
        if (params) {
            let result = text;
            if (typeof params === 'object' && !Array.isArray(params)) {
                // 命名参数：%{name}
                for (const key in params) {
                    result = result.replace(new RegExp('%\\{' + key + '\\}', 'g'), params[key]);
                }
            } else if (Array.isArray(params)) {
                // 位置参数：%{1}, %{2}
                params.forEach((param, index) => {
                    result = result.replace(new RegExp('%\\{' + (index + 1) + '\\}', 'g'), param);
                });
            } else {
                // 单个参数：%{1}
                result = result.replace(/%\{1\}/g, params);
            }
            return result;
        }
        // 无参数，直接返回原文
        return text;
    }

    /**
     * 检查浏览器是否支持Geolocation API
     */
    function isGeolocationSupported() {
        return 'geolocation' in navigator;
    }

    /**
     * 检查缓存是否有效
     */
    function isCacheValid(timestamp) {
        if (!timestamp) return false;
        return Date.now() - timestamp < positionCache.cacheDuration;
    }

    /**
     * 格式化位置数据
     */
    function formatPosition(position, source = 'browser') {
        return {
            latitude: position.coords?.latitude || position.latitude,
            longitude: position.coords?.longitude || position.longitude,
            accuracy: position.coords?.accuracy || position.accuracy || null,
            altitude: position.coords?.altitude || position.altitude || null,
            altitudeAccuracy: position.coords?.altitudeAccuracy || position.altitudeAccuracy || null,
            heading: position.coords?.heading || position.heading || null,
            speed: position.coords?.speed || position.speed || null,
            timestamp: position.timestamp || Date.now(),
            source: source
        };
    }

    /**
     * 获取浏览器当前位置
     * @param {Object} options - 定位选项
     * @param {boolean} options.enableHighAccuracy - 是否启用高精度
     * @param {number} options.timeout - 超时时间（毫秒）
     * @param {number} options.maximumAge - 最大缓存时间（毫秒）
     * @returns {Promise<Object>} 位置信息
     */
    function getCurrentPosition(options = {}) {
        return new Promise((resolve, reject) => {
            // 检查缓存
            if (isCacheValid(positionCache.browserTimestamp) && positionCache.browser) {
                resolve(positionCache.browser);
                return;
            }

            // 检查浏览器支持
            if (!isGeolocationSupported()) {
                reject(new Error(__('浏览器不支持Geolocation API')));
                return;
            }

            const defaultOptions = {
                enableHighAccuracy: false,
                timeout: 10000,
                maximumAge: 60000
            };

            const geolocationOptions = Object.assign({}, defaultOptions, options);

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const formattedPosition = formatPosition(position, 'browser');
                    // 更新缓存
                    positionCache.browser = formattedPosition;
                    positionCache.browserTimestamp = Date.now();
                    resolve(formattedPosition);
                },
                (error) => {
                    let errorMessage = __('定位失败');
                    switch (error.code) {
                        case error.PERMISSION_DENIED:
                            errorMessage = __('用户拒绝了定位权限');
                            break;
                        case error.POSITION_UNAVAILABLE:
                            errorMessage = __('位置信息不可用');
                            break;
                        case error.TIMEOUT:
                            errorMessage = __('定位请求超时');
                            break;
                    }
                    reject(new Error(errorMessage));
                },
                geolocationOptions
            );
        });
    }

    /**
     * 监听位置变化
     * @param {Function} callback - 位置变化回调函数
     * @param {Object} options - 定位选项
     * @returns {number} 监听器ID
     */
    function watchPosition(callback, options = {}) {
        if (!isGeolocationSupported()) {
            throw new Error(__('浏览器不支持Geolocation API'));
        }

        const watchId = ++watchIdCounter;
        const defaultOptions = {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 60000
        };

        const geolocationOptions = Object.assign({}, defaultOptions, options);

        const nativeWatchId = navigator.geolocation.watchPosition(
            (position) => {
                const formattedPosition = formatPosition(position, 'browser');
                // 更新缓存
                positionCache.browser = formattedPosition;
                positionCache.browserTimestamp = Date.now();
                if (callback) {
                    callback(formattedPosition);
                }
            },
            (error) => {
                let errorMessage = __('定位失败');
                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        errorMessage = __('用户拒绝了定位权限');
                        break;
                    case error.POSITION_UNAVAILABLE:
                        errorMessage = __('位置信息不可用');
                        break;
                    case error.TIMEOUT:
                        errorMessage = __('定位请求超时');
                        break;
                }
                if (callback) {
                    callback(null, new Error(errorMessage));
                }
            },
            geolocationOptions
        );

        watchers.set(watchId, nativeWatchId);
        return watchId;
    }

    /**
     * 清除位置监听
     * @param {number} watchId - 监听器ID
     */
    function clearWatch(watchId) {
        const nativeWatchId = watchers.get(watchId);
        if (nativeWatchId !== undefined) {
            navigator.geolocation.clearWatch(nativeWatchId);
            watchers.delete(watchId);
        }
    }

    /**
     * 通过IP地址获取位置信息
     * @returns {Promise<Object>} 位置信息
     */
    function getLocationByIP() {
        return new Promise((resolve, reject) => {
            // 检查缓存
            if (isCacheValid(positionCache.ipTimestamp) && positionCache.ip) {
                resolve(positionCache.ip);
                return;
            }

            // 调用后端API（后端会自动fallback到多个免费通道）
            const apiUrl = window.__WelineThemeConfig?.geo?.ipApiUrl || '/geo/rest/v1/frontend/location/ip';

            fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(__('IP定位服务响应错误'));
                }
                return response.json();
            })
            .then(response => {
                // 检查响应格式
                if (response.code !== 200 || !response.data) {
                    throw new Error(response.msg || __('IP定位失败'));
                }

                const data = response.data;
                
                // 格式化IP定位数据
                const position = {
                    latitude: data.latitude || null,
                    longitude: data.longitude || null,
                    accuracy: data.accuracy || null,
                    country: data.country || null,
                    countryCode: data.countryCode || null,
                    region: data.region || null,
                    city: data.city || null,
                    timezone: data.timezone || null,
                    timestamp: Date.now(),
                    source: data.source || 'ip'
                };

                // 更新缓存
                positionCache.ip = position;
                positionCache.ipTimestamp = Date.now();
                resolve(position);
            })
            .catch(error => {
                reject(new Error(__('IP定位服务不可用: %{1}', error.message)));
            });
        });
    }

    /**
     * 智能定位（自动选择最佳定位方式）
     * 优先使用浏览器定位，失败则使用IP定位
     * @param {Object} options - 定位选项
     * @returns {Promise<Object>} 位置信息
     */
    function getLocation(options = {}) {
        return getCurrentPosition(options)
            .catch(() => {
                // 浏览器定位失败，降级到IP定位
                return getLocationByIP();
            });
    }

    /**
     * 清除所有缓存
     */
    function clearCache() {
        positionCache.browser = null;
        positionCache.ip = null;
        positionCache.browserTimestamp = null;
        positionCache.ipTimestamp = null;
    }

    /**
     * 设置缓存时长
     * @param {number} duration - 缓存时长（毫秒）
     */
    function setCacheDuration(duration) {
        positionCache.cacheDuration = duration;
    }

    // 导出WelineGeo对象
    window.WelineGeo = {
        // 浏览器定位API
        getCurrentPosition: getCurrentPosition,
        watchPosition: watchPosition,
        clearWatch: clearWatch,
        
        // IP定位API
        getLocationByIP: getLocationByIP,
        
        // 智能定位（自动选择最佳方式）
        getLocation: getLocation,
        
        // 工具方法
        clearCache: clearCache,
        setCacheDuration: setCacheDuration,
        isGeolocationSupported: isGeolocationSupported,
        
        // 版本信息
        version: '1.0.0'
    };

})(window);

