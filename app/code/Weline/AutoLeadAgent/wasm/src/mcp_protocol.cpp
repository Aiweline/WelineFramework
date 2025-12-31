/**
 * MCP 协议实现 - WASM 端
 *
 * 说明：
 * - 实现 MCP 工具调用的 JSON 编码/解码
 * - 支持带元信息的工具调用格式
 * - 提供工具结果解析功能
 *
 * 核心结构：
 * - ToolCall: 工具调用请求
 * - ToolResult: 工具执行结果
 */

#include <string>
#include <cstddef>
#include <cstdio>

namespace AutoLeadAgent
{
    namespace MCP
    {
        // ====================================================================
        // 数据结构
        // ====================================================================

        /**
         * MCP 工具调用结构
         */
        struct ToolCall
        {
            std::string id;           // 调用 ID
            std::string name;         // 工具名称
            std::string argumentsJson; // 参数 JSON
            std::string origin;       // 来源标记
        };

        /**
         * MCP 工具结果结构
         */
        struct ToolResult
        {
            std::string id;           // 调用 ID
            std::string name;         // 工具名称
            bool success;             // 是否成功
            std::string rawJson;      // 原始结果 JSON
            std::string errorCode;    // 错误码
            std::string errorMessage; // 错误消息
        };

        // ====================================================================
        // 辅助函数：JSON 字符串处理
        // ====================================================================

        /**
         * 转义 JSON 字符串
         */
        static std::string escapeJsonString(const std::string &s)
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
                case '\b':
                    result += "\\b";
                    break;
                case '\f':
                    result += "\\f";
                    break;
                default:
                    if (c >= 0 && c < 32)
                    {
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

        /**
         * 从 JSON 中提取字符串字段
         */
        static std::string extractStringField(const std::string &json, const char *field)
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

        /**
         * 从 JSON 中提取布尔字段
         */
        static bool extractBoolField(const std::string &json, const char *field, bool defaultVal = false)
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

        /**
         * 从 JSON 中提取原始字段（对象/数组/null）
         */
        static std::string extractRawField(const std::string &json, const char *field)
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

            if (json[pos] == '"')
            {
                // 字符串值
                std::size_t start = pos + 1;
                std::size_t end = start;
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
                return json.substr(pos, end - pos + 1);
            }

            if (json[pos] == '{' || json[pos] == '[')
            {
                char open = json[pos];
                char close = (open == '{') ? '}' : ']';
                int depth = 0;
                std::size_t start = pos;
                bool inString = false;

                for (; pos < json.size(); pos++)
                {
                    if (json[pos] == '"' && (pos == 0 || json[pos - 1] != '\\'))
                    {
                        inString = !inString;
                    }
                    else if (!inString)
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
                }
                return json.substr(start);
            }

            // 数字、布尔等原始值
            std::size_t start = pos;
            while (pos < json.size() && json[pos] != ',' && json[pos] != '}' && json[pos] != ']' &&
                   json[pos] != '\n' && json[pos] != '\r')
                pos++;

            // 去除尾部空白
            while (pos > start && (json[pos - 1] == ' ' || json[pos - 1] == '\t'))
                pos--;

            return json.substr(start, pos - start);
        }

        // ====================================================================
        // 核心编码/解码函数
        // ====================================================================

        /**
         * 将工具调用编码为 MCP 风格的 JSON 字符串（基础版）
         */
        std::string encodeToolCall(const ToolCall &toolCall)
        {
            std::string json = "{";
            json += "\"name\":\"" + escapeJsonString(toolCall.name) + "\",";
            json += "\"arguments\":" + (toolCall.argumentsJson.empty() ? "null" : toolCall.argumentsJson);
            json += "}";
            return json;
        }

