# Weline AI 获客浏览器扩展（MV3）

在用户浏览器内执行获客检索与页面抽取，通过**票据桥**与 `Weline_AiAcquisition` 的智能体运行互联。
搜索、页面抽取出入库仍走后端；模型调用支持三种方式：AI 模块云端模型、本机 Ollama/OpenAI 兼容服务、Chrome 内置端侧模型。

## 目录

```
extension/
├── manifest.json          # MV3 清单
├── background.js          # service worker：SSE + 智能体循环 + 搜索/抽取/工具
├── content.js             # 当前页抽取（按需注入，不常驻）
├── popup/                 # 连接、停止、状态、成本、日志
├── options/               # 后端地址、默认检索词
├── wasm/agent_core.wat    # 策略内核源码
├── wasm/agent_core.wasm   # 构建产物
├── build.sh               # wat2wasm 构建 + 魔数校验
└── tests/wasm-smoke.js    # WASM 冒烟测试
```

## 构建 WASM

```bash
bash app/code/Weline/AiAcquisition/extension/build.sh
node app/code/Weline/AiAcquisition/extension/tests/wasm-smoke.js
```

依赖 [wabt](https://github.com/WebAssembly/wabt) 的 `wat2wasm`（macOS：`brew install wabt`）。
通过时最后一行输出 `WASM_OK`。

导出函数：

| 函数 | 签名 | 说明 |
|---|---|---|
| `score_lead` | `(i32×5) -> i32` | 5 轴各 0–2，返回 0–10 资格分 |
| `should_continue` | `(i32,i32,i32) -> i32` | 轮次/线索数未达上限返回 1 |
| `next_query_slot` | `(i32,i32) -> i32` | 返回下一个检索槽位，耗尽返回 -1 |

## 安装（Chrome / Edge）

1. 在后台「AI 获客 → 获客智能体 → 浏览器扩展」点 **下载扩展 ZIP**，解压。
2. 打开 `chrome://extensions`（Edge：`edge://extensions`）。
3. 打开「开发者模式」，点「加载已解压的扩展程序」，选择解压后的 `extension/` 目录。
4. 打开扩展「设置」，填写后端站点地址（如 `https://example.com`），按需填默认检索词并保存。
5. 在后台「AI 获客 → 获客智能体」选择模型运行方式、创建运行，复制运行票据（32 字节 hex，2 小时有效）。
6. 点扩展图标，粘贴票据，点「连接并开始」。弹窗显示模型方式、状态、已入库数、搜索次数、当前成本与日志。

> 运行期间请保持浏览器打开；service worker 由 `alarms` 每 30 秒唤醒保活。
> 弹窗关闭不影响后台运行，重新打开即可看到最新状态。

## 协议

所有请求都带票据，`credentials: 'omit'`，20 秒超时：

| 方法 | 路径 | 用途 |
|---|---|---|
| GET | `/ai_acquisition/frontend/bridge/context?ticket=` | 运行上下文、提示词、模型与报价 |
| GET | `/ai_acquisition/frontend/bridge/stream?ticket=&last_event_id=` | SSE 事件流（`event: bridge` / `event: done`） |
| GET | `/ai_acquisition/frontend/bridge/leads?ticket=&keyword=` | 已有线索摘要（`list_existing_leads`） |
| POST | `/ai_acquisition/frontend/bridge/event` | 回传事件 |
| POST | `/ai_acquisition/frontend/bridge/step` | 模型步进（返回 assistant / tool_calls / usage / cost） |
| POST | `/ai_acquisition/frontend/bridge/lead` | 线索入库（去重合并） |
| POST | `/ai_acquisition/frontend/bridge/complete` | 结束运行并写统计 |

工具：`web_search`（Bing RSS → DuckDuckGo HTML 回退）、`inspect_page`、`save_lead`、`list_existing_leads`。

## 权限说明

| 权限 | 用途 |
|---|---|
| `storage` | 保存后端地址与默认检索词（**不保存票据**） |
| `alarms` | 运行期间保活 service worker |
| `scripting` | 按需注入 `content.js` 抽取当前页 |
| `tabs` | 读取当前标签页地址，判断是否可直接抽取 |
| `host_permissions: <all_urls>` | 访问用户配置的后端与公开搜索/页面 |

CSP 仅放开 `'wasm-unsafe-eval'` 以运行本地 WASM；**没有**远程脚本、没有 `eval`、没有硬编码密钥。

## 安全与数据边界

- 票据只存在 service worker 内存，不写 storage、不进日志、不出现在错误提示里。
- 后端地址在启动时校验为 http(s) 且运行期间来源不可变，避免被改写成任意代理地址。
- 只采公开页面与公开商务联系方式；不绕过登录、付费墙或验证码；不采集非公开个人数据。
- `save_lead` 先经 WASM 5 轴评分，低于 6 分直接跳过，不入库。
- 日志只记录工具名、结果条数与状态，不打印完整邮箱。

## 故障排查

| 现象 | 处理 |
|---|---|
| 弹窗「后台未就绪」 | 重新加载扩展；确认 service worker 未报错 |
| `票据无效或已过期` | 票据 2 小时过期，回后台重新创建运行 |
| `事件流连接失败` | 检查后端地址、反向代理是否关闭 SSE 缓冲（`X-Accel-Buffering: no`） |
| 搜索失败 | 浏览器网络无法访问 Bing/DDG；可在后台改用 SearXNG 搜索链 |
| `WASM 文件加载失败` | 重新执行 `build.sh`，确认 `wasm/agent_core.wasm` 存在 |


## 三种模型运行方式

| 方式 | 运行位置 | 计费 | 配置 |
|---|---|---|---|
| `ai_module` | 后端调用 AI 模块模型 | 按 token 计费，写 `ai_usage_log` | 后台选择 AI 模块模型 |
| `local_computer` | 扩展直接调用本机 Ollama / OpenAI 兼容服务 | 不计费 | 运行页填服务地址（如 `http://127.0.0.1:11434`）与模型名 |
| `browser_local` | 扩展调用 Chrome 内置 Prompt API（Gemini Nano） | 不计费 | 运行页选 Chrome 内置模型；需 Chrome 138+ 并已下载模型 |

本地服务需要允许扩展来源（Ollama 可设置 `OLLAMA_ORIGINS=*` 或在启动参数中放行 `chrome-extension://*`）。浏览器端侧模型不可用时，扩展会明确提示改用本地电脑模型。

### 本地模型协议

扩展按 OpenAI Chat Completions 协议请求：

```http
POST {local_endpoint}/v1/chat/completions
{"model":"qwen2.5:3b","messages":[...],"tools":[...],"tool_choice":"auto","stream":false}
```

没有原生 tool calling 的模型，需要能按 system prompt 返回 JSON；否则只会执行一轮文本结果。

### 端侧模型

`browser_local` 使用 Chrome 内置 `LanguageModel`（Prompt API）。扩展不下载远程脚本或权重，模型由 Chrome 管理；首次使用可能触发浏览器模型下载。不可用时回退到本地电脑模型或 AI 模块模型。
