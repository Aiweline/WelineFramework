/**
 * 智能体大脑 - WASM 核心实现
 *
 * 说明：
 * - WASM 主导 ReAct 决策循环，JS 只负责任务注入与工具执行
 * - 维护完整的 AgentState 状态机
 * - 产出 MCP ToolCall JSON，由 JS 执行后回传结果
 *
 * 核心接口：
 * - wasm_start_task(taskJson)      : 初始化任务
 * - wasm_next_decision()           : 获取下一步决策
 * - wasm_apply_tool_result(result) : 应用工具执行结果
 * - wasm_get_status()              : 获取当前状态和日志
 */

#include <string>
#include <vector>
#include <cstring>
#include <cstdio>

// ============================================================================
// 前向声明：MCP 协议函数（在 mcp_protocol.cpp 中实现）
// ============================================================================
namespace AutoLeadAgent
{
    namespace MCP
    {
        struct ToolCall
        {
            std::string id;
            std::string name;
            std::string argumentsJson;
            std::string origin;
        };

        struct ToolResult
        {
            std::string id;
            std::string name;
            bool success;
            std::string rawJson;
            std::string errorCode;
            std::string errorMessage;
        };

        std::string encodeToolCallWithMeta(const ToolCall &call, const std::string &taskId, int iteration);
        ToolResult parseToolResult(const std::string &json);
    }
}

// ============================================================================
// 状态机定义
// ============================================================================
namespace
{
    // 智能体状态枚举
    enum class AgentPhase
    {
        IDLE,            // 空闲，等待任务
        INITIAL,         // 初始化，准备开始搜索
        NAVIGATING,      // 正在导航到目标页面
        TAKING_SNAPSHOT, // 正在获取页面快照
        EXTRACTING,      // 正在提取联系信息
        ANALYZING,       // 分析搜索结果，选择下一个目标
        VISITING,        // 访问候选页面
        COMPLETE,        // 任务完成
        ERROR            // 出错
    };

    // 候选 URL 结构
    struct CandidateUrl
    {
        std::string url;
        std::string title;
        std::string snippet;
        bool visited;
        double score;
    };

    // 发现的客户信息
    struct FoundCustomer
    {
        std::string url;
        std::string title;
        std::string contactJson; // {email, phone, socialMediaAccounts}
        double matchScore;
    };

    // 日志条目
    struct LogEntry
    {
        int timestamp;
        std::string level; // "info", "warn", "error", "debug"
        std::string message;
    };

    // 智能体完整状态
    struct AgentState
    {
        // 任务信息
        std::string taskId;
        std::string sourceType;      // "search_engine", "social_media", "direct"
        std::string profileJson;     // 客户画像 JSON
        std::string keywords;        // 搜索关键词
        std::string searchEngine;    // 当前使用的搜索引擎
        std::string searchEngineUrl; // 搜索引擎 URL 模板

        // 当前状态
        AgentPhase phase;
        std::string currentUrl;
        std::string pageSnapshot;
        int currentTabId;

        // 进度控制
        int step;
        int iteration;
        int maxIterations;
        int maxCandidates;
        bool done;
        bool hasError;
        std::string errorMessage;

        // 工具调用跟踪
        int toolCallCounter;
        std::string lastToolCallId;
        std::string lastToolName;

        // 数据收集
        std::vector<CandidateUrl> candidates;
        int currentCandidateIndex;
        std::vector<FoundCustomer> foundCustomers;

        // 日志
        std::vector<LogEntry> logs;
        int logCounter;

        // 初始化
        void reset()
        {
            taskId.clear();
            sourceType = "search_engine";
            profileJson.clear();
            keywords.clear();
            searchEngine = "Google";
            searchEngineUrl = "https://www.google.com/search?q=";

            phase = AgentPhase::IDLE;
            currentUrl.clear();
            pageSnapshot.clear();
            currentTabId = -1;

            step = 0;
            iteration = 0;
            maxIterations = 20;
            maxCandidates = 10;
            done = false;
            hasError = false;
            errorMessage.clear();

            toolCallCounter = 0;
            lastToolCallId.clear();
            lastToolName.clear();

            candidates.clear();
            currentCandidateIndex = 0;
            foundCustomers.clear();

            logs.clear();
            logCounter = 0;
        }
    };

    // 全局状态实例
    AgentState g_state;

    // 输出缓冲区（用于返回字符串给 JS）
    std::string g_outputBuffer;