        /**
         * 将工具调用编码为带元信息的 JSON 字符串（供 JS 使用）
         * 
         * 输出格式：
         * {
         *   "type": "tool_call",
         *   "id": "tc_xxx",
         *   "name": "browser_navigate",
         *   "arguments": { ... },
         *   "meta": {
         *     "taskId": "xxx",
         *     "iteration": 5,
         *     "origin": "wasm_agent"
         *   }
         * }
         */
        std::string encodeToolCallWithMeta(const ToolCall &call, const std::string &taskId, int iteration)
        {
            std::string json = "{";
            
            // 类型标识
            json += "\"type\":\"tool_call\",";
            
            // 调用 ID
            json += "\"id\":\"" + escapeJsonString(call.id) + "\",";
            
            // 工具名称
            json += "\"name\":\"" + escapeJsonString(call.name) + "\",";
            
            // 参数
            json += "\"arguments\":";
            if (call.argumentsJson.empty() || call.argumentsJson == "null")
            {
                json += "{}";
            }
            else
            {
                json += call.argumentsJson;
            }
            json += ",";
            
            // 元信息
            json += "\"meta\":{";
            json += "\"taskId\":\"" + escapeJsonString(taskId) + "\",";
            
            char buf[32];
            snprintf(buf, sizeof(buf), "\"iteration\":%d,", iteration);
            json += buf;
            
            json += "\"origin\":\"" + escapeJsonString(call.origin.empty() ? "wasm_agent" : call.origin) + "\"";
            json += "}";
            
            json += "}";
            return json;
        }

        /**
         * 从 JSON 文本中解析工具调用
         */
        ToolCall parseToolCall(const std::string &json)
        {
            ToolCall call;
            call.id = extractStringField(json, "id");
            call.name = extractStringField(json, "name");
            call.argumentsJson = extractRawField(json, "arguments");
            call.origin = extractStringField(json, "origin");
            
            // 如果没有 id，尝试从 meta 中提取
            if (call.id.empty())
            {
                std::string meta = extractRawField(json, "meta");
                if (!meta.empty())
                {
                    call.id = extractStringField(meta, "id");
                    if (call.origin.empty())
                    {
                        call.origin = extractStringField(meta, "origin");
                    }
                }
            }
            
            return call;
        }

        /**
         * 从 JSON 文本中解析工具执行结果
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
        ToolResult parseToolResult(const std::string &json)
        {
            ToolResult result;
            result.id = extractStringField(json, "id");
            result.name = extractStringField(json, "name");
            result.success = extractBoolField(json, "success", false);
            result.rawJson = extractRawField(json, "result");
            
            // 解析错误信息
            std::string errorJson = extractRawField(json, "error");
            if (!errorJson.empty() && errorJson != "null")
            {
                result.errorCode = extractStringField(errorJson, "code");
                result.errorMessage = extractStringField(errorJson, "message");
            }
            
            // 如果没有 error 对象，尝试直接提取 message
            if (result.errorMessage.empty() && !result.success)
            {
                result.errorMessage = extractStringField(json, "message");
            }
            
            return result;
        }

        /**
         * 编码工具结果为 JSON（用于响应）
         */
        std::string encodeToolResult(const ToolResult &result)
        {
            std::string json = "{";
            
            json += "\"id\":\"" + escapeJsonString(result.id) + "\",";
            json += "\"name\":\"" + escapeJsonString(result.name) + "\",";
            json += "\"success\":";
            json += result.success ? "true" : "false";
            
            if (!result.rawJson.empty() && result.rawJson != "null")
            {
                json += ",\"result\":" + result.rawJson;
            }
            
            if (!result.success && (!result.errorCode.empty() || !result.errorMessage.empty()))
            {
                json += ",\"error\":{";
                if (!result.errorCode.empty())
                {
                    json += "\"code\":\"" + escapeJsonString(result.errorCode) + "\"";
                    if (!result.errorMessage.empty())
                    {
                        json += ",";
                    }
                }
                if (!result.errorMessage.empty())
                {
                    json += "\"message\":\"" + escapeJsonString(result.errorMessage) + "\"";
                }
                json += "}";
            }
            
            json += "}";
            return json;
        }

        // ====================================================================
        // 工具调用构建辅助函数
        // ====================================================================

        /**
         * 创建 browser_navigate 调用
         */
        ToolCall createNavigateCall(const std::string &id, const std::string &url)
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_navigate";
            call.argumentsJson = "{\"url\":\"" + escapeJsonString(url) + "\"}";
            call.origin = "wasm_agent";
            return call;
        }

