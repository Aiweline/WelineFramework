/**
 * AutoLeadAgent 浏览器扩展 - 后台服务
 * 
 * 负责：
 * - 接收网页端的爬取请求
 * - 创建新标签页访问目标网站
 * - 协调内容脚本提取数据
 * - 返回爬取结果
 */

// 初始化日志 - 确认 background script 已加载
console.log('[AutoLeadAgent Extension] Background script 已加载', new Date().toISOString());

// 导入 MCP 工具集和 WASM 桥接层（如果文件存在）
try {
    importScripts('mcp-tools.js');
    console.log('[AutoLeadAgent Extension] MCP tools loaded');
} catch (e) {
    console.warn('[AutoLeadAgent Extension] MCP tools not found:', e);
}

try {
    importScripts('wasm-bridge.js');
    console.log('[AutoLeadAgent Extension] WASM bridge loaded');
} catch (e) {
    console.warn('[AutoLeadAgent Extension] WASM bridge not found:', e);
}

// MCP 连接管理
const mcpConnections = new Map(); // Map<connectionId, {tools, wasmBridge}>
let wasmBridgeInstance = null;

// MCP tools and schema (imported from mcp-tools.js)
// These will be available after importScripts('mcp-tools.js')
// Don't declare here if using importScripts as it will cause SyntaxError: Identifier already declared
// if (typeof MCP_TOOLS_SCHEMA === 'undefined') var MCP_TOOLS_SCHEMA;
// if (typeof mcpTools === 'undefined') var mcpTools;

// 爬取任务队列
const crawlQueue = [];
let isProcessing = false;

// 心跳机制：记录每个任务最后收到心跳的时间
const taskHeartbeats = new Map(); // Map<requestId, lastHeartbeatTime>
const HEARTBEAT_TIMEOUT = 60000; // 60秒超时

// 请求去重机制：防止同一个请求被重复处理
const processingRequests = new Map(); // Map<requestId, startTime>

// 搜索平台打开间隔控制：记录每个搜索引擎上次打开的时间
const engineLastOpenTime = new Map(); // Map<engineName, lastOpenTime>
const SEARCH_ENGINE_INTERVAL = 2000; // 同一搜索引擎打开间隔：2秒（2000毫秒）

// 搜索引擎配置（全球搜索引擎，支持并发搜索）
const SEARCH_ENGINES = [
    {
        name: 'Baidu',
        url: 'https://www.baidu.com/s?wd=',
        resultSelector: '.result', // 百度搜索结果项
        titleSelector: 'h3 a',
        linkSelector: 'h3 a',
        snippetSelector: '.content-right_8Zs40',
        isChinese: true, // 标记为中文搜索引擎
        pingUrl: 'https://www.baidu.com'
    },
    {
        name: 'Google',
        url: 'https://www.google.com/search?q=',
        resultSelector: '.g', // Google 搜索结果项
        titleSelector: 'h3',
        linkSelector: 'a[href^="http"]',
        snippetSelector: '.VwiC3b, .IsZvec',
        isChinese: false,
        pingUrl: 'https://www.google.com'
    },
    {
        name: 'Bing',
        url: 'https://www.bing.com/search?q=',
        resultSelector: '.b_algo', // Bing 搜索结果项
        titleSelector: 'h2 a',
        linkSelector: 'h2 a',
        snippetSelector: '.b_caption p',
        isChinese: false,
        pingUrl: 'https://www.bing.com'
    },
    {
        name: 'DuckDuckGo',
        url: 'https://html.duckduckgo.com/html/?q=',
        resultSelector: '.result', // DuckDuckGo 搜索结果项
        titleSelector: '.result__a',
        linkSelector: '.result__a',
        snippetSelector: '.result__snippet',
        isChinese: false,
        pingUrl: 'https://html.duckduckgo.com'
    },
    {
        name: 'Yandex',
        url: 'https://yandex.com/search/?text=',
        resultSelector: '.serp-item', // Yandex 搜索结果项
        titleSelector: '.organic__url-text',
        linkSelector: '.organic__url',
        snippetSelector: '.text-container',
        isChinese: false,
        pingUrl: 'https://yandex.com'
    },
    {
        name: 'Yahoo',
        url: 'https://search.yahoo.com/search?p=',
        resultSelector: '.dd', // Yahoo 搜索结果项
        titleSelector: 'h3 a',
        linkSelector: 'h3 a',
        snippetSelector: '.compText',
        isChinese: false,
        pingUrl: 'https://search.yahoo.com'
    },
    {
        name: '360搜索',
        url: 'https://www.so.com/s?q=',
        resultSelector: '.res-list', // 360搜索 搜索结果项
        titleSelector: 'h3 a',
        linkSelector: 'h3 a',
        snippetSelector: '.res-rich',
        isChinese: true,
        pingUrl: 'https://www.so.com'
    },
    {
        name: '搜狗',
        url: 'https://www.sogou.com/web?query=',
        resultSelector: '.vrwrap', // 搜狗搜索结果项
        titleSelector: 'h3 a',
        linkSelector: 'h3 a',
        snippetSelector: '.str-text',
        isChinese: true,
        pingUrl: 'https://www.sogou.com'
    },
    // 欧洲搜索引擎
    {
        name: 'Ecosia',
        url: 'https://www.ecosia.org/search?q=',
        resultSelector: '.result', // Ecosia 搜索结果项
        titleSelector: '.result__title a',
        linkSelector: '.result__title a',
        snippetSelector: '.result__snippet',
        isChinese: false,
        pingUrl: 'https://www.ecosia.org'
    },
    {
        name: 'Qwant',
        url: 'https://www.qwant.com/?q=',
        resultSelector: '.result', // Qwant 搜索结果项
        titleSelector: '.web-result-title a',
        linkSelector: '.web-result-title a',
        snippetSelector: '.web-result-description',
        isChinese: false,
        pingUrl: 'https://www.qwant.com'
    },
    {
        name: 'Startpage',
        url: 'https://www.startpage.com/sp/search?query=',
        resultSelector: '.w-gl__result', // Startpage 搜索结果项
        titleSelector: '.w-gl__result-title a',
        linkSelector: '.w-gl__result-title a',
        snippetSelector: '.w-gl__description',
        isChinese: false,
        pingUrl: 'https://www.startpage.com'
    },
    // 亚洲搜索引擎
    {
        name: 'Naver',
        url: 'https://search.naver.com/search.naver?query=',
        resultSelector: '.api_subject_bx', // Naver 搜索结果项
        titleSelector: '.api_txt_lines a',
        linkSelector: '.api_txt_lines a',
        snippetSelector: '.api_txt_lines',
        isChinese: false,
        pingUrl: 'https://www.naver.com'
    },
    {
        name: 'Yahoo Japan',
        url: 'https://search.yahoo.co.jp/search?p=',
        resultSelector: '.sw-Card', // Yahoo Japan 搜索结果项
        titleSelector: '.sw-Card__title a',
        linkSelector: '.sw-Card__title a',
        snippetSelector: '.sw-Card__summary',
        isChinese: false,
        pingUrl: 'https://www.yahoo.co.jp'
    },
    // 其他地区搜索引擎
    {
        name: 'Ask.com',
        url: 'https://www.ask.com/web?q=',
        resultSelector: '.PartialSearchResults-item', // Ask.com 搜索结果项
        titleSelector: '.PartialSearchResults-item-title a',
        linkSelector: '.PartialSearchResults-item-title a',
        snippetSelector: '.PartialSearchResults-item-abstract',
        isChinese: false,
        pingUrl: 'https://www.ask.com'
    },
    {
        name: 'AOL Search',
        url: 'https://search.aol.com/aol/search?q=',
        resultSelector: '.algo', // AOL Search 搜索结果项
        titleSelector: '.algo-title a',
        linkSelector: '.algo-title a',
        snippetSelector: '.algo-abstract',
        isChinese: false,
        pingUrl: 'https://www.aol.com'
    }
];

/**
 * 生成多个搜索查询（多查询策略）
 * @param {Array} keywords - 客户画像关键词
 * @param {Object} profileInfo - 画像信息（行业、地区等）
 * @returns {Array} 搜索查询数组
 */
/**
 * 搜索语法模板引擎
 * 支持占位符替换：{domain}, {keyword1}, {keyword2}, {keyword3}, {industry}, {region}
 */
function renderSearchTemplate(template, replacements) {
    if (!template) return '';

    let result = template;

    // 替换占位符
    Object.keys(replacements).forEach(key => {
        const placeholder = '{' + key + '}';
        const value = replacements[key] || '';
        result = result.replace(new RegExp(placeholder.replace(/[{}]/g, '\\$&'), 'g'), value);
    });

    return result.trim();
}

/**
 * 生成目标网站搜索查询
 * @param {Array} targetWebsites - 目标网站列表（包含target_website_id, domain, search_syntax_template等）
 * @param {Array} keywords - 关键词列表
 * @param {Object} profileInfo - 画像信息
 * @returns {Array} 生成的搜索查询列表
 */
function generateTargetWebsiteQueries(targetWebsites = [], keywords = [], profileInfo = {}) {
    const queries = [];

    if (!targetWebsites || targetWebsites.length === 0) {
        return queries;
    }

    if (!keywords || keywords.length === 0) {
        return queries;
    }

    const seenQueries = new Set();

    const addQuery = (query) => {
        const normalized = query.trim().toLowerCase();
        if (normalized && !seenQueries.has(normalized) && normalized.length > 0) {
            queries.push(query.trim());
            seenQueries.add(normalized);
        }
    };

    // 为每个目标网站生成查询
    targetWebsites.forEach((website, index) => {
        console.log('[AutoLeadAgent Extension] 处理目标网站 ' + (index + 1) + ':', website);

        const template = website.search_syntax_template || `site:${website.domain} "{keyword1}" "{keyword2}"`;
        const domain = website.domain || '';

        console.log('[AutoLeadAgent Extension] 目标网站 ' + (index + 1) + ' 模板:', template);
        console.log('[AutoLeadAgent Extension] 目标网站 ' + (index + 1) + ' 域名:', domain);

        // 准备替换值
        const keyword1 = keywords[0] || '';
        const keyword2 = keywords[1] || '';
        const keyword3 = keywords[2] || '';
        const industry = profileInfo.industry || '';
        const region = profileInfo.region || '';

        console.log('[AutoLeadAgent Extension] 替换值:', {
            keyword1, keyword2, keyword3, industry, region, domain
        });

        // 生成基础查询
        const replacements = {
            domain: domain,
            keyword1: keyword1,
            keyword2: keyword2,
            keyword3: keyword3,
            industry: industry,
            region: region
        };

        const query = renderSearchTemplate(template, replacements);
        console.log('[AutoLeadAgent Extension] 生成的查询:', query);
        if (query && query.trim().length > 0) {
            addQuery(query);
            console.log('[AutoLeadAgent Extension] 已添加查询:', query);
        } else {
            console.warn('[AutoLeadAgent Extension] 查询为空，未添加');
        }

        // 如果有多个关键词，生成组合查询
        if (keywords.length >= 2) {
            const replacements2 = {
                domain: domain,
                keyword1: keywords.slice(0, 2).join(' '),
                keyword2: keywords.length >= 3 ? keywords[2] : keyword1,
                keyword3: keywords.length >= 4 ? keywords[3] : '',
                industry: industry,
                region: region
            };
            const query2 = renderSearchTemplate(template, replacements2);
            if (query2 && query2.trim().length > 0 && query2 !== query) {
                addQuery(query2);
                console.log('[AutoLeadAgent Extension] 已添加组合查询:', query2);
            }
        }
    });

    console.log('[AutoLeadAgent Extension] generateTargetWebsiteQueries 最终生成的查询:', queries);

    return queries;
}

/**
 * 从画像反推客户会出现的场景
 * @param {Object} profileInfo - 画像信息
 * @param {Array} keywords - 画像关键词
 * @param {Object} mapping - 画像到场景的映射规则（从外部传入，不能硬编码）
 * @returns {Array} 场景列表
 */
