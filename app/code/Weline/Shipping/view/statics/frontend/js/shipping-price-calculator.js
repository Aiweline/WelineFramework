/**
 * Shipping模块 - 配送费价格计算器
 * 
 * 注册到Product价格计算系统，自动计算配送费
 * 
 * @author Aiweline
 * @version 1.0.0
 */
(function (window, document) {
    'use strict';

    /**
     * 计算配送费
     */
    async function calculateShippingFee(context) {
        try {
            // 获取配送位置
            const location = context.metadata.location;
            if (!location) {
                // 尝试从cookie/localStorage获取
                if (window.WelineShipping && window.WelineShipping.Location) {
                    const savedLocation = window.WelineShipping.Location.load();
                    if (savedLocation) {
                        location = savedLocation;
                    }
                }
            }

            if (!location) {
                return null; // 没有配送位置，不计算配送费
            }

            // 获取产品信息
            const productInfo = context.productInfo;
            const quantity = context.quantity || 1;

            // 计算产品总重量和体积
            const totalWeight = (productInfo.weight || 0) * quantity;
            const totalVolume = (productInfo.volume || 0) * quantity;

            // 获取配送信息（从IndexedDB或接口）
            let shippingInfo = null;
            if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                // 先从IndexedDB获取
                shippingInfo = await window.WelineShipping.IndexedDB.getShippingInfo(location);

                // 如果IndexedDB中没有或需要更新，调用接口
                if (!shippingInfo || await window.WelineShipping.IndexedDB.shouldUpdate(location)) {
                    if (window.WelineShipping && window.WelineShipping.Location) {
                        shippingInfo = await window.WelineShipping.Location.fetchShippingInfo(location);
                    }
                }
            }

            if (!shippingInfo || !shippingInfo.services || shippingInfo.services.length === 0) {
                return null; // 没有可用的配送服务
            }

            // 使用第一个配送服务（或根据业务逻辑选择）
            const service = shippingInfo.services[0];
            const serviceId = service.service_id;

            // 从IndexedDB获取价格规则并计算配送费
            if (window.WelineShipping && window.WelineShipping.IndexedDB) {
                const priceRules = await window.WelineShipping.IndexedDB.getPriceRules(serviceId);

                if (priceRules) {
                    const shippingFee = window.WelineShipping.IndexedDB.calculateFee(priceRules, {
                        weight: totalWeight,
                        volume: totalVolume,
                        quantity: quantity,
                    });

                    return {
                        // 注意：不再需要返回type，因为中间件名称已经在注册时指定
                        amount: shippingFee,
                        description: `配送费 (${service.service_name})`,
                        metadata: {
                            service_id: serviceId,
                            service_name: service.service_name,
                        }
                    };
                }
            }

            // 如果IndexedDB中没有价格规则，返回null（不计算配送费）
            return null;

        } catch (error) {
            console.error('[ShippingPriceCalculator] 计算配送费失败:', error);
            return null; // 出错时不添加配送费
        }
    }

    /**
     * 初始化：注册价格计算中间件
     */
    function init() {
        // 等待Weline和Product模块加载
        if (typeof window.Weline === 'undefined' || typeof window.WeShop === 'undefined') {
            // 延迟初始化
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                setTimeout(init, 100);
            }
            return;
        }

        // 使用通用中间件机制注册，名称带上 Product 命名空间前缀
        // 约定：WeShop_Product::price::shipping
        if (window.Weline && window.Weline.Middleware) {
            window.Weline.Middleware.register(
                'WeShop_Product::price::shipping',
                calculateShippingFee,
                {
                    name: 'ShippingPriceCalculator',
                    priority: 10,
                    source: 'Weline_Shipping',
                    meta: { type: 'shipping' }
                }
            );

            console.log('[ShippingPriceCalculator] 配送费中间件已通过 Weline.Middleware 注册');
        } else {
            // 延迟注册
            setTimeout(init, 100);
        }
    }

    // 初始化
    init();

    // 导出API（可选）
    if (typeof window.WelineShipping === 'undefined') {
        window.WelineShipping = {};
    }

    window.WelineShipping.PriceCalculator = {
        calculate: calculateShippingFee,
    };

})(window, document);