        /**
         * 创建 browser_snapshot 调用
         */
        ToolCall createSnapshotCall(const std::string &id, int tabId = -1, const std::string &selector = "")
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_snapshot";
            
            std::string args = "{";
            bool hasArg = false;
            
            if (tabId > 0)
            {
                char buf[32];
                snprintf(buf, sizeof(buf), "\"tabId\":%d", tabId);
                args += buf;
                hasArg = true;
            }
            
            if (!selector.empty())
            {
                if (hasArg)
                    args += ",";
                args += "\"selector\":\"" + escapeJsonString(selector) + "\"";
            }
            
            args += "}";
            call.argumentsJson = args;
            call.origin = "wasm_agent";
            return call;
        }

        /**
         * 创建 browser_extract 调用
         */
        ToolCall createExtractCall(const std::string &id, int tabId = -1, 
                                   bool extractEmail = true, bool extractPhone = true,
                                   const std::string &socialPlatforms = "[\"linkedin\",\"twitter\",\"facebook\"]")
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_extract";
            
            std::string args = "{";
            
            if (tabId > 0)
            {
                char buf[32];
                snprintf(buf, sizeof(buf), "\"tabId\":%d,", tabId);
                args += buf;
            }
            
            args += "\"selectors\":{";
            args += "\"email\":";
            args += extractEmail ? "true" : "false";
            args += ",\"phone\":";
            args += extractPhone ? "true" : "false";
            args += ",\"social\":";
            args += socialPlatforms;
            args += "}}";
            
            call.argumentsJson = args;
            call.origin = "wasm_agent";
            return call;
        }

        /**
         * 创建 browser_click 调用
         */
        ToolCall createClickCall(const std::string &id, const std::string &selector, int tabId = -1)
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_click";
            
            std::string args = "{";
            
            if (tabId > 0)
            {
                char buf[32];
                snprintf(buf, sizeof(buf), "\"tabId\":%d,", tabId);
                args += buf;
            }
            
            args += "\"selector\":\"" + escapeJsonString(selector) + "\"";
            args += "}";
            
            call.argumentsJson = args;
            call.origin = "wasm_agent";
            return call;
        }

        /**
         * 创建 browser_type 调用
         */
        ToolCall createTypeCall(const std::string &id, const std::string &selector, 
                                const std::string &text, int tabId = -1)
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_type";
            
            std::string args = "{";
            
            if (tabId > 0)
            {
                char buf[32];
                snprintf(buf, sizeof(buf), "\"tabId\":%d,", tabId);
                args += buf;
            }
            
            args += "\"selector\":\"" + escapeJsonString(selector) + "\",";
            args += "\"text\":\"" + escapeJsonString(text) + "\"";
            args += "}";
            
            call.argumentsJson = args;
            call.origin = "wasm_agent";
            return call;
        }

        /**
         * 创建 browser_wait_for 调用
         */
        ToolCall createWaitForCall(const std::string &id, const std::string &selector = "",
                                   const std::string &text = "", int timeout = 5000, int tabId = -1)
        {
            ToolCall call;
            call.id = id;
            call.name = "browser_wait_for";
            
            std::string args = "{";
            bool hasArg = false;
            
            if (tabId > 0)
            {
                char buf[32];
                snprintf(buf, sizeof(buf), "\"tabId\":%d", tabId);
                args += buf;
                hasArg = true;
            }
            
            if (!selector.empty())
            {
                if (hasArg)
                    args += ",";
                args += "\"selector\":\"" + escapeJsonString(selector) + "\"";
                hasArg = true;
            }
            
            if (!text.empty())
            {
                if (hasArg)
                    args += ",";
                args += "\"text\":\"" + escapeJsonString(text) + "\"";
                hasArg = true;
            }
            
            if (hasArg)
                args += ",";
            char buf[32];
            snprintf(buf, sizeof(buf), "\"timeout\":%d", timeout);
            args += buf;
            
            args += "}";
            call.argumentsJson = args;
            call.origin = "wasm_agent";
            return call;
        }

    } // namespace MCP
} // namespace AutoLeadAgent