function inferScenesFromProfile(profileInfo, keywords, mapping) {
    const scenes = [];
    const seenScenes = new Set();

    // 规则映射：基于关键词匹配场景
    keywords.forEach(keyword => {
        Object.keys(mapping).forEach(trait => {
            // 跳过 activityWords 键
            if (trait === 'activityWords') {
                return;
            }

            // 检查关键词是否包含特征词，或特征词是否包含关键词
            if (keyword.indexOf(trait) !== -1 || trait.indexOf(keyword) !== -1) {
                if (mapping[trait] && Array.isArray(mapping[trait])) {
                    mapping[trait].forEach(scene => {
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
    if (profileInfo.industry && profileInfo.industry !== '通用' && profileInfo.industry !== '其他') {
        const industryKey = profileInfo.industry;
        // 检查行业是否在映射中
        if (mapping[industryKey] && Array.isArray(mapping[industryKey])) {
            mapping[industryKey].forEach(scene => {
                if (!seenScenes.has(scene)) {
                    scenes.push(scene);
                    seenScenes.add(scene);
                }
            });
        }
    }

    // AI推理扩展：如果场景太少，尝试使用模型进一步推断
    // 暂时使用关键词组合来扩展场景
    if (scenes.length < 3 && keywords.length > 0) {
        // 基于关键词组合生成通用场景（使用映射中的通用后缀，如果没有则使用默认）
        const communitySuffix = mapping._communitySuffix || '社区'; // 默认中文后缀
        keywords.slice(0, 3).forEach(kw => {
            const genericScene = kw + communitySuffix;
            if (!seenScenes.has(genericScene)) {
                scenes.push(genericScene);
                seenScenes.add(genericScene);
            }
        });
    }

    return scenes;
}

function generateSearchQueries(keywords, profileInfo = {}, sceneMapping = null) {
    const queries = [];

    if (!keywords || keywords.length === 0) {
        return queries;
    }

    const seenQueries = new Set(); // 用于去重

    const addQuery = (query) => {
        const normalized = query.trim().toLowerCase();
        if (normalized && !seenQueries.has(normalized) && normalized.length > 0) {
            queries.push(query.trim());
            seenQueries.add(normalized);
        }
    };

    // 新策略：从画像反推场景，然后生成场景+活动词的搜索查询
    // 映射规则必须从外部传入，不能硬编码
    if (sceneMapping) {
        const scenes = inferScenesFromProfile(profileInfo, keywords, sceneMapping);

        if (scenes.length > 0) {
            // 生成场景+活动词的搜索查询
            const activityWords = sceneMapping.activityWords || ['评论', '发帖', '分享', '讨论', '参与', '活跃', '关注', '点赞', '转发', '互动', '留言', '发布'];
            scenes.forEach(scene => {
                // 为每个场景生成多个活动词组合（限制数量避免查询过多）
                const selectedActivities = activityWords.slice(0, 5); // 每个场景使用前5个活动词
                selectedActivities.forEach(activity => {
                    addQuery(scene + ' ' + activity);
                });
            });

            // 生成场景+角色词的搜索查询（如"时尚杂志 读者"）
            const roleWords = sceneMapping.roleWords || ['读者', '用户', '成员', '粉丝', '关注者', '参与者', '活跃用户', '会员'];
            scenes.forEach(scene => {
                // 为每个场景生成多个角色词组合（限制数量）
                const selectedRoles = roleWords.slice(0, 4); // 每个场景使用前4个角色词
                selectedRoles.forEach(role => {
                    addQuery(scene + ' ' + role);
                });
            });
        }
    } else {
        // 如果没有提供映射规则，使用传统策略
        console.warn('[AutoLeadAgent Extension] 未提供场景映射规则，使用传统搜索策略');
    }

    // 如果场景反推结果不足，使用传统策略作为补充
    if (queries.length < 5) {
        // 策略1：基础查询 - 直接使用关键词
        addQuery(keywords.join(' '));

        // 策略2：关键词重排（不同的顺序可能得到不同结果）
        if (keywords.length > 1) {
            const shuffled = [...keywords].sort(() => Math.random() - 0.5);
            addQuery(shuffled.join(' '));
        }

        // 策略3：行业词 + 地区
        if (profileInfo.industry && profileInfo.industry !== '通用' && profileInfo.industry !== '其他') {
            if (profileInfo.region && profileInfo.region.trim()) {
                addQuery(profileInfo.industry + ' ' + profileInfo.region);
                addQuery(keywords.join(' ') + ' ' + profileInfo.industry + ' ' + profileInfo.region);
            }
            addQuery(keywords.join(' ') + ' ' + profileInfo.industry);
        }

        // 策略4：地区查询
        if (profileInfo.region && profileInfo.region.trim()) {
            addQuery(keywords.join(' ') + ' ' + profileInfo.region);
        }
    }

    return queries;
}

// 监听来自网页的消息
chrome.runtime.onMessageExternal.addListener((request, sender, sendResponse) => {
    console.log('[AutoLeadAgent Extension] Received external message:', request);

    if (request.action === 'ping') {
        // 检测扩展是否已安装
        sendResponse({ success: true, version: chrome.runtime.getManifest().version });
        return true;
    }

    // 处理 HuggingFace 模型下载（来自外部网页）
    if (request.type === 'HF_DOWNLOAD_MODEL') {
        console.log('[AutoLeadAgent Extension] 收到外部 HF_DOWNLOAD_MODEL 请求:', request);
        handleHfDownloadModel(request, sendResponse);
        return true; // 保持消息通道开放以支持异步响应
    }

    // 处理 HuggingFace 登录检测（来自外部网页）
    if (request.type === 'HF_CHECK_LOGIN') {
        console.log('[AutoLeadAgent Extension] 收到外部 HF_CHECK_LOGIN 请求:', request);
        handleHfCheckLogin(request, sendResponse);
        return true; // 保持消息通道开放以支持异步响应
    }

    // 处理 HuggingFace 模型搜索（来自外部网页）
    if (request.type === 'HF_SEARCH_MODELS') {
        console.log('[AutoLeadAgent Extension] 收到外部 HF_SEARCH_MODELS 请求:', request);
        handleHfSearchModels(request, sendResponse);
        return true; // 保持消息通道开放以支持异步响应
    }

    // 处理 HuggingFace 获取模型信息（来自外部网页）
    if (request.type === 'HF_GET_MODEL_INFO') {
        console.log('[AutoLeadAgent Extension] 收到外部 HF_GET_MODEL_INFO 请求:', request);
        handleHfGetModelInfo(request, sendResponse);
        return true; // 保持消息通道开放以支持异步响应
    }

    // 处理 HuggingFace 获取文件大小（来自外部网页）
    if (request.type === 'HF_GET_FILE_SIZE') {
        console.log('[AutoLeadAgent Extension] 收到外部 HF_GET_FILE_SIZE 请求:', request);
        // 添加请求去重机制
        const requestId = request.requestId || 'hf_filesize_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const dedupKey = `hf_filesize_${request.modelId}_${request.filename}`;

        if (processingRequests.has(dedupKey)) {
            const startTime = processingRequests.get(dedupKey);
            const elapsed = Date.now() - startTime;
            console.warn('[AutoLeadAgent Extension] 检测到重复的 HF_GET_FILE_SIZE 请求，已处理时间:', Math.floor(elapsed / 1000) + '秒');
            sendResponse({
                success: false,
                error: '该文件大小正在获取中，请勿重复提交',
                errorType: 'duplicate_request',
                requestId: requestId
            });
            return true;
        }
        processingRequests.set(dedupKey, Date.now());

        // 包装 sendResponse 以确保清理
        const originalSendResponse = sendResponse;
        const wrappedSendResponse = (response) => {
            processingRequests.delete(dedupKey);
            originalSendResponse(response);
        };

        // 处理异步函数，确保错误也被捕获
        handleHfGetFileSize(request, wrappedSendResponse).catch(error => {
            console.error('[AutoLeadAgent Extension] HF_GET_FILE_SIZE 处理异常:', error);
            if (processingRequests.has(dedupKey)) {
                wrappedSendResponse({
                    success: false,
                    error: '获取文件大小失败: ' + (error.message || '未知错误')
                });
            }
        });

        return true; // 保持消息通道开放以支持异步响应
    }

    if (request.action === 'crawl') {
        // 外部消息监听器：只处理来自外部网页的直接消息
        // 如果消息来自内容脚本转发，应该由 onMessage 处理，这里不处理以避免重复
        // 检查消息来源：如果是通过 content script 转发的，不在这里处理
        console.log('[AutoLeadAgent Extension] 收到外部 crawl 请求，但应该由内容脚本转发处理，忽略此请求');
        return false; // 不处理，让 onMessage 处理
    }

    if (request.action === 'getStatus') {
        sendResponse({
            success: true,
            queueLength: crawlQueue.length,
            isProcessing: isProcessing
        });
        return true;
    }

    if (request.action === 'heartbeat') {
        // 心跳信号：更新对应任务的心跳时间
        const requestId = request.requestId || request.taskId;
        if (requestId) {
            taskHeartbeats.set(requestId, Date.now());
            console.log('[AutoLeadAgent Extension] 收到心跳信号，requestId:', requestId);
        }
        sendResponse({ success: true, timestamp: Date.now() });
        return true;
    }

    return false;
});

// 初始化 WASM 桥接层
async function initWasmBridge() {
    try {
        if (typeof WasmBridge !== 'undefined') {
            wasmBridgeInstance = WasmBridge;
            console.log('[AutoLeadAgent Extension] WASM bridge initialized');
        }
    } catch (error) {
        console.warn('[AutoLeadAgent Extension] WASM bridge initialization failed:', error);
    }
}

// 初始化 MCP 工具（在 importScripts 之后调用）
function initMCPTools() {
    try {
        // In Service Worker context, importScripts makes variables available in global scope
        // Check if mcpTools is available (from mcp-tools.js)
        if (typeof mcpTools !== 'undefined' && mcpTools) {
            // Use the mcpTools from mcp-tools.js directly
            console.log('[AutoLeadAgent Extension] MCP tools found in global scope');
        } else if (typeof self !== 'undefined' && self.mcpTools) {
            // Service Worker context - check self
            mcpTools = self.mcpTools;
            MCP_TOOLS_SCHEMA = self.MCP_TOOLS_SCHEMA || {};
            console.log('[AutoLeadAgent Extension] MCP tools found in self scope');
        } else if (typeof window !== 'undefined' && window.mcpTools) {
            // Window context
            mcpTools = window.mcpTools;
            MCP_TOOLS_SCHEMA = window.MCP_TOOLS_SCHEMA || {};
            console.log('[AutoLeadAgent Extension] MCP tools found in window scope');
        } else {
            console.warn('[AutoLeadAgent Extension] mcpTools not found, using empty object');
            mcpTools = {};
            MCP_TOOLS_SCHEMA = {};
        }
        console.log('[AutoLeadAgent Extension] MCP tools initialized, available tools:', Object.keys(mcpTools || {}).length);
    } catch (error) {
        console.warn('[AutoLeadAgent Extension] MCP tools initialization failed:', error);
        mcpTools = {};
        MCP_TOOLS_SCHEMA = {};
    }
}

// 处理 MCP 连接请求
async function handleMCPConnect(request, sendResponse) {
    try {
        const connectionId = 'mcp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const tools = Object.keys(mcpTools || {}).map(toolName => {
            const schema = (MCP_TOOLS_SCHEMA || {})[toolName];
            return schema || { name: toolName };
        });
        mcpConnections.set(connectionId, { tools: tools, wasmBridge: wasmBridgeInstance, createdAt: Date.now() });
        sendResponse({ success: true, connectionId: connectionId, tools: tools });
    } catch (error) {
        console.error('[AutoLeadAgent Extension] MCP connect failed:', error);
        sendResponse({ success: false, error: error.message });
    }
}

// 处理 MCP 工具调用
async function handleMCPCallTool(request, sendResponse) {
    try {
        const { connectionId, tool, arguments: args } = request;
        if (!connectionId || !tool) throw new Error('Connection ID and tool name are required');
        const toolFunc = (mcpTools || {})[tool];
        if (!toolFunc) throw new Error('Tool not found: ' + tool);
        const result = await toolFunc(args || {});
        sendResponse({ success: true, result: result });
    } catch (error) {
        console.error('[AutoLeadAgent Extension] MCP tool call failed:', error);
        sendResponse({ success: false, error: error.message });
    }
}

// 处理 MCP 工具列表请求
function handleMCPListTools(request, sendResponse) {
    try {
        const { connectionId } = request;
        const connection = mcpConnections.get(connectionId);
        if (!connection) throw new Error('Connection not found');
        sendResponse({ success: true, tools: connection.tools });
    } catch (error) {
        console.error('[AutoLeadAgent Extension] MCP list tools failed:', error);
        sendResponse({ success: false, error: error.message });
    }
}

/**
 * 处理 WASM 智能体直接工具调用
 * 不需要 MCP 连接 ID，直接执行工具
 * 
 * 请求格式：
 * {
 *   type: 'WASM_EXECUTE_TOOL',
 *   id: 'tc_xxx',           // 工具调用 ID
 *   name: 'browser_navigate', // 工具名称
 *   arguments: { ... },      // 工具参数
 *   meta: { taskId, iteration, origin }  // 元信息
 * }
 */
/**
 * 处理启动后台 WASM 任务
 */
async function handleWasmStartBackgroundTask(request, sendResponse) {
    const { taskConfig, wasmPath } = request;

    console.log('[AutoLeadAgent Extension] 正在启动后台 WASM 任务:', taskConfig.taskId);

    try {
        if (typeof WasmBridge === 'undefined') {
            throw new Error('WasmBridge not available in background script');
        }

        // 1. 加载 WASM 模块
        await WasmBridge.loadWasmModule(wasmPath);

        // 2. 启动任务
        await WasmBridge.startTaskInWasm(taskConfig, {
            onDecision: async (decision) => {
                console.log('[AutoLeadAgent Extension] 后台 WASM 决策:', decision.type);

                if (decision.type === 'tool_call') {
                    const { name, arguments: args } = decision;
                    try {
                        const toolFunc = (mcpTools || {})[name];
                        if (!toolFunc) throw new Error('Tool not found: ' + name);

                        const result = await toolFunc(args || {});
                        WasmBridge.applyToolResult({
                            name: name,
                            result: result,
                            status: 'success'
                        });
                    } catch (error) {
                        console.error('[AutoLeadAgent Extension] 后台工具调用失败:', name, error);
                        WasmBridge.applyToolResult({
                            name: name,
                            error: error.message,
                            status: 'error'
                        });
                    }
                }
            },
            onStatus: (status) => {
                console.log('[AutoLeadAgent Extension] 后台 WASM 状态:', status.phase);
            },
            onComplete: (result) => {
                console.log('[AutoLeadAgent Extension] 后台 WASM 任务完成:', result);
            },
            onError: (error) => {
                console.error('[AutoLeadAgent Extension] 后台 WASM 任务出错:', error);
            }
        });

        sendResponse({ success: true, message: 'Background task started' });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 启动后台 WASM 任务失败:', error);
        sendResponse({ success: false, error: error.message });
    }
}

async function handleWasmExecuteTool(request, sendResponse) {
    const startTime = Date.now();
    const { id, name, arguments: args, meta } = request;

    console.log('[AutoLeadAgent Extension] WASM 工具调用:', name, 'ID:', id);

    try {
        if (!name) {
            throw new Error('Tool name is required');
        }

        // 获取工具函数
        const toolFunc = (mcpTools || {})[name];
        if (!toolFunc) {
            throw new Error('Tool not found: ' + name);
        }

        // 执行工具
        const result = await toolFunc(args || {});

        const duration = Date.now() - startTime;
        console.log('[AutoLeadAgent Extension] WASM 工具执行完成:', name, '耗时:', duration + 'ms');

        sendResponse({
            success: true,
            id: id,
            name: name,
            result: result,
            meta: {
                ...meta,
                duration: duration
            }
        });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] WASM 工具执行失败:', name, error);

        sendResponse({
            success: false,
            id: id,
            name: name,
            error: {
                code: 'TOOL_EXECUTION_ERROR',
                message: error.message
            },
            meta: meta
        });
    }
}

/**
 * 处理 WASM 批量工具调用
 * 用于一次性执行多个工具（顺序执行）
 * 
 * 请求格式：
 * {
 *   type: 'WASM_EXECUTE_TOOLS_BATCH',
 *   calls: [
 *     { id: 'tc_1', name: 'browser_navigate', arguments: { ... } },
 *     { id: 'tc_2', name: 'browser_snapshot', arguments: { ... } }
 *   ],
 *   meta: { taskId, iteration }
 * }
 */
async function handleWasmExecuteToolsBatch(request, sendResponse) {
    const { calls, meta } = request;
    const results = [];

    console.log('[AutoLeadAgent Extension] WASM 批量工具调用, 数量:', calls.length);

    for (const call of calls) {
        const { id, name, arguments: args } = call;

        try {
            const toolFunc = (mcpTools || {})[name];
            if (!toolFunc) {
                results.push({
                    success: false,
                    id: id,
                    name: name,
                    error: { code: 'TOOL_NOT_FOUND', message: 'Tool not found: ' + name }
                });
                continue;
            }

            const result = await toolFunc(args || {});
            results.push({
                success: true,
                id: id,
                name: name,
                result: result
            });

        } catch (error) {
            results.push({
                success: false,
                id: id,
                name: name,
                error: { code: 'TOOL_EXECUTION_ERROR', message: error.message }
            });
        }
    }

    console.log('[AutoLeadAgent Extension] WASM 批量工具调用完成, 成功:',
        results.filter(r => r.success).length, '/', results.length);

    sendResponse({
        success: true,
        results: results,
        meta: meta
    });
}

// 初始化
initWasmBridge();
initMCPTools();

// ==================== Port 连接处理（用于长时间操作，如下载） ====================

// 监听 Port 连接建立
chrome.runtime.onConnect.addListener((port) => {
    console.log('[AutoLeadAgent Extension] 收到 Port 连接请求:', port.name);

    if (port.name === 'hf-download') {
        let currentModelId = null;

        // 监听 Port 消息
        port.onMessage.addListener((request) => {
            console.log('[AutoLeadAgent Extension] 收到 Port 消息:', request);

            if (request.type === 'start-download') {
                const { modelId } = request;
                if (!modelId) {
                    port.postMessage({
                        type: 'download-error',
                        error: '模型ID不能为空'
                    });
                    return;
                }

                currentModelId = modelId;
                console.log('[AutoLeadAgent Extension] 通过 Port 开始下载:', modelId);

                // 保存 Port 连接
                downloadPorts.set(modelId, port);

                // 先检查登录状态
                checkLoginAndStartDownload(modelId, port).catch(error => {
                    console.error('[AutoLeadAgent Extension] Port 下载失败:', error);
                    port.postMessage({
                        type: 'download-error',
                        modelId: modelId,
                        error: error.message || '下载失败'
                    });
                    downloadPorts.delete(modelId);
                });

            } else if (request.type === 'cancel-download') {
                const { modelId } = request;
                console.log('[AutoLeadAgent Extension] 取消下载:', modelId);

                // 清理 Port 连接
                downloadPorts.delete(modelId);

                // 发送取消确认
                port.postMessage({
                    type: 'download-cancelled',
                    modelId: modelId
                });

                // 断开连接
                port.disconnect();

            } else if (request.type === 'check-status') {
                const { modelId } = request;
                const portExists = downloadPorts.has(modelId);
                port.postMessage({
                    type: 'download-status',
                    modelId: modelId,
                    isDownloading: portExists
                });
            }
        });

        // 处理 Port 断开
        port.onDisconnect.addListener(() => {
            console.log('[AutoLeadAgent Extension] Port 连接断开:', currentModelId);

            if (currentModelId) {
                downloadPorts.delete(currentModelId);
            }

            // 清理所有相关连接
            for (const [modelId, p] of downloadPorts.entries()) {
                if (p === port) {
                    downloadPorts.delete(modelId);
                    break;
                }
            }
        });
    }
});

/**
 * 检查登录状态并开始下载（用于 Port 连接）
 */
async function checkLoginAndStartDownload(modelId, port) {
    // 1. 检查登录状态
    const loginCheck = await fetch('https://huggingface.co/api/whoami-v2', {
        method: 'GET',
        credentials: 'include',
        headers: {
            'Accept': 'application/json'
        }
    });

    if (!loginCheck.ok) {
        // 未登录，打开登录页面
        console.log('[AutoLeadAgent Extension] 未登录，打开登录页面');
        const loginTab = await chrome.tabs.create({
            url: 'https://huggingface.co/login',
            active: true
        });

        // 开始监听登录页面的登录状态
        startLoginDetection(loginTab.id, modelId);

        port.postMessage({
            type: 'download-need-login',
            modelId: modelId,
            loginTabId: loginTab.id,
            message: '需要登录 HuggingFace，已打开登录页面'
        });

        return; // 等待登录成功后再继续
    }

    // 2. 已登录，开始下载模型
    console.log('[AutoLeadAgent Extension] 已登录，通过 Port 开始下载模型');

    // 发送下载开始消息
    port.postMessage({
        type: 'download-started',
        modelId: modelId,
        message: '下载已开始'
    });

    // 执行下载
    await downloadHfModelViaPort(modelId, port);
}

// 监听来自内容脚本的消息
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    console.log('[AutoLeadAgent Extension] ===== 收到消息 =====');
    console.log('[AutoLeadAgent Extension] Received message from content:', request);
    console.log('[AutoLeadAgent Extension] Message action:', request.action);
    console.log('[AutoLeadAgent Extension] Sender:', sender);
    console.log('[AutoLeadAgent Extension] Timestamp:', new Date().toISOString());

    // 处理 MCP 相关消息
    if (request.type === 'MCP_CONNECT') {
        handleMCPConnect(request, sendResponse);
        return true;
    }
    if (request.type === 'MCP_CALL_TOOL') {
        handleMCPCallTool(request, sendResponse);
        return true;
    }
    if (request.type === 'MCP_LIST_TOOLS') {
        handleMCPListTools(request, sendResponse);
        return true;
    }
    if (request.type === 'MCP_PING') {
        sendResponse({ success: true, message: 'MCP is available' });
        return false;
    }
    if (request.type === 'MCP_DISCONNECT') {
        const { connectionId } = request;
        if (connectionId) mcpConnections.delete(connectionId);
        sendResponse({ success: true });
        return false;
    }

    // 处理 WASM 智能体直接工具调用（不需要连接 ID）
    if (request.type === 'WASM_EXECUTE_TOOL') {
        handleWasmExecuteTool(request, sendResponse);
        return true;
    }

    // 处理启动后台 WASM 任务
    if (request.type === 'WASM_START_BACKGROUND_TASK') {
        handleWasmStartBackgroundTask(request, sendResponse);
        return true;
    }

    // 处理 WASM 批量工具调用
    if (request.type === 'WASM_EXECUTE_TOOLS_BATCH') {
        handleWasmExecuteToolsBatch(request, sendResponse);
        return true;
    }

    // 处理 HuggingFace 模型下载
    if (request.type === 'HF_DOWNLOAD_MODEL') {
        handleHfDownloadModel(request, sendResponse);
        return true;
    }

    // 处理 HuggingFace 登录检测
    if (request.type === 'HF_CHECK_LOGIN') {
        handleHfCheckLogin(request, sendResponse);
        return true;
    }

    // 处理 HuggingFace 模型搜索
    if (request.type === 'HF_SEARCH_MODELS') {
        // 添加请求去重机制
        const requestId = request.requestId || 'hf_search_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        if (processingRequests.has(requestId)) {
            const startTime = processingRequests.get(requestId);
            const elapsed = Date.now() - startTime;
            console.warn('[AutoLeadAgent Extension] 检测到重复的 HF_SEARCH_MODELS 请求，requestId:', requestId, '已处理时间:', Math.floor(elapsed / 1000) + '秒');
            sendResponse({
                success: false,
                error: '该请求正在处理中，请勿重复提交',
                errorType: 'duplicate_request',
                requestId: requestId,
                processingSince: startTime
            });
            return true;
        }
        processingRequests.set(requestId, Date.now());

        // 包装 sendResponse 以确保清理和错误处理
        let hasResponded = false;
        const originalSendResponse = sendResponse;
        const wrappedSendResponse = (response) => {
            if (hasResponded) {
                console.warn('[AutoLeadAgent Extension] 尝试重复发送 HF_SEARCH_MODELS 响应，已忽略');
                return;
            }
            hasResponded = true;
            processingRequests.delete(requestId);
            try {
                originalSendResponse(response);
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 发送 HF_SEARCH_MODELS 响应失败:', e);
            }
        };

        // 处理异步函数，确保错误也被捕获
        handleHfSearchModels(request, wrappedSendResponse).catch(error => {
            console.error('[AutoLeadAgent Extension] HF_SEARCH_MODELS 处理异常:', error);
            if (!hasResponded) {
                wrappedSendResponse({
                    success: false,
                    error: '搜索模型失败: ' + (error.message || '未知错误')
                });
            }
        });

        return true; // 保持消息通道开放以支持异步响应
    }

    // 处理 HuggingFace 获取模型信息
    if (request.type === 'HF_GET_MODEL_INFO') {
        // 添加请求去重机制（基于 modelId + requestId）
        const modelId = request.modelId || '';
        const requestId = request.requestId || 'hf_info_' + modelId + '_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const dedupKey = modelId ? `hf_info_${modelId}` : requestId;

        if (processingRequests.has(dedupKey)) {
            const startTime = processingRequests.get(dedupKey);
            const elapsed = Date.now() - startTime;
            console.warn('[AutoLeadAgent Extension] 检测到重复的 HF_GET_MODEL_INFO 请求，modelId:', modelId, '已处理时间:', Math.floor(elapsed / 1000) + '秒');
            sendResponse({
                success: false,
                error: '该模型信息正在获取中，请勿重复提交',
                errorType: 'duplicate_request',
                requestId: requestId,
                modelId: modelId,
                processingSince: startTime
            });
            return true;
        }
        processingRequests.set(dedupKey, Date.now());
        // 包装 sendResponse 以确保清理
        const originalSendResponse = sendResponse;
        const wrappedSendResponse = (response) => {
            processingRequests.delete(dedupKey);
            originalSendResponse(response);
        };
        handleHfGetModelInfo(request, wrappedSendResponse);
        return true;
    }

    // 处理 HuggingFace 获取文件大小（来自 content script）
    if (request.type === 'HF_GET_FILE_SIZE') {
        console.log('[AutoLeadAgent Extension] 收到 HF_GET_FILE_SIZE 请求（来自 content script）:', request);
        // 添加请求去重机制
        const requestId = request.requestId || 'hf_filesize_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const dedupKey = `hf_filesize_${request.modelId}_${request.filename}`;

        if (processingRequests.has(dedupKey)) {
            const startTime = processingRequests.get(dedupKey);
            const elapsed = Date.now() - startTime;
            console.warn('[AutoLeadAgent Extension] 检测到重复的 HF_GET_FILE_SIZE 请求，已处理时间:', Math.floor(elapsed / 1000) + '秒');
            sendResponse({
                success: false,
                error: '该文件大小正在获取中，请勿重复提交',
                errorType: 'duplicate_request',
                requestId: requestId
            });
            return true;
        }
        processingRequests.set(dedupKey, Date.now());

        // 包装 sendResponse 以确保清理
        const originalSendResponse = sendResponse;
        const wrappedSendResponse = (response) => {
            processingRequests.delete(dedupKey);
            originalSendResponse(response);
        };

        // 处理异步函数，确保错误也被捕获
        handleHfGetFileSize(request, wrappedSendResponse).catch(error => {
            console.error('[AutoLeadAgent Extension] HF_GET_FILE_SIZE 处理异常:', error);
            if (processingRequests.has(dedupKey)) {
                wrappedSendResponse({
                    success: false,
                    error: '获取文件大小失败: ' + (error.message || '未知错误')
                });
            }
        });

        return true; // 保持消息通道开放以支持异步响应
    }

    if (request.action === 'extractedData') {
        // 处理内容脚本提取的数据
        handleExtractedData(request.data, request.taskId);
        sendResponse({ success: true });
        return true;
    }

    // 处理来自内容脚本的爬取请求（内容脚本转发自网页）
    if (request.action === 'ping') {
        // 检测扩展是否已安装
        console.log('[AutoLeadAgent Extension] Handling ping request');
        sendResponse({ success: true, version: chrome.runtime.getManifest().version });
        return true;
    }

    if (request.action === 'crawl') {
        // 获取或生成requestId用于去重
        const requestId = request.requestId || request.taskId || 'unknown_' + Date.now();

        // 检查是否已经在处理相同的请求
        if (processingRequests.has(requestId)) {
            const startTime = processingRequests.get(requestId);
            const elapsed = Date.now() - startTime;
            console.warn('[AutoLeadAgent Extension] 检测到重复的 crawl 请求（来自内容脚本），requestId:', requestId, '已处理时间:', Math.floor(elapsed / 1000) + '秒');
            // 返回提示信息，但不重复处理
            sendResponse({
                success: false,
                error: '该请求正在处理中，请勿重复提交',
                errorType: 'duplicate_request',
                requestId: requestId,
                processingSince: startTime
            });
            return true;
        }

        console.log('[AutoLeadAgent Extension] ===== 开始处理 crawl 请求 =====');
        console.log('[AutoLeadAgent Extension] Handling crawl request, starting handleCrawlRequest...');

        // 立即发送确认消息到前端
        try {
            sendLogToFrontend('crawl', '✅ Background script 已收到 crawl 请求');
            sendLogToFrontend('crawl', '📥 请求ID: ' + requestId);
        } catch (e) {
            console.error('[AutoLeadAgent Extension] 发送确认日志失败:', e);
        }

        // 标记请求为处理中
        processingRequests.set(requestId, Date.now());

        // 添加爬取任务（异步处理，保持消息通道开放）
        handleCrawlRequest(request, sendResponse).catch(error => {
            console.error('[AutoLeadAgent Extension] 爬取任务异常:', error);
            console.error('[AutoLeadAgent Extension] 错误堆栈:', error.stack);
            // 清理处理标记
            processingRequests.delete(requestId);
            // 发送错误日志到前端
            sendLogToFrontend('crawl', '❌ 爬取任务异常: ' + (error.message || '未知错误'));
            // 如果还没有响应，发送错误响应
            try {
                sendResponse({
                    success: false,
                    error: '爬取任务异常: ' + (error.message || '未知错误'),
                    errorType: 'unhandled_exception',
                    errorStack: error.stack
                });
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 无法发送错误响应:', e);
            }
        }).finally(() => {
            // 任务完成后清理处理标记（延迟清理，避免立即重复）
            setTimeout(() => {
                processingRequests.delete(requestId);
            }, 1000);
        });
        return true; // 保持消息通道开放
    }

    if (request.action === 'getStatus') {
        sendResponse({
            success: true,
            queueLength: crawlQueue.length,
            isProcessing: isProcessing
        });
        return true;
    }

    if (request.action === 'heartbeat') {
        // 心跳信号：更新对应任务的心跳时间
        const requestId = request.requestId || request.taskId;
        if (requestId) {
            taskHeartbeats.set(requestId, Date.now());
            console.log('[AutoLeadAgent Extension] 收到心跳信号，requestId:', requestId);
        }
        sendResponse({ success: true, timestamp: Date.now() });
        return true;
    }

    console.warn('[AutoLeadAgent Extension] Unknown action:', request.action);
    return false;
});

/**
 * Ping URL检测连通性
 * @param {string} url - 要ping的URL
 * @param {number} timeout - 超时时间（毫秒），默认5000
 * @returns {Promise<{success: boolean, latency: number, error?: string}>}
 */
async function pingUrl(url, timeout = 5000) {
    const startTime = performance.now();

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const response = await fetch(url, {
            method: 'HEAD',
            mode: 'no-cors', // 避免CORS问题
            signal: controller.signal,
            cache: 'no-cache'
        });

        clearTimeout(timeoutId);
        const latency = performance.now() - startTime;

        // no-cors模式下无法读取响应状态，但能发起请求就说明网络连通
        return {
            success: true,
            latency: Math.round(latency)
        };
    } catch (error) {
        const latency = performance.now() - startTime;

        if (error.name === 'AbortError') {
            return {
                success: false,
                latency: Math.round(latency),
                error: '请求超时'
            };
        }

        return {
            success: false,
            latency: Math.round(latency),
            error: error.message || '网络错误'
        };
    }
}

// 存储需要发送日志的标签页ID（通常是任务管理页面）
let logTargetTabId = null;

// 日志去重机制：记录已发送的日志（基于内容+时间戳）
const sentLogs = new Map(); // Map<logKey, timestamp>
const LOG_DEDUP_WINDOW = 100; // 100ms窗口内相同日志只发送一次

/**
 * 生成日志去重键
 */
function getLogKey(type, message) {
    return `${type}:${message}`;
}

/**
 * 检查日志是否在去重窗口内
 */
function isLogDuplicate(type, message) {
    const logKey = getLogKey(type, message);
    const now = Date.now();
    const lastSentTime = sentLogs.get(logKey);

    if (lastSentTime && (now - lastSentTime) < LOG_DEDUP_WINDOW) {
        return true; // 在去重窗口内，跳过
    }

    // 更新发送时间
    sentLogs.set(logKey, now);

    // 清理过期的日志记录（超过1分钟的记录）
    const oneMinuteAgo = now - 60000;
    for (const [key, timestamp] of sentLogs.entries()) {
        if (timestamp < oneMinuteAgo) {
            sentLogs.delete(key);
        }
    }

    return false;
}

/**
 * 查找并缓存目标标签页ID
 */
async function findTargetTabId() {
    // 如果已缓存且有效，直接返回
    if (logTargetTabId) {
        try {
            await chrome.tabs.get(logTargetTabId);
            return logTargetTabId;
        } catch (e) {
            // 标签页已关闭，清除缓存
            logTargetTabId = null;
        }
    }

    // 查找任务管理页面
    const tabs = await chrome.tabs.query({});
    if (!tabs || tabs.length === 0) {
        return null;
    }

    // 优先查找任务管理页面（包含 auto-lead-agent 路径的页面）
    const taskManagementTabs = tabs.filter(tab => {
        // 跳过扩展自己的页面
        if (tab.url && tab.url.startsWith('chrome-extension://')) {
            return false;
        }
        // 只发送到任务管理页面（包含 auto-lead-agent 路径）
        if (tab.url && (
            tab.url.includes('/auto-lead-agent') ||
            tab.url.includes('/autoleadagent') ||
            tab.url.includes('auto_lead_agent')
        )) {
            return true;
        }
        return false;
    });

    if (taskManagementTabs.length > 0) {
        logTargetTabId = taskManagementTabs[0].id;
        return logTargetTabId;
    }

    // 如果没有找到任务管理页面，尝试发送到第一个非搜索结果页面
    const searchEngines = [
        'google.com/search',
        'baidu.com/s',
        'bing.com/search',
        'yahoo.com/search',
        'duckduckgo.com'
    ];

    const nonSearchTabs = tabs.filter(tab => {
        if (tab.url && tab.url.startsWith('chrome-extension://')) {
            return false;
        }
        return !searchEngines.some(engine => tab.url && tab.url.includes(engine));
    });

    if (nonSearchTabs.length > 0) {
        logTargetTabId = nonSearchTabs[0].id;
        return logTargetTabId;
    }

    return null;
}

/**
 * 发送日志到前端
 * 只发送到任务管理页面，避免重复日志
 */
function sendLogToFrontend(type, message) {
    try {
        // 同时输出到控制台（用于调试）
        console.log('[AutoLeadAgent Extension Log]', message);

        // 检查是否重复（去重机制）
        if (isLogDuplicate(type, message)) {
            return; // 跳过重复日志
        }

        // 异步查找目标标签页并发送日志
        findTargetTabId().then(targetTabId => {
            if (!targetTabId) {
                // 没有找到目标标签页，静默失败（不输出警告，避免日志污染）
                return;
            }

            // 只发送到单一目标标签页
            chrome.tabs.sendMessage(targetTabId, {
                type: 'AUTOLEADAGENT_LOG',
                logType: type,
                message: message,
                timestamp: new Date().toISOString()
            }).catch((error) => {
                // 发送失败时清除缓存的标签页ID，下次重新查找
                if (error.message && error.message.includes('Could not establish connection')) {
                    logTargetTabId = null;
                }
                // 静默处理错误，避免日志污染
            });
        }).catch((error) => {
            // 查找标签页失败，静默处理
            console.warn('[AutoLeadAgent Extension] 查找目标标签页失败:', error);
        });
    } catch (error) {
        // 日志发送失败不影响主流程，但记录错误
        console.error('[AutoLeadAgent Extension] 发送日志异常:', error);
    }
}

/**
 * 处理爬取请求
 * 分步骤搜索策略：
 * 1. 先ping百度和目标平台，确保网络连通
 * 2. 使用百度搜索（国内搜索引擎，对中文关键词更友好）
 * 3. 验证每个客户是否包含有效信息（邮箱/手机/社媒账户至少一个）
 */
/**
 * 在单个搜索引擎中搜索，只提取URL列表（不关闭标签页，用于分页）
 */
/**
 * 获取模型API URL
 * 通过查找任务管理页面（包含 auto-lead-agent 的页面）来获取API基础URL
 */
async function getModelApiUrl() {
    console.log('[AutoLeadAgent Extension] 开始获取模型API URL...');
    console.log('[AutoLeadAgent Extension] logTargetTabId:', logTargetTabId);

    // 首先尝试从 logTargetTabId 获取
    if (logTargetTabId) {
        try {
            const targetTab = await chrome.tabs.get(logTargetTabId);
            console.log('[AutoLeadAgent Extension] 从logTargetTabId获取标签页:', targetTab?.url);
            if (targetTab && targetTab.url) {
                const urlObj = new URL(targetTab.url);
                const pathParts = urlObj.pathname.split('/').filter(p => p);
                const autoLeadIndex = pathParts.indexOf('auto-lead-agent');
                if (autoLeadIndex >= 0) {
                    const basePath = '/' + pathParts.slice(0, autoLeadIndex + 1).join('/');
                    const apiUrl = urlObj.origin + basePath + '/ai/api/v1/chat/completions';
                    console.log('[AutoLeadAgent Extension] 从logTargetTabId获取API URL:', apiUrl);
                    return apiUrl;
                } else {
                    const apiUrl = urlObj.origin + '/ai/api/v1/chat/completions';
                    console.log('[AutoLeadAgent Extension] 从logTargetTabId使用默认API路径:', apiUrl);
                    return apiUrl;
                }
            }
        } catch (e) {
            console.warn('[AutoLeadAgent Extension] 无法从logTargetTabId获取URL:', e);
        }
    }

    // 如果无法从 logTargetTabId 获取，查询所有标签页查找任务管理页面
    try {
        const tabs = await chrome.tabs.query({});
        console.log('[AutoLeadAgent Extension] 查询到', tabs?.length || 0, '个标签页');

        if (tabs && tabs.length > 0) {
            // 打印所有标签页URL用于调试
            console.log('[AutoLeadAgent Extension] 所有标签页URL:');
            tabs.forEach((tab, index) => {
                if (tab.url) {
                    console.log(`  [${index}] ${tab.url}`);
                }
            });

            // 查找包含 auto-lead-agent 的页面（使用更灵活的匹配）
            const taskManagementTab = tabs.find(tab => {
                if (!tab.url || tab.url.startsWith('chrome-extension://')) {
                    return false;
                }
                // 更灵活的匹配：不区分大小写，支持多种格式
                const urlLower = tab.url.toLowerCase();
                return urlLower.includes('auto-lead-agent') ||
                    urlLower.includes('autoleadagent') ||
                    urlLower.includes('auto_lead_agent') ||
                    urlLower.includes('autolead-agent');
            });

            if (taskManagementTab && taskManagementTab.url) {
                console.log('[AutoLeadAgent Extension] 找到任务管理页面:', taskManagementTab.url);
                const urlObj = new URL(taskManagementTab.url);

                // 方法1: 从路径部分提取
                const pathParts = urlObj.pathname.split('/').filter(p => p);
                const autoLeadIndex = pathParts.indexOf('auto-lead-agent');
                if (autoLeadIndex >= 0) {
                    const basePath = '/' + pathParts.slice(0, autoLeadIndex + 1).join('/');
                    const apiUrl = urlObj.origin + basePath + '/ai/api/v1/chat/completions';
                    console.log('[AutoLeadAgent Extension] 从任务管理页面获取API URL (方法1):', apiUrl);
                    return apiUrl;
                }

                // 方法2: 使用正则表达式从完整URL中提取
                // 匹配格式: http://domain/.../auto-lead-agent/... 或 http://domain/.../auto-lead-agent
                const urlLower = taskManagementTab.url.toLowerCase();
                if (urlLower.includes('auto-lead-agent')) {
                    // 提取到 auto-lead-agent 及其之前的所有路径
                    // 支持格式: /path/to/auto-lead-agent 或 /path/to/auto-lead-agent/backend/index
                    const match = taskManagementTab.url.match(/(.*\/auto-lead-agent[^\/]*)/i);
                    if (match && match[1]) {
                        // 确保路径以 / 开头
                        let basePath = match[1];
                        if (!basePath.startsWith('/')) {
                            basePath = '/' + basePath;
                        }
                        // 如果basePath已经是完整URL，直接使用
                        if (basePath.startsWith('http://') || basePath.startsWith('https://')) {
                            const apiUrl = basePath + '/ai/api/v1/chat/completions';
                            console.log('[AutoLeadAgent Extension] 从URL匹配获取API URL (方法2-完整URL):', apiUrl);
                            return apiUrl;
                        } else {
                            const apiUrl = urlObj.origin + basePath + '/ai/api/v1/chat/completions';
                            console.log('[AutoLeadAgent Extension] 从URL匹配获取API URL (方法2):', apiUrl);
                            return apiUrl;
                        }
                    }
                }

                // 方法3: 使用默认路径
                const apiUrl = urlObj.origin + '/ai/api/v1/chat/completions';
                console.log('[AutoLeadAgent Extension] 使用默认API路径:', apiUrl);
                return apiUrl;
            } else {
                console.warn('[AutoLeadAgent Extension] 未找到任务管理页面（包含 auto-lead-agent 的标签页）');
                console.warn('[AutoLeadAgent Extension] 请确保任务管理页面已打开，且URL包含 "auto-lead-agent"');
            }
        }
    } catch (e) {
        console.error('[AutoLeadAgent Extension] 查询标签页失败:', e);
    }

    // 如果都失败了，返回null
    console.error('[AutoLeadAgent Extension] 无法确定模型API URL，所有方法都失败');
    return null;
}

async function searchInEngine(engine, query, maxResults, tabId = null, openedTabs = null) {
    const searchUrl = engine.url + encodeURIComponent(query) + (engine.name === 'Baidu' ? '&rn=' + maxResults : '');

    const logMsg = '在' + engine.name + '中搜索: ' + query;
    console.log('[AutoLeadAgent Extension]', logMsg);
    sendLogToFrontend('crawl', '🔍 ' + logMsg);
    sendLogToFrontend('crawl', '  📍 搜索URL: ' + searchUrl);
    sendLogToFrontend('crawl', '  📝 查询内容: ' + query);
    sendLogToFrontend('crawl', '  🔢 最大结果数: ' + maxResults);

    // 检查搜索平台打开间隔：如果距离上次打开同一搜索引擎的时间太短，等待一段时间
    const lastOpenTime = engineLastOpenTime.get(engine.name);
    if (lastOpenTime) {
        const timeSinceLastOpen = Date.now() - lastOpenTime;
        if (timeSinceLastOpen < SEARCH_ENGINE_INTERVAL) {
            const waitTime = SEARCH_ENGINE_INTERVAL - timeSinceLastOpen;
            const waitSeconds = (waitTime / 1000).toFixed(1);
            sendLogToFrontend('crawl', '  ⏳ 等待 ' + waitSeconds + ' 秒后打开 ' + engine.name + '（控制访问间隔）...');
            await sleep(waitTime);
            sendLogToFrontend('crawl', '  ✓ 等待完成，开始打开 ' + engine.name);
        }
    }
    // 更新最后打开时间
    engineLastOpenTime.set(engine.name, Date.now());

    let tab;
    let isNewTab = false;

    // 如果提供了tabId，使用现有标签页；否则创建新标签页
    if (tabId) {
        try {
            tab = await chrome.tabs.get(tabId);
            // 导航到新的搜索URL
            await chrome.tabs.update(tabId, { url: searchUrl });
            sendLogToFrontend('crawl', '  → 使用现有标签页 (ID: ' + tabId + ')，导航到新搜索');
            sendLogToFrontend('crawl', '  → 导航URL: ' + searchUrl);
            // 如果提供了openedTabs，添加到跟踪列表
            if (openedTabs && !openedTabs.has(tabId)) {
                openedTabs.add(tabId);
            }
        } catch (e) {
            // 标签页不存在，创建新的
            tab = await chrome.tabs.create({ url: searchUrl, active: false });
            isNewTab = true;
            sendLogToFrontend('crawl', '  → 创建新搜索标签页 (ID: ' + tab.id + ')');
            sendLogToFrontend('crawl', '  → 标签页URL: ' + searchUrl);
            // 添加到跟踪列表
            if (openedTabs) {
                openedTabs.add(tab.id);
            }
        }
    } else {
        tab = await chrome.tabs.create({ url: searchUrl, active: false });
        isNewTab = true;
        sendLogToFrontend('crawl', '  → 创建搜索标签页 (ID: ' + tab.id + ')');
        sendLogToFrontend('crawl', '  → 标签页URL: ' + searchUrl);
        // 添加到跟踪列表
        if (openedTabs) {
            openedTabs.add(tab.id);
        }
    }

    try {
        // 等待页面加载
        sendLogToFrontend('crawl', '  → 等待' + engine.name + '页面加载...');
        await waitForTabLoad(tab.id, 30000);
        sendLogToFrontend('crawl', '  ✓ ' + engine.name + '页面加载完成');

        // 增加等待时间并添加动态检测页面加载完成的逻辑
        sendLogToFrontend('crawl', '  → 等待5秒让页面完全加载（包括动态内容）...');

        // 动态检测页面是否完全加载（检查DOM是否稳定）
        try {
            await chrome.scripting.executeScript({
                target: { tabId: tab.id },
                func: function () {
                    return new Promise((resolve) => {
                        let lastHeight = document.body.scrollHeight;
                        let stableCount = 0;
                        const checkInterval = setInterval(() => {
                            const currentHeight = document.body.scrollHeight;
                            if (currentHeight === lastHeight) {
                                stableCount++;
                                if (stableCount >= 3) {
                                    // DOM高度稳定3次检查（约1.5秒），认为加载完成
                                    clearInterval(checkInterval);
                                    resolve();
                                }
                            } else {
                                stableCount = 0;
                                lastHeight = currentHeight;
                            }
                        }, 500);

                        // 最多等待5秒
                        setTimeout(() => {
                            clearInterval(checkInterval);
                            resolve();
                        }, 5000);
                    });
                }
            });
        } catch (e) {
            // 如果动态检测失败，使用固定等待时间
            await sleep(5000);
        }

        sendLogToFrontend('crawl', '  ✓ 等待完成');

        // 执行内容脚本提取URL列表（直接使用端侧模型获取结果）
        sendLogToFrontend('crawl', '  → 使用端侧模型从' + engine.name + '提取搜索结果URL...');
        console.log('[AutoLeadAgent Extension] 准备执行 extractSearchEngineResults，tabId:', tab.id);

        let results;
        try {
            results = await chrome.scripting.executeScript({
                target: { tabId: tab.id },
                func: extractSearchEngineResults,
                args: [engine, null, maxResults, null, []]
            });
            console.log('[AutoLeadAgent Extension] executeScript 执行完成，results:', results);
        } catch (scriptError) {
            console.error('[AutoLeadAgent Extension] executeScript 执行失败:', scriptError);
            console.error('[AutoLeadAgent Extension] 错误堆栈:', scriptError.stack);
            sendLogToFrontend('crawl', '  ❌ 提取URL失败: ' + scriptError.message);
            throw scriptError; // 重新抛出错误以便调试
        }

        if (!results || results.length === 0) {
            console.error('[AutoLeadAgent Extension] executeScript 返回空结果');
            sendLogToFrontend('crawl', '  ❌ 提取URL失败: 脚本返回空结果');
            throw new Error('executeScript 返回空结果');
        }

        let urlList = results[0]?.result || [];
        console.log('[AutoLeadAgent Extension] 提取到的URL列表:', urlList);
        console.log('[AutoLeadAgent Extension] URL列表长度:', urlList.length);
        console.log('[AutoLeadAgent Extension] URL列表详情:', JSON.stringify(urlList, null, 2));

        // 如果标准方法提取失败，使用模型推理提取
        if (urlList.length === 0) {
            console.log('[AutoLeadAgent Extension] 标准方法提取失败，尝试使用模型推理提取...');
            sendLogToFrontend('crawl', '  → 标准方法未提取到结果，使用模型推理提取...');

            try {
                // 获取页面HTML
                const htmlResult = await chrome.scripting.executeScript({
                    target: { tabId: tab.id },
                    func: () => {
                        return {
                            html: document.documentElement.outerHTML,
                            url: window.location.href,
                            title: document.title
                        };
                    }
                });

                const pageData = htmlResult[0]?.result || {};
                const pageHtml = pageData.html || '';
                const pageUrl = pageData.url || '';

                console.log('[AutoLeadAgent Extension] 获取到页面HTML，长度:', pageHtml.length);
                console.log('[AutoLeadAgent Extension] 页面URL:', pageUrl);
                console.log('[AutoLeadAgent Extension] 页面标题:', pageData.title);

                // 打印HTML的前5000个字符用于调试
                console.log('[AutoLeadAgent Extension] ===== HTML内容（前5000字符） =====');
                console.log(pageHtml.substring(0, 5000));
                console.log('[AutoLeadAgent Extension] ===== HTML内容结束 =====');

                // 也打印HTML的后1000个字符
                if (pageHtml.length > 5000) {
                    console.log('[AutoLeadAgent Extension] ===== HTML内容（后1000字符） =====');
                    console.log(pageHtml.substring(pageHtml.length - 1000));
                    console.log('[AutoLeadAgent Extension] ===== HTML内容结束 =====');
                }

                // 调用后端API使用模型提取URL
                sendLogToFrontend('crawl', '  → 调用模型API提取搜索结果URL...');

                // 获取模型API URL
                const modelApiUrl = await getModelApiUrl();
                if (!modelApiUrl) {
                    throw new Error('无法确定模型API URL，请确保在任务管理页面中运行');
                }

                console.log('[AutoLeadAgent Extension] 模型API URL:', modelApiUrl);

                // 构建提示词
                const prompt = `请从以下Google搜索结果页面的HTML中提取所有搜索结果项的URL。

要求：
1. 只提取搜索结果项的URL，不要提取Google自己的链接（如google.com/search、google.com/url等）
2. 提取的URL应该是外部网站的链接
3. 返回JSON格式，包含url、title、snippet字段
4. 最多提取${maxResults}个结果

HTML内容：
${pageHtml.substring(0, 50000)}${pageHtml.length > 50000 ? '...(已截断)' : ''}

请返回JSON数组格式，例如：
[
  {"url": "https://example.com/page", "title": "页面标题", "snippet": "页面摘要"},
  ...
]`;

                // 调用模型API（兼容 /ai/api/v1/chat/completions 接口）
                const modelResponse = await fetch(modelApiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        model: 'gpt-4o-mini', // 默认模型代码（后端会做映射）
                        messages: [
                            {
                                role: 'system',
                                content: '你是一个擅长解析搜索引擎结果页面HTML的助手，负责从HTML中提取搜索结果项的URL、标题和摘要。只返回JSON数组，不要返回多余说明。'
                            },
                            {
                                role: 'user',
                                content: prompt
                            }
                        ],
                        temperature: 0.3,
                        max_tokens: 2000
                    })
                });

                if (!modelResponse.ok) {
                    throw new Error(`模型API调用失败: ${modelResponse.status} ${modelResponse.statusText}`);
                }

                const modelData = await modelResponse.json();
                console.log('[AutoLeadAgent Extension] 模型API完整响应:', JSON.stringify(modelData, null, 2));

                // 解析模型返回的结果（兼容 OpenAI 风格）
                let modelContent = '';
                if (modelData && Array.isArray(modelData.choices) && modelData.choices.length > 0 &&
                    modelData.choices[0].message && typeof modelData.choices[0].message.content === 'string') {
                    modelContent = modelData.choices[0].message.content;
                } else if (modelData && modelData.content) {
                    modelContent = modelData.content;
                } else if (modelData && modelData.data && modelData.data.content) {
                    modelContent = modelData.data.content;
                } else if (modelData && modelData.response) {
                    modelContent = modelData.response;
                }

                console.log('[AutoLeadAgent Extension] 模型返回的原始内容:', modelContent);

                if (modelContent) {
                    try {
                        // 尝试从内容中提取JSON（可能包含markdown代码块）
                        let jsonStr = modelContent.trim();

                        // 移除可能的markdown代码块标记
                        if (jsonStr.startsWith('```json')) {
                            jsonStr = jsonStr.replace(/^```json\s*/, '').replace(/\s*```$/, '');
                        } else if (jsonStr.startsWith('```')) {
                            jsonStr = jsonStr.replace(/^```\s*/, '').replace(/\s*```$/, '');
                        }

                        // 尝试提取JSON数组（可能在文本中）
                        const jsonMatch = jsonStr.match(/\[[\s\S]*\]/);
                        if (jsonMatch) {
                            jsonStr = jsonMatch[0];
                        }

                        // 解析JSON
                        const extractedUrls = JSON.parse(jsonStr);
                        if (Array.isArray(extractedUrls) && extractedUrls.length > 0) {
                            urlList = extractedUrls;
                            console.log('[AutoLeadAgent Extension] 模型提取成功，找到', urlList.length, '个URL');
                            sendLogToFrontend('crawl', '  ✓ 模型提取成功，找到 ' + urlList.length + ' 个结果URL');

                            // 显示提取到的URL列表
                            urlList.slice(0, 5).forEach((item, idx) => {
                                console.log(`[AutoLeadAgent Extension] URL ${idx + 1}:`, item.url, item.title || '无标题');
                            });
                        } else {
                            console.warn('[AutoLeadAgent Extension] 模型返回的结果不是有效数组或为空');
                            console.warn('[AutoLeadAgent Extension] 解析后的数据:', extractedUrls);
                        }
                    } catch (parseError) {
                        console.error('[AutoLeadAgent Extension] 解析模型返回结果失败:', parseError);
                        console.error('[AutoLeadAgent Extension] 模型返回内容:', modelContent);
                        console.error('[AutoLeadAgent Extension] 错误堆栈:', parseError.stack);
                    }
                } else {
                    console.warn('[AutoLeadAgent Extension] 模型响应中没有找到内容字段');
                    console.warn('[AutoLeadAgent Extension] 完整响应:', JSON.stringify(modelData, null, 2));
                }

            } catch (modelError) {
                console.error('[AutoLeadAgent Extension] 模型推理提取失败:', modelError);
                sendLogToFrontend('crawl', '  ⚠️ 模型推理提取失败: ' + modelError.message);
                // 继续执行，返回空结果
            }
        }

        if (urlList.length === 0) {
            // 直接停止任务
            return { urls: [], tabId: null, error: { errorType: 'no_results', errorMessage: '未找到任何结果' } };
        }

        // 检查是否有调试信息（备用方法执行情况）
        if (urlList.length === 0 && results[0]?.result) {
            // 检查浏览器控制台的日志（这些日志在注入脚本中，无法直接获取）
            // 但我们可以通过检查页面来获取更多信息
            try {
                const debugInfo = await chrome.scripting.executeScript({
                    target: { tabId: tab.id },
                    func: () => {
                        const allLinks = document.querySelectorAll('a[href]');
                        let validLinksCount = 0;
                        let facebookLinksCount = 0;

                        allLinks.forEach(link => {
                            let href = link.href || link.getAttribute('href') || '';
                            if (href.includes('/url?q=')) {
                                try {
                                    const urlParams = new URLSearchParams(href.split('?')[1]);
                                    href = urlParams.get('q') || href;
                                } catch (e) { }
                            }
                            if (href && href.startsWith('http') &&
                                !href.includes('google.com/search') &&
                                !href.includes('google.com/url') &&
                                !href.includes('google.com/maps')) {
                                validLinksCount++;
                                if (href.includes('facebook.com')) {
                                    facebookLinksCount++;
                                }
                            }
                        });

                        return {
                            allLinksCount: allLinks.length,
                            validLinksCount: validLinksCount,
                            facebookLinksCount: facebookLinksCount
                        };
                    }
                });

                const info = debugInfo[0]?.result || {};
                if (info.validLinksCount > 0) {
                    sendLogToFrontend('crawl', '  → 调试信息：页面有 ' + info.validLinksCount + ' 个有效外部链接，其中 ' + info.facebookLinksCount + ' 个Facebook链接');
                    sendLogToFrontend('crawl', '  → 备用方法应该能找到结果，请检查浏览器控制台查看详细日志');
                }
            } catch (e) {
                console.warn('[AutoLeadAgent Extension] 获取调试信息失败:', e);
            }
        }

        // 检查是否有错误（被屏蔽等情况）
        if (urlList.length > 0 && urlList[0].error) {
            const errorInfo = urlList[0];
            console.error('[AutoLeadAgent Extension] 搜索引擎屏蔽检测:', JSON.stringify(errorInfo, null, 2));
            sendLogToFrontend('crawl', '  ⚠️ ' + errorInfo.errorMessage);
            if (errorInfo.suggestion) {
                sendLogToFrontend('crawl', '  💡 ' + errorInfo.suggestion);
            }

            // 如果是验证码错误，尝试等待验证码完成（在 background.js 中处理）
            if (errorInfo.errorType === 'blocked_by_search_engine' && errorInfo.errorMessage.includes('验证码')) {
                sendLogToFrontend('crawl', '  ⏳ 检测到验证码，等待30秒看是否自动完成...');
                // 等待30秒，每2秒检查一次验证码是否消失
                const maxWaitTime = 30000;
                const startWait = Date.now();
                let captchaResolved = false;

                while (Date.now() - startWait < maxWaitTime && !captchaResolved) {
                    await sleep(2000); // 每2秒检查一次

                    try {
                        // 重新执行脚本检查验证码是否还存在
                        const checkResult = await chrome.scripting.executeScript({
                            target: { tabId: tab.id },
                            func: function () {
                                const captchaSelectors = [
                                    '#captcha', '.captcha', '[id*="captcha"]', '[class*="captcha"]',
                                    '#verify', '.verify', '[id*="verify"]', '[class*="verify"]',
                                    '#security-check', '.security-check',
                                    'iframe[src*="captcha"]', 'iframe[src*="verify"]'
                                ];
                                for (const selector of captchaSelectors) {
                                    try {
                                        if (document.querySelector(selector)) {
                                            return true; // 验证码还存在
                                        }
                                    } catch (e) {
                                        // 忽略选择器错误
                                    }
                                }
                                return false; // 验证码已消失
                            }
                        });

                        const stillHasCaptcha = checkResult[0]?.result || false;
                        if (!stillHasCaptcha) {
                            captchaResolved = true;
                            sendLogToFrontend('crawl', '  ✓ 验证码已自动完成或消失，重新提取搜索结果...');

                            // 等待页面稳定
                            await sleep(2000);

                            // 重新提取搜索结果
                            const retryResults = await chrome.scripting.executeScript({
                                target: { tabId: tab.id },
                                func: extractSearchEngineResults,
                                args: [engine, null, maxResults, null, []]
                            });

                            const retryUrlList = retryResults[0]?.result || [];
                            console.log('[AutoLeadAgent Extension] 重新提取到的URL列表:', retryUrlList);
                            if (retryUrlList.length > 0 && !retryUrlList[0].error) {
                                // 成功提取到结果
                                urlList = retryUrlList;
                                sendLogToFrontend('crawl', '  ✓ 重新提取成功，找到 ' + urlList.length + ' 个结果');
                                break; // 跳出等待循环，继续处理
                            } else {
                                sendLogToFrontend('crawl', '  ⚠️ 重新提取后仍未找到结果，可能页面结构变化');
                            }
                        }
                    } catch (e) {
                        console.warn('[AutoLeadAgent Extension] 检查验证码状态失败:', e);
                    }
                }

                if (!captchaResolved || urlList.length === 0) {
                    sendLogToFrontend('crawl', '  ⚠️ 等待30秒后验证码仍未完成或未找到结果，跳过此次搜索');
                    // 关闭标签页
                    if (isNewTab) {
                        try {
                            await chrome.tabs.remove(tab.id);
                            if (openedTabs) {
                                openedTabs.delete(tab.id);
                            }
                        } catch (e) {
                            if (openedTabs) {
                                openedTabs.delete(tab.id);
                            }
                        }
                    }
                    // 返回错误信息
                    return { urls: [], tabId: null, error: errorInfo };
                }
            } else {
                // 非验证码错误，关闭标签页并返回
                if (isNewTab) {
                    try {
                        await chrome.tabs.remove(tab.id);
                        if (openedTabs) {
                            openedTabs.delete(tab.id);
                        }
                    } catch (e) {
                        if (openedTabs) {
                            openedTabs.delete(tab.id);
                        }
                    }
                }
                // 返回错误信息
                return { urls: [], tabId: null, error: errorInfo };
            }
        }

        // 检查是否有广告被过滤的日志信息（从结果中提取）
        let adCount = 0;
        let totalProcessed = urlList.length;
        if (urlList.length > 0 && urlList[0].adCount !== undefined) {
            adCount = urlList[0].adCount;
            totalProcessed = urlList[0].totalProcessed || urlList.length;
            // 移除adCount和totalProcessed属性，只保留URL列表
            delete urlList[0].adCount;
            delete urlList[0].totalProcessed;
        }

        const resultMsg = '从' + engine.name + '提取到 ' + urlList.length + ' 个结果URL';
        sendLogToFrontend('crawl', '  ✓ ' + resultMsg);
        if (adCount > 0) {
            sendLogToFrontend('crawl', '  🚫 已过滤 ' + adCount + ' 个广告结果（共处理 ' + totalProcessed + ' 个结果）');
        }

        // 显示每个结果的详细信息
        if (urlList.length > 0) {
            sendLogToFrontend('crawl', '  📋 提取的结果列表:');
            urlList.slice(0, 10).forEach((resultItem, idx) => {
                sendLogToFrontend('crawl', '     ' + (idx + 1) + '. ' + (resultItem.title || '(无标题)'));
                sendLogToFrontend('crawl', '        URL: ' + resultItem.url);
                if (resultItem.snippet) {
                    const snippetPreview = resultItem.snippet.substring(0, 80) + (resultItem.snippet.length > 80 ? '...' : '');
                    sendLogToFrontend('crawl', '        摘要: ' + snippetPreview);
                }
            });
            if (urlList.length > 10) {
                sendLogToFrontend('crawl', '     ... 还有 ' + (urlList.length - 10) + ' 个结果');
            }
        }

        // 如果结果为空，检查是否是验证码页面
        if (urlList.length === 0) {
            sendLogToFrontend('crawl', '  ⚠️ 未找到搜索结果，检查是否是人机验证页面...');

            // 检查是否是验证码页面
            const captchaCheckResult = await chrome.scripting.executeScript({
                target: { tabId: tab.id },
                func: function () {
                    const pageText = document.body ? document.body.innerText || document.body.textContent : '';
                    const pageTitle = document.title || '';

                    // 检测验证码关键词
                    const captchaKeywords = [
                        '验证码', 'captcha', 'verify', '验证', '人机验证',
                        '安全验证', 'security check', '安全检查',
                        'robot', 'bot', '自动化检测',
                        'unusual traffic', '异常流量', 'suspicious activity'
                    ];

                    const hasCaptchaKeyword = captchaKeywords.some(keyword => {
                        const lowerText = pageText.toLowerCase();
                        const lowerTitle = pageTitle.toLowerCase();
                        return lowerText.includes(keyword.toLowerCase()) || lowerTitle.includes(keyword.toLowerCase());
                    });

                    // 检测验证码元素
                    const captchaSelectors = [
                        '#captcha', '.captcha', '[id*="captcha"]', '[class*="captcha"]',
                        '#verify', '.verify', '[id*="verify"]', '[class*="verify"]',
                        '#security-check', '.security-check',
                        'iframe[src*="captcha"]', 'iframe[src*="verify"]',
                        'iframe[src*="recaptcha"]', 'iframe[src*="hcaptcha"]',
                        '[class*="recaptcha"]', '[class*="hcaptcha"]'
                    ];

                    let hasCaptchaElement = false;
                    for (const selector of captchaSelectors) {
                        try {
                            if (document.querySelector(selector)) {
                                hasCaptchaElement = true;
                                break;
                            }
                        } catch (e) {
                            // 忽略选择器错误
                        }
                    }

                    return {
                        isCaptcha: hasCaptchaKeyword || hasCaptchaElement,
                        hasCaptchaKeyword: hasCaptchaKeyword,
                        hasCaptchaElement: hasCaptchaElement,
                        pageTitle: pageTitle,
                        pageText: pageText.substring(0, 500) // 只返回前500字符
                    };
                }
            });

            const captchaInfo = captchaCheckResult[0]?.result || {};
            if (captchaInfo.isCaptcha) {
                sendLogToFrontend('crawl', '  🔍 检测到人机验证页面，使用模型分析验证码按钮...');

                // 尝试自动处理验证码
                const captchaHandled = await handleCaptchaAutomatically(tab.id, engine, sendLogToFrontend);

                if (captchaHandled) {
                    sendLogToFrontend('crawl', '  ✓ 验证码处理完成，重新提取搜索结果...');
                    // 等待页面稳定
                    await sleep(3000);

                    // 重新提取搜索结果
                    const retryResults = await chrome.scripting.executeScript({
                        target: { tabId: tab.id },
                        func: extractSearchEngineResults,
                        args: [engine, null, maxResults, null, []]
                    });

                    const retryUrlList = retryResults[0]?.result || [];
                    if (retryUrlList.length > 0 && !retryUrlList[0].error) {
                        // 成功提取到结果
                        urlList = retryUrlList;
                        sendLogToFrontend('crawl', '  ✓ 重新提取成功，找到 ' + urlList.length + ' 个结果');
                    } else {
                        sendLogToFrontend('crawl', '  ⚠️ 重新提取后仍未找到结果');
                    }
                } else {
                    sendLogToFrontend('crawl', '  ⚠️ 无法自动处理验证码，可能需要手动处理');
                }
            }
        }

        // 返回URL列表和标签页ID（不关闭标签页，用于分页）
        return { urls: urlList, tabId: tab.id, error: null };
    } catch (error) {
        console.error('[AutoLeadAgent Extension] 搜索异常:', engine.name, error);
        sendLogToFrontend('crawl', '  ❌ ' + engine.name + ' 搜索异常: ' + error.message);
        // 如果创建了新标签页，关闭它
        if (isNewTab) {
            try {
                await chrome.tabs.remove(tab.id);
                if (openedTabs) {
                    openedTabs.delete(tab.id); // 从跟踪列表移除
                }
            } catch (e) {
                // 忽略关闭错误
                if (openedTabs) {
                    openedTabs.delete(tab.id); // 即使关闭失败，也从跟踪列表移除
                }
            }
        }
        return { urls: [], tabId: null, error: { message: error.message } };
    }
}

