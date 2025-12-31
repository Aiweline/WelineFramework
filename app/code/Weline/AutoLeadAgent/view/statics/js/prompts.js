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
分析以下客户画像，提取关键特征：

客户画像：
${profileText}

请返回 JSON 格式的分析结果，包含以下字段：
{
  "features": ["特征1", "特征2", ...],
  "keywords": ["关键词1", "关键词2", ...],
  "industry": "行业",
  "region": "地区",
  "description": "描述"
}
` : `
Analyze the following customer profile and extract key features:

Customer Profile:
${profileText}

Please return JSON format analysis result with the following fields:
{
  "features": ["feature1", "feature2", ...],
  "keywords": ["keyword1", "keyword2", ...],
  "industry": "industry",
  "region": "region",
  "description": "description"
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
根据以下客户画像生成搜索关键词：

客户画像：
${JSON.stringify(profile, null, 2)}

请返回 JSON 格式的关键词列表：
{
  "keywords": ["关键词1", "关键词2", ...]
}
` : `
Generate search keywords based on the following customer profile:

Customer Profile:
${JSON.stringify(profile, null, 2)}

Please return JSON format keyword list:
{
  "keywords": ["keyword1", "keyword2", ...]
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
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.AutoLeadAgentPrompts = AutoLeadAgentPrompts;
}

