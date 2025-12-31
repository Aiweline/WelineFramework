/**
 * MCP 工具集定义
 * 在扩展中注册工具
 */

// MCP 工具 Schema 定义
// Use var to make it available in global scope for Service Worker
var MCP_TOOLS_SCHEMA = {
    "browser_navigate": {
        name: "browser_navigate",
        description: "导航到指定URL，打开网页或搜索引擎",
        inputSchema: {
            type: "object",
            properties: {
                url: {
                    type: "string",
                    description: "要访问的URL，例如 'https://www.google.com/search?q=关键词'"
                }
            },
            required: ["url"]
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                url: { type: "string" },
                title: { type: "string" },
                tabId: { type: "number" }
            }
        }
    },
    "browser_snapshot": {
        name: "browser_snapshot",
        description: "获取页面快照，用于分析内容（DOM预处理：过滤script和style标签）",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selector: {
                    type: "string",
                    description: "CSS选择器（可选，用于截取特定元素）"
                }
            }
        },
        outputSchema: {
            type: "object",
            properties: {
                html: { type: "string" },
                text: { type: "string" },
                url: { type: "string" },
                title: { type: "string" }
            }
        }
    },
    "browser_extract": {
        name: "browser_extract",
        description: "提取页面内容（邮箱、电话、社媒等信息）",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selectors: {
                    type: "object",
                    description: "选择器配置",
                    properties: {
                        email: { type: "boolean" },
                        phone: { type: "boolean" },
                        social: {
                            type: "array",
                            items: { type: "string" },
                            description: "社媒平台列表，如 ['facebook', 'linkedin']"
                        }
                    }
                }
            }
        },
        outputSchema: {
            type: "object",
            properties: {
                emails: { type: "array", items: { type: "string" } },
                phones: { type: "array", items: { type: "string" } },
                socialMediaAccounts: { type: "object" }
            }
        }
    },
    "browser_click": {
        name: "browser_click",
        description: "点击页面元素",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selector: {
                    type: "string",
                    description: "CSS选择器",
                    required: true
                }
            },
            required: ["selector"]
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                clicked: { type: "boolean" }
            }
        }
    },
    "browser_type": {
        name: "browser_type",
        description: "在输入框中输入文本",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selector: {
                    type: "string",
                    description: "CSS选择器",
                    required: true
                },
                text: {
                    type: "string",
                    description: "要输入的文本",
                    required: true
                }
            },
            required: ["selector", "text"]
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                typed: { type: "boolean" }
            }
        }
    },
    "browser_wait_for": {
        name: "browser_wait_for",
        description: "等待元素出现或文本出现",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selector: {
                    type: "string",
                    description: "CSS选择器（可选）"
                },
                text: {
                    type: "string",
                    description: "要等待的文本（可选）"
                },
                timeout: {
                    type: "number",
                    description: "超时时间（毫秒，默认5000）"
                }
            }
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                found: { type: "boolean" }
            }
        }
    },
    "browser_fill_form": {
        name: "browser_fill_form",
        description: "填写表单",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                fields: {
                    type: "array",
                    items: {
                        type: "object",
                        properties: {
                            name: { type: "string" },
                            ref: { type: "string" },
                            type: { type: "string" },
                            value: { type: "string" }
                        }
                    },
                    required: true
                }
            },
            required: ["fields"]
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                filled: { type: "number" }
            }
        }
    },
    "browser_select_option": {
        name: "browser_select_option",
        description: "选择下拉选项",
        inputSchema: {
            type: "object",
            properties: {
                tabId: {
                    type: "number",
                    description: "标签页ID（可选，默认当前活动标签页）"
                },
                selector: {
                    type: "string",
                    description: "CSS选择器",
                    required: true
                },
                values: {
                    type: "array",
                    items: { type: "string" },
                    description: "要选择的值",
                    required: true
                }
            },
            required: ["selector", "values"]
        },
        outputSchema: {
            type: "object",
            properties: {
                success: { type: "boolean" },
                selected: { type: "boolean" }
            }
        }
    }
};