async function handleCrawlRequest(request, sendResponse) {
    const startTime = Date.now();

    // 获取或生成requestId
    const requestId = request.requestId || request.taskId || 'unknown_' + Date.now();

    // 跟踪所有打开的标签页，用于统一清理
    const openedTabs = new Set(); // Set<tabId>
    const cleanupAllTabs = async () => {
        if (openedTabs.size > 0) {
            console.log('[AutoLeadAgent Extension] 开始清理 ' + openedTabs.size + ' 个标签页...');
            sendLogToFrontend('crawl', '🧹 开始清理 ' + openedTabs.size + ' 个打开的标签页...');
            for (const tabId of openedTabs) {
                try {
                    await chrome.tabs.remove(tabId);
                    sendLogToFrontend('crawl', '  ✓ 已关闭标签页 (ID: ' + tabId + ')');
                } catch (e) {
                    console.warn('[AutoLeadAgent Extension] 关闭标签页失败 (ID: ' + tabId + '):', e.message);
                }
            }
            openedTabs.clear();
            sendLogToFrontend('crawl', '✓ 所有标签页已清理完成');
        }
    };

    console.log('[AutoLeadAgent Extension] ===== handleCrawlRequest 开始执行 =====');
    console.log('[AutoLeadAgent Extension] 执行时间:', new Date().toISOString());
    console.log('[AutoLeadAgent Extension] 请求参数:', {
        searchQueries: request.searchQueries?.length || 0,
        searchEngines: request.searchEngines || [],
        requestId: requestId
    });

    // 初始化心跳时间（记录任务开始时间）
    taskHeartbeats.set(requestId, Date.now());

    // 检查心跳是否超时的函数
    const checkHeartbeat = () => {
        const lastHeartbeat = taskHeartbeats.get(requestId);
        if (!lastHeartbeat) {
            // 如果没有记录，说明任务刚启动，返回false（不停止）
            return false;
        }
        const timeSinceLastHeartbeat = Date.now() - lastHeartbeat;
        if (timeSinceLastHeartbeat >= HEARTBEAT_TIMEOUT) {
            const seconds = Math.floor(timeSinceLastHeartbeat / 1000);
            console.warn('[AutoLeadAgent Extension] 心跳超时:', seconds + '秒未收到外部信号');
            sendLogToFrontend('crawl', '  ⚠️ 心跳超时：' + seconds + '秒未收到外部信号，停止搜索');
            return true; // 需要停止
        }
        return false; // 不需要停止
    };

    // 清理函数：任务结束时移除心跳记录
    const cleanupHeartbeat = () => {
        taskHeartbeats.delete(requestId);
    };

    // 标记响应是否已发送，避免重复响应
    let hasResponded = false;
    const safeSendResponse = (response) => {
        if (!hasResponded) {
            hasResponded = true;
            const duration = Date.now() - startTime;
            try {
                console.log('[AutoLeadAgent Extension] 发送响应（耗时: ' + Math.floor(duration / 1000) + '秒）:', {
                    success: response.success,
                    count: response.count || 0,
                    error: response.error || null
                });
                sendResponse(response);
            } catch (error) {
                console.error('[AutoLeadAgent Extension] 发送响应失败:', error);
                console.error('[AutoLeadAgent Extension] 错误堆栈:', error.stack);
                // 尝试通过日志发送错误信息
                sendLogToFrontend('crawl', '❌ 发送响应失败: ' + error.message);
            }
        } else {
            console.warn('[AutoLeadAgent Extension] 尝试重复发送响应，已忽略');
        }
    };

    try {
        // 新的请求格式：searchQueries, searchEngines, profileInfo
        const {
            searchQueries,
            searchEngines = ['Baidu'],
            keywords,
            originalKeywords,
            profileInfo = {},
            searchLanguage = null,  // 搜索语言
            targetRegion = null,    // 目标地区
            taskId,
            maxResults = 10,
            sceneMapping = null     // 场景映射规则（从外部传入，不能硬编码）
        } = request;

        // 记录当前使用的语言和地区
        if (searchLanguage || targetRegion) {
            let logMsg = '📋 当前搜索参数:';
            if (targetRegion) {
                logMsg += ' 地区=' + targetRegion;
            }
            if (searchLanguage) {
                logMsg += ' 语言=' + searchLanguage;
            }
            if (searchEngines && searchEngines.length > 0) {
                logMsg += ' 搜索引擎=' + searchEngines.join(', ');
            }
            sendLogToFrontend('crawl', logMsg);
        }

        console.log('[AutoLeadAgent Extension] Crawl request 参数:', {
            searchQueries,
            searchEngines,
            keywords,
            originalKeywords,
            profileInfo,
            searchLanguage,
            targetRegion,
            taskId,
            maxResults
        });

        // 立即发送开始日志（多次尝试确保发送成功）
        console.log('[AutoLeadAgent Extension] 发送开始日志...');
        console.log('[AutoLeadAgent Extension] 请求详情:', {
            searchQueriesCount: searchQueries ? searchQueries.length : 0,
            searchEnginesCount: searchEngines ? searchEngines.length : 0,
            searchEngines: searchEngines || [],
            maxResults: maxResults
        });
        sendLogToFrontend('crawl', '🚀 扩展开始处理搜索请求...');
        sendLogToFrontend('crawl', '📋 请求参数: ' + (searchQueries ? searchQueries.length : 0) + ' 个查询, ' + (searchEngines ? searchEngines.length : 0) + ' 个搜索引擎');

        // 延迟再次发送，确保日志到达
        setTimeout(() => {
            sendLogToFrontend('crawl', '✅ Background script 正在处理中...');
        }, 100);

        console.log('[AutoLeadAgent Extension] 开始日志已发送');

        // ========== 步骤0: Ping检查所有搜索引擎 ==========
        const logMsg0 = '【步骤0】开始ping检查所有搜索引擎...';
        console.log('[AutoLeadAgent Extension]', logMsg0);
        sendLogToFrontend('crawl', logMsg0);

        // 确定要使用的搜索引擎
        let enginesToUse = searchEngines.map(name =>
            SEARCH_ENGINES.find(e => e.name === name)
        ).filter(e => e);

        if (enginesToUse.length === 0) {
            // 默认使用百度
            enginesToUse = [SEARCH_ENGINES.find(e => e.name === 'Baidu')];
        }

        // 对所有要使用的搜索引擎进行ping检查
        const pingResults = {};
        const availableEngines = [];

        for (const engine of enginesToUse) {
            const urlToPing = engine.pingUrl || engine.url.split('?')[0] || engine.url;
            const logMsgPing = 'Ping ' + engine.name + ': ' + urlToPing;
            console.log('[AutoLeadAgent Extension]', logMsgPing);
            sendLogToFrontend('crawl', logMsgPing);

            const pingResult = await pingUrl(urlToPing, 5000);
            pingResults[engine.name] = pingResult;

            if (pingResult.success) {
                const logMsgSuccess = engine.name + ' ping成功，延迟: ' + pingResult.latency + 'ms';
                console.log('[AutoLeadAgent Extension]', logMsgSuccess);
                sendLogToFrontend('crawl', logMsgSuccess);
                availableEngines.push(engine);
            } else {
                const logMsgFail = engine.name + ' ping失败: ' + (pingResult.error || '未知错误');
                console.warn('[AutoLeadAgent Extension]', logMsgFail);
                sendLogToFrontend('crawl', '⚠️ ' + logMsgFail);
            }
        }

        // 如果没有任何搜索引擎可用，返回错误
        if (availableEngines.length === 0) {
            const logMsgError = '所有搜索引擎ping失败';
            console.error('[AutoLeadAgent Extension]', logMsgError);
            sendLogToFrontend('crawl', '❌ ' + logMsgError);
            safeSendResponse({
                success: false,
                error: '所有搜索引擎网络连通性检查失败，无法进行搜索',
                pingResults: pingResults
            });
            return;
        }

        const logMsg0Complete = '【步骤0完成】' + availableEngines.length + '个搜索引擎可用: ' + availableEngines.map(e => e.name).join(', ');
        console.log('[AutoLeadAgent Extension]', logMsg0Complete);
        sendLogToFrontend('crawl', logMsg0Complete);

        // 更新要使用的搜索引擎列表为可用的搜索引擎
        enginesToUse = availableEngines;

        // ========== 步骤1: 生成搜索查询 ==========
        let queries = searchQueries || [];
        // 优先使用 keywords，如果没有则使用 originalKeywords
        let keywordsToUse = (keywords && Array.isArray(keywords) && keywords.length > 0) ? keywords : (originalKeywords || []);
        let targetWebsites = request.targetWebsites || [];

        // 调试日志：显示接收到的数据
        console.log('[AutoLeadAgent Extension] 接收到的数据:', {
            searchQueries: queries,
            keywords: keywords,
            originalKeywords: originalKeywords,
            keywordsToUse: keywordsToUse,
            targetWebsites: targetWebsites,
            targetWebsitesLength: targetWebsites.length,
            profileInfo: profileInfo
        });
        sendLogToFrontend('crawl', '【调试】接收到的数据:');
        sendLogToFrontend('crawl', '  普通查询数量: ' + queries.length);
        sendLogToFrontend('crawl', '  keywords参数: ' + (keywords && Array.isArray(keywords) ? keywords.join(', ') : '无或非数组'));
        sendLogToFrontend('crawl', '  originalKeywords参数: ' + (originalKeywords && Array.isArray(originalKeywords) ? originalKeywords.join(', ') : '无或非数组'));
        sendLogToFrontend('crawl', '  最终使用的关键词数量: ' + keywordsToUse.length + ' (' + keywordsToUse.join(', ') + ')');
        sendLogToFrontend('crawl', '  目标网站数量: ' + targetWebsites.length);
        if (targetWebsites.length > 0) {
            targetWebsites.forEach((w, i) => {
                sendLogToFrontend('crawl', '    目标网站' + (i + 1) + ': ' + (w.name || w.domain || '未知') + ' (模板: ' + (w.search_syntax_template || '默认') + ')');
            });
        }

        // 如果有目标网站，优先生成目标网站搜索查询
        if (targetWebsites.length > 0) {
            if (keywordsToUse.length > 0) {
                const targetQueries = generateTargetWebsiteQueries(targetWebsites, keywordsToUse, profileInfo);
                console.log('[AutoLeadAgent Extension] 生成的目标网站查询:', targetQueries);
                if (targetQueries.length > 0) {
                    // 如果有目标网站查询，优先使用，清空普通查询
                    queries = targetQueries; // 只使用目标网站查询，不使用普通查询
                    sendLogToFrontend('crawl', '【目标网站查询】为 ' + targetWebsites.length + ' 个目标网站生成了 ' + targetQueries.length + ' 个精准搜索查询');
                    sendLogToFrontend('crawl', '🎯 将使用目标网站精准搜索语法（已禁用普通查询）');
                    targetQueries.forEach((q, i) => {
                        sendLogToFrontend('crawl', '  精准查询' + (i + 1) + ': ' + q);
                    });
                } else {
                    sendLogToFrontend('crawl', '❌ 目标网站查询生成失败（关键词: ' + keywordsToUse.join(', ') + '），将使用普通查询');
                    sendLogToFrontend('crawl', '❌ 请检查目标网站的search_syntax_template配置是否正确');
                    // 如果目标网站查询生成失败，使用普通查询
                    if (queries.length === 0 && keywordsToUse.length > 0) {
                        queries = generateSearchQueries(keywordsToUse, profileInfo, sceneMapping);
                    }
                }
            } else {
                sendLogToFrontend('crawl', '❌ 检测到目标网站配置，但关键词为空，无法生成精准查询');
                sendLogToFrontend('crawl', '❌ 将使用普通查询（如果有）');
            }
        } else {
            // 如果没有目标网站，使用普通查询
            sendLogToFrontend('crawl', '⚠️ 未检测到目标网站配置，使用普通搜索查询');
            if (queries.length === 0 && keywordsToUse.length > 0) {
                queries = generateSearchQueries(keywordsToUse, profileInfo);
            }
        }

        const logMsg1 = '【步骤1】生成' + queries.length + '个搜索查询';
        console.log('[AutoLeadAgent Extension]', logMsg1);
        sendLogToFrontend('crawl', logMsg1);
        queries.forEach((q, i) => {
            const logMsg = '  查询' + (i + 1) + ': ' + q;
            console.log('[AutoLeadAgent Extension]', logMsg);
            sendLogToFrontend('crawl', logMsg);
        });

        // ========== 步骤2: 多搜索引擎并发搜索 ==========
        // enginesToUse 已经在步骤0中确定（只包含ping成功的搜索引擎）
        let extractedData = [];
        let allSourceUrls = [];
        let lastError = null;
        let blockedEngines = []; // 记录被屏蔽的搜索引擎

        const logMsg2 = '【步骤2】使用' + enginesToUse.length + '个搜索引擎并发搜索: ' + enginesToUse.map(e => e.name).join(', ');
        console.log('[AutoLeadAgent Extension]', logMsg2);
        sendLogToFrontend('crawl', logMsg2);

        // 并发限制：最多同时10个标签页（避免浏览器资源耗尽）
        const MAX_CONCURRENT_TABS = 10;

        // 时间限制：5分钟内找不到有效客户就停止
        const MAX_SEARCH_TIME = 5 * 60 * 1000; // 5分钟（毫秒）
        const searchStartTime = Date.now(); // 记录搜索开始时间
        let lastValidCustomerTime = searchStartTime; // 记录最后一个有效客户找到的时间

        // 检查是否找到有效客户的函数
        const isValidCustomer = (customer) => {
            return (customer.email && customer.email.trim()) ||
                (customer.phone && customer.phone.trim()) ||
                (customer.socialMediaAccounts && Object.keys(customer.socialMediaAccounts).length > 0);
        };

        // 检查是否应该停止搜索的函数
        const shouldStopSearch = () => {
            const now = Date.now();
            const timeSinceLastValid = now - lastValidCustomerTime;

            if (timeSinceLastValid >= MAX_SEARCH_TIME) {
                const minutes = Math.floor(timeSinceLastValid / 60000);
                const seconds = Math.floor((timeSinceLastValid % 60000) / 1000);
                sendLogToFrontend('crawl', `  ⚠️ 已超过 ${minutes}分${seconds}秒未找到有效客户，停止搜索`);
                return true;
            }
            return false;
        };

        // 对每个查询，在所有搜索引擎中搜索并深度爬取
        for (let queryIndex = 0; queryIndex < queries.length; queryIndex++) {
            // 检查心跳是否超时
            if (checkHeartbeat()) {
                sendLogToFrontend('crawl', '  → 停止搜索：60秒内未收到外部信号');
                safeSendResponse({
                    success: false,
                    error: '心跳超时：60秒内未收到外部信号，搜索已停止',
                    errorType: 'heartbeat_timeout',
                    data: extractedData,
                    count: extractedData.length
                });
                return; // 停止整个任务
            }

            // 检查是否应该停止（5分钟无有效客户）
            if (shouldStopSearch()) {
                sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                break; // 跳出查询循环
            }

            const query = queries[queryIndex];
            const logMsg = '【查询' + (queryIndex + 1) + '/' + queries.length + '】' + query;
            console.log('[AutoLeadAgent Extension]', logMsg);
            sendLogToFrontend('crawl', '');
            sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
            sendLogToFrontend('crawl', logMsg);
            sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
            sendLogToFrontend('crawl', '');

            // 对每个搜索引擎进行搜索
            for (const engine of enginesToUse) {
                // 检查心跳是否超时
                if (checkHeartbeat()) {
                    sendLogToFrontend('crawl', '  → 停止搜索：5秒内未收到外部信号');
                    safeSendResponse({
                        success: false,
                        error: '心跳超时：5秒内未收到外部信号，搜索已停止',
                        errorType: 'heartbeat_timeout',
                        data: extractedData,
                        count: extractedData.length
                    });
                    return; // 停止整个任务
                }

                // 检查是否应该停止（5分钟无有效客户）
                if (shouldStopSearch()) {
                    sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                    break; // 跳出搜索引擎循环
                }

                // 检查搜索平台打开间隔：如果距离上次打开同一搜索引擎的时间太短，等待一段时间
                const lastOpenTime = engineLastOpenTime.get(engine.name);
                if (lastOpenTime) {
                    const timeSinceLastOpen = Date.now() - lastOpenTime;
                    if (timeSinceLastOpen < SEARCH_ENGINE_INTERVAL) {
                        const waitTime = SEARCH_ENGINE_INTERVAL - timeSinceLastOpen;
                        const waitSeconds = (waitTime / 1000).toFixed(1);
                        sendLogToFrontend('crawl', '  ⏳ 等待 ' + waitSeconds + ' 秒后打开 ' + engine.name + '（控制访问间隔）...');
                        await sleep(waitTime);
                        sendLogToFrontend('crawl', '  ✓ 等待完成，开始打开 ' + engine.name);
                    }
                }
                // 更新最后打开时间
                engineLastOpenTime.set(engine.name, Date.now());

                sendLogToFrontend('crawl', '');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                sendLogToFrontend('crawl', '🔍 [' + engine.name + '] 开始搜索查询: "' + query + '"');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

                let currentPage = 1;
                const maxPages = 50; // 最多搜索50页
                const visitedUrls = new Set(); // 记录已访问的URL，避免重复爬取
                let nextPageUrl = null; // 存储下一页URL
                let searchTabId = null; // 当前页搜索标签页ID（移到循环外，避免作用域问题）

                // 循环处理分页（每页处理完立即关闭标签页）
                while (currentPage <= maxPages) {

                    try {
                        // 根据当前页决定搜索方式
                        let searchResult;
                        if (currentPage === 1) {
                            // 第一页：创建新标签页进行搜索
                            searchResult = await searchInEngine(engine, query, maxResults, null, openedTabs);
                        } else {
                            // 后续页：如果有下一页URL，直接访问；否则尝试查找分页按钮
                            if (nextPageUrl) {
                                // 检查搜索平台打开间隔
                                const lastOpenTime = engineLastOpenTime.get(engine.name);
                                if (lastOpenTime) {
                                    const timeSinceLastOpen = Date.now() - lastOpenTime;
                                    if (timeSinceLastOpen < SEARCH_ENGINE_INTERVAL) {
                                        const waitTime = SEARCH_ENGINE_INTERVAL - timeSinceLastOpen;
                                        const waitSeconds = (waitTime / 1000).toFixed(1);
                                        sendLogToFrontend('crawl', '  ⏳ 等待 ' + waitSeconds + ' 秒后打开 ' + engine.name + ' 第' + currentPage + '页（控制访问间隔）...');
                                        await sleep(waitTime);
                                        sendLogToFrontend('crawl', '  ✓ 等待完成，开始打开 ' + engine.name + ' 第' + currentPage + '页');
                                    }
                                }
                                // 更新最后打开时间
                                engineLastOpenTime.set(engine.name, Date.now());

                                // 创建新标签页访问下一页URL
                                sendLogToFrontend('crawl', '  → [' + engine.name + '] 创建新标签页访问第' + currentPage + '页: ' + nextPageUrl);
                                const tab = await chrome.tabs.create({ url: nextPageUrl, active: false });
                                searchTabId = tab.id;
                                // 添加到跟踪列表
                                openedTabs.add(tab.id);

                                sendLogToFrontend('crawl', '  → 等待' + engine.name + '页面加载...');
                                await waitForTabLoad(tab.id, 30000);
                                sendLogToFrontend('crawl', '  ✓ ' + engine.name + '页面加载完成');

                                sendLogToFrontend('crawl', '  → 等待动态内容加载（3秒）...');
                                await sleep(3000);
                                sendLogToFrontend('crawl', '  ✓ 动态内容加载完成');

                                // 提取URL列表
                                sendLogToFrontend('crawl', '  → 开始从' + engine.name + '提取搜索结果URL（使用端侧模型过滤广告）...');
                                const results = await chrome.scripting.executeScript({
                                    target: { tabId: tab.id },
                                    func: extractSearchEngineResults,
                                    args: [engine, null, maxResults, null, []]
                                });

                                const urlList = results[0]?.result || [];

                                // 检查是否有错误
                                if (urlList.length > 0 && urlList[0].error) {
                                    const errorInfo = urlList[0];
                                    sendLogToFrontend('crawl', '  ⚠️ ' + errorInfo.errorMessage);
                                    try {
                                        await chrome.tabs.remove(tab.id);
                                        openedTabs.delete(tab.id); // 从跟踪列表移除
                                    } catch (e) {
                                        openedTabs.delete(tab.id); // 即使关闭失败，也从跟踪列表移除
                                    }
                                    break;
                                }

                                // 处理广告统计
                                let adCount = 0;
                                let totalProcessed = urlList.length;
                                if (urlList.length > 0 && urlList[0].adCount !== undefined) {
                                    adCount = urlList[0].adCount;
                                    totalProcessed = urlList[0].totalProcessed || urlList.length;
                                    delete urlList[0].adCount;
                                    delete urlList[0].totalProcessed;
                                }

                                const resultMsg = '从' + engine.name + '提取到 ' + urlList.length + ' 个结果URL';
                                sendLogToFrontend('crawl', '  ✓ ' + resultMsg);
                                if (adCount > 0) {
                                    sendLogToFrontend('crawl', '  🚫 已过滤 ' + adCount + ' 个广告结果（共处理 ' + totalProcessed + ' 个结果）');
                                }

                                // 显示结果列表
                                if (urlList.length > 0) {
                                    sendLogToFrontend('crawl', '  📋 提取的结果列表:');
                                    urlList.slice(0, 10).forEach((resultItem, idx) => {
                                        sendLogToFrontend('crawl', '     ' + (idx + 1) + '. ' + (resultItem.title || '(无标题)'));
                                        sendLogToFrontend('crawl', '        URL: ' + resultItem.url);
                                        if (resultItem.snippet) {
                                            const snippetPreview = resultItem.snippet.substring(0, 80) + (resultItem.snippet.length > 80 ? '...' : '');
                                            sendLogToFrontend('crawl', '        摘要: ' + snippetPreview);
                                        }
                                    });
                                    if (urlList.length > 10) {
                                        sendLogToFrontend('crawl', '     ... 还有 ' + (urlList.length - 10) + ' 个结果');
                                    }
                                }

                                searchResult = {
                                    urls: urlList,
                                    tabId: tab.id,
                                    error: null
                                };
                            } else {
                                // 没有下一页URL，无法继续
                                sendLogToFrontend('crawl', '  ⚠️ [' + engine.name + '] 未找到下一页URL，停止分页');
                                break;
                            }
                        }

                        if (searchResult.error) {
                            sendLogToFrontend('crawl', '  ⚠️ [' + engine.name + '] ' + searchResult.error.errorMessage);
                            // 关闭搜索标签页（如果有）
                            if (searchResult.tabId) {
                                try {
                                    await chrome.tabs.remove(searchResult.tabId);
                                    openedTabs.delete(searchResult.tabId); // 从跟踪列表移除
                                    sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 搜索标签页已关闭');
                                } catch (e) {
                                    console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                    openedTabs.delete(searchResult.tabId); // 即使关闭失败，也从跟踪列表移除
                                }
                            }
                            if (searchTabId && searchTabId !== searchResult.tabId) {
                                try {
                                    await chrome.tabs.remove(searchTabId);
                                    openedTabs.delete(searchTabId); // 从跟踪列表移除
                                } catch (e) {
                                    openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                }
                            }
                            break; // 遇到错误，停止该搜索引擎
                        }

                        searchTabId = searchResult.tabId; // 保存当前页标签页ID

                        const urlList = searchResult.urls || [];

                        // 详细日志：显示提取到的结果详情（调试用）
                        console.log('[AutoLeadAgent Extension] ===== 提取结果验证 =====');
                        console.log('[AutoLeadAgent Extension] 提取到的结果列表长度:', urlList.length);
                        console.log('[AutoLeadAgent Extension] 提取到的结果列表详情:', JSON.stringify(urlList.slice(0, 5), null, 2));

                        if (urlList.length > 0) {
                            sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '/' + maxPages + '页提取到 ' + urlList.length + ' 个结果URL');
                            sendLogToFrontend('crawl', '  🔍 [调试] urlList内容验证: 长度=' + urlList.length + ', 有效URL=' + urlList.filter(item => item.url).length);

                            // 显示前3个结果的预览
                            urlList.slice(0, 3).forEach((item, idx) => {
                                const urlPreview = item.url ? (item.url.substring(0, 60) + (item.url.length > 60 ? '...' : '')) : '无URL';
                                sendLogToFrontend('crawl', `     ${idx + 1}. ${item.title || '无标题'} - ${urlPreview}`);
                            });
                            if (urlList.length > 3) {
                                sendLogToFrontend('crawl', `     ... 还有 ${urlList.length - 3} 个结果`);
                            }
                        }

                        // 如果urlList仍然为空（包括模型提取后），记录日志
                        if (urlList.length === 0) {
                            console.log('[AutoLeadAgent Extension] 分页循环：最终urlList为空，准备停止或继续');
                            sendLogToFrontend('crawl', '  ⚠️ [' + engine.name + '] 第' + currentPage + '页未提取到任何结果URL');
                            sendLogToFrontend('crawl', '  🔍 [调试] urlList为空，深度爬取将无法执行');

                            // 如果第一页未能提取到任何结果，尝试使用模型推理提取
                            if (currentPage === 1) {
                                sendLogToFrontend('crawl', '  → 标准方法未提取到结果，尝试使用模型推理提取...');
                                console.log('[AutoLeadAgent Extension] 分页循环：标准方法提取失败，尝试使用模型推理提取...');

                                try {
                                    // 获取页面HTML
                                    const htmlResult = await chrome.scripting.executeScript({
                                        target: { tabId: searchTabId },
                                        func: () => {
                                            return {
                                                html: document.documentElement.outerHTML,
                                                url: window.location.href,
                                                title: document.title
                                            };
                                        }
                                    });

                                    const pageData = htmlResult[0]?.result || {};
                                    const pageHtml = pageData.html || '';
                                    const pageUrl = pageData.url || '';

                                    console.log('[AutoLeadAgent Extension] 分页循环：获取到页面HTML，长度:', pageHtml.length);
                                    console.log('[AutoLeadAgent Extension] 分页循环：页面URL:', pageUrl);
                                    console.log('[AutoLeadAgent Extension] 分页循环：页面标题:', pageData.title);

                                    // 打印HTML的前5000个字符用于调试
                                    console.log('[AutoLeadAgent Extension] ===== 分页循环 HTML内容（前5000字符） =====');
                                    console.log(pageHtml.substring(0, 5000));
                                    console.log('[AutoLeadAgent Extension] ===== HTML内容结束 =====');

                                    // 调用后端API使用模型提取URL
                                    sendLogToFrontend('crawl', '  → 调用模型API提取搜索结果URL...');

                                    // 获取模型API URL
                                    const modelApiUrl = await getModelApiUrl();
                                    if (!modelApiUrl) {
                                        throw new Error('无法确定模型API URL，请确保在任务管理页面中运行');
                                    }

                                    console.log('[AutoLeadAgent Extension] 分页循环：模型API URL:', modelApiUrl);

                                    // 构建提示词
                                    const prompt = `请从以下Google搜索结果页面的HTML中提取所有搜索结果项的URL。

要求：
1. 只提取搜索结果项的URL，不要提取Google自己的链接（如google.com/search、google.com/url等）
2. 提取的URL应该是外部网站的链接（特别是facebook.com的链接）
3. 返回JSON格式，包含url、title、snippet字段
4. 最多提取${maxResults}个结果

HTML内容：
${pageHtml.substring(0, 50000)}${pageHtml.length > 50000 ? '...(已截断)' : ''}

请返回JSON数组格式，例如：
[
  {"url": "https://www.facebook.com/example", "title": "页面标题", "snippet": "页面摘要"},
  ...
]`;

                                    // 调用模型API（兼容 /ai/api/v1/chat/completions 接口）
                                    const modelResponse = await fetch(modelApiUrl, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                        },
                                        body: JSON.stringify({
                                            model: 'gpt-4o-mini',
                                            messages: [
                                                {
                                                    role: 'system',
                                                    content: '你是一个擅长解析搜索引擎结果页面HTML的助手，负责从HTML中提取搜索结果项的URL、标题和摘要。只返回JSON数组，不要返回多余说明。'
                                                },
                                                {
                                                    role: 'user',
                                                    content: prompt
                                                }
                                            ],
                                            temperature: 0.3,
                                            max_tokens: 2000
                                        })
                                    });

                                    if (!modelResponse.ok) {
                                        throw new Error(`模型API调用失败: ${modelResponse.status} ${modelResponse.statusText}`);
                                    }

                                    const modelData = await modelResponse.json();
                                    console.log('[AutoLeadAgent Extension] 分页循环：模型API完整响应:', JSON.stringify(modelData, null, 2));

                                    // 解析模型返回的结果（兼容 OpenAI 风格）
                                    let modelContent = '';
                                    if (modelData && Array.isArray(modelData.choices) && modelData.choices.length > 0 &&
                                        modelData.choices[0].message && typeof modelData.choices[0].message.content === 'string') {
                                        modelContent = modelData.choices[0].message.content;
                                    } else if (modelData && modelData.content) {
                                        modelContent = modelData.content;
                                    } else if (modelData && modelData.data && modelData.data.content) {
                                        modelContent = modelData.data.content;
                                    } else if (modelData && modelData.response) {
                                        modelContent = modelData.response;
                                    }

                                    console.log('[AutoLeadAgent Extension] 分页循环：模型返回的原始内容:', modelContent);

                                    if (modelContent) {
                                        try {
                                            // 尝试从内容中提取JSON
                                            let jsonStr = modelContent.trim();
                                            if (jsonStr.startsWith('```json')) {
                                                jsonStr = jsonStr.replace(/^```json\s*/, '').replace(/\s*```$/, '');
                                            } else if (jsonStr.startsWith('```')) {
                                                jsonStr = jsonStr.replace(/^```\s*/, '').replace(/\s*```$/, '');
                                            }
                                            const jsonMatch = jsonStr.match(/\[[\s\S]*\]/);
                                            if (jsonMatch) {
                                                jsonStr = jsonMatch[0];
                                            }

                                            const extractedUrls = JSON.parse(jsonStr);
                                            if (Array.isArray(extractedUrls) && extractedUrls.length > 0) {
                                                urlList = extractedUrls;
                                                console.log('[AutoLeadAgent Extension] 分页循环：模型提取成功，找到', urlList.length, '个URL');
                                                console.log('[AutoLeadAgent Extension] 分页循环：重新提取到的URL列表:', JSON.stringify(urlList, null, 2));
                                                sendLogToFrontend('crawl', '  ✓ 模型提取成功，找到 ' + urlList.length + ' 个结果URL');

                                                // 显示提取到的URL列表
                                                urlList.slice(0, 5).forEach((item, idx) => {
                                                    console.log(`[AutoLeadAgent Extension] 分页循环：URL ${idx + 1}:`, item.url, item.title || '无标题');
                                                    sendLogToFrontend('crawl', `     ${idx + 1}. ${item.title || '无标题'} - ${item.url || '无URL'}`);
                                                });
                                                if (urlList.length > 5) {
                                                    sendLogToFrontend('crawl', `     ... 还有 ${urlList.length - 5} 个结果`);
                                                }
                                            } else {
                                                console.warn('[AutoLeadAgent Extension] 分页循环：模型返回的结果不是有效数组或为空');
                                            }
                                        } catch (parseError) {
                                            console.error('[AutoLeadAgent Extension] 分页循环：解析模型返回结果失败:', parseError);
                                            console.error('[AutoLeadAgent Extension] 分页循环：模型返回内容:', modelContent);
                                        }
                                    }

                                    // 如果模型提取也失败，继续执行停止逻辑
                                    if (urlList.length === 0) {
                                        sendLogToFrontend('crawl', '  ⚠️ 模型推理提取也失败，停止该搜索引擎的搜索');
                                        sendLogToFrontend('crawl', '  → 可能原因：1. 页面结构变化 2. 模型识别失败 3. 页面需要验证码 4. 搜索结果为空');

                                        // 关闭当前页标签页
                                        if (searchTabId) {
                                            try {
                                                await chrome.tabs.remove(searchTabId);
                                                openedTabs.delete(searchTabId);
                                                sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                            } catch (e) {
                                                console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                                openedTabs.delete(searchTabId);
                                            }
                                        }
                                        break; // 直接停止该搜索引擎的分页循环
                                    }
                                } catch (modelError) {
                                    console.error('[AutoLeadAgent Extension] 分页循环：模型推理提取失败:', modelError);
                                    sendLogToFrontend('crawl', '  ⚠️ 模型推理提取失败: ' + modelError.message);

                                    // 关闭当前页标签页
                                    if (searchTabId) {
                                        try {
                                            await chrome.tabs.remove(searchTabId);
                                            openedTabs.delete(searchTabId);
                                            sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                        } catch (e) {
                                            console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                            openedTabs.delete(searchTabId);
                                        }
                                    }
                                    break; // 直接停止该搜索引擎的分页循环
                                }
                            } else {
                                // 非第一页，继续尝试
                                sendLogToFrontend('crawl', '  → 可能原因：1. 页面结构变化 2. 模型识别失败 3. 页面需要验证码');

                                // 尝试在搜索页面获取更多信息用于调试
                                if (searchTabId) {
                                    try {
                                        const pageInfo = await chrome.scripting.executeScript({
                                            target: { tabId: searchTabId },
                                            func: () => {
                                                return {
                                                    title: document.title,
                                                    url: window.location.href,
                                                    bodyTextLength: (document.body ? document.body.innerText || document.body.textContent : '').length,
                                                    hasResults: document.querySelectorAll('div, article, section, li').length,
                                                    pageText: (document.body ? document.body.innerText || document.body.textContent : '').substring(0, 500)
                                                };
                                            }
                                        });
                                        const info = pageInfo[0]?.result || {};
                                        sendLogToFrontend('crawl', `  → 页面信息: 标题="${info.title}", 文本长度=${info.bodyTextLength}, 容器元素=${info.hasResults}`);
                                        if (info.pageText) {
                                            sendLogToFrontend('crawl', `  → 页面内容预览: ${info.pageText.substring(0, 200)}...`);
                                        }
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 获取页面信息失败:', e);
                                    }
                                }

                                // 关闭当前页标签页
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                break; // 没有更多结果，停止分页
                            }

                            // 添加调试日志
                            if (urlList.length > 0) {
                                sendLogToFrontend('crawl', '');
                                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                                sendLogToFrontend('crawl', '🚀 [' + engine.name + '] 开始处理 ' + urlList.length + ' 个结果条目');
                                sendLogToFrontend('crawl', '  📋 处理流程: 1) 访问结果条目提取信息 2) 10层深度递归爬取');
                                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                            } else {
                                sendLogToFrontend('crawl', '  ⚠️ 未提取到结果，跳过结果条目处理和深度爬取');
                                sendLogToFrontend('crawl', '  💡 提示: 请检查搜索引擎结果提取逻辑是否正常工作');
                            }

                            // 对每个URL进行处理：先访问结果条目，然后深度爬取
                            for (let urlIndex = 0; urlIndex < urlList.length; urlIndex++) {
                                // 检查心跳是否超时
                                if (checkHeartbeat()) {
                                    sendLogToFrontend('crawl', '  → 停止搜索：5秒内未收到外部信号');
                                    safeSendResponse({
                                        success: false,
                                        error: '心跳超时：5秒内未收到外部信号，搜索已停止',
                                        errorType: 'heartbeat_timeout',
                                        data: extractedData,
                                        count: extractedData.length
                                    });
                                    return; // 停止整个任务
                                }

                                // 检查是否应该停止（5分钟无有效客户）
                                if (shouldStopSearch()) {
                                    sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                                    break; // 跳出URL循环
                                }

                                const resultItem = urlList[urlIndex];
                                const targetUrl = resultItem.url;

                                if (!targetUrl || visitedUrls.has(targetUrl)) {
                                    continue; // 跳过无效URL或已访问的URL
                                }

                                visitedUrls.add(targetUrl);

                                sendLogToFrontend('crawl', '');
                                sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
                                sendLogToFrontend('crawl', '🌐 [' + engine.name + '] 开始访问第' + (urlIndex + 1) + '/' + urlList.length + ' 个结果');
                                sendLogToFrontend('crawl', '   📍 URL: ' + targetUrl);
                                if (resultItem.title) {
                                    sendLogToFrontend('crawl', '   📝 标题: ' + resultItem.title);
                                }
                                if (resultItem.snippet) {
                                    const snippetPreview = resultItem.snippet.substring(0, 150) + (resultItem.snippet.length > 150 ? '...' : '');
                                    sendLogToFrontend('crawl', '   📄 摘要: ' + snippetPreview);
                                }
                                sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');

                                /**
                                 * 客户质量评分系统
                                 */
                                function calculateCustomerQualityScore(customer, profileInfo = {}) {
                                    let score = 0;
                                    const reasons = [];

                                    // 1. 邮箱质量评分（企业邮箱 > 个人邮箱）
                                    if (customer.email) {
                                        const email = customer.email.toLowerCase();
                                        // 企业邮箱（排除常见个人邮箱服务商）
                                        const personalEmailDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'qq.com', '163.com', '126.com'];
                                        const isBusinessEmail = !personalEmailDomains.some(domain => email.includes(domain));

                                        if (isBusinessEmail) {
                                            score += 3.0;
                                            reasons.push('企业邮箱');
                                        } else {
                                            score += 1.0;
                                            reasons.push('个人邮箱');
                                        }
                                    }

                                    // 2. 信息完整度评分（邮箱+电话+社媒 > 单一信息）
                                    let infoCount = 0;
                                    if (customer.email) infoCount++;
                                    if (customer.phone) infoCount++;
                                    if (customer.socialMediaAccounts && Object.keys(customer.socialMediaAccounts).length > 0) infoCount++;

                                    if (infoCount >= 3) {
                                        score += 4.0;
                                        reasons.push('信息完整（邮箱+电话+社媒）');
                                    } else if (infoCount === 2) {
                                        score += 2.0;
                                        reasons.push('信息较完整（2项）');
                                    } else if (infoCount === 1) {
                                        score += 1.0;
                                        reasons.push('信息单一（1项）');
                                    }

                                    // 3. 页面权威性评分（官网 > 第三方平台）
                                    if (customer.pageType === 'about' || customer.pageType === 'contact') {
                                        score += 2.0;
                                        reasons.push('官方页面（About/Contact）');
                                    } else if (customer.pageType === 'home') {
                                        score += 1.5;
                                        reasons.push('首页');
                                    } else if (customer.pageType === 'team') {
                                        score += 1.0;
                                        reasons.push('团队页面');
                                    }

                                    // 4. 行业匹配度评分（基于店铺画像）
                                    if (profileInfo.industry && customer.industry) {
                                        const customerIndustries = customer.industry.toLowerCase().split(',').map(i => i.trim());
                                        const targetIndustry = profileInfo.industry.toLowerCase();
                                        if (customerIndustries.some(ind => ind.includes(targetIndustry) || targetIndustry.includes(ind))) {
                                            score += 2.0;
                                            reasons.push('行业匹配');
                                        }
                                    }

                                    // 5. 公司信息完整性（有公司名称、规模等）
                                    if (customer.companyName) {
                                        score += 1.0;
                                        reasons.push('有公司名称');
                                    }
                                    if (customer.companySize) {
                                        score += 0.5;
                                        reasons.push('有公司规模信息');
                                    }
                                    if (customer.location) {
                                        score += 0.5;
                                        reasons.push('有地理位置信息');
                                    }

                                    // 6. 联系人信息（有联系人姓名和职位）
                                    if (customer.contactName) {
                                        score += 1.0;
                                        reasons.push('有联系人姓名');
                                    }
                                    if (customer.jobTitle) {
                                        score += 0.5;
                                        reasons.push('有职位信息');
                                    }

                                    return {
                                        score: Math.min(score, 10.0), // 最高10分
                                        reasons: reasons,
                                        level: score >= 7 ? 'high' : score >= 4 ? 'medium' : 'low'
                                    };
                                }

                                // 步骤1: 先访问结果条目并提取信息
                                sendLogToFrontend('crawl', '   🔍 步骤1: 访问结果条目并提取用户信息...');
                                const resultItemInfo = await visitSearchResultAndExtract(
                                    targetUrl,
                                    resultItem.title,
                                    resultItem.snippet,
                                    engine,
                                    sendLogToFrontend,
                                    openedTabs
                                );

                                // 如果从结果条目提取到信息，转换为标准格式并添加到extractedData
                                let resultItemCustomer = null;
                                if (resultItemInfo) {
                                    resultItemCustomer = {
                                        extractedAt: resultItemInfo.extractedAt,
                                        source: engine.name,
                                        profileUrl: resultItemInfo.url,
                                        email: resultItemInfo.emails && resultItemInfo.emails.length > 0 ? resultItemInfo.emails[0] : null,
                                        phone: resultItemInfo.phones && resultItemInfo.phones.length > 0 ? resultItemInfo.phones[0] : null,
                                        socialMediaAccounts: resultItemInfo.socialMediaAccounts || {},
                                        depth: 0, // 结果条目本身，深度为0
                                        sourceUrl: targetUrl,
                                        title: resultItem.title,
                                        snippet: resultItem.snippet,
                                        // 新增字段
                                        companyName: resultItemInfo.companyName || null,
                                        contactName: resultItemInfo.contactName || null,
                                        jobTitle: resultItemInfo.jobTitle || null,
                                        companySize: resultItemInfo.companySize || null,
                                        industry: resultItemInfo.industry || null,
                                        location: resultItemInfo.location || null,
                                        website: resultItemInfo.website || null,
                                        foundedYear: resultItemInfo.foundedYear || null,
                                        pageType: resultItemInfo.pageType || 'other'
                                    };

                                    // 计算客户质量评分
                                    const qualityScore = calculateCustomerQualityScore(resultItemCustomer, request.profileInfo || {});
                                    resultItemCustomer.qualityScore = qualityScore.score;
                                    resultItemCustomer.qualityLevel = qualityScore.level;
                                    resultItemCustomer.qualityReasons = qualityScore.reasons;

                                    // 添加到extractedData
                                    extractedData.push(resultItemCustomer);

                                    // 检查是否是有效客户
                                    if (isValidCustomer(resultItemCustomer)) {
                                        lastValidCustomerTime = Date.now(); // 更新最后找到有效客户的时间
                                        if (sendLogToFrontend) {
                                            sendLogToFrontend('crawl', '   ✅ 从结果条目找到有效客户！');
                                            sendLogToFrontend('crawl', `   ⭐ 客户质量评分: ${qualityScore.score.toFixed(1)}/10 (${qualityScore.level})`);
                                            if (qualityScore.reasons.length > 0) {
                                                sendLogToFrontend('crawl', `      原因: ${qualityScore.reasons.join(', ')}`);
                                            }
                                        }
                                    } else {
                                        if (sendLogToFrontend) {
                                            sendLogToFrontend('crawl', '   ⚠️ 结果条目未包含有效客户信息（无邮箱/电话/社媒）');
                                        }
                                    }
                                }

                                // 步骤2: 继续深度爬取该URL（10层深度）
                                sendLogToFrontend('crawl', '');
                                sendLogToFrontend('crawl', '   🔍 步骤2: 开始10层深度递归爬取...');
                                sendLogToFrontend('crawl', '   📍 目标URL: ' + targetUrl);
                                sendLogToFrontend('crawl', '   🔢 最大深度: 10层');
                                sendLogToFrontend('crawl', '   ⏳ 开始深度爬取（这可能需要几分钟）...');

                                const deepCrawlStartTime = Date.now();
                                const deepCrawlResults = await deepCrawlWebsite(
                                    targetUrl,
                                    10, // 最大深度10层
                                    new Set(), // 每个URL使用独立的visited set
                                    0, // 从深度0开始
                                    sendLogToFrontend
                                );

                                const deepCrawlDuration = Math.floor((Date.now() - deepCrawlStartTime) / 1000);
                                sendLogToFrontend('crawl', '   ✓ 深度爬取完成，耗时: ' + deepCrawlDuration + ' 秒');
                                sendLogToFrontend('crawl', '   📊 深度爬取结果: 找到 ' + deepCrawlResults.length + ' 条客户信息');

                                // 将深度爬取的结果转换为标准格式，并检查是否有有效客户
                                // 注意：结果条目本身的信息已经在上一步添加，这里只处理深度爬取的结果
                                let foundValidCustomerInDeepCrawl = false;
                                const processedUrls = new Set(); // 用于去重，避免重复添加相同URL的信息

                                // 如果结果条目已经提取了信息，记录其URL，避免重复
                                if (resultItemCustomer && resultItemCustomer.profileUrl) {
                                    processedUrls.add(resultItemCustomer.profileUrl);
                                }

                                deepCrawlResults.forEach(customerInfo => {
                                    // 如果这个URL已经在结果条目中处理过，跳过（避免重复）
                                    if (processedUrls.has(customerInfo.url)) {
                                        return;
                                    }
                                    processedUrls.add(customerInfo.url);

                                    const customer = {
                                        extractedAt: customerInfo.extractedAt,
                                        source: engine.name,
                                        profileUrl: customerInfo.url,
                                        email: customerInfo.emails && customerInfo.emails.length > 0 ? customerInfo.emails[0] : null,
                                        phone: customerInfo.phones && customerInfo.phones.length > 0 ? customerInfo.phones[0] : null,
                                        socialMediaAccounts: customerInfo.socialMediaAccounts || {},
                                        depth: customerInfo.depth,
                                        sourceUrl: customerInfo.sourceUrl || targetUrl,
                                        title: resultItem.title,
                                        snippet: resultItem.snippet,
                                        // 新增字段
                                        companyName: customerInfo.companyName || null,
                                        contactName: customerInfo.contactName || null,
                                        jobTitle: customerInfo.jobTitle || null,
                                        companySize: customerInfo.companySize || null,
                                        industry: customerInfo.industry || null,
                                        location: customerInfo.location || null,
                                        website: customerInfo.website || null,
                                        foundedYear: customerInfo.foundedYear || null,
                                        pageType: customerInfo.pageType || 'other'
                                    };

                                    // 计算客户质量评分
                                    const qualityScore = calculateCustomerQualityScore(customer, request.profileInfo || {});
                                    customer.qualityScore = qualityScore.score;
                                    customer.qualityLevel = qualityScore.level;
                                    customer.qualityReasons = qualityScore.reasons;

                                    extractedData.push(customer);

                                    // 检查是否是有效客户
                                    if (isValidCustomer(customer)) {
                                        foundValidCustomerInDeepCrawl = true;
                                        lastValidCustomerTime = Date.now(); // 更新最后找到有效客户的时间

                                        // 记录质量评分日志
                                        if (sendLogToFrontend) {
                                            sendLogToFrontend('crawl', `  │  ⭐ 客户质量评分: ${qualityScore.score.toFixed(1)}/10 (${qualityScore.level})`);
                                            if (qualityScore.reasons.length > 0) {
                                                sendLogToFrontend('crawl', `  │     原因: ${qualityScore.reasons.join(', ')}`);
                                            }
                                        }
                                    }
                                });

                                // 汇总日志
                                sendLogToFrontend('crawl', '');
                                sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
                                sendLogToFrontend('crawl', '✓ [' + engine.name + '] 完成第' + (urlIndex + 1) + '/' + urlList.length + ' 个结果的处理');
                                sendLogToFrontend('crawl', '   📍 URL: ' + targetUrl);

                                // 统计结果
                                const totalFound = (resultItemCustomer ? 1 : 0) + deepCrawlResults.length;
                                const hasValidCustomer = (resultItemCustomer && isValidCustomer(resultItemCustomer)) || foundValidCustomerInDeepCrawl;

                                if (totalFound > 0) {
                                    sendLogToFrontend('crawl', '   ✅ 共找到 ' + totalFound + ' 条客户信息');
                                    if (resultItemCustomer) {
                                        sendLogToFrontend('crawl', '      - 结果条目: 1 条');
                                    }
                                    if (deepCrawlResults.length > 0) {
                                        sendLogToFrontend('crawl', '      - 深度爬取: ' + deepCrawlResults.length + ' 条');
                                    }

                                    if (hasValidCustomer) {
                                        const timeSinceStart = Math.floor((Date.now() - searchStartTime) / 1000);
                                        sendLogToFrontend('crawl', '   🎯 包含有效客户！已用时 ' + timeSinceStart + ' 秒');
                                    } else {
                                        sendLogToFrontend('crawl', '   ⚠️ 未找到有效客户（无邮箱/电话/社媒）');
                                    }
                                } else {
                                    sendLogToFrontend('crawl', '   ⚠️ 未找到任何客户信息');
                                }
                                sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
                                sendLogToFrontend('crawl', '');

                                // 如果找到有效客户，重置计时器
                                if (hasValidCustomer) {
                                    // 继续搜索，不停止
                                }
                            }

                            // 检查是否应该停止（在完成当前页所有URL后）
                            if (shouldStopSearch()) {
                                sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                                // 关闭当前页标签页
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                break; // 跳出分页循环
                            }

                            // 检查心跳是否超时
                            if (checkHeartbeat()) {
                                sendLogToFrontend('crawl', '  → 停止搜索：60秒内未收到外部信号');
                                // 关闭当前页标签页
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                safeSendResponse({
                                    success: false,
                                    error: '心跳超时：60秒内未收到外部信号，搜索已停止',
                                    errorType: 'heartbeat_timeout',
                                    data: extractedData,
                                    count: extractedData.length
                                });
                                return; // 停止整个任务
                            }

                            // 检查是否应该停止（5分钟无有效客户）
                            if (shouldStopSearch()) {
                                sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                                // 关闭当前页标签页
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                break; // 跳出分页循环
                            }

                            // 当前页所有URL爬取完成，提取下一页URL，然后关闭当前页标签页
                            if (searchTabId) {
                                // 在关闭标签页前，先提取下一页URL
                                try {
                                    sendLogToFrontend('crawl', '  → [' + engine.name + '] 提取下一页URL（使用端侧模型分析）...');
                                    const nextPageUrlResult = await chrome.scripting.executeScript({
                                        target: { tabId: searchTabId },
                                        func: getNextPageUrl
                                    });
                                    nextPageUrl = nextPageUrlResult[0]?.result || null;

                                    if (nextPageUrl) {
                                        sendLogToFrontend('crawl', '  ✓ 找到下一页URL: ' + nextPageUrl);
                                    } else {
                                        sendLogToFrontend('crawl', '  ⚠️ 未找到下一页URL');
                                    }
                                } catch (e) {
                                    console.warn('[AutoLeadAgent Extension] 提取下一页URL失败:', e.message);
                                    sendLogToFrontend('crawl', '  ⚠️ 提取下一页URL失败: ' + e.message);
                                    nextPageUrl = null;
                                }

                                // 关闭当前页标签页
                                try {
                                    sendLogToFrontend('crawl', '  → [' + engine.name + '] 第' + currentPage + '页处理完成，关闭标签页 (ID: ' + searchTabId + ')');
                                    await chrome.tabs.remove(searchTabId);
                                    openedTabs.delete(searchTabId); // 从跟踪列表移除
                                    sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    searchTabId = null; // 清空引用
                                } catch (e) {
                                    console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                    sendLogToFrontend('crawl', '  ⚠️ [' + engine.name + '] 关闭标签页失败: ' + e.message);
                                    openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                }
                            }

                            // 检查是否应该停止（在完成当前页所有URL后）
                            if (shouldStopSearch()) {
                                sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                                // 关闭当前页标签页（如果还没关闭）
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                break; // 跳出分页循环
                            }

                            // 检查心跳是否超时
                            if (checkHeartbeat()) {
                                sendLogToFrontend('crawl', '  → 停止搜索：60秒内未收到外部信号');
                                // 关闭当前页标签页（如果还没关闭）
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                safeSendResponse({
                                    success: false,
                                    error: '心跳超时：60秒内未收到外部信号，搜索已停止',
                                    errorType: 'heartbeat_timeout',
                                    data: extractedData,
                                    count: extractedData.length
                                });
                                return; // 停止整个任务
                            }

                            // 如果没有下一页URL，停止分页
                            if (!nextPageUrl) {
                                sendLogToFrontend('crawl', '  → [' + engine.name + '] 未找到下一页URL，停止分页');
                                // 关闭当前页标签页（如果还没关闭）
                                if (searchTabId) {
                                    try {
                                        await chrome.tabs.remove(searchTabId);
                                        openedTabs.delete(searchTabId); // 从跟踪列表移除
                                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 第' + currentPage + '页标签页已关闭');
                                    } catch (e) {
                                        console.warn('[AutoLeadAgent Extension] 关闭标签页失败:', e.message);
                                        openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                                    }
                                }
                                break;
                            }

                            // 准备翻页到下一页
                            currentPage++;
                            sendLogToFrontend('crawl', '');
                            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                            sendLogToFrontend('crawl', '📄 [' + engine.name + '] 准备翻页到第' + currentPage + '页...');
                            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                        }

                    } catch (error) {
                        const errorMsg = engine.name + ' 搜索/爬取异常: ' + error.message;
                        console.error('[AutoLeadAgent Extension]', errorMsg);
                        sendLogToFrontend('crawl', '  ❌ [' + engine.name + '] ' + errorMsg);
                        lastError = error;

                        // 确保关闭标签页
                        if (searchTabId) {
                            try {
                                await chrome.tabs.remove(searchTabId);
                                openedTabs.delete(searchTabId); // 从跟踪列表移除
                            } catch (e) {
                                // 忽略关闭错误
                                openedTabs.delete(searchTabId); // 即使关闭失败，也从跟踪列表移除
                            }
                        }
                        break; // 遇到异常，停止该搜索引擎
                    }
                }

                allSourceUrls.push({ engine: engine.name, query: query, url: engine.url + encodeURIComponent(query) });

                // 确保关闭所有搜索标签页（包括可能遗留的）
                if (searchTabId) {
                    try {
                        await chrome.tabs.remove(searchTabId);
                        openedTabs.delete(searchTabId);
                        sendLogToFrontend('crawl', '  ✓ [' + engine.name + '] 搜索标签页已关闭');
                    } catch (e) {
                        openedTabs.delete(searchTabId);
                    }
                }

                sendLogToFrontend('crawl', '');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                sendLogToFrontend('crawl', '✓ [' + engine.name + '] 完成查询: "' + query + '"');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                sendLogToFrontend('crawl', '');

                // 检查是否应该停止（在完成当前搜索引擎后）
                if (shouldStopSearch()) {
                    sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                    break; // 跳出搜索引擎循环
                }
            }

            sendLogToFrontend('crawl', '');
            sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
            sendLogToFrontend('crawl', '✅ 完成查询 ' + (queryIndex + 1) + '/' + queries.length + ': "' + query + '"');
            sendLogToFrontend('crawl', '   已处理 ' + enginesToUse.length + ' 个搜索引擎');
            sendLogToFrontend('crawl', '═══════════════════════════════════════════════════════════');
            sendLogToFrontend('crawl', '');

            // 检查是否应该停止（在完成当前查询后）
            if (shouldStopSearch()) {
                sendLogToFrontend('crawl', '  → 停止搜索：5分钟内未找到有效客户');
                break; // 跳出查询循环
            }
        }

        // 检查是否因为超时停止
        const totalSearchTime = Date.now() - searchStartTime;
        const timeSinceLastValid = Date.now() - lastValidCustomerTime;
        if (timeSinceLastValid >= MAX_SEARCH_TIME) {
            const minutes = Math.floor(timeSinceLastValid / 60000);
            const seconds = Math.floor((timeSinceLastValid % 60000) / 1000);
            sendLogToFrontend('crawl', `  ⚠️ 搜索已停止：${minutes}分${seconds}秒内未找到有效客户`);
        }

        const logMsg3 = '【步骤2完成】共找到' + extractedData.length + '个候选结果';
        console.log('[AutoLeadAgent Extension]', logMsg3);
        sendLogToFrontend('crawl', logMsg3);

        // ========== 步骤3: 验证和去重 ==========
        const logMsg3Start = '【步骤3】开始验证和去重，当前候选数: ' + extractedData.length;
        console.log('[AutoLeadAgent Extension]', logMsg3Start);
        sendLogToFrontend('crawl', logMsg3Start);

        // 验证客户有效性
        const beforeValidate = extractedData.length;
        extractedData = validateAndFilterCustomers(extractedData);
        const afterValidate = extractedData.length;
        sendLogToFrontend('crawl', '  → 验证完成: ' + beforeValidate + ' 个候选 -> ' + afterValidate + ' 个有效客户');

        // 去重（基于URL或邮箱）
        sendLogToFrontend('crawl', '  → 开始去重...');
        const seenUrls = new Set();
        const seenEmails = new Set();
        const uniqueData = [];

        extractedData.forEach(customer => {
            const url = customer.profileUrl || customer.url || '';
            const email = customer.email || '';

            if (url && seenUrls.has(url)) return;
            if (email && seenEmails.has(email)) return;

            if (url) seenUrls.add(url);
            if (email) seenEmails.add(email);
            uniqueData.push(customer);
        });

        const beforeDedup = extractedData.length;
        extractedData = uniqueData;
        const afterDedup = extractedData.length;
        const dedupMsg = '【步骤3完成】去重完成: ' + beforeDedup + ' 个 -> ' + afterDedup + ' 个（去重 ' + (beforeDedup - afterDedup) + ' 个）';
        console.log('[AutoLeadAgent Extension]', dedupMsg);
        sendLogToFrontend('crawl', dedupMsg);

        // ========== 步骤4: 验证最终结果 ==========
        console.log('[AutoLeadAgent Extension] ===== 最终结果统计 =====');
        console.log('[AutoLeadAgent Extension] 提取到的客户数据总数:', extractedData.length);
        console.log('[AutoLeadAgent Extension] 已搜索的URL数量:', allSourceUrls.length);
        console.log('[AutoLeadAgent Extension] 被屏蔽的搜索引擎数量:', blockedEngines.length);

        if (extractedData.length > 0) {
            // 统计有效客户数量
            const validCustomers = extractedData.filter(customer => {
                return (customer.email && customer.email.trim()) ||
                    (customer.phone && customer.phone.trim()) ||
                    (customer.socialMediaAccounts && Object.keys(customer.socialMediaAccounts).length > 0);
            });
            console.log('[AutoLeadAgent Extension] 有效客户数量:', validCustomers.length);
            console.log('[AutoLeadAgent Extension] 无效客户数量:', extractedData.length - validCustomers.length);

            // 显示前几个客户的数据示例
            if (extractedData.length > 0) {
                console.log('[AutoLeadAgent Extension] 客户数据示例（前3个）:');
                extractedData.slice(0, 3).forEach((customer, idx) => {
                    console.log(`[AutoLeadAgent Extension] 客户 ${idx + 1}:`, {
                        hasEmail: !!(customer.email && customer.email.trim()),
                        hasPhone: !!(customer.phone && customer.phone.trim()),
                        hasSocialMedia: !!(customer.socialMediaAccounts && Object.keys(customer.socialMediaAccounts).length > 0),
                        profileUrl: customer.profileUrl,
                        source: customer.source
                    });
                });
            }
        }

        if (extractedData.length === 0) {
            let errorMessage = lastError
                ? lastError.message
                : '未找到有效客户（必须包含邮箱、手机号或社媒账户）';

            // 如果有被屏蔽的搜索引擎，添加提示
            if (blockedEngines.length > 0) {
                errorMessage += '。注意：以下搜索引擎可能已屏蔽访问：' + blockedEngines.join(', ');
                sendLogToFrontend('crawl', '⚠️ 可能被屏蔽的搜索引擎: ' + blockedEngines.join(', '));
                sendLogToFrontend('crawl', '💡 建议：等待一段时间后重试，或尝试其他搜索引擎');
            }

            console.error('[AutoLeadAgent Extension] 搜索失败:', errorMessage);
            console.error('[AutoLeadAgent Extension] 最后错误:', lastError ? JSON.stringify(lastError, Object.getOwnPropertyNames(lastError), 2) : 'null');
            console.error('[AutoLeadAgent Extension] 已搜索的URL:', JSON.stringify(allSourceUrls, null, 2));
            console.error('[AutoLeadAgent Extension] 可能被屏蔽的搜索引擎:', blockedEngines.length > 0 ? blockedEngines.join(', ') : '无');

            // 添加更详细的诊断信息
            if (allSourceUrls.length > 0) {
                console.error('[AutoLeadAgent Extension] 诊断信息:');
                console.error('[AutoLeadAgent Extension] - 搜索查询已执行，共 ' + allSourceUrls.length + ' 个查询');
                console.error('[AutoLeadAgent Extension] - 但未提取到任何客户数据');
                console.error('[AutoLeadAgent Extension] - 可能原因:');
                console.error('[AutoLeadAgent Extension]   1. 从搜索结果页面提取URL失败');
                console.error('[AutoLeadAgent Extension]   2. 访问提取到的URL后未找到联系信息');
                console.error('[AutoLeadAgent Extension]   3. 提取到的联系信息不符合有效客户标准');
            } else {
                console.error('[AutoLeadAgent Extension] 诊断信息:');
                console.error('[AutoLeadAgent Extension] - 没有执行任何搜索查询');
                console.error('[AutoLeadAgent Extension] - 可能原因: 查询生成失败或查询列表为空');
            }

            safeSendResponse({
                success: false,
                error: errorMessage,
                sourceUrls: allSourceUrls,
                blockedEngines: blockedEngines,
                errorType: blockedEngines.length > 0 ? 'blocked_by_search_engine' : (lastError ? 'crawl_error' : 'no_valid_customers'),
                errorDetails: lastError ? {
                    message: lastError.message,
                    stack: lastError.stack
                } : null
            });
            return;
        }

        // 为每个客户添加来源网址信息
        extractedData = extractedData.map(customer => ({
            ...customer,
            sourceUrls: allSourceUrls // 记录所有搜索过的网址
        }));

        console.log('[AutoLeadAgent Extension] 【完成】共找到', extractedData.length, '个有效客户');
        sendLogToFrontend('crawl', '✓ 本次搜索完成，找到 ' + extractedData.length + ' 个有效客户');

        // 扩展只管执行搜索并返回结果，是否继续搜索由外部（task-runner.js）控制
        cleanupHeartbeat(); // 清理心跳记录

        // 清理所有打开的标签页
        await cleanupAllTabs();

        safeSendResponse({
            success: true,
            data: extractedData,
            count: extractedData.length,
            sourceUrls: allSourceUrls,
            queriesUsed: queries,
            enginesUsed: enginesToUse.map(e => e.name)
        });
    } catch (error) {
        console.error('[AutoLeadAgent Extension] handleCrawlRequest 异常:', error);
        cleanupHeartbeat(); // 清理心跳记录

        // 清理所有打开的标签页
        await cleanupAllTabs();

        safeSendResponse({
            success: false,
            error: '爬取过程发生异常: ' + (error.message || '未知错误'),
            errorType: 'exception',
            errorStack: error.stack
        });
    }
}

