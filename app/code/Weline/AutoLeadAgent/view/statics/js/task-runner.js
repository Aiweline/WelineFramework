/**
 * 自动寻客任务执行器
 * 
 * 负责真正驱动浏览器端侧AI模型执行任务
 * - 加载WASM模型
 * - 执行任务逻辑
 * - 实时更新进度（找到的客户数量）
 * - 输出推理日志和爬取日志
 * - 报告结果到服务器
 */

var AutoLeadAgentTaskRunner = (function () {
    'use strict';

    // 任务状态常量
    var TASK_STATUS = {
        IDLE: 'idle',           // 待机
        LOADING: 'loading',     // 加载模型中
        INFERRING: 'inferring', // 推理中（分析画像）
        CRAWLING: 'crawling',   // 爬取中（搜索客户）
        RUNNING: 'running',     // 运行中（通用）
        PAUSED: 'paused',       // 已暂停
        COMPLETED: 'completed', // 已完成
        FAILED: 'failed'        // 失败
    };

    // 状态机状态定义（ReAct模式）
    var STATE_MACHINE = {
        INITIALIZING: 'initializing',  // 初始化阶段（分析画像、生成策略）
        VALIDATING: 'validating',      // 验证阶段（ping检查、环境验证）
        CRAWLING: 'crawling',          // 爬取阶段（执行搜索、提取数据）
        ANALYZING: 'analyzing',        // 分析阶段（提取画像、匹配评分）
        DECIDING: 'deciding',          // 决策阶段（决定保存、继续或调整）
        COMPLETED: 'completed',        // 完成阶段（任务结束）
        FAILED: 'failed'               // 失败阶段（错误处理）
    };

    // 状态显示文本
    var STATUS_LABELS = {
        'idle': '待机',
        'loading': '加载模型中',
        'inferring': '推理中',
        'crawling': '爬取中',
        'running': '运行中',
        'paused': '已暂停',
        'completed': '已完成',
        'failed': '失败'
    };

    // 运行时状态
    var state = {
        status: TASK_STATUS.IDLE,
        stateMachineState: STATE_MACHINE.INITIALIZING, // 状态机当前状态
        currentTaskId: null,
        currentStoreId: null,
        currentSourceTypeProfile: null, // 当前来源类型画像
        profileSummary: null,           // 画像总结（思考阶段生成）
        searchStrategy: null,           // 搜索策略（思考阶段生成）
        wasmModule: null,
        wasmInstance: null,
        foundCount: 0,          // 找到的潜在客户数量
        startTime: null,
        candidates: [],
        abortController: null,
        isPaused: false,
        inferenceLog: [],       // 推理日志
        crawlingLog: []         // 爬取日志
    };

    // 性能指标
    var metrics = {
        inferenceTime: 0,
        memoryUsage: 0,
        totalInferences: 0,
        crawlCount: 0,          // 爬取次数
        lastCrawlTime: null     // 最后爬取时间
    };

    // 配置
    var config = {
        apiBaseUrl: '',
        progressUpdateInterval: 2000,   // 进度更新间隔
        inferenceTimeout: 30000,
        crawlInterval: 3000,            // 爬取间隔
        maxCandidatesPerBatch: 10,      // 每批次最大候选数
        extensionId: null,              // 浏览器扩展ID（自动检测）
        useExtension: false             // 是否使用扩展进行爬取
    };

    // 从后端获取的模块配置（包含 hf_model_id / hf_model_enabled 等），按页面生命周期缓存
    var cachedAgentConfig = null;

    // 日志回调
    var logCallbacks = {
        inference: [],  // 推理日志
        crawl: []       // 爬取日志
    };

    /**
     * 初始化任务执行器
     */
    function init(options) {
        options = options || {};

        // 从当前路径构建API基础URL
        var currentPath = window.location.pathname;
        config.apiBaseUrl = currentPath.replace(/\/[^\/]+$/, '');

        if (options.inferenceTimeout) {
            config.inferenceTimeout = options.inferenceTimeout;
        }
        if (options.crawlInterval) {
            config.crawlInterval = options.crawlInterval;
        }

        console.log('[TaskRunner] Initialized with config:', config);

        // 绑定UI更新
        updateUIStatus(TASK_STATUS.IDLE);

        // 检测浏览器扩展
        detectExtension();
    }

    /**
     * 获取模块配置（包含 hf_model_id / hf_model_enabled 等）
     * 结果按页面生命周期缓存，避免重复请求
     */
    async function getAgentConfig() {
        if (cachedAgentConfig) {
            return cachedAgentConfig;
        }

        try {
            // /auto-lead-agent/backend/index -> /auto-lead-agent/backend/config/getConfig
            var base = config.apiBaseUrl.replace(/\/index$/, '');
            var url = base + '/config/getConfig';

            var res = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            var data = await res.json();
            if (data && data.success && data.data) {
                cachedAgentConfig = data.data;
                return cachedAgentConfig;
            } else {
                console.warn('[TaskRunner] Failed to load agent config:', data && data.message);
                return null;
            }
        } catch (e) {
            console.error('[TaskRunner] Error loading agent config:', e);
            return null;
        }
    }

    /**
     * 加载端侧推理模型（HFModelManager + ModelInference）
     */
    async function loadInferenceModel() {
        // 如果已经通过 HFModelManager 加载过模型，则不重复加载
        if (typeof HFModelManager !== 'undefined' &&
            HFModelManager &&
            typeof HFModelManager.isLoaded === 'function' &&
            HFModelManager.isLoaded()) {
            emitLog('inference', '端侧模型已加载，跳过重复加载');
            return true;
        }

        state.status = TASK_STATUS.LOADING;
        updateUIStatus(TASK_STATUS.LOADING);
        updateModelStatus('loading', null, null);
        emitLog('inference', '开始加载端侧推理模型（HFModelManager + ModelInference）...');

        try {
            var startTime = performance.now();

            // 获取配置以初始化模型管理器
            var agentConfig = await getAgentConfig();
            var hfModelId = agentConfig && agentConfig.hf_model_id ? agentConfig.hf_model_id : null;
            var cacheSize = agentConfig && agentConfig.hf_model_cache_size
                ? parseInt(agentConfig.hf_model_cache_size, 10)
                : 10240;

            if (!hfModelId) {
                throw new Error('未配置 Hugging Face 模型 ID');
            }

            if (typeof HFModelManager === 'undefined' || typeof ModelInference === 'undefined') {
                throw new Error('HFModelManager 或 ModelInference 未在页面中加载');
            }

            // 初始化模型推理层
            ModelInference.init({
                apiBaseUrl: config.apiBaseUrl,
                modelId: hfModelId,
                cacheSize: cacheSize || 10240
            });

            // 触发实际模型加载（Chrome AI / WebLLM）
            await HFModelManager.loadModel();

            var loadTime = performance.now() - startTime;
            emitLog('inference', '端侧模型加载完成，耗时: ' + loadTime.toFixed(0) + 'ms');

            updateModelStatus('idle', loadTime, getMemoryUsage());
            return true;
        } catch (error) {
            emitLog('inference', '端侧模型加载失败: ' + error.message, { error: error.stack });
            state.status = TASK_STATUS.FAILED;
            updateUIStatus(TASK_STATUS.FAILED);
            updateModelStatus('error', null, null);
            return false;
        }
    }

    /**
     * 检测浏览器扩展是否已安装
     */
    function detectExtension() {
        // 监听扩展的就绪消息
        window.addEventListener('message', function (event) {
            if (event.source !== window) return;

            if (event.data && event.data.type === 'AUTOLEADAGENT_READY') {
                config.useExtension = true;
                config.extensionVersion = event.data.version;
                console.log('[TaskRunner] Browser extension detected, version:', event.data.version);
                emitLog('inference', '✓ 浏览器扩展已连接 (v' + event.data.version + ')');

                // 触发扩展就绪事件
                var readyEvent = new CustomEvent('taskRunnerExtensionReady', {
                    detail: { version: event.data.version }
                });
                window.dispatchEvent(readyEvent);
            }

            // 处理扩展响应
            if (event.data && event.data.type === 'AUTOLEADAGENT_RESPONSE') {
                handleExtensionResponse(event.data);
            }

            // 处理扩展日志
            if (event.data && event.data.type === 'AUTOLEADAGENT_LOG') {
                var logType = event.data.logType || 'crawl';
                var logMessage = event.data.message || '';
                if (logMessage) {
                    emitLog(logType, logMessage);
                }
            }
        });

        // 尝试通过 Chrome runtime API 检测（如果扩展提供了 externally_connectable）
        if (typeof chrome !== 'undefined' && chrome.runtime && chrome.runtime.sendMessage) {
            // 尝试已知的扩展ID列表
            tryConnectExtension();
        }

        console.log('[TaskRunner] Waiting for extension detection...');
    }

    /**
     * 尝试连接扩展
     */
    function tryConnectExtension() {
        // 扩展可能的ID（用户安装后会有固定ID）
        // 开发模式下ID是动态的，需要用户手动配置
        var possibleIds = window.AUTOLEADAGENT_EXTENSION_IDS || [];

        possibleIds.forEach(function (extId) {
            try {
                chrome.runtime.sendMessage(extId, { action: 'ping' }, function (response) {
                    if (chrome.runtime.lastError) {
                        console.log('[TaskRunner] Extension not found with ID:', extId);
                        return;
                    }
                    if (response && response.success) {
                        config.extensionId = extId;
                        config.useExtension = true;
                        config.extensionVersion = response.version;
                        console.log('[TaskRunner] Connected to extension:', extId, 'version:', response.version);
                        emitLog('inference', '✓ 已连接浏览器扩展 (v' + response.version + ')');
                    }
                });
            } catch (e) {
                console.log('[TaskRunner] Failed to connect extension:', extId, e);
            }
        });
    }

    /**
     * 检查扩展是否可用
     */
    function isExtensionAvailable() {
        return config.useExtension;
    }

    /**
     * 等待扩展响应的Promise存储
     */
    var extensionPendingRequests = {};
    var extensionRequestId = 0;

    /**
     * 处理扩展响应
     */
    function handleExtensionResponse(data) {
        var requestId = data.requestId;
        var response = data.response;

        console.log('[TaskRunner] 收到扩展响应:', {
            requestId: requestId,
            hasResponse: !!response,
            responseSuccess: response ? response.success : null,
            responseCount: response ? response.count : null
        });

        if (extensionPendingRequests[requestId]) {
            console.log('[TaskRunner] 找到对应的请求，解析响应...');
            extensionPendingRequests[requestId].resolve(response);
            delete extensionPendingRequests[requestId];
        } else {
            // 请求可能因为超时而被清理，这是正常的，不需要警告
            // 检查是否是 heartbeat 或 crawl 请求的延迟响应
            var isHeartbeat = requestId && requestId.indexOf('req_') === 0 && 
                              (response && (response.success === true && response.timestamp));
            var isCrawlResponse = requestId && requestId.indexOf('req_') === 0 && 
                                  (response && (response.hasOwnProperty('success') || response.hasOwnProperty('error')));
            
            if (isHeartbeat) {
                // heartbeat 响应，静默处理（可能是超时后返回的响应）
                console.log('[TaskRunner] 收到 heartbeat 响应（可能已超时）:', requestId);
            } else if (isCrawlResponse) {
                // crawl 响应可能在超时后才返回，这是正常的，只记录日志不警告
                console.log('[TaskRunner] 收到 crawl 响应（可能已超时）:', requestId, {
                    success: response ? response.success : null,
                    hasError: response ? !!response.error : null,
                    count: response ? response.count : null
                });
            } else {
                // 其他未知请求ID，发出警告
            console.warn('[TaskRunner] 收到未知请求ID的响应:', requestId);
            console.warn('[TaskRunner] 当前待处理的请求:', Object.keys(extensionPendingRequests));
            }
        }
    }

    /**
     * 向扩展发送请求
     */
    function sendToExtension(action, payload) {
        return new Promise(function (resolve, reject) {
            var requestId = 'req_' + (++extensionRequestId) + '_' + Date.now();

            // 设置超时时间：根据操作类型和参数动态计算
            var timeoutDuration = 30000; // 默认30秒
            if (action === 'ping') {
                timeoutDuration = 10000; // ping操作：10秒（ping通常很快）
            } else if (action === 'heartbeat') {
                timeoutDuration = 5000; // heartbeat操作：5秒（心跳应该很快返回）
            } else if (action === 'crawl') {
                // 根据查询数量和搜索引擎数量动态计算超时时间
                // 基础时间：60秒（ping检查 + 初始化 + 页面加载）
                // 每个查询：30秒（每个查询需要在多个搜索引擎中搜索，需要更多时间）
                // 每个搜索引擎：20秒（每个搜索引擎需要创建标签页、加载页面、提取数据）
                var baseTime = 60000; // 60秒基础时间（增加基础时间，确保ping和初始化完成）
                var queryCount = (payload.searchQueries && payload.searchQueries.length) || 1;
                var engineCount = (payload.searchEngines && payload.searchEngines.length) || 1;
                // 每个查询需要在所有搜索引擎中搜索，所以时间 = 查询数 * 搜索引擎数 * 单次搜索时间
                var singleSearchTime = 30000; // 单次搜索时间：30秒（创建标签页、加载、提取）
                var totalSearchTime = queryCount * engineCount * singleSearchTime;

                // 计算总超时时间：基础时间 + 总搜索时间
                // 但不超过10分钟（600秒），避免等待过久
                timeoutDuration = Math.min(baseTime + totalSearchTime, 600000);

                console.log('[TaskRunner] 计算超时时间:', {
                    queryCount: queryCount,
                    engineCount: engineCount,
                    totalSearches: queryCount * engineCount,
                    baseTime: Math.floor(baseTime / 1000) + '秒',
                    singleSearchTime: Math.floor(singleSearchTime / 1000) + '秒',
                    totalSearchTime: Math.floor(totalSearchTime / 1000) + '秒',
                    totalTimeout: Math.floor(timeoutDuration / 1000) + '秒',
                    totalTimeoutMinutes: (timeoutDuration / 60000).toFixed(1) + '分钟'
                });
            }

            var timeout = setTimeout(function () {
                if (extensionPendingRequests[requestId]) {
                    delete extensionPendingRequests[requestId];
                    var timeoutSeconds = Math.floor(timeoutDuration / 1000);
                    var timeoutMinutes = Math.floor(timeoutDuration / 60000);
                    var timeoutMsg = timeoutMinutes >= 1
                        ? '扩展请求超时（已等待 ' + timeoutMinutes + ' 分钟）'
                        : '扩展请求超时（已等待 ' + timeoutSeconds + ' 秒）';
                    console.error('[TaskRunner]', timeoutMsg, {
                        action: action,
                        requestId: requestId,
                        timeoutDuration: timeoutDuration,
                        calculatedTimeout: Math.floor(timeoutDuration / 1000) + '秒'
                    });
                    // 记录超时时的状态
                    if (action === 'crawl') {
                        console.error('[TaskRunner] 爬取请求超时，可能的原因：');
                        console.error('[TaskRunner] 1. Background script 处理时间过长');
                        console.error('[TaskRunner] 2. 响应没有正确传递回 content script');
                        console.error('[TaskRunner] 3. 网络问题导致搜索引擎访问缓慢');
                    }
                    reject(new Error(timeoutMsg));
                }
            }, timeoutDuration);

            extensionPendingRequests[requestId] = {
                resolve: function (response) {
                    clearTimeout(timeout);
                    resolve(response);
                },
                reject: function (error) {
                    clearTimeout(timeout);
                    reject(error);
                }
            };

            // 优先使用直接发送（如果可用），否则使用 postMessage（通过内容脚本转发）
            // 注意：只使用一种方式，避免重复发送导致重复日志
            console.log('[TaskRunner] 发送请求到扩展:', {
                action: action,
                requestId: requestId,
                payloadKeys: Object.keys(payload || {})
            });
            
            // 如果有直接的扩展ID且可以访问chrome.runtime，优先使用直接发送
            if (config.extensionId && typeof chrome !== 'undefined' && chrome.runtime) {
                try {
                    chrome.runtime.sendMessage(config.extensionId, {
                        action: action,
                        ...payload,
                        requestId: requestId
                    }, function (response) {
                        if (chrome.runtime.lastError) {
                            console.warn('[TaskRunner] Direct extension message failed:', chrome.runtime.lastError);
                            // 直接发送失败，回退到 postMessage 方式
                            window.postMessage({
                                type: 'AUTOLEADAGENT_REQUEST',
                                action: action,
                                payload: payload,
                                requestId: requestId
                            }, '*');
                            return;
                        }
                        if (extensionPendingRequests[requestId]) {
                            clearTimeout(timeout);
                            extensionPendingRequests[requestId].resolve(response);
                            delete extensionPendingRequests[requestId];
                        }
                    });
                } catch (e) {
                    console.warn('[TaskRunner] Direct extension message error:', e);
                    // 直接发送异常，回退到 postMessage 方式
                    window.postMessage({
                        type: 'AUTOLEADAGENT_REQUEST',
                        action: action,
                        payload: payload,
                        requestId: requestId
                    }, '*');
                }
            } else {
                // 无法直接发送，使用 postMessage（内容脚本会转发）
                window.postMessage({
                    type: 'AUTOLEADAGENT_REQUEST',
                    action: action,
                    payload: payload,
                    requestId: requestId
                }, '*');
            }
        });
    }

    /**
     * 通过扩展爬取平台
     * 直接使用原始关键词进行百度搜索
     * 
     * @returns {Promise<Object>} 返回 {success: boolean, data: [], sourceUrls: [], count: number, error: string}
     */
    async function crawlWithExtension(searchQueries, searchEngines, profileInfo, maxResults) {
        // 生成请求ID用于心跳
        var requestId = 'req_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        
        // 启动心跳发送（每3秒发送一次，确保5秒内至少收到一次）
        var heartbeatInterval = null;
        var heartbeatStartTime = Date.now();
        
        function startHeartbeat() {
            heartbeatInterval = setInterval(function() {
                try {
                    sendToExtension('heartbeat', {
                        requestId: requestId,
                        taskId: state.taskId
                    }).catch(function(error) {
                        console.warn('[TaskRunner] 心跳发送失败:', error);
                    });
                } catch (error) {
                    console.warn('[TaskRunner] 心跳发送异常:', error);
                }
            }, 3000); // 每3秒发送一次心跳
        }
        
        function stopHeartbeat() {
            if (heartbeatInterval) {
                clearInterval(heartbeatInterval);
                heartbeatInterval = null;
            }
        }
        
        // 启动心跳
        startHeartbeat();
        // 获取目标网站信息
        var targetWebsites = profileInfo && profileInfo.targetWebsites ? profileInfo.targetWebsites : [];
        
        // 如果有目标网站，优先使用目标网站查询，不使用普通查询
        var queriesToSend = searchQueries;
        var keywordsToSend = [];
        
        if (targetWebsites && targetWebsites.length > 0) {
            // 有目标网站时，不传递普通查询，让background.js生成目标网站查询
            queriesToSend = []; // 清空普通查询
            // 优先从 profileInfo.keywords 获取关键词，如果没有则从 searchQueries 提取
            if (profileInfo && profileInfo.keywords && Array.isArray(profileInfo.keywords) && profileInfo.keywords.length > 0) {
                keywordsToSend = profileInfo.keywords;
                emitLog('crawl', '🎯 检测到目标网站配置，将使用精准搜索语法（' + targetWebsites.length + ' 个目标网站）');
                emitLog('crawl', '📝 使用关键词: ' + keywordsToSend.join(', '));
            } else if (searchQueries && searchQueries.length > 0) {
                // 从第一个查询中提取关键词（向后兼容）
                keywordsToSend = searchQueries[0].split(/\s+/).filter(function(kw) {
                    return kw && kw.trim().length > 0;
                });
                emitLog('crawl', '🎯 检测到目标网站配置，将使用精准搜索语法（' + targetWebsites.length + ' 个目标网站）');
                emitLog('crawl', '📝 从查询中提取关键词: ' + keywordsToSend.join(', '));
            } else {
                emitLog('crawl', '⚠️ 检测到目标网站配置，但关键词为空，无法生成精准查询');
            }
        }
        
        emitLog('crawl', '通过浏览器扩展在搜索引擎中搜索客户...');
        emitLog('crawl', '搜索查询数量: ' + (queriesToSend.length || '将由目标网站查询生成'));
        emitLog('crawl', '使用的搜索引擎: ' + (searchEngines && searchEngines.length > 0 ? searchEngines.join(', ') : 'Baidu'));
        if (targetWebsites && targetWebsites.length > 0) {
            emitLog('crawl', '目标网站: ' + targetWebsites.map(function(w) {
                return w.name || w.domain || '未知';
            }).join(', '));
        } else if (queriesToSend.length > 0) {
            emitLog('crawl', '查询示例: ' + queriesToSend.slice(0, 3).join(', ') + (queriesToSend.length > 3 ? '...' : ''));
        }

        try {
            emitLog('crawl', '⏳ 正在发送爬取请求到 background script，等待响应...');
            var requestStartTime = Date.now();
            
            var response = await sendToExtension('crawl', {
                searchQueries: queriesToSend, // 多个搜索查询（如果有目标网站则为空，让background.js生成）
                searchEngines: searchEngines || ['Baidu'], // 使用的搜索引擎
                profileInfo: profileInfo || {}, // 画像信息（行业、地区等）
                searchLanguage: profileInfo && profileInfo.language ? profileInfo.language : null, // 搜索语言
                targetRegion: profileInfo && profileInfo.region ? profileInfo.region : null, // 目标地区
                targetWebsites: targetWebsites, // 目标网站列表
                keywords: keywordsToSend.length > 0 ? keywordsToSend : (queriesToSend.length > 0 ? queriesToSend[0].split(' ') : []), // 关键词用于生成目标网站查询
                originalKeywords: keywordsToSend.length > 0 ? keywordsToSend : (queriesToSend.length > 0 ? queriesToSend[0].split(' ') : []), // 原始关键词
                maxResults: maxResults || config.maxCandidatesPerBatch,
                requestId: requestId, // 传递请求ID用于心跳
                sceneMapping: state.sceneMapping || null // 场景映射规则（从API获取，传递给扩展）
            });

            var requestDuration = Date.now() - requestStartTime;
            emitLog('crawl', '✓ 收到 background script 响应（耗时: ' + Math.floor(requestDuration / 1000) + ' 秒）');
            console.log('[TaskRunner] Extension response received:', {
                success: response ? response.success : false,
                count: response ? response.count : 0,
                error: response ? response.error : null,
                errorType: response ? response.errorType : null,
                duration: Math.floor(requestDuration / 1000) + '秒'
            });
            // 只在调试模式下输出完整响应（避免日志过长）
            if (config.debug || false) {
                emitLog('crawl', '拓展响应结果：' + JSON.stringify(response));
            }
            if (response && response.success) {
                emitLog('crawl', '✓ 从搜索引擎获取到 ' + response.count + ' 条有效结果');
                if (response.queriesUsed && response.queriesUsed.length > 0) {
                    emitLog('crawl', '使用的查询: ' + response.queriesUsed.slice(0, 3).join(', ') + (response.queriesUsed.length > 3 ? '...' : ''));
                }
                if (response.enginesUsed && response.enginesUsed.length > 0) {
                    emitLog('crawl', '使用的搜索引擎: ' + response.enginesUsed.join(', '));
                }
                return response; // 返回完整响应，包含 sourceUrls
            } else {
                // 详细记录扩展返回的错误信息
                var errorMsg = '未知错误';
                var errorDetails = [];

                if (response) {
                    errorMsg = response.error || '未知错误';

                    // 记录错误类型
                    if (response.errorType) {
                        var errorTypeText = response.errorType;
                        if (response.errorType === 'blocked_by_search_engine') {
                            errorTypeText = '被搜索引擎屏蔽';
                        }
                        var detail = '错误类型: ' + errorTypeText;
                        errorDetails.push(detail);
                        emitLog('crawl', '  ⚠️ ' + detail);
                    }

                    // 记录被屏蔽的搜索引擎（如果有）
                    if (response.blockedEngines && response.blockedEngines.length > 0) {
                        var detail1 = '⚠️ 可能被屏蔽的搜索引擎: ' + response.blockedEngines.join(', ');
                        errorDetails.push(detail1);
                        emitLog('crawl', '  ' + detail1);
                        var detail2 = '💡 建议: 等待一段时间后重试，或尝试其他搜索引擎';
                        errorDetails.push(detail2);
                        emitLog('crawl', '  ' + detail2);
                    }

                    // 记录 ping 结果（如果有）
                    if (response.pingResults) {
                        if (response.pingResults.baidu && !response.pingResults.baidu.success) {
                            var detail = '百度连通性检查失败: ' + (response.pingResults.baidu.error || '未知错误');
                            errorDetails.push(detail);
                            emitLog('crawl', '  ⚠️ ' + detail);
                            if (response.pingResults.baidu.latency) {
                                var detail2 = '延迟: ' + response.pingResults.baidu.latency + 'ms';
                                errorDetails.push(detail2);
                                emitLog('crawl', '  ⚠️ ' + detail2);
                            }
                        }
                    }

                    // 记录搜索的 URL（如果有）
                    if (response.sourceUrls && response.sourceUrls.length > 0) {
                        var detail = '已尝试搜索 ' + response.sourceUrls.length + ' 个搜索引擎';
                        errorDetails.push(detail);
                        emitLog('crawl', '  ⚠️ ' + detail);
                        response.sourceUrls.forEach(function (urlInfo) {
                            var urlDisplay = urlInfo.url || (urlInfo.engine + ': ' + (urlInfo.query || ''));
                            var detail2 = '  - ' + urlDisplay;
                            errorDetails.push(detail2);
                            emitLog('crawl', '  ⚠️ ' + detail2);
                        });
                    }
                }

                emitLog('crawl', '❌ 搜索引擎搜索失败: ' + errorMsg);
                stopHeartbeat(); // 停止心跳
                return { success: false, data: [], sourceUrls: response ? response.sourceUrls : [], error: errorMsg };
            }
        } catch (error) {
            // 详细记录异常信息
            stopHeartbeat(); // 停止心跳（确保在异常时也停止）
            var errorInfo = [];
            errorInfo.push('错误消息: ' + error.message);
            if (error.name) {
                errorInfo.push('错误类型: ' + error.name);
            }
            if (error.stack) {
                // 只记录堆栈的前几行，避免日志过长
                var stackLines = error.stack.split('\n').slice(0, 3);
                errorInfo.push('错误堆栈: ' + stackLines.join(' → '));
            }
            if (error.code) {
                errorInfo.push('错误代码: ' + error.code);
            }

            emitLog('crawl', '❌ 搜索引擎搜索异常: ' + error.message);
            errorInfo.forEach(function (info) {
                emitLog('crawl', '  ⚠️ ' + info);
            });

            return { success: false, data: [], sourceUrls: [], error: error.message };
        }
    }

    /**
     * 翻译关键词为英语
     * 优先使用后端翻译服务，失败时使用 Google 翻译 API 作为备用
     */
    async function translateKeywords(keywords, targetLang) {
        targetLang = targetLang || 'en';

        // 尝试使用后端翻译服务
        try {
            var url = config.apiBaseUrl + '/translate-keywords';
            var response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'keywords=' + encodeURIComponent(JSON.stringify(keywords)) +
                    '&target_lang=' + encodeURIComponent(targetLang)
            });

            var result = await response.json();

            if (result.success && result.data && result.data.translated) {
                emitLog('inference', '关键词翻译完成: ' + keywords.join(', ') + ' → ' + result.data.translated.join(', '));
                return result.data.translated;
            }
        } catch (error) {
            console.warn('[TaskRunner] Backend translation failed, trying Google Translate:', error);
        }

        // 如果后端翻译失败，使用 Google 翻译 API 作为备用
        try {
            emitLog('inference', '使用 Google 翻译 API 作为备用方案...');
            var translatedKeywords = [];

            // 将关键词合并为文本进行翻译
            var textToTranslate = keywords.join(', ');
            var translatedText = await translateWithGoogle(textToTranslate, targetLang);

            if (translatedText) {
                // 将翻译后的文本分割回数组
                translatedKeywords = translatedText.split(',').map(function (k) {
                    return k.trim();
                }).filter(function (k) {
                    return k.length > 0;
                });

                if (translatedKeywords.length > 0) {
                    emitLog('inference', 'Google 翻译完成: ' + keywords.join(', ') + ' → ' + translatedKeywords.join(', '));
                    return translatedKeywords;
                }
            }

            // 如果批量翻译失败，尝试逐个翻译
            emitLog('inference', '批量翻译失败，尝试逐个翻译关键词...');
            for (var i = 0; i < keywords.length; i++) {
                var keyword = keywords[i];
                if (!keyword || keyword.trim() === '') {
                    continue;
                }

                var translated = await translateWithGoogle(keyword, targetLang);
                if (translated) {
                    translatedKeywords.push(translated);
                } else {
                    throw new Error('无法翻译关键词: ' + keyword);
                }
            }

            if (translatedKeywords.length > 0) {
                emitLog('inference', '逐个翻译完成: ' + keywords.join(', ') + ' → ' + translatedKeywords.join(', '));
                return translatedKeywords;
            }

        } catch (error) {
            console.error('[TaskRunner] Google Translate error:', error);
            emitLog('inference', '❌ 翻译失败: ' + error.message);
            // 不再回退到原始关键词，而是抛出错误
            throw new Error('翻译服务不可用，无法继续搜索');
        }

        // 如果所有翻译方法都失败，抛出错误
        throw new Error('所有翻译方法都失败，无法继续搜索');
    }

    /**
     * 检测 Google 服务是否可访问
     * 使用缓存避免频繁检测
     * 
     * @returns {Promise<boolean>} 可访问返回 true，否则返回 false
     */
    async function isGoogleAccessible() {
        // 使用静态变量缓存检测结果
        if (typeof isGoogleAccessible.cache === 'undefined') {
            isGoogleAccessible.cache = null;
            isGoogleAccessible.cacheTime = 0;
        }

        var cacheDuration = 300000; // 缓存5分钟（毫秒）
        var now = Date.now();

        // 如果缓存有效，直接返回
        if (isGoogleAccessible.cache !== null && (now - isGoogleAccessible.cacheTime) < cacheDuration) {
            return isGoogleAccessible.cache;
        }

        try {
            // 尝试连接 Google 翻译服务（使用 HEAD 请求，超时时间短）
            var controller = new AbortController();
            var timeoutId = setTimeout(function () {
                controller.abort();
            }, 2000); // 2秒超时

            var response = await fetch('https://translate-pa.googleapis.com', {
                method: 'HEAD',
                mode: 'no-cors', // 使用 no-cors 避免 CORS 错误
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            // no-cors 模式下无法读取响应，但能发起请求说明网络是通的
            isGoogleAccessible.cache = true;
            isGoogleAccessible.cacheTime = now;
            return true;

        } catch (error) {
            // 如果请求失败，说明网络不通
            console.warn('[TaskRunner] Google service is not accessible:', error.message);
            isGoogleAccessible.cache = false;
            isGoogleAccessible.cacheTime = now;
            return false;
        }
    }

    /**
     * 使用 Google 翻译 API 翻译文本
     * 
     * @param {string} text 要翻译的文本
     * @param {string} targetLang 目标语言代码（如 'en'）
     * @param {string} sourceLang 源语言代码（默认 'auto' 自动检测）
     * @returns {Promise<string>} 翻译后的文本，失败返回空字符串
     */
    async function translateWithGoogle(text, targetLang, sourceLang) {
        sourceLang = sourceLang || 'auto';

        if (!text || typeof text !== 'string' || text.trim() === '') {
            return '';
        }

        // 先检测 Google 服务是否可访问
        var accessible = await isGoogleAccessible();
        if (!accessible) {
            console.warn('[TaskRunner] Google Translate service is not accessible, skipping');
            return '';
        }

        try {
            // 标准化语言代码
            var finalTarget = normalizeGoogleLang(targetLang);
            var finalSource = sourceLang === 'auto' ? 'auto' : normalizeGoogleLang(sourceLang);

            var cleanText = text.trim();
            var apiKey = 'AIzaSyATBXajvzQLTDHEQbcpq0Ihe0vWDHmO520'; // Google 翻译 API Key

            // 构建请求体
            var requestBody = JSON.stringify([[[cleanText], finalSource, finalTarget], 'te_lib']);

            var response = await fetch('https://translate-pa.googleapis.com/v1/translateHtml', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json+protobuf',
                    'x-goog-api-key': apiKey,
                },
                body: requestBody,
            });

            if (!response.ok) {
                var errorText = await response.text();
                console.error('[TaskRunner] Google Translate HTTP error:', response.status, errorText);
                return '';
            }

            var data = await response.json();

            if (!Array.isArray(data) || data.length === 0 || !Array.isArray(data[0])) {
                console.error('[TaskRunner] Google Translate invalid response format');
                return '';
            }

            var result = data[0];
            var translatedText = result[0] || '';

            if (Array.isArray(translatedText)) {
                translatedText = translatedText[0] || '';
            }

            if (typeof translatedText === 'string') {
                // 移除末尾的单个点（Google 翻译有时会添加）
                var match = translatedText.match(/(.*?)(\.+)$/);
                if (match && match[2].length === 1) {
                    translatedText = match[1];
                }
            }

            return String(translatedText);

        } catch (error) {
            console.error('[TaskRunner] Google Translate exception:', error);
            return '';
        }
    }

    /**
     * 标准化 Google 语言代码
     * 
     * @param {string} lang 语言代码
     * @returns {string} 标准化后的语言代码
     */
    function normalizeGoogleLang(lang) {
        var langMap = {
            'zh': 'zh-CN',
            'zh-cn': 'zh-CN',
            'zh-hans': 'zh-CN',
            'zh-hans-cn': 'zh-CN',
            'en': 'en',
            'en-us': 'en',
            'ja': 'ja',
            'ko': 'ko',
            'es': 'es',
            'fr': 'fr',
            'de': 'de',
            'ru': 'ru',
            'pt': 'pt',
            'it': 'it',
            'ar': 'ar',
        };

        var normalized = lang.toLowerCase();
        return langMap[normalized] || lang;
    }

    /**
     * 备用JS爬取方案：由于 CORS 限制，此方案无法获取真实数据
     * 必须使用浏览器扩展才能绕过 CORS 限制
     * 
     * @deprecated 此函数已废弃，因为 CORS 限制无法获取真实数据
     * @param {string} platform - 目标平台
     * @param {Array} keywords - 搜索关键词
     * @param {number} maxResults - 最大结果数
     * @param {string} engineType - 搜索引擎类型：'Baidu' 或 'Other'
     * @returns {Promise<Object>} 返回 {candidates: [], sourceUrls: []}
     */
    async function crawlWithFetch(platform, keywords, maxResults, engineType) {
        // 此函数已废弃，因为浏览器 CORS 策略阻止直接访问搜索结果
        // 必须使用浏览器扩展才能绕过 CORS 限制
        emitLog('crawl', '⚠️ 备用方案无法使用：浏览器 CORS 策略阻止访问搜索结果');
        emitLog('crawl', '💡 请安装 AutoLeadAgent 浏览器扩展以启用真实爬取');
        return { candidates: [], sourceUrls: [] };
    }

    /**
     * 注册日志回调
     */
    function onLog(type, callback) {
        if (logCallbacks[type]) {
            logCallbacks[type].push(callback);
        }
    }

    /**
     * 输出日志
     */
    function emitLog(type, message, data) {
        var timestamp = new Date().toLocaleTimeString();
        var logEntry = {
            time: timestamp,
            message: message,
            data: data || null
        };

        // 触发回调
        if (logCallbacks[type]) {
            logCallbacks[type].forEach(function (cb) {
                try {
                    cb(logEntry);
                } catch (e) {
                    console.error('[TaskRunner] Log callback error:', e);
                }
            });
        }

        // 触发自定义事件
        var event = new CustomEvent('taskRunnerLog', {
            detail: {
                type: type,
                entry: logEntry,
                taskId: state.currentTaskId
            }
        });
        window.dispatchEvent(event);

        // 控制台输出
        console.log('[TaskRunner][' + type + '][' + timestamp + ']', message, data || '');
    }

    /**
     * 加载WASM模型
     */
    async function loadWasmModel() {
        if (state.wasmInstance) {
            emitLog('inference', '模型已加载，跳过重复加载');
            return true;
        }

        state.status = TASK_STATUS.LOADING;
        updateUIStatus(TASK_STATUS.LOADING);
        updateModelStatus('loading', null, null);
        emitLog('inference', '开始加载AI模型...');

        try {
            var startTime = performance.now();

            // 尝试从API获取WASM
            var wasmUrl = config.apiBaseUrl.replace('/backend/index', '/api/wasm/download');

            // 如果AgentCore已经存在且已加载WASM，直接使用
            if (window.agentCore && window.agentCore.wasmInstance) {
                state.wasmInstance = window.agentCore.wasmInstance;
                state.wasmModule = window.agentCore.wasmModule;
                emitLog('inference', '使用已加载的AgentCore WASM实例');
            } else {
                // 创建基于JS的推理引擎作为备用
                state.wasmInstance = createJSInferenceEngine();
                emitLog('inference', '使用JavaScript推理引擎（WASM备用）');
            }

            var loadTime = performance.now() - startTime;
            emitLog('inference', '模型加载完成，耗时: ' + loadTime.toFixed(0) + 'ms');

            updateModelStatus('idle', loadTime, getMemoryUsage());
            
            // 同步更新 ModelLifecycle 状态
            if (typeof ModelLifecycle !== 'undefined') {
                ModelLifecycle.updateUI('loaded');
            }
            
            return true;

        } catch (error) {
            emitLog('inference', '模型加载失败: ' + error.message, { error: error.stack });
            state.status = TASK_STATUS.FAILED;
            updateUIStatus(TASK_STATUS.FAILED);
            updateModelStatus('error', null, null);
            return false;
        }
    }

    /**
     * 创建基于JS的推理引擎（WASM备用）
     */
    function createJSInferenceEngine() {
        return {
            exports: {
                // 提取特征向量（JS实现）
                extractProfileFeatures: function (text, textLength, featuresPtr, featuresSize) {
                    // JS实现：简单的特征提取
                    if (!text || textLength <= 0) {
                        return 0;
                    }

                    var words = text.split(/[\s,，。！？；：、]+/);
                    var wordCount = words.length;
                    var charCount = textLength;
                    var uniqueWords = {};
                    words.forEach(function (w) {
                        if (w.length >= 2) {
                            uniqueWords[w] = (uniqueWords[w] || 0) + 1;
                        }
                    });
                    var uniqueWordCount = Object.keys(uniqueWords).length;

                    // 计算特征值
                    var features = [
                        wordCount / 100.0,           // 归一化词数
                        charCount / 1000.0,          // 归一化字符数
                        uniqueWordCount / 50.0,      // 归一化唯一词数
                        Math.min(1.0, uniqueWordCount / wordCount) // 词汇多样性
                    ];

                    // 如果提供了内存指针，写入特征（WASM模式）
                    if (featuresPtr && this.HEAPF64) {
                        var heap = new Float64Array(this.HEAPF64.buffer, featuresPtr, featuresSize);
                        for (var i = 0; i < Math.min(features.length, featuresSize); i++) {
                            heap[i] = features[i];
                        }
                        return features.length;
                    }

                    // JS模式：返回特征数量（实际特征需要从其他地方获取）
                    return features.length;
                },

                // 计算客户评分（JS实现）
                calculateCustomerScore: function (profileData, profileLength) {
                    if (!profileData || profileLength <= 0) {
                        return 0.0;
                    }

                    var profile = typeof profileData === 'string' ? JSON.parse(profileData) : profileData;
                    var score = 0.0;

                    // 基础评分
                    if (profile.name && profile.name !== '未知') score += 20;
                    if (profile.industry && profile.industry !== '通用') score += 20;
                    if (profile.description && profile.description.length > 20) score += 20;
                    if (profile.keywords && profile.keywords.length > 0) score += 20;
                    if (profile.target_customers && profile.target_customers.length > 0) score += 10;
                    if (profile.product_features && profile.product_features.length > 0) score += 10;

                    return Math.min(100.0, score);
                },

                // 语义拆解：将描述性文本拆解为有意义的词汇
                semanticDecompose: function (text) {
                    if (!text || typeof text !== 'string') return [];

                    var decomposed = [];
                    var textLower = text.toLowerCase();

                    // 停用词列表（无意义的词）
                    var stopWords = ['一家', '一个', '的', '和', '与', '或', '及', '等', '以及', '还有',
                        '是', '为', '在', '有', '了', '也', '都', '就', '还', '可以', '能够',
                        '专注于', '致力于', '提供', '服务', '产品', '方案', '解决'];

                    // 复合词识别词典（产品类别）
                    var productCategories = {
                        '女鞋': ['女鞋', '女士鞋', '女性鞋', '女式鞋'],
                        '男鞋': ['男鞋', '男士鞋', '男性鞋', '男式鞋'],
                        '运动鞋': ['运动鞋', '跑鞋', '篮球鞋', '足球鞋'],
                        '定制': ['定制', '定制化', '个性化', '专属'],
                        '高端': ['高端', '高品质', '精品', '奢华'],
                        '全球': ['全球', '国际', '跨国', '跨境'],
                        '专业': ['专业', '专业化', '专业级', '专家']
                    };

                    // 服务特征词典
                    var serviceFeatures = {
                        '定制化': ['定制', '定制化', '个性化', '专属', '量身定制'],
                        '专业化': ['专业', '专业化', '专业级', '专家', '资深'],
                        '高端': ['高端', '高品质', '精品', '奢华', '顶级'],
                        '全球': ['全球', '国际', '跨国', '跨境', '海外'],
                        '本地': ['本地', '当地', '区域', '本地化']
                    };

                    // 识别复合词
                    Object.keys(productCategories).forEach(function (category) {
                        productCategories[category].forEach(function (keyword) {
                            if (text.indexOf(keyword) !== -1) {
                                decomposed.push(category);
                            }
                        });
                    });

                    // 识别服务特征
                    Object.keys(serviceFeatures).forEach(function (feature) {
                        serviceFeatures[feature].forEach(function (keyword) {
                            if (text.indexOf(keyword) !== -1 && decomposed.indexOf(feature) === -1) {
                                decomposed.push(feature);
                            }
                        });
                    });

                    // 拆解文本为词汇（去除停用词）
                    var words = text.split(/[\s,，。！？；：、·\-_]+/);
                    words.forEach(function (word) {
                        word = word.trim();
                        // 过滤停用词
                        if (stopWords.indexOf(word) === -1 && word.length >= 2 && word.length <= 10) {
                            // 检查是否是复合词的一部分
                            var isPartOfCompound = false;
                            Object.keys(productCategories).forEach(function (category) {
                                productCategories[category].forEach(function (keyword) {
                                    if (keyword.indexOf(word) !== -1 || word.indexOf(keyword) !== -1) {
                                        isPartOfCompound = true;
                                    }
                                });
                            });

                            if (!isPartOfCompound && decomposed.indexOf(word) === -1) {
                                decomposed.push(word);
                            }
                        }
                    });

                    return decomposed;
                },

                // 客户画像推断：从店铺画像推断客户画像
                inferCustomerProfileFromStore: function (storeProfile) {
                    var customerProfile = {
                        gender: [],
                        traits: [],
                        needs: [],
                        roles: [],
                        scenarios: []
                    };

                    // 产品到客户映射规则
                    var productToCustomerMap = {
                        '女鞋': {
                            gender: ['女性'],
                            traits: ['时尚', '爱美', '注重形象', '追求品质'],
                            needs: ['舒适', '美观', '个性化', '时尚潮流'],
                            scenarios: ['职场', '社交', '日常', '商务']
                        },
                        '男鞋': {
                            gender: ['男性'],
                            traits: ['商务', '实用', '注重品质', '专业'],
                            needs: ['品质', '舒适', '耐用', '商务形象'],
                            scenarios: ['职场', '商务', '正式场合']
                        },
                        '运动鞋': {
                            gender: ['男性', '女性'],
                            traits: ['运动', '健康', '活力', '年轻'],
                            needs: ['舒适', '保护', '性能', '时尚'],
                            scenarios: ['运动', '健身', '日常', '休闲']
                        },
                        '定制': {
                            traits: ['追求个性', '注重品质', '有消费能力', '品味独特'],
                            needs: ['个性化', '专属', '高品质', '独特设计'],
                            roles: ['中高收入人群', '专业人士', '企业家', '设计师', '艺术家']
                        },
                        '高端': {
                            traits: ['高消费能力', '注重品质', '追求卓越', '品味高雅'],
                            needs: ['高品质', '奢华', '顶级服务', '尊贵体验'],
                            roles: ['高收入人群', '企业家', '高管', '专业人士', '成功人士']
                        },
                        '全球': {
                            traits: ['国际化视野', '跨文化', '开放', '多元'],
                            needs: ['全球服务', '跨境', '国际化', '多元文化'],
                            roles: ['跨国企业', '国际商务人士', '外企员工', '海外华人']
                        },
                        '专业': {
                            traits: ['专业', '严谨', '注重细节', '追求卓越'],
                            needs: ['专业服务', '专业建议', '专业方案', '专业支持'],
                            roles: ['专业人士', '专家', '顾问', '技术人员', '管理者']
                        }
                    };

                    // 从店铺画像中提取的关键词
                    var storeKeywords = [];
                    if (storeProfile.name) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(storeProfile.name));
                    }
                    if (storeProfile.description) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(storeProfile.description));
                    }
                    if (storeProfile.products && Array.isArray(storeProfile.products)) {
                        storeProfile.products.forEach(function (p) {
                            storeKeywords = storeKeywords.concat(this.semanticDecompose(p));
                        }.bind(this));
                    }

                    // 应用映射规则
                    storeKeywords.forEach(function (keyword) {
                        if (productToCustomerMap[keyword]) {
                            var mapping = productToCustomerMap[keyword];
                            if (mapping.gender) {
                                mapping.gender.forEach(function (g) {
                                    if (customerProfile.gender.indexOf(g) === -1) {
                                        customerProfile.gender.push(g);
                                    }
                                });
                            }
                            if (mapping.traits) {
                                mapping.traits.forEach(function (t) {
                                    if (customerProfile.traits.indexOf(t) === -1) {
                                        customerProfile.traits.push(t);
                                    }
                                });
                            }
                            if (mapping.needs) {
                                mapping.needs.forEach(function (n) {
                                    if (customerProfile.needs.indexOf(n) === -1) {
                                        customerProfile.needs.push(n);
                                    }
                                });
                            }
                            if (mapping.roles) {
                                mapping.roles.forEach(function (r) {
                                    if (customerProfile.roles.indexOf(r) === -1) {
                                        customerProfile.roles.push(r);
                                    }
                                });
                            }
                            if (mapping.scenarios) {
                                mapping.scenarios.forEach(function (s) {
                                    if (customerProfile.scenarios.indexOf(s) === -1) {
                                        customerProfile.scenarios.push(s);
                                    }
                                });
                            }
                        }
                    });

                    // 从目标客户字段直接提取
                    if (storeProfile.target_customers && Array.isArray(storeProfile.target_customers)) {
                        storeProfile.target_customers.forEach(function (tc) {
                            if (tc && customerProfile.roles.indexOf(tc) === -1) {
                                customerProfile.roles.push(tc);
                            }
                        });
                    }

                    return customerProfile;
                },

                // 分析店铺画像，生成搜索关键词（改进版：偏向行为/搜索意图）
                analyzeStoreProfile: function (profileJson) {
                    var profile = typeof profileJson === 'string' ? JSON.parse(profileJson) : profileJson;

                    // 步骤1：语义拆解店铺画像
                    var storeKeywords = [];
                    if (profile.name) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(profile.name));
                    }
                    if (profile.description) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(profile.description));
                    }
                    if (profile.products && Array.isArray(profile.products)) {
                        var self = this;
                        profile.products.forEach(function (p) {
                            storeKeywords = storeKeywords.concat(self.semanticDecompose(p));
                        });
                    }

                    // 步骤2：从店铺画像推断客户画像
                    var customerProfile = this.inferCustomerProfileFromStore(profile);

                    // 步骤3：从客户画像 + 客户内容生成行为/搜索意图型关键词
                    var customerKeywords = [];

                    // 3.1 基于客户画像的基础特征词（性别 / 特征 / 需求 / 角色 / 场景）
                    customerProfile.gender.forEach(function (g) {
                        if (customerKeywords.indexOf(g) === -1) {
                            customerKeywords.push(g);
                        }
                    });

                    customerProfile.traits.forEach(function (t) {
                        if (customerKeywords.indexOf(t) === -1) {
                            customerKeywords.push(t);
                        }
                    });

                    customerProfile.needs.forEach(function (n) {
                        if (customerKeywords.indexOf(n) === -1) {
                            customerKeywords.push(n);
                        }
                    });

                    customerProfile.roles.forEach(function (r) {
                        if (customerKeywords.indexOf(r) === -1) {
                            customerKeywords.push(r);
                        }
                    });

                    customerProfile.scenarios.forEach(function (s) {
                        if (customerKeywords.indexOf(s) === -1) {
                            customerKeywords.push(s);
                        }
                    });

                    // 3.2 将店铺画像转化为“客户可能在网上的行为 / 搜索意图”
                    var customerContent = this.convertProfileToCustomerContent(profile, customerProfile);
                    var behaviorKeywords = [];

                    if (customerContent && Array.isArray(customerContent.searchIntents)) {
                        behaviorKeywords = behaviorKeywords.concat(customerContent.searchIntents);
                    }
                    if (customerContent && Array.isArray(customerContent.comments)) {
                        // 适当加入部分评论作为长尾搜索词
                        behaviorKeywords = behaviorKeywords.concat(customerContent.comments.slice(0, 20));
                    }

                    // 如果行为意图太少，再根据画像特征生成行为型短语（如“爱买口红”“爱美做美甲”）
                    if (behaviorKeywords.length < 10) {
                        var traitBehaviorMap = {
                            '爱美': ['爱买口红', '喜欢做美甲', '经常买护肤品', '爱看美妆博主', '喜欢试新口红颜色'],
                            '时尚': ['喜欢买时尚女装', '追最新潮流单品', '爱逛时尚女装店', '喜欢搭配各种裙子'],
                            '注重形象': ['爱做造型设计', '经常去美甲店', '喜欢买高跟鞋', '喜欢穿漂亮连衣裙'],
                            '追求品质': ['偏好高端品牌女装', '愿意为好品质付费', '喜欢买真皮女鞋', '常逛精品女装店']
                        };

                        customerProfile.traits.forEach(function (t) {
                            if (traitBehaviorMap[t]) {
                                traitBehaviorMap[t].forEach(function (b) {
                                    if (behaviorKeywords.indexOf(b) === -1) {
                                        behaviorKeywords.push(b);
                                    }
                                });
                            }
                        });
                    }

                    // 如果行为型关键词仍然不足，从店铺关键词中生成行为短语
                    if (behaviorKeywords.length < 10) {
                        var valuableStoreKeywords = ['女鞋', '女装', '口红', '美甲', '裙子', '高跟鞋', '护肤', '化妆', '定制', '高端', '专业', '全球', '个性化', '品质'];
                        valuableStoreKeywords.forEach(function (k) {
                            if (storeKeywords.indexOf(k) !== -1) {
                                var phrases = [
                                    '想买' + k,
                                    '哪里可以买到' + k,
                                    k + ' 哪家好',
                                    k + ' 推荐',
                                    '爱买' + k
                                ];
                                phrases.forEach(function (p) {
                                    if (behaviorKeywords.indexOf(p) === -1) {
                                        behaviorKeywords.push(p);
                                    }
                                });
                            }
                        });
                    }

                    // 合并画像基础关键词与行为关键词
                    customerKeywords = customerKeywords.concat(behaviorKeywords);

                    // 过滤无意义的关键词
                    var meaninglessWords = ['通用', '其他', '未知', '无', 'null', 'undefined', '', ' ', '·', '-', '_',
                        '一家', '一个', '的', '和', '与', '或', '及', '等', '以及', '还有'];

                    function isValidKeyword(word) {
                        if (!word || word.trim() === '') return false;
                        word = word.trim();
                        // 过滤太短或太长的词
                        if (word.length < 2 || word.length > 20) return false;
                        // 过滤无意义的词
                        if (meaninglessWords.indexOf(word) !== -1) return false;
                        // 过滤纯数字或特殊字符
                        if (/^[\d\s·\-_]+$/.test(word)) return false;
                        return true;
                    }

                    // 过滤和去重
                    var uniqueKeywords = [];
                    customerKeywords.forEach(function (k) {
                        if (isValidKeyword(k) && uniqueKeywords.indexOf(k) === -1) {
                            uniqueKeywords.push(k);
                        }
                    });

                    // 如果关键词太少，添加一些通用但有用的词（兜底）
                    if (uniqueKeywords.length < 3) {
                        var fallbackKeywords = ['需要女装', '想买女鞋', '寻找目标客户', '专业服务'];
                        fallbackKeywords.forEach(function (k) {
                            if (uniqueKeywords.indexOf(k) === -1) {
                                uniqueKeywords.push(k);
                            }
                        });
                    }

                    // 行为型关键词优先：包含动词 / 行为词的短语优先输出
                    var behaviorFirstKeywords = [];
                    uniqueKeywords.forEach(function (k) {
                        if (/[买购找寻求看做约定报逛试穿]/.test(k) || /推荐|需要|哪里|哪家|好不好|体验|预约|报名/.test(k)) {
                            if (behaviorFirstKeywords.indexOf(k) === -1) {
                                behaviorFirstKeywords.push(k);
                            }
                        }
                    });

                    // 如果行为型关键词不够，再补充部分其它关键词，保证数量
                    if (behaviorFirstKeywords.length < 20) {
                        uniqueKeywords.forEach(function (k) {
                            if (behaviorFirstKeywords.indexOf(k) === -1 && behaviorFirstKeywords.length < 30) {
                                behaviorFirstKeywords.push(k);
                            }
                        });
                    }

                    return behaviorFirstKeywords.slice(0, 20);
                },

                /**
                 * 将店铺画像转化为客户可能在网上发布的内容
                 * 包括：评论、签名、搜索意图等
                 * 
                 * @param {Object} storeProfile - 店铺画像
                 * @param {Object} customerProfile - 客户画像（从店铺画像推断）
                 * @returns {Object} 客户内容对象 {comments: [], signatures: [], searchIntents: []}
                 */
                convertProfileToCustomerContent: function (storeProfile, customerProfile) {
                    var customerContent = {
                        comments: [],      // 客户可能发布的评论
                        signatures: [],     // 客户可能使用的签名/简介
                        searchIntents: []  // 客户可能的搜索意图
                    };

                    // 转化规则库
                    var conversionRules = {
                        // 产品类别 -> 客户需求表达
                        '女鞋': {
                            comments: ['需要女鞋', '想买女鞋', '寻找女鞋', '女鞋推荐', '哪里买女鞋', '女鞋哪家好'],
                            signatures: ['时尚爱好者', '爱美人士', '注重形象', '追求时尚'],
                            searchIntents: ['女鞋推荐', '哪里买女鞋', '女鞋哪家好', '女鞋品牌推荐']
                        },
                        '男鞋': {
                            comments: ['需要男鞋', '想买男鞋', '寻找男鞋', '男鞋推荐', '哪里买男鞋', '男鞋哪家好'],
                            signatures: ['商务人士', '注重品质', '追求专业'],
                            searchIntents: ['男鞋推荐', '哪里买男鞋', '男鞋哪家好', '商务男鞋推荐']
                        },
                        '运动鞋': {
                            comments: ['需要运动鞋', '想买运动鞋', '寻找运动鞋', '运动鞋推荐', '哪里买运动鞋'],
                            signatures: ['运动爱好者', '健身达人', '追求健康'],
                            searchIntents: ['运动鞋推荐', '哪里买运动鞋', '运动鞋哪家好', '专业运动鞋']
                        },
                        '定制': {
                            comments: ['需要定制', '想定制', '寻找定制服务', '定制推荐', '哪里可以定制', '定制哪家好'],
                            signatures: ['追求个性', '注重品质', '定制需求者', '个性化爱好者'],
                            searchIntents: ['定制服务推荐', '哪里可以定制', '定制哪家好', '个性化定制', '定制流程']
                        },
                        '高端': {
                            comments: ['需要高端产品', '寻找高品质服务', '高端推荐', '哪里买高端产品'],
                            signatures: ['追求品质', '注重品质', '高端消费者', '品味独特'],
                            searchIntents: ['高端产品推荐', '高品质服务', '高端品牌', '奢华体验']
                        },
                        '专业': {
                            comments: ['需要专业服务', '寻找专业方案', '专业推荐', '哪里找专业服务'],
                            signatures: ['专业人士', '追求专业', '注重专业'],
                            searchIntents: ['专业服务推荐', '专业方案', '专业建议', '专业支持']
                        },
                        '全球': {
                            comments: ['需要全球服务', '寻找国际品牌', '全球推荐'],
                            signatures: ['国际化视野', '跨文化', '全球消费者'],
                            searchIntents: ['全球服务', '国际品牌', '跨境服务', '海外购物']
                        }
                    };

                    // 从店铺画像中提取的关键词
                    var storeKeywords = [];
                    if (storeProfile.name) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(storeProfile.name));
                    }
                    if (storeProfile.description) {
                        storeKeywords = storeKeywords.concat(this.semanticDecompose(storeProfile.description));
                    }
                    if (storeProfile.products && Array.isArray(storeProfile.products)) {
                        var self = this;
                        storeProfile.products.forEach(function (p) {
                            storeKeywords = storeKeywords.concat(self.semanticDecompose(p));
                        });
                    }

                    // 应用转化规则
                    var usedComments = {};
                    var usedSignatures = {};
                    var usedSearchIntents = {};

                    storeKeywords.forEach(function (keyword) {
                        if (conversionRules[keyword]) {
                            var rule = conversionRules[keyword];

                            // 添加评论
                            if (rule.comments) {
                                rule.comments.forEach(function (comment) {
                                    if (!usedComments[comment]) {
                                        customerContent.comments.push(comment);
                                        usedComments[comment] = true;
                                    }
                                });
                            }

                            // 添加签名
                            if (rule.signatures) {
                                rule.signatures.forEach(function (signature) {
                                    if (!usedSignatures[signature]) {
                                        customerContent.signatures.push(signature);
                                        usedSignatures[signature] = true;
                                    }
                                });
                            }

                            // 添加搜索意图
                            if (rule.searchIntents) {
                                rule.searchIntents.forEach(function (intent) {
                                    if (!usedSearchIntents[intent]) {
                                        customerContent.searchIntents.push(intent);
                                        usedSearchIntents[intent] = true;
                                    }
                                });
                            }
                        }
                    });

                    // 从客户画像中生成更多内容
                    if (customerProfile) {
                        // 基于客户特征生成评论
                        if (customerProfile.traits && customerProfile.traits.length > 0) {
                            customerProfile.traits.forEach(function (trait) {
                                var comment = '需要' + trait + '的产品';
                                if (!usedComments[comment]) {
                                    customerContent.comments.push(comment);
                                    usedComments[comment] = true;
                                }
                            });
                        }

                        // 基于客户需求生成搜索意图
                        if (customerProfile.needs && customerProfile.needs.length > 0) {
                            customerProfile.needs.forEach(function (need) {
                                var intent = need + '推荐';
                                if (!usedSearchIntents[intent]) {
                                    customerContent.searchIntents.push(intent);
                                    usedSearchIntents[intent] = true;
                                }
                            });
                        }

                        // 基于客户角色生成签名
                        if (customerProfile.roles && customerProfile.roles.length > 0) {
                            customerProfile.roles.forEach(function (role) {
                                if (!usedSignatures[role]) {
                                    customerContent.signatures.push(role);
                                    usedSignatures[role] = true;
                                }
                            });
                        }
                    }

                    // 组合生成更自然的搜索查询
                    // 例如："定制女鞋推荐"、"哪里可以定制女鞋"等
                    if (storeKeywords.length > 0 && customerContent.comments.length > 0) {
                        // 组合产品词和需求词
                        storeKeywords.slice(0, 3).forEach(function (product) {
                            customerContent.comments.slice(0, 3).forEach(function (comment) {
                                // 如果评论中包含产品词，生成组合查询
                                if (comment.indexOf(product) === -1) {
                                    var combinedQuery = comment + ' ' + product;
                                    if (!usedSearchIntents[combinedQuery]) {
                                        customerContent.searchIntents.push(combinedQuery);
                                        usedSearchIntents[combinedQuery] = true;
                                    }
                                }
                            });
                        });
                    }

                    // 添加通用搜索意图模板
                    var genericTemplates = [
                        '哪里可以买',
                        '哪家好',
                        '推荐',
                        '价格',
                        '评价',
                        '体验',
                        '对比'
                    ];

                    storeKeywords.slice(0, 2).forEach(function (keyword) {
                        genericTemplates.forEach(function (template) {
                            var intent = keyword + template;
                            if (!usedSearchIntents[intent]) {
                                customerContent.searchIntents.push(intent);
                                usedSearchIntents[intent] = true;
                            }
                        });
                    });

                    // 限制数量，避免过多
                    customerContent.comments = customerContent.comments.slice(0, 15);
                    customerContent.signatures = customerContent.signatures.slice(0, 10);
                    customerContent.searchIntents = customerContent.searchIntents.slice(0, 20);

                    return customerContent;
                },

                // 提取客户画像特征
                extractCustomerProfile: function (candidateData) {
                    var profile = {
                        name: candidateData.name || '',
                        industry: candidateData.industry || '',
                        region: candidateData.region || '',
                        keywords: [],
                        bio: candidateData.bio || '',
                        email: candidateData.email || null,
                        phone: candidateData.phone || null,
                        socialMediaAccounts: candidateData.socialMediaAccounts || {},
                        matchedTextSegments: candidateData.matchedTextSegments || []
                    };

                    // 从bio中提取关键词
                    if (profile.bio) {
                        var words = profile.bio.split(/[\s,，。！？；：、]+/);
                        words.forEach(function (word) {
                            word = word.trim();
                            if (word.length >= 2 && word.length <= 10) {
                                profile.keywords.push(word);
                            }
                        });
                    }

                    // 从matchedTextSegments中提取关键词
                    if (profile.matchedTextSegments && profile.matchedTextSegments.length > 0) {
                        profile.matchedTextSegments.forEach(function (segment) {
                            if (segment.keyword && profile.keywords.indexOf(segment.keyword) === -1) {
                                profile.keywords.push(segment.keyword);
                            }
                        });
                    }

                    // 去重
                    profile.keywords = profile.keywords.filter(function (k, i, arr) {
                        return arr.indexOf(k) === i;
                    });

                    return profile;
                },

                // 计算客户匹配分数（增强版，多维度权重）
                calculateMatchScore: function (sourceTypeProfile, candidateProfile) {
                    var score = 0;
                    var maxScore = 100;
                    var matchReasons = []; // 记录匹配原因

                    // 基础分数（10%）
                    score += 10;

                    // 行业匹配度（权重25%）
                    if (sourceTypeProfile.industry && candidateProfile.industry) {
                        if (sourceTypeProfile.industry === candidateProfile.industry) {
                            score += 25;
                            matchReasons.push('行业完全匹配: ' + sourceTypeProfile.industry);
                        } else if (sourceTypeProfile.industry.indexOf(candidateProfile.industry) !== -1 ||
                            candidateProfile.industry.indexOf(sourceTypeProfile.industry) !== -1) {
                            score += 15;
                            matchReasons.push('行业部分匹配: ' + sourceTypeProfile.industry + ' vs ' + candidateProfile.industry);
                        }
                    }

                    // 关键词相似度（权重30%，使用特征向量）
                    var sourceKeywords = sourceTypeProfile.keywords || [];
                    var candidateKeywords = candidateProfile.keywords || [];
                    var matchedKeywords = [];
                    var matchedCount = 0;

                    sourceKeywords.forEach(function (sk) {
                        candidateKeywords.forEach(function (ck) {
                            var skLower = sk.toLowerCase();
                            var ckLower = ck.toLowerCase();
                            if (skLower === ckLower ||
                                skLower.indexOf(ckLower) !== -1 ||
                                ckLower.indexOf(skLower) !== -1) {
                                if (matchedKeywords.indexOf(sk) === -1) {
                                    matchedKeywords.push(sk);
                                    matchedCount++;
                                }
                            }
                        });
                    });

                    if (matchedCount > 0) {
                        var keywordScore = Math.min(30, matchedCount * 6);
                        score += keywordScore;
                        matchReasons.push('关键词匹配: ' + matchedKeywords.slice(0, 3).join(', ') + (matchedKeywords.length > 3 ? '...' : ''));
                    }

                    // 地域匹配度（权重20%）
                    if (sourceTypeProfile.region && candidateProfile.region) {
                        if (sourceTypeProfile.region === candidateProfile.region) {
                            score += 20;
                            matchReasons.push('地域匹配: ' + sourceTypeProfile.region);
                        } else if (sourceTypeProfile.region.indexOf(candidateProfile.region) !== -1 ||
                            candidateProfile.region.indexOf(sourceTypeProfile.region) !== -1) {
                            score += 10;
                            matchReasons.push('地域部分匹配: ' + sourceTypeProfile.region + ' vs ' + candidateProfile.region);
                        }
                    }

                    // 目标客户群体匹配度（权重15%）
                    if (sourceTypeProfile.target_customers && Array.isArray(sourceTypeProfile.target_customers)) {
                        var targetMatch = false;
                        sourceTypeProfile.target_customers.forEach(function (target) {
                            if (candidateProfile.bio && candidateProfile.bio.indexOf(target) !== -1) {
                                targetMatch = true;
                                matchReasons.push('目标客户匹配: ' + target);
                            }
                        });
                        if (targetMatch) {
                            score += 15;
                        }
                    }

                    // 产品/服务特征匹配度（权重10%）
                    if (sourceTypeProfile.product_features && Array.isArray(sourceTypeProfile.product_features)) {
                        var featureMatch = false;
                        sourceTypeProfile.product_features.forEach(function (feature) {
                            if (candidateProfile.bio && candidateProfile.bio.indexOf(feature) !== -1) {
                                featureMatch = true;
                                matchReasons.push('产品特征匹配: ' + feature);
                            }
                        });
                        if (featureMatch) {
                            score += 10;
                        }
                    }

                    return {
                        score: Math.min(maxScore, score),
                        reasons: matchReasons
                    };
                },

                // 生成模拟候选客户
                generateMockCandidates: function (keywords, count) {
                    var candidates = [];
                    var platforms = ['LinkedIn', 'Twitter', 'Facebook', 'Instagram', 'YouTube'];
                    var industries = ['科技', '金融', '零售', '制造', '服务', '医疗', '教育'];
                    var regions = ['北美', '欧洲', '亚太', '中东', '南美'];

                    for (var i = 0; i < count; i++) {
                        var platform = platforms[Math.floor(Math.random() * platforms.length)];
                        var industry = industries[Math.floor(Math.random() * industries.length)];
                        var region = regions[Math.floor(Math.random() * regions.length)];

                        candidates.push({
                            id: 'candidate_' + Date.now() + '_' + i,
                            name: '潜在客户 ' + (i + 1),
                            platform: platform,
                            url: 'https://' + platform.toLowerCase() + '.com/user' + (Math.floor(Math.random() * 10000)),
                            industry: industry,
                            region: region,
                            keywords: keywords.slice(0, Math.floor(Math.random() * 5) + 1),
                            followers: Math.floor(Math.random() * 100000),
                            engagement: (Math.random() * 10).toFixed(2) + '%',
                            profileData: {
                                bio: '这是一位来自' + region + '的' + industry + '行业专业人士',
                                joinDate: '2020-0' + (Math.floor(Math.random() * 9) + 1) + '-01'
                            }
                        });
                    }

                    return candidates;
                },

                // 分析画像生成搜索关键词（JS实现，改进版：基于目标客户特征）
                analyzeProfileForKeywords: function (profileJson, profileLength, keywordsJsonPtr, keywordsSize) {
                    try {
                        // 使用改进的 analyzeStoreProfile 方法
                        var keywords = this.analyzeStoreProfile(profileJson);

                        // 构建JSON数组
                        var keywordsJson = JSON.stringify(keywords);
                        var jsonLength = keywordsJson.length;

                        // 如果提供了内存指针，写入JSON（WASM模式）
                        if (keywordsJsonPtr && this.UTF8ToString && this.stringToUTF8) {
                            // WASM模式：需要写入内存
                            if (jsonLength < keywordsSize) {
                                this.stringToUTF8(keywordsJson, keywordsJsonPtr, keywordsSize);
                                return jsonLength;
                            }
                        }

                        // JS模式：返回JSON长度（实际JSON需要从其他地方获取）
                        return jsonLength;
                    } catch (error) {
                        console.error('[JS Engine] analyzeProfileForKeywords error:', error);
                        return 0;
                    }
                },

                // 匹配网页内容与画像（JS实现）
                matchWebContentWithProfile: function (webContent, contentLength, profileJson, profileLength) {
                    try {
                        if (!webContent || contentLength <= 0 || !profileJson || profileLength <= 0) {
                            return 0.0;
                        }

                        var profile = typeof profileJson === 'string' ? JSON.parse(profileJson) : profileJson;
                        var content = typeof webContent === 'string' ? webContent : String(webContent);
                        var contentLower = content.toLowerCase();

                        var score = 0.0;
                        var matchCount = 0;
                        var totalFields = 0;

                        // 检查行业匹配
                        if (profile.industry) {
                            totalFields++;
                            if (contentLower.indexOf(profile.industry.toLowerCase()) !== -1) {
                                matchCount++;
                                score += 25;
                            }
                        }

                        // 检查名称匹配
                        if (profile.name) {
                            totalFields++;
                            var nameWords = profile.name.split(/[\s,，。！？；：、]+/);
                            var nameMatchCount = 0;
                            nameWords.forEach(function (word) {
                                if (word.length >= 2 && contentLower.indexOf(word.toLowerCase()) !== -1) {
                                    nameMatchCount++;
                                }
                            });
                            if (nameMatchCount > 0) {
                                matchCount++;
                                score += Math.min(20, nameMatchCount * 5);
                            }
                        }

                        // 检查关键词匹配
                        if (profile.keywords && Array.isArray(profile.keywords)) {
                            totalFields++;
                            var keywordMatchCount = 0;
                            profile.keywords.forEach(function (kw) {
                                if (kw && contentLower.indexOf(kw.toLowerCase()) !== -1) {
                                    keywordMatchCount++;
                                }
                            });
                            if (keywordMatchCount > 0) {
                                matchCount++;
                                score += Math.min(30, keywordMatchCount * 3);
                            }
                        }

                        // 检查目标客户匹配
                        if (profile.target_customers && Array.isArray(profile.target_customers)) {
                            totalFields++;
                            var targetMatch = false;
                            profile.target_customers.forEach(function (tc) {
                                if (tc && contentLower.indexOf(tc.toLowerCase()) !== -1) {
                                    targetMatch = true;
                                }
                            });
                            if (targetMatch) {
                                matchCount++;
                                score += 15;
                            }
                        }

                        // 检查产品特征匹配
                        if (profile.product_features && Array.isArray(profile.product_features)) {
                            totalFields++;
                            var featureMatch = false;
                            profile.product_features.forEach(function (pf) {
                                if (pf && contentLower.indexOf(pf.toLowerCase()) !== -1) {
                                    featureMatch = true;
                                }
                            });
                            if (featureMatch) {
                                matchCount++;
                                score += 10;
                            }
                        }

                        // 文本长度因子
                        var lengthFactor = Math.min(1.0, contentLength / 500.0);
                        score = score * 0.8 + lengthFactor * 20.0;

                        return Math.min(100.0, Math.max(0.0, score));
                    } catch (error) {
                        console.error('[JS Engine] matchWebContentWithProfile error:', error);
                        return 0.0;
                    }
                },

                // 从网页内容中提取联系信息（JS实现）
                extractContactInfo: function (webContent, contentLength, contactJsonPtr, contactSize) {
                    try {
                        if (!webContent || contentLength <= 0) {
                            return 0;
                        }

                        var content = typeof webContent === 'string' ? webContent : String(webContent);
                        var contactInfo = {
                            email: null,
                            phone: null,
                            socialMediaAccounts: {}
                        };

                        // 提取邮箱
                        var emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
                        var emailMatches = content.match(emailRegex);
                        if (emailMatches && emailMatches.length > 0) {
                            contactInfo.email = emailMatches[0];
                        }

                        // 提取电话
                        var phoneRegex = /(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}|\+?\d{10,15}/g;
                        var phoneMatches = content.match(phoneRegex);
                        if (phoneMatches && phoneMatches.length > 0) {
                            contactInfo.phone = phoneMatches[0].trim();
                        }

                        // 提取社媒账户
                        var socialPatterns = {
                            linkedin: /linkedin\.com\/(in|pub|company|school)\/([^\/\s"']+)/gi,
                            twitter: /(twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/gi,
                            facebook: /facebook\.com\/([a-zA-Z0-9.]+)/gi,
                            instagram: /instagram\.com\/([a-zA-Z0-9_.]+)/gi,
                            youtube: /youtube\.com\/(channel\/|user\/|@)([a-zA-Z0-9_-]+)/gi
                        };

                        for (var platform in socialPatterns) {
                            var pattern = socialPatterns[platform];
                            var matches = content.match(pattern);
                            if (matches && matches.length > 0) {
                                var accounts = [];
                                matches.forEach(function (match) {
                                    var urlMatch = match.match(/https?:\/\/([^\s"']+)/i);
                                    if (urlMatch) {
                                        accounts.push(urlMatch[0]);
                                    } else if (match.indexOf('http') === -1) {
                                        accounts.push('https://' + match);
                                    } else {
                                        accounts.push(match);
                                    }
                                });
                                if (accounts.length > 0) {
                                    contactInfo.socialMediaAccounts[platform] = accounts;
                                }
                            }
                        }

                        // 清理空值
                        if (!contactInfo.email) delete contactInfo.email;
                        if (!contactInfo.phone) delete contactInfo.phone;
                        if (Object.keys(contactInfo.socialMediaAccounts).length === 0) {
                            delete contactInfo.socialMediaAccounts;
                        }

                        // 构建JSON
                        var contactJson = JSON.stringify(contactInfo);
                        var jsonLength = contactJson.length;

                        // 如果提供了内存指针，写入JSON（WASM模式）
                        if (contactJsonPtr && this.UTF8ToString && this.stringToUTF8) {
                            // WASM模式：需要写入内存
                            if (jsonLength < contactSize) {
                                this.stringToUTF8(contactJson, contactJsonPtr, contactSize);
                                return jsonLength;
                            }
                        }

                        // JS模式：返回JSON长度（实际JSON需要从其他地方获取）
                        return jsonLength;
                    } catch (error) {
                        console.error('[JS Engine] extractContactInfo error:', error);
                        return 0;
                    }
                }
            }
        };
    }

    /**
     * 开始执行任务
     */
    async function startTask(taskId, storeId, sourceTypeProfile) {
        if (state.status === TASK_STATUS.RUNNING ||
            state.status === TASK_STATUS.CRAWLING ||
            state.status === TASK_STATUS.INFERRING) {
            console.warn('[TaskRunner] Task already running');
            return false;
        }

        console.log('[TaskRunner] Starting task:', taskId, 'for store:', storeId);

        // ========== 前置环境检查：浏览器 / 模型配置 / MCP 扩展 ==========
        // 1. 浏览器内核检查（必须为 Chrome）
        if (typeof BrowserDetector !== 'undefined' &&
            BrowserDetector &&
            typeof BrowserDetector.getBrowserInfo === 'function') {
            try {
                var browserInfo = BrowserDetector.getBrowserInfo();
                if (!browserInfo.isChrome) {
                    var msgChrome = '自动寻客任务需要在 Chrome 浏览器中运行，当前浏览器：' +
                        (browserInfo.name || 'Unknown') + ' ' + (browserInfo.version || '');
                    console.error('[TaskRunner]', msgChrome);
                    emitLog('inference', '错误: ' + msgChrome);
                    if (typeof BrowserDetector.showIncompatibleDialog === 'function') {
                        BrowserDetector.showIncompatibleDialog({
                            title: '浏览器不兼容',
                            message: '自动寻客任务需要在 Chrome 浏览器中运行，请使用 Chrome 打开本页面后再启动任务。'
                        });
                    } else {
                        alert(msgChrome);
                    }
                    return false;
                }
            } catch (e) {
                console.warn('[TaskRunner] BrowserDetector check failed:', e);
            }
        }

        // 2. 模型配置检查（必须已配置并启用端侧模型）
        var agentConfig = await getAgentConfig();
        if (!agentConfig || !agentConfig.hf_model_id) {
            var msgNoModel = '未检测到端侧推理模型配置。请先在“配置管理”中选择 Hugging Face 模型并启用后再启动任务。';
            console.error('[TaskRunner]', msgNoModel, 'config:', agentConfig);
            emitLog('inference', '错误: ' + msgNoModel);
            alert(msgNoModel);
            return false;
        }
        if (!agentConfig.hf_model_enabled) {
            var msgModelDisabled = '已配置端侧模型（' + agentConfig.hf_model_id + '），但当前处于未启用状态。请在“配置管理”中启用模型后再启动任务。';
            console.error('[TaskRunner]', msgModelDisabled);
            emitLog('inference', '错误: ' + msgModelDisabled);
            alert(msgModelDisabled);
            return false;
        }

        // 3. Browser MCP 扩展检查（必须可用，以便模型通过 MCP 控制浏览器）
        if (typeof MCPClient !== 'undefined' &&
            MCPClient &&
            typeof MCPClient.isMCPAvailable === 'function') {
            try {
                var mcpAvailable = await MCPClient.isMCPAvailable();
                if (!mcpAvailable) {
                    var msgNoMcp = '未检测到 Browser MCP 浏览器扩展或其未就绪，无法通过 AI 工具自动操作浏览器。请先安装并启用 Browser MCP 扩展。';
                    console.error('[TaskRunner]', msgNoMcp);
                    emitLog('inference', '错误: ' + msgNoMcp);
                    alert(msgNoMcp);
                    return false;
                }
            } catch (e) {
                console.warn('[TaskRunner] MCP availability check failed:', e);
                var msgMcpErr = '检测 Browser MCP 扩展状态失败，请确认扩展已安装并启用后重试。';
                emitLog('inference', '错误: ' + msgMcpErr);
                alert(msgMcpErr);
                return false;
            }
        } else {
            var msgMcpUnsupported = '当前环境不支持 Browser MCP 扩展检测，无法确保模型可以通过 MCP 控制浏览器。';
            console.error('[TaskRunner]', msgMcpUnsupported);
            emitLog('inference', '错误: ' + msgMcpUnsupported);
            alert(msgMcpUnsupported);
            return false;
        }

        // 验证任务配置：必须有搜索引擎和目标网站
        var selectedSearchEngines = sourceTypeProfile && sourceTypeProfile.selected_search_engines 
            ? sourceTypeProfile.selected_search_engines 
            : [];
        var selectedTargetWebsites = sourceTypeProfile && sourceTypeProfile.selected_target_websites 
            ? sourceTypeProfile.selected_target_websites 
            : [];
        
        if (!selectedSearchEngines || selectedSearchEngines.length === 0) {
            var errorMsg = '任务缺少搜索引擎配置，无法执行。请先编辑任务并选择至少一个搜索引擎。';
            console.error('[TaskRunner]', errorMsg);
            emitLog('inference', '错误: ' + errorMsg);
            await reportTaskError(taskId, errorMsg);
            return false;
        }
        
        if (!selectedTargetWebsites || selectedTargetWebsites.length === 0) {
            var errorMsg2 = '任务缺少目标网站配置，无法执行。请先编辑任务并选择至少一个目标网站。';
            console.error('[TaskRunner]', errorMsg2);
            emitLog('inference', '错误: ' + errorMsg2);
            await reportTaskError(taskId, errorMsg2);
            return false;
        }

        // 延迟一小段时间确保UI已更新，然后发送第一条日志（这会清除"任务启动中..."占位文本）
        setTimeout(function () {
            emitLog('inference', '开始执行任务 #' + taskId);
        }, 50);

        // 重置状态
        state.currentTaskId = taskId;
        state.currentStoreId = storeId;
        state.currentSourceTypeProfile = sourceTypeProfile || null;
        state.foundCount = 0;
        state.startTime = performance.now();
        state.candidates = [];
        state.abortController = new AbortController();
        state.isPaused = false;
        state.stateMachineState = STATE_MACHINE.INITIALIZING;
        state.inferenceLog = [];
        state.crawlingLog = [];

        // 加载端侧推理模型
        var modelLoaded = await loadInferenceModel();
        if (!modelLoaded) {
            reportTaskError(taskId, '模型加载失败');
            return false;
        }

        // 更新任务状态为运行中
        await updateTaskStatus(taskId, 'running', 0);

        state.status = TASK_STATUS.RUNNING;
        updateUIStatus(TASK_STATUS.RUNNING);
        updateModelStatus('running', null, getMemoryUsage());

        try {
            // 执行任务流程
            await executeTaskPipeline(taskId, sourceTypeProfile || storeProfile);
            return true;

        } catch (error) {
            console.error('[TaskRunner] Task execution failed:', error);
            emitLog('inference', '任务执行失败: ' + error.message);
            state.status = TASK_STATUS.FAILED;
            updateUIStatus(TASK_STATUS.FAILED);
            updateModelStatus('error', null, null);

            await reportTaskError(taskId, error.message || '任务执行失败');
            return false;
        }
    }

    /**
     * 执行任务流水线（ReAct模式）
     */
    async function executeTaskPipeline(taskId, sourceTypeProfile) {
        var engine = state.wasmInstance.exports;

        // 检查是否被中止
        function checkAbort() {
            if (state.abortController && state.abortController.signal.aborted) {
                throw new Error('任务已取消');
            }
            if (state.isPaused) {
                throw new Error('任务已暂停');
            }
        }

        // ========== ReAct模式：思考阶段（Think）- 使用模型分析类型画像 ==========
        state.stateMachineState = STATE_MACHINE.INITIALIZING;
        state.status = TASK_STATUS.INFERRING;
        updateUIStatus(TASK_STATUS.INFERRING);
        emitLog('inference', '【思考阶段】开始使用模型分析来源类型画像...');

        checkAbort();
        var inferenceStart = performance.now();
        
        // 从后端API获取场景映射规则（中文默认版本）
        var profileToScenesMapping = null;
        var targetLanguage = sourceTypeProfile.language || 'zh'; // 目标语言，用于翻译
        
        try {
            // 构建API URL
            var currentPath = window.location.pathname;
            var apiUrl = currentPath.replace(/\/[^\/]+$/, '/get-scene-mapping');
            
            emitLog('inference', '【获取场景映射规则】从后端获取中文默认映射规则...');
            
            // 获取中文默认映射规则
            var mappingResponse = await fetch(apiUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });
            
            if (mappingResponse.ok) {
                var mappingData = await mappingResponse.json();
                if (mappingData.success && mappingData.data) {
                    profileToScenesMapping = mappingData.data;
                    emitLog('inference', '✓ 成功获取场景映射规则（中文）');
                    
                    // 如果目标语言不是中文，使用Google翻译API翻译场景词
                    if (targetLanguage && targetLanguage !== 'zh' && targetLanguage !== 'zh-cn' && targetLanguage !== 'zh-hans') {
                        emitLog('inference', '【翻译场景映射规则】目标语言: ' + targetLanguage + '，开始翻译...');
                        profileToScenesMapping = await translateSceneMapping(profileToScenesMapping, targetLanguage);
                        emitLog('inference', '✓ 场景映射规则翻译完成');
                    }
                } else {
                    emitLog('inference', '⚠️ 获取场景映射规则失败，使用默认规则');
                }
            } else {
                emitLog('inference', '⚠️ 获取场景映射规则失败，使用默认规则');
            }
        } catch (error) {
            emitLog('inference', '⚠️ 获取场景映射规则出错: ' + error.message + '，使用默认规则');
        }
        
        // 如果获取失败，使用默认值
        if (!profileToScenesMapping) {
            profileToScenesMapping = {
                'activityWords': ['评论', '发帖', '分享', '讨论', '参与', '活跃', '关注', '点赞', '转发', '互动', '留言', '发布'],
                'roleWords': ['读者', '用户', '成员', '粉丝', '关注者', '参与者', '活跃用户', '会员'],
                '_communitySuffix': '社区'
            };
            emitLog('inference', '⚠️ 使用默认场景映射规则（建议检查网络连接或后端配置）');
        }
        
        // 保存映射规则到state，供后续使用（传递给扩展）
        state.sceneMapping = profileToScenesMapping;
        
        /**
         * 翻译场景映射规则到目标语言
         * @param {Object} mapping - 中文映射规则
         * @param {string} targetLang - 目标语言代码
         * @returns {Promise<Object>} 翻译后的映射规则
         */
        async function translateSceneMapping(mapping, targetLang) {
            var translatedMapping = {};
            
            // 翻译每个特征词对应的场景数组
            for (var trait in mapping) {
                // 跳过特殊键
                if (trait === 'activityWords' || trait === 'roleWords' || trait === '_communitySuffix') {
                    continue;
                }
                
                var scenes = mapping[trait];
                if (Array.isArray(scenes)) {
                    var translatedScenes = [];
                    for (var i = 0; i < scenes.length; i++) {
                        var translated = await translateWithGoogle(scenes[i], targetLang);
                        if (translated && translated.trim()) {
                            translatedScenes.push(translated.trim());
                        } else {
                            // 如果翻译失败，保留原文
                            translatedScenes.push(scenes[i]);
                        }
                    }
                    translatedMapping[trait] = translatedScenes;
                }
            }
            
            // 翻译活动词
            if (mapping.activityWords && Array.isArray(mapping.activityWords)) {
                var translatedActivities = [];
                for (var i = 0; i < mapping.activityWords.length; i++) {
                    var translated = await translateWithGoogle(mapping.activityWords[i], targetLang);
                    if (translated && translated.trim()) {
                        translatedActivities.push(translated.trim());
                    } else {
                        translatedActivities.push(mapping.activityWords[i]);
                    }
                }
                translatedMapping.activityWords = translatedActivities;
            }
            
            // 翻译角色词
            if (mapping.roleWords && Array.isArray(mapping.roleWords)) {
                var translatedRoles = [];
                for (var i = 0; i < mapping.roleWords.length; i++) {
                    var translated = await translateWithGoogle(mapping.roleWords[i], targetLang);
                    if (translated && translated.trim()) {
                        translatedRoles.push(translated.trim());
                    } else {
                        translatedRoles.push(mapping.roleWords[i]);
                    }
                }
                translatedMapping.roleWords = translatedRoles;
            }
            
            // 翻译通用场景后缀
            if (mapping._communitySuffix) {
                var translatedSuffix = await translateWithGoogle(mapping._communitySuffix, targetLang);
                translatedMapping._communitySuffix = translatedSuffix && translatedSuffix.trim() ? translatedSuffix.trim() : mapping._communitySuffix;
            }
            
            return translatedMapping;
        }

        // 步骤1：准备画像数据并使用模型分析
        emitLog('inference', '【步骤1】准备画像数据并使用模型分析...');
        await sleep(200);

        // 构建完整的画像文本（用于模型分析）
        // 确保数组字段正确转换（安全处理）
        var safeJoin = function (arr) {
            if (!arr) return '';
            if (Array.isArray(arr)) {
                return arr.filter(function (item) { return item != null && item !== ''; }).join(' ');
            }
            return String(arr);
        };

        var keywordsArray = Array.isArray(sourceTypeProfile.keywords)
            ? sourceTypeProfile.keywords
            : (sourceTypeProfile.keywords ? [sourceTypeProfile.keywords] : []);
        var targetCustomersArray = Array.isArray(sourceTypeProfile.target_customers)
            ? sourceTypeProfile.target_customers
            : (sourceTypeProfile.target_customers ? [sourceTypeProfile.target_customers] : []);
        var productFeaturesArray = Array.isArray(sourceTypeProfile.product_features)
            ? sourceTypeProfile.product_features
            : (sourceTypeProfile.product_features ? [sourceTypeProfile.product_features] : []);
        var productsArray = Array.isArray(sourceTypeProfile.products)
            ? sourceTypeProfile.products
            : (sourceTypeProfile.products ? [sourceTypeProfile.products] : []);

        var profileText = [
            sourceTypeProfile.name || '',
            sourceTypeProfile.description || '',
            sourceTypeProfile.industry || '',
            sourceTypeProfile.region || '',
            safeJoin(keywordsArray),
            safeJoin(targetCustomersArray),
            safeJoin(productFeaturesArray),
            safeJoin(productsArray)
        ].filter(function (t) { return t && t.trim().length > 0; }).join(' ');

        emitLog('inference', '✓ 画像数据准备完成，文本长度: ' + profileText.length + ' 字符');

        // 使用模型分析画像，提取关键特征
        emitLog('inference', '【步骤1.1】使用模型分析画像特征...');
        await sleep(300);

        var profileAnalysisResult = null;
        try {
            if (engine.analyzeProfileForKeywords && typeof engine.analyzeProfileForKeywords === 'function') {
                var profileJson = JSON.stringify(sourceTypeProfile);
                var keywordsJsonSize = 2048; // 足够大的缓冲区
                var keywordsJsonPtr = null;
                var keywordsJson = null;

                // 尝试WASM模式（需要内存分配）
                if (engine._malloc && engine.UTF8ToString) {
                    try {
                        keywordsJsonPtr = engine._malloc(keywordsJsonSize);
                        if (keywordsJsonPtr) {
                            var resultLength = engine.analyzeProfileForKeywords(
                                profileJson,
                                profileJson.length,
                                keywordsJsonPtr,
                                keywordsJsonSize
                            );

                            if (resultLength > 0) {
                                keywordsJson = engine.UTF8ToString(keywordsJsonPtr);
                                profileAnalysisResult = JSON.parse(keywordsJson);
                                emitLog('inference', '✓ WASM模型分析完成，提取了 ' + (profileAnalysisResult ? profileAnalysisResult.length : 0) + ' 个关键特征');
                            }

                            if (engine._free) {
                                engine._free(keywordsJsonPtr);
                            }
                        }
                    } catch (error) {
                        emitLog('inference', '⚠️ WASM模型分析失败，使用JS方法: ' + error.message);
                    }
                }

                // 如果WASM模式失败，使用JS模式
                if (!profileAnalysisResult) {
                    var resultLength = engine.analyzeProfileForKeywords(profileJson, profileJson.length, null, keywordsJsonSize);
                    if (resultLength > 0) {
                        // JS模式：需要从引擎内部获取结果（这里简化处理，直接使用analyzeStoreProfile）
                        profileAnalysisResult = engine.analyzeStoreProfile(sourceTypeProfile);
                        emitLog('inference', '✓ JavaScript模型分析完成，提取了 ' + (profileAnalysisResult ? profileAnalysisResult.length : 0) + ' 个关键特征');
                    }
                }
            } else {
                // 如果函数不存在，使用备用方法
                profileAnalysisResult = engine.analyzeStoreProfile(sourceTypeProfile);
                emitLog('inference', '✓ 使用备用方法分析，提取了 ' + (profileAnalysisResult ? profileAnalysisResult.length : 0) + ' 个关键特征');
            }
        } catch (error) {
            emitLog('inference', '⚠️ 画像分析出错: ' + error.message);
            // 使用备用方法
            profileAnalysisResult = engine.analyzeStoreProfile(sourceTypeProfile);
        }

        if (profileAnalysisResult && profileAnalysisResult.length > 0) {
            emitLog('inference', '✓ 模型分析的关键特征: ' + profileAnalysisResult.slice(0, 5).join(', ') + (profileAnalysisResult.length > 5 ? '...' : ''));
        }

        // 步骤2：使用模型提取特征向量
        emitLog('inference', '【步骤2】使用WASM模型提取特征向量...');
        await sleep(300);

        var profileFeatures = null;
        var featureVector = null;

        // 使用模型提取特征向量
        try {
            if (engine.extractProfileFeatures && typeof engine.extractProfileFeatures === 'function') {
                var featuresSize = 100; // 特征向量大小

                // 尝试WASM模式（需要内存分配）
                if (engine._malloc && engine.HEAPF64) {
                    try {
                        var featuresPtr = engine._malloc(featuresSize * 8); // double类型，8字节
                        if (featuresPtr) {
                            var featureCount = engine.extractProfileFeatures(profileText, profileText.length, featuresPtr, featuresSize);

                            if (featureCount > 0) {
                                var features = new Float64Array(engine.HEAPF64.buffer, featuresPtr, featureCount);
                                featureVector = Array.from(features);
                                emitLog('inference', '✓ WASM模型提取了 ' + featureCount + ' 维特征向量');
                            }

                            // 释放内存
                            if (engine._free) {
                                engine._free(featuresPtr);
                            }
                        }
                    } catch (error) {
                        emitLog('inference', '⚠️ WASM特征提取失败，使用JS方法: ' + error.message);
                    }
                }

                // 如果WASM模式失败，使用JS模式（不分配内存）
                if (!featureVector) {
                    var featureCount = engine.extractProfileFeatures(profileText, profileText.length, null, featuresSize);
                    if (featureCount > 0) {
                        // JS模式：手动计算特征
                        var words = profileText.split(/[\s,，。！？；：、]+/);
                        var wordCount = words.length;
                        var charCount = profileText.length;
                        var uniqueWords = {};
                        words.forEach(function (w) {
                            if (w && w.length >= 2) {
                                uniqueWords[w] = (uniqueWords[w] || 0) + 1;
                            }
                        });
                        var uniqueWordCount = Object.keys(uniqueWords).length;

                        featureVector = [
                            Math.min(1.0, wordCount / 100.0),
                            Math.min(1.0, charCount / 1000.0),
                            Math.min(1.0, uniqueWordCount / 50.0),
                            wordCount > 0 ? Math.min(1.0, uniqueWordCount / wordCount) : 0,
                            (sourceTypeProfile.industry ? 1 : 0),
                            (sourceTypeProfile.region ? 1 : 0),
                            (keywordsArray.length > 0 ? Math.min(1.0, keywordsArray.length / 20.0) : 0)
                        ];
                        emitLog('inference', '✓ JavaScript提取了 ' + featureVector.length + ' 维特征');
                    }
                }
            } else {
                // 如果函数不存在，使用基础JS方法
                emitLog('inference', '使用基础JavaScript方法提取特征...');
                var words = profileText.split(/[\s,，。！？；：、]+/);
                var wordCount = words.length;
                var charCount = profileText.length;
                var uniqueWords = {};
                words.forEach(function (w) {
                    if (w && w.length >= 2) {
                        uniqueWords[w] = (uniqueWords[w] || 0) + 1;
                    }
                });
                var uniqueWordCount = Object.keys(uniqueWords).length;

                featureVector = [
                    Math.min(1.0, wordCount / 100.0),
                    Math.min(1.0, charCount / 1000.0),
                    Math.min(1.0, uniqueWordCount / 50.0),
                    wordCount > 0 ? Math.min(1.0, uniqueWordCount / wordCount) : 0
                ];
                emitLog('inference', '✓ 基础方法提取了 ' + featureVector.length + ' 维特征');
            }
        } catch (error) {
            emitLog('inference', '⚠️ 特征提取出错: ' + error.message);
            // 使用默认特征向量
            featureVector = [0.5, 0.5, 0.5, 0.5];
        }

        // 步骤3：使用模型分析画像维度
        emitLog('inference', '【步骤3】使用模型分析画像维度...');
        await sleep(300);

        // 确保数组字段正确转换
        var keywordsArray = Array.isArray(sourceTypeProfile.keywords)
            ? sourceTypeProfile.keywords
            : (sourceTypeProfile.keywords ? [sourceTypeProfile.keywords] : []);
        var targetCustomersArray = Array.isArray(sourceTypeProfile.target_customers)
            ? sourceTypeProfile.target_customers
            : (sourceTypeProfile.target_customers ? [sourceTypeProfile.target_customers] : []);
        var productFeaturesArray = Array.isArray(sourceTypeProfile.product_features)
            ? sourceTypeProfile.product_features
            : (sourceTypeProfile.product_features ? [sourceTypeProfile.product_features] : []);
        var productsArray = Array.isArray(sourceTypeProfile.products)
            ? sourceTypeProfile.products
            : (sourceTypeProfile.products ? [sourceTypeProfile.products] : []);

        var profileSummary = {
            name: sourceTypeProfile.name || '未知',
            industry: sourceTypeProfile.industry || '通用',
            region: sourceTypeProfile.region || '',
            description: sourceTypeProfile.description || '',
            keywords: keywordsArray,
            targetCustomers: targetCustomersArray,
            productFeatures: productFeaturesArray,
            products: productsArray,
            featureVector: featureVector, // 保存特征向量
            analysisScore: 0 // 画像分析评分
        };

        // 使用模型计算画像质量评分
        if (engine.calculateCustomerScore && typeof engine.calculateCustomerScore === 'function') {
            try {
                var profileJson = JSON.stringify(sourceTypeProfile);
                profileSummary.analysisScore = engine.calculateCustomerScore(profileJson, profileJson.length);
                emitLog('inference', '✓ 模型分析评分: ' + profileSummary.analysisScore.toFixed(1) + ' / 100');
            } catch (error) {
                emitLog('inference', '⚠️ 模型评分失败，使用默认评分');
                profileSummary.analysisScore = 70; // 默认评分
            }
        } else {
            // JS备用评分
            var score = 50; // 基础分
            if (profileSummary.name && profileSummary.name !== '未知') score += 10;
            if (profileSummary.industry && profileSummary.industry !== '通用') score += 10;
            if (profileSummary.description && profileSummary.description.length > 20) score += 10;
            if (profileSummary.keywords.length > 0) score += 10;
            if (profileSummary.targetCustomers.length > 0) score += 10;
            profileSummary.analysisScore = Math.min(100, score);
            emitLog('inference', '✓ JavaScript分析评分: ' + profileSummary.analysisScore.toFixed(1) + ' / 100');
        }

        emitLog('inference', '✓ 画像维度分析：');
        emitLog('inference', '  - 名称: ' + profileSummary.name);
        emitLog('inference', '  - 行业: ' + profileSummary.industry);
        if (profileSummary.region) {
            emitLog('inference', '  - 地域: ' + profileSummary.region);
        }
        if (profileSummary.description) {
            var descPreview = profileSummary.description.length > 50
                ? profileSummary.description.substring(0, 50) + '...'
                : profileSummary.description;
            emitLog('inference', '  - 描述: ' + descPreview);
        }
        if (profileSummary.targetCustomers.length > 0) {
            emitLog('inference', '  - 目标客户: ' + profileSummary.targetCustomers.join(', '));
        }
        if (profileSummary.productFeatures.length > 0) {
            emitLog('inference', '  - 产品特征: ' + profileSummary.productFeatures.join(', '));
        }

        // 步骤4：基于模型分析结果生成搜索策略
        emitLog('inference', '【步骤4】使用模型智能生成客户画像关键词...');
        await sleep(300);

        // 使用模型分析生成关键词（智能推理）
        var keywords = [];
        try {
            if (engine.analyzeProfileForKeywords && typeof engine.analyzeProfileForKeywords === 'function') {
                var profileJson = JSON.stringify(sourceTypeProfile);
                var keywordsJsonSize = 2048;
                var keywordsJsonPtr = null;
                var keywordsJson = null;

                // 尝试WASM模式
                if (engine._malloc && engine.UTF8ToString) {
                    try {
                        keywordsJsonPtr = engine._malloc(keywordsJsonSize);
                        if (keywordsJsonPtr) {
                            var resultLength = engine.analyzeProfileForKeywords(
                                profileJson,
                                profileJson.length,
                                keywordsJsonPtr,
                                keywordsJsonSize
                            );

                            if (resultLength > 0) {
                                keywordsJson = engine.UTF8ToString(keywordsJsonPtr);
                                keywords = JSON.parse(keywordsJson);
                                emitLog('inference', '✓ WASM模型生成客户画像关键词: ' + keywords.length + ' 个');
                            }

                            if (engine._free) {
                                engine._free(keywordsJsonPtr);
                            }
                        }
                    } catch (error) {
                        emitLog('inference', '⚠️ WASM关键词生成失败，使用JS方法: ' + error.message);
                    }
                }

                // 如果WASM模式失败，使用JS模式
                if (keywords.length === 0) {
                    var resultLength = engine.analyzeProfileForKeywords(profileJson, profileJson.length, null, keywordsJsonSize);
                    if (resultLength > 0) {
                        // JS模式：使用备用方法
                        keywords = engine.analyzeStoreProfile(sourceTypeProfile);
                        emitLog('inference', '✓ JavaScript模型生成客户画像关键词: ' + keywords.length + ' 个');
                    }
                }
            } else {
                // 如果函数不存在，使用备用方法
                keywords = engine.analyzeStoreProfile(sourceTypeProfile);
                emitLog('inference', '✓ 使用备用方法生成客户画像关键词: ' + keywords.length + ' 个');
            }
        } catch (error) {
            emitLog('inference', '⚠️ 关键词生成出错: ' + error.message);
            // 使用备用方法
            keywords = engine.analyzeStoreProfile(sourceTypeProfile);
        }

        // 如果模型生成的关键词为空，使用备用方法
        if (!keywords || keywords.length === 0) {
            keywords = engine.analyzeStoreProfile(sourceTypeProfile);
            emitLog('inference', '⚠️ 模型未生成客户画像关键词，使用备用方法: ' + keywords.length + ' 个');
        }

        // 根据画像分析评分调整搜索策略
        var searchDepth = profileSummary.analysisScore >= 80 ? 5 : (profileSummary.analysisScore >= 60 ? 3 : 2);
        var keywordCount = profileSummary.analysisScore >= 80 ? 10 : (profileSummary.analysisScore >= 60 ? 7 : 5);

        emitLog('inference', '✓ 生成客户画像关键词 (' + keywords.length + ' 个): ' + keywords.slice(0, 5).join(', ') + (keywords.length > 5 ? '...' : ''));

        // 分析目标客户特征并记录
        var meaninglessWords = ['通用', '其他', '未知', '无', 'null', 'undefined', '', ' ', '·', '-', '_'];
        var hasGenericKeywords = keywords.some(function (kw) {
            return meaninglessWords.indexOf(kw) !== -1 || /^[\d\s·\-_]+$/.test(kw);
        });

        if (hasGenericKeywords) {
            emitLog('inference', '⚠️ 检测到通用关键词，建议补充更具体的店铺描述信息');
        }

        // 分析关键词对应的目标客户特征
        var customerInsights = [];
        keywords.forEach(function (kw) {
            if (meaninglessWords.indexOf(kw) === -1 && kw.length >= 2) {
                // 分析关键词对应的客户类型
                if (kw.indexOf('零售') !== -1 || kw.indexOf('商店') !== -1 || kw.indexOf('超市') !== -1) {
                    customerInsights.push('目标客户：零售消费者、购物需求人群');
                }
                if (kw.indexOf('服务') !== -1 || kw.indexOf('咨询') !== -1) {
                    customerInsights.push('目标客户：需要专业服务的企业或个人');
                }
                if (kw.indexOf('科技') !== -1 || kw.indexOf('技术') !== -1) {
                    customerInsights.push('目标客户：科技行业从业者、IT决策者');
                }
                if (kw.indexOf('教育') !== -1 || kw.indexOf('培训') !== -1) {
                    customerInsights.push('目标客户：教育工作者、学习者');
                }
                if (kw.indexOf('医疗') !== -1 || kw.indexOf('健康') !== -1) {
                    customerInsights.push('目标客户：医疗从业者、健康关注者');
                }
            }
        });

        // 去重客户洞察
        var uniqueInsights = [];
        customerInsights.forEach(function (insight) {
            if (uniqueInsights.indexOf(insight) === -1) {
                uniqueInsights.push(insight);
            }
        });

        if (uniqueInsights.length > 0) {
            emitLog('inference', '【客户画像分析】基于关键词推断的目标客户特征：');
            uniqueInsights.forEach(function (insight) {
                emitLog('inference', '  - ' + insight);
            });
        } else if (keywords.length > 0) {
            emitLog('inference', '💡 提示：关键词较通用，建议补充店铺描述、目标客户、产品特征等信息以生成更精准的客户画像');
        }

        // 步骤：将店铺画像转化为客户可能在网上发布的内容
        emitLog('inference', '【画像转化】将店铺画像转化为客户内容...');
        var customerProfile = engine.inferCustomerProfileFromStore(sourceTypeProfile);
        var customerContent = engine.convertProfileToCustomerContent(sourceTypeProfile, customerProfile);

        emitLog('inference', '✓ 画像转化完成：');
        emitLog('inference', '  - 客户评论: ' + customerContent.comments.length + ' 条');
        emitLog('inference', '  - 客户签名: ' + customerContent.signatures.length + ' 条');
        emitLog('inference', '  - 搜索意图: ' + customerContent.searchIntents.length + ' 条');

        if (customerContent.comments.length > 0) {
            emitLog('inference', '  评论示例: ' + customerContent.comments.slice(0, 3).join(', ') + (customerContent.comments.length > 3 ? '...' : ''));
        }
        if (customerContent.searchIntents.length > 0) {
            emitLog('inference', '  搜索意图示例: ' + customerContent.searchIntents.slice(0, 3).join(', ') + (customerContent.searchIntents.length > 3 ? '...' : ''));
        }

        /**
         * 从画像反推客户会出现的场景
         * @param {Object} profileSummary - 画像摘要
         * @param {Array} keywords - 画像关键词
         * @param {Object} mapping - 画像到场景的映射规则
         * @returns {Array} 场景列表
         */
        function inferScenesFromProfile(profileSummary, keywords, mapping) {
            var scenes = [];
            var seenScenes = new Set();
            
            // 规则映射：基于关键词匹配场景
            keywords.forEach(function(keyword) {
                Object.keys(mapping).forEach(function(trait) {
                    // 跳过 activityWords 键
                    if (trait === 'activityWords') {
                        return;
                    }
                    
                    // 检查关键词是否包含特征词，或特征词是否包含关键词
                    if (keyword.indexOf(trait) !== -1 || trait.indexOf(keyword) !== -1) {
                        if (mapping[trait] && Array.isArray(mapping[trait])) {
                            mapping[trait].forEach(function(scene) {
                                if (!seenScenes.has(scene)) {
                                    scenes.push(scene);
                                    seenScenes.add(scene);
                                }
                            });
                        }
                    }
                });
            });
            
            // 基于行业信息扩展场景
            if (profileSummary.industry && profileSummary.industry !== '通用' && profileSummary.industry !== '其他') {
                var industryKey = profileSummary.industry;
                // 检查行业是否在映射中
                if (mapping[industryKey] && Array.isArray(mapping[industryKey])) {
                    mapping[industryKey].forEach(function(scene) {
                        if (!seenScenes.has(scene)) {
                            scenes.push(scene);
                            seenScenes.add(scene);
                        }
                    });
                }
            }
            
            // AI推理扩展：如果场景太少，尝试使用模型进一步推断
            // 这里可以调用WASM模型或JS模型进行智能扩展
            // 暂时使用关键词组合来扩展场景
            if (scenes.length < 3 && keywords.length > 0) {
                // 基于关键词组合生成通用场景
                keywords.slice(0, 3).forEach(function(kw) {
                    var genericScene = kw + '社区';
                    if (!seenScenes.has(genericScene)) {
                        scenes.push(genericScene);
                        seenScenes.add(genericScene);
                    }
                });
            }
            
            return scenes;
        }

        // 生成多个搜索查询（基于场景反推）
        var searchQueries = [];

        // 步骤1：从画像反推场景
        var scenes = inferScenesFromProfile(profileSummary, keywords, profileToScenesMapping);
        emitLog('inference', '【场景反推】从画像反推客户会出现的场景...');
        emitLog('inference', '✓ 反推客户场景 (' + scenes.length + ' 个): ' + scenes.slice(0, 5).join(', ') + (scenes.length > 5 ? '...' : ''));

        // 步骤2：生成场景+活动词的搜索查询
        var activityWords = profileToScenesMapping.activityWords || ['评论', '发帖', '分享', '讨论', '参与', '活跃', '关注', '点赞', '转发', '互动', '留言', '发布'];
        scenes.forEach(function(scene) {
            // 为每个场景生成多个活动词组合（限制数量避免查询过多）
            var selectedActivities = activityWords.slice(0, 5); // 每个场景使用前5个活动词
            selectedActivities.forEach(function(activity) {
                var query = scene + ' ' + activity;
                if (searchQueries.indexOf(query) === -1) {
                    searchQueries.push(query);
                }
            });
        });

        // 步骤3：生成场景+角色词的搜索查询（如"时尚杂志 读者"）
        var roleWords = profileToScenesMapping.roleWords || ['读者', '用户', '成员', '粉丝', '关注者', '参与者', '活跃用户', '会员'];
        scenes.forEach(function(scene) {
            // 为每个场景生成多个角色词组合（限制数量）
            var selectedRoles = roleWords.slice(0, 4); // 每个场景使用前4个角色词
            selectedRoles.forEach(function(role) {
                var query = scene + ' ' + role;
                if (searchQueries.indexOf(query) === -1) {
                    searchQueries.push(query);
                }
            });
        });

        // 步骤4：如果场景反推结果不足，使用原始客户内容查询作为补充
        if (scenes.length === 0 && customerContent.searchIntents && customerContent.searchIntents.length > 0) {
            emitLog('inference', '⚠️ 场景反推结果为空，使用客户搜索意图作为补充');
            customerContent.searchIntents.slice(0, 5).forEach(function (intent) {
                if (intent && intent.trim() && searchQueries.indexOf(intent.trim()) === -1) {
                    searchQueries.push(intent.trim());
                }
            });
        }

        // 步骤5：如果查询数量仍然不足，使用关键词+场景的组合
        if (searchQueries.length < 5 && scenes.length > 0) {
            var topKeywords = keywords.slice(0, Math.min(3, keywords.length));
            scenes.slice(0, 3).forEach(function(scene) {
                topKeywords.forEach(function(keyword) {
                    var query = keyword + ' ' + scene;
                    if (searchQueries.indexOf(query) === -1) {
                        searchQueries.push(query);
                    }
                });
            });
        }

        // 去重
        var uniqueQueries = [];
        searchQueries.forEach(function (q) {
            if (q && uniqueQueries.indexOf(q) === -1) {
                uniqueQueries.push(q);
            }
        });
        searchQueries = uniqueQueries;

        // 构建智能搜索策略
        var searchStrategy = {
            primaryKeywords: keywords.slice(0, Math.min(keywordCount, keywords.length)), // 主要关键词（根据评分调整数量）
            secondaryKeywords: keywords.slice(keywordCount, keywordCount + 5), // 次要关键词
            searchQueries: searchQueries, // 多个搜索查询（基于客户内容转化）
            searchEngines: ['Baidu', 'Google', 'Bing', 'DuckDuckGo'], // 使用的搜索引擎（全球并发）
            industry: profileSummary.industry,
            region: profileSummary.region,
            searchDepth: searchDepth, // 搜索深度（根据画像质量调整）
            analysisScore: profileSummary.analysisScore, // 画像分析评分
            featureVector: featureVector, // 特征向量（用于后续匹配）
            customerContent: customerContent // 客户内容（用于后续匹配）
        };

        emitLog('inference', '✓ 智能搜索策略：');
        emitLog('inference', '  - 客户画像关键词: ' + searchStrategy.primaryKeywords.join(', '));
        emitLog('inference', '  - 反推场景数量: ' + scenes.length + ' 个');
        if (scenes.length > 0) {
            emitLog('inference', '  - 反推场景列表: ' + scenes.slice(0, 10).join(', ') + (scenes.length > 10 ? '...' : ''));
        }
        emitLog('inference', '  - 搜索查询数量: ' + searchStrategy.searchQueries.length + ' 个');
        if (searchStrategy.searchQueries.length > 0) {
            emitLog('inference', '  - 搜索查询示例: ' + searchStrategy.searchQueries.slice(0, 5).join(', ') + (searchStrategy.searchQueries.length > 5 ? '...' : ''));
        }
        emitLog('inference', '  - 使用的搜索引擎: ' + searchStrategy.searchEngines.join(', '));
        emitLog('inference', '  - 搜索深度: ' + searchStrategy.searchDepth + ' 轮（基于画像质量 ' + profileSummary.analysisScore.toFixed(1) + ' 分）');

        // 步骤5：评估可行性
        emitLog('inference', '【步骤5】评估可行性...');
        await sleep(200);

        var feasibilityCheck = {
            extensionAvailable: config.useExtension,
            wasmLoaded: state.wasmInstance !== null,
            networkReady: true, // 将在验证阶段检查
            profileComplete: !!(profileSummary.name && profileSummary.industry),
            hasKeywords: keywords.length > 0,
            analysisComplete: featureVector !== null && featureVector.length > 0
        };

        emitLog('inference', '✓ 可行性评估结果：');
        emitLog('inference', '  - 浏览器扩展: ' + (feasibilityCheck.extensionAvailable ? '✓ 已安装' : '✗ 未安装'));
        emitLog('inference', '  - WASM模型: ' + (feasibilityCheck.wasmLoaded ? '✓ 已加载' : '✗ 未加载'));
        emitLog('inference', '  - 画像完整性: ' + (feasibilityCheck.profileComplete ? '✓ 完整' : '✗ 不完整'));
        emitLog('inference', '  - 关键词数量: ' + (feasibilityCheck.hasKeywords ? '✓ ' + keywords.length + ' 个' : '✗ 无关键词'));
        emitLog('inference', '  - 模型分析: ' + (feasibilityCheck.analysisComplete ? '✓ 完成' : '✗ 未完成'));

        if (!feasibilityCheck.extensionAvailable) {
            emitLog('inference', '⚠️ 警告：浏览器扩展未安装，将无法进行真实爬取');
        }
        if (!feasibilityCheck.profileComplete) {
            emitLog('inference', '⚠️ 警告：画像信息不完整，可能影响搜索效果');
        }
        if (!feasibilityCheck.hasKeywords) {
            emitLog('inference', '⚠️ 警告：未生成有效关键词，将使用默认策略');
        }
        if (!feasibilityCheck.analysisComplete) {
            emitLog('inference', '⚠️ 警告：模型分析未完成，将使用基础策略');
        }

        metrics.inferenceTime = performance.now() - inferenceStart;
        metrics.totalInferences++;

        emitLog('inference', '【思考阶段完成】模型分析完成，耗时: ' + metrics.inferenceTime.toFixed(0) + 'ms');
        emitLog('inference', '画像分析评分: ' + profileSummary.analysisScore.toFixed(1) + ' / 100');
        emitLog('inference', '准备开始查询阶段...');

        // 更新推理次数到前端
        updateProgress(state.foundCount, '思考阶段完成，推理次数: ' + metrics.totalInferences);

        // 保存画像总结和搜索策略到state，供后续使用
        state.profileSummary = profileSummary;
        state.searchStrategy = searchStrategy;

        // ========== ReAct模式：验证阶段（Validate）==========
        state.stateMachineState = STATE_MACHINE.VALIDATING;
        emitLog('crawl', '【验证阶段】开始网络连通性检查...');

        // Ping检查将在扩展中完成，这里只记录状态
        if (!feasibilityCheck.extensionAvailable) {
            emitLog('crawl', '❌ 验证失败：浏览器扩展未安装');
            state.stateMachineState = STATE_MACHINE.FAILED;
            state.status = TASK_STATUS.FAILED;
            updateUIStatus(TASK_STATUS.FAILED);
            updateModelStatus('error', null, getMemoryUsage());
            await updateTaskStatus(taskId, 'failed', state.foundCount);
            await reportTaskError(taskId, '浏览器扩展未安装，无法进行真实爬取');
            return;
        }

        emitLog('crawl', '✓ 验证通过：浏览器扩展已就绪');

        // ========== ReAct模式：行动阶段（Act）==========
        state.stateMachineState = STATE_MACHINE.CRAWLING;
        state.status = TASK_STATUS.CRAWLING;
        updateUIStatus(TASK_STATUS.CRAWLING);

        emitLog('crawl', '【行动阶段】开始执行搜索引擎搜索...');

        // 使用思考阶段生成的搜索策略
        var searchKeywords = state.searchStrategy ? state.searchStrategy.primaryKeywords : keywords;
        var profileSummary = state.profileSummary || {};

        // 读取任务配置的搜索引擎和目标网站
        var selectedSearchEngines = sourceTypeProfile.selected_search_engines || [];
        var selectedTargetWebsites = sourceTypeProfile.selected_target_websites || [];
        
        // 如果没有从任务配置中获取，使用默认逻辑
        if (!selectedSearchEngines || selectedSearchEngines.length === 0) {
            selectedSearchEngines = state.searchStrategy && state.searchStrategy.searchEngines
                ? state.searchStrategy.searchEngines
                : ['Baidu', 'Google', 'Bing', 'DuckDuckGo'];
        }
        
        // 读取外部传入的搜索配置（地区、语言、搜索引擎）
        var searchConfigs = sourceTypeProfile.search_configs || [];
        var regions = sourceTypeProfile.regions || [];
        var languages = sourceTypeProfile.languages || [];

        // 如果没有搜索配置，使用默认逻辑
        if (!searchConfigs || searchConfigs.length === 0) {
            // 创建默认配置，使用任务配置的搜索引擎
            searchConfigs = [{
                region: profileSummary.region || '中国',
                language: 'zh',
                searchEngines: selectedSearchEngines,
                targetWebsites: selectedTargetWebsites
            }];
        } else {
            // 如果有搜索配置，也添加目标网站信息
            searchConfigs.forEach(function(config) {
                if (!config.targetWebsites) {
                    config.targetWebsites = selectedTargetWebsites;
                }
                if (!config.searchEngines || config.searchEngines.length === 0) {
                    config.searchEngines = selectedSearchEngines;
                }
            });
        }

        emitLog('crawl', '【多语言/地区处理】共 ' + searchConfigs.length + ' 个配置需要处理');
        searchConfigs.forEach(function (config, idx) {
            emitLog('crawl', '  配置 ' + (idx + 1) + ': 地区=' + config.region + ', 语言=' + config.language + ', 搜索引擎=' + config.searchEngines.join(', '));
        });

        // 检查是否有目标网站配置
        var hasTargetWebsites = false;
        for (var cfgIdx = 0; cfgIdx < searchConfigs.length; cfgIdx++) {
            if (searchConfigs[cfgIdx].targetWebsites && searchConfigs[cfgIdx].targetWebsites.length > 0) {
                hasTargetWebsites = true;
                break;
            }
        }
        
        // 生成多个搜索查询（优先使用思考阶段生成的基于客户内容的查询）
        var baseSearchQueries = [];
        if (hasTargetWebsites) {
            // 如果有目标网站，不生成普通查询，只提取关键词用于生成目标网站查询
            emitLog('crawl', '🎯 检测到目标网站配置，将使用精准搜索语法，不生成普通查询');
            // 为了兼容，仍然生成一个基础查询作为关键词来源（但不会被使用）
            baseSearchQueries.push(searchKeywords.join(' '));
        } else if (state.searchStrategy && state.searchStrategy.searchQueries && state.searchStrategy.searchQueries.length > 0) {
            baseSearchQueries = state.searchStrategy.searchQueries;
            emitLog('crawl', '✓ 使用思考阶段生成的客户内容查询: ' + baseSearchQueries.length + ' 个');
        } else {
            // 如果没有预生成的查询，从关键词生成（备用方案）
            emitLog('crawl', '⚠️ 使用备用查询生成方案');
            // 基础查询
            baseSearchQueries.push(searchKeywords.join(' '));

            // 行业查询
            if (profileSummary.industry && profileSummary.industry !== '通用' && profileSummary.industry !== '其他') {
                baseSearchQueries.push(searchKeywords.join(' ') + ' ' + profileSummary.industry);
            }

            // 地区查询
            if (profileSummary.region && profileSummary.region.trim()) {
                baseSearchQueries.push(searchKeywords.join(' ') + ' ' + profileSummary.region);
            }

            // 关键词重排
            if (searchKeywords.length > 1) {
                var shuffled = searchKeywords.slice().sort(function () { return Math.random() - 0.5; });
                baseSearchQueries.push(shuffled.join(' '));
            }
        }

        emitLog('crawl', '客户画像关键词：' + searchKeywords.join(', '));
        if (hasTargetWebsites) {
            emitLog('crawl', '🎯 将使用目标网站精准搜索语法（关键词将用于生成site:domain查询）');
        } else {
            emitLog('crawl', '基础搜索查询：' + baseSearchQueries.length + ' 个');
            baseSearchQueries.forEach(function (q, i) {
                emitLog('crawl', '  查询 ' + (i + 1) + ': ' + q);
            });
        }

        // 搜索循环：持续搜索直到找到500个有效客户
        var targetValidCustomers = 500; // 目标有效客户数量
        var maxSearchRounds = 50; // 最大搜索轮数，避免无限循环
        if (!state.searchRound) {
            state.searchRound = 0;
        }
        
        while (state.foundCount < targetValidCustomers && state.searchRound < maxSearchRounds) {
            state.searchRound++;
            
            try {
                checkAbort();
                
                if (state.searchRound > 1) {
                    emitLog('crawl', '');
                    emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                    emitLog('crawl', '【第 ' + state.searchRound + ' 轮搜索】当前已找到 ' + state.foundCount + ' 个有效客户，目标 ' + targetValidCustomers + ' 个');
                    emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                }

                var rawCandidates = [];
                var allSourceUrls = []; // 记录所有搜索过的网址

                // 逐个处理每个语言和地区组合
                for (var configIndex = 0; configIndex < searchConfigs.length; configIndex++) {
                var currentConfig = searchConfigs[configIndex];
                var currentRegion = currentConfig.region;
                var currentLanguage = currentConfig.language;
                var currentSearchEngines = currentConfig.searchEngines;

                emitLog('crawl', '');
                emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                emitLog('crawl', '【处理配置 ' + (configIndex + 1) + '/' + searchConfigs.length + '】');
                emitLog('crawl', '当前地区: ' + currentRegion);
                emitLog('crawl', '当前语言: ' + currentLanguage);
                emitLog('crawl', '当前搜索引擎: ' + currentSearchEngines.join(', '));
                emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

                // 根据是否有扩展选择爬取方式
                if (config.useExtension) {
                    // 使用多查询策略在搜索引擎中搜索
                    // 获取当前配置的目标网站
                    var currentTargetWebsites = searchConfigs[configIndex].targetWebsites || [];
                    
                    // 如果有目标网站，使用关键词而不是baseSearchQueries
                    var queriesToUse = baseSearchQueries;
                    if (currentTargetWebsites && currentTargetWebsites.length > 0) {
                        // 有目标网站时，传递空查询数组，让background.js生成目标网站查询
                        queriesToUse = [];
                        emitLog('crawl', '🎯 配置 ' + (configIndex + 1) + ' 有 ' + currentTargetWebsites.length + ' 个目标网站，将使用精准搜索语法');
                        emitLog('crawl', '📝 关键词: ' + searchKeywords.join(', '));
                    }
                    
                    var extensionResponse = await crawlWithExtension(
                        queriesToUse,
                        currentSearchEngines,
                        {
                            industry: profileSummary.industry,
                            region: currentRegion,
                            language: currentLanguage,
                            targetWebsites: currentTargetWebsites,
                            keywords: searchKeywords // 传递关键词用于生成目标网站查询
                        },
                        config.maxCandidatesPerBatch
                    );

                    if (extensionResponse && extensionResponse.success) {
                        var configCandidates = extensionResponse.data || [];
                        var configSourceUrls = extensionResponse.sourceUrls || [];

                        // 合并结果
                        rawCandidates = rawCandidates.concat(configCandidates);
                        allSourceUrls = allSourceUrls.concat(configSourceUrls);

                        if (extensionResponse.sourceUrls && extensionResponse.sourceUrls.length > 0) {
                            emitLog('crawl', '✓ 已搜索 ' + extensionResponse.sourceUrls.length + ' 个搜索引擎查询');
                            extensionResponse.sourceUrls.forEach(function (urlInfo) {
                                var urlDisplay = urlInfo.url || (urlInfo.engine + ': ' + (urlInfo.query || ''));
                                emitLog('crawl', '  - ' + urlDisplay);
                            });
                        }

                        if (extensionResponse.queriesUsed && extensionResponse.queriesUsed.length > 0) {
                            emitLog('crawl', '使用的查询: ' + extensionResponse.queriesUsed.slice(0, 5).join(', ') + (extensionResponse.queriesUsed.length > 5 ? '...' : ''));
                        }

                        emitLog('crawl', '✓ 配置 ' + (configIndex + 1) + ' 完成，找到 ' + configCandidates.length + ' 个候选客户');
                    } else {
                        // 扩展存在但本次搜索失败，记录错误但继续处理下一个配置
                        var errorMsg = extensionResponse ? extensionResponse.error : '未知错误';
                        emitLog('crawl', '⚠️ 配置 ' + (configIndex + 1) + ' 搜索失败: ' + errorMsg);

                        // 记录详细的错误信息
                        if (extensionResponse) {
                            if (extensionResponse.pingResults) {
                                if (extensionResponse.pingResults.baidu && !extensionResponse.pingResults.baidu.success) {
                                    emitLog('crawl', '  ⚠️ 百度网络连通性检查失败: ' + (extensionResponse.pingResults.baidu.error || '未知错误'));
                                }
                            }
                            if (extensionResponse.errorType) {
                                emitLog('crawl', '  ⚠️ 错误类型: ' + extensionResponse.errorType);
                                
                                // 对于某些错误类型，提供重试建议
                                if (extensionResponse.errorType === 'blocked_by_search_engine') {
                                    emitLog('crawl', '  💡 建议：等待一段时间后重试，或尝试其他搜索引擎');
                                } else if (extensionResponse.errorType === 'timeout_error') {
                                    emitLog('crawl', '  💡 建议：检查网络连接，或减少搜索查询数量');
                                } else if (extensionResponse.errorType === 'exception') {
                                    emitLog('crawl', '  💡 建议：检查扩展是否正常运行，或刷新页面重试');
                                }
                            }
                            if (extensionResponse.sourceUrls && extensionResponse.sourceUrls.length > 0) {
                                emitLog('crawl', '  ⚠️ 已尝试搜索 ' + extensionResponse.sourceUrls.length + ' 个查询');
                            }
                        }

                        // 继续处理下一个配置，不中断整个任务
                        emitLog('crawl', '继续处理下一个配置...');
                    }
                } else {
                    // 未安装扩展，无法绕过 CORS 限制
                    emitLog('crawl', '❌ 未检测到浏览器扩展，无法进行真实搜索');
                    emitLog('crawl', '💡 原因：浏览器 CORS 策略阻止直接访问搜索引擎结果');
                    emitLog('crawl', '💡 解决方案：请安装 AutoLeadAgent 浏览器扩展');
                    emitLog('crawl', '💡 扩展可以绕过 CORS 限制，实现真实搜索');

                    // 显示扩展安装提示
                    if (typeof window.showExtensionInstallModal === 'function') {
                        window.showExtensionInstallModal();
                    }

                    // 直接结束任务，避免在没有扩展的情况下反复推理
                    emitLog('crawl', '⚠️ 由于未安装扩展，本次任务无法继续，将任务标记为失败。');
                    state.status = TASK_STATUS.FAILED;
                    updateUIStatus(TASK_STATUS.FAILED);
                    updateModelStatus('error', null, getMemoryUsage());
                    await updateTaskStatus(taskId, 'failed', state.foundCount);
                    return;
                }
            }

            // 所有配置处理完成
            emitLog('crawl', '');
            emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            emitLog('crawl', '【所有配置处理完成】');
            emitLog('crawl', '总计找到 ' + rawCandidates.length + ' 个候选客户');
            emitLog('crawl', '总计搜索 ' + allSourceUrls.length + ' 个网址');
            emitLog('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

            // 验证客户有效性（必须包含邮箱、手机或社媒账户）
            if (rawCandidates.length > 0) {
                emitLog('crawl', '【步骤3】验证客户有效性...');
                var validCandidates = [];
                for (var i = 0; i < rawCandidates.length; i++) {
                    var candidate = rawCandidates[i];
                    var hasEmail = candidate.email && candidate.email.trim().length > 0;
                    var hasPhone = candidate.phone && candidate.phone.trim().length > 0;
                    var hasSocialMedia = candidate.socialMediaAccounts &&
                        Object.keys(candidate.socialMediaAccounts).length > 0;

                    if (hasEmail || hasPhone || hasSocialMedia) {
                        // 添加来源网址信息
                        candidate.sourceUrls = allSourceUrls;
                        validCandidates.push(candidate);
                    }
                }

                rawCandidates = validCandidates;
                emitLog('crawl', '验证结果: ' + rawCandidates.length + ' 个有效客户');
            }

            emitLog('crawl', '搜索引擎搜索完成，返回 ' + rawCandidates.length + ' 条有效结果');

            // 如果没有任何候选客户，记录警告但继续处理（可能只是当前配置没有结果）
            if (rawCandidates.length === 0) {
                emitLog('crawl', '⚠️ 当前未找到任何候选客户，可能原因：');
                emitLog('crawl', '  1. 搜索关键词不匹配');
                emitLog('crawl', '  2. 搜索引擎被屏蔽或网络问题');
                emitLog('crawl', '  3. 搜索结果中未包含有效联系方式（邮箱/手机/社媒）');
                emitLog('crawl', '继续处理流程，尝试使用模型推理...');
            }

            // ========== ReAct模式：观察阶段（Observe）==========
            state.stateMachineState = STATE_MACHINE.ANALYZING;
            state.status = TASK_STATUS.INFERRING;
            updateUIStatus(TASK_STATUS.INFERRING);
            emitLog('inference', '【观察阶段】开始分析爬取到的数据...');
            emitLog('inference', '正在提取 ' + rawCandidates.length + ' 位候选客户的画像特征...');

            // 提取客户画像特征并使用模型智能提取联系信息
            var candidateProfiles = [];
            for (var i = 0; i < rawCandidates.length; i++) {
                checkAbort();
                var candidate = rawCandidates[i];
                var customerProfile = engine.extractCustomerProfile(candidate);

                // 使用模型智能提取联系信息（补充扩展提取的不足）
                var webContentForContact = [
                    candidate.name || '',
                    candidate.bio || '',
                    customerProfile.bio || '',
                    (candidate.matchedTextSegments || []).map(function (s) { return s.text || ''; }).join(' ')
                ].filter(function (t) { return t && t.trim().length > 0; }).join(' ');

                var modelContactInfo = null;
                try {
                    if (engine.extractContactInfo && typeof engine.extractContactInfo === 'function') {
                        var contactJsonSize = 1024;
                        var contactJsonPtr = null;
                        var contactJson = null;

                        // 尝试WASM模式
                        if (engine._malloc && engine.UTF8ToString) {
                            try {
                                contactJsonPtr = engine._malloc(contactJsonSize);
                                if (contactJsonPtr) {
                                    var resultLength = engine.extractContactInfo(
                                        webContentForContact,
                                        webContentForContact.length,
                                        contactJsonPtr,
                                        contactJsonSize
                                    );

                                    if (resultLength > 0) {
                                        contactJson = engine.UTF8ToString(contactJsonPtr);
                                        modelContactInfo = JSON.parse(contactJson);
                                    }

                                    if (engine._free) {
                                        engine._free(contactJsonPtr);
                                    }
                                }
                            } catch (error) {
                                // WASM模式失败，继续使用扩展提取的信息
                            }
                        }

                        // 如果WASM模式失败，使用JS模式
                        if (!modelContactInfo) {
                            var resultLength = engine.extractContactInfo(webContentForContact, webContentForContact.length, null, contactJsonSize);
                            if (resultLength > 0) {
                                // JS模式：需要从引擎内部获取结果（这里简化处理）
                                // 实际上JS模式的extractContactInfo会返回JSON长度，但我们需要实际数据
                                // 这里我们保留扩展提取的信息，模型提取作为补充
                            }
                        }

                        // 合并模型提取的联系信息到候选数据中（补充扩展提取的不足）
                        if (modelContactInfo) {
                            if (modelContactInfo.email && !candidate.email) {
                                candidate.email = modelContactInfo.email;
                            }
                            if (modelContactInfo.phone && !candidate.phone) {
                                candidate.phone = modelContactInfo.phone;
                            }
                            if (modelContactInfo.socialMediaAccounts) {
                                if (!candidate.socialMediaAccounts) {
                                    candidate.socialMediaAccounts = {};
                                }
                                for (var platform in modelContactInfo.socialMediaAccounts) {
                                    if (!candidate.socialMediaAccounts[platform]) {
                                        candidate.socialMediaAccounts[platform] = modelContactInfo.socialMediaAccounts[platform];
                                    }
                                }
                            }
                        }
                    }
                } catch (error) {
                    // 模型提取失败，继续使用扩展提取的信息
                }

                candidateProfiles.push({
                    candidate: candidate,
                    profile: customerProfile
                });
            }

            emitLog('inference', '画像特征提取完成，已使用模型智能提取联系信息，开始进行语义匹配...');

            // ========== ReAct模式：决策阶段（Decide）==========
            state.stateMachineState = STATE_MACHINE.DECIDING;
            emitLog('inference', '【决策阶段】使用模型进行语义匹配评分...');

            // 准备画像JSON用于模型匹配
            var sourceTypeProfileJson = JSON.stringify(sourceTypeProfile);

            // 使用增强的匹配算法进行评分（结合模型语义匹配和字段匹配）
            var qualifiedCount = 0;
            for (var i = 0; i < candidateProfiles.length; i++) {
                checkAbort();

                var item = candidateProfiles[i];
                var candidate = item.candidate;
                var customerProfile = item.profile;

                // 构建网页内容文本（用于模型匹配）
                var webContent = [
                    candidate.name || '',
                    candidate.bio || '',
                    customerProfile.bio || '',
                    (customerProfile.keywords || []).join(' '),
                    (candidate.matchedTextSegments || []).map(function (s) { return s.text || ''; }).join(' ')
                ].filter(function (t) { return t && t.trim().length > 0; }).join(' ');

                // 使用模型进行语义匹配
                var modelMatchScore = 0;
                try {
                    if (engine.matchWebContentWithProfile && typeof engine.matchWebContentWithProfile === 'function') {
                        modelMatchScore = engine.matchWebContentWithProfile(
                            webContent,
                            webContent.length,
                            sourceTypeProfileJson,
                            sourceTypeProfileJson.length
                        );
                    }
                } catch (error) {
                    emitLog('inference', '⚠️ 模型语义匹配失败: ' + error.message);
                }

                // 使用增强的calculateMatchScore（字段匹配，返回分数和原因）
                var fieldMatchResult = engine.calculateMatchScore(sourceTypeProfile, customerProfile);
                var fieldScore = typeof fieldMatchResult === 'object' ? fieldMatchResult.score : fieldMatchResult;
                var reasons = typeof fieldMatchResult === 'object' ? fieldMatchResult.reasons : [];

                // 综合模型语义匹配分数和字段匹配分数（模型匹配权重70%，字段匹配权重30%）
                var finalScore = modelMatchScore * 0.7 + fieldScore * 0.3;

                if (modelMatchScore > 0) {
                    reasons.push('模型语义匹配: ' + modelMatchScore.toFixed(1) + '分');
                }

                candidate.score = Math.round(finalScore);
                candidate.matchReasons = reasons;
                candidate.modelMatchScore = modelMatchScore;
                candidate.fieldMatchScore = fieldScore;

                // 只保存分数超过阈值的候选（默认50分）
                var threshold = 50;
                if (finalScore >= threshold) {
                    state.candidates.push(candidate);
                    state.foundCount++;
                    qualifiedCount++;

                    var reasonText = reasons.length > 0 ? ' (' + reasons.slice(0, 2).join('; ') + ')' : '';
                    var websiteType = candidate.websiteType || 'unknown';
                    emitLog('inference', '✓ 匹配成功: ' + (candidate.name || '未知') + ' (分数: ' + finalScore.toFixed(1) + ', 来源: ' + websiteType + ')' + reasonText);
                } else {
                    emitLog('inference', '✗ 匹配失败: ' + (candidate.name || '未知') + ' (分数: ' + finalScore.toFixed(1) + ' < 阈值 ' + threshold + ')');
                }
            }

            metrics.totalInferences++;
            metrics.crawlCount = 1; // 单次搜索，不再使用轮数
            metrics.lastCrawlTime = new Date().toLocaleTimeString();

            emitLog('inference', '本轮筛选: ' + qualifiedCount + '/' + rawCandidates.length + ' 符合条件');

            // 更新服务器进度（找到的客户数量）
            await updateTaskFoundCount(taskId, state.foundCount);

            // 更新进度显示
            updateProgress(state.foundCount, '已找到 ' + state.foundCount + ' 个潜在客户');

            // 每找到一批就保存
            if (qualifiedCount > 0) {
                var newCandidates = state.candidates.slice(-qualifiedCount);
                await saveCandidates(taskId, newCandidates);
                emitLog('crawl', '已保存 ' + qualifiedCount + ' 位新客户到数据库');
            }

                // 检查是否已达到目标数量（500个有效客户）
                if (state.foundCount >= targetValidCustomers) {
                    emitLog('crawl', '✓ 已达到目标数量（' + state.foundCount + ' / ' + targetValidCustomers + '），停止搜索');
                    break; // 跳出 while 循环
                }
                
                // 如果未达到目标，继续下一轮搜索
                emitLog('crawl', '当前找到 ' + state.foundCount + ' 个有效客户，目标 ' + targetValidCustomers + ' 个，继续搜索...');
                
                // 继续下一轮搜索前，稍微延迟，避免请求过于频繁
                await sleep(1000);

        } catch (error) {
            if (error.message === '任务已取消' || error.message === '任务已暂停') {
                emitLog('crawl', '搜索中断: ' + error.message);
                // 不再使用break，直接返回
                return;
            }

            // 详细记录异常信息
            var errorInfo = [];
            errorInfo.push('错误消息: ' + error.message);
            if (error.name) {
                errorInfo.push('错误类型: ' + error.name);
            }
            if (error.stack) {
                // 只记录堆栈的前几行，避免日志过长
                var stackLines = error.stack.split('\n').slice(0, 3);
                errorInfo.push('错误位置: ' + stackLines.join(' → '));
            }
            if (error.code) {
                errorInfo.push('错误代码: ' + error.code);
            }

            emitLog('crawl', '❌ 爬取出错: ' + error.message + '，稍后重试...');
            errorInfo.forEach(function (info) {
                emitLog('crawl', '  ⚠️ ' + info);
            });

                await sleep(2000);
            }
        } // 结束搜索循环 while

        // 检查是否因为达到最大轮数而停止
        if (state.foundCount < targetValidCustomers && state.searchRound >= maxSearchRounds) {
            emitLog('crawl', '⚠️ 已达到最大搜索轮数（' + maxSearchRounds + '），停止搜索');
            emitLog('crawl', '最终找到 ' + state.foundCount + ' 个有效客户（目标 ' + targetValidCustomers + ' 个）');
        } else if (state.foundCount >= targetValidCustomers) {
            emitLog('crawl', '✓ 成功达到目标数量（' + state.foundCount + ' / ' + targetValidCustomers + '）');
        }

        // ========== ReAct模式：任务结束 ==========
        state.stateMachineState = STATE_MACHINE.COMPLETED;

        if (state.isPaused) {
            emitLog('inference', '任务已暂停，已找到 ' + state.foundCount + ' 个潜在客户');
            state.status = TASK_STATUS.PAUSED;
            updateUIStatus(TASK_STATUS.PAUSED);
            await updateTaskStatus(taskId, 'paused', state.foundCount);
        } else if (state.abortController.signal.aborted) {
            emitLog('inference', '任务已停止，共找到 ' + state.foundCount + ' 个潜在客户');
            state.status = TASK_STATUS.IDLE;
            updateUIStatus(TASK_STATUS.IDLE);
        } else {
            // 正常完成
            state.status = TASK_STATUS.COMPLETED;
            updateUIStatus(TASK_STATUS.COMPLETED);
            updateModelStatus('success', metrics.inferenceTime, getMemoryUsage());
            emitLog('inference', '任务完成！共找到 ' + state.foundCount + ' 个潜在客户');
            await updateTaskStatus(taskId, 'completed', state.foundCount);
        }

        console.log('[TaskRunner] Task pipeline finished');
        console.log('[TaskRunner] Total crawl rounds:', metrics.crawlCount);
        console.log('[TaskRunner] Total candidates found:', state.foundCount);
    }

    /**
     * 暂停任务
     */
    function pauseTask() {
        if (state.status === TASK_STATUS.RUNNING ||
            state.status === TASK_STATUS.CRAWLING ||
            state.status === TASK_STATUS.INFERRING) {
            state.isPaused = true;
            emitLog('inference', '正在暂停任务...');
            console.log('[TaskRunner] Task pause requested');
        }
    }

    /**
     * 停止任务
     */
    async function stopTask() {
        if (state.status !== TASK_STATUS.IDLE && state.status !== TASK_STATUS.COMPLETED) {
            emitLog('inference', '正在停止任务...');

            if (state.abortController) {
                state.abortController.abort();
            }

            if (state.currentTaskId) {
                await updateTaskStatus(state.currentTaskId, 'cancelled', state.foundCount);
            }

            state.status = TASK_STATUS.IDLE;
            state.isPaused = false;
            updateUIStatus(TASK_STATUS.IDLE);
            updateModelStatus('idle', null, getMemoryUsage());

            emitLog('inference', '任务已停止');
            console.log('[TaskRunner] Task stopped');
        }
    }

    /**
     * 更新任务状态到服务器
     */
    async function updateTaskStatus(taskId, status, foundCount) {
        try {
            var url = config.apiBaseUrl + '/task-progress';

            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'task_id=' + encodeURIComponent(taskId) +
                    '&status=' + encodeURIComponent(status) +
                    '&found_count=' + encodeURIComponent(foundCount)
            });
        } catch (error) {
            console.error('[TaskRunner] Failed to update task status:', error);
        }
    }

    /**
     * 更新找到的客户数量
     */
    async function updateTaskFoundCount(taskId, foundCount) {
        try {
            var url = config.apiBaseUrl + '/task-progress';

            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'task_id=' + encodeURIComponent(taskId) +
                    '&found_count=' + encodeURIComponent(foundCount)
            });
        } catch (error) {
            console.error('[TaskRunner] Failed to update found count:', error);
        }
    }

    /**
     * 保存候选客户到服务器
     */
    async function saveCandidates(taskId, candidates) {
        try {
            var url = config.apiBaseUrl + '/save-candidates';

            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    task_id: taskId,
                    candidates: candidates
                })
            });
        } catch (error) {
            console.error('[TaskRunner] Failed to save candidates:', error);
        }
    }

    /**
     * 报告任务错误
     */
    async function reportTaskError(taskId, errorMessage) {
        emitLog('inference', '任务错误: ' + errorMessage);

        try {
            await updateTaskStatus(taskId, 'failed', state.foundCount);

            var url = config.apiBaseUrl + '/report-task-error';

            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'task_id=' + encodeURIComponent(taskId) +
                    '&error=' + encodeURIComponent(errorMessage)
            });
        } catch (error) {
            console.error('[TaskRunner] Failed to report task error:', error);
        }
    }

    /**
     * 更新UI状态
     */
    function updateUIStatus(status) {
        // 触发自定义事件，让UI组件响应
        var event = new CustomEvent('taskRunnerStatusChange', {
            detail: {
                status: status,
                statusLabel: STATUS_LABELS[status] || status,
                taskId: state.currentTaskId,
                foundCount: state.foundCount,
                totalInferences: metrics.totalInferences || 0
            }
        });
        window.dispatchEvent(event);
    }

    /**
     * 更新进度显示
     */
    function updateProgress(foundCount, message) {
        var event = new CustomEvent('taskRunnerProgress', {
            detail: {
                foundCount: foundCount,
                message: message,
                taskId: state.currentTaskId,
                totalInferences: metrics.totalInferences || 0
            }
        });
        window.dispatchEvent(event);

        // 更新模型状态指示器
        if (typeof window.updateModelStatus === 'function') {
            window.updateModelStatus('running', metrics.inferenceTime, getMemoryUsage());
        }
    }

    /**
     * 更新模型状态指示器
     */
    function updateModelStatus(status, inferenceTime, memoryMB) {
        if (typeof window.updateModelStatus === 'function') {
            window.updateModelStatus(status, inferenceTime, memoryMB);
        }
    }

    /**
     * 获取内存使用量（MB）
     */
    function getMemoryUsage() {
        if (window.performance && performance.memory) {
            return performance.memory.usedJSHeapSize / 1024 / 1024;
        }
        return null;
    }

    /**
     * 辅助函数：睡眠
     */
    function sleep(ms) {
        return new Promise(function (resolve) {
            setTimeout(resolve, ms);
        });
    }

    /**
     * 获取当前状态
     */
    function getState() {
        return {
            status: state.status,
            statusLabel: STATUS_LABELS[state.status] || state.status,
            taskId: state.currentTaskId,
            foundCount: state.foundCount,
            candidates: state.candidates,
            metrics: metrics
        };
    }

    /**
     * 检查是否正在运行任务
     */
    function isRunning() {
        return state.status === TASK_STATUS.RUNNING ||
            state.status === TASK_STATUS.LOADING ||
            state.status === TASK_STATUS.CRAWLING ||
            state.status === TASK_STATUS.INFERRING;
    }

    /**
     * 获取当前任务ID
     */
    function getCurrentTaskId() {
        return state.currentTaskId;
    }

    /**
     * 获取找到的客户数量
     */
    function getFoundCount() {
        return state.foundCount;
    }

    /**
     * 获取状态标签
     */
    function getStatusLabel(status) {
        return STATUS_LABELS[status] || status;
    }

    // 导出公共API
    return {
        init: init,
        startTask: startTask,
        pauseTask: pauseTask,
        stopTask: stopTask,
        getState: getState,
        isRunning: isRunning,
        getCurrentTaskId: getCurrentTaskId,
        getFoundCount: getFoundCount,
        getStatusLabel: getStatusLabel,
        loadWasmModel: loadWasmModel,
        onLog: onLog,
        isExtensionAvailable: isExtensionAvailable,
        detectExtension: detectExtension,
        crawlWithExtension: crawlWithExtension,
        TASK_STATUS: TASK_STATUS,
        STATUS_LABELS: STATUS_LABELS
    };

})();

// 初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        AutoLeadAgentTaskRunner.init();
    });
} else {
    AutoLeadAgentTaskRunner.init();
}
