/**
 * 模型推理实现
 * WASM 调用 + 完全模型驱动 + MCP 工具调用
 * 所有功能都通过模型调用实现，不使用规则
 */

var ModelInference = (function () {
    'use strict';

    var modelManager = null;
    var wasmBridge = null;

    /**
     * 初始化模型推理引擎
     * @param {Object} options 配置选项
     */
    function init(options) {
        options = options || {};
        
        // 初始化模型管理器
        if (typeof HFModelManager !== 'undefined') {
            modelManager = HFModelManager;
            modelManager.init({
                apiBaseUrl: options.apiBaseUrl || '',
                modelId: options.modelId || null,
                cacheSize: options.cacheSize || 10240,
            });
        }

        // 初始化 WASM 桥接（如果可用）
        if (typeof WasmBridge !== 'undefined') {
            wasmBridge = WasmBridge;
        }

        console.log('[ModelInference] Initialized');
    }

    /**
     * 调用模型生成文本
     * @param {string} prompt 提示词
     * @param {Object} options 选项
     * @returns {Promise<string>} 生成的文本
     */
    async function callModel(prompt, options) {
        options = options || {};

        if (!modelManager) {
            throw new Error('Model manager is not initialized');
        }

        try {
            // 如果模型未加载，先加载
            if (!modelManager.isLoaded()) {
                await modelManager.loadModel();
            }

            // 调用模型生成
            const response = await modelManager.generate(prompt, {
                maxTokens: options.maxTokens || 512,
                temperature: options.temperature || 0.7,
                topP: options.topP || 0.9,
            });

            return response || '';
        } catch (error) {
            console.error('[ModelInference] Model call failed:', error);
            
            // 检查是否是文件不存在的错误
            const errorMsg = error.message || String(error);
            
            // 如果错误信息明确说明文件在仓库中不存在，直接抛出，不尝试下载
            if (errorMsg.includes('文件在模型仓库中不存在') || 
                errorMsg.includes('不支持 ONNX/WebLLM 格式') ||
                errorMsg.includes('WASM 内存限制')) {
                // 这些错误不需要尝试下载，直接抛出
                throw error;
            }
            
            // 其他文件缺失错误，尝试自动下载
            if ((errorMsg.includes('Could not locate file') || 
                 errorMsg.includes('locate file') || 
                 errorMsg.includes('404') ||
                 errorMsg.includes('not found') ||
                 errorMsg.includes('MODEL_FILE_NOT_FOUND') ||
                 errorMsg.includes('模型文件不存在')) && 
                modelManager && typeof modelManager.getState === 'function') {
                const state = modelManager.getState();
                if (state && state.modelId) {
                    console.log('[ModelInference] 检测到文件缺失，尝试自动下载模型:', state.modelId);
                    try {
                        // 触发自动下载
                        if (typeof window !== 'undefined' && typeof window.ensureModelDownloaded === 'function') {
                            await window.ensureModelDownloaded(state.modelId);
                            // 下载完成后，重新尝试加载和调用
                            console.log('[ModelInference] 模型下载完成，重新尝试调用');
                            await modelManager.unload();
                            await modelManager.loadModel();
                            const response = await modelManager.generate(prompt, {
                                maxTokens: options.maxTokens || 512,
                                temperature: options.temperature || 0.7,
                                topP: options.topP || 0.9,
                            });
                            return response || '';
                        }
                    } catch (downloadError) {
                        console.error('[ModelInference] 自动下载失败:', downloadError);
                        // 继续抛出原始错误
                    }
                }
            }
            
            throw error;
        }
    }

    /**
     * 解析模型返回的 JSON
     * @param {string} response 模型响应
     * @returns {Object} 解析后的 JSON 对象
     */
    function parseModelResponse(response) {
        if (!response || typeof response !== 'string') {
            throw new Error('Invalid model response');
        }

        // 尝试提取 JSON（可能包含在 markdown 代码块中）
        let jsonStr = response.trim();

        // 移除 markdown 代码块标记
        if (jsonStr.startsWith('```')) {
            const lines = jsonStr.split('\n');
            lines.shift(); // 移除第一行 ```
            if (lines[lines.length - 1].trim() === '```') {
                lines.pop(); // 移除最后一行 ```
            }
            jsonStr = lines.join('\n').trim();
        }

        // 尝试解析 JSON
        try {
            return JSON.parse(jsonStr);
        } catch (e) {
            // 如果解析失败，尝试提取 JSON 对象
            const jsonMatch = jsonStr.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                return JSON.parse(jsonMatch[0]);
            }
            throw new Error('Failed to parse model response as JSON: ' + e.message);
        }
    }

    /**
     * 分析客户画像（WASM 调用模型）
     * @param {string} profileText 画像文本
     * @returns {Promise<Object>} 分析结果
     */
    async function analyzeProfile(profileText) {
        const prompt = `分析以下客户画像，提取关键特征：

客户画像：
${profileText}

请返回 JSON 格式的分析结果，包含以下字段：
{
  "features": ["特征1", "特征2", ...],
  "keywords": ["关键词1", "关键词2", ...],
  "industry": "行业",
  "region": "地区",
  "description": "描述"
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result;
        } catch (error) {
            console.error('[ModelInference] Profile analysis failed:', error);
            throw error;
        }
    }

    /**
     * 匹配网页内容与画像（WASM 调用模型）
     * @param {string} content 网页内容
     * @param {Object} profile 客户画像
     * @returns {Promise<number>} 匹配分数 (0-100)
     */
    async function matchContentWithProfile(content, profile) {
        const prompt = `分析以下网页内容是否匹配客户画像：

网页内容：
${content.substring(0, 2000)}${content.length > 2000 ? '...' : ''}

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的匹配结果：
{
  "score": 85,
  "reason": "匹配原因",
  "matchedFeatures": ["匹配的特征1", "匹配的特征2"]
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result.score || 0;
        } catch (error) {
            console.error('[ModelInference] Content matching failed:', error);
            return 0;
        }
    }

    /**
     * 从画像生成搜索关键词（WASM 调用模型）
     * @param {Object} profile 客户画像
     * @returns {Promise<Array<string>>} 关键词列表
     */
    async function generateKeywords(profile) {
        const prompt = `根据以下客户画像生成搜索关键词：

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的关键词列表：
{
  "keywords": ["关键词1", "关键词2", ...]
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result.keywords || [];
        } catch (error) {
            console.error('[ModelInference] Keyword generation failed:', error);
            return [];
        }
    }

    /**
     * 计算匹配分数（WASM 调用模型）
     * @param {Object} customer 客户信息
     * @param {Object} profile 客户画像
     * @returns {Promise<number>} 匹配分数 (0-100)
     */
    async function calculateMatchScore(customer, profile) {
        const prompt = `计算以下客户信息与画像的匹配分数：

客户信息：
${JSON.stringify(customer, null, 2)}

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的评分结果：
{
  "score": 75,
  "reasons": ["原因1", "原因2"]
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result.score || 0;
        } catch (error) {
            console.error('[ModelInference] Score calculation failed:', error);
            return 0;
        }
    }

    /**
     * 生成搜索策略（WASM 调用模型）
     * @param {Object} profile 客户画像
     * @returns {Promise<Object>} 搜索策略
     */
    async function generateSearchStrategy(profile) {
        const prompt = `根据以下客户画像生成搜索策略：

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的搜索策略：
{
  "queries": ["查询1", "查询2", ...],
  "engines": ["Google", "Baidu"],
  "depth": 2
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result;
        } catch (error) {
            console.error('[ModelInference] Strategy generation failed:', error);
            return { queries: [], engines: [], depth: 1 };
        }
    }

    /**
     * 从画像反推客户场景（WASM 调用模型）
     * @param {Object} profile 客户画像
     * @returns {Promise<Array<string>>} 场景列表
     */
    async function inferScenes(profile) {
        const prompt = `根据以下客户画像反推客户可能出现的场景：

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的场景列表：
{
  "scenes": ["场景1", "场景2", ...]
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result.scenes || [];
        } catch (error) {
            console.error('[ModelInference] Scene inference failed:', error);
            return [];
        }
    }

    /**
     * 生成 ReAct 决策（Think 阶段）
     * @param {string} prompt 决策提示词
     * @returns {Promise<Object>} 决策对象
     */
    async function generateDecision(prompt) {
        try {
            const response = await callModel(prompt, {
                maxTokens: 512,
                temperature: 0.7,
            });
            const decision = parseModelResponse(response);
            return decision;
        } catch (error) {
            console.error('[ModelInference] Decision generation failed:', error);
            throw error;
        }
    }

    /**
     * 检测输入文本的语言
     * @param {string} text 文本
     * @returns {Promise<string>} 语言代码
     */
    async function detectLanguage(text) {
        const prompt = `检测以下文本的语言：

文本：
${text.substring(0, 500)}

请返回 JSON 格式的语言检测结果：
{
  "language": "zh",
  "confidence": 0.95
}`;

        try {
            const response = await callModel(prompt);
            const result = parseModelResponse(response);
            return result.language || 'auto';
        } catch (error) {
            console.error('[ModelInference] Language detection failed:', error);
            // 如果是文件缺失错误，已经在上层 callModel 中处理了
            return 'auto';
        }
    }

    /**
     * Google 翻译（复用 task-runner.js 中的函数）
     * @param {string} text 文本
     * @param {string} targetLang 目标语言
     * @param {string} sourceLang 源语言
     * @returns {Promise<string>} 翻译后的文本
     */
    async function translateWithGoogle(text, targetLang, sourceLang) {
        // 如果全局有 translateWithGoogle 函数，直接使用
        if (typeof window !== 'undefined' && typeof window.translateWithGoogle === 'function') {
            return await window.translateWithGoogle(text, targetLang, sourceLang);
        }

        // 否则实现基本版本
        sourceLang = sourceLang || 'auto';

        if (!text || typeof text !== 'string' || text.trim() === '') {
            return '';
        }

        try {
            const apiKey = 'AIzaSyATBXajvzQLTDHEQbcpq0Ihe0vWDHmO520';
            const finalTarget = normalizeGoogleLang(targetLang);
            const finalSource = sourceLang === 'auto' ? 'auto' : normalizeGoogleLang(sourceLang);
            const cleanText = text.trim();
            const requestBody = JSON.stringify([[[cleanText], finalSource, finalTarget], 'te_lib']);

            const response = await fetch('https://translate-pa.googleapis.com/v1/translateHtml', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json+protobuf',
                    'x-goog-api-key': apiKey,
                },
                body: requestBody,
            });

            if (!response.ok) {
                return '';
            }

            const data = await response.json();
            if (!Array.isArray(data) || data.length === 0 || !Array.isArray(data[0])) {
                return '';
            }

            const result = data[0];
            let translatedText = result[0] || '';

            if (Array.isArray(translatedText)) {
                translatedText = translatedText[0] || '';
            }

            return String(translatedText);
        } catch (error) {
            console.error('[ModelInference] Google Translate failed:', error);
            return '';
        }
    }

    /**
     * 标准化 Google 语言代码
     */
    function normalizeGoogleLang(lang) {
        const langMap = {
            'zh': 'zh-CN',
            'zh_CN': 'zh-CN',
            'zh_Hans_CN': 'zh-CN',
            'en': 'en',
            'en_US': 'en',
            'ja': 'ja',
            'ja_JP': 'ja',
            'ko': 'ko',
            'ko_KR': 'ko',
        };
        return langMap[lang] || lang;
    }

    /**
     * 模型翻译（降级方案）
     * @param {string} text 文本
     * @param {string} targetLang 目标语言
     * @param {string} sourceLang 源语言
     * @returns {Promise<string>} 翻译后的文本
     */
    async function generateTranslation(text, targetLang, sourceLang) {
        const prompt = `将以下文本从${sourceLang === 'auto' ? '自动检测的语言' : sourceLang}翻译到${targetLang}：

文本：
${text}

请只返回翻译后的文本，不要包含任何其他内容。`;

        try {
            const response = await callModel(prompt, {
                maxTokens: text.length * 2,
                temperature: 0.3, // 降低温度以获得更准确的翻译
            });
            return response.trim();
        } catch (error) {
            console.error('[ModelInference] Model translation failed:', error);
            return text; // 失败时返回原文
        }
    }

    /**
     * 统一翻译接口（优先 Google 翻译，降级模型翻译）
     * @param {string} text 文本
     * @param {string} targetLang 目标语言
     * @param {string} sourceLang 源语言
     * @returns {Promise<string>} 翻译后的文本
     */
    async function translateIfNeeded(text, targetLang, sourceLang) {
        if (!text || typeof text !== 'string' || text.trim() === '') {
            return text;
        }

        sourceLang = sourceLang || 'auto';

        // 如果源语言和目标语言相同，不需要翻译
        if (sourceLang !== 'auto' && sourceLang === targetLang) {
            return text;
        }

        try {
            // 优先尝试 Google 翻译
            const googleTranslated = await translateWithGoogle(text, targetLang, sourceLang);
            if (googleTranslated && googleTranslated.trim() !== '') {
                return googleTranslated;
            }
        } catch (error) {
            console.warn('[ModelInference] Google Translate failed, falling back to model:', error);
        }

        // 降级使用模型翻译
        try {
            const modelTranslated = await generateTranslation(text, targetLang, sourceLang);
            if (modelTranslated && modelTranslated.trim() !== '') {
                return modelTranslated;
            }
        } catch (error) {
            console.warn('[ModelInference] Model translation failed:', error);
        }

        // 都失败时返回原始文本
        return text;
    }

    // 导出公共 API
    return {
        init: init,
        callModel: callModel,
        parseModelResponse: parseModelResponse,
        analyzeProfile: analyzeProfile,
        matchContentWithProfile: matchContentWithProfile,
        generateKeywords: generateKeywords,
        calculateMatchScore: calculateMatchScore,
        generateSearchStrategy: generateSearchStrategy,
        inferScenes: inferScenes,
        generateDecision: generateDecision,
        detectLanguage: detectLanguage,
        translateWithGoogle: translateWithGoogle,
        generateTranslation: generateTranslation,
        translateIfNeeded: translateIfNeeded,
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ModelInference = ModelInference;
}