/**
 * 验证并过滤客户数据
 * 有效客户必须至少包含以下信息之一：
 * - 邮箱地址
 * - 手机号码
 * - 社媒账户（至少一个平台）
 */
function validateAndFilterCustomers(customers) {
    const validCustomers = [];
    let emailCount = 0;
    let phoneCount = 0;
    let socialMediaCount = 0;

    for (const customer of customers) {
        // 检查是否包含有效信息
        const hasEmail = customer.email && customer.email.trim().length > 0;
        const hasPhone = customer.phone && customer.phone.trim().length > 0;
        const hasSocialMedia = customer.socialMediaAccounts &&
            Object.keys(customer.socialMediaAccounts).length > 0;

        // 至少需要一种联系方式
        if (hasEmail || hasPhone || hasSocialMedia) {
            // 确保有基本信息
            if (customer.profileUrl || customer.name) {
                validCustomers.push(customer);
                if (hasEmail) emailCount++;
                if (hasPhone) phoneCount++;
                if (hasSocialMedia) socialMediaCount++;
            }
        }
    }

    const validateMsg = '验证结果: ' + customers.length + ' 个候选 -> ' + validCustomers.length + ' 个有效客户';
    const detailMsg = '（含邮箱: ' + emailCount + ', 含手机: ' + phoneCount + ', 含社媒: ' + socialMediaCount + '）';
    console.log('[AutoLeadAgent Extension]', validateMsg, detailMsg);
    sendLogToFrontend('crawl', '  → ' + validateMsg + detailMsg);

    return validCustomers;
}

/**
 * 等待标签页加载完成（增强版：更长的超时时间和更好的错误处理）
 */
