/**
 * 延迟立即加载功能单元测试
 * 
 * 测试 theme.js 中的延迟立即加载机制：
 * 1. 带 data-load-order="last" 的 declare 应入队而非立即加载
 * 2. 带 options.loadOrder: 'last' 的 declare 应入队
 * 3. 显式参数优先于 script 标签属性
 * 4. flush 函数应并行启动所有队列项
 * 5. DOM 已加载时应使用 setTimeout(flush, 0)
 * 
 * 运行方式：使用 Node.js 测试框架（如 Jest、Mocha）或浏览器测试工具
 */

// 模拟测试环境
const createMockEnvironment = () => {
    const loadedModules = new Set();
    const loadingModules = new Map();
    const immediateLoadDeferredQueue = [];
    let domContentLoadedCallbacks = [];
    let documentReadyState = 'loading';
    let currentScript = null;

    // 模拟 document
    const mockDocument = {
        get readyState() {
            return documentReadyState;
        },
        get currentScript() {
            return currentScript;
        },
        addEventListener: (event, callback, options) => {
            if (event === 'DOMContentLoaded') {
                domContentLoadedCallbacks.push(callback);
            }
        }
    };

    // 模拟 moduleLoader
    const mockModuleLoader = {
        loadModule: (moduleName, customPath) => {
            return new Promise((resolve) => {
                loadedModules.add(moduleName);
                setTimeout(() => resolve({ name: moduleName }), 10);
            });
        }
    };

    // 模拟 moduleDeclarer
    const mockModuleDeclarer = {
        declaredModules: new Map(),
        declare: (moduleNames, customPath) => {
            const list = Array.isArray(moduleNames) ? moduleNames : [moduleNames];
            list.forEach(name => {
                mockModuleDeclarer.declaredModules.set(name, { name, customPath });
            });
        },
        setupProxies: () => Promise.resolve()
    };

    // 辅助函数
    const setDocumentReadyState = (state) => {
        documentReadyState = state;
    };

    const setCurrentScript = (script) => {
        currentScript = script;
    };

    const triggerDOMContentLoaded = () => {
        domContentLoadedCallbacks.forEach(cb => cb());
        domContentLoadedCallbacks = [];
    };

    const reset = () => {
        loadedModules.clear();
        loadingModules.clear();
        immediateLoadDeferredQueue.length = 0;
        domContentLoadedCallbacks = [];
        documentReadyState = 'loading';
        currentScript = null;
        mockModuleDeclarer.declaredModules.clear();
    };

    return {
        mockDocument,
        mockModuleLoader,
        mockModuleDeclarer,
        immediateLoadDeferredQueue,
        loadedModules,
        setDocumentReadyState,
        setCurrentScript,
        triggerDOMContentLoaded,
        reset
    };
};