// MCP 工具实现
// Use var to make it available in global scope for Service Worker
var mcpTools = {
    "browser_navigate": async function(args) {
        const { url } = args;
        if (!url) {
            throw new Error('URL is required');
        }

        const tab = await chrome.tabs.create({ url: url });
        
        // 等待标签页加载
        await new Promise((resolve) => {
            const listener = (tabId, changeInfo) => {
                if (tabId === tab.id && changeInfo.status === 'complete') {
                    chrome.tabs.onUpdated.removeListener(listener);
                    resolve();
                }
            };
            chrome.tabs.onUpdated.addListener(listener);
            
            // 超时处理
            setTimeout(() => {
                chrome.tabs.onUpdated.removeListener(listener);
                resolve();
            }, 30000);
        });

        return {
            success: true,
            url: tab.url,
            title: tab.title,
            tabId: tab.id
        };
    },

    "browser_snapshot": async function(args) {
        const { tabId, selector } = args;
        const targetTabId = tabId || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]?.id;

        if (!targetTabId) {
            throw new Error('No active tab found');
        }

        const [{result}] = await chrome.scripting.executeScript({
            target: { tabId: targetTabId },
            func: function(sel) {
                // DOM 预处理：过滤 script 和 style 标签
                const body = document.body.cloneNode(true);
                body.querySelectorAll('script, style').forEach(el => el.remove());
                
                const element = sel ? body.querySelector(sel) : body;
                
                return {
                    html: element ? element.innerHTML : '',
                    text: element ? element.innerText : '',
                    url: window.location.href,
                    title: document.title
                };
            },
            args: [selector]
        });

        return result;
    },

    "browser_extract": async function(args) {
        const { tabId, selectors } = args;
        const targetTabId = tabId || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]?.id;

        if (!targetTabId) {
            throw new Error('No active tab found');
        }

        const [{result}] = await chrome.scripting.executeScript({
            target: { tabId: targetTabId },
            func: function(selConfig) {
                const result = {
                    emails: [],
                    phones: [],
                    socialMediaAccounts: {}
                };

                // 提取邮箱
                if (selConfig.email !== false) {
                    const emailRegex = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g;
                    const text = document.body.innerText;
                    result.emails = [...new Set(text.match(emailRegex) || [])];
                }

                // 提取电话
                if (selConfig.phone !== false) {
                    const phoneRegex = /(\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/g;
                    const text = document.body.innerText;
                    result.phones = [...new Set(text.match(phoneRegex) || [])];
                }

                // 提取社媒账户
                if (selConfig.social && Array.isArray(selConfig.social)) {
                    selConfig.social.forEach(platform => {
                        const links = Array.from(document.querySelectorAll('a[href*="' + platform + '"]'));
                        result.socialMediaAccounts[platform] = links.map(link => link.href).filter(Boolean);
                    });
                }

                return result;
            },
            args: [selectors || {}]
        });

        return result;
    },

    "browser_click": async function(args) {
        const { tabId, selector } = args;
        const targetTabId = tabId || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]?.id;

        if (!targetTabId || !selector) {
            throw new Error('Tab ID and selector are required');
        }

        await chrome.scripting.executeScript({
            target: { tabId: targetTabId },
            func: function(sel) {
                const element = document.querySelector(sel);
                if (element) {
                    element.click();
                    return true;
                }
                return false;
            },
            args: [selector]
        });

        return { success: true, clicked: true };
    },

    "browser_type": async function(args) {
        const { tabId, selector, text } = args;
        const targetTabId = tabId || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]?.id;

        if (!targetTabId || !selector || !text) {
            throw new Error('Tab ID, selector and text are required');
        }

        await chrome.scripting.executeScript({
            target: { tabId: targetTabId },
            func: function(sel, txt) {
                const input = document.querySelector(sel);
                if (input) {
                    input.value = txt;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }
                return false;
            },
            args: [selector, text]
        });

        return { success: true, typed: true };
    },

    "browser_wait_for": async function(args) {
        const { tabId, selector, text, timeout } = args;
        const targetTabId = tabId || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]?.id;
        const waitTimeout = timeout || 5000;

        if (!targetTabId) {
            throw new Error('Tab ID is required');
        }

        const startTime = Date.now();
        while (Date.now() - startTime < waitTimeout) {
            const [{result}] = await chrome.scripting.executeScript({
                target: { tabId: targetTabId },
                func: function(sel, txt) {
                    if (sel) {
                        return document.querySelector(sel) !== null;
                    }
                    if (txt) {
                        return document.body.innerText.includes(txt);
                    }
                    return false;
                },
                args: [selector, text]
            });

            if (result) {
                return { success: true, found: true };
            }

            await new Promise(resolve => setTimeout(resolve, 100));
        }

        return { success: false, found: false };
    }
};

// Export to global scope for Service Worker
if (typeof self !== 'undefined') {
    // Service Worker context
    self.mcpTools = mcpTools;
    self.MCP_TOOLS_SCHEMA = MCP_TOOLS_SCHEMA;
}

// Export to global scope for window context
if (typeof window !== 'undefined') {
    window.mcpTools = mcpTools;
    window.MCP_TOOLS_SCHEMA = MCP_TOOLS_SCHEMA;
}

// Export for CommonJS (if in module environment)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { MCP_TOOLS_SCHEMA, mcpTools };
}