function waitForTabLoad(tabId, timeoutMs = 60000) {
    return new Promise((resolve, reject) => {
        // 先检查标签页是否已经加载完成
        chrome.tabs.get(tabId, (tab) => {
            if (chrome.runtime.lastError) {
                const errorMsg = '无法获取标签页: ' + chrome.runtime.lastError.message;
                console.error('[AutoLeadAgent Extension]', errorMsg);
                sendLogToFrontend('crawl', '  ❌ ' + errorMsg);
                reject(new Error(errorMsg));
                return;
            }

            if (tab.status === 'complete') {
                console.log('[AutoLeadAgent Extension] 标签页已加载完成');
                sendLogToFrontend('crawl', '  → 标签页已加载完成');
                resolve();
                return;
            }

            // 设置超时（默认60秒，对于慢速网络或复杂页面）
            const timeout = setTimeout(() => {
                chrome.tabs.onUpdated.removeListener(listener);
                const timeoutMsg = '页面加载超时（' + Math.floor(timeoutMs / 1000) + '秒），但将继续尝试提取数据';
                console.warn('[AutoLeadAgent Extension]', timeoutMsg);
                sendLogToFrontend('crawl', '  ⚠️ ' + timeoutMsg);
                // 不直接reject，而是resolve，让后续流程继续尝试
                resolve();
            }, timeoutMs);

            function listener(updatedTabId, info) {
                if (updatedTabId === tabId) {
                    // 检查加载状态
                    if (info.status === 'complete') {
                        clearTimeout(timeout);
                        chrome.tabs.onUpdated.removeListener(listener);
                        console.log('[AutoLeadAgent Extension] 标签页加载完成');
                        sendLogToFrontend('crawl', '  → 标签页加载完成');
                        resolve();
                    } else if (info.status === 'loading') {
                        console.log('[AutoLeadAgent Extension] 标签页加载中...');
                        // 不频繁发送日志，避免日志过多
                    }
                }
            }

            chrome.tabs.onUpdated.addListener(listener);
        });
    });
}

/**
 * 判断搜索结果是否是广告（注入到目标页面执行）
 * 使用端侧模型和规则判断
 */
function isAdvertisementResult(item, engine) {
    // 广告关键词（中文和英文）
    const adKeywords = [
        '广告', '推广', '营销', '竞价', 'Sponsored', 'Ad', 'Advertisement',
        '推广链接', '商业推广', '广告推广', 'Sponsored Links',
        '广告位', '推广位', '广告展示', '商业广告'
    ];

    // 广告URL特征
    const adUrlPatterns = [
        /\/ad[s]?\//i,
        /\/promo/i,
        /\/sponsored/i,
        /\/advertisement/i,
        /\/marketing/i,
        /adclick/i,
        /adserver/i,
        /doubleclick/i,
        /googleadservices/i
    ];

    // 广告class/id特征
    const adClassPatterns = [
        /ad[s]?/i,
        /sponsored/i,
        /promo/i,
        /advertisement/i,
        /commercial/i,
        /推广/i,
        /广告/i
    ];

    // 提取文本内容
    const itemText = item.innerText || item.textContent || '';
    const itemHtml = item.innerHTML || '';
    const itemClass = item.className || '';
    const itemId = item.id || '';

    // 检查class和id
    const hasAdClass = adClassPatterns.some(pattern =>
        pattern.test(itemClass) || pattern.test(itemId)
    );

    // 检查文本中的广告关键词
    const hasAdKeyword = adKeywords.some(keyword => {
        const lowerText = itemText.toLowerCase();
        const lowerHtml = itemHtml.toLowerCase();
        return lowerText.includes(keyword.toLowerCase()) ||
            lowerHtml.includes(keyword.toLowerCase());
    });

    // 检查URL
    let hasAdUrl = false;
    const links = item.querySelectorAll('a[href]');
    for (const link of links) {
        const href = link.href || link.getAttribute('href') || '';
        if (adUrlPatterns.some(pattern => pattern.test(href))) {
            hasAdUrl = true;
            break;
        }
    }

    // 检查是否有"广告"标识（百度、Google等常见标识）
    const adIndicators = [
        '广告', 'Ad', 'Sponsored', '推广',
        '[广告]', '(广告)', '(Ad)', '[Ad]',
        '商业推广', 'Sponsored Links'
    ];

    const hasAdIndicator = adIndicators.some(indicator => {
        const lowerText = itemText.toLowerCase();
        const lowerHtml = itemHtml.toLowerCase();
        return lowerText.includes(indicator.toLowerCase()) ||
            lowerHtml.includes(indicator.toLowerCase());
    });

    // 使用端侧模型进行更智能的判断
    // 构建判断文本
    const judgmentText = [
        itemText.substring(0, 200), // 前200个字符
        itemHtml.substring(0, 500)  // 前500个字符的HTML
    ].join(' ');

    // 简单的模型判断（基于特征）
    let modelScore = 0;

    // 特征1: 广告关键词密度
    const adKeywordCount = adKeywords.filter(keyword =>
        judgmentText.toLowerCase().includes(keyword.toLowerCase())
    ).length;
    modelScore += adKeywordCount * 0.3;

    // 特征2: URL特征
    if (hasAdUrl) {
        modelScore += 0.4;
    }

    // 特征3: Class/ID特征
    if (hasAdClass) {
        modelScore += 0.3;
    }

    // 特征4: 广告标识
    if (hasAdIndicator) {
        modelScore += 0.5;
    }

    // 特征5: 文本长度（广告通常较短）
    if (itemText.length < 50) {
        modelScore += 0.1;
    }

    // 特征6: 链接数量（广告通常链接较少）
    if (links.length <= 1) {
        modelScore += 0.1;
    }

    // 综合判断：如果模型分数 >= 0.5，认为是广告
    const isAd = modelScore >= 0.5 || hasAdIndicator || (hasAdClass && hasAdKeyword);

    return {
        isAd: isAd,
        score: modelScore,
        reasons: {
            hasAdKeyword: hasAdKeyword,
            hasAdUrl: hasAdUrl,
            hasAdClass: hasAdClass,
            hasAdIndicator: hasAdIndicator
        }
    };
}

/**
 * 从搜索引擎结果页面提取URL列表（注入到目标页面执行）
 * 只提取搜索结果URL，不提取详细信息（详细信息将在深度爬取时提取）
 * 使用端侧模型判断并跳过广告结果
 */
function extractSearchEngineResults(engine, platformConfig, maxResults, platform, matchKeywords) {
    console.log('[AutoLeadAgent] ===== extractSearchEngineResults 函数开始执行 =====');
    console.log('[AutoLeadAgent] 参数:', { engine: engine?.name, maxResults, platform, matchKeywords });

    const results = [];

    // 正则表达式（用于后续深度爬取）
    const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
    const phoneRegex = /(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}|\+?\d{10,15}/g;

    try {
        console.log('[AutoLeadAgent] 开始提取搜索结果...');
        // 检测是否被搜索引擎屏蔽
        const pageText = document.body ? document.body.innerText || document.body.textContent : '';
        const pageTitle = document.title || '';
        const pageUrl = window.location.href || '';

        // 检测验证码/屏蔽关键词（中文和英文）
        const blockKeywords = [
            '验证码', 'captcha', 'verify', '验证', '人机验证',
            '访问过于频繁', '访问频率', 'too many requests', 'rate limit',
            '请稍后再试', 'try again later', '暂时无法访问',
            '安全验证', 'security check', '安全检查',
            '机器人', 'robot', 'bot', '自动化',
            '异常访问', 'abnormal access', 'suspicious activity',
            'IP限制', 'IP限制', 'IP blocked', 'IP封禁'
        ];

        const hasBlockKeyword = blockKeywords.some(keyword => {
            const lowerText = pageText.toLowerCase();
            const lowerTitle = pageTitle.toLowerCase();
            return lowerText.includes(keyword.toLowerCase()) || lowerTitle.includes(keyword.toLowerCase());
        });

        // 检测验证码元素（常见的选择器）
        const captchaSelectors = [
            '#captcha', '.captcha', '[id*="captcha"]', '[class*="captcha"]',
            '#verify', '.verify', '[id*="verify"]', '[class*="verify"]',
            '#security-check', '.security-check',
            'iframe[src*="captcha"]', 'iframe[src*="verify"]'
        ];

        let hasCaptchaElement = false;
        for (const selector of captchaSelectors) {
            try {
                if (document.querySelector(selector)) {
                    hasCaptchaElement = true;
                    break;
                }
            } catch (e) {
                // 忽略选择器错误
            }
        }

        // 如果检测到验证码，返回错误（验证码处理应该在 background.js 中处理，而不是在注入函数中）
        if (hasCaptchaElement) {
            const blockReason = '检测到验证码页面';
            console.warn('[AutoLeadAgent] 搜索引擎屏蔽检测:', JSON.stringify({
                engine: engine.name,
                reason: blockReason,
                hasCaptchaElement: true,
                pageTitle: pageTitle,
                pageUrl: pageUrl
            }, null, 2));

            return [{
                error: true,
                errorType: 'blocked_by_search_engine',
                errorMessage: blockReason + '，可能被' + engine.name + '屏蔽',
                engine: engine.name,
                pageTitle: pageTitle,
                pageUrl: pageUrl,
                suggestion: '建议：1. 等待一段时间后重试 2. 更换搜索引擎 3. 检查网络连接 4. 手动完成验证码后重试'
            }];
        }

        // 如果检测到访问限制关键词（但不是验证码），返回错误
        if (hasBlockKeyword && !hasCaptchaElement) {
            const blockReason = '检测到访问限制提示';
            console.warn('[AutoLeadAgent] 搜索引擎屏蔽检测:', JSON.stringify({
                engine: engine.name,
                reason: blockReason,
                hasBlockKeyword: hasBlockKeyword,
                pageTitle: pageTitle,
                pageUrl: pageUrl
            }, null, 2));

            return [{
                error: true,
                errorType: 'blocked_by_search_engine',
                errorMessage: blockReason + '，可能被' + engine.name + '屏蔽',
                engine: engine.name,
                pageTitle: pageTitle,
                pageUrl: pageUrl,
                suggestion: '建议：1. 等待一段时间后重试 2. 更换搜索引擎 3. 检查网络连接'
            }];
        }

        // 直接提取Google搜索结果条目（class="MjjYud"等）
        console.log('[AutoLeadAgent] 开始提取搜索结果条目...');

        // 检查是否是空结果页面（正常情况）还是被屏蔽
        const isEmptyResult = pageText.includes('没有找到') ||
            pageText.includes('no results') ||
            pageText.includes('未找到相关') ||
            pageText.includes('抱歉，没有找到');

        let items = [];
        if (!isEmptyResult && pageText.length > 100) {
            // 方法1：直接使用Google搜索结果的选择器（最可靠）
            if (engine.name === 'Google') {
                // Google搜索结果条目的多种选择器（按优先级）
                const googleSelectors = [
                    // 最新的选择器（2024年）
                    'div[data-sokoban-feature]',
                    'div[jscontroller]',
                    'div[data-ved]',
                    // 传统选择器
                    '.g',
                    '.tF2Cxc',
                    '.MjjYud',
                    '.g-blk',
                    // 更通用的选择器
                    'div[data-hveid]',
                    'div[data-ved][data-hveid]'
                ];

                // 尝试每个选择器，直到找到结果
                for (const selector of googleSelectors) {
                    try {
                        items = document.querySelectorAll(selector);
                        if (items.length > 0) {
                            console.log('[AutoLeadAgent] Google选择器 "' + selector + '" 找到 ' + items.length + ' 个结果条目');
                            break;
                        }
                    } catch (e) {
                        // 选择器无效，继续下一个
                        continue;
                    }
                }

                // 如果标准选择器找不到，尝试查找所有包含外部链接的div
                if (items.length === 0) {
                    console.log('[AutoLeadAgent] 标准选择器未找到，尝试查找包含外部链接的div...');
                    const allDivs = document.querySelectorAll('div');
                    const resultDivs = [];
                    const seenDivs = new Set();

                    allDivs.forEach(div => {
                        if (seenDivs.has(div)) return;

                        // 查找div内的所有a标签
                        const links = div.querySelectorAll('a[href]');
                        for (const link of links) {
                            let href = link.href || link.getAttribute('href') || '';

                            // 处理Google的/url?q=重定向（多种格式）
                            if (href.includes('/url?q=')) {
                                try {
                                    const urlParams = new URLSearchParams(href.split('?')[1]);
                                    href = urlParams.get('q') || href;
                                } catch (e) { }
                            }

                            // 处理 data-href 和 data-url 属性
                            if (!href || href.startsWith('javascript:')) {
                                href = link.getAttribute('data-href') || link.getAttribute('data-url') || href;
                            }

                            // 如果是外部链接（不是Google自己的），这个div就是结果条目
                            if (href && href.startsWith('http') &&
                                !href.includes('google.com/search') &&
                                !href.includes('google.com/url') &&
                                !href.includes('google.com/maps') &&
                                !href.includes('google.com/images')) {
                                resultDivs.push(div);
                                seenDivs.add(div);
                                break; // 找到一个链接就够了
                            }
                        }
                    });
                    items = resultDivs;
                    console.log('[AutoLeadAgent] 通过链接查找找到 ' + items.length + ' 个结果条目');
                }
            }

            // 如果Google选择器没找到，跳过模型分析，直接使用备用方法
            // 注意：findSearchResultItemsByModel 在注入时不可用，会导致函数执行失败
            if (items.length === 0) {
                console.log('[AutoLeadAgent] 标准选择器未找到结果，将使用备用方法...');
            }

            // 如果模型也没找到，使用备用方法
            if (items.length === 0) {
                console.warn('[AutoLeadAgent] 端侧模型未找到搜索结果项，尝试备用方法...');

                // 备用方案1：使用更宽松的选择器直接查找所有包含外部链接的元素
                console.log('[AutoLeadAgent] 备用方法1：查找所有包含外部链接的元素...');
                const allLinks = document.querySelectorAll('a[href]');
                const linkContainers = new Set();
                const validLinksMap = new Map(); // 存储链接到容器的映射
                let validLinksCount = 0;

                // 搜索引擎域名列表（用于过滤）
                const searchEngineDomains = [
                    'google.com', 'baidu.com', 'bing.com', 'yahoo.com',
                    'duckduckgo.com', 'yandex.com', 'sogou.com', 'so.com'
                ];

                allLinks.forEach(link => {
                    let href = link.href || link.getAttribute('href') || '';
                    const originalHref = href;

                    // 处理Google的 /url?q= 重定向格式（多种解析方式）
                    if (href.includes('/url?q=')) {
                        try {
                            const urlParams = new URLSearchParams(href.split('?')[1]);
                            href = urlParams.get('q') || href;
                        } catch (e) {
                            // 解析失败，尝试正则表达式提取
                            const match = href.match(/[?&]q=([^&]+)/);
                            if (match) {
                                try {
                                    href = decodeURIComponent(match[1]);
                                } catch (e2) {
                                    // 解码失败，使用原始链接
                                }
                            }
                        }
                    }

                    // 处理 data-href 和 data-url 属性（Google有时使用这些属性）
                    if (!href || href.startsWith('javascript:') || href.startsWith('#')) {
                        href = link.getAttribute('data-href') ||
                            link.getAttribute('data-url') ||
                            link.getAttribute('data-ved') ||
                            href;
                    }

                    // 处理相对链接
                    if (href.startsWith('/')) {
                        href = window.location.origin + href;
                    }

                    // 处理协议相对URL
                    if (href.startsWith('//')) {
                        href = 'https:' + href;
                    }

                    // 验证URL有效性并排除搜索引擎自己的链接
                    let isValidExternalLink = false;
                    if (href && href.startsWith('http')) {
                        try {
                            const urlObj = new URL(href);
                            const hostname = urlObj.hostname.toLowerCase();

                            // 检查是否是搜索引擎域名
                            const isSearchEngine = searchEngineDomains.some(domain =>
                                hostname === domain || hostname.endsWith('.' + domain)
                            );

                            // 排除搜索引擎域名和常见的内链
                            if (!isSearchEngine &&
                                !hostname.includes('googleusercontent.com') &&
                                !hostname.includes('gstatic.com') &&
                                !urlObj.pathname.includes('/search') &&
                                !urlObj.pathname.includes('/url')) {
                                isValidExternalLink = true;
                            }
                        } catch (e) {
                            // URL解析失败，跳过
                        }
                    }

                    if (isValidExternalLink) {
                        validLinksCount++;

                        // 计算链接的重要性评分（用于排序）
                        const linkText = (link.textContent || link.innerText || '').trim();
                        const linkTitle = link.getAttribute('title') || '';
                        let importanceScore = 0;

                        // 链接文本长度（合理的标题长度）
                        if (linkText.length >= 10 && linkText.length <= 200) {
                            importanceScore += 2;
                        }

                        // 链接位置（在页面中的位置，越靠前越重要）
                        const rect = link.getBoundingClientRect();
                        if (rect.top < window.innerHeight * 2) {
                            importanceScore += 1;
                        }

                        // 找到链接的父容器（向上查找5层，更深入）
                        let container = link.parentElement;
                        for (let i = 0; i < 5 && container; i++) {
                            if (container.tagName === 'DIV' ||
                                container.tagName === 'ARTICLE' ||
                                container.tagName === 'SECTION' ||
                                container.tagName === 'LI') {
                                // 确保容器有足够的文本内容（至少20个字符）
                                const containerText = container.textContent || container.innerText || '';
                                if (containerText.length >= 20) {
                                    linkContainers.add(container);
                                    // 存储链接到容器的映射，确保容器能正确提取URL
                                    if (!validLinksMap.has(container)) {
                                        validLinksMap.set(container, []);
                                    }
                                    validLinksMap.get(container).push({
                                        element: link,
                                        href: href,
                                        importanceScore: importanceScore,
                                        linkText: linkText || linkTitle
                                    });
                                    break;
                                }
                            }
                            container = container.parentElement;
                        }
                    }
                });

                // 按重要性评分排序链接
                linkContainers.forEach(container => {
                    const links = validLinksMap.get(container);
                    if (links && links.length > 0) {
                        links.sort((a, b) => b.importanceScore - a.importanceScore);
                    }
                });

                console.log('[AutoLeadAgent] 备用方法1：找到 ' + validLinksCount + ' 个有效外部链接，' + linkContainers.size + ' 个容器');

                // 为每个容器添加链接信息，确保后续能正确提取URL
                items = Array.from(linkContainers).map(container => {
                    // 创建一个增强的容器对象，确保能正确提取链接
                    const links = validLinksMap.get(container) || [];
                    if (links.length > 0) {
                        // 为容器添加一个标记，表示它包含有效链接
                        container._validLinks = links;
                    }
                    return container;
                });

                // 如果备用方法1还是找不到，尝试备用方法2：直接使用所有包含facebook.com的链接
                if (items.length === 0) {
                    console.log('[AutoLeadAgent] 备用方法2：直接提取所有包含facebook.com的链接...');
                    const facebookLinks = [];
                    allLinks.forEach(link => {
                        let href = link.href || link.getAttribute('href') || '';
                        if (href.includes('/url?q=')) {
                            try {
                                const urlParams = new URLSearchParams(href.split('?')[1]);
                                href = urlParams.get('q') || href;
                            } catch (e) { }
                        }
                        if (href.includes('facebook.com') && !href.includes('google.com')) {
                            facebookLinks.push({
                                url: href,
                                title: link.textContent.trim() || '',
                                element: link
                            });
                        }
                    });
                    console.log('[AutoLeadAgent] 备用方法2找到 ' + facebookLinks.length + ' 个Facebook链接');

                    // 如果找到Facebook链接，创建虚拟容器
                    if (facebookLinks.length > 0) {
                        facebookLinks.forEach((linkInfo, idx) => {
                            // 创建一个虚拟的容器对象，包含链接信息
                            const virtualContainer = {
                                querySelector: function (selector) {
                                    if (selector === 'a[href]' || selector === 'a') {
                                        return linkInfo.element;
                                    }
                                    return linkInfo.element.querySelector(selector);
                                },
                                querySelectorAll: function (selector) {
                                    return linkInfo.element.querySelectorAll(selector);
                                },
                                textContent: linkInfo.element.textContent || linkInfo.title,
                                innerText: linkInfo.element.innerText || linkInfo.title,
                                innerHTML: linkInfo.element.innerHTML || '',
                                getAttribute: function (attr) {
                                    return linkInfo.element.getAttribute(attr);
                                },
                                tagName: 'DIV',
                                className: 'virtual-container',
                                id: 'virtual-' + idx
                            };
                            items.push(virtualContainer);
                        });
                    }
                }

                console.log('[AutoLeadAgent] 备用方法最终找到 ' + items.length + ' 个可能的搜索结果容器');

                if (items.length === 0) {
                    console.error('[AutoLeadAgent] 所有方法都未找到搜索结果项:', {
                        engine: engine.name,
                        pageTitle: pageTitle,
                        pageUrl: pageUrl,
                        bodyTextLength: pageText.length,
                        allLinksCount: allLinks.length,
                        validLinksCount: validLinksCount,
                        pageTextPreview: pageText.substring(0, 500)
                    });

                    // 页面有内容但找不到结果项，可能是被屏蔽或页面结构变化
                    return [{
                        error: true,
                        errorType: 'no_results_found',
                        errorMessage: '未找到搜索结果项，可能页面结构已变化或被屏蔽',
                        engine: engine.name,
                        pageTitle: pageTitle,
                        pageUrl: pageUrl,
                        suggestion: '建议：1. 检查搜索引擎页面结构是否变化 2. 尝试其他搜索引擎 3. 检查是否需要验证码 4. 检查浏览器控制台查看详细日志'
                    }];
                }
            }
        } else if (isEmptyResult) {
            // 空结果页面，正常情况
            console.log('[AutoLeadAgent] 检测到空结果页面（正常情况）');
            return [];
        } else {
            // 页面内容太少，可能是加载不完整
            console.warn('[AutoLeadAgent] 页面内容太少，可能加载不完整:', {
                engine: engine.name,
                pageTitle: pageTitle,
                pageUrl: pageUrl,
                bodyTextLength: pageText.length
            });
            return [{
                error: true,
                errorType: 'page_content_too_short',
                errorMessage: '页面内容太少，可能加载不完整',
                engine: engine.name,
                pageTitle: pageTitle,
                pageUrl: pageUrl,
                suggestion: '建议：1. 检查网络连接 2. 等待页面完全加载 3. 重试'
            }];
        }

        console.log('[AutoLeadAgent] Found search results:', items.length, 'from', engine.name);
        console.log('[AutoLeadAgent] 开始从 ' + items.length + ' 个结果条目中提取链接...');

        // 只提取URL列表，详细信息将在深度爬取时提取
        // 使用端侧模型判断并跳过广告结果
        let adCount = 0;
        let processedCount = 0;
        let extractedCount = 0;
        for (let i = 0; i < Math.min(items.length, maxResults * 2); i++) { // 多提取一些，因为会过滤广告
            const item = items[i];
            processedCount++;
            console.log('[AutoLeadAgent] 处理结果条目 #' + (i + 1) + '/' + Math.min(items.length, maxResults * 2));

            // 使用端侧模型判断是否是广告（内联版本，避免调用外部函数）
            // 广告关键词（中文和英文）
            const adKeywords = [
                '广告', '推广', '营销', '竞价', 'Sponsored', 'Ad', 'Advertisement',
                '推广链接', '商业推广', '广告推广', 'Sponsored Links',
                '广告位', '推广位', '广告展示', '商业广告'
            ];

            // 广告URL特征
            const adUrlPatterns = [
                /\/ad[s]?\//i,
                /\/promo/i,
                /\/sponsored/i,
                /\/advertisement/i,
                /\/marketing/i,
                /adclick/i,
                /adserver/i,
                /doubleclick/i,
                /googleadservices/i
            ];

            // 广告class/id特征
            const adClassPatterns = [
                /ad[s]?/i,
                /sponsored/i,
                /promo/i,
                /advertisement/i,
                /commercial/i,
                /推广/i,
                /广告/i
            ];

            // 提取文本内容
            const itemText = item.innerText || item.textContent || '';
            const itemHtml = item.innerHTML || '';
            const itemClass = item.className || '';
            const itemId = item.id || '';

            // 检查class和id
            const hasAdClass = adClassPatterns.some(pattern =>
                pattern.test(itemClass) || pattern.test(itemId)
            );

            // 检查文本中的广告关键词
            const hasAdKeyword = adKeywords.some(keyword => {
                const lowerText = itemText.toLowerCase();
                const lowerHtml = itemHtml.toLowerCase();
                return lowerText.includes(keyword.toLowerCase()) ||
                    lowerHtml.includes(keyword.toLowerCase());
            });

            // 检查URL
            let hasAdUrl = false;
            const links = item.querySelectorAll('a[href]');
            for (const link of links) {
                const href = link.href || link.getAttribute('href') || '';
                if (adUrlPatterns.some(pattern => pattern.test(href))) {
                    hasAdUrl = true;
                    break;
                }
            }

            // 检查是否有"广告"标识
            const adIndicators = [
                '广告', 'Ad', 'Sponsored', '推广',
                '[广告]', '(广告)', '(Ad)', '[Ad]',
                '商业推广', 'Sponsored Links'
            ];

            const hasAdIndicator = adIndicators.some(indicator => {
                const lowerText = itemText.toLowerCase();
                const lowerHtml = itemHtml.toLowerCase();
                return lowerText.includes(indicator.toLowerCase()) ||
                    lowerHtml.includes(indicator.toLowerCase());
            });

            // 简单的模型判断（基于特征）
            let modelScore = 0;
            const judgmentText = [
                itemText.substring(0, 200),
                itemHtml.substring(0, 500)
            ].join(' ');

            // 特征1: 广告关键词密度
            const adKeywordCount = adKeywords.filter(keyword =>
                judgmentText.toLowerCase().includes(keyword.toLowerCase())
            ).length;
            modelScore += adKeywordCount * 0.3;

            // 特征2-6
            if (hasAdUrl) modelScore += 0.4;
            if (hasAdClass) modelScore += 0.3;
            if (hasAdIndicator) modelScore += 0.5;
            if (itemText.length < 50) modelScore += 0.1;
            if (links.length <= 1) modelScore += 0.1;

            // 综合判断：如果模型分数 >= 0.5，认为是广告
            const isAd = modelScore >= 0.5 || hasAdIndicator || (hasAdClass && hasAdKeyword);

            if (isAd) {
                adCount++;
                console.log('[AutoLeadAgent] 跳过广告结果 #' + processedCount + ':', {
                    score: modelScore.toFixed(2),
                    hasAdKeyword: hasAdKeyword,
                    hasAdUrl: hasAdUrl,
                    hasAdClass: hasAdClass,
                    hasAdIndicator: hasAdIndicator
                });
                continue; // 跳过广告结果
            }

            // 如果已经找到足够的非广告结果，停止
            if (results.length >= maxResults) {
                break;
            }

            const resultItem = {
                url: null,
                title: '',
                snippet: '',
                extractedAt: new Date().toISOString(),
                source: engine.name
            };

            // 提取标题和链接（百度需要特殊处理）
            if (engine.name === 'Baidu') {
                // 百度：标题通常在 h3 或 a 标签中
                const titleEl = item.querySelector('h3 a, .t a, a[data-click]');
                if (titleEl) {
                    resultItem.title = titleEl.textContent.trim();
                    resultItem.url = titleEl.href || titleEl.getAttribute('href');
                }

                // 如果没有找到，尝试其他方式
                if (!resultItem.url) {
                    const linkEl = item.querySelector('a[href^="http"]');
                    if (linkEl) {
                        resultItem.url = linkEl.href || linkEl.getAttribute('href');
                        if (!resultItem.title) {
                            resultItem.title = linkEl.textContent.trim();
                        }
                    }
                }

                // 提取摘要（百度）
                const snippetEl = item.querySelector('.content-right_8Zs40, .c-abstract, .c-span9');
                if (snippetEl) {
                    resultItem.snippet = snippetEl.textContent.trim();
                }
            } else if (engine.name === 'Google') {
                // Google 特殊处理：尝试多个选择器
                // 标题可能在 h3 或 a 标签中
                const titleSelectors = ['h3', 'h3 a', 'a h3', '.LC20lb', '.DKV0Md'];
                for (const selector of titleSelectors) {
                    const titleEl = item.querySelector(selector);
                    if (titleEl) {
                        resultItem.title = titleEl.textContent.trim();
                        break;
                    }
                }

                // 优先使用备用方法1存储的链接信息（如果存在）
                if (item._validLinks && item._validLinks.length > 0) {
                    console.log('[AutoLeadAgent] 结果条目 #' + (i + 1) + ' 使用备用方法1存储的链接，共 ' + item._validLinks.length + ' 个');
                    const linkInfo = item._validLinks[0]; // 使用第一个有效链接
                    resultItem.url = linkInfo.href;
                    console.log('[AutoLeadAgent] 提取到链接: ' + linkInfo.href);

                    // 如果还没有标题，尝试从链接元素获取
                    if (!resultItem.title && linkInfo.element) {
                        const linkText = linkInfo.element.textContent.trim();
                        if (linkText && linkText.length > 5 && linkText.length < 200) {
                            resultItem.title = linkText;
                        } else {
                            // 尝试从父元素获取标题
                            const parentTitle = linkInfo.element.closest('h1, h2, h3, h4, h5, h6');
                            if (parentTitle) {
                                resultItem.title = parentTitle.textContent.trim();
                            }
                        }
                    }
                } else {
                    // 直接从结果条目div中提取所有a标签链接（这是关键！）
                    // 从你提供的图片看，结果条目是class="MjjYud"的div，里面包含a标签
                    const allLinks = item.querySelectorAll('a[href]');
                    console.log('[AutoLeadAgent] 结果条目 #' + (i + 1) + ' 找到 ' + allLinks.length + ' 个a标签');

                    for (const link of allLinks) {
                        let href = link.href || link.getAttribute('href') || '';
                        const originalHref = href;

                        // 处理Google的/url?q=重定向格式（多种解析方式）
                        if (href.includes('/url?q=')) {
                            try {
                                const urlParams = new URLSearchParams(href.split('?')[1]);
                                href = urlParams.get('q') || href;
                                console.log('[AutoLeadAgent] 解析重定向链接: ' + originalHref + ' -> ' + href);
                            } catch (e) {
                                // 解析失败，尝试正则表达式提取
                                const match = href.match(/[?&]q=([^&]+)/);
                                if (match) {
                                    try {
                                        href = decodeURIComponent(match[1]);
                                        console.log('[AutoLeadAgent] 使用正则解析重定向链接: ' + originalHref + ' -> ' + href);
                                    } catch (e2) {
                                        console.warn('[AutoLeadAgent] 解析重定向链接失败:', e2);
                                    }
                                } else {
                                    console.warn('[AutoLeadAgent] 解析重定向链接失败:', e);
                                }
                            }
                        }

                        // 处理 data-href 和 data-url 属性
                        if (!href || href.startsWith('javascript:') || href.startsWith('#')) {
                            href = link.getAttribute('data-href') ||
                                link.getAttribute('data-url') ||
                                href;
                        }

                        // 处理协议相对URL
                        if (href.startsWith('//')) {
                            href = 'https:' + href;
                        }

                        // 处理相对链接
                        if (href.startsWith('/') && !href.startsWith('//')) {
                            href = window.location.origin + href;
                        }

                        // 验证URL有效性并过滤掉Google自己的链接
                        let isValidExternalLink = false;
                        if (href && href.startsWith('http')) {
                            try {
                                const urlObj = new URL(href);
                                const hostname = urlObj.hostname.toLowerCase();

                                // 排除Google域名和常见的内链
                                if (!hostname.includes('google.com') &&
                                    !hostname.includes('googleusercontent.com') &&
                                    !hostname.includes('gstatic.com') &&
                                    !urlObj.pathname.includes('/search') &&
                                    !urlObj.pathname.includes('/url') &&
                                    !urlObj.pathname.includes('/maps')) {
                                    isValidExternalLink = true;
                                }
                            } catch (e) {
                                // URL解析失败，跳过
                            }
                        }

                        if (isValidExternalLink) {
                            resultItem.url = href;
                            console.log('[AutoLeadAgent] 提取到链接: ' + href);

                            // 如果还没有标题，尝试从链接文本获取
                            if (!resultItem.title) {
                                const linkText = (link.textContent || link.innerText || '').trim();
                                const linkTitle = link.getAttribute('title') || '';

                                if (linkText && linkText.length > 5 && linkText.length < 200) {
                                    resultItem.title = linkText;
                                } else if (linkTitle && linkTitle.length > 5 && linkTitle.length < 200) {
                                    resultItem.title = linkTitle;
                                } else {
                                    // 尝试从父元素获取标题
                                    const parentTitle = link.closest('h1, h2, h3, h4, h5, h6');
                                    if (parentTitle) {
                                        const parentText = (parentTitle.textContent || parentTitle.innerText || '').trim();
                                        if (parentText && parentText.length > 5 && parentText.length < 200) {
                                            resultItem.title = parentText;
                                        }
                                    }
                                }
                            }
                            break; // 找到第一个有效链接就够了
                        }
                    }
                }

                // 提取摘要/描述
                const snippetSelectors = ['.VwiC3b', '.IsZvec', '.s', '.st', '.MUxGbd'];
                for (const selector of snippetSelectors) {
                    const snippetEl = item.querySelector(selector);
                    if (snippetEl) {
                        resultItem.snippet = snippetEl.textContent.trim();
                        break;
                    }
                }
            } else {
                // 其他搜索引擎使用标准选择器
                const titleEl = item.querySelector(engine.titleSelector);
                if (titleEl) {
                    resultItem.title = titleEl.textContent.trim();
                }

                // 提取链接
                const linkEl = item.querySelector(engine.linkSelector);
                if (linkEl) {
                    resultItem.url = linkEl.href || linkEl.getAttribute('href');
                }

                // 提取摘要/描述
                const snippetEl = item.querySelector(engine.snippetSelector);
                if (snippetEl) {
                    resultItem.snippet = snippetEl.textContent.trim();
                }
            }

            // 如果通过标准选择器没有找到信息，使用通用方法提取（适用于模型找到的元素）
            if (!resultItem.url || !resultItem.title) {
                // 优先使用备用方法1存储的链接信息（如果存在）
                if (item._validLinks && item._validLinks.length > 0) {
                    const linkInfo = item._validLinks[0]; // 使用第一个有效链接
                    if (!resultItem.url) {
                        resultItem.url = linkInfo.href;
                    }
                    // 如果还没有标题，尝试从链接元素获取
                    if (!resultItem.title && linkInfo.element) {
                        const linkText = linkInfo.element.textContent.trim();
                        if (linkText && linkText.length > 5 && linkText.length < 200) {
                            resultItem.title = linkText;
                        } else {
                            // 尝试从父元素获取标题
                            const parentTitle = linkInfo.element.closest('h1, h2, h3, h4, h5, h6');
                            if (parentTitle) {
                                resultItem.title = parentTitle.textContent.trim();
                            }
                        }
                    }
                } else {
                    // 尝试从整个item中查找所有外部链接
                    const allLinks = item.querySelectorAll('a[href^="http"]');
                    for (const link of allLinks) {
                        const href = link.href || link.getAttribute('href') || '';
                        // 排除搜索引擎自己的链接
                        if (href &&
                            !href.includes('google.com/search') &&
                            !href.includes('google.com/url') &&
                            !href.includes('google.com/maps') &&
                            !href.includes('baidu.com') &&
                            !href.includes('bing.com')) {
                            if (!resultItem.url) {
                                resultItem.url = href;
                            }
                            // 如果还没有标题，尝试从链接文本或父元素获取
                            if (!resultItem.title) {
                                const linkText = link.textContent.trim();
                                if (linkText && linkText.length > 5 && linkText.length < 200) {
                                    resultItem.title = linkText;
                                } else {
                                    // 尝试从父元素获取标题
                                    const parentTitle = link.closest('h1, h2, h3, h4, h5, h6');
                                    if (parentTitle) {
                                        resultItem.title = parentTitle.textContent.trim();
                                    }
                                }
                            }
                            break;
                        }
                    }
                }

                // 如果还没有标题，尝试从h1-h6元素获取
                if (!resultItem.title) {
                    const titleElements = item.querySelectorAll('h1, h2, h3, h4, h5, h6');
                    for (const titleEl of titleElements) {
                        const titleText = titleEl.textContent.trim();
                        if (titleText && titleText.length > 5 && titleText.length < 200) {
                            resultItem.title = titleText;
                            break;
                        }
                    }
                }

                // 如果还没有摘要，尝试从段落或div中提取描述性文本
                if (!resultItem.snippet) {
                    const textContent = item.textContent || item.innerText || '';
                    // 提取前200个字符作为摘要
                    if (textContent.length > 50) {
                        resultItem.snippet = textContent.substring(0, 200).trim();
                    }
                }
            }

            // 处理相对URL
            if (resultItem.url) {
                if (resultItem.url.startsWith('/')) {
                    resultItem.url = window.location.origin + resultItem.url;
                }
                // 只返回有URL的结果
                resultItem.index = results.length + 1; // 添加索引用于日志
                results.push(resultItem);
                extractedCount++;

                // 在控制台输出每个结果的详细信息（用于调试）
                console.log('[AutoLeadAgent] ✅ 提取到结果 #' + resultItem.index + ':', {
                    title: resultItem.title ? (resultItem.title.substring(0, 50) + (resultItem.title.length > 50 ? '...' : '')) : '无标题',
                    url: resultItem.url,
                    snippet: resultItem.snippet ? (resultItem.snippet.substring(0, 100) + (resultItem.snippet.length > 100 ? '...' : '')) : '无摘要'
                });
            } else {
                // 如果没有提取到URL，记录警告
                console.warn('[AutoLeadAgent] ❌ 结果项 #' + (i + 1) + ' 未提取到URL:', {
                    hasTitle: !!resultItem.title,
                    hasSnippet: !!resultItem.snippet,
                    itemTagName: item.tagName,
                    itemClassName: item.className,
                    itemId: item.id,
                    itemHtml: item.innerHTML.substring(0, 300)
                });
            }
        }

        console.log('[AutoLeadAgent] ===== 提取完成 =====');
        console.log('[AutoLeadAgent] 处理了 ' + processedCount + ' 个结果条目');
        console.log('[AutoLeadAgent] 跳过了 ' + adCount + ' 个广告结果');
        console.log('[AutoLeadAgent] 成功提取 ' + extractedCount + ' 个有效结果URL');
        console.log('[AutoLeadAgent] 最终返回 ' + results.length + ' 个结果');

        if (adCount > 0) {
            console.log('[AutoLeadAgent] Skipped', adCount, 'advertisement results from', engine.name);
            // 在结果中添加广告统计信息（用于日志输出）
            if (results.length > 0) {
                results[0].adCount = adCount;
                results[0].totalProcessed = processedCount;
            }
        }

        // 如果提取到0个结果，输出详细调试信息
        if (results.length === 0 && items.length > 0) {
            console.error('[AutoLeadAgent] ⚠️ 警告：找到 ' + items.length + ' 个结果条目，但提取到 0 个URL！');
            console.error('[AutoLeadAgent] 可能原因：1. a标签链接格式特殊 2. 重定向链接解析失败 3. 链接被过滤');
        }

    } catch (error) {
        console.error('[AutoLeadAgent] Extract error:', error);
        console.error('[AutoLeadAgent] Error stack:', error.stack);
        console.error('[AutoLeadAgent] Error details:', {
            message: error.message,
            name: error.name,
            stack: error.stack,
            pageTitle: document.title,
            pageUrl: window.location.href
        });
        // 直接抛出严重错误，不返回空数组
        throw new Error('提取搜索结果时发生严重错误: ' + error.message + ' | Stack: ' + error.stack);
    }

    return results;
}

