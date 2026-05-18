/**
 * Shipping模块 - 配送地址管理
 * 
 * 管理配送地址的cookie和浏览器存储
 * 根据配送区域调用接口获取配送信息并存储到IndexedDB
 * 
 * @author Aiweline
 * @version 2.0.0
 */
(function(window, document) {
    'use strict';
    
    // 存储键名
    const COOKIE_KEY = 'weline_delivery_location';
    const STORAGE_KEY = 'weline_delivery_location';
    const SYNC_FLAG_KEY = 'weline_delivery_location_synced';
    let deliveryAddressApiPromise = null;
    const MANUAL_SELECT_FLAG_KEY = 'weline_shipping_address_manual_selected'; // 用户是否手动选择过地址
    
    /**
     * 检查用户是否登录
     */
    function isLoggedIn() {
        try {
            if (typeof Weline !== 'undefined' && Weline.Account) {
                const user = Weline.Account.getFrontendApiUser();
                return user !== null && user !== undefined;
            }
            // 降级方案：检查session
            return document.cookie.indexOf('w_ut') !== -1;
        } catch (e) {
            return false;
        }
    }
    
    /**
     * 保存地址到浏览器localStorage
     */
    function saveToBrowser(address) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(address));
            localStorage.setItem(SYNC_FLAG_KEY, 'false'); // 标记为未同步
        } catch (e) {
            console.error('保存地址到浏览器失败:', e);
        }
    }
    
    /**
     * 从浏览器localStorage读取地址
     */
    function loadFromBrowser() {
        try {
            const data = localStorage.getItem(STORAGE_KEY);
            if (data) {
                return JSON.parse(data);
            }
        } catch (e) {
            console.error('从浏览器读取地址失败:', e);
        }
        return null;
    }
    
    /**
     * 清除浏览器存储的地址
     */
    function clearBrowserStorage() {
        try {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.removeItem(SYNC_FLAG_KEY);
            localStorage.removeItem(MANUAL_SELECT_FLAG_KEY);
        } catch (e) {
            console.error('清除浏览器存储失败:', e);
        }
    }
    
    /**
     * 检查用户是否手动选择过地址
     */
    function isManualSelected() {
        try {
            return localStorage.getItem(MANUAL_SELECT_FLAG_KEY) === 'true';
        } catch (e) {
            return false;
        }
    }
    
    /**
     * 设置手动选择标志
     */
    function setManualSelected(selected = true) {
        try {
            localStorage.setItem(MANUAL_SELECT_FLAG_KEY, selected ? 'true' : 'false');
        } catch (e) {
            console.error('设置手动选择标志失败:', e);
        }
    }

    function getDeliveryAddressApi() {
        if (!deliveryAddressApiPromise) {
            deliveryAddressApiPromise = window.Weline && window.Weline.Api
                ? window.Weline.Api.resource('deliveryAddress')
                : Promise.reject(new Error('Weline.Api is unavailable.'));
        }
        return deliveryAddressApiPromise;
    }
    
    /**
     * 更新地址到session（通过API）
     */
    async function updateToSession(address) {
        try {
            const DeliveryAddressApi = await getDeliveryAddressApi();
            const result = await DeliveryAddressApi.updateSession(address, {silent: true});
            if (!result || result.success === false) {
                throw new Error((result && (result.message || result.msg)) || '更新session失败');
            }
            
            return result.data;
        } catch (e) {
            console.error('更新地址到session失败:', e);
            throw e;
        }
    }
    
    /**
     * 同步浏览器存储的地址到session（登录后调用）
     */
    async function syncFromBrowserToSession() {
        try {
            // 检查是否已同步
            const synced = localStorage.getItem(SYNC_FLAG_KEY);
            if (synced === 'true') {
                return; // 已同步，跳过
            }
            
            const address = loadFromBrowser();
            if (!address) {
                return; // 没有存储的地址
            }
            
            // 调用 worker API 同步
            const DeliveryAddressApi = await getDeliveryAddressApi();
            const result = await DeliveryAddressApi.syncFromBrowser({ address: address }, {silent: true});
            if (!result || result.success === false) {
                throw new Error((result && (result.message || result.msg)) || '同步失败');
            }
            
            // 标记为已同步
            localStorage.setItem(SYNC_FLAG_KEY, 'true');
            
            console.log('配送地址已同步到session');
        } catch (e) {
            console.error('同步浏览器地址到session失败:', e);
        }
    }
    
    /**
     * 处理地址更新
     * @param {Object} addressData - 地址数据
     * @param {boolean} isManual - 是否是手动选择（默认false，表示自动定位）
     */
    async function handleAddressUpdate(addressData, isManual = false) {
        try {
            // 如果是手动选择，设置标志
            if (isManual) {
                setManualSelected(true);
            }
            
            // 保存到浏览器（无论是否登录）
            saveToBrowser(addressData);
            
            // 如果已登录，同步到session
            if (isLoggedIn()) {
                await updateToSession(addressData);
                localStorage.setItem(SYNC_FLAG_KEY, 'true');
            } else {
                localStorage.setItem(SYNC_FLAG_KEY, 'false');
            }
        } catch (e) {
            console.error('处理地址更新失败:', e);
        }
    }
    
    /**
     * 从cookie或localStorage读取位置信息
     */
    function loadLocationFromStorage() {
        try {
            // 优先从cookie读取
            const cookies = document.cookie.split(';');
            for (let cookie of cookies) {
                cookie = cookie.trim();
                if (cookie.startsWith(COOKIE_KEY + '=')) {
                    const value = decodeURIComponent(cookie.substring(COOKIE_KEY.length + 1));
                    return JSON.parse(value);
                }
            }
            
            // 从localStorage读取
            const data = localStorage.getItem(STORAGE_KEY);
            if (data) {
                return JSON.parse(data);
            }
        } catch (e) {
            console.error('读取位置信息失败:', e);
        }
        return null;
    }
    
    /**
     * 保存位置信息到cookie和localStorage
     */
    function saveLocationToStorage(location) {
        try {
            const value = JSON.stringify(location);
            
            // 保存到cookie（30天过期）
            const expires = new Date();
            expires.setTime(expires.getTime() + 30 * 24 * 60 * 60 * 1000);
            document.cookie = `${COOKIE_KEY}=${encodeURIComponent(value)};expires=${expires.toUTCString()};path=/`;
            
            // 保存到localStorage
            localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            console.error('保存位置信息失败:', e);
        }
    }
    
    /**
     * 调用配送接口获取配送信息
     */
    async function fetchShippingInfo(location) {
        try {
            // 检查IndexedDB中是否有缓存，且是否需要更新
            if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                const shouldUpdate = await window.WelineShipping.IndexedDB.shouldUpdate(location);
                if (!shouldUpdate) {
                    // 使用缓存
                    const cachedInfo = await window.WelineShipping.IndexedDB.getShippingInfo(location);
                    if (cachedInfo) {
                        console.log('使用缓存的配送信息');
                        return cachedInfo;
                    }
                }
            }
            
            // 调用 worker API 获取配送信息
            const DeliveryAddressApi = await getDeliveryAddressApi();
            const result = await DeliveryAddressApi.shippingInfoByLocation({
                country_code: location.countryCode || location.country_code || 'CN',
                province: location.province || '',
                city: location.city || '',
                district: location.district || ''
            }, {silent: true});
            if (!result || result.success === false) {
                throw new Error((result && (result.message || result.msg)) || '获取配送信息失败');
            }
            
            const shippingInfo = result.data;
            
            // 保存到IndexedDB
            if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                await window.WelineShipping.IndexedDB.saveShippingInfo(location, shippingInfo);
            }
            
            return shippingInfo;
        } catch (e) {
            console.error('获取配送信息失败:', e);
            // 尝试从IndexedDB获取缓存
            if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                return await window.WelineShipping.IndexedDB.getShippingInfo(location);
            }
            return null;
        }
    }
    
    /**
     * 初始化
     */
    function init() {
        // 页面加载时，从cookie/localStorage读取位置信息
        // 如果有位置信息，检查是否需要更新配送信息
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', async function() {
                const location = loadLocationFromStorage();
                if (location) {
                    // 检查是否需要更新配送信息
                    if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                        const shouldUpdate = await window.WelineShipping.IndexedDB.shouldUpdate(location);
                        if (shouldUpdate) {
                            // 异步更新配送信息（不阻塞页面）
                            fetchShippingInfo(location).catch(e => {
                                console.error('更新配送信息失败:', e);
                            });
                        }
                    }
                }
            });
        } else {
            const location = loadLocationFromStorage();
            if (location && window.WelineShipping && window.WelineShipping.IndexedDB) {
                window.WelineShipping.IndexedDB.shouldUpdate(location).then(shouldUpdate => {
                    if (shouldUpdate) {
                        fetchShippingInfo(location).catch(e => {
                            console.error('更新配送信息失败:', e);
                        });
                    }
                });
            }
        }
    }
    
    /**
     * 构建完整地址字符串
     */
    function buildFullAddress(address) {
        const parts = [
            address.country || '',
            address.province || address.region || '',
            address.city || '',
            address.district || '',
            address.street || '',
        ].filter(part => part);
        return parts.join('');
    }
    
    // 初始化
    init();
    
    // 导出API
    if (typeof window.WelineShipping === 'undefined') {
        window.WelineShipping = {};
    }
    
    window.WelineShipping.Location = {
        load: loadLocationFromStorage,
        save: saveLocationToStorage,
        fetchShippingInfo: fetchShippingInfo,
        isManualSelected: isManualSelected,
        setManualSelected: setManualSelected,
    };
    
})(window, document);
