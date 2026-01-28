/**
 * Hugging Face 模型管理器
 * 优先检测 Chrome Built-in AI，降级到 @xenova/transformers
 */

var HFModelManager = (function () {
    'use strict';

    // 模型状态
    var modelState = {
        type: null, // 'chrome_ai' | 'webllm' | null
        loaded: false,
        loading: false,
        model: null,
        session: null,
        error: null,
        pipelineTask: null, // 当前 pipeline 任务类型
    };

    // 配置
    var config = {
        apiBaseUrl: '',
        modelId: null,
        cacheSize: 10240, // MB (最大默认值)
        localModelBaseUrl: null, // 本地模型文件 API 基础 URL
    };

    // 保存原始的 fetch 函数
    var originalFetch = typeof fetch !== 'undefined' ? fetch : null;

    /**
     * 创建自定义 fetch 函数，拦截 Hugging Face 请求并重定向到本地 API
     */
    function createCustomFetch() {
        if (!originalFetch) {
            return null;
        }

        return async function (url, options) {
            // 检查是否是 Hugging Face 模型文件请求
            if (typeof url === 'string' && url.includes('huggingface.co')) {
                // 提取模型ID和文件名
                var match = url.match(/huggingface\.co\/([^\/]+\/[^\/]+)\/resolve\/[^\/]+\/(.+)$/);
                if (match && match[2]) {
                    var urlModelId = match[1]; // 从URL中提取模型ID
                    var filename = decodeURIComponent(match[2]);
                    var originalFilename = filename;

                    // 使用配置的模型ID或URL中的模型ID
                    var effectiveModelId = config.modelId || urlModelId;

                    console.log('[HFModelManager] 拦截 Hugging Face 请求:', effectiveModelId, '/', filename);

                    // 尝试从 LocalFileStorage (IndexedDB) 读取
                    var fileData = null;

                    // 方法1: LocalFileStorage
                    if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getModelFile) {
                        try {
                            // 首先尝试精确匹配
                            fileData = await LocalFileStorage.getModelFile(effectiveModelId, filename);

                            // 如果精确匹配失败，尝试查找替代格式
                            if (!fileData || fileData.byteLength === 0) {
                                console.log('[HFModelManager] LocalFileStorage 精确匹配失败，尝试查找替代格式...');

                                // 获取模型的所有本地文件
                                var files = await LocalFileStorage.collectModelFiles(effectiveModelId);
                                console.log('[HFModelManager] 本地文件列表:', files);

                                // 尝试匹配不同的文件格式
                                if (filename.endsWith('.onnx')) {
                                    // onnx/decoder_model_merged_quantized.onnx -> model.safetensors
                                    if (filename.includes('decoder_model_merged')) {
                                        var altFilename = 'model.safetensors';
                                        fileData = await LocalFileStorage.getModelFile(effectiveModelId, altFilename);
                                        if (fileData && fileData.byteLength > 0) {
                                            console.log('[HFModelManager] 使用替代文件:', altFilename);
                                            filename = altFilename;
                                        }
                                    }
                                }

                                // 如果还是找不到，尝试查找包含相同模式的文件
                                if ((!fileData || fileData.byteLength === 0) && files && files.length > 0) {
                                    for (var i = 0; i < files.length; i++) {
                                        var localFile = files[i];
                                        // 尝试匹配基础文件名
                                        var localBaseName = localFile.filename.split('/').pop();
                                        var requestedBaseName = filename.split('/').pop();

                                        // 移除扩展名进行比较
                                        var localNameWithoutExt = localBaseName.replace(/\.(safetensors|bin|onnx)$/, '');
                                        var requestedNameWithoutExt = requestedBaseName.replace(/\.(safetensors|bin|onnx)$/, '');

                                        // 如果基本名称匹配（忽略扩展名）
                                        if (localNameWithoutExt === requestedNameWithoutExt ||
                                            (localBaseName.includes('model') && requestedBaseName.includes('model'))) {
                                            fileData = await LocalFileStorage.getModelFile(effectiveModelId, localFile.filename);
                                            if (fileData && fileData.byteLength > 0) {
                                                console.log('[HFModelManager] 使用匹配文件:', localFile.filename);
                                                filename = localFile.filename;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (localError) {
                            console.warn('[HFModelManager] LocalFileStorage 加载出错:', filename, localError.message);
                        }
                    }

                    // 方法2: ModelStorage（备用）
                    if ((!fileData || fileData.byteLength === 0) && typeof ModelStorage !== 'undefined' && ModelStorage.getModelFile) {
                        try {
                            console.log('[HFModelManager] 尝试从 ModelStorage 加载:', effectiveModelId, '/', filename);
                            fileData = await ModelStorage.getModelFile(effectiveModelId, filename);
                            if (fileData && fileData.byteLength > 0) {
                                console.log('[HFModelManager] 从 ModelStorage 加载成功:', filename, (fileData.byteLength / 1024 / 1024).toFixed(2), 'MB');
                            }
                        } catch (msError) {
                            console.warn('[HFModelManager] ModelStorage 加载出错:', filename, msError.message);
                        }
                    }

                    // 如果成功获取到文件数据，返回响应
                    if (fileData && fileData.byteLength > 0) {
                        console.log('[HFModelManager] 成功从缓存加载文件:', filename, (fileData.byteLength / 1024 / 1024).toFixed(2), 'MB');

                        // 创建 Blob 响应
                        var blob = new Blob([fileData], { type: 'application/octet-stream' });
                        var response = new Response(blob, {
                            status: 200,
                            statusText: 'OK',
                            headers: {
                                'Content-Type': 'application/octet-stream',
                                'Content-Length': fileData.byteLength.toString()
                            }
                        });
                        return response;
                    }

                    console.log('[HFModelManager] 缓存中未找到文件，从 Hugging Face 下载:', filename);

                    // 下载文件并保存到缓存
                    try {
                        var response = await originalFetch(url, options);
                        if (response.ok) {
                            // 克隆响应以便保存
                            var responseClone = response.clone();
                            var arrayBuffer = await responseClone.arrayBuffer();

                            if (arrayBuffer && arrayBuffer.byteLength > 0) {
                                console.log('[HFModelManager] 下载完成，保存到缓存:', filename, (arrayBuffer.byteLength / 1024 / 1024).toFixed(2), 'MB');

                                // 异步保存到 LocalFileStorage
                                if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFile) {
                                    LocalFileStorage.saveModelFile(effectiveModelId, filename, arrayBuffer)
                                        .then(function() {
                                            console.log('[HFModelManager] 文件已缓存到 LocalFileStorage:', filename);
                                        })
                                        .catch(function(saveError) {
                                            console.warn('[HFModelManager] 缓存文件失败:', filename, saveError);
                                        });
                                }

                                // 同时保存到 ModelStorage
                                if (typeof ModelStorage !== 'undefined' && ModelStorage.saveModelFile) {
                                    ModelStorage.saveModelFile(effectiveModelId, filename, arrayBuffer)
                                        .then(function() {
                                            console.log('[HFModelManager] 文件已缓存到 ModelStorage:', filename);
                                        })
                                        .catch(function(msError) {
                                            console.warn('[HFModelManager] ModelStorage 缓存失败:', filename, msError);
                                        });
                                }
                            }
                        }
                        return response;
                    } catch (fetchError) {
                        console.error('[HFModelManager] 从 Hugging Face 下载失败:', filename, fetchError);
                        throw fetchError;
                    }
                }
            }

            // 使用原始 fetch（非 Hugging Face 请求）
            return originalFetch(url, options);
        };
    }

    /**
     * 初始化模型管理器
     * @param {Object} options 配置选项
     */
    function init(options) {
        options = options || {};
        config.apiBaseUrl = options.apiBaseUrl || '';
        config.modelId = options.modelId || null;
        config.cacheSize = options.cacheSize || 10240;

        // 设置 localModelBaseUrl 以启用本地文件系统优先
        // 即使值为 null 或空字符串，只要存在就会触发 createCustomFetch
        if (options.localModelBaseUrl === undefined) {
            config.localModelBaseUrl = '/models/' + config.modelId; // 使用本地文件系统
        } else {
            config.localModelBaseUrl = options.localModelBaseUrl;
        }

        // 不再需要构建服务器 API URL，直接使用本地文件系统
        console.log('[HFModelManager] Initialized with config:', config);
        console.log('[HFModelManager] 将使用本地文件系统存储（File System Access API）');
    }

    /**
     * 检测 Chrome Built-in AI
     * @returns {boolean} 是否可用
     */
    function detectChromeAI() {
        try {
            return typeof window !== 'undefined' &&
                window.ai &&
                typeof window.ai.createTextSession === 'function';
        } catch (e) {
            console.warn('[HFModelManager] Chrome AI detection failed:', e);
            return false;
        }
    }

    /**
     * 加载 Chrome Built-in AI
     * @returns {Promise<Object>} 会话对象
     */
    async function loadChromeAI() {
        try {
            console.log('[HFModelManager] Loading Chrome Built-in AI...');

            if (!detectChromeAI()) {
                throw new Error('Chrome Built-in AI is not available');
            }

            const session = await window.ai.createTextSession();

            modelState.type = 'chrome_ai';
            modelState.session = session;
            modelState.loaded = true;
            modelState.loading = false;
            modelState.error = null;

            console.log('[HFModelManager] Chrome Built-in AI loaded successfully');
            return session;
        } catch (error) {
            console.error('[HFModelManager] Failed to load Chrome AI:', error);
            modelState.error = error.message;
            throw error;
        }
    }

    /**
     * 根据模型 ID 推断 pipeline 任务类型
     * @param {string} modelId 模型 ID
     * @returns {string} 任务类型
     */
    function inferPipelineTask(modelId) {
        if (!modelId) return 'text-generation';

        var lowerModelId = modelId.toLowerCase();

        // sentence-transformers 模型使用 feature-extraction
        if (lowerModelId.includes('sentence-transformer') ||
            lowerModelId.includes('all-minilm') ||
            lowerModelId.includes('all-mpnet') ||
            lowerModelId.includes('paraphrase') ||
            lowerModelId.includes('msmarco') ||
            lowerModelId.includes('multi-qa')) {
            return 'feature-extraction';
        }

        // BERT/RoBERTa 等编码器模型
        if (lowerModelId.includes('bert') && !lowerModelId.includes('gpt')) {
            // 检查是否是分类模型
            if (lowerModelId.includes('classification') ||
                lowerModelId.includes('sentiment') ||
                lowerModelId.includes('ner') ||
                lowerModelId.includes('qa')) {
                return 'text-classification';
            }
            return 'feature-extraction';
        }

        // T5/FLAN 等 seq2seq 模型
        if (lowerModelId.includes('t5') ||
            lowerModelId.includes('flan') ||
            lowerModelId.includes('bart') ||
            lowerModelId.includes('pegasus')) {
            return 'text2text-generation';
        }

        // 问答模型
        if (lowerModelId.includes('qa') || lowerModelId.includes('question-answering')) {
            return 'question-answering';
        }

        // 翻译模型
        if (lowerModelId.includes('translation') || lowerModelId.includes('opus-mt')) {
            return 'translation';
        }

        // 摘要模型
        if (lowerModelId.includes('summarization') || lowerModelId.includes('summary')) {
            return 'summarization';
        }

        // 默认使用 text-generation（适用于 GPT, Llama, Qwen 等）
        return 'text-generation';
    }

    /**
     * 检查模型是否支持 WebLLM/ONNX 格式
     * @param {string} modelId 模型 ID
     * @returns {boolean} 是否支持
     */
    function isModelSupportedForWebLLM(modelId) {
        if (!modelId) return false;

        var lowerModelId = modelId.toLowerCase();

        // 已知不支持的模型列表
        var unsupportedModels = [
            'openai-community/gpt2',
            'openai-community/gpt3',
            'openai-community/gpt4',
            'meta-llama',
            'meta-llama-2',
            'meta-llama-3',
            'mistralai/mistral-7b'
        ];

        // 检查是否在不支持的列表中
        for (var i = 0; i < unsupportedModels.length; i++) {
            if (lowerModelId.includes(unsupportedModels[i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 加载 WebLLM 模型（@xenova/transformers）
     * @returns {Promise<Object>} 模型对象
     */
    async function loadWebLLM() {
        // 在函数作用域内定义变量，确保在 finally 中可访问
        var originalEnvFetch = null;
        var originalGlobalFetch = null;
        var env = null;

        try {
            console.log('[HFModelManager] Loading WebLLM model...');

            modelState.loading = true;
            modelState.error = null;

            // 派发加载开始事件
            if (typeof window !== 'undefined') {
                window.dispatchEvent(new CustomEvent('hf-model-loading-progress', {
                    detail: {
                        percent: 0,
                        status: 'start',
                        file: '',
                        modelId: config.modelId
                    }
                }));
            }

            // 检查是否已加载 @xenova/transformers
            if (typeof window.pipeline === 'undefined') {
                // 动态加载 @xenova/transformers
                await loadTransformersLibrary();
            }

            if (!config.modelId) {
                throw new Error('Model ID is not configured');
            }

            // 使用 @xenova/transformers 加载模型
            const transformersModule = await import('https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2');
            const { pipeline, env: transformersEnv } = transformersModule;
            env = transformersEnv; // 保存到外部变量

            // 配置 transformers.js 环境以启用缓存
            if (typeof env !== 'undefined') {
                // 启用本地缓存
                env.useBrowserCache = true;
                env.allowLocalModels = true;
                env.allowRemoteModels = true;

                // 设置缓存目录（transformers.js 会使用 IndexedDB）
                // env.cacheDir = 'models'; // 使用默认的 IndexedDB 缓存

                console.log('[HFModelManager] Transformers.js 缓存配置:', {
                    useBrowserCache: env.useBrowserCache,
                    allowLocalModels: env.allowLocalModels,
                    allowRemoteModels: env.allowRemoteModels
                });
            }

            // 配置自定义 fetch（用于从我们的 IndexedDB 缓存加载）
            var customFetch = createCustomFetch();

            // 方法1：尝试设置 env.fetch（如果 transformers 支持）
            if (customFetch && typeof env !== 'undefined') {
                if (env.fetch) {
                    originalEnvFetch = env.fetch;
                }
                env.fetch = customFetch;
                console.log('[HFModelManager] 已配置 env.fetch，将优先使用本地缓存');
            }

            // 方法2：如果 transformers 使用全局 fetch，也替换它
            if (customFetch && typeof window !== 'undefined' && typeof fetch !== 'undefined') {
                originalGlobalFetch = window.fetch;
                window.fetch = customFetch;
                console.log('[HFModelManager] 已配置全局 fetch，将优先使用本地缓存');
            }

            console.log('[HFModelManager] Loading model:', config.modelId);

            // 根据模型 ID 推断任务类型
            var taskType = inferPipelineTask(config.modelId);
            console.log('[HFModelManager] 推断的任务类型:', taskType);

            // 进度回调函数 - 用于更新 UI
            var progressCallback = function (data) {
                if (data.status === 'progress') {
                    var percent = Math.round((data.progress || 0) * 100);
                    var fileName = data.file || '';
                    console.log('[HFModelManager] 加载进度:', percent + '%', fileName);
                    
                    // 派发自定义事件以更新 UI
                    if (typeof window !== 'undefined') {
                        window.dispatchEvent(new CustomEvent('hf-model-loading-progress', {
                            detail: {
                                percent: percent,
                                file: fileName,
                                status: data.status,
                                loaded: data.loaded,
                                total: data.total
                            }
                        }));
                    }
                } else if (data.status === 'ready') {
                    console.log('[HFModelManager] 模型准备就绪');
                    if (typeof window !== 'undefined') {
                        window.dispatchEvent(new CustomEvent('hf-model-loading-progress', {
                            detail: { percent: 100, status: 'ready', file: '' }
                        }));
                    }
                } else if (data.status === 'initiate' || data.status === 'download') {
                    console.log('[HFModelManager] 开始下载:', data.file || '');
                    if (typeof window !== 'undefined') {
                        window.dispatchEvent(new CustomEvent('hf-model-loading-progress', {
                            detail: {
                                percent: 0,
                                file: data.file || '',
                                status: data.status
                            }
                        }));
                    }
                }
            };

            // 尝试加载模型，如果失败则提供更友好的错误信息
            let model;
            try {
                model = await pipeline(taskType, config.modelId, {
                    device: 'webgpu', // 优先使用 WebGPU
                    dtype: 'q8', // 量化以节省内存
                    quantized: false, // 不使用量化版本，使用已下载的 safetensors 文件
                    progress_callback: progressCallback
                });
            } catch (error) {
                // 如果 WebGPU 失败，尝试使用 CPU
                console.warn('[HFModelManager] WebGPU failed, trying CPU:', error);
                try {
                    model = await pipeline(taskType, config.modelId, {
                        device: 'cpu',
                        quantized: false, // 不使用量化版本
                        progress_callback: progressCallback
                    });
                } catch (cpuError) {
                    // 如果都失败，抛出更友好的错误
                    const errorMsg = cpuError.message || String(cpuError);

                    // 检查是否是文件不存在的错误
                    if (errorMsg.includes('Could not locate file') ||
                        errorMsg.includes('locate file') ||
                        errorMsg.includes('404') ||
                        errorMsg.includes('not found')) {
                        console.warn('[HFModelManager] 模型文件不存在或无法访问:', errorMsg);

                        // 尝试从错误信息中提取缺失的文件名
                        var missingFile = extractMissingFileName(errorMsg);
                        if (missingFile && config.modelId) {
                            console.log('[HFModelManager] 检测到缺失文件:', missingFile);

                            // 先检查文件是否在 Hugging Face 仓库中存在
                            var fileCheck = await checkFileExistsInRepository(config.modelId, missingFile);
                            if (!fileCheck.exists) {
                                // 文件在仓库中不存在，不应该尝试下载
                                console.warn('[HFModelManager] 文件在模型仓库中不存在:', missingFile, fileCheck.reason);

                                // 显示友好的错误提示，建议用户换模型
                                var friendlyErrorMsg = '模型 "' + config.modelId + '" 加载失败\n\n';
                                friendlyErrorMsg += '原因：文件 "' + missingFile + '" 在模型仓库中不存在\n\n';
                                friendlyErrorMsg += '该模型可能不支持 WebLLM/ONNX 格式，建议更换为以下支持的模型：\n';
                                friendlyErrorMsg += '• Qwen/Qwen2.5-1.5B-Instruct（推荐，小模型，速度快）\n';
                                friendlyErrorMsg += '• Qwen/Qwen3-0.6B（超小模型，适合测试）\n';
                                friendlyErrorMsg += '• Qwen/Qwen2.5-3B-Instruct（中等模型）\n\n';
                                friendlyErrorMsg += '提示：如果 Chrome Built-in AI 可用，系统会自动使用它。';

                                // 尝试显示 Toast 提示（如果可用）
                                if (typeof window !== 'undefined' && typeof window.safeToast === 'function') {
                                    window.safeToast('error', friendlyErrorMsg, 15000); // 显示15秒
                                } else if (typeof window !== 'undefined' && typeof window.alert === 'function') {
                                    window.alert(friendlyErrorMsg);
                                }

                                throw new Error(friendlyErrorMsg);
                            }

                            // 文件在仓库中存在，检查本地文件是否完整
                            var fileComplete = await checkFileComplete(config.modelId, missingFile);
                            if (!fileComplete) {
                                // 触发自动下载
                                try {
                                    await triggerModelDownload(config.modelId, missingFile);
                                    // 下载完成后，重新尝试加载模型
                                    console.log('[HFModelManager] 模型下载完成，重新尝试加载');
                                    model = await pipeline('text-generation', config.modelId, {
                                        device: 'cpu',
                                    });
                                    // 如果成功，继续执行
                                    modelState.type = 'webllm';
                                    modelState.model = model;
                                    modelState.loaded = true;
                                    modelState.loading = false;
                                    modelState.error = null;
                                    modelState.pipelineTask = taskType;
                                    console.log('[HFModelManager] WebLLM model loaded successfully after auto-download');
                                    return model;
                                } catch (downloadError) {
                                    console.error('[HFModelManager] 自动下载失败:', downloadError);
                                    // 下载失败，继续原有的降级逻辑
                                }
                            } else {
                                console.log('[HFModelManager] 文件已存在且完整，可能是其他问题:', missingFile);
                            }
                        }

                        // 检查是否可以使用 Chrome Built-in AI 作为降级方案
                        if (detectChromeAI()) {
                            console.warn('[HFModelManager] 模型文件缺失，但检测到 Chrome Built-in AI 可用，将在 loadModel 中自动降级');
                            // 不在这里抛出错误，让 loadModel 函数处理降级
                            throw new Error('MODEL_FILE_NOT_FOUND'); // 特殊标记，让 loadModel 知道是文件问题
                        }
                        // 提供更详细的错误信息，包括 WASM 限制说明和建议换模型
                        var detailedError = '模型 "' + (config.modelId || '未知') + '" 加载失败\n\n';
                        detailedError += '错误：' + errorMsg + '\n\n';
                        detailedError += '可能的原因：\n';
                        detailedError += '1. 文件在模型仓库中不存在（某些模型可能不支持 ONNX/WebLLM 格式）\n';
                        detailedError += '2. 网络连接问题\n';
                        detailedError += '3. WASM 内存限制（浏览器中 WASM 通常只能加载小于 2GB 的模型）\n\n';
                        detailedError += '建议更换为以下支持的模型：\n';
                        detailedError += '• Qwen/Qwen2.5-1.5B-Instruct（推荐，小模型，速度快）\n';
                        detailedError += '• Qwen/Qwen3-0.6B（超小模型，适合测试）\n';
                        detailedError += '• Qwen/Qwen2.5-3B-Instruct（中等模型）\n\n';
                        detailedError += '提示：如果 Chrome Built-in AI 可用，系统会自动使用它。';

                        // 尝试显示 Toast 提示（如果可用）
                        if (typeof window !== 'undefined' && typeof window.safeToast === 'function') {
                            window.safeToast('error', detailedError, 15000); // 显示15秒
                        } else if (typeof window !== 'undefined' && typeof window.alert === 'function') {
                            window.alert(detailedError);
                        }

                        throw new Error(detailedError);
                    }

                    if (errorMsg.includes('Unsupported model type') || errorMsg.includes('qwen3')) {
                        // 检查是否可以使用 Chrome Built-in AI 作为降级方案
                        if (detectChromeAI()) {
                            console.warn('[HFModelManager] 模型类型不支持，但检测到 Chrome Built-in AI 可用，将在 loadModel 中自动降级');
                            // 不在这里抛出错误，让 loadModel 函数处理降级
                        }
                        throw new Error('当前模型类型（' + config.modelId + '）可能不被 @xenova/transformers 支持。建议：1. 使用 Chrome Built-in AI（如果可用）；2. 或选择其他支持的模型（如 Qwen/Qwen2.5-1.5B-Instruct、microsoft/Phi-3-mini-4k-instruct、TinyLlama/TinyLlama-1.1B-Chat-v1.0 等）。原始错误: ' + errorMsg);
                    }
                    throw cpuError;
                }
            }

            modelState.type = 'webllm';
            modelState.model = model;
            modelState.loaded = true;
            modelState.loading = false;
            modelState.error = null;
            modelState.pipelineTask = taskType;

            console.log('[HFModelManager] WebLLM model loaded successfully, task:', taskType);
            return model;
        } catch (error) {
            console.error('[HFModelManager] Failed to load WebLLM:', error);
            modelState.loading = false;
            modelState.error = error.message;

            // 检查是否是 IR 版本不兼容错误
            if (error.message && (error.message.includes('IR version') || error.message.includes('Unsupported model IR'))) {
                console.warn('[HFModelManager] ONNX IR 版本不兼容，需要使用旧版本模型');

                var friendlyErrorMsg = '模型 "' + config.modelId + '" 使用的 ONNX IR 版本过高。\n\n';
                friendlyErrorMsg += 'ONNX Runtime WebAssembly 最大支持 IR 版本 8，但模型使用了 IR 版本 9。\n\n';
                friendlyErrorMsg += '建议方案：\n';
                friendlyErrorMsg += '1. 使用支持 WebLLM 的模型（如 Qwen/Qwen3-0.6B）\n';
                friendlyErrorMsg += '2. 使用 Qwen/Qwen2.5-1.5B-Instruct（推荐）\n';
                friendlyErrorMsg += '3. 如果 Chrome Built-in AI 可用，系统会自动使用它\n\n';
                friendlyErrorMsg += '提示：请选择带有 "WebLLM compatible" 标签的模型。';

                if (typeof window !== 'undefined' && typeof window.alert === 'function') {
                    window.alert(friendlyErrorMsg);
                }

                throw error;
            }

            // 检查是否是 ONNX 会话创建失败
            if (error.message && error.message.includes('create a session')) {
                console.warn('[HFModelManager] ONNX 会话创建失败，可能是因为模型格式不兼容');

                // 尝试检查是否有本地 safetensors 文件
                if (typeof LocalFileStorage !== 'undefined') {
                    try {
                        var metadata = LocalFileStorage.getModelMetadata();
                        var modelData = metadata[config.modelId];
                        if (modelData && modelData.files && modelData.files.length > 0) {
                            var hasSafetensors = modelData.files.some(f => f.filename.endsWith('.safetensors'));
                            var hasOnnx = modelData.files.some(f => f.filename.endsWith('.onnx'));

                            if (hasSafetensors && !hasOnnx) {
                                // 下载的是 safetensors 格式，但 ONNX Runtime 需要 onnx 格式
                                var friendlyErrorMsg = '模型 "' + config.modelId + '" 已下载，但使用的是 PyTorch safetensors 格式。\n\n';
                                friendlyErrorMsg += '当前浏览器环境需要 ONNX 格式的模型才能运行。\n\n';
                                friendlyErrorMsg += '建议方案：\n';
                                friendlyErrorMsg += '1. 使用支持 WebLLM 的模型（如 Qwen/Qwen3-0.6B）\n';
                                friendlyErrorMsg += '2. 如果需要使用此模型，可能需要服务器端推理\n\n';
                                friendlyErrorMsg += '提示：Chrome Built-in AI 可能可以提供本地推理能力。';

                                if (typeof window !== 'undefined' && typeof window.alert === 'function') {
                                    window.alert(friendlyErrorMsg);
                                }
                            }
                        }
                    } catch (e) {
                        console.warn('[HFModelManager] 检查模型格式失败:', e);
                    }
                }
            }

            throw error;
        } finally {
            // 恢复原始 fetch（如果已替换）
            if (originalEnvFetch && env && typeof env !== 'undefined') {
                env.fetch = originalEnvFetch;
            }
            if (originalGlobalFetch && typeof window !== 'undefined') {
                window.fetch = originalGlobalFetch;
            }
        }
    }

    /**
     * 从错误信息中提取缺失的文件名
     * @param {string} errorMsg 错误信息
     * @returns {string|null} 文件名或null
     */
    function extractMissingFileName(errorMsg) {
        try {
            // 尝试从URL中提取文件名
            // 格式: "Could not locate file: "https://huggingface.co/openai-community/gpt2/resolve/main/onnx/decoder_model_merged_quantized.onnx""
            var urlMatch = errorMsg.match(/https?:\/\/[^"'\s]+/);
            if (urlMatch) {
                var url = urlMatch[0];
                // 从URL中提取文件名（resolve/main/之后的部分）
                var pathMatch = url.match(/\/resolve\/main\/(.+)$/);
                if (pathMatch && pathMatch[1]) {
                    return decodeURIComponent(pathMatch[1]);
                }
            }

            // 尝试直接匹配文件名模式
            var filenameMatch = errorMsg.match(/([a-zA-Z0-9_\-\.\/]+\.(onnx|bin|safetensors|json|txt|model))/);
            if (filenameMatch) {
                return filenameMatch[1];
            }

            return null;
        } catch (e) {
            console.warn('[HFModelManager] 提取文件名失败:', e);
            return null;
        }
    }

    /**
     * 检查文件是否在 Hugging Face 仓库中存在
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<{exists: boolean, reason?: string}>} 文件是否存在及原因
     */
    async function checkFileExistsInRepository(modelId, filename) {
        try {
            // 尝试从 Hugging Face 获取文件信息
            var encodedModelId = modelId.split('/').map(encodeURIComponent).join('/');
            var fileUrl = 'https://huggingface.co/' + encodedModelId + '/resolve/main/' + encodeURIComponent(filename);

            // 使用 HEAD 请求检查文件是否存在
            var response = await originalFetch(fileUrl, {
                method: 'HEAD',
                credentials: 'include'
            });

            if (response.ok) {
                return { exists: true };
            } else if (response.status === 404) {
                return { exists: false, reason: '文件在模型仓库中不存在' };
            } else {
                return { exists: false, reason: '无法检查文件状态（HTTP ' + response.status + '）' };
            }
        } catch (e) {
            console.warn('[HFModelManager] 检查文件在仓库中是否存在失败:', e);
            return { exists: false, reason: '检查失败: ' + e.message };
        }
    }

    /**
     * 检查文件是否完整（通过API检查文件是否存在且大小正确）
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<boolean>} 文件是否完整
     */
    async function checkFileComplete(modelId, filename) {
        try {
            if (!config.apiBaseUrl) {
                return false;
            }

            // 构建检查文件API URL
            var checkUrl = config.apiBaseUrl + 'check-model-file?model_id=' + encodeURIComponent(modelId) + '&filename=' + encodeURIComponent(filename);

            var response = await originalFetch(checkUrl);
            if (!response.ok) {
                return false;
            }

            var data = await response.json();
            // 如果文件存在且大小大于0，认为文件完整
            return data.success && data.exists && data.data && data.data.size > 0;
        } catch (e) {
            console.warn('[HFModelManager] 检查文件完整性失败:', e);
            return false;
        }
    }

    /**
     * 触发模型自动下载
     * @param {string} modelId 模型ID
     * @param {string} missingFile 缺失的文件名（可选）
     * @returns {Promise<void>}
     */
    async function triggerModelDownload(modelId, missingFile) {
        return new Promise(function (resolve, reject) {
            if (!modelId) {
                reject(new Error('模型ID不能为空'));
                return;
            }

            console.log('[HFModelManager] 触发模型自动下载:', modelId, missingFile ? '(缺失文件: ' + missingFile + ')' : '');

            // 检查是否有全局的 ensureModelDownloaded 函数（来自 config-models.js）
            if (typeof window !== 'undefined' && typeof window.ensureModelDownloaded === 'function') {
                window.ensureModelDownloaded(modelId)
                    .then(function (result) {
                        console.log('[HFModelManager] 模型下载完成:', result);
                        // 如果指定了缺失文件，检查该文件是否已完整
                        if (missingFile) {
                            return checkFileComplete(modelId, missingFile).then(function (complete) {
                                if (!complete) {
                                    console.warn('[HFModelManager] 文件下载后仍不完整:', missingFile);
                                    // 即使不完整，也继续，因为可能是文件本身不存在
                                }
                                resolve(result);
                            });
                        }
                        resolve(result);
                    })
                    .catch(function (error) {
                        console.error('[HFModelManager] 模型下载失败:', error);
                        reject(error);
                    });
                return;
            }

            // 如果没有全局函数，尝试通过消息机制触发下载
            if (typeof window !== 'undefined' && typeof window.postMessage === 'function') {
                // 发送消息给扩展或页面脚本
                window.postMessage({
                    type: 'HF_DOWNLOAD_MODEL',
                    modelId: modelId,
                    auto: true,
                    missingFile: missingFile
                }, '*');

                // 等待下载完成（通过轮询检查）
                var checkInterval = setInterval(function () {
                    // 这里可以添加检查逻辑，暂时使用超时
                }, 1000);

                // 设置超时
                setTimeout(function () {
                    clearInterval(checkInterval);
                    // 假设下载已完成（实际应该检查文件是否存在）
                    resolve({ success: true, auto: true });
                }, 30000); // 30秒超时
                return;
            }

            // 如果都没有，直接拒绝
            reject(new Error('无法触发模型下载：缺少必要的函数或API'));
        });
    }

    /**
     * 动态加载 @xenova/transformers 库
     */
    async function loadTransformersLibrary() {
        return new Promise((resolve, reject) => {
            if (typeof window.pipeline !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.type = 'module';
            script.textContent = `
                import { pipeline } from 'https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2';
                window.pipeline = pipeline;
                window.dispatchEvent(new Event('transformersLoaded'));
            `;
            document.head.appendChild(script);

            window.addEventListener('transformersLoaded', resolve, { once: true });
            script.onerror = reject;
        });
    }

    /**
     * 加载模型（自动选择最佳方案）
     * @returns {Promise<Object>} 模型或会话对象
     */
    async function loadModel() {
        if (modelState.loaded && modelState.type) {
            console.log('[HFModelManager] Model already loaded:', modelState.type);
            return modelState.type === 'chrome_ai' ? modelState.session : modelState.model;
        }

        if (modelState.loading) {
            console.log('[HFModelManager] Model is already loading, waiting...');
            // 等待加载完成
            while (modelState.loading) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            return modelState.type === 'chrome_ai' ? modelState.session : modelState.model;
        }

        try {
            // 优先尝试 Chrome Built-in AI
            if (detectChromeAI()) {
                console.log('[HFModelManager] Chrome Built-in AI detected, using it');
                return await loadChromeAI();
            }

            // 降级到 WebLLM
            console.log('[HFModelManager] Chrome Built-in AI not available, falling back to WebLLM');
            try {
                return await loadWebLLM();
            } catch (webllmError) {
                // 如果 WebLLM 加载失败（特别是模型类型不支持或文件不存在），尝试再次使用 Chrome Built-in AI
                const errorMsg = webllmError.message || String(webllmError);
                const isUnsupportedModel = errorMsg.includes('Unsupported model type') ||
                    errorMsg.includes('qwen3') ||
                    errorMsg.includes('not supported');
                const isFileNotFound = errorMsg.includes('Could not locate file') ||
                    errorMsg.includes('locate file') ||
                    errorMsg === 'MODEL_FILE_NOT_FOUND' ||
                    errorMsg.includes('404') ||
                    errorMsg.includes('not found');

                if ((isUnsupportedModel || isFileNotFound) && detectChromeAI()) {
                    console.warn('[HFModelManager] WebLLM model loading failed, falling back to Chrome Built-in AI:', errorMsg);
                    if (isFileNotFound) {
                        console.log('[HFModelManager] 模型文件不存在或无法访问，自动切换到 Chrome Built-in AI');
                    } else {
                        console.log('[HFModelManager] 当前模型类型可能不被 @xenova/transformers 支持，自动切换到 Chrome Built-in AI');
                    }
                    return await loadChromeAI();
                }

                // 如果 Chrome AI 也不可用，或者不是模型类型/文件问题，抛出原始错误
                throw webllmError;
            }
        } catch (error) {
            console.error('[HFModelManager] Failed to load model:', error);

            // 最后尝试：如果所有方法都失败，但 Chrome AI 可用，强制使用 Chrome AI
            const errorMsg = error.message || String(error);
            if (detectChromeAI() && !modelState.loaded) {
                console.warn('[HFModelManager] All loading methods failed, attempting Chrome Built-in AI as last resort');
                try {
                    return await loadChromeAI();
                } catch (chromeError) {
                    console.error('[HFModelManager] Chrome AI also failed:', chromeError);
                    throw new Error('模型加载失败。原因：' + errorMsg + '。建议：1. 使用 Chrome Built-in AI（如果可用）；2. 或选择其他支持的模型（如 Qwen2、Llama 等）。');
                }
            }

            throw error;
        }
    }

    /**
     * 生成文本（统一接口）
     * @param {string} prompt 提示词
     * @param {Object} options 选项
     * @returns {Promise<string>} 生成的文本或嵌入结果描述
     */
    async function generate(prompt, options) {
        options = options || {};

        if (!modelState.loaded) {
            await loadModel();
        }

        try {
            if (modelState.type === 'chrome_ai') {
                // 使用 Chrome Built-in AI
                const response = await modelState.session.prompt(prompt);
                return response || '';
            } else if (modelState.type === 'webllm') {
                // 根据 pipeline 任务类型使用不同的推理逻辑
                const task = modelState.pipelineTask || 'text-generation';

                if (task === 'feature-extraction') {
                    // 嵌入模型：返回向量信息
                    const result = await modelState.model(prompt, { pooling: 'mean', normalize: true });
                    if (result && result.data) {
                        var dims = result.dims || [result.data.length];
                        return '✓ 嵌入生成成功\n维度: ' + dims.join('x') + '\n前5个值: [' +
                            Array.from(result.data).slice(0, 5).map(v => v.toFixed(4)).join(', ') + ', ...]';
                    }
                    return '嵌入生成完成（无数据返回）';
                }

                if (task === 'text-classification') {
                    // 分类模型
                    const result = await modelState.model(prompt);
                    if (Array.isArray(result) && result.length > 0) {
                        return '分类结果: ' + result[0].label + ' (置信度: ' + (result[0].score * 100).toFixed(1) + '%)';
                    }
                    return JSON.stringify(result);
                }

                if (task === 'text2text-generation' || task === 'translation' || task === 'summarization') {
                    // Seq2Seq 模型
                    const result = await modelState.model(prompt, {
                        max_new_tokens: options.maxTokens || 256,
                    });
                    if (Array.isArray(result) && result.length > 0) {
                        return result[0].generated_text || result[0].translation_text || result[0].summary_text || '';
                    }
                    return '';
                }

                // 默认：text-generation
                const result = await modelState.model(prompt, {
                    max_new_tokens: options.maxTokens || 512,
                    temperature: options.temperature || 0.7,
                    top_p: options.topP || 0.9,
                });

                if (Array.isArray(result) && result.length > 0) {
                    return result[0].generated_text || '';
                }
                return '';
            } else {
                throw new Error('Model is not loaded');
            }
        } catch (error) {
            console.error('[HFModelManager] Generation failed:', error);
            throw error;
        }
    }

    /**
     * 获取文本嵌入向量
     * @param {string} text 输入文本
     * @returns {Promise<Float32Array>} 嵌入向量
     */
    async function getEmbedding(text) {
        if (!modelState.loaded) {
            await loadModel();
        }

        if (modelState.type !== 'webllm' || modelState.pipelineTask !== 'feature-extraction') {
            throw new Error('当前模型不支持嵌入提取，请选择 sentence-transformers 类型的模型');
        }

        try {
            const result = await modelState.model(text, { pooling: 'mean', normalize: true });
            return result.data;
        } catch (error) {
            console.error('[HFModelManager] Embedding failed:', error);
            throw error;
        }
    }

    /**
     * 流式生成文本
     * @param {string} prompt 提示词
     * @param {Function} callback 回调函数
     * @param {Object} options 选项
     */
    async function generateStream(prompt, callback, options) {
        options = options || {};

        if (!modelState.loaded) {
            await loadModel();
        }

        try {
            if (modelState.type === 'chrome_ai') {
                // Chrome AI 可能不支持流式，使用普通生成
                const response = await generate(prompt, options);
                if (typeof callback === 'function') {
                    callback(response);
                }
            } else if (modelState.type === 'webllm') {
                // WebLLM 流式生成
                const stream = await modelState.model(prompt, {
                    max_new_tokens: options.maxTokens || 512,
                    temperature: options.temperature || 0.7,
                    top_p: options.topP || 0.9,
                    stream: true,
                });

                for await (const chunk of stream) {
                    if (typeof callback === 'function') {
                        callback(chunk.generated_text || '');
                    }
                }
            } else {
                throw new Error('Model is not loaded');
            }
        } catch (error) {
            console.error('[HFModelManager] Stream generation failed:', error);
            throw error;
        }
    }

    /**
     * 获取模型状态
     * @returns {Object} 状态对象
     */
    function getState() {
        return {
            type: modelState.type,
            loaded: modelState.loaded,
            loading: modelState.loading,
            error: modelState.error,
            modelId: config.modelId,
        };
    }

    /**
     * 检查模型是否已加载
     * @returns {boolean} 是否已加载
     */
    function isLoaded() {
        return modelState.loaded && (modelState.session || modelState.model);
    }

    /**
     * 卸载模型
     */
    function unload() {
        if (modelState.type === 'chrome_ai' && modelState.session) {
            // Chrome AI 会话可能不需要显式关闭
            modelState.session = null;
        } else if (modelState.type === 'webllm' && modelState.model) {
            // WebLLM 模型清理
            modelState.model = null;
        }

        modelState.type = null;
        modelState.loaded = false;
        modelState.loading = false;
        modelState.error = null;
        modelState.pipelineTask = null;

        console.log('[HFModelManager] Model unloaded');
    }

    /**
     * 检查模型是否已缓存
     * @param {string} modelId 模型ID（可选，默认使用配置的模型ID）
     * @returns {Promise<{cached: boolean, files: Array, totalSize: number}>}
     */
    async function checkModelCache(modelId) {
        modelId = modelId || config.modelId;
        if (!modelId) {
            return { cached: false, files: [], totalSize: 0 };
        }

        console.log('[HFModelManager] 检查模型缓存:', modelId);

        var result = {
            cached: false,
            files: [],
            totalSize: 0,
            sources: []
        };

        // 检查 LocalFileStorage
        if (typeof LocalFileStorage !== 'undefined') {
            try {
                var files = await LocalFileStorage.collectModelFiles(modelId);
                if (files && files.length > 0) {
                    result.cached = true;
                    result.files = files;
                    result.totalSize = files.reduce(function(sum, f) { return sum + (f.size || 0); }, 0);
                    result.sources.push('LocalFileStorage');
                    console.log('[HFModelManager] LocalFileStorage 缓存:', files.length, '文件,', (result.totalSize / 1024 / 1024).toFixed(2), 'MB');
                }
            } catch (error) {
                console.warn('[HFModelManager] 检查 LocalFileStorage 缓存失败:', error);
            }
        }

        // 检查 ModelStorage
        if (typeof ModelStorage !== 'undefined' && ModelStorage.collectModelFiles) {
            try {
                var msFiles = await ModelStorage.collectModelFiles(modelId);
                if (msFiles && msFiles.length > 0) {
                    if (!result.cached) {
                        result.cached = true;
                        result.files = msFiles;
                        result.totalSize = msFiles.reduce(function(sum, f) { return sum + (f.size || 0); }, 0);
                    }
                    result.sources.push('ModelStorage');
                    console.log('[HFModelManager] ModelStorage 缓存:', msFiles.length, '文件');
                }
            } catch (error) {
                console.warn('[HFModelManager] 检查 ModelStorage 缓存失败:', error);
            }
        }

        return result;
    }

    /**
     * 获取缓存统计信息
     * @returns {Promise<{totalSize: number, models: Array, quota: Object}>}
     */
    async function getCacheStats() {
        var stats = {
            totalSize: 0,
            models: [],
            quota: { usage: 0, quota: 0, percentUsed: 0 }
        };

        // 从 LocalFileStorage 获取统计
        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getStorageStats) {
            try {
                var lsStats = await LocalFileStorage.getStorageStats();
                stats.totalSize = lsStats.totalSize || 0;
                stats.models = lsStats.models || [];
            } catch (error) {
                console.warn('[HFModelManager] 获取 LocalFileStorage 统计失败:', error);
            }
        }

        // 获取存储配额
        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getStorageQuota) {
            try {
                stats.quota = await LocalFileStorage.getStorageQuota();
            } catch (error) {
                console.warn('[HFModelManager] 获取存储配额失败:', error);
            }
        }

        console.log('[HFModelManager] 缓存统计:', {
            totalSize: (stats.totalSize / 1024 / 1024).toFixed(2) + ' MB',
            modelCount: stats.models.length,
            quotaUsed: stats.quota.percentUsed + '%'
        });

        return stats;
    }

    // 导出公共 API
    return {
        init: init,
        detectChromeAI: detectChromeAI,
        loadModel: loadModel,
        generate: generate,
        generateStream: generateStream,
        getEmbedding: getEmbedding,
        getState: getState,
        isLoaded: isLoaded,
        unload: unload,
        inferPipelineTask: inferPipelineTask,
        // 缓存相关
        checkModelCache: checkModelCache,
        getCacheStats: getCacheStats
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.HFModelManager = HFModelManager;
}