    // ========================================================================
    // 辅助函数：简易 JSON 解析（不引入完整 JSON 库）
    // ========================================================================

    std::string extractStringField(const std::string &json, const char *field)
    {
        std::string key = "\"";
        key += field;
        key += "\"";

        std::size_t pos = json.find(key);
        if (pos == std::string::npos)
            return {};

        pos = json.find(':', pos);
        if (pos == std::string::npos)
            return {};

        // 跳过空白
        pos++;
        while (pos < json.size() && (json[pos] == ' ' || json[pos] == '\t' || json[pos] == '\n'))
            pos++;

        if (pos >= json.size() || json[pos] != '"')
            return {};

        std::size_t start = pos + 1;
        std::size_t end = start;

        // 处理转义字符
        while (end < json.size())
        {
            if (json[end] == '\\' && end + 1 < json.size())
            {
                end += 2;
            }
            else if (json[end] == '"')
            {
                break;
            }
            else
            {
                end++;
            }
        }

        if (end <= start)
            return {};
        return json.substr(start, end - start);
    }

    int extractIntField(const std::string &json, const char *field, int defaultVal = 0)
    {
        std::string key = "\"";
        key += field;
        key += "\"";

        std::size_t pos = json.find(key);
        if (pos == std::string::npos)
            return defaultVal;

        pos = json.find(':', pos);
        if (pos == std::string::npos)
            return defaultVal;

        pos++;
        while (pos < json.size() && (json[pos] == ' ' || json[pos] == '\t'))
            pos++;

        int value = 0;
        bool negative = false;
        if (pos < json.size() && json[pos] == '-')
        {
            negative = true;
            pos++;
        }

        while (pos < json.size() && json[pos] >= '0' && json[pos] <= '9')
        {
            value = value * 10 + (json[pos] - '0');
            pos++;
        }

        return negative ? -value : value;
    }

    bool extractBoolField(const std::string &json, const char *field, bool defaultVal = false)
    {
        std::string key = "\"";
        key += field;
        key += "\"";

        std::size_t pos = json.find(key);
        if (pos == std::string::npos)
            return defaultVal;

        pos = json.find(':', pos);
        if (pos == std::string::npos)
            return defaultVal;

        pos++;
        while (pos < json.size() && (json[pos] == ' ' || json[pos] == '\t'))
            pos++;

        if (json.compare(pos, 4, "true") == 0)
            return true;
        if (json.compare(pos, 5, "false") == 0)
            return false;

        return defaultVal;
    }

    std::string extractRawField(const std::string &json, const char *field)
    {
        std::string key = "\"";
        key += field;
        key += "\"";

        std::size_t pos = json.find(key);
        if (pos == std::string::npos)
            return {};

        pos = json.find(':', pos);
        if (pos == std::string::npos)
            return {};

        pos++;
        while (pos < json.size() && (json[pos] == ' ' || json[pos] == '\n' || json[pos] == '\t' || json[pos] == '\r'))
            pos++;

        if (pos >= json.size())
            return {};

        if (json.compare(pos, 4, "null") == 0)
            return "null";

        if (json[pos] == '{' || json[pos] == '[')
        {
            char open = json[pos];
            char close = (open == '{') ? '}' : ']';
            int depth = 0;
            std::size_t start = pos;

            for (; pos < json.size(); pos++)
            {
                if (json[pos] == open)
                    depth++;
                else if (json[pos] == close)
                {
                    depth--;
                    if (depth == 0)
                        return json.substr(start, pos - start + 1);
                }
            }
            return json.substr(start);
        }

        std::size_t start = pos;
        while (pos < json.size() && json[pos] != ',' && json[pos] != '}' && json[pos] != ']')
            pos++;

        return json.substr(start, pos - start);
    }

    // ========================================================================
    // 辅助函数：JSON 字符串转义
    // ========================================================================
    std::string escapeJsonString(const std::string &s)
    {
        std::string result;
        result.reserve(s.size() + 10);
        for (char c : s)
        {
            switch (c)
            {
            case '"':
                result += "\\\"";
                break;
            case '\\':
                result += "\\\\";
                break;
            case '\n':
                result += "\\n";
                break;
            case '\r':
                result += "\\r";
                break;
            case '\t':
                result += "\\t";
                break;
            default:
                if (c >= 0 && c < 32)
                {
                    // 控制字符用 \uXXXX
                    char buf[8];
                    snprintf(buf, sizeof(buf), "\\u%04x", (unsigned char)c);
                    result += buf;
                }
                else
                {
                    result += c;
                }
            }
        }
        return result;
    }