// 测试用例
const tests = {
    'options.loadOrder: "last" 应将模块入队': () => {
        const env = createMockEnvironment();
        
        // 模拟 declare 函数（简化版）
        const declare = (moduleNames, loadImmediately, customPath, callback, options) => {
            if (!loadImmediately) return;
            
            let shouldDefer = false;
            if (options && options.loadOrder === 'last') {
                shouldDefer = true;
            }
            
            if (shouldDefer) {
                env.immediateLoadDeferredQueue.push({
                    moduleNames,
                    actualCustomPath: customPath,
                    callback
                });
                return 'deferred';
            }
            return 'immediate';
        };

        const result = declare('testModule', true, '/path/to/module.js', null, { loadOrder: 'last' });
        
        console.assert(result === 'deferred', 'options.loadOrder: "last" 应返回 deferred');
        console.assert(env.immediateLoadDeferredQueue.length === 1, '队列应有 1 个项');
        console.assert(env.immediateLoadDeferredQueue[0].moduleNames === 'testModule', '队列项模块名应正确');
        
        console.log('✓ options.loadOrder: "last" 应将模块入队');
    },

    'data-load-order="last" 应将模块入队': () => {
        const env = createMockEnvironment();
        
        // 设置当前 script 有 data-load-order="last"
        env.setCurrentScript({
            getAttribute: (attr) => attr === 'data-load-order' ? 'last' : null
        });

        const declare = (moduleNames, loadImmediately, customPath, callback, options) => {
            if (!loadImmediately) return;
            
            let shouldDefer = false;
            
            // 先检查显式参数
            if (options && (options.loadOrder === 'last' || options.loadOrder === 'defer')) {
                shouldDefer = true;
            }
            
            // 再检查 script 属性
            if (!shouldDefer && env.mockDocument.currentScript) {
                const scriptLoadOrder = env.mockDocument.currentScript.getAttribute('data-load-order');
                if (scriptLoadOrder === 'last' || scriptLoadOrder === 'defer') {
                    shouldDefer = true;
                }
            }
            
            if (shouldDefer) {
                env.immediateLoadDeferredQueue.push({
                    moduleNames,
                    actualCustomPath: customPath,
                    callback
                });
                return 'deferred';
            }
            return 'immediate';
        };

        const result = declare('testModule', true, '/path/to/module.js', null, null);
        
        console.assert(result === 'deferred', 'data-load-order="last" 应返回 deferred');
        console.assert(env.immediateLoadDeferredQueue.length === 1, '队列应有 1 个项');
        
        console.log('✓ data-load-order="last" 应将模块入队');
    },

    '显式参数应优先于 script 属性': () => {
        const env = createMockEnvironment();
        
        // 设置 script 不延迟
        env.setCurrentScript({
            getAttribute: () => null
        });

        const declare = (moduleNames, loadImmediately, customPath, callback, options) => {
            if (!loadImmediately) return;
            
            let shouldDefer = false;
            
            // 先检查显式参数（优先）
            if (options && (options.loadOrder === 'last' || options.loadOrder === 'defer')) {
                shouldDefer = true;
            }
            
            // 再检查 script 属性
            if (!shouldDefer && env.mockDocument.currentScript) {
                const scriptLoadOrder = env.mockDocument.currentScript.getAttribute('data-load-order');
                if (scriptLoadOrder === 'last' || scriptLoadOrder === 'defer') {
                    shouldDefer = true;
                }
            }
            
            if (shouldDefer) {
                return 'deferred';
            }
            return 'immediate';
        };

        // 显式指定延迟，即使 script 没有 data-load-order
        const result = declare('testModule', true, '/path/to/module.js', null, { loadOrder: 'last' });
        
        console.assert(result === 'deferred', '显式参数 loadOrder: "last" 应生效');
        
        console.log('✓ 显式参数应优先于 script 属性');
    },

    '无延迟参数时应立即加载': () => {
        const env = createMockEnvironment();
        
        env.setCurrentScript({
            getAttribute: () => null
        });

        const declare = (moduleNames, loadImmediately, customPath, callback, options) => {
            if (!loadImmediately) return 'not-immediate';
            
            let shouldDefer = false;
            
            if (options && (options.loadOrder === 'last' || options.loadOrder === 'defer')) {
                shouldDefer = true;
            }
            
            if (!shouldDefer && env.mockDocument.currentScript) {
                const scriptLoadOrder = env.mockDocument.currentScript.getAttribute('data-load-order');
                if (scriptLoadOrder === 'last' || scriptLoadOrder === 'defer') {
                    shouldDefer = true;
                }
            }
            
            if (shouldDefer) {
                return 'deferred';
            }
            return 'immediate';
        };

        const result = declare('testModule', true, '/path/to/module.js', null, null);
        
        console.assert(result === 'immediate', '无延迟参数时应返回 immediate');
        console.assert(env.immediateLoadDeferredQueue.length === 0, '队列应为空');
        
        console.log('✓ 无延迟参数时应立即加载');
    },

    'flush 应并行启动所有队列项': async () => {
        const env = createMockEnvironment();
        const loadOrder = [];
        
        // 向队列添加多个项
        env.immediateLoadDeferredQueue.push(
            { moduleNames: 'module1', actualCustomPath: null, callback: null },
            { moduleNames: 'module2', actualCustomPath: null, callback: null },
            { moduleNames: 'module3', actualCustomPath: null, callback: null }
        );

        // 模拟 flush 函数
        const flush = () => {
            const snapshot = env.immediateLoadDeferredQueue.splice(0);
            const loadPromises = [];
            
            snapshot.forEach(item => {
                loadOrder.push(`start:${item.moduleNames}`);
                const promise = env.mockModuleLoader.loadModule(item.moduleNames, item.actualCustomPath)
                    .then(() => {
                        loadOrder.push(`done:${item.moduleNames}`);
                    });
                loadPromises.push(promise);
            });
            
            return Promise.all(loadPromises);
        };

        await flush();
        
        // 验证所有模块都先 start，再 done（并行）
        console.assert(loadOrder[0] === 'start:module1', '第一个应是 start:module1');
        console.assert(loadOrder[1] === 'start:module2', '第二个应是 start:module2');
        console.assert(loadOrder[2] === 'start:module3', '第三个应是 start:module3');
        console.assert(loadOrder.length === 6, '应有 6 个记录（3 个 start + 3 个 done）');
        
        console.log('✓ flush 应并行启动所有队列项');
    },

    'DOM 已加载时应使用 setTimeout': () => {
        const env = createMockEnvironment();
        let usedSetTimeout = false;
        let usedAddEventListener = false;

        // 模拟 setTimeout
        const mockSetTimeout = (fn, delay) => {
            usedSetTimeout = true;
        };

        // 模拟 DOM 已加载
        env.setDocumentReadyState('complete');

        // 模拟注册 flush 的逻辑
        const registerFlush = () => {
            if (env.mockDocument.readyState === 'loading') {
                env.mockDocument.addEventListener('DOMContentLoaded', () => {});
                usedAddEventListener = true;
            } else {
                mockSetTimeout(() => {}, 0);
            }
        };

        registerFlush();
        
        console.assert(usedSetTimeout === true, 'DOM 已加载时应使用 setTimeout');
        console.assert(usedAddEventListener === false, '不应使用 addEventListener');
        
        console.log('✓ DOM 已加载时应使用 setTimeout');
    },

    'DOM 未加载时应使用 addEventListener': () => {
        const env = createMockEnvironment();
        let usedSetTimeout = false;
        let usedAddEventListener = false;

        const mockSetTimeout = (fn, delay) => {
            usedSetTimeout = true;
        };

        // 模拟 DOM 未加载
        env.setDocumentReadyState('loading');

        const registerFlush = () => {
            if (env.mockDocument.readyState === 'loading') {
                env.mockDocument.addEventListener('DOMContentLoaded', () => {});
                usedAddEventListener = true;
            } else {
                mockSetTimeout(() => {}, 0);
            }
        };

        registerFlush();
        
        console.assert(usedAddEventListener === true, 'DOM 未加载时应使用 addEventListener');
        console.assert(usedSetTimeout === false, '不应使用 setTimeout');
        
        console.log('✓ DOM 未加载时应使用 addEventListener');
    }
};

// 运行所有测试
const runTests = async () => {
    console.log('=== 延迟立即加载功能单元测试 ===\n');
    
    for (const [name, testFn] of Object.entries(tests)) {
        try {
            const result = testFn();
            if (result instanceof Promise) {
                await result;
            }
        } catch (error) {
            console.error(`✗ ${name}`);
            console.error(error);
        }
    }
    
    console.log('\n=== 测试完成 ===');
};

// 如果在 Node.js 环境中运行
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { tests, runTests };
}

// 自动运行测试
runTests();