/**
 * 处理内容脚本提取的数据
 */
function handleExtractedData(data, taskId) {
    console.log('[AutoLeadAgent Extension] Received extracted data for task:', taskId, data);
    // 可以在这里存储或转发数据
}

/**
 * 提取页面所有a标签链接（注入到目标页面执行）
 */
function extractPageLinks() {
    const links = [];
    const anchors = document.querySelectorAll('a[href]');

    anchors.forEach(anchor => {
        const href = anchor.href || anchor.getAttribute('href');
        if (href && href.startsWith('http')) {
            try {
                const url = new URL(href);
                // 只提取同域名的链接
                if (url.origin === window.location.origin) {
                    links.push({
                        url: href,
                        text: anchor.textContent.trim(),
                        title: anchor.title || anchor.textContent.trim()
                    });
                }
            } catch (e) {
                // 忽略无效URL
            }
        }
    });

    return links;
}

/**
 * 识别页面类型（注入到目标页面执行）
 */
function identifyPageType() {
    const url = window.location.href.toLowerCase();
    const path = window.location.pathname.toLowerCase();
    const title = (document.title || '').toLowerCase();
    const pageText = (document.body ? document.body.innerText || document.body.textContent : '').toLowerCase();

    // 关键页面类型关键词
    const pageTypePatterns = {
        'about': ['about', '关于', '关于我们', 'about us', 'about-me', 'company', '公司介绍'],
        'contact': ['contact', '联系', '联系我们', 'contact us', '联系方式', 'reach us'],
        'team': ['team', '团队', '团队成员', 'our team', 'staff', '员工'],
        'home': ['home', '首页', '主页', 'index'],
        'product': ['product', '产品', 'products', '服务', 'services', 'solutions'],
        'blog': ['blog', '博客', 'news', '新闻', 'article', '文章']
    };

    let maxScore = 0;
    let detectedType = 'other';

    for (const [type, keywords] of Object.entries(pageTypePatterns)) {
        let score = 0;

        // URL匹配
        if (keywords.some(kw => url.includes(kw) || path.includes(kw))) {
            score += 3;
        }

        // 标题匹配
        if (keywords.some(kw => title.includes(kw))) {
            score += 2;
        }

        // 内容匹配
        if (keywords.some(kw => pageText.includes(kw))) {
            score += 1;
        }

        if (score > maxScore) {
            maxScore = score;
            detectedType = type;
        }
    }

    return detectedType;
}

/**
 * 提取当前页面的客户信息（注入到目标页面执行）
 * 增强版：提取更多信息类型
 */
function extractCustomerInfo() {
    const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
    const phoneRegex = /(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}|\+?\d{10,15}/g;

    // 社媒平台URL模式
    const socialMediaPatterns = {
        linkedin: /linkedin\.com\/(in|pub|company|school)\/([^\/\s"']+)/gi,
        twitter: /(twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/gi,
        facebook: /facebook\.com\/([a-zA-Z0-9.]+)/gi,
        instagram: /instagram\.com\/([a-zA-Z0-9_.]+)/gi,
        youtube: /youtube\.com\/(channel\/|user\/|@)([a-zA-Z0-9_-]+)/gi,
        github: /github\.com\/([a-zA-Z0-9_-]+)/gi,
        tiktok: /tiktok\.com\/@([a-zA-Z0-9_.]+)/gi,
        weibo: /weibo\.com\/([a-zA-Z0-9_]+)/gi,
        zhihu: /zhihu\.com\/(people|org)\/([a-zA-Z0-9_-]+)/gi
    };

    const pageText = document.body ? document.body.innerText || document.body.textContent : '';
    const pageHtml = document.body ? document.body.innerHTML : '';
    const pageTitle = document.title || '';
    const pageUrl = window.location.href;
    const domain = window.location.hostname;

    // 识别页面类型
    const pageType = identifyPageType();

    const customerInfo = {
        emails: [],
        phones: [],
        socialMediaAccounts: {},
        extractedAt: new Date().toISOString(),
        url: pageUrl,
        pageType: pageType,
        // 新增字段
        companyName: null,
        contactName: null,
        jobTitle: null,
        companySize: null,
        industry: null,
        location: null,
        website: domain,
        foundedYear: null
    };

    // 提取邮箱
    const emailMatches = pageText.match(emailRegex);
    if (emailMatches) {
        const validEmails = emailMatches.filter(email =>
            !email.includes('@example.com') &&
            !email.includes('@domain.com') &&
            email.length < 100 &&
            !email.includes('image') &&
            !email.includes('icon')
        );
        customerInfo.emails = [...new Set(validEmails)]; // 去重
    }

    // 提取手机号
    const phoneMatches = pageText.match(phoneRegex);
    if (phoneMatches) {
        const validPhones = phoneMatches.filter(phone => {
            const cleaned = phone.replace(/[\s\-\(\)]/g, '');
            return cleaned.length >= 10 && cleaned.length <= 15;
        });
        customerInfo.phones = [...new Set(validPhones.map(p => p.trim()))]; // 去重
    }

    // 提取社媒账户
    for (const [platformName, pattern] of Object.entries(socialMediaPatterns)) {
        const matches = pageText.match(pattern) || pageHtml.match(pattern);
        if (matches && matches.length > 0) {
            const accounts = matches.map(match => {
                const urlMatch = match.match(/https?:\/\/([^\s"']+)/i);
                return urlMatch ? urlMatch[0] : match;
            });
            if (accounts.length > 0) {
                customerInfo.socialMediaAccounts[platformName] = [...new Set(accounts)];
            }
        }
    }

    // 特殊处理：如果是社交媒体平台页面，尝试提取更多信息
    if (domain.includes('facebook.com')) {
        // Facebook 个人页面特殊处理
        try {
            // 提取 Facebook 用户名（从URL）
            const fbUserMatch = pageUrl.match(/facebook\.com\/([^\/\?]+)/);
            if (fbUserMatch && fbUserMatch[1] && !fbUserMatch[1].includes('.')) {
                customerInfo.socialMediaAccounts.facebook = customerInfo.socialMediaAccounts.facebook || [];
                const fbUrl = 'https://www.facebook.com/' + fbUserMatch[1];
                if (!customerInfo.socialMediaAccounts.facebook.includes(fbUrl)) {
                    customerInfo.socialMediaAccounts.facebook.push(fbUrl);
                }
            }

            // 尝试从页面提取更多信息（姓名、简介等）
            const nameSelectors = [
                'h1[data-testid="user-name"]',
                'h1',
                '[data-testid="user-name"]',
                '.x1heor9g', // Facebook 可能的姓名选择器
                'span[dir="auto"]'
            ];

            for (const selector of nameSelectors) {
                const nameEl = document.querySelector(selector);
                if (nameEl && nameEl.textContent && nameEl.textContent.trim().length > 0) {
                    const name = nameEl.textContent.trim();
                    if (name.length >= 2 && name.length <= 100 && !name.includes('Facebook')) {
                        customerInfo.contactName = name;
                        break;
                    }
                }
            }

            // 提取简介/关于信息
            const aboutSelectors = [
                '[data-testid="user-bio"]',
                '[data-testid="about"]',
                '.x1y1aw1k', // Facebook 可能的简介选择器
                'div[dir="auto"]'
            ];

            for (const selector of aboutSelectors) {
                const aboutEl = document.querySelector(selector);
                if (aboutEl && aboutEl.textContent) {
                    const aboutText = aboutEl.textContent.trim();
                    // 从简介中提取邮箱和电话
                    const emailMatches = aboutText.match(emailRegex);
                    if (emailMatches) {
                        customerInfo.emails = [...new Set([...customerInfo.emails, ...emailMatches])];
                    }
                    const phoneMatches = aboutText.match(phoneRegex);
                    if (phoneMatches) {
                        customerInfo.phones = [...new Set([...customerInfo.phones, ...phoneMatches.map(p => p.trim())])];
                    }
                }
            }
        } catch (e) {
            console.warn('[AutoLeadAgent] Facebook 特殊处理失败:', e);
        }
    }

    if (domain.includes('linkedin.com')) {
        // LinkedIn 个人页面特殊处理
        try {
            const nameEl = document.querySelector('h1.text-heading-xlarge, h1.pv-text-details__left-panel h1');
            if (nameEl && nameEl.textContent) {
                customerInfo.contactName = nameEl.textContent.trim();
            }

            const titleEl = document.querySelector('.text-body-medium.break-words, .pv-text-details__left-panel .text-body-medium');
            if (titleEl && titleEl.textContent) {
                customerInfo.jobTitle = titleEl.textContent.trim();
            }
        } catch (e) {
            console.warn('[AutoLeadAgent] LinkedIn 特殊处理失败:', e);
        }
    }

    if (domain.includes('instagram.com')) {
        // Instagram 个人页面特殊处理
        try {
            const nameEl = document.querySelector('h1, h2, span[dir="auto"]');
            if (nameEl && nameEl.textContent) {
                const name = nameEl.textContent.trim();
                if (name.length >= 2 && name.length <= 100) {
                    customerInfo.contactName = name;
                }
            }
        } catch (e) {
            console.warn('[AutoLeadAgent] Instagram 特殊处理失败:', e);
        }
    }

    // 提取公司名称（从标题、h1、meta等）
    const companyNamePatterns = [
        /<h1[^>]*>([^<]+)<\/h1>/i,
        /<title>([^<]+)<\/title>/i,
        /<meta[^>]*property=["']og:site_name["'][^>]*content=["']([^"']+)["']/i,
        /<meta[^>]*name=["']application-name["'][^>]*content=["']([^"']+)["']/i
    ];

    for (const pattern of companyNamePatterns) {
        const match = pageHtml.match(pattern);
        if (match && match[1]) {
            const name = match[1].trim();
            if (name.length > 2 && name.length < 100) {
                customerInfo.companyName = name;
                break;
            }
        }
    }

    // 如果没有找到，尝试从标题提取
    if (!customerInfo.companyName && pageTitle) {
        const titleParts = pageTitle.split(/[-|–—]/);
        if (titleParts.length > 0) {
            customerInfo.companyName = titleParts[0].trim();
        }
    }

    // 提取联系人姓名（从Contact、About等页面）
    if (pageType === 'contact' || pageType === 'about' || pageType === 'team') {
        // 查找常见的姓名模式（中文和英文）
        const namePatterns = [
            /(?:联系人|负责人|经理|总监|CEO|CTO|CFO|President|Director|Manager)[:：]\s*([A-Za-z\s]+|[\u4e00-\u9fa5]+)/i,
            /([A-Z][a-z]+\s+[A-Z][a-z]+)/, // 英文全名
            /([\u4e00-\u9fa5]{2,4})/ // 中文姓名
        ];

        for (const pattern of namePatterns) {
            const match = pageText.match(pattern);
            if (match && match[1]) {
                const name = match[1].trim();
                if (name.length >= 2 && name.length <= 50) {
                    customerInfo.contactName = name;
                    break;
                }
            }
        }
    }

    // 提取职位/角色
    const jobTitlePatterns = [
        /(?:职位|职务|Position|Title|Role)[:：]\s*([^\n\r]+)/i,
        /(CEO|CTO|CFO|COO|President|Director|Manager|Lead|Senior|Junior|Engineer|Developer|Designer|Sales|Marketing)/i
    ];

    for (const pattern of jobTitlePatterns) {
        const match = pageText.match(pattern);
        if (match && match[1]) {
            customerInfo.jobTitle = match[1].trim();
            break;
        }
    }

    // 提取公司规模（员工数）
    const companySizePatterns = [
        /(?:员工|人员|团队|Employees|Staff|Team)[:：]?\s*(\d+)[^\d]*(?:人|名|employees|staff|members)/i,
        /(\d+)[^\d]*(?:人|名|employees|staff|members)/i
    ];

    for (const pattern of companySizePatterns) {
        const match = pageText.match(pattern);
        if (match && match[1]) {
            const size = parseInt(match[1]);
            if (size > 0 && size < 1000000) {
                customerInfo.companySize = size;
                break;
            }
        }
    }

    // 提取地理位置（地址）
    const locationPatterns = [
        /(?:地址|位置|Address|Location)[:：]\s*([^\n\r]{10,200})/i,
        /([\u4e00-\u9fa5]{2,}(?:省|市|区|县|街道|路|号)[^\n\r]{0,50})/,
        /(\d+[^\n\r]{0,30}(?:Street|Avenue|Road|Lane|Drive|Boulevard)[^\n\r]{0,30})/i
    ];

    for (const pattern of locationPatterns) {
        const match = pageText.match(pattern);
        if (match && match[1]) {
            const location = match[1].trim();
            if (location.length >= 5 && location.length <= 200) {
                customerInfo.location = location;
                break;
            }
        }
    }

    // 提取成立时间
    const foundedPatterns = [
        /(?:成立|创立|Founded|Established|Since)[:：]?\s*(\d{4})/i,
        /(19|20)\d{2}/ // 年份模式
    ];

    for (const pattern of foundedPatterns) {
        const match = pageText.match(pattern);
        if (match && match[1]) {
            const year = parseInt(match[1]);
            if (year >= 1900 && year <= new Date().getFullYear()) {
                customerInfo.foundedYear = year;
                break;
            }
        }
    }

    // 提取行业标签（从关键词、描述等）
    const industryKeywords = [
        '软件', 'Software', 'IT', '互联网', 'Internet', '科技', 'Technology',
        '金融', 'Finance', '银行', 'Bank', '保险', 'Insurance',
        '教育', 'Education', '医疗', 'Healthcare', '零售', 'Retail',
        '制造', 'Manufacturing', '物流', 'Logistics', '房地产', 'Real Estate'
    ];

    const foundIndustries = [];
    for (const keyword of industryKeywords) {
        if (pageText.includes(keyword) || pageTitle.includes(keyword)) {
            foundIndustries.push(keyword);
        }
    }

    if (foundIndustries.length > 0) {
        customerInfo.industry = foundIndustries.slice(0, 3).join(', '); // 最多3个行业标签
    }

    return customerInfo;
}

/**
 * 使用端侧模型分析元素是否是搜索结果条目（注入到目标页面执行）
 */
function analyzeSearchResultItem(element, engine) {
    // 提取元素信息
    const text = (element.textContent || element.innerText || '').trim();
    const textLower = text.toLowerCase();
    const html = element.innerHTML || '';
    const className = (element.className || '').toLowerCase();
    const id = (element.id || '').toLowerCase();
    const tagName = element.tagName.toLowerCase();

    // 端侧模型评分
    let score = 0;
    const reasons = [];

    // 特征1: 包含外部链接（最重要）
    // 尝试多种方式查找链接
    const externalLinks = element.querySelectorAll('a[href]');
    let hasValidExternalLink = false;
    let validLinkUrl = null;

    for (const link of externalLinks) {
        let href = link.href || link.getAttribute('href') || '';

        // 处理Google的 /url?q= 重定向格式
        if (href.includes('/url?q=')) {
            try {
                const urlParams = new URLSearchParams(href.split('?')[1]);
                href = urlParams.get('q') || href;
            } catch (e) {
                // 解析失败，使用原始链接
            }
        }

        // 处理相对链接
        if (href.startsWith('/')) {
            href = window.location.origin + href;
        }

        // 排除搜索引擎自己的链接
        if (href &&
            !href.includes('google.com/search') &&
            !href.includes('google.com/url') &&
            !href.includes('google.com/maps') &&
            !href.includes('baidu.com') &&
            !href.includes('bing.com') &&
            !href.startsWith('javascript:') &&
            !href.startsWith('#')) {
            hasValidExternalLink = true;
            validLinkUrl = href;
            break;
        }
    }

    if (hasValidExternalLink) {
        score += 6.0;
        reasons.push('包含有效外部链接');
    } else {
        // 即使没有外部链接，如果其他特征明显，也可以考虑（降低要求）
        // 但会降低评分
        score -= 2.0;
        reasons.push('未找到有效外部链接');
    }

    // 特征2: 包含标题元素（h1-h6）
    const hasTitle = element.querySelector('h1, h2, h3, h4, h5, h6') !== null;
    if (hasTitle) {
        score += 3.0;
        reasons.push('包含标题元素');
    }

    // 特征3: 文本长度（搜索结果项通常有足够的文本内容）
    if (text.length >= 30 && text.length <= 2000) {
        score += 2.0;
        reasons.push('文本长度合适（30-2000字符）');
    } else if (text.length > 2000 && text.length < 5000) {
        score += 1.0;
        reasons.push('文本较长（可能包含多个结果）');
    } else if (text.length >= 10 && text.length < 30) {
        score += 0.5;
        reasons.push('文本较短但可能有内容');
    }

    // 特征4: 位置特征（搜索结果通常在页面中央区域）
    const rect = element.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    const elementCenterY = rect.top + rect.height / 2;
    const elementCenterX = rect.left + rect.width / 2;
    const centerY = viewportHeight / 2;
    const centerX = viewportWidth / 2;

    // 如果元素在视口中央区域（上下50%，左右50%），加分
    const distanceFromCenter = Math.sqrt(
        Math.pow(elementCenterX - centerX, 2) + Math.pow(elementCenterY - centerY, 2)
    );
    const maxDistance = Math.sqrt(Math.pow(viewportWidth, 2) + Math.pow(viewportHeight, 2));
    const centerRatio = 1 - (distanceFromCenter / maxDistance);

    if (centerRatio > 0.3) {
        score += 1.5;
        reasons.push('位置在页面中央区域');
    }

    // 特征5: 排除导航、页脚、侧边栏等区域
    const excludeKeywords = ['nav', 'footer', 'sidebar', 'header', 'menu', 'navigation', '广告', 'ad'];
    const shouldExclude = excludeKeywords.some(keyword =>
        className.includes(keyword) ||
        id.includes(keyword) ||
        textLower.includes(keyword)
    );

    if (shouldExclude) {
        score -= 3.0;
        reasons.push('可能不是搜索结果（包含排除关键词）');
    }

    // 特征6: 可见性和尺寸
    const isVisible = rect.width > 0 && rect.height > 0 &&
        window.getComputedStyle(element).display !== 'none' &&
        window.getComputedStyle(element).visibility !== 'hidden';
    if (isVisible) {
        score += 1.0;
        reasons.push('元素可见');
    }

    // 特征7: 包含摘要/描述文本（搜索结果通常有描述）
    const hasDescription = text.length > 50 &&
        (text.includes('.') || text.includes('，') || text.includes(',') || text.includes('。')) &&
        !text.startsWith('http') && !text.startsWith('www');
    if (hasDescription) {
        score += 2.0;
        reasons.push('包含描述性文本');
    } else if (text.length > 20) {
        // 即使没有明显的描述标记，如果有足够文本也加分
        score += 0.5;
        reasons.push('包含一定文本内容');
    }

    // 特征9: 包含链接文本（即使链接本身不在当前元素内）
    const linkText = element.querySelector('a');
    if (linkText && linkText.textContent && linkText.textContent.trim().length > 5) {
        score += 1.0;
        reasons.push('包含链接文本');
    }

    // 特征10: 元素结构特征（搜索结果通常有特定的DOM结构）
    const hasMultipleChildren = element.children.length >= 2;
    if (hasMultipleChildren) {
        score += 0.5;
        reasons.push('包含多个子元素（可能是结构化结果）');
    }

    // 特征8: 不包含广告特征
    const adKeywords = ['广告', 'ad', 'sponsored', '推广'];
    const hasAdKeyword = adKeywords.some(keyword => textLower.includes(keyword));
    if (!hasAdKeyword) {
        score += 1.0;
        reasons.push('不包含广告关键词');
    } else {
        score -= 2.0;
        reasons.push('可能包含广告');
    }

    // 判断是否是搜索结果条目
    // 进一步降低阈值，提高识别率
    // 如果有有效链接，score >= 3.5；如果没有链接但其他特征明显，score >= 5.5
    const isSearchResultItem = hasValidExternalLink
        ? score >= 3.5  // 有链接时进一步降低阈值
        : score >= 5.5; // 无链接时需要更高的分数（但比之前降低）

    return {
        isSearchResultItem: isSearchResultItem,
        score: score,
        reasons: reasons,
        validLinkUrl: validLinkUrl,
        hasTitle: hasTitle,
        textLength: text.length,
        centerRatio: centerRatio,
        isVisible: isVisible
    };
}

/**
 * 使用端侧模型查找搜索结果条目（注入到目标页面执行）
 */
function findSearchResultItemsByModel(engine) {
    const candidates = [];
    const allScores = []; // 用于调试：记录所有元素的分数

    // 查找所有可能的容器元素
    const possibleContainers = document.querySelectorAll('div, article, section, li');

    console.log('[AutoLeadAgent] 开始分析 ' + possibleContainers.length + ' 个可能的容器元素...');

    possibleContainers.forEach((element, index) => {
        // 使用端侧模型分析
        const analysis = analyzeSearchResultItem(element, engine);

        // 记录所有分数（用于调试）
        if (analysis.score > 0) {
            allScores.push({
                index: index,
                score: analysis.score,
                hasLink: !!analysis.validLinkUrl,
                textLength: analysis.textLength,
                reasons: analysis.reasons
            });
        }

        if (analysis.isSearchResultItem) {
            candidates.push({
                element: element,
                score: analysis.score,
                reasons: analysis.reasons,
                validLinkUrl: analysis.validLinkUrl,
                hasTitle: analysis.hasTitle,
                textLength: analysis.textLength
            });
        }
    });

    // 按分数排序（分数高的优先）
    candidates.sort((a, b) => b.score - a.score);

    // 调试日志：输出前10个最高分的元素（即使不是搜索结果条目）
    if (allScores.length > 0) {
        allScores.sort((a, b) => b.score - a.score);
        console.log('[AutoLeadAgent] 前10个最高分元素:', allScores.slice(0, 10).map(s => ({
            score: s.score.toFixed(2),
            hasLink: s.hasLink,
            textLength: s.textLength,
            reasons: s.reasons.slice(0, 3) // 只显示前3个原因
        })));
    }

    console.log('[AutoLeadAgent] 找到 ' + candidates.length + ' 个候选搜索结果条目');

    // 返回前20个候选元素
    return candidates.slice(0, 20).map(c => c.element);
}

/**
 * 使用端侧模型分析分页按钮（注入到目标页面执行）
 */
function analyzePaginationButton(element) {
    // 下一页关键词（多语言支持）
    const nextPageKeywords = [
        // 英文
        'next', 'next page', 'more', 'more results', 'show more',
        // 中文
        '下一页', '下页', '后一页', '更多', '更多结果',
        // 日文
        '次へ', '次のページ', 'もっと見る',
        // 韩文
        '다음', '다음 페이지',
        // 法文
        'suivant', 'page suivante', 'plus',
        // 德文
        'weiter', 'nächste seite', 'mehr',
        // 西班牙文
        'siguiente', 'página siguiente', 'más',
        // 俄文
        'следующая', 'далее',
        // 图标和符号
        '>', '»', '→', '›', '»', 'next ›', 'next »', '›', '→'
    ];

    // 排除的关键词（上一页、首页等）
    const excludeKeywords = [
        'prev', 'previous', '上一页', '上页', '前页', '首页', 'first', 'home',
        '<', '«', '←', '‹', '«', '‹ prev', '« previous'
    ];

    // 提取元素信息
    const text = (element.textContent || element.innerText || '').trim();
    const textLower = text.toLowerCase();
    const ariaLabel = (element.getAttribute('aria-label') || '').toLowerCase();
    const className = (element.className || '').toLowerCase();
    const id = (element.id || '').toLowerCase();
    const href = element.href || element.getAttribute('href') || '';
    const tagName = element.tagName.toLowerCase();

    // 检查是否应该排除
    const shouldExclude = excludeKeywords.some(keyword =>
        textLower.includes(keyword) ||
        ariaLabel.includes(keyword) ||
        className.includes(keyword) ||
        id.includes(keyword)
    );

    if (shouldExclude) {
        return { isNextPage: false, score: 0, reasons: ['包含排除关键词'] };
    }

    // 端侧模型评分
    let score = 0;
    const reasons = [];

    // 特征1: 文本匹配（最重要）
    const textMatch = nextPageKeywords.some(keyword =>
        textLower.includes(keyword.toLowerCase()) ||
        ariaLabel.includes(keyword.toLowerCase())
    );
    if (textMatch) {
        score += 5.0;
        reasons.push('文本匹配下一页关键词');
    }

    // 特征2: Class/ID 匹配
    const classIdMatch = className.includes('next') ||
        id.includes('next') ||
        className.includes('下一页') ||
        id.includes('下一页') ||
        className.includes('pager') ||
        className.includes('pagination');
    if (classIdMatch) {
        score += 3.0;
        reasons.push('Class/ID匹配分页特征');
    }

    // 特征3: 位置特征（分页按钮通常在页面底部）
    const rect = element.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    const scrollHeight = document.documentElement.scrollHeight;
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const elementTop = rect.top + scrollTop;
    const positionRatio = elementTop / scrollHeight;

    // 如果按钮在页面下半部分（特别是后30%），加分
    if (positionRatio > 0.5) {
        score += 2.0;
        reasons.push('位置在页面下半部分');
    }
    if (positionRatio > 0.7) {
        score += 1.0;
        reasons.push('位置在页面底部区域');
    }

    // 特征4: 可见性和可点击性
    const isVisible = rect.width > 0 && rect.height > 0 &&
        window.getComputedStyle(element).display !== 'none' &&
        window.getComputedStyle(element).visibility !== 'hidden';
    if (isVisible) {
        score += 1.0;
        reasons.push('元素可见');
    }

    const isClickable = (tagName === 'a' && href) || tagName === 'button' ||
        element.onclick || element.getAttribute('onclick');
    if (isClickable) {
        score += 1.0;
        reasons.push('元素可点击');
    }

    // 特征5: 在分页容器中
    const paginationContainers = ['pagination', 'pager', 'page-nav', 'page-navigation'];
    let parent = element.parentElement;
    let inPaginationContainer = false;
    let depth = 0;
    while (parent && depth < 5) {
        const parentClass = (parent.className || '').toLowerCase();
        const parentId = (parent.id || '').toLowerCase();
        if (paginationContainers.some(container =>
            parentClass.includes(container) || parentId.includes(container))) {
            inPaginationContainer = true;
            break;
        }
        parent = parent.parentElement;
        depth++;
    }
    if (inPaginationContainer) {
        score += 2.0;
        reasons.push('在分页容器中');
    }

    // 特征6: URL特征（如果包含页码参数）
    if (href) {
        const pagePatterns = [/page[=\/](\d+)/i, /p[=\/](\d+)/i, /pn[=\/](\d+)/i, /start[=\/](\d+)/i];
        const hasPageParam = pagePatterns.some(pattern => pattern.test(href));
        if (hasPageParam) {
            score += 1.5;
            reasons.push('URL包含页码参数');
        }
    }

    // 特征7: 数字特征（下一页按钮可能包含当前页+1的数字）
    const numberMatch = text.match(/\d+/);
    if (numberMatch) {
        score += 0.5;
        reasons.push('包含数字');
    }

    // 判断是否是下一页按钮（阈值：score >= 3.0）
    const isNextPage = score >= 3.0;

    return {
        isNextPage: isNextPage,
        score: score,
        reasons: reasons,
        positionRatio: positionRatio,
        isVisible: isVisible,
        isClickable: isClickable,
        inPaginationContainer: inPaginationContainer
    };
}

/**
 * 查找分页按钮候选（注入到目标页面执行，使用端侧模型分析）
 */
function findPaginationButtons() {
    const candidates = [];

    // 查找所有可能的链接和按钮
    const allLinks = document.querySelectorAll('a, button');

    allLinks.forEach(element => {
        // 使用端侧模型分析
        const analysis = analyzePaginationButton(element);

        if (analysis.isNextPage) {
            // 提取完整的URL（如果是相对URL，转换为绝对URL）
            let href = element.href || element.getAttribute('href') || '';
            if (href && !href.startsWith('http') && !href.startsWith('//')) {
                if (href.startsWith('/')) {
                    href = window.location.origin + href;
                } else {
                    href = window.location.origin + '/' + href;
                }
            }

            candidates.push({
                element: {
                    tagName: element.tagName,
                    text: element.textContent.trim(),
                    href: href,
                    className: element.className,
                    id: element.id,
                    ariaLabel: element.getAttribute('aria-label') || ''
                },
                score: analysis.score,
                reasons: analysis.reasons,
                positionRatio: analysis.positionRatio,
                isVisible: analysis.isVisible,
                isClickable: analysis.isClickable,
                inPaginationContainer: analysis.inPaginationContainer
            });
        }
    });

    // 按分数排序（分数高的优先）
    candidates.sort((a, b) => b.score - a.score);

    return candidates;
}

/**
 * 获取下一页URL（注入到目标页面执行）
 * 返回最佳分页按钮的URL，用于创建新标签页访问
 */
function getNextPageUrl() {
    const candidates = findPaginationButtons();

    if (candidates.length === 0) {
        return null;
    }

    // 返回分数最高的候选按钮的URL
    const bestCandidate = candidates[0];
    return bestCandidate.element.href || null;
}

/**
 * 使用端侧模型分析验证码按钮（注入到目标页面执行）
 */
function analyzeCaptchaButton(element) {
    // 验证码按钮关键词（多语言支持）
    const captchaKeywords = [
        // 英文
        'verify', 'continue', 'i\'m not a robot', 'i am not a robot', 'proceed', 'submit',
        'check', 'confirm', 'pass', 'complete', 'solve', 'challenge',
        // 中文
        '验证', '继续', '我不是机器人', '确认', '通过', '完成', '提交',
        '点击验证', '人机验证', '安全验证', '验证身份',
        // 图标和符号
        '✓', '✔', 'check', 'done'
    ];

    // 排除的关键词
    const excludeKeywords = [
        'cancel', 'close', 'skip', '取消', '关闭', '跳过',
        'back', '返回', 'previous', '上一页'
    ];

    // 提取元素信息
    const text = (element.textContent || element.innerText || '').trim();
    const textLower = text.toLowerCase();
    const ariaLabel = (element.getAttribute('aria-label') || '').toLowerCase();
    const className = (element.className || '').toLowerCase();
    const id = (element.id || '').toLowerCase();
    const tagName = element.tagName.toLowerCase();

    // 检查是否应该排除
    const shouldExclude = excludeKeywords.some(keyword =>
        textLower.includes(keyword) ||
        ariaLabel.includes(keyword) ||
        className.includes(keyword) ||
        id.includes(keyword)
    );

    if (shouldExclude) {
        return { isCaptchaButton: false, score: 0, reasons: ['包含排除关键词'] };
    }

    // 端侧模型评分
    let score = 0;
    const reasons = [];

    // 特征1: 文本匹配（最重要）
    const textMatch = captchaKeywords.some(keyword =>
        textLower.includes(keyword.toLowerCase()) ||
        ariaLabel.includes(keyword.toLowerCase())
    );
    if (textMatch) {
        score += 6.0;
        reasons.push('文本匹配验证码关键词');
    }

    // 特征2: Class/ID 匹配
    const classIdMatch = className.includes('captcha') ||
        id.includes('captcha') ||
        className.includes('verify') ||
        id.includes('verify') ||
        className.includes('recaptcha') ||
        id.includes('recaptcha') ||
        className.includes('challenge') ||
        id.includes('challenge');
    if (classIdMatch) {
        score += 4.0;
        reasons.push('Class/ID匹配验证码特征');
    }

    // 特征3: 位置特征（验证码按钮通常在页面中央或验证码区域）
    const rect = element.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
    const viewportWidth = window.innerWidth || document.documentElement.clientWidth;
    const elementCenterY = rect.top + rect.height / 2;
    const elementCenterX = rect.left + rect.width / 2;
    const centerY = viewportHeight / 2;
    const centerX = viewportWidth / 2;

    // 如果按钮在视口中央区域（上下30%，左右30%），加分
    const distanceFromCenter = Math.sqrt(
        Math.pow(elementCenterX - centerX, 2) + Math.pow(elementCenterY - centerY, 2)
    );
    const maxDistance = Math.sqrt(Math.pow(viewportWidth, 2) + Math.pow(viewportHeight, 2));
    const centerRatio = 1 - (distanceFromCenter / maxDistance);

    if (centerRatio > 0.5) {
        score += 2.0;
        reasons.push('位置在页面中央区域');
    }

    // 特征4: 可见性和可点击性
    const isVisible = rect.width > 0 && rect.height > 0 &&
        window.getComputedStyle(element).display !== 'none' &&
        window.getComputedStyle(element).visibility !== 'hidden';
    if (isVisible) {
        score += 1.5;
        reasons.push('元素可见');
    }

    const isClickable = tagName === 'button' ||
        tagName === 'a' ||
        element.onclick ||
        element.getAttribute('onclick') ||
        element.getAttribute('role') === 'button';
    if (isClickable) {
        score += 1.5;
        reasons.push('元素可点击');
    }

    // 特征5: 在验证码容器中
    const captchaContainers = ['captcha', 'verify', 'recaptcha', 'challenge', 'security'];
    let parent = element.parentElement;
    let inCaptchaContainer = false;
    let depth = 0;
    while (parent && depth < 5) {
        const parentClass = (parent.className || '').toLowerCase();
        const parentId = (parent.id || '').toLowerCase();
        if (captchaContainers.some(container =>
            parentClass.includes(container) || parentId.includes(container))) {
            inCaptchaContainer = true;
            break;
        }
        parent = parent.parentElement;
        depth++;
    }
    if (inCaptchaContainer) {
        score += 3.0;
        reasons.push('在验证码容器中');
    }

    // 特征6: 按钮大小（验证码按钮通常不会太小）
    if (rect.width >= 100 && rect.height >= 30) {
        score += 1.0;
        reasons.push('按钮尺寸合适');
    }

    // 判断是否是验证码按钮（阈值：score >= 4.0）
    const isCaptchaButton = score >= 4.0;

    return {
        isCaptchaButton: isCaptchaButton,
        score: score,
        reasons: reasons,
        centerRatio: centerRatio,
        isVisible: isVisible,
        isClickable: isClickable,
        inCaptchaContainer: inCaptchaContainer
    };
}

/**
 * 查找验证码按钮候选（注入到目标页面执行，使用端侧模型分析）
 */
function findCaptchaButtons() {
    const candidates = [];

    // 查找所有可能的按钮和链接
    const allElements = document.querySelectorAll('button, a, div[role="button"], span[role="button"]');

    allElements.forEach(element => {
        // 使用端侧模型分析
        const analysis = analyzeCaptchaButton(element);

        if (analysis.isCaptchaButton) {
            candidates.push({
                element: {
                    tagName: element.tagName,
                    text: element.textContent.trim(),
                    className: element.className,
                    id: element.id,
                    ariaLabel: element.getAttribute('aria-label') || '',
                    xpath: (() => {
                        // 生成简单的XPath用于定位
                        if (element.id) {
                            return `//*[@id="${element.id}"]`;
                        }
                        if (element.className) {
                            const classes = element.className.split(' ').filter(c => c).join('.');
                            if (classes) {
                                return `//${element.tagName.toLowerCase()}[@class="${classes}"]`;
                            }
                        }
                        return null;
                    })()
                },
                score: analysis.score,
                reasons: analysis.reasons,
                centerRatio: analysis.centerRatio,
                isVisible: analysis.isVisible,
                isClickable: analysis.isClickable,
                inCaptchaContainer: analysis.inCaptchaContainer
            });
        }
    });

    // 按分数排序（分数高的优先）
    candidates.sort((a, b) => b.score - a.score);

    return candidates;
}

/**
 * 深度爬取网站（递归访问，最多10层深度）
 */
/**
 * 根据页面类型获取最大深度（智能深度控制）
 */
function getMaxDepthForPageType(pageType) {
    const depthMap = {
        'home': 2,      // 首页：深度1-2
        'product': 3,   // 产品页：深度2-3
        'blog': 5,      // 博客/文章：深度3-5
        'about': 2,    // 关于我们：深度1-2
        'contact': 1,   // 联系页面：深度1
        'team': 2,     // 团队页面：深度1-2
        'other': 10    // 其他页面：深度5-10
    };
    return depthMap[pageType] || 10;
}

/**
 * 评估链接重要性（智能链接过滤）
 */
function evaluateLinkImportance(linkUrl, linkText) {
    let score = 0;
    const urlLower = linkUrl.toLowerCase();
    const textLower = (linkText || '').toLowerCase();

    // 关键页面链接（高优先级）
    const importantKeywords = ['contact', 'about', 'team', 'company', '联系我们', '关于我们', '团队'];
    if (importantKeywords.some(kw => urlLower.includes(kw) || textLower.includes(kw))) {
        score += 10;
    }

    // 排除无关链接
    const excludeKeywords = ['ad', 'advertisement', 'privacy', 'terms', 'cookie', '广告', '隐私', '条款'];
    if (excludeKeywords.some(kw => urlLower.includes(kw) || textLower.includes(kw))) {
        score -= 5;
    }

    // 外部链接（低优先级）
    if (urlLower.startsWith('http') && !urlLower.includes(window.location.hostname)) {
        score -= 3;
    }

    // JavaScript链接（低优先级）
    if (urlLower.startsWith('javascript:') || urlLower.startsWith('#')) {
        score -= 5;
    }

    return score;
}

/**
 * 访问搜索结果条目并提取用户信息
 * @param {string} resultUrl - 结果条目URL
 * @param {string} resultTitle - 结果条目标题
 * @param {string} resultSnippet - 结果条目摘要
 * @param {Object} engine - 搜索引擎对象
 * @param {Function} sendLogToFrontend - 日志发送函数
 * @param {Set} openedTabs - 已打开的标签页集合
 * @returns {Promise<Object|null>} 提取的客户信息，失败返回null
 */
async function visitSearchResultAndExtract(resultUrl, resultTitle, resultSnippet, engine, sendLogToFrontend, openedTabs) {
    let tab = null;
    try {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 访问结果条目: ' + resultUrl);
        }

        // 创建新标签页访问结果URL
        tab = await chrome.tabs.create({
            url: resultUrl,
            active: false
        });

        // 添加到跟踪列表
        if (openedTabs) {
            openedTabs.add(tab.id);
        }

        // 等待页面加载
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 等待页面加载...');
        }
        await waitForTabLoad(tab.id, 30000);

        // 等待3秒让动态内容加载
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 等待3秒让动态内容加载...');
        }
        await sleep(3000);

        // 提取客户信息
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 使用模型提取用户信息...');
        }
        const customerInfoResult = await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            func: extractCustomerInfo
        });

        const customerInfo = customerInfoResult[0]?.result || {};

        // 关闭标签页
        try {
            await chrome.tabs.remove(tab.id);
            if (openedTabs) {
                openedTabs.delete(tab.id);
            }
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ✓ 结果条目标签页已关闭');
            }
        } catch (e) {
            console.warn('[AutoLeadAgent Extension] 关闭结果条目标签页失败:', e.message);
            if (openedTabs) {
                openedTabs.delete(tab.id);
            }
        }

        // 返回提取的信息
        if (customerInfo.emails && customerInfo.emails.length > 0 ||
            customerInfo.phones && customerInfo.phones.length > 0 ||
            customerInfo.socialMediaAccounts && Object.keys(customerInfo.socialMediaAccounts).length > 0) {
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ✓ 从结果条目提取到用户信息');
            }
            return customerInfo;
        } else {
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ⚠️ 结果条目未包含有效用户信息');
            }
            return null;
        }

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 访问结果条目失败:', resultUrl, error);
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  ❌ 访问结果条目失败: ' + error.message);
        }

        // 确保关闭标签页
        if (tab && tab.id) {
            try {
                await chrome.tabs.remove(tab.id);
                if (openedTabs) {
                    openedTabs.delete(tab.id);
                }
            } catch (e) {
                if (openedTabs) {
                    openedTabs.delete(tab.id);
                }
            }
        }

        return null;
    }
}