    // ========================================================================
    // 日志函数
    // ========================================================================
    void addLog(const std::string &level, const std::string &message)
    {
        LogEntry entry;
        entry.timestamp = g_state.logCounter++;
        entry.level = level;
        entry.message = message;
        g_state.logs.push_back(entry);

        // 限制日志数量
        if (g_state.logs.size() > 100)
        {
            g_state.logs.erase(g_state.logs.begin());
        }
    }

    // ========================================================================
    // 生成唯一 ID
    // ========================================================================
    std::string generateToolCallId()
    {
        char buf[64];
        snprintf(buf, sizeof(buf), "tc_%s_%d", g_state.taskId.c_str(), g_state.toolCallCounter++);
        return std::string(buf);
    }

    // ========================================================================
    // 构建搜索 URL
    // ========================================================================
    std::string buildSearchUrl()
    {
        std::string url = g_state.searchEngineUrl;

        // URL 编码关键词（简化版）
        std::string encoded;
        for (char c : g_state.keywords)
        {
            if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
                (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.')
            {
                encoded += c;
            }
            else if (c == ' ')
            {
                encoded += '+';
            }
            else
            {
                char buf[4];
                snprintf(buf, sizeof(buf), "%%%02X", (unsigned char)c);
                encoded += buf;
            }
        }

        return url + encoded;
    }

    // ========================================================================
    // 构建 ToolCall JSON
    // ========================================================================
    std::string buildToolCallJson(const std::string &toolName, const std::string &argsJson)
    {
        AutoLeadAgent::MCP::ToolCall call;
        call.id = generateToolCallId();
        call.name = toolName;
        call.argumentsJson = argsJson;
        call.origin = "wasm_agent";

        g_state.lastToolCallId = call.id;
        g_state.lastToolName = toolName;

        return AutoLeadAgent::MCP::encodeToolCallWithMeta(call, g_state.taskId, g_state.iteration);
    }

    // ========================================================================
    // 解析搜索结果中的候选 URL（从快照 HTML/text 中提取）
    // ========================================================================
    void parseSearchResultCandidates(const std::string &snapshotJson)
    {
        // 从 snapshot 结果中提取 URL 列表
        // 这里使用简化的解析逻辑，实际可以更复杂

        std::string text = extractStringField(snapshotJson, "text");
        std::string html = extractStringField(snapshotJson, "html");

        // 简单提取 http/https URL
        std::string content = html.empty() ? text : html;
        std::size_t pos = 0;

        while (pos < content.size() && g_state.candidates.size() < (size_t)g_state.maxCandidates)
        {
            std::size_t httpPos = content.find("http", pos);
            if (httpPos == std::string::npos)
                break;

            // 检查是 http:// 还是 https://
            if (content.compare(httpPos, 7, "http://") != 0 &&
                content.compare(httpPos, 8, "https://") != 0)
            {
                pos = httpPos + 1;
                continue;
            }

            // 提取 URL 直到遇到空白或引号
            std::size_t urlEnd = httpPos;
            while (urlEnd < content.size())
            {
                char c = content[urlEnd];
                if (c == ' ' || c == '"' || c == '\'' || c == '>' || c == '<' ||
                    c == '\n' || c == '\r' || c == '\t' || c == ')')
                    break;
                urlEnd++;
            }

            std::string url = content.substr(httpPos, urlEnd - httpPos);

            // 过滤掉搜索引擎自身的 URL
            if (url.find("google.com") == std::string::npos &&
                url.find("bing.com") == std::string::npos &&
                url.find("baidu.com") == std::string::npos &&
                url.find("duckduckgo.com") == std::string::npos &&
                url.size() > 15)
            {
                // 检查是否已存在
                bool exists = false;
                for (const auto &c : g_state.candidates)
                {
                    if (c.url == url)
                    {
                        exists = true;
                        break;
                    }
                }

                if (!exists)
                {
                    CandidateUrl candidate;
                    candidate.url = url;
                    candidate.visited = false;
                    candidate.score = 0.5; // 默认评分
                    g_state.candidates.push_back(candidate);

                    addLog("info", "发现候选URL: " + url);
                }
            }

            pos = urlEnd;
        }
    }

    // ========================================================================
    // 解析提取的联系信息
    // ========================================================================
    void parseExtractedContacts(const std::string &resultJson)
    {
        // 检查是否有有效的联系信息
        std::string emails = extractRawField(resultJson, "emails");
        std::string phones = extractRawField(resultJson, "phones");
        std::string social = extractRawField(resultJson, "socialMediaAccounts");

        bool hasEmails = !emails.empty() && emails != "[]" && emails != "null";
        bool hasPhones = !phones.empty() && phones != "[]" && phones != "null";
        bool hasSocial = !social.empty() && social != "{}" && social != "null";

        if (hasEmails || hasPhones || hasSocial)
        {
            FoundCustomer customer;
            customer.url = g_state.currentUrl;
            customer.contactJson = resultJson;
            customer.matchScore = 0.7; // 默认评分

            g_state.foundCustomers.push_back(customer);

            addLog("info", "找到联系信息，URL: " + g_state.currentUrl);
        }
    }

} // anonymous namespace

// ============================================================================
// 导出接口（供 JS 调用）
// ============================================================================
extern "C"
{
    /**
     * 启动任务
     * @param taskJson 任务配置 JSON
     * 
     * 期望格式：
     * {
     *   "taskId": "xxx",
     *   "sourceType": "search_engine",
     *   "profile": { ... },
     *   "keywords": "搜索关键词",
     *   "searchEngine": "Google",
     *   "searchEngineUrl": "https://www.google.com/search?q=",
     *   "maxIterations": 20,
     *   "maxCandidates": 10
     * }
     */
    void wasm_start_task(const char *taskJson)
    {
        if (!taskJson)
            return;

        std::string json(taskJson);
        g_state.reset();

        // 解析任务配置
        g_state.taskId = extractStringField(json, "taskId");
        if (g_state.taskId.empty())
        {
            g_state.taskId = "task_" + std::to_string(g_state.toolCallCounter++);
        }

        g_state.sourceType = extractStringField(json, "sourceType");
        if (g_state.sourceType.empty())
            g_state.sourceType = "search_engine";

        g_state.profileJson = extractRawField(json, "profile");
        g_state.keywords = extractStringField(json, "keywords");
        g_state.searchEngine = extractStringField(json, "searchEngine");
        g_state.searchEngineUrl = extractStringField(json, "searchEngineUrl");

        if (g_state.searchEngine.empty())
            g_state.searchEngine = "Google";
        if (g_state.searchEngineUrl.empty())
            g_state.searchEngineUrl = "https://www.google.com/search?q=";

        g_state.maxIterations = extractIntField(json, "maxIterations", 20);
        g_state.maxCandidates = extractIntField(json, "maxCandidates", 10);

        // 设置初始状态
        g_state.phase = AgentPhase::INITIAL;
        g_state.done = false;

        addLog("info", "任务启动: " + g_state.taskId);
        addLog("info", "搜索关键词: " + g_state.keywords);
        addLog("info", "搜索引擎: " + g_state.searchEngine);
    }

    /**
     * 获取下一步决策
     * @return ToolCall JSON 或 完成状态 JSON
     * 
     * 返回格式：
     * 1. 工具调用：{"type":"tool_call","id":"...","name":"...","arguments":{...},"taskId":"...","iteration":...}
     * 2. 完成：{"type":"complete","taskId":"...","foundCustomers":[...],"stats":{...}}
     * 3. 错误：{"type":"error","message":"..."}
     */
    const char *wasm_next_decision()
    {
        g_outputBuffer.clear();

        // 检查是否已完成或出错
        if (g_state.done || g_state.phase == AgentPhase::COMPLETE)
        {
            // 构建完成结果
            g_outputBuffer = "{\"type\":\"complete\",\"taskId\":\"";
            g_outputBuffer += escapeJsonString(g_state.taskId);
            g_outputBuffer += "\",\"foundCustomers\":[";

            for (size_t i = 0; i < g_state.foundCustomers.size(); i++)
            {
                if (i > 0)
                    g_outputBuffer += ",";
                g_outputBuffer += "{\"url\":\"";
                g_outputBuffer += escapeJsonString(g_state.foundCustomers[i].url);
                g_outputBuffer += "\",\"contacts\":";
                g_outputBuffer += g_state.foundCustomers[i].contactJson.empty() ? "{}" : g_state.foundCustomers[i].contactJson;
                g_outputBuffer += ",\"score\":";
                char buf[32];
                snprintf(buf, sizeof(buf), "%.2f", g_state.foundCustomers[i].matchScore);
                g_outputBuffer += buf;
                g_outputBuffer += "}";
            }

            g_outputBuffer += "],\"stats\":{\"totalIterations\":";
            char buf[32];
            snprintf(buf, sizeof(buf), "%d", g_state.iteration);
            g_outputBuffer += buf;
            g_outputBuffer += ",\"candidatesFound\":";
            snprintf(buf, sizeof(buf), "%zu", g_state.candidates.size());
            g_outputBuffer += buf;
            g_outputBuffer += ",\"customersFound\":";
            snprintf(buf, sizeof(buf), "%zu", g_state.foundCustomers.size());
            g_outputBuffer += buf;
            g_outputBuffer += "}}";

            addLog("info", "任务完成");
            return g_outputBuffer.c_str();
        }

        if (g_state.hasError || g_state.phase == AgentPhase::ERROR)
        {
            g_outputBuffer = "{\"type\":\"error\",\"message\":\"";
            g_outputBuffer += escapeJsonString(g_state.errorMessage);
            g_outputBuffer += "\"}";
            return g_outputBuffer.c_str();
        }

        // 检查迭代次数限制
        if (g_state.iteration >= g_state.maxIterations)
        {
            addLog("info", "达到最大迭代次数，任务完成");
            g_state.phase = AgentPhase::COMPLETE;
            g_state.done = true;
            return wasm_next_decision(); // 递归返回完成状态
        }

        g_state.iteration++;
        g_state.step++;

        // 根据当前阶段生成决策
        switch (g_state.phase)
        {
        case AgentPhase::INITIAL:
        {
            // 第一步：导航到搜索引擎
            addLog("info", "阶段: INITIAL - 导航到搜索引擎");

            std::string searchUrl = buildSearchUrl();
            g_state.currentUrl = searchUrl;

            std::string args = "{\"url\":\"" + escapeJsonString(searchUrl) + "\"}";
            g_outputBuffer = buildToolCallJson("browser_navigate", args);

            g_state.phase = AgentPhase::NAVIGATING;
            break;
        }

        case AgentPhase::NAVIGATING:
        {
            // 导航完成后，获取页面快照
            addLog("info", "阶段: NAVIGATING -> TAKING_SNAPSHOT");

            std::string args = "{}";
            if (g_state.currentTabId > 0)
            {
                char buf[64];
                snprintf(buf, sizeof(buf), "{\"tabId\":%d}", g_state.currentTabId);
                args = buf;
            }

            g_outputBuffer = buildToolCallJson("browser_snapshot", args);
            g_state.phase = AgentPhase::TAKING_SNAPSHOT;
            break;
        }

        case AgentPhase::TAKING_SNAPSHOT:
        {
            // 快照完成后，根据来源决定下一步
            if (g_state.candidates.empty())
            {
                // 还在搜索结果页面，需要分析
                addLog("info", "阶段: TAKING_SNAPSHOT -> ANALYZING");
                g_state.phase = AgentPhase::ANALYZING;
                return wasm_next_decision(); // 立即进入分析
            }
            else
            {
                // 已经有候选，提取联系信息
                addLog("info", "阶段: TAKING_SNAPSHOT -> EXTRACTING");

                std::string args = "{\"selectors\":{\"email\":true,\"phone\":true,\"social\":[\"linkedin\",\"twitter\",\"facebook\"]}}";
                g_outputBuffer = buildToolCallJson("browser_extract", args);

                g_state.phase = AgentPhase::EXTRACTING;
            }
            break;
        }

        case AgentPhase::ANALYZING:
        {
            // 分析阶段：如果有候选，访问下一个；否则任务完成
            if (g_state.currentCandidateIndex < (int)g_state.candidates.size())
            {
                // 访问下一个候选
                CandidateUrl &candidate = g_state.candidates[g_state.currentCandidateIndex];
                g_state.currentCandidateIndex++;

                if (!candidate.visited)
                {
                    addLog("info", "阶段: ANALYZING -> VISITING 候选 " + candidate.url);

                    candidate.visited = true;
                    g_state.currentUrl = candidate.url;

                    std::string args = "{\"url\":\"" + escapeJsonString(candidate.url) + "\"}";
                    g_outputBuffer = buildToolCallJson("browser_navigate", args);

                    g_state.phase = AgentPhase::VISITING;
                }
                else
                {
                    // 已访问，继续下一个
                    return wasm_next_decision();
                }
            }
            else
            {
                // 所有候选都已访问，任务完成
                addLog("info", "所有候选已访问，任务完成");
                g_state.phase = AgentPhase::COMPLETE;
                g_state.done = true;
                return wasm_next_decision();
            }
            break;
        }

        case AgentPhase::VISITING:
        {
            // 访问候选页面后，获取快照
            addLog("info", "阶段: VISITING -> TAKING_SNAPSHOT");

            std::string args = "{}";
            g_outputBuffer = buildToolCallJson("browser_snapshot", args);

            g_state.phase = AgentPhase::TAKING_SNAPSHOT;
            break;
        }

        case AgentPhase::EXTRACTING:
        {
            // 提取完成后，返回分析阶段处理下一个候选
            addLog("info", "阶段: EXTRACTING -> ANALYZING");
            g_state.phase = AgentPhase::ANALYZING;
            return wasm_next_decision();
        }

        case AgentPhase::IDLE:
        case AgentPhase::COMPLETE:
        case AgentPhase::ERROR:
        default:
        {
            // 不应该到达这里
            g_outputBuffer = "{\"type\":\"error\",\"message\":\"Invalid agent phase\"}";
            break;
        }
        }

        return g_outputBuffer.c_str();
    }

    /**
     * 应用工具执行结果
     * @param resultJson 工具执行结果 JSON
     * 
     * 期望格式：
     * {
     *   "id": "tc_xxx",
     *   "name": "browser_navigate",
     *   "success": true,
     *   "result": { ... },
     *   "error": { "code": "xxx", "message": "xxx" }
     * }
     */
    void wasm_apply_tool_result(const char *resultJson)
    {
        if (!resultJson)
            return;

        std::string json(resultJson);

        // 解析结果
        bool success = extractBoolField(json, "success", false);
        std::string name = extractStringField(json, "name");
        std::string resultData = extractRawField(json, "result");

        addLog("debug", "收到工具结果: " + name + ", 成功: " + (success ? "是" : "否"));

        if (!success)
        {
            std::string errorMsg = extractStringField(json, "message");
            if (errorMsg.empty())
            {
                errorMsg = extractStringField(extractRawField(json, "error"), "message");
            }

            addLog("error", "工具执行失败: " + name + " - " + errorMsg);

            // 某些错误可以容忍，继续执行
            // 严重错误则停止任务
            if (name == "browser_navigate" && g_state.phase == AgentPhase::NAVIGATING)
            {
                // 导航失败，任务出错
                g_state.hasError = true;
                g_state.errorMessage = "导航失败: " + errorMsg;
                g_state.phase = AgentPhase::ERROR;
            }
            return;
        }

        // 根据工具类型处理结果
        if (name == "browser_navigate")
        {
            // 更新当前 URL 和 Tab ID
            g_state.currentUrl = extractStringField(resultData, "url");
            g_state.currentTabId = extractIntField(resultData, "tabId", -1);

            addLog("info", "导航成功: " + g_state.currentUrl);
        }
        else if (name == "browser_snapshot")
        {
            // 保存快照
            g_state.pageSnapshot = resultData;

            // 如果在搜索结果页面，解析候选 URL
            if (g_state.candidates.empty() && !resultData.empty())
            {
                parseSearchResultCandidates(resultData);
                addLog("info", "解析到 " + std::to_string(g_state.candidates.size()) + " 个候选URL");
            }
        }
        else if (name == "browser_extract")
        {
            // 解析联系信息
            if (!resultData.empty())
            {
                parseExtractedContacts(resultData);
            }
        }
    }

    /**
     * 获取当前状态
     * @return 状态 JSON
     * 
     * 返回格式：
     * {
     *   "taskId": "xxx",
     *   "phase": "ANALYZING",
     *   "step": 5,
     *   "iteration": 3,
     *   "currentUrl": "...",
     *   "candidatesCount": 10,
     *   "customersFound": 2,
     *   "done": false,
     *   "hasError": false,
     *   "logs": [...]
     * }
     */
    const char *wasm_get_status()
    {
        g_outputBuffer = "{";

        // 任务 ID
        g_outputBuffer += "\"taskId\":\"" + escapeJsonString(g_state.taskId) + "\",";

        // 阶段
        const char *phaseName = "UNKNOWN";
        switch (g_state.phase)
        {
        case AgentPhase::IDLE:
            phaseName = "IDLE";
            break;
        case AgentPhase::INITIAL:
            phaseName = "INITIAL";
            break;
        case AgentPhase::NAVIGATING:
            phaseName = "NAVIGATING";
            break;
        case AgentPhase::TAKING_SNAPSHOT:
            phaseName = "TAKING_SNAPSHOT";
            break;
        case AgentPhase::EXTRACTING:
            phaseName = "EXTRACTING";
            break;
        case AgentPhase::ANALYZING:
            phaseName = "ANALYZING";
            break;
        case AgentPhase::VISITING:
            phaseName = "VISITING";
            break;
        case AgentPhase::COMPLETE:
            phaseName = "COMPLETE";
            break;
        case AgentPhase::ERROR:
            phaseName = "ERROR";
            break;
        }
        g_outputBuffer += "\"phase\":\"";
        g_outputBuffer += phaseName;
        g_outputBuffer += "\",";

        // 进度
        char buf[64];
        snprintf(buf, sizeof(buf), "\"step\":%d,", g_state.step);
        g_outputBuffer += buf;
        snprintf(buf, sizeof(buf), "\"iteration\":%d,", g_state.iteration);
        g_outputBuffer += buf;
        snprintf(buf, sizeof(buf), "\"maxIterations\":%d,", g_state.maxIterations);
        g_outputBuffer += buf;

        // 当前 URL
        g_outputBuffer += "\"currentUrl\":\"" + escapeJsonString(g_state.currentUrl) + "\",";

        // 统计
        snprintf(buf, sizeof(buf), "\"candidatesCount\":%zu,", g_state.candidates.size());
        g_outputBuffer += buf;
        snprintf(buf, sizeof(buf), "\"candidatesVisited\":%d,", g_state.currentCandidateIndex);
        g_outputBuffer += buf;
        snprintf(buf, sizeof(buf), "\"customersFound\":%zu,", g_state.foundCustomers.size());
        g_outputBuffer += buf;

        // 状态标志
        g_outputBuffer += "\"done\":";
        g_outputBuffer += g_state.done ? "true" : "false";
        g_outputBuffer += ",\"hasError\":";
        g_outputBuffer += g_state.hasError ? "true" : "false";

        if (g_state.hasError)
        {
            g_outputBuffer += ",\"errorMessage\":\"" + escapeJsonString(g_state.errorMessage) + "\"";
        }

        // 最近日志（最后10条）
        g_outputBuffer += ",\"logs\":[";
        size_t logStart = g_state.logs.size() > 10 ? g_state.logs.size() - 10 : 0;
        for (size_t i = logStart; i < g_state.logs.size(); i++)
        {
            if (i > logStart)
                g_outputBuffer += ",";
            g_outputBuffer += "{\"ts\":";
            snprintf(buf, sizeof(buf), "%d", g_state.logs[i].timestamp);
            g_outputBuffer += buf;
            g_outputBuffer += ",\"level\":\"" + g_state.logs[i].level + "\"";
            g_outputBuffer += ",\"msg\":\"" + escapeJsonString(g_state.logs[i].message) + "\"}";
        }
        g_outputBuffer += "]";

        g_outputBuffer += "}";

        return g_outputBuffer.c_str();
    }

    /**
     * 停止任务
     */
    void wasm_stop_task()
    {
        addLog("info", "任务被手动停止");
        g_state.done = true;
        g_state.phase = AgentPhase::COMPLETE;
    }

    /**
     * 检查 WASM 是否就绪
     */
    int wasm_is_ready()
    {
        return 1; // 始终就绪
    }

    /**
     * 获取版本号
     */
    const char *wasm_get_version()
    {
        static const char *version = "1.0.0";
        return version;
    }

    // ========================================================================
    // 保留旧接口以保持兼容性
    // ========================================================================

    struct AgentBrainHandle
    {
        int placeholder;
    };

    AgentBrainHandle *createAgentBrain()
    {
        static AgentBrainHandle handle{1};
        g_state.reset();
        return &handle;
    }

    const char *decideNextAction(const char *stateJson,
                                 const char *profileJson,
                                 const char *promptJson)
    {
        // 兼容旧接口：调用新的决策函数
        return wasm_next_decision();
    }

    void updateState(const char *stateJson)
    {
        // 兼容旧接口：应用状态更新
        if (stateJson)
        {
            wasm_apply_tool_result(stateJson);
        }
    }
}
