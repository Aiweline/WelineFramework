# Weline_AiAcquisition

AI 获客模块：把公开网络上找到的潜客沉淀为可跟进线索，并提供获客智能体与浏览器搜索桥。

- 线索：洲/国家地理、公司、来源 URL、公开邮箱、AI 偏好与业务方向、5 轴评分、去重。
- 智能体：`ai_acquisition`，支持 `web_search` / `inspect_page` / `save_lead` / `list_existing_leads`。
- 模型：只使用 `Weline_Ai` 中启用的文本模型；运行前给出估算，运行后记录 token 与成本。
- 浏览器：MV3 扩展 + SSE 票据桥，搜索在用户浏览器执行；WASM 内核负责资格打分与循环预算。
- 后台：`AI 获客 → 客户线索 / 获客智能体`。

安全边界：只采公开页面与公开商务联系方式；不绕过登录/付费墙/验证码；ticket 2 小时过期且只存哈希前的随机值（不写日志）。


## 后台 UI

- 设计原型：`dev/session/ai-acquisition-20261023/prototype/lead-admin.html`（A/B 可切换）；用户确认 **A · 顶部筛选 + 全宽表格**。
- 生产样式：`view/statics/css/backend/acquisition.css`，只消费 Weline 设计 Token。
- 截图：`dev/session/ai-acquisition-20261023/prototype/shots/`（1440 / 1024 / 768 / 抽屉 / 空态）。
- 前端改动遵循全局「先审丑 → 草图 → 原型确认 → 生产」门禁。


## 归属与站点画像

- 后台归属：`业务运营 → AI运营 → AI获客`（`Weline_Backend::ai_ops_group`），与 `Weline_Ops` 的 AI运营台同级。
- 代码位置：保留独立功能模块 `Weline_AiAcquisition`，不塞进 `Weline_Ops` 内核；通过
  `extends/module/Weline_Ops/Lane/AcquisitionLane.php` 向 AI运营台贡献「获客」车道（创建运行、查看线索、任务类型、连接健康）。
- 获客必须选网站：运行页先选 `website_id`，`RunService` 把站点身份、运营画像、类目/商品、价格带、语种市场、证据缺口注入智能体 system prompt；浏览器桥拿到的 `prompt` 已包含这份画像。
- 默认站 `website_id=0` 也是合法选择（当前站），列表同时展示其他站点。


## 三种模型运行方式

| 模式 | 运行位置 | 计费 | 配置 |
|---|---|---|---|
| `ai_module` | 后端调用 AI 模块模型 | 按 token，写 `ai_usage_log` | 后台选 AI 模块模型 |
| `local_computer` | 扩展直接调本机 Ollama / OpenAI 兼容服务 | 不计费 | 运行页填本机地址与模型名 |
| `browser_local` | 扩展调 Chrome 内置 Prompt API（Gemini Nano） | 不计费 | 运行页选 Chrome 内置模型 |

本地/浏览器模式只把 `assistant/tool_calls/usage` 回传 `/bridge/step` 记账，不在服务端调用模型。
扩展下载：后台运行页「浏览器扩展」卡片 → **下载扩展 ZIP**；解压后加载 `extension/`。

## 扩展下载

`view/statics/downloads/ai-acquisition-extension.zip`（v1.1.0）由后台静态链接提供；改扩展代码后需重新打包。