async function deepCrawlWebsite(url, maxDepth = 10, visited = new Set(), currentDepth = 0, sendLogToFrontend = null) {
    const allCustomerInfo = [];

    // 检查深度限制和是否已访问
    if (currentDepth >= maxDepth) {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  → 达到最大深度 ${maxDepth}，停止爬取: ${url}`);
        }
        return allCustomerInfo;
    }

    if (visited.has(url)) {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  → 已访问过，跳过: ${url}`);
        }
        return allCustomerInfo;
    }

    visited.add(url);

    try {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  → [深度 ${currentDepth + 1}/${maxDepth}] 访问: ${url}`);
        }

        // 创建新标签页访问URL
        const tab = await chrome.tabs.create({
            url: url,
            active: false
        });

        try {
            // 等待页面加载（带超时控制）
            try {
                await Promise.race([
                    waitForTabLoad(tab.id, 30000),
                    new Promise((_, reject) => setTimeout(() => reject(new Error('页面加载超时')), 30000))
                ]);
            } catch (timeoutError) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', `  ⚠️ 页面加载超时: ${url}`);
                }
                throw timeoutError;
            }

            // 检查是否是社交媒体平台，需要更长的等待时间和滚动操作
            const isSocialMediaPlatform = /(facebook\.com|linkedin\.com|twitter\.com|x\.com|instagram\.com|youtube\.com)/i.test(url);

            if (isSocialMediaPlatform) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', `  │  🌐 检测到社交媒体平台，增加等待时间和滚动操作...`);
                }
                // 社交媒体平台需要更长的等待时间
                await sleep(5000); // 等待5秒让动态内容加载

                // 滚动页面以加载更多内容（特别是Facebook、Instagram等）
                try {
                    await chrome.scripting.executeScript({
                        target: { tabId: tab.id },
                        func: function () {
                            return new Promise((resolve) => {
                                let scrollPosition = 0;
                                const scrollStep = 500;
                                const maxScrolls = 5; // 最多滚动5次
                                let scrollCount = 0;

                                const scrollInterval = setInterval(() => {
                                    window.scrollBy(0, scrollStep);
                                    scrollPosition += scrollStep;
                                    scrollCount++;

                                    // 如果已经滚动到底部或达到最大滚动次数，停止
                                    if (scrollCount >= maxScrolls ||
                                        (window.innerHeight + window.scrollY) >= document.body.scrollHeight) {
                                        clearInterval(scrollInterval);
                                        // 等待内容加载
                                        setTimeout(() => {
                                            // 滚动回顶部
                                            window.scrollTo(0, 0);
                                            resolve();
                                        }, 2000);
                                    }
                                }, 1000); // 每秒滚动一次
                            });
                        }
                    });
                    if (sendLogToFrontend) {
                        sendLogToFrontend('crawl', `  │  ✓ 页面滚动完成，内容已加载`);
                    }
                } catch (scrollError) {
                    if (sendLogToFrontend) {
                        sendLogToFrontend('crawl', `  │  ⚠️ 滚动操作失败: ${scrollError.message}`);
                    }
                }
            } else {
                await sleep(2000); // 普通页面等待2秒
            }

            // 检查页面是否可访问（404、403等）
            try {
                const tabInfo = await chrome.tabs.get(tab.id);
                if (tabInfo.url && (tabInfo.url.includes('404') || tabInfo.url.includes('403') || tabInfo.url.includes('error'))) {
                    if (sendLogToFrontend) {
                        sendLogToFrontend('crawl', `  ⚠️ 页面无法访问: ${url}`);
                    }
                    return allCustomerInfo; // 返回空结果，不继续爬取
                }
            } catch (e) {
                // 忽略检查错误
            }

            // 提取当前页面的客户信息和页面类型
            let customerInfo = {};
            let pageType = 'other';
            try {
                const customerInfoResult = await chrome.scripting.executeScript({
                    target: { tabId: tab.id },
                    func: extractCustomerInfo
                });
                customerInfo = customerInfoResult[0]?.result || {};
                pageType = customerInfo.pageType || 'other';

                // 根据页面类型调整最大深度
                const pageTypeMaxDepth = getMaxDepthForPageType(pageType);
                const adjustedMaxDepth = Math.min(maxDepth, currentDepth + pageTypeMaxDepth);

                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', `  │  页面类型: ${pageType}，调整后最大深度: ${adjustedMaxDepth}`);
                }

                // 更新maxDepth为调整后的值（用于后续递归）
                maxDepth = adjustedMaxDepth;
            } catch (scriptError) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', `  ⚠️ 提取客户信息失败: ${scriptError.message}`);
                }
                // 继续执行，即使提取失败
            }

            // 只要有邮箱、电话或社媒信息，就保存
            if ((customerInfo.emails && customerInfo.emails.length > 0) ||
                (customerInfo.phones && customerInfo.phones.length > 0) ||
                (customerInfo.socialMediaAccounts && Object.keys(customerInfo.socialMediaAccounts).length > 0)) {
                allCustomerInfo.push({
                    ...customerInfo,
                    depth: currentDepth + 1,
                    sourceUrl: url
                });

                if (sendLogToFrontend) {
                    const emailCount = customerInfo.emails ? customerInfo.emails.length : 0;
                    const phoneCount = customerInfo.phones ? customerInfo.phones.length : 0;
                    const socialCount = customerInfo.socialMediaAccounts ? Object.keys(customerInfo.socialMediaAccounts).length : 0;
                    sendLogToFrontend('crawl', `  │  ✅ 提取到客户信息:`);
                    if (emailCount > 0) {
                        sendLogToFrontend('crawl', `  │     📧 邮箱: ${emailCount} 个`);
                        customerInfo.emails.slice(0, 3).forEach(email => {
                            sendLogToFrontend('crawl', `  │        - ${email}`);
                        });
                        if (emailCount > 3) {
                            sendLogToFrontend('crawl', `  │        ... 还有 ${emailCount - 3} 个`);
                        }
                    }
                    if (phoneCount > 0) {
                        sendLogToFrontend('crawl', `  │     📱 电话: ${phoneCount} 个`);
                        customerInfo.phones.slice(0, 3).forEach(phone => {
                            sendLogToFrontend('crawl', `  │        - ${phone}`);
                        });
                        if (phoneCount > 3) {
                            sendLogToFrontend('crawl', `  │        ... 还有 ${phoneCount - 3} 个`);
                        }
                    }
                    if (socialCount > 0) {
                        sendLogToFrontend('crawl', `  │     🌐 社媒: ${socialCount} 个平台`);
                        Object.keys(customerInfo.socialMediaAccounts).forEach(platform => {
                            const accounts = customerInfo.socialMediaAccounts[platform];
                            sendLogToFrontend('crawl', `  │        - ${platform}: ${accounts.length} 个账号`);
                        });
                    }
                }
            } else {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', `  │  ⚠️ 未找到客户信息（无邮箱/电话/社媒）`);
                }
            }

            // 如果还没达到最大深度，继续提取链接并递归访问
            if (currentDepth < maxDepth - 1) {
                // 提取页面所有链接
                let links = [];
                try {
                    const linksResult = await chrome.scripting.executeScript({
                        target: { tabId: tab.id },
                        func: extractPageLinks
                    });
                    links = linksResult[0]?.result || [];
                } catch (scriptError) {
                    if (sendLogToFrontend) {
                        sendLogToFrontend('crawl', `  ⚠️ 提取链接失败: ${scriptError.message}`);
                    }
                    // 继续执行，即使提取链接失败
                }

                if (sendLogToFrontend) {
                    if (links.length > 0) {
                        sendLogToFrontend('crawl', `  │  🔗 找到 ${links.length} 个链接`);
                        const linksToVisitCount = Math.min(links.length, 20);
                        sendLogToFrontend('crawl', `  │  → 将访问前 ${linksToVisitCount} 个链接（进入深度 ${currentDepth + 2}/${maxDepth}）`);
                    } else {
                        sendLogToFrontend('crawl', `  │  ⚠️ 未找到链接，无法继续深入`);
                    }
                }

                // 智能链接过滤：优先访问重要性高的链接，限制每层最多访问20个链接
                // 过滤掉重要性为负的链接（广告、无关链接等）
                const filteredLinks = links.filter(link => link.importance >= 0);
                const linksToVisit = filteredLinks.slice(0, 20).map(link => link.url);

                if (sendLogToFrontend && filteredLinks.length < links.length) {
                    sendLogToFrontend('crawl', `  │  🔍 过滤掉 ${links.length - filteredLinks.length} 个低重要性链接`);
                }

                // 递归访问下一层链接
                for (let linkIndex = 0; linkIndex < linksToVisit.length; linkIndex++) {
                    const linkUrl = linksToVisit[linkIndex];
                    if (!visited.has(linkUrl)) {
                        if (sendLogToFrontend) {
                            sendLogToFrontend('crawl', `  │  → 递归访问链接 ${linkIndex + 1}/${linksToVisit.length}`);
                        }
                        try {
                            const subResults = await deepCrawlWebsite(
                                linkUrl,
                                maxDepth,
                                visited,
                                currentDepth + 1,
                                sendLogToFrontend
                            );
                            allCustomerInfo.push(...subResults);
                            if (sendLogToFrontend && subResults.length > 0) {
                                sendLogToFrontend('crawl', `  │     ✓ 从该链接找到 ${subResults.length} 条客户信息`);
                            }
                        } catch (subError) {
                            if (sendLogToFrontend) {
                                sendLogToFrontend('crawl', `  │     ❌ 递归爬取失败: ${subError.message}`);
                            }
                            // 继续处理下一个链接
                        }
                    }
                }
            }

            // 退出当前层的日志
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', `  └─ [深度 ${currentDepth + 1}/${maxDepth}] 退出页面`);
                sendLogToFrontend('crawl', `     本层找到 ${allCustomerInfo.length} 条客户信息`);
            }

        } finally {
            // 关闭标签页
            try {
                await chrome.tabs.remove(tab.id);
            } catch (e) {
                console.warn('[AutoLeadAgent Extension] 关闭标签页警告:', e.message);
            }
        }

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 深度爬取异常:', url, error);
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  ❌ 爬取失败: ${url} - ${error.message}`);
        }
    }

    return allCustomerInfo;
}

/**
 * 查找并点击分页按钮
 */
async function findAndClickNextPage(tabId, engine, sendLogToFrontend = null) {
    try {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '');
            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            sendLogToFrontend('crawl', '🔍 [' + engine.name + '] 开始查找分页按钮（使用端侧模型分析）...');
        }

        // 记录翻页前的页面状态（用于验证翻页是否成功）
        const beforeState = await chrome.scripting.executeScript({
            target: { tabId: tabId },
            func: function () {
                return {
                    url: window.location.href,
                    title: document.title,
                    firstResultText: (() => {
                        const firstResult = document.querySelector('.result, .c-container, .g, .search-result');
                        return firstResult ? (firstResult.textContent || '').substring(0, 100) : '';
                    })()
                };
            }
        });
        const beforeUrl = beforeState[0]?.result?.url || '';
        const beforeTitle = beforeState[0]?.result?.title || '';
        const beforeFirstResult = beforeState[0]?.result?.firstResultText || '';

        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  📍 翻页前URL: ' + beforeUrl);
        }

        // 提取分页按钮候选（使用端侧模型）
        const buttonsResult = await chrome.scripting.executeScript({
            target: { tabId: tabId },
            func: findPaginationButtons
        });

        const candidates = buttonsResult[0]?.result || [];

        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 端侧模型分析找到 ' + candidates.length + ' 个候选按钮');
        }

        if (candidates.length === 0) {
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ⚠️ 未找到分页按钮，无法继续分页');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            }
            return false;
        }

        // 显示所有候选按钮（带端侧模型分析结果）
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  📋 候选按钮列表（端侧模型评分）:');
            candidates.slice(0, 5).forEach((candidate, idx) => {
                sendLogToFrontend('crawl', '     ' + (idx + 1) + '. 文本: "' + candidate.element.text + '"');
                sendLogToFrontend('crawl', '        分数: ' + candidate.score.toFixed(2));
                sendLogToFrontend('crawl', '        原因: ' + candidate.reasons.join(', '));
                if (candidate.inPaginationContainer) {
                    sendLogToFrontend('crawl', '        位置: 在分页容器中');
                }
            });
            if (candidates.length > 5) {
                sendLogToFrontend('crawl', '     ... 还有 ' + (candidates.length - 5) + ' 个候选');
            }
        }

        // 尝试最多3个候选按钮（按分数从高到低）
        const maxAttempts = Math.min(3, candidates.length);
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            const candidate = candidates[attempt];

            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '');
                sendLogToFrontend('crawl', '  🔄 尝试 ' + (attempt + 1) + '/' + maxAttempts + ':');
                sendLogToFrontend('crawl', '     文本: "' + candidate.element.text + '"');
                sendLogToFrontend('crawl', '     分数: ' + candidate.score.toFixed(2));
            }

            // 点击分页按钮
            const clickResult = await chrome.scripting.executeScript({
                target: { tabId: tabId },
                func: function (elementInfo) {
                    // 根据元素信息查找并点击
                    let element = null;

                    // 尝试通过ID查找
                    if (elementInfo.id) {
                        element = document.getElementById(elementInfo.id);
                    }

                    // 尝试通过class查找
                    if (!element && elementInfo.className) {
                        const classParts = elementInfo.className.split(' ').filter(c => c);
                        if (classParts.length > 0) {
                            const elements = document.querySelectorAll('.' + classParts[0]);
                            // 如果有多个，选择文本匹配的
                            for (const el of elements) {
                                if (el.textContent.trim() === elementInfo.text) {
                                    element = el;
                                    break;
                                }
                            }
                            if (!element && elements.length > 0) {
                                element = elements[0];
                            }
                        }
                    }

                    // 尝试通过文本查找
                    if (!element && elementInfo.text) {
                        const allLinks = document.querySelectorAll('a, button');
                        for (const link of allLinks) {
                            if (link.textContent.trim() === elementInfo.text) {
                                element = link;
                                break;
                            }
                        }
                    }

                    // 尝试通过href查找
                    if (!element && elementInfo.href) {
                        element = document.querySelector(`a[href="${elementInfo.href}"]`);
                    }

                    if (element) {
                        // 滚动到元素位置
                        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        // 等待一下确保滚动完成
                        return new Promise((resolve) => {
                            setTimeout(() => {
                                try {
                                    element.click();
                                    resolve({ success: true, message: '点击成功' });
                                } catch (e) {
                                    resolve({ success: false, message: '点击异常: ' + e.message });
                                }
                            }, 500);
                        });
                    } else {
                        return { success: false, message: '未找到元素' };
                    }
                },
                args: [candidate.element]
            });

            const clickSuccess = clickResult[0]?.result?.success || false;

            if (!clickSuccess) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '  ❌ 点击失败: ' + (clickResult[0]?.result?.message || '未知错误'));
                }
                continue; // 尝试下一个候选
            }

            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ✅ 按钮点击成功');
                sendLogToFrontend('crawl', '  → 等待新页面加载...');
            }

            // 等待新页面加载
            await waitForTabLoad(tabId, 30000);
            await sleep(3000); // 等待动态内容加载

            // 验证翻页是否成功
            const afterState = await chrome.scripting.executeScript({
                target: { tabId: tabId },
                func: function () {
                    return {
                        url: window.location.href,
                        title: document.title,
                        firstResultText: (() => {
                            const firstResult = document.querySelector('.result, .c-container, .g, .search-result');
                            return firstResult ? (firstResult.textContent || '').substring(0, 100) : '';
                        })()
                    };
                }
            });
            const afterUrl = afterState[0]?.result?.url || '';
            const afterFirstResult = afterState[0]?.result?.firstResultText || '';

            // 验证URL是否变化
            const urlChanged = afterUrl !== beforeUrl;
            // 验证第一个结果是否变化（内容变化）
            const contentChanged = afterFirstResult !== beforeFirstResult && afterFirstResult.length > 0;

            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ✓ 新页面加载完成');
                sendLogToFrontend('crawl', '  📍 翻页后URL: ' + afterUrl);
                sendLogToFrontend('crawl', '  🔍 翻页验证:');
                sendLogToFrontend('crawl', '     URL变化: ' + (urlChanged ? '✅' : '❌'));
                sendLogToFrontend('crawl', '     内容变化: ' + (contentChanged ? '✅' : '❌'));
            }

            // 如果URL或内容发生变化，说明翻页成功
            if (urlChanged || contentChanged) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '  ✅ 翻页验证成功！');
                    sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                }
                return true;
            } else {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '  ⚠️ 翻页验证失败：URL和内容均未变化');
                    sendLogToFrontend('crawl', '  → 尝试下一个候选按钮...');
                }
                // 继续尝试下一个候选
                continue;
            }
        }

        // 所有候选都失败了
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  ❌ 所有候选按钮都失败，无法翻页');
            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        }
        return false;

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 分页处理异常:', error);
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  ❌ 分页处理异常: ${error.message}`);
        }
        return false;
    }
}

/**
 * 自动处理验证码（使用端侧模型分析并点击验证码按钮）
 */
async function handleCaptchaAutomatically(tabId, engine, sendLogToFrontend = null) {
    try {
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '');
            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            sendLogToFrontend('crawl', '🔍 [' + engine.name + '] 开始自动处理验证码（使用端侧模型分析）...');
        }

        // 提取验证码按钮候选（使用端侧模型）
        const buttonsResult = await chrome.scripting.executeScript({
            target: { tabId: tabId },
            func: findCaptchaButtons
        });

        const candidates = buttonsResult[0]?.result || [];

        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  → 端侧模型分析找到 ' + candidates.length + ' 个候选验证码按钮');
        }

        if (candidates.length === 0) {
            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ⚠️ 未找到验证码按钮，无法自动处理');
                sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            }
            return false;
        }

        // 显示所有候选按钮（带端侧模型分析结果）
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  📋 候选验证码按钮列表（端侧模型评分）:');
            candidates.slice(0, 5).forEach((candidate, idx) => {
                sendLogToFrontend('crawl', '     ' + (idx + 1) + '. 文本: "' + candidate.element.text + '"');
                sendLogToFrontend('crawl', '        分数: ' + candidate.score.toFixed(2));
                sendLogToFrontend('crawl', '        原因: ' + candidate.reasons.join(', '));
                if (candidate.inCaptchaContainer) {
                    sendLogToFrontend('crawl', '        位置: 在验证码容器中');
                }
            });
            if (candidates.length > 5) {
                sendLogToFrontend('crawl', '     ... 还有 ' + (candidates.length - 5) + ' 个候选');
            }
        }

        // 尝试最多3个候选按钮（按分数从高到低）
        const maxAttempts = Math.min(3, candidates.length);
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            const candidate = candidates[attempt];

            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  → 尝试点击候选按钮 ' + (attempt + 1) + '/' + maxAttempts + ': "' + candidate.element.text + '"');
            }

            // 点击按钮
            const clickResult = await chrome.scripting.executeScript({
                target: { tabId: tabId },
                func: function (elementInfo) {
                    // 根据元素信息查找元素
                    let element = null;

                    if (elementInfo.id) {
                        element = document.getElementById(elementInfo.id);
                    } else if (elementInfo.xpath) {
                        // 简单的XPath查找（仅支持id和class）
                        const xpathResult = document.evaluate(
                            elementInfo.xpath,
                            document,
                            null,
                            XPathResult.FIRST_ORDERED_NODE_TYPE,
                            null
                        );
                        element = xpathResult.singleNodeValue;
                    } else {
                        // 通过文本和标签名查找
                        const allElements = document.querySelectorAll(elementInfo.tagName);
                        for (const el of allElements) {
                            if (el.textContent.trim() === elementInfo.text) {
                                element = el;
                                break;
                            }
                        }
                    }

                    if (element) {
                        return new Promise(resolve => {
                            setTimeout(() => {
                                try {
                                    // 尝试多种点击方式
                                    if (element.click) {
                                        element.click();
                                    } else if (element.dispatchEvent) {
                                        const clickEvent = new MouseEvent('click', {
                                            bubbles: true,
                                            cancelable: true,
                                            view: window
                                        });
                                        element.dispatchEvent(clickEvent);
                                    }
                                    resolve({ success: true, message: '点击成功' });
                                } catch (e) {
                                    resolve({ success: false, message: '点击异常: ' + e.message });
                                }
                            }, 500);
                        });
                    } else {
                        return { success: false, message: '未找到元素' };
                    }
                },
                args: [candidate.element]
            });

            const clickSuccess = clickResult[0]?.result?.success || false;

            if (!clickSuccess) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '  ❌ 点击失败: ' + (clickResult[0]?.result?.message || '未知错误'));
                }
                continue; // 尝试下一个候选
            }

            if (sendLogToFrontend) {
                sendLogToFrontend('crawl', '  ✓ 点击成功，等待验证完成...');
            }

            // 等待验证完成（最多等待30秒）
            const maxWaitTime = 30000;
            const startWait = Date.now();
            let captchaResolved = false;

            while (Date.now() - startWait < maxWaitTime && !captchaResolved) {
                await sleep(2000); // 每2秒检查一次

                try {
                    // 检查验证码是否还存在
                    const checkResult = await chrome.scripting.executeScript({
                        target: { tabId: tabId },
                        func: function () {
                            const captchaSelectors = [
                                '#captcha', '.captcha', '[id*="captcha"]', '[class*="captcha"]',
                                '#verify', '.verify', '[id*="verify"]', '[class*="verify"]',
                                '#security-check', '.security-check',
                                'iframe[src*="captcha"]', 'iframe[src*="verify"]',
                                'iframe[src*="recaptcha"]', 'iframe[src*="hcaptcha"]'
                            ];

                            for (const selector of captchaSelectors) {
                                try {
                                    if (document.querySelector(selector)) {
                                        return true; // 验证码还存在
                                    }
                                } catch (e) {
                                    // 忽略选择器错误
                                }
                            }

                            // 检查页面是否变化（URL或标题变化）
                            const pageText = document.body ? document.body.innerText || document.body.textContent : '';
                            const captchaKeywords = ['验证码', 'captcha', 'verify', '验证', '人机验证'];
                            const stillHasKeyword = captchaKeywords.some(keyword =>
                                pageText.toLowerCase().includes(keyword.toLowerCase())
                            );

                            return stillHasKeyword;
                        }
                    });

                    const stillHasCaptcha = checkResult[0]?.result || false;
                    if (!stillHasCaptcha) {
                        captchaResolved = true;
                        if (sendLogToFrontend) {
                            sendLogToFrontend('crawl', '  ✓ 验证码已完成或消失');
                        }
                        break;
                    }
                } catch (e) {
                    console.warn('[AutoLeadAgent Extension] 检查验证码状态失败:', e);
                }
            }

            if (captchaResolved) {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                }
                return true; // 验证码处理成功
            } else {
                if (sendLogToFrontend) {
                    sendLogToFrontend('crawl', '  ⚠️ 等待30秒后验证码仍未完成，尝试下一个候选');
                }
                continue; // 尝试下一个候选
            }
        }

        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', '  ❌ 所有候选按钮都尝试失败，无法自动处理验证码');
            sendLogToFrontend('crawl', '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        }
        return false;

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 验证码处理异常:', error);
        if (sendLogToFrontend) {
            sendLogToFrontend('crawl', `  ❌ 验证码处理异常: ${error.message}`);
        }
        return false;
    }
}

/**
 * 睡眠函数
 */
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// 扩展安装时
chrome.runtime.onInstalled.addListener(() => {
    console.log('[AutoLeadAgent Extension] Installed');
});

// 点击扩展图标时
chrome.action.onClicked.addListener((tab) => {
    console.log('[AutoLeadAgent Extension] Icon clicked');
});

// ==================== HuggingFace 模型下载功能 ====================

// 存储下载进度监听器
const hfDownloadProgressListeners = new Map(); // Map<modelId, {port, tabId}>

// 存储 Port 连接（用于长时间下载操作，不受超时限制）
const downloadPorts = new Map(); // Map<modelId, Port>

/**
 * 检查 HuggingFace 登录状态
 */
async function handleHfCheckLogin(request, sendResponse) {
    try {
        console.log('[AutoLeadAgent Extension] 检查 HuggingFace 登录状态');

        // 尝试访问 whoami API 检查登录状态
        const response = await fetch('https://huggingface.co/api/whoami-v2', {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (response.ok) {
            const userInfo = await response.json();
            console.log('[AutoLeadAgent Extension] HuggingFace 已登录:', userInfo.name || userInfo.username);
            const result = {
                success: true,
                loggedIn: true,
                user: userInfo.name || userInfo.username || 'Unknown'
            };
            console.log('[AutoLeadAgent Extension] 发送登录检查响应:', result);
            sendResponse(result);
        } else {
            console.log('[AutoLeadAgent Extension] HuggingFace 未登录，状态码:', response.status);
            const result = {
                success: true,
                loggedIn: false
            };
            console.log('[AutoLeadAgent Extension] 发送登录检查响应:', result);
            sendResponse(result);
        }
    } catch (error) {
        console.error('[AutoLeadAgent Extension] 检查登录状态失败:', error);
        const result = {
            success: false,
            error: error.message || '检查登录状态失败'
        };
        console.log('[AutoLeadAgent Extension] 发送登录检查错误响应:', result);
        sendResponse(result);
    }
}

/**
 * 处理 HuggingFace 模型下载请求
 */
async function handleHfDownloadModel(request, sendResponse) {
    const { modelId } = request;

    if (!modelId) {
        sendResponse({
            success: false,
            error: '模型ID不能为空'
        });
        return;
    }

    console.log('[AutoLeadAgent Extension] 开始下载模型:', modelId);

    // 使用安全响应包装，防止重复响应
    let hasResponded = false;
    const safeSendResponse = (response) => {
        if (hasResponded) {
            console.warn('[AutoLeadAgent Extension] 尝试重复发送下载响应，已忽略');
            return;
        }
        hasResponded = true;
        try {
            sendResponse(response);
        } catch (e) {
            console.error('[AutoLeadAgent Extension] 发送下载响应失败:', e);
        }
    };

    try {
        // 1. 先检查登录状态
        const loginCheck = await fetch('https://huggingface.co/api/whoami-v2', {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!loginCheck.ok) {
            // 未登录，打开登录页面
            console.log('[AutoLeadAgent Extension] 未登录，打开登录页面');
            const loginTab = await chrome.tabs.create({
                url: 'https://huggingface.co/login',
                active: true
            });

            // 开始监听登录页面的登录状态
            startLoginDetection(loginTab.id, modelId);

            safeSendResponse({
                success: false,
                needLogin: true,
                loginTabId: loginTab.id,
                message: '需要登录 HuggingFace，已打开登录页面'
            });
            return;
        }

        // 2. 已登录，开始下载模型（异步执行，不阻塞其他消息）
        console.log('[AutoLeadAgent Extension] 已登录，开始下载模型');

        // 立即返回"已开始下载"的响应，实际下载在后台进行
        // 下载进度通过 HF_DOWNLOAD_PROGRESS 消息实时更新
        safeSendResponse({
            success: true,
            started: true,
            modelId: modelId,
            message: '模型下载已开始，请查看进度更新'
        });

        // 在后台异步执行下载（不阻塞消息通道）
        downloadHfModel(modelId, (response) => {
            // 下载完成或失败时，通过进度消息通知（而不是通过 sendResponse，因为已经响应过了）
            console.log('[AutoLeadAgent Extension] 模型下载完成:', response);
            // 最终状态通过 HF_DOWNLOAD_PROGRESS 消息的 100% 进度来通知
        }).catch(error => {
            console.error('[AutoLeadAgent Extension] 下载模型后台执行失败:', error);
            // 错误通过进度消息通知
        });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 下载模型失败:', error);
        safeSendResponse({
            success: false,
            error: error.message || '下载模型失败'
        });
    }
}

/**
 * 开始监听登录状态（用于下载模型）
 */
function startLoginDetection(loginTabId, modelId) {
    console.log('[AutoLeadAgent Extension] 开始监听登录状态，tabId:', loginTabId);

    const checkInterval = setInterval(async () => {
        try {
            const response = await fetch('https://huggingface.co/api/whoami-v2', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                // 登录成功
                console.log('[AutoLeadAgent Extension] 检测到登录成功');
                clearInterval(checkInterval);

                // 通知所有配置页面登录成功
                const loginOkMessage = {
                    type: 'HF_LOGIN_OK',
                    modelId: modelId
                };

                // 发送到所有标签页的 content script
                chrome.tabs.query({}, function (tabs) {
                    tabs.forEach(function (tab) {
                        try {
                            chrome.tabs.sendMessage(tab.id, loginOkMessage).catch(() => {
                                // 忽略错误
                            });
                        } catch (e) {
                            // 忽略错误
                        }
                    });
                });

                // 可选：关闭登录标签页
                try {
                    await chrome.tabs.remove(loginTabId);
                } catch (e) {
                    // 忽略关闭失败
                }
            }
        } catch (error) {
            // 继续等待
        }
    }, 2000); // 每2秒检查一次

    // 30分钟后停止检查
    setTimeout(() => {
        clearInterval(checkInterval);
    }, 30 * 60 * 1000);
}

/**
 * 开始监听登录状态（用于获取模型信息）
 */
function startLoginDetectionForModelInfo(loginTabId, modelId) {
    console.log('[AutoLeadAgent Extension] 开始监听登录状态（获取模型信息），tabId:', loginTabId);

    const checkInterval = setInterval(async () => {
        try {
            const response = await fetch('https://huggingface.co/api/whoami-v2', {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (response.ok) {
                // 登录成功
                console.log('[AutoLeadAgent Extension] 检测到登录成功（获取模型信息）');
                clearInterval(checkInterval);

                // 通知所有配置页面登录成功（用于获取模型信息）
                const loginOkMessage = {
                    type: 'HF_LOGIN_OK_FOR_MODEL_INFO',
                    modelId: modelId
                };

                // 发送到所有标签页的 content script
                chrome.tabs.query({}, function (tabs) {
                    tabs.forEach(function (tab) {
                        try {
                            chrome.tabs.sendMessage(tab.id, loginOkMessage).catch(() => {
                                // 忽略错误
                            });
                        } catch (e) {
                            // 忽略错误
                        }
                    });
                });

                // 可选：关闭登录标签页
                try {
                    await chrome.tabs.remove(loginTabId);
                } catch (e) {
                    // 忽略关闭失败
                }
            }
        } catch (error) {
            // 继续等待
        }
    }, 2000); // 每2秒检查一次

    // 30分钟后停止检查
    setTimeout(() => {
        clearInterval(checkInterval);
    }, 30 * 60 * 1000);
}

/**
 * 正确编码模型ID（保留斜杠，只编码每个部分）
 * 注意：模型名称中的斜杠不应该被编码，直接使用即可
 * encodeURIComponent 会将斜杠编码为 %2F，导致 API 返回 400 错误 "repo name includes an url-encoded slash"
 */
function encodeModelId(modelId) {
    if (!modelId) return '';
    // 将模型ID按斜杠分割，分别编码每个部分，然后重新组合
    return modelId.split('/').map(part => encodeURIComponent(part)).join('/');
}

/**
 * 已废弃：不再使用 IndexedDB 存储，改为本地文件系统存储
 * 文件现在通过 Port 发送到前端，由前端使用 LocalFileStorage 保存到本地文件系统
 */

/**
 * 下载 HuggingFace 模型
 */
async function downloadHfModel(modelId, onComplete) {
    try {
        console.log('[AutoLeadAgent Extension] 开始下载模型文件:', modelId);

        // 1. 获取模型文件列表
        const modelInfoUrl = `https://huggingface.co/api/models/${encodeModelId(modelId)}`;
        const modelInfoResponse = await fetch(modelInfoUrl, {
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!modelInfoResponse.ok) {
            throw new Error(`获取模型信息失败: ${modelInfoResponse.status}`);
        }

        const modelInfo = await modelInfoResponse.json();
        console.log('[AutoLeadAgent Extension] 模型信息:', modelInfo);

        // 2. 获取文件列表（优先使用 siblings API，如果失败则从 modelInfo 中获取）
        let siblings = [];
        const siblingsUrl = `https://huggingface.co/api/models/${encodeModelId(modelId)}/siblings`;

        try {
            const siblingsResponse = await fetch(siblingsUrl, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (siblingsResponse.ok) {
                siblings = await siblingsResponse.json();
                console.log('[AutoLeadAgent Extension] 从 siblings API 获取文件列表:', siblings);
            } else {
                // siblings API 失败（可能是 404），尝试从 modelInfo 中获取
                console.warn('[AutoLeadAgent Extension] siblings API 返回', siblingsResponse.status, '，尝试从 modelInfo 中获取文件列表');

                if (modelInfo.siblings && Array.isArray(modelInfo.siblings)) {
                    siblings = modelInfo.siblings;
                    console.log('[AutoLeadAgent Extension] 从 modelInfo 获取文件列表:', siblings);
                } else {
                    // 如果都没有，抛出错误
                    throw new Error(`获取文件列表失败: siblings API 返回 ${siblingsResponse.status}，且 modelInfo 中无 siblings 数据`);
                }
            }
        } catch (error) {
            // 如果 fetch 失败或解析失败，尝试从 modelInfo 中获取
            console.warn('[AutoLeadAgent Extension] 获取 siblings API 失败:', error.message);

            if (modelInfo.siblings && Array.isArray(modelInfo.siblings)) {
                siblings = modelInfo.siblings;
                console.log('[AutoLeadAgent Extension] 从 modelInfo 获取文件列表（fallback）:', siblings);
            } else {
                // 如果都没有，抛出错误
                throw new Error(`获取文件列表失败: ${error.message}`);
            }
        }

        if (!siblings || siblings.length === 0) {
            throw new Error('模型文件列表为空，无法下载');
        }

        // 3. 筛选需要下载的文件（模型权重、tokenizer等）
        const importantFiles = siblings.filter(file => {
            const name = file.rfilename || file.filename || '';
            return name.endsWith('.safetensors') ||
                name.endsWith('.bin') ||
                name.endsWith('.json') ||
                name === 'tokenizer.json' ||
                name === 'config.json' ||
                name.startsWith('tokenizer_config.json');
        });

        console.log('[AutoLeadAgent Extension] 需要下载的文件:', importantFiles.length);

        // 4. 计算总大小（先尝试从文件信息获取，如果失败则通过 HEAD 请求获取实际大小）
        let totalSize = 0;
        for (const file of importantFiles) {
            totalSize += file.size || 0;
        }

        console.log('[AutoLeadAgent Extension] 从文件信息计算的总大小:', totalSize, '字节');

        // 如果总大小为0，通过 HEAD 请求获取所有文件的实际大小
        if (totalSize === 0) {
            console.log('[AutoLeadAgent Extension] 文件信息中没有大小，通过 HEAD 请求获取实际大小...');
            for (const file of importantFiles) {
                const filename = file.rfilename || file.filename;
                const fileUrl = `https://huggingface.co/${encodeModelId(modelId)}/resolve/main/${encodeURIComponent(filename)}`;

                try {
                    const headResponse = await fetch(fileUrl, {
                        method: 'HEAD',
                        credentials: 'include'
                    });

                    if (headResponse.ok) {
                        const contentLength = parseInt(headResponse.headers.get('content-length') || '0', 10);
                        if (contentLength > 0) {
                            file.size = contentLength;
                            totalSize += contentLength;
                            console.log('[AutoLeadAgent Extension] 获取文件大小:', filename, contentLength, '字节');
                        }
                    }
                } catch (error) {
                    console.warn('[AutoLeadAgent Extension] 获取文件大小失败:', filename, error.message);
                    // 继续处理其他文件
                }
            }

            console.log('[AutoLeadAgent Extension] 通过 HEAD 请求获取的总大小:', totalSize, '字节');
        }

        // 如果仍然为0，使用估算值（基于文件数量）
        if (totalSize === 0) {
            console.warn('[AutoLeadAgent Extension] 无法获取文件大小，使用估算值');
            // 估算：每个文件平均 100MB（保守估计）
            totalSize = importantFiles.length * 100 * 1024 * 1024;
            console.log('[AutoLeadAgent Extension] 使用估算总大小:', totalSize, '字节（', importantFiles.length, '个文件 × 100MB）');
        }

        // 5. 开始下载文件
        let downloadedSize = 0;
        const actualTotalSize = totalSize; // 使用计算出的总大小
        const downloadedFiles = [];
        let lastProgressUpdate = 0; // 上次进度更新时间（用于节流）
        const PROGRESS_UPDATE_INTERVAL = 200; // 每200ms更新一次进度

        // 发送初始进度（0%）
        const sendProgressUpdate = (filename, downloaded, total, progress) => {
            const now = Date.now();
            // 节流：避免过于频繁的更新
            if (now - lastProgressUpdate < PROGRESS_UPDATE_INTERVAL && progress < 100) {
                return;
            }
            lastProgressUpdate = now;

            const progressMessage = {
                type: 'HF_DOWNLOAD_PROGRESS',
                modelId: modelId,
                filename: filename,
                downloaded: downloaded,
                total: total,
                progress: progress
            };

            // 发送到所有标签页的 content script，由 content script 转发到页面
            chrome.tabs.query({}, function (tabs) {
                tabs.forEach(function (tab) {
                    try {
                        chrome.tabs.sendMessage(tab.id, progressMessage).catch(() => {
                            // 忽略错误，可能没有 content script 或不是配置页面
                        });
                    } catch (e) {
                        // 忽略错误
                    }
                });
            });
        };

        // 发送初始进度（确保总大小不为0）
        console.log('[AutoLeadAgent Extension] 开始下载，总大小:', actualTotalSize, '字节');
        sendProgressUpdate('准备下载...', 0, actualTotalSize, 0);

        for (const file of importantFiles) {
            const filename = file.rfilename || file.filename;
            const fileUrl = `https://huggingface.co/${encodeModelId(modelId)}/resolve/main/${encodeURIComponent(filename)}`;

            console.log('[AutoLeadAgent Extension] 下载文件:', filename, '大小:', file.size || '未知');

            try {
                const fileResponse = await fetch(fileUrl, {
                    credentials: 'include'
                });

                if (!fileResponse.ok) {
                    console.warn('[AutoLeadAgent Extension] 下载文件失败:', filename, fileResponse.status);
                    continue;
                }

                // 获取实际文件大小（优先使用 content-length，其次使用文件信息中的 size）
                const contentLength = parseInt(fileResponse.headers.get('content-length') || '0', 10);
                const fileSize = contentLength > 0 ? contentLength : (file.size || 0);

                // 如果文件大小与预期不符，更新文件信息（但不改变总大小，因为已经在开始前计算好了）
                if (fileSize > 0 && file.size !== fileSize) {
                    console.log('[AutoLeadAgent Extension] 文件实际大小与预期不同:', filename, '预期:', file.size, '实际:', fileSize);
                    file.size = fileSize;
                }

                const reader = fileResponse.body.getReader();
                const chunks = [];
                let fileDownloadedSize = 0; // 当前文件已下载大小

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    chunks.push(value);
                    fileDownloadedSize += value.byteLength;
                    downloadedSize += value.byteLength;

                    // 计算进度（确保总大小不为0）
                    let progress = 0;
                    if (actualTotalSize > 0) {
                        progress = Math.min(100, (downloadedSize / actualTotalSize) * 100);
                    } else {
                        // 如果总大小仍然为0（不应该发生），使用文件大小估算
                        console.warn('[AutoLeadAgent Extension] 总大小为0，使用文件大小估算进度');
                        progress = fileSize > 0 ? Math.min(100, (downloadedSize / fileSize) * 100) : 0;
                    }

                    // 发送进度更新（节流）
                    sendProgressUpdate(filename, downloadedSize, actualTotalSize || fileSize, progress);
                }

                // 文件下载完成，发送完成进度
                let fileProgress = 0;
                if (actualTotalSize > 0) {
                    fileProgress = Math.min(100, (downloadedSize / actualTotalSize) * 100);
                } else if (fileSize > 0) {
                    fileProgress = Math.min(100, (downloadedSize / fileSize) * 100);
                }
                sendProgressUpdate(filename, downloadedSize, actualTotalSize || fileSize, fileProgress);

                // 合并 chunks 为 ArrayBuffer
                const totalLength = chunks.reduce((acc, chunk) => acc + chunk.length, 0);
                const arrayBuffer = new ArrayBuffer(totalLength);
                const uint8Array = new Uint8Array(arrayBuffer);
                let offset = 0;
                for (const chunk of chunks) {
                    uint8Array.set(chunk, offset);
                    offset += chunk.length;
                }

                // 保存到 IndexedDB（通过消息通知前端保存）
                downloadedFiles.push({
                    filename: filename,
                    size: arrayBuffer.byteLength,
                    type: 'application/octet-stream'
                });

                // 通知前端文件下载完成
                // 注意：大文件不能直接通过消息传递，需要通过 IndexedDB 或其他方式
                // 这里先通知前端，然后通过 chrome.storage 临时存储文件元数据
                const fileDownloadedMessage = {
                    type: 'HF_FILE_DOWNLOADED',
                    modelId: modelId,
                    filename: filename,
                    size: arrayBuffer.byteLength
                };

                // 发送到所有标签页的 content script
                chrome.tabs.query({}, function (tabs) {
                    tabs.forEach(function (tab) {
                        try {
                            chrome.tabs.sendMessage(tab.id, fileDownloadedMessage).catch(() => {
                                // 忽略错误
                            });
                        } catch (e) {
                            // 忽略错误
                        }
                    });
                });

                // 将文件数据保存到 chrome.storage（临时方案，大文件可能有问题）
                // 注意：chrome.storage.local 有 10MB 限制，大文件需要使用 IndexedDB
                // 这里只保存小文件（< 5MB），大文件需要前端通过其他方式处理
                if (arrayBuffer.byteLength < 5 * 1024 * 1024) {
                    const key = `hf_model_${modelId}_${filename}`;
                    chrome.storage.local.set({
                        [key]: {
                            data: Array.from(uint8Array), // 转换为普通数组以便存储
                            modelId: modelId,
                            filename: filename,
                            size: arrayBuffer.byteLength
                        }
                    }).catch(err => {
                        console.error('[AutoLeadAgent Extension] 保存文件到 storage 失败:', err);
                    });
                } else {
                    console.warn('[AutoLeadAgent Extension] 文件过大，跳过 chrome.storage 保存，需要使用 IndexedDB:', filename, arrayBuffer.byteLength);
                }

                console.log('[AutoLeadAgent Extension] 文件下载完成:', filename, '大小:', blob.size);
            } catch (error) {
                console.error('[AutoLeadAgent Extension] 下载文件异常:', filename, error);
            }
        }

        // 6. 下载完成
        const finalTotalSize = actualTotalSize || totalSize || downloadedSize;
        console.log('[AutoLeadAgent Extension] 模型下载完成:', modelId, '总大小:', finalTotalSize, '字节，已下载:', downloadedSize, '字节');

        // 发送最终进度（100%）
        sendProgressUpdate('下载完成', downloadedSize, finalTotalSize, 100);

        // 调用完成回调（如果提供）
        if (onComplete) {
            onComplete({
                success: true,
                modelId: modelId,
                downloadedFiles: downloadedFiles.length,
                totalSize: finalTotalSize,
                downloadedSize: downloadedSize
            });
        }

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 下载模型文件失败:', error);

        // 发送错误进度消息
        const errorMessage = {
            type: 'HF_DOWNLOAD_PROGRESS',
            modelId: modelId,
            filename: '下载失败',
            downloaded: 0,
            total: 0,
            progress: -1, // 使用 -1 表示错误
            error: error.message || '下载模型失败'
        };

        chrome.tabs.query({}, function (tabs) {
            tabs.forEach(function (tab) {
                try {
                    chrome.tabs.sendMessage(tab.id, errorMessage).catch(() => { });
                } catch (e) { }
            });
        });

        // 调用完成回调（如果提供）
        if (onComplete) {
            onComplete({
                success: false,
                error: error.message || '下载模型失败'
            });
        }
    }
}

