/**
 * Shipping模块 - IndexedDB存储和配送费计算
 * 
 * 管理配送信息的IndexedDB存储，提供配送费计算功能
 * 避免重复请求，直接从缓存的配送区域价格计算配送费
 * 
 * @author Aiweline
 * @version 1.0.0
 */
(function(window, document) {
    'use strict';
    
    // IndexedDB数据库配置
    const DB_NAME = 'WelineShipping';
    const DB_VERSION = 1;
    const STORE_SHIPPING_INFO = 'shipping_info';
    const STORE_PRICE_RULES = 'price_rules';
    
    let db = null;
    
    /**
     * 初始化IndexedDB
     */
    function initIndexedDB() {
        return new Promise((resolve, reject) => {
            if (db) {
                resolve(db);
                return;
            }
            
            if (!window.indexedDB) {
                reject(new Error('IndexedDB不支持'));
                return;
            }
            
            const request = indexedDB.open(DB_NAME, DB_VERSION);
            
            request.onerror = function() {
                reject(new Error('打开IndexedDB失败'));
            };
            
            request.onsuccess = function() {
                db = request.result;
                resolve(db);
            };
            
            request.onupgradeneeded = function(event) {
                const db = event.target.result;
                
                // 创建配送信息存储
                if (!db.objectStoreNames.contains(STORE_SHIPPING_INFO)) {
                    const shippingInfoStore = db.createObjectStore(STORE_SHIPPING_INFO, { keyPath: 'location_key' });
                    shippingInfoStore.createIndex('location_key', 'location_key', { unique: true });
                    shippingInfoStore.createIndex('updated_at', 'updated_at', { unique: false });
                }
                
                // 创建价格规则存储
                if (!db.objectStoreNames.contains(STORE_PRICE_RULES)) {
                    const priceRulesStore = db.createObjectStore(STORE_PRICE_RULES, { keyPath: 'service_id' });
                    priceRulesStore.createIndex('service_id', 'service_id', { unique: true });
                    priceRulesStore.createIndex('updated_at', 'updated_at', { unique: false });
                }
            };
        });
    }
    
    /**
     * 生成位置键
     */
    function generateLocationKey(location) {
        const parts = [
            location.country_code || location.country || '',
            location.province || '',
            location.city || '',
            location.district || '',
        ].filter(p => p);
        return parts.join('|');
    }
    
    /**
     * 保存配送信息到IndexedDB
     */
    async function saveShippingInfoToIndexedDB(location, shippingInfo) {
        try {
            await initIndexedDB();
            
            const locationKey = generateLocationKey(location);
            const transaction = db.transaction([STORE_SHIPPING_INFO], 'readwrite');
            const store = transaction.objectStore(STORE_SHIPPING_INFO);
            
            const data = {
                location_key: locationKey,
                location: location,
                shipping_info: shippingInfo,
                updated_at: new Date().toISOString(),
            };
            
            await new Promise((resolve, reject) => {
                const request = store.put(data);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });
            
            // 同时保存价格规则
            if (shippingInfo.services && Array.isArray(shippingInfo.services)) {
                await savePriceRulesToIndexedDB(shippingInfo.services);
            }
            
            console.log('配送信息已保存到IndexedDB');
        } catch (e) {
            console.error('保存配送信息到IndexedDB失败:', e);
        }
    }
    
    /**
     * 保存价格规则到IndexedDB
     */
    async function savePriceRulesToIndexedDB(services) {
        try {
            await initIndexedDB();
            
            const transaction = db.transaction([STORE_PRICE_RULES], 'readwrite');
            const store = transaction.objectStore(STORE_PRICE_RULES);
            
            for (const service of services) {
                if (service.service_id && service.price_rules) {
                    const data = {
                        service_id: service.service_id,
                        price_rules: service.price_rules,
                        service_name: service.service_name,
                        service_code: service.service_code,
                        updated_at: new Date().toISOString(),
                    };
                    
                    await new Promise((resolve, reject) => {
                        const request = store.put(data);
                        request.onsuccess = () => resolve();
                        request.onerror = () => reject(request.error);
                    });
                }
            }
        } catch (e) {
            console.error('保存价格规则到IndexedDB失败:', e);
        }
    }
    
    /**
     * 从IndexedDB获取配送信息
     */
    async function getShippingInfoFromIndexedDB(location) {
        try {
            await initIndexedDB();
            
            const locationKey = generateLocationKey(location);
            const transaction = db.transaction([STORE_SHIPPING_INFO], 'readonly');
            const store = transaction.objectStore(STORE_SHIPPING_INFO);
            
            return new Promise((resolve, reject) => {
                const request = store.get(locationKey);
                request.onsuccess = () => {
                    const result = request.result;
                    if (result && result.shipping_info) {
                        resolve(result.shipping_info);
                    } else {
                        resolve(null);
                    }
                };
                request.onerror = () => reject(request.error);
            });
        } catch (e) {
            console.error('从IndexedDB获取配送信息失败:', e);
            return null;
        }
    }
    
    /**
     * 从IndexedDB获取价格规则
     */
    async function getPriceRulesFromIndexedDB(serviceId) {
        try {
            await initIndexedDB();
            
            const transaction = db.transaction([STORE_PRICE_RULES], 'readonly');
            const store = transaction.objectStore(STORE_PRICE_RULES);
            
            return new Promise((resolve, reject) => {
                const request = store.get(serviceId);
                request.onsuccess = () => {
                    const result = request.result;
                    if (result && result.price_rules) {
                        resolve(result.price_rules);
                    } else {
                        resolve(null);
                    }
                };
                request.onerror = () => reject(request.error);
            });
        } catch (e) {
            console.error('从IndexedDB获取价格规则失败:', e);
            return null;
        }
    }
    
    /**
     * 根据价格规则计算配送费
     */
    function calculateShippingFee(priceRules, options = {}) {
        if (!priceRules) {
            return 0;
        }
        
        const {
            weight = 0,
            volume = 0,
            quantity = 1,
        } = options;
        
        const calculationType = priceRules.calculation_type || 'fixed';
        let fee = priceRules.base_fee || 0;
        
        switch (calculationType) {
            case 'weight':
                fee += (weight || 0) * (priceRules.weight_rate || 0);
                break;
                
            case 'volume':
                fee += (volume || 0) * (priceRules.volume_rate || 0);
                break;
                
            case 'quantity':
                fee += (quantity || 1) * (priceRules.quantity_rate || 0);
                break;
                
            case 'fixed':
                // 固定费用，只使用base_fee
                break;
                
            case 'mixed':
                // 混合计算（简化处理）
                if (priceRules.mixed_config) {
                    try {
                        const config = typeof priceRules.mixed_config === 'string' 
                            ? JSON.parse(priceRules.mixed_config) 
                            : priceRules.mixed_config;
                        
                        if (config.weight && weight) {
                            fee += weight * (config.weight_rate || 0);
                        }
                        if (config.volume && volume) {
                            fee += volume * (config.volume_rate || 0);
                        }
                        if (config.quantity && quantity) {
                            fee += quantity * (config.quantity_rate || 0);
                        }
                    } catch (e) {
                        console.error('解析混合配置失败:', e);
                    }
                }
                break;
        }
        
        return Math.max(0, fee);
    }
    
    /**
     * 计算产品配送费（从IndexedDB获取价格规则）
     */
    async function calculateProductShippingFee(serviceId, productInfo) {
        try {
            // 先从IndexedDB获取价格规则
            const priceRules = await getPriceRulesFromIndexedDB(serviceId);
            
            if (priceRules) {
                // 使用缓存的价格规则计算
                return calculateShippingFee(priceRules, {
                    weight: productInfo.weight || 0,
                    volume: productInfo.volume || 0,
                    quantity: productInfo.quantity || 1,
                });
            }
            
            // 如果IndexedDB中没有，返回null，让调用者决定是否请求接口
            return null;
        } catch (e) {
            console.error('计算产品配送费失败:', e);
            return null;
        }
    }
    
    /**
     * 检查配送信息是否需要更新（基于时间戳）
     */
    async function shouldUpdateShippingInfo(location, maxAge = 24 * 60 * 60 * 1000) {
        try {
            await initIndexedDB();
            
            const locationKey = generateLocationKey(location);
            const transaction = db.transaction([STORE_SHIPPING_INFO], 'readonly');
            const store = transaction.objectStore(STORE_SHIPPING_INFO);
            
            return new Promise((resolve, reject) => {
                const request = store.get(locationKey);
                request.onsuccess = () => {
                    const result = request.result;
                    if (!result) {
                        resolve(true); // 没有数据，需要更新
                        return;
                    }
                    
                    const updatedAt = new Date(result.updated_at).getTime();
                    const now = Date.now();
                    const age = now - updatedAt;
                    
                    resolve(age > maxAge); // 超过最大年龄，需要更新
                };
                request.onerror = () => reject(request.error);
            });
        } catch (e) {
            console.error('检查配送信息更新状态失败:', e);
            return true; // 出错时默认需要更新
        }
    }
    
    // 导出API
    if (typeof window.WelineShipping === 'undefined') {
        window.WelineShipping = {};
    }
    
    window.WelineShipping.IndexedDB = {
        init: initIndexedDB,
        saveShippingInfo: saveShippingInfoToIndexedDB,
        getShippingInfo: getShippingInfoFromIndexedDB,
        savePriceRules: savePriceRulesToIndexedDB,
        getPriceRules: getPriceRulesFromIndexedDB,
        calculateFee: calculateShippingFee,
        calculateProductFee: calculateProductShippingFee,
        shouldUpdate: shouldUpdateShippingInfo,
        generateLocationKey: generateLocationKey,
    };
    
})(window, document);
