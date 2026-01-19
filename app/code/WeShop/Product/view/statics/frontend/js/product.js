/**
 * WeShop Product模块 - 产品价格计算系统（WASM版本）
 * 
 * 提供统一的产品价格计算和更新框架
 * 使用 WebAssembly 进行高性能价格计算
 * 支持其他模块通过中间件扩展价格计算逻辑（如配送费、税费等）
 * 
 * 中间件命名规范（由Product模块定义）：
 * - 'shipping': 配送费中间件
 * - 'tax': 税费中间件
 * - 'discount': 折扣中间件
 * - 'other': 其他费用中间件
 * 
 * @author Aiweline
 * @version 2.0.0
 */
(function (window, document) {
    'use strict';

    /**
     * Product模块定义的中间件名称常量
     * 其他模块注册中间件时必须使用这些标准名称
     */
    const MIDDLEWARE_NAMES = {
        SHIPPING: 'shipping',    // 配送费中间件
        TAX: 'tax',              // 税费中间件
        DISCOUNT: 'discount',    // 折扣中间件
        OTHER: 'other'            // 其他费用中间件
    };

    /**
     * WASM模块实例
     */
    let wasmModule = null;
    let wasmReady = false;

    /**
     * 价格计算上下文
     */
    class PriceContext {
        constructor(productInfo, options = {}) {
            this.productInfo = productInfo; // 产品信息
            this.quantity = options.quantity || 1; // 数量
            this.basePrice = productInfo.price || 0; // 基础价格
            this.currency = options.currency || 'CNY'; // 货币代码

            // 价格组成部分（使用WASM结构）
            this.components = {
                base: this.basePrice * this.quantity, // 基础价格（数量 * 单价）
                shipping: 0, // 配送费
                tax: 0, // 税费
                discount: 0, // 折扣金额
                other: 0, // 其他费用
            };

            // WASM内存指针（用于高性能计算）
            this.wasmComponentsPtr = null;

            // 价格调整记录（用于调试和显示）
            this.adjustments = [];

            // 元数据（供回调函数使用）
            this.metadata = {
                location: options.location || null, // 配送位置
                customerId: options.customerId || null, // 客户ID
                couponCode: options.couponCode || null, // 优惠券代码
                ...options.metadata || {},
            };

            // 初始化WASM内存
            this.initWasmMemory();
        }

        /**
         * 初始化WASM内存
         */
        initWasmMemory() {
            if (wasmReady && wasmModule) {
                try {
                    // 分配内存存储PriceComponents结构（5个double = 40字节）
                    this.wasmComponentsPtr = wasmModule._malloc(40);
                    if (this.wasmComponentsPtr) {
                        // 初始化内存
                        const view = new Float64Array(wasmModule.HEAPF64.buffer, this.wasmComponentsPtr, 5);
                        view[0] = this.components.base;
                        view[1] = this.components.shipping;
                        view[2] = this.components.tax;
                        view[3] = this.components.discount;
                        view[4] = this.components.other;
                    }
                } catch (e) {
                    console.warn('[PriceContext] WASM内存初始化失败，使用JavaScript计算:', e);
                }
            }
        }

        /**
         * 同步WASM内存到JavaScript对象
         */
        syncFromWasm() {
            if (this.wasmComponentsPtr && wasmModule) {
                try {
                    const view = new Float64Array(wasmModule.HEAPF64.buffer, this.wasmComponentsPtr, 5);
                    this.components.base = view[0];
                    this.components.shipping = view[1];
                    this.components.tax = view[2];
                    this.components.discount = view[3];
                    this.components.other = view[4];
                } catch (e) {
                    console.warn('[PriceContext] WASM内存同步失败:', e);
                }
            }
        }

        /**
         * 同步JavaScript对象到WASM内存
         */
        syncToWasm() {
            if (this.wasmComponentsPtr && wasmModule) {
                try {
                    const view = new Float64Array(wasmModule.HEAPF64.buffer, this.wasmComponentsPtr, 5);
                    view[0] = this.components.base;
                    view[1] = this.components.shipping;
                    view[2] = this.components.tax;
                    view[3] = this.components.discount;
                    view[4] = this.components.other;
                } catch (e) {
                    console.warn('[PriceContext] WASM内存同步失败:', e);
                }
            }
        }

        /**
         * 添加价格调整（使用WASM）
         */
        addAdjustment(type, amount, description, source = '') {
            this.adjustments.push({
                type: type,
                amount: amount,
                description: description,
                source: source,
                timestamp: Date.now(),
            });

            // 验证中间件名称
            if (!Object.values(MIDDLEWARE_NAMES).includes(type)) {
                console.warn(`[PriceContext] 未知的中间件类型: ${type}，使用 'other'`);
                type = MIDDLEWARE_NAMES.OTHER;
            }

            // 使用WASM应用调整
            if (wasmReady && wasmModule && this.wasmComponentsPtr) {
                try {
                    // 同步到WASM
                    this.syncToWasm();

                    // 获取类型索引
                    const typeIndex = type === MIDDLEWARE_NAMES.SHIPPING ? 0 :
                        type === MIDDLEWARE_NAMES.TAX ? 1 :
                            type === MIDDLEWARE_NAMES.DISCOUNT ? 2 : 3;

                    // 调用WASM函数
                    wasmModule._apply_price_adjustment(this.wasmComponentsPtr, typeIndex, amount);

                    // 同步回JavaScript
                    this.syncFromWasm();
                } catch (e) {
                    console.warn('[PriceContext] WASM调整失败，使用JavaScript计算:', e);
                    // 降级到JavaScript计算
                    if (this.components.hasOwnProperty(type)) {
                        this.components[type] += amount;
                    } else {
                        this.components.other += amount;
                    }
                }
            } else {
                // 降级到JavaScript计算
                if (this.components.hasOwnProperty(type)) {
                    this.components[type] += amount;
                } else {
                    this.components.other += amount;
                }
            }
        }

        /**
         * 计算总价（使用WASM，异步）
         * 
         * @returns {Promise<number>}
         */
        async calculateTotalAsync() {
            // 如果已经计算过，直接返回
            if (this._calculatedTotal !== undefined) {
                return this._calculatedTotal;
            }

            // 确保WASM已加载
            if (!wasmReady) {
                await loadWasmModule();
            }

            // 使用setTimeout让出事件循环，确保异步执行
            return new Promise((resolve) => {
                setTimeout(() => {
                    try {
                        if (wasmReady && wasmModule && this.wasmComponentsPtr) {
                            // 同步到WASM
                            this.syncToWasm();

                            // 调用WASM函数
                            const total = wasmModule._calculate_total_price(this.wasmComponentsPtr);
                            this._calculatedTotal = total;
                            resolve(total);
                        } else {
                            // 降级到JavaScript计算
                            const total = Math.max(0,
                                this.components.base +
                                this.components.shipping +
                                this.components.tax +
                                this.components.other -
                                this.components.discount
                            );
                            this._calculatedTotal = total;
                            resolve(total);
                        }
                    } catch (e) {
                        console.warn('[PriceContext] WASM计算失败，使用JavaScript计算:', e);
                        // 降级到JavaScript计算
                        const total = Math.max(0,
                            this.components.base +
                            this.components.shipping +
                            this.components.tax +
                            this.components.other -
                            this.components.discount
                        );
                        this._calculatedTotal = total;
                        resolve(total);
                    }
                }, 0);
            });
        }

        /**
         * 获取价格明细（同步版本，使用已计算的总价）
         */
        getPriceBreakdown() {
            // 确保WASM内存已同步
            this.syncFromWasm();

            return {
                base: this.components.base,
                shipping: this.components.shipping,
                tax: this.components.tax,
                discount: this.components.discount,
                other: this.components.other,
                total: this._calculatedTotal !== undefined ? this._calculatedTotal : 0,
                adjustments: this.adjustments,
            };
        }

        /**
         * 清理WASM内存
         */
        destroy() {
            if (this.wasmComponentsPtr && wasmModule) {
                wasmModule._free(this.wasmComponentsPtr);
                this.wasmComponentsPtr = null;
            }
        }
    }

    /**
     * 价格计算器
     */
    class PriceCalculator {
        constructor() {
            // 中间件列表（按优先级排序）
            // 键为中间件名称（由Product模块定义），值为中间件配置数组
            this.middlewares = {
                [MIDDLEWARE_NAMES.SHIPPING]: [],
                [MIDDLEWARE_NAMES.TAX]: [],
                [MIDDLEWARE_NAMES.DISCOUNT]: [],
                [MIDDLEWARE_NAMES.OTHER]: [],
            };
        }

        /**
         * 注册中间件
         * 
         * @param {string} middlewareName - 中间件名称（必须使用MIDDLEWARE_NAMES中定义的标准名称）
         * @param {Function} middleware - 中间件函数
         * @param {Object} options - 选项
         * @param {number} options.priority - 优先级（数字越小越先执行，默认100）
         * @param {string} options.name - 中间件实例名称（用于调试和识别）
         */
        register(middlewareName, middleware, options = {}) {
            // 验证中间件名称
            if (!Object.values(MIDDLEWARE_NAMES).includes(middlewareName)) {
                console.error(`[PriceCalculator] 无效的中间件名称: ${middlewareName}。必须使用以下标准名称之一:`, Object.values(MIDDLEWARE_NAMES));
                return;
            }

            if (typeof middleware !== 'function') {
                console.error('[PriceCalculator] 中间件必须是函数');
                return;
            }

            const config = {
                middleware: middleware,
                priority: options.priority ?? 100,
                name: options.name || 'anonymous',
                middlewareName: middlewareName,
            };

            this.middlewares[middlewareName].push(config);

            // 按优先级排序
            this.middlewares[middlewareName].sort((a, b) => a.priority - b.priority);

            if (window.DEV) {
                console.log(`[PriceCalculator] 注册中间件: ${config.name} (类型: ${middlewareName}, 优先级: ${config.priority})`);
            }
        }

        /**
         * 移除中间件
         * 
         * @param {string} middlewareName - 中间件名称
         * @param {string} name - 中间件实例名称
         */
        unregister(middlewareName, name) {
            if (!this.middlewares[middlewareName]) {
                return;
            }

            const index = this.middlewares[middlewareName].findIndex(m => m.name === name);
            if (index !== -1) {
                this.middlewares[middlewareName].splice(index, 1);
                if (window.DEV) {
                    console.log(`[PriceCalculator] 移除中间件: ${name} (类型: ${middlewareName})`);
                }
            }
        }

        /**
         * 计算价格（使用WASM和中间件，完全异步）
         * 
         * @param {Object} productInfo - 产品信息
         * @param {Object} options - 选项
         * @returns {Promise<PriceContext>}
         */
        async calculate(productInfo, options = {}) {
            const context = new PriceContext(productInfo, options);

            // 按中间件类型顺序执行（shipping -> tax -> discount -> other）
            const executionOrder = [
                MIDDLEWARE_NAMES.SHIPPING,
                MIDDLEWARE_NAMES.TAX,
                MIDDLEWARE_NAMES.DISCOUNT,
                MIDDLEWARE_NAMES.OTHER
            ];

            // 执行所有中间件（异步）
            for (const middlewareName of executionOrder) {
                const middlewares = this.middlewares[middlewareName] || [];

                for (const config of middlewares) {
                    try {
                        const result = await config.middleware(context);

                        // 如果中间件返回了调整结果，自动应用
                        if (result && typeof result === 'object') {
                            if (result.amount !== undefined) {
                                context.addAdjustment(
                                    middlewareName, // 使用标准中间件名称
                                    result.amount,
                                    result.description || config.name,
                                    config.name
                                );
                            }
                        }
                    } catch (error) {
                        console.error(`[PriceCalculator] 中间件 ${config.name} (${middlewareName}) 执行失败:`, error);
                        // 继续执行其他中间件
                    }
                }
            }

            // 所有中间件执行完成后，使用WASM异步计算总价
            await this.calculateTotalWithWasm(context);

            return context;
        }

        /**
         * 使用WASM异步计算总价
         * 
         * @param {PriceContext} context - 价格上下文
         * @returns {Promise<void>}
         */
        async calculateTotalWithWasm(context) {
            // 确保WASM已加载
            if (!wasmReady) {
                await loadWasmModule();
            }

            // 使用setTimeout让出事件循环，确保异步执行
            return new Promise((resolve) => {
                setTimeout(async () => {
                    try {
                        if (wasmReady && wasmModule && context.wasmComponentsPtr) {
                            // 同步到WASM
                            context.syncToWasm();

                            // 调用WASM函数计算总价
                            const total = wasmModule._calculate_total_price(context.wasmComponentsPtr);

                            // 将总价存储到context（不直接修改components，避免同步问题）
                            context._calculatedTotal = total;
                        } else {
                            // 降级到JavaScript计算
                            context._calculatedTotal = Math.max(0,
                                context.components.base +
                                context.components.shipping +
                                context.components.tax +
                                context.components.other -
                                context.components.discount
                            );
                        }
                    } catch (e) {
                        console.warn('[PriceCalculator] WASM计算总价失败，使用JavaScript计算:', e);
                        // 降级到JavaScript计算
                        context._calculatedTotal = Math.max(0,
                            context.components.base +
                            context.components.shipping +
                            context.components.tax +
                            context.components.other -
                            context.components.discount
                        );
                    }
                    resolve();
                }, 0);
            });
        }

        /**
         * 更新价格显示（异步，等待计算完成）
         * 
         * @param {PriceContext} context - 价格上下文
         * @param {Object} options - 选项
         * @returns {Promise<void>}
         */
        async updateDisplay(context, options = {}) {
            // 确保总价已计算
            if (context._calculatedTotal === undefined) {
                await context.calculateTotalAsync();
            }

            const breakdown = context.getPriceBreakdown();
            const selectors = options.selectors || {};

            // 更新基础价格
            if (selectors.basePrice) {
                this.updateElement(selectors.basePrice, breakdown.base);
            }

            // 更新配送费
            if (selectors.shipping && breakdown.shipping > 0) {
                this.updateElement(selectors.shipping, breakdown.shipping, true);
            }

            // 更新税费
            if (selectors.tax && breakdown.tax > 0) {
                this.updateElement(selectors.tax, breakdown.tax, true);
            }

            // 更新折扣
            if (selectors.discount && breakdown.discount > 0) {
                this.updateElement(selectors.discount, breakdown.discount, true);
            }

            // 更新总价
            if (selectors.total) {
                this.updateElement(selectors.total, breakdown.total);
            }

            // 触发价格更新事件（异步计算完成后通知）
            const event = new CustomEvent('weline:product:price-updated', {
                detail: {
                    context: context,
                    breakdown: breakdown,
                }
            });
            document.dispatchEvent(event);
        }

        /**
         * 更新DOM元素
         */
        updateElement(selector, value, show = true) {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                if (show) {
                    el.style.display = '';
                    el.textContent = this.formatPrice(value);
                } else {
                    el.style.display = 'none';
                }
            });
        }

        /**
         * 格式化价格
         */
        formatPrice(price, currency = 'CNY') {
            const symbols = {
                'CNY': '¥',
                'USD': '$',
                'EUR': '€',
            };
            const symbol = symbols[currency] || currency;
            return symbol + parseFloat(price).toFixed(2);
        }
    }

    /**
     * 加载WASM模块
     */
    async function loadWasmModule() {
        if (wasmReady) {
            return wasmModule;
        }

        try {
            // 获取WASM路径（从配置或使用默认路径）
            let wasmUrl = window.__WeShopProductConfig?.wasmUrl;
            if (!wasmUrl) {
                // 降级到默认路径
                const isDev = window.DEV || false;
                if (isDev) {
                    wasmUrl = '/WeShop/Product/view/statics/frontend/wasm/price-calculator.wasm';
                } else {
                    wasmUrl = '/static/WeShop/Product/frontend/wasm/price-calculator.wasm';
                }
            }

            // 使用WebAssembly.instantiateStreaming加载
            const wasmResponse = await fetch(wasmUrl);
            if (!wasmResponse.ok) {
                throw new Error(`WASM文件加载失败: ${wasmResponse.status}`);
            }

            const wasmBytes = await wasmResponse.arrayBuffer();
            const wasmResult = await WebAssembly.instantiate(wasmBytes, {
                env: {
                    // 如果需要导入函数，在这里定义
                }
            });

            wasmModule = wasmResult.instance.exports;
            wasmReady = true;

            if (window.DEV) {
                console.log('[PriceCalculator] WASM模块加载成功');
            }

            return wasmModule;
        } catch (error) {
            console.warn('[PriceCalculator] WASM模块加载失败，将使用JavaScript计算:', error);
            wasmReady = false;
            wasmModule = null;
            return null;
        }
    }

    // 创建全局价格计算器实例
    const priceCalculator = new PriceCalculator();

    /**
     * 将WASM接口对象包装成中间件函数
     * 
     * @param {Object} wasmInterface - WASM接口描述对象
     * @returns {Function} 包装后的中间件函数
     */
    function wrapWasmInterface(wasmInterface) {
        return async function (context) {
            // 确保WASM已加载
            if (!wasmReady) {
                await loadWasmModule();
            }

            if (!wasmReady || !wasmModule) {
                console.warn('[WeShop.Product] WASM未加载，WASM中间件无法执行');
                return null;
            }

            try {
                const wasmFunc = wasmModule[wasmInterface.wasmFunction];
                if (!wasmFunc || typeof wasmFunc !== 'function') {
                    console.error(`[WeShop.Product] WASM函数不存在: ${wasmInterface.wasmFunction}`);
                    return null;
                }

                const params = wasmInterface.params || [];
                const args = params.map(paramName => {
                    if (paramName === 'base') return context.components.base;
                    if (paramName === 'shipping') return context.components.shipping;
                    if (paramName === 'tax') return context.components.tax;
                    if (paramName === 'discount') return context.components.discount;
                    if (paramName === 'other') return context.components.other;
                    if (paramName === 'quantity') return context.quantity;
                    if (paramName === 'weight') return context.productInfo.weight || 0;
                    if (paramName === 'volume') return context.productInfo.volume || 0;
                    if (context.metadata && Object.prototype.hasOwnProperty.call(context.metadata, paramName)) {
                        return context.metadata[paramName];
                    }
                    return 0;
                });

                const result = wasmFunc.apply(null, args);

                return {
                    amount: result || 0,
                    description: wasmInterface.description || `WASM计算 (${wasmInterface.wasmFunction})`
                };
            } catch (e) {
                console.error('[WeShop.Product] WASM中间件执行失败:', e);
                return null;
            }
        };
    }

    /**
     * 从 Theme 的通用中间件机制中提取属于 Product 的中间件
     * 
     * 约定命名规则：
     * - 本模块中间件名称前缀为：'WeShop_Product::price::'
     * - 最后的片段为类型：shipping / tax / discount / other
     */
    function bootstrapThemeMiddlewares() {
        if (!window.Weline || !window.Weline.Middleware) {
            return;
        }

        const registry = window.Weline.Middleware;
        const validTypes = Object.values(MIDDLEWARE_NAMES);
        const prefix = 'WeShop_Product::price::';

        try {
            const entries = typeof registry.getByPrefix === 'function'
                ? registry.getByPrefix(prefix)
                : (typeof registry.getAll === 'function' ? registry.getAll() : []);

            (entries || []).forEach((entry) => {
                if (!entry || typeof entry.name !== 'string') return;

                if (!entry.name.startsWith(prefix)) return;

                const type = entry.name.substring(prefix.length); // e.g. 'shipping'
                if (!validTypes.includes(type)) {
                    if (window.DEV) {
                        console.log('[WeShop.Product] 跳过未知类型中间件:', entry.name);
                    }
                    return;
                }

                let handler = entry.handler;

                // 如果是WASM接口描述对象，包装成函数
                const isWasmInterface = typeof handler === 'object' &&
                    handler !== null &&
                    handler.type === 'wasm' &&
                    typeof handler.wasmFunction === 'string';

                if (isWasmInterface) {
                    handler = wrapWasmInterface(handler);
                } else if (typeof handler !== 'function') {
                    console.warn('[WeShop.Product] 中间件handler类型不支持:', entry);
                    return;
                }

                try {
                    priceCalculator.register(type, handler, {
                        priority: entry.priority,
                        name: entry.displayName || entry.name,
                    });

                    if (window.DEV) {
                        console.log(`[WeShop.Product] 已接管中间件: ${entry.displayName || entry.name} (${type})`);
                    }
                } catch (e) {
                    console.warn('[WeShop.Product] 注册中间件失败:', entry, e);
                }
            });
        } catch (e) {
            console.warn('[WeShop.Product] 读取通用中间件注册表失败:', e);
        }
    }

    // 初始化时尝试接管 Theme 注册的 Product 中间件
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapThemeMiddlewares);
    } else {
        bootstrapThemeMiddlewares();
    }

    // 导出到全局
    if (typeof window.WeShop === 'undefined') {
        window.WeShop = {};
    }

    window.WeShop.Product = {
        PriceCalculator: priceCalculator,
        PriceContext: PriceContext,

        /**
         * 中间件名称常量（供其他模块使用）
         */
        MIDDLEWARE_NAMES: MIDDLEWARE_NAMES,

        /**
         * 便捷方法：计算产品价格
         */
        calculatePrice: async function (productInfo, options) {
            // 确保WASM已加载
            if (!wasmReady) {
                await loadWasmModule();
            }
            return await priceCalculator.calculate(productInfo, options);
        },

        /**
         * 便捷方法：更新价格显示（异步）
         */
        updatePrice: async function (context, options) {
            await priceCalculator.updateDisplay(context, options);
        },

        /**
         * 便捷方法：计算并更新价格（一条龙，完全异步）
         */
        calculateAndUpdatePrice: async function (productInfo, options, displayOptions) {
            // 确保WASM已加载
            if (!wasmReady) {
                await loadWasmModule();
            }

            // 计算价格（异步）
            const context = await priceCalculator.calculate(productInfo, options);

            // 更新显示（异步）
            if (displayOptions) {
                await priceCalculator.updateDisplay(context, displayOptions);
            }

            return context;
        },

        /**
         * 加载WASM模块
         */
        loadWasm: loadWasmModule,
    };

    // 如果Weline存在，也挂载到Weline对象上
    if (typeof window.Weline !== 'undefined') {
        window.Weline.Product = window.WeShop.Product;
    }

    // 自动加载WASM模块
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            loadWasmModule().catch(e => {
                console.warn('[PriceCalculator] 自动加载WASM失败:', e);
            });
        });
    } else {
        loadWasmModule().catch(e => {
            console.warn('[PriceCalculator] 自动加载WASM失败:', e);
        });
    }

})(window, document);