/**
 * 通过 Port 下载 HuggingFace 模型（使用 port.postMessage 发送进度）
 */
async function downloadHfModelViaPort(modelId, port) {
    try {
        console.log('[AutoLeadAgent Extension] 开始通过 Port 下载模型文件:', modelId);

        // 1. 获取模型文件列表
        const modelInfoUrl = `https://huggingface.co/api/models/${encodeModelId(modelId)}`;
        const modelInfoResponse = await fetch(modelInfoUrl, {
            credentials: 'include',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!modelInfoResponse.ok) {
            throw new Error(`获取模型信息失败: ${modelInfoResponse.status}`);
        }

        const modelInfo = await modelInfoResponse.json();
        console.log('[AutoLeadAgent Extension] 模型信息:', modelInfo);

        // 2. 获取文件列表
        let siblings = [];
        const siblingsUrl = `https://huggingface.co/api/models/${encodeModelId(modelId)}/siblings`;

        try {
            const siblingsResponse = await fetch(siblingsUrl, {
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (siblingsResponse.ok) {
                siblings = await siblingsResponse.json();
            } else {
                if (modelInfo.siblings && Array.isArray(modelInfo.siblings)) {
                    siblings = modelInfo.siblings;
                } else {
                    throw new Error(`获取文件列表失败: siblings API 返回 ${siblingsResponse.status}`);
                }
            }
        } catch (error) {
            if (modelInfo.siblings && Array.isArray(modelInfo.siblings)) {
                siblings = modelInfo.siblings;
            } else {
                throw new Error(`获取文件列表失败: ${error.message}`);
            }
        }

        if (!siblings || siblings.length === 0) {
            throw new Error('模型文件列表为空，无法下载');
        }

        // 3. 筛选需要下载的文件
        const importantFiles = siblings.filter(file => {
            const name = file.rfilename || file.filename || '';
            return name.endsWith('.safetensors') ||
                name.endsWith('.bin') ||
                name.endsWith('.json') ||
                name === 'tokenizer.json' ||
                name === 'config.json' ||
                name.startsWith('tokenizer_config.json');
        });

        console.log('[AutoLeadAgent Extension] 需要下载的文件:', importantFiles.length);

        // 4. 计算总大小
        let totalSize = 0;
        for (const file of importantFiles) {
            totalSize += file.size || 0;
        }

        if (totalSize === 0) {
            // 通过 HEAD 请求获取实际大小
            for (const file of importantFiles) {
                const filename = file.rfilename || file.filename;
                const fileUrl = `https://huggingface.co/${encodeModelId(modelId)}/resolve/main/${encodeURIComponent(filename)}`;

                try {
                    const headResponse = await fetch(fileUrl, {
                        method: 'HEAD',
                        credentials: 'include'
                    });

                    if (headResponse.ok) {
                        const contentLength = parseInt(headResponse.headers.get('content-length') || '0', 10);
                        if (contentLength > 0) {
                            file.size = contentLength;
                            totalSize += contentLength;
                        }
                    }
                } catch (error) {
                    console.warn('[AutoLeadAgent Extension] 获取文件大小失败:', filename);
                }
            }
        }

        if (totalSize === 0) {
            totalSize = importantFiles.length * 100 * 1024 * 1024; // 估算值
        }

        // 5. 开始下载文件
        let downloadedSize = 0;
        const actualTotalSize = totalSize;
        const downloadedFiles = [];
        let lastProgressUpdate = 0;
        const PROGRESS_UPDATE_INTERVAL = 200;

        // 通过 Port 发送进度更新
        const sendProgressUpdate = (filename, downloaded, total, progress) => {
            const now = Date.now();
            if (now - lastProgressUpdate < PROGRESS_UPDATE_INTERVAL && progress < 100) {
                return;
            }
            lastProgressUpdate = now;

            port.postMessage({
                type: 'download-progress',
                modelId: modelId,
                filename: filename,
                downloaded: downloaded,
                total: total,
                progress: progress
            });
        };

        // 发送初始进度
        sendProgressUpdate('准备下载...', 0, actualTotalSize, 0);

        for (const file of importantFiles) {
            const filename = file.rfilename || file.filename;
            const fileUrl = `https://huggingface.co/${encodeModelId(modelId)}/resolve/main/${encodeURIComponent(filename)}`;

            console.log('[AutoLeadAgent Extension] 下载文件:', filename);

            try {
                const fileResponse = await fetch(fileUrl, {
                    credentials: 'include'
                });

                if (!fileResponse.ok) {
                    console.warn('[AutoLeadAgent Extension] 下载文件失败:', filename, fileResponse.status);
                    continue;
                }

                const contentLength = parseInt(fileResponse.headers.get('content-length') || '0', 10);
                const fileSize = contentLength > 0 ? contentLength : (file.size || 0);

                const reader = fileResponse.body.getReader();
                let fileDownloadedSize = 0;

                // 发送文件开始消息
                port.postMessage({
                    type: 'download-file-start',
                    modelId: modelId,
                    filename: filename,
                    size: fileSize
                });

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    fileDownloadedSize += value.byteLength;
                    downloadedSize += value.byteLength;

                    // 计算进度
                    let progress = 0;
                    if (actualTotalSize > 0) {
                        progress = Math.min(100, (downloadedSize / actualTotalSize) * 100);
                    } else if (fileSize > 0) {
                        progress = Math.min(100, (downloadedSize / fileSize) * 100);
                    }

                    sendProgressUpdate(filename, downloadedSize, actualTotalSize || fileSize, progress);

                    // 立即将数据块发送到前端，不再在后台进行 ArrayBuffer 合并
                    // 注意：使用 slice() 复制数据，避免 transferable 导致数据被清空
                    const dataCopy = value.buffer.slice(0, value.byteLength);
                    port.postMessage({
                        type: 'download-file-chunk',
                        modelId: modelId,
                        filename: filename,
                        data: dataCopy,
                        offset: fileDownloadedSize - value.byteLength,
                        isComplete: false
                    });
                }

                // 通知前端文件分块发送完成
                port.postMessage({
                    type: 'download-file-chunk',
                    modelId: modelId,
                    filename: filename,
                    data: new ArrayBuffer(0),
                    offset: fileDownloadedSize,
                    isComplete: true
                });

                // 通知前端文件下载完成
                port.postMessage({
                    type: 'download-file-complete',
                    modelId: modelId,
                    filename: filename,
                    size: fileDownloadedSize
                });

                console.log('[AutoLeadAgent Extension] 文件下载完成并分块发送:', filename);
            } catch (error) {
                console.error('[AutoLeadAgent Extension] 下载文件异常:', filename, error);
            }
        }

        // 6. 下载完成
        const finalTotalSize = actualTotalSize || totalSize || downloadedSize;
        console.log('[AutoLeadAgent Extension] 模型下载完成:', modelId, '总大小:', finalTotalSize);

        // 发送最终进度（100%）
        sendProgressUpdate('下载完成', downloadedSize, finalTotalSize, 100);

        // 发送完成消息
        port.postMessage({
            type: 'download-complete',
            modelId: modelId,
            downloadedFiles: downloadedFiles.length,
            totalSize: finalTotalSize,
            downloadedSize: downloadedSize
        });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] Port 下载模型失败:', error);

        port.postMessage({
            type: 'download-error',
            modelId: modelId,
            error: error.message || '下载模型失败'
        });

        throw error;
    } finally {
        // 清理 Port 连接
        downloadPorts.delete(modelId);
    }
}

/**
 * 处理 HuggingFace 模型搜索请求
 */
async function handleHfSearchModels(request, sendResponse) {
    try {
        const { query = '', task = 'text-generation', limit = 50 } = request;

        console.log('[AutoLeadAgent Extension] 搜索模型:', { query, task, limit });

        // 构建搜索 URL
        const params = new URLSearchParams({
            limit: String(limit),
            sort: 'downloads',
            direction: '-1'
        });

        // 添加扩展字段以获取模型权重大小（safetensors 信息）
        params.append('expand', 'safetensors');
        params.append('expand', 'downloads');
        params.append('expand', 'author');
        params.append('expand', 'likes');
        params.append('expand', 'siblings');

        if (query) {
            params.append('search', query);
        }
        if (task) {
            params.append('task', task);
        }

        // 使用 expand 参数获取完整数据
        const url = `https://huggingface.co/api/models?${params.toString()}`;
        console.log('[AutoLeadAgent Extension] 搜索 URL:', url);

        // 添加超时控制
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30秒超时

        let response;
        try {
            response = await fetch(url, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'User-Agent': 'WelineFramework-AutoLeadAgent/1.0'
                },
                signal: controller.signal
            });
            clearTimeout(timeoutId);
        } catch (fetchError) {
            clearTimeout(timeoutId);

            // 提供更详细的错误信息
            let errorMessage = '网络请求失败';
            if (fetchError.name === 'AbortError') {
                errorMessage = '请求超时（30秒），请检查网络连接';
            } else if (fetchError.message) {
                errorMessage = '网络错误: ' + fetchError.message;
            } else if (fetchError.toString) {
                errorMessage = '网络错误: ' + fetchError.toString();
            }

            console.error('[AutoLeadAgent Extension] Fetch 失败:', fetchError);
            console.error('[AutoLeadAgent Extension] 错误类型:', fetchError.name);
            console.error('[AutoLeadAgent Extension] 错误消息:', fetchError.message);

            throw new Error(errorMessage);
        }

        if (!response.ok) {
            const errorText = await response.text().catch(() => '');
            console.error('[AutoLeadAgent Extension] 搜索模型失败:', response.status, response.statusText, errorText);
            throw new Error(`搜索失败: ${response.status} ${response.statusText}${errorText ? ' - ' + errorText : ''}`);
        }

        const models = await response.json();
        if (!Array.isArray(models)) {
            console.error('[AutoLeadAgent Extension] API 返回格式错误，不是数组:', typeof models, models);
            throw new Error('API 返回格式错误');
        }

        console.log('[AutoLeadAgent Extension] 搜索返回', models.length, '个模型（过滤前）');

        // 过滤和格式化模型数据
        const formattedModels = [];
        for (const model of models) {
            // 基础字段检查：API 可能返回 id 或 modelId
            const modelId = model.id || model.modelId;
            if (!modelId) {
                continue;
            }

            // 1) 检查任务类型
            const pipelineTag = model.pipeline_tag || model.pipelineTag || '';
            // 如果用户指定了任务类型，API 已经过滤了，但我们这里可以做二次校验

            // 2) 检查库类型
            const library = model.library_name || model.libraryName || '';

            // 3) 标签检查
            const tags = model.tags || [];

            // 过滤掉有限制 / 受控访问的模型
            const isPrivate = model.private === true;
            const isGated = model.gated === true;
            const isMetaLlama = modelId.startsWith('meta-llama/');

            if (isPrivate || isGated || isMetaLlama) {
                continue;
            }

            // 估算模型大小
            let totalSize = 0;
            // 优先检查顶层 size (有些 API 返回会直接带上)
            if (model.size) {
                totalSize = parseInt(model.size, 10);
            }
            // 其次从 safetensors 权重信息中获取（expand=safetensors）
            else if (model.safetensors && model.safetensors.total) {
                totalSize = parseInt(model.safetensors.total, 10);
            }
            // 再次尝试从文件列表累加（需要 expand=siblings 或 full=true）
            else if (Array.isArray(model.siblings)) {
                for (const sibling of model.siblings) {
                    if (sibling.size) {
                        totalSize += parseInt(sibling.size, 10);
                    }
                }
            }

            // 针对某些模型，即使没有 safetensors 也有 downloads 信息，这里记录一下
            const estimatedSizeMB = totalSize > 0 ? Math.round(totalSize / 1024 / 1024) : 0;

            if (estimatedSizeMB === 0) {
                console.log('[AutoLeadAgent Extension] 模型大小为 0:', modelId, 'safetensors:', !!model.safetensors, 'siblings:', model.siblings ? model.siblings.length : 0);
            }

            formattedModels.push({
                id: modelId,
                name: modelId,
                author: model.author || '',
                downloads: model.downloads || 0,
                likes: model.likes || 0,
                pipeline_tag: pipelineTag,
                tags: tags,
                library_name: library || null,
                model_index: model.model_index || null,
                estimated_size_mb: estimatedSizeMB
            });
        }

        console.log('[AutoLeadAgent Extension] 搜索完成，找到', formattedModels.length, '个模型（过滤后）');

        const result = {
            success: true,
            data: formattedModels,
            total: formattedModels.length
        };
        console.log('[AutoLeadAgent Extension] 发送搜索响应:', result);

        // 确保 sendResponse 被调用
        if (sendResponse) {
            try {
                sendResponse(result);
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 发送响应失败:', e);
            }
        }

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 搜索模型失败:', error);
        console.error('[AutoLeadAgent Extension] 错误堆栈:', error.stack);

        // 提供更友好的错误信息
        let errorMessage = error.message || '搜索模型失败';
        if (errorMessage.includes('Failed to fetch') || errorMessage.includes('network')) {
            errorMessage = '网络连接失败，请检查：\n1. 网络连接是否正常\n2. 是否可以访问 huggingface.co\n3. 防火墙或代理设置';
        } else if (errorMessage.includes('timeout') || errorMessage.includes('超时')) {
            errorMessage = '请求超时，请检查网络连接或稍后重试';
        }

        const errorResult = {
            success: false,
            error: errorMessage,
            errorType: error.name || 'NetworkError',
            originalError: error.message || 'Unknown error'
        };
        console.log('[AutoLeadAgent Extension] 发送错误响应:', errorResult);

        // 确保 sendResponse 被调用
        if (sendResponse) {
            try {
                sendResponse(errorResult);
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 发送错误响应失败:', e);
            }
        }
    }
}

/**
 * 处理 HuggingFace 获取模型信息请求
 */
async function handleHfGetModelInfo(request, sendResponse) {
    let hasResponded = false;
    const safeSendResponse = (response) => {
        if (!hasResponded) {
            hasResponded = true;
            try {
                sendResponse(response);
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 发送响应失败:', e);
            }
        } else {
            console.warn('[AutoLeadAgent Extension] 尝试重复发送响应，已忽略');
        }
    };

    try {
        const { modelId } = request;

        if (!modelId) {
            safeSendResponse({
                success: false,
                error: '模型ID不能为空'
            });
            return;
        }

        console.log('[AutoLeadAgent Extension] 获取模型信息:', modelId);

        // 调用 Hugging Face API 获取模型信息
        // 增加 expand=safetensors 以获取准确的权重体积
        const url = `https://huggingface.co/api/models/${encodeModelId(modelId)}?expand=safetensors`;
        console.log('[AutoLeadAgent Extension] 模型信息 URL:', url);

        const response = await fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Accept': 'application/json',
                'User-Agent': 'WelineFramework-AutoLeadAgent/1.0'
            }
        });

        if (!response.ok) {
            // 如果是400或401错误，先读取错误信息（response 只能读取一次）
            if (response.status === 400 || response.status === 401) {
                // 先读取错误信息（response.text() 只能调用一次）
                let errorText = '';
                try {
                    errorText = await response.text();
                    console.log('[AutoLeadAgent Extension] 400/401 错误信息:', errorText);
                } catch (e) {
                    console.warn('[AutoLeadAgent Extension] 读取错误信息失败:', e);
                }

                // 检查是否是明确的编码错误（如 "Invalid repo name" 且包含 "url-encoded"）
                const isEncodingError = errorText && (
                    errorText.includes('Invalid repo name') &&
                    errorText.includes('url-encoded')
                );

                // 如果不是明确的编码错误，先检查登录状态
                if (!isEncodingError) {
                    let isLoggedIn = false;
                    try {
                        const loginCheck = await fetch('https://huggingface.co/api/whoami-v2', {
                            method: 'GET',
                            credentials: 'include',
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        isLoggedIn = loginCheck.ok;
                        console.log('[AutoLeadAgent Extension] 登录状态检查结果:', isLoggedIn);
                    } catch (e) {
                        // 检查登录状态失败，假设未登录
                        console.warn('[AutoLeadAgent Extension] 检查登录状态失败:', e);
                    }

                    // 如果未登录，打开登录页面
                    if (!isLoggedIn) {
                        console.log('[AutoLeadAgent Extension] 获取模型信息需要登录，打开登录页面');
                        const loginTab = await chrome.tabs.create({
                            url: 'https://huggingface.co/login',
                            active: true
                        });

                        // 开始监听登录页面的登录状态
                        startLoginDetectionForModelInfo(loginTab.id, modelId);

                        safeSendResponse({
                            success: false,
                            needLogin: true,
                            loginTabId: loginTab.id,
                            message: '获取模型信息需要登录 HuggingFace，已打开登录页面'
                        });
                        return;
                    }

                    // 如果已登录但仍然是400/401，检查错误信息是否表明需要更多权限
                    const needsMoreAccess = errorText && (
                        errorText.includes('gated') ||
                        errorText.includes('access') ||
                        errorText.includes('permission') ||
                        errorText.includes('private') ||
                        errorText.includes('unauthorized') ||
                        errorText.includes('forbidden')
                    );

                    if (needsMoreAccess) {
                        console.log('[AutoLeadAgent Extension] 模型可能需要更多权限，打开登录页面');
                        const loginTab = await chrome.tabs.create({
                            url: 'https://huggingface.co/login',
                            active: true
                        });

                        // 开始监听登录页面的登录状态
                        startLoginDetectionForModelInfo(loginTab.id, modelId);

                        safeSendResponse({
                            success: false,
                            needLogin: true,
                            loginTabId: loginTab.id,
                            message: '该模型可能需要登录或申请访问权限，已打开登录页面'
                        });
                        return;
                    }
                }

                // 如果是编码错误或已登录但仍然是400，返回错误信息
                safeSendResponse({
                    success: false,
                    error: `获取模型信息失败: ${response.status} ${response.statusText}` + (errorText ? ' - ' + errorText : '')
                });
                return;
            }

            // 其他状态码的错误
            let errorMessage = `获取模型信息失败: ${response.status} ${response.statusText}`;
            try {
                const errorData = await response.text();
                if (errorData) {
                    errorMessage += ' - ' + errorData;
                }
            } catch (e) {
                // 忽略读取错误
            }
            safeSendResponse({
                success: false,
                error: errorMessage
            });
            return;
        }

        const modelInfo = await response.json();
        if (!modelInfo || typeof modelInfo !== 'object') {
            throw new Error('API 返回格式错误');
        }

        // 估算模型大小
        let totalSize = 0;
        // 优先从 safetensors 权重信息中获取
        if (modelInfo.safetensors && modelInfo.safetensors.total) {
            totalSize = parseInt(modelInfo.safetensors.total, 10);
        }
        // 其次尝试从文件列表累加
        else if (Array.isArray(modelInfo.siblings)) {
            for (const sibling of modelInfo.siblings) {
                if (sibling.size) {
                    totalSize += parseInt(sibling.size, 10);
                }
            }
        }
        const estimatedSizeMB = totalSize > 0 ? Math.round(totalSize / 1024 / 1024) : 0;

        // 格式化模型信息
        const formattedInfo = {
            id: modelInfo.id || modelId,
            name: modelInfo.modelId || modelId,
            author: modelInfo.author || '',
            downloads: modelInfo.downloads || 0,
            likes: modelInfo.likes || 0,
            pipeline_tag: modelInfo.pipeline_tag || modelInfo.pipelineTag || '',
            tags: modelInfo.tags || [],
            library_name: modelInfo.library_name || modelInfo.libraryName || null,
            model_index: modelInfo.model_index || null,
            siblings: modelInfo.siblings || [],
            config: modelInfo.config || null,
            card_data: modelInfo.cardData || null,
            estimated_size: totalSize, // 字节
            estimated_size_mb: estimatedSizeMB // MB
        };

        console.log('[AutoLeadAgent Extension] 模型信息获取成功:', formattedInfo.name);
        safeSendResponse({
            success: true,
            data: formattedInfo
        });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 获取模型信息失败:', error);
        safeSendResponse({
            success: false,
            error: error.message || '获取模型信息失败'
        });
    }
}

/**
 * 处理 HuggingFace 获取文件大小请求
 */
async function handleHfGetFileSize(request, sendResponse) {
    let hasResponded = false;
    const safeSendResponse = (response) => {
        if (!hasResponded) {
            hasResponded = true;
            try {
                sendResponse(response);
            } catch (e) {
                console.error('[AutoLeadAgent Extension] 发送响应失败:', e);
            }
        } else {
            console.warn('[AutoLeadAgent Extension] 尝试重复发送响应，已忽略');
        }
    };

    try {
        const { modelId, filename } = request;

        if (!modelId || !filename) {
            safeSendResponse({
                success: false,
                error: '模型ID和文件名不能为空'
            });
            return;
        }

        console.log('[AutoLeadAgent Extension] 获取文件大小:', modelId, filename);

        // 构建文件 URL
        const fileUrl = `https://huggingface.co/${encodeModelId(modelId)}/resolve/main/${encodeURIComponent(filename)}`;

        // 使用 HEAD 请求获取文件大小（扩展不受 CORS 限制）
        const response = await fetch(fileUrl, {
            method: 'HEAD',
            credentials: 'include',
            headers: {
                'User-Agent': 'WelineFramework-AutoLeadAgent/1.0'
            }
        });

        if (!response.ok) {
            safeSendResponse({
                success: false,
                error: `获取文件大小失败: ${response.status} ${response.statusText}`,
                status: response.status
            });
            return;
        }

        const contentLength = parseInt(response.headers.get('content-length') || '0', 10);

        safeSendResponse({
            success: true,
            size: contentLength,
            filename: filename
        });

    } catch (error) {
        console.error('[AutoLeadAgent Extension] 获取文件大小失败:', error);
        safeSendResponse({
            success: false,
            error: error.message || '获取文件大小失败'
        });
    }
}

