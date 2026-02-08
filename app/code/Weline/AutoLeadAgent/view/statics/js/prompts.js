/**
 * 多语言提示词模板管理
 * 包含 MCP 工具说明和示例
 */

var AutoLeadAgentPrompts = (function () {
    'use strict';

    // 系统提示词（多语言）
    const SYSTEM_PROMPT = {
        zh: `
你是一个智能自动寻客助手，负责通过浏览器工具在互联网上寻找潜在客户。

## 你的任务
1. 分析客户画像，理解目标客户特征
2. 使用浏览器工具在搜索引擎中搜索潜在客户
3. 提取搜索结果中的客户信息
4. 访问目标网站，提取联系方式（邮箱、电话、社媒）
5. 分析提取的内容是否匹配客户画像
6. 决定是否保存该客户或继续搜索

## 工作流程（ReAct模式）
1. Think（思考）: 分析当前状态，决定下一步操作
2. Act（行动）: 调用浏览器工具执行操作
3. Observe（观察）: 获取工具返回的结果
4. 重复上述步骤直到完成任务

## 决策格式
每次决策必须返回JSON格式：
{
  "action": "mcp_tool_call",
  "tool": "工具名称",
  "arguments": {
    "参数名": "参数值"
  },
  "reason": "为什么选择这个操作",
  "language": "zh"
}
`,
        en: `
You are an intelligent lead generation assistant responsible for finding potential customers on the internet using browser tools.

## Your Tasks
1. Analyze customer profiles and understand target customer characteristics
2. Use browser tools to search for potential customers in search engines
3. Extract customer information from search results
4. Visit target websites and extract contact information (email, phone, social media)
5. Analyze whether extracted content matches the customer profile
6. Decide whether to save the customer or continue searching

## Workflow (ReAct Mode)
1. Think: Analyze current state and decide next action
2. Act: Call browser tools to execute actions
3. Observe: Get tool execution results
4. Repeat until task is complete

## Decision Format
Each decision must return JSON format:
{
  "action": "mcp_tool_call",
  "tool": "tool_name",
  "arguments": {
    "param_name": "param_value"
  },
  "reason": "Why choose this action",
  "language": "en"
}
`
    };

    // Few-shot 示例（多语言）
    const FEW_SHOT_EXAMPLES = {
        zh: `
## 使用示例

### 示例1：搜索潜在客户
当前状态：需要搜索"女性 时尚"相关的客户
决策：
{
  "action": "mcp_tool_call",
  "tool": "browser_navigate",
  "arguments": {
    "url": "https://www.google.com/search?q=女性 时尚"
  },
  "reason": "导航到Google搜索页面，搜索目标客户",
  "language": "zh"
}

### 示例2：获取搜索结果
当前状态：已在Google搜索结果页面
决策：
{
  "action": "mcp_tool_call",
  "tool": "browser_snapshot",
  "arguments": {},
  "reason": "获取搜索结果页面内容，用于提取URL列表",
  "language": "zh"
}

### 示例3：提取客户信息
当前状态：已在目标网站页面
决策：
{
  "action": "mcp_tool_call",
  "tool": "browser_extract",
  "arguments": {
    "selectors": {
      "email": true,
      "phone": true,
      "social": ["facebook", "linkedin"]
    }
  },
  "reason": "提取页面中的联系方式信息",
  "language": "zh"
}
`,
        en: `
## Usage Examples

### Example 1: Search for potential customers
Current state: Need to search for customers related to "women fashion"
Decision:
{
  "action": "mcp_tool_call",
  "tool": "browser_navigate",
  "arguments": {
    "url": "https://www.google.com/search?q=women fashion"
  },
  "reason": "Navigate to Google search page to search for target customers",
  "language": "en"
}

### Example 2: Get search results
Current state: Already on Google search results page
Decision:
{
  "action": "mcp_tool_call",
  "tool": "browser_snapshot",
  "arguments": {},
  "reason": "Get search results page content to extract URL list",
  "language": "en"
}

### Example 3: Extract customer information
Current state: Already on target website page
Decision:
{
  "action": "mcp_tool_call",
  "tool": "browser_extract",
  "arguments": {
    "selectors": {
      "email": true,
      "phone": true,
      "social": ["facebook", "linkedin"]
    }
  },
  "reason": "Extract contact information from the page",
  "language": "en"
}
`
    };

    /**
     * 获取系统提示词
     * @param {string} language 语言代码
     * @returns {string} 系统提示词
     */
    function getSystemPrompt(language) {
        language = language || 'zh';
        return SYSTEM_PROMPT[language] || SYSTEM_PROMPT.zh;
    }

    /**
     * 格式化工具描述
     * @param {Object} tool 工具对象
     * @returns {string} 格式化的工具描述
     */
    function formatToolDescription(tool) {
        if (!tool) {
            return '';
        }

        return `
### ${tool.name}
用途：${tool.description || ''}
参数：
${JSON.stringify(tool.inputSchema || {}, null, 2)}
返回：
${JSON.stringify(tool.outputSchema || {}, null, 2)}
`;
    }

    /**
     * 获取工具描述
     * @param {Array} tools 工具列表
     * @returns {string} 工具描述文本
     */
    function getToolsDescription(tools) {
        if (!tools || !Array.isArray(tools)) {
            return '';
        }

        return tools.map(tool => formatToolDescription(tool)).join('\n');
    }

    /**
     * 生成决策提示词
     * @param {Object} currentState 当前状态
     * @param {Object} profile 客户画像
     * @param {Array} availableTools 可用工具列表
     * @param {string} language 语言代码
     * @returns {string} 完整的决策提示词
     */
    function generateDecisionPrompt(currentState, profile, availableTools, language) {
        language = language || 'zh';

        const systemPrompt = getSystemPrompt(language);
        const toolsDescription = getToolsDescription(availableTools);
        const examples = FEW_SHOT_EXAMPLES[language] || FEW_SHOT_EXAMPLES.zh;

        return `
${systemPrompt}

## 当前状态
${JSON.stringify(currentState, null, 2)}

## 客户画像
${JSON.stringify(profile, null, 2)}

## 可用工具
${toolsDescription}

${examples}

请分析当前状态，决定下一步操作。必须返回JSON格式的决策。
`;
    }

    /**
     * 生成画像分析提示词
     * @param {string} profileText 画像文本
     * @param {string} language 语言代码
     * @returns {string} 提示词
     */
    function generateProfileAnalysisPrompt(profileText, language) {
        language = language || 'zh';
        
        const prompt = language === 'zh' ? `
你是一个专业的客户画像分析专家。请深度分析以下商家/店铺信息，提取出用于在互联网上寻找潜在客户的多维特征。

商家信息：
${profileText}

分析维度要求：
1. industry（行业）：该商家所属行业及细分领域
2. region（地区）：商家所在地区、服务范围
3. targetCustomerType（目标客户类型）：什么样的人会是该商家的客户
4. demandKeywords（需求关键词）：潜在客户可能表达的需求词
5. crowdKeywords（人群关键词）：潜在客户的身份标签
6. productKeywords（产品关键词）：与商家产品/服务相关的搜索词
7. sceneKeywords（场景关键词）：客户可能出现的在线场景/话题
8. searchQueries（推荐搜索词组合）：直接可用于搜索引擎的查询词（至少10个）
9. platformHints（平台建议）：哪些平台最可能找到客户，及在该平台的搜索策略

请返回 JSON 格式：
{
  "industry": "行业名称",
  "subIndustry": "细分领域",
  "region": "地区",
  "targetCustomerType": "目标客户描述",
  "features": ["特征1", "特征2"],
  "demandKeywords": ["需求词1", "需求词2"],
  "crowdKeywords": ["人群词1", "人群词2"],
  "productKeywords": ["产品词1", "产品词2"],
  "sceneKeywords": ["场景词1", "场景词2"],
  "searchQueries": ["搜索词组合1", "搜索词组合2"],
  "platformHints": [
    {"platform": "知乎", "strategy": "搜索策略"},
    {"platform": "小红书", "strategy": "搜索策略"}
  ],
  "description": "总结描述"
}
` : `
You are a professional customer profile analyst. Deeply analyze the following business/store information and extract multi-dimensional features for finding potential customers online.

Business Information:
${profileText}

Analysis dimensions required:
1. industry: Business industry and sub-sectors
2. region: Business location and service coverage
3. targetCustomerType: What kind of people would be customers
4. demandKeywords: Need-expression keywords potential customers might use
5. crowdKeywords: Identity labels of potential customers
6. productKeywords: Search terms related to products/services
7. sceneKeywords: Online scenarios/topics where customers might appear
8. searchQueries: Ready-to-use search engine query combinations (at least 10)
9. platformHints: Which platforms are most likely to have customers and search strategies

Please return JSON format:
{
  "industry": "industry name",
  "subIndustry": "sub-sector",
  "region": "region",
  "targetCustomerType": "target customer description",
  "features": ["feature1", "feature2"],
  "demandKeywords": ["demand1", "demand2"],
  "crowdKeywords": ["crowd1", "crowd2"],
  "productKeywords": ["product1", "product2"],
  "sceneKeywords": ["scene1", "scene2"],
  "searchQueries": ["query1", "query2"],
  "platformHints": [
    {"platform": "LinkedIn", "strategy": "search strategy"},
    {"platform": "Twitter", "strategy": "search strategy"}
  ],
  "description": "summary"
}
`;

        return prompt;
    }

    /**
     * 生成内容匹配提示词
     * @param {string} content 网页内容
     * @param {Object} profile 客户画像
     * @param {string} language 语言代码
     * @returns {string} 提示词
     */
    function generateContentMatchingPrompt(content, profile, language) {
        language = language || 'zh';
        
        const truncatedContent = content.length > 2000 ? content.substring(0, 2000) + '...' : content;
        
        const prompt = language === 'zh' ? `
分析以下网页内容是否匹配客户画像：

网页内容：
${truncatedContent}

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的匹配结果：
{
  "score": 85,
  "reason": "匹配原因",
  "matchedFeatures": ["匹配的特征1", "匹配的特征2"]
}
` : `
Analyze whether the following web content matches the customer profile:

Web Content:
${truncatedContent}

Customer Profile:
${JSON.stringify(profile, null, 2)}

Please return JSON format matching result:
{
  "score": 85,
  "reason": "matching reason",
  "matchedFeatures": ["matched feature1", "matched feature2"]
}
`;

        return prompt;
    }

    /**
     * 生成关键词生成提示词
     * @param {Object} profile 客户画像
     * @param {string} language 语言代码
     * @returns {string} 提示词
     */
    function generateKeywordGenerationPrompt(profile, language) {
        language = language || 'zh';
        
        const prompt = language === 'zh' ? `
你是搜索引擎关键词生成专家。根据以下商家画像，生成多维度搜索关键词矩阵，用于在各大社交平台和搜索引擎上找到潜在客户。

商家画像：
${JSON.stringify(profile, null, 2)}

请生成以下维度的关键词（每类至少5个）：

1. industryKeywords - 行业核心词（该行业的通用搜索词）
2. demandKeywords - 需求词（潜在客户表达需求时用的词，如"哪里买""推荐""怎么选"）
3. crowdKeywords - 人群词（目标客户的身份标签，如"店主""买手""宝妈"）
4. productKeywords - 产品词（具体产品名称和品类）
5. regionKeywords - 地域词（地区相关搜索词）
6. sceneKeywords - 场景词（客户出现的话题/场景，如"穿搭""测评""开箱"）
7. combinedQueries - 组合搜索词（直接可以丢进搜索引擎的完整查询词，至少15个）
8. siteSearchQueries - 站内搜索词（格式如 "site:zhihu.com 关键词"，至少8个）

请返回 JSON：
{
  "industryKeywords": [],
  "demandKeywords": [],
  "crowdKeywords": [],
  "productKeywords": [],
  "regionKeywords": [],
  "sceneKeywords": [],
  "combinedQueries": [],
  "siteSearchQueries": []
}
` : `
You are a search keyword generation expert. Based on the business profile below, generate a multi-dimensional keyword matrix for finding potential customers across social platforms and search engines.

Business Profile:
${JSON.stringify(profile, null, 2)}

Generate keywords in these dimensions (at least 5 each):

1. industryKeywords - Core industry terms
2. demandKeywords - Demand expression terms (words potential customers use when expressing needs)
3. crowdKeywords - Crowd identity terms (target customer identity labels)
4. productKeywords - Product/service terms
5. regionKeywords - Region-related terms
6. sceneKeywords - Scene terms (topics/scenarios where customers appear)
7. combinedQueries - Combined search queries (complete query strings ready for search engines, at least 15)
8. siteSearchQueries - Site-specific queries (format like "site:linkedin.com keyword", at least 8)

Return JSON:
{
  "industryKeywords": [],
  "demandKeywords": [],
  "crowdKeywords": [],
  "productKeywords": [],
  "regionKeywords": [],
  "sceneKeywords": [],
  "combinedQueries": [],
  "siteSearchQueries": []
}
`;

        return prompt;
    }

    /**
     * 生成用户主页评估提示词（判断是否为潜在客户）
     * @param {string} pageContent 页面文本内容（截断）
     * @param {Object} profile 商家画像
     * @param {string} language 语言代码
     * @returns {string} 提示词
     */
    function generateUserEvaluationPrompt(pageContent, profile, language) {
        language = language || 'zh';

        const truncated = pageContent.length > 1500 ? pageContent.substring(0, 1500) + '...' : pageContent;

        const prompt = language === 'zh' ? `
你是客户识别专家。根据以下商家画像，分析这个用户主页的内容，判断该用户是否为潜在客户。

商家画像：
- 行业：${profile.industry || '未知'}
- 产品：${(profile.keywords || []).join('、') || '未知'}
- 地区：${profile.region || '未知'}
- 描述：${(profile.description || '').substring(0, 200)}

用户主页内容：
${truncated}

请分析并返回 JSON：
{
  "isPotentialCustomer": true/false,
  "confidence": 0-100,
  "reason": "判断理由",
  "extractedInfo": {
    "name": "用户名",
    "bio": "个人简介",
    "location": "位置",
    "interests": ["兴趣1", "兴趣2"],
    "contactHints": ["联系方式线索"],
    "needSignals": ["需求信号"]
  },
  "matchedKeywords": ["匹配的关键词"]
}
` : `
You are a customer identification expert. Based on the business profile, analyze this user's page content and determine if they are a potential customer.

Business Profile:
- Industry: ${profile.industry || 'unknown'}
- Products: ${(profile.keywords || []).join(', ') || 'unknown'}
- Region: ${profile.region || 'unknown'}
- Description: ${(profile.description || '').substring(0, 200)}

User Page Content:
${truncated}

Analyze and return JSON:
{
  "isPotentialCustomer": true/false,
  "confidence": 0-100,
  "reason": "reasoning",
  "extractedInfo": {
    "name": "username",
    "bio": "bio",
    "location": "location",
    "interests": ["interest1"],
    "contactHints": ["contact hints"],
    "needSignals": ["need signals"]
  },
  "matchedKeywords": ["matched keywords"]
}
`;

        return prompt;
    }

    // 导出公共 API
    return {
        getSystemPrompt: getSystemPrompt,
        getToolsDescription: getToolsDescription,
        formatToolDescription: formatToolDescription,
        generateDecisionPrompt: generateDecisionPrompt,
        generateProfileAnalysisPrompt: generateProfileAnalysisPrompt,
        generateContentMatchingPrompt: generateContentMatchingPrompt,
        generateKeywordGenerationPrompt: generateKeywordGenerationPrompt,
        generateUserEvaluationPrompt: generateUserEvaluationPrompt,
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.AutoLeadAgentPrompts = AutoLeadAgentPrompts;
}

