# Weline_AiAcquisition · AI 获客

## 1. 定位

把公开网络上找到的潜客沉淀成可跟进的客户记录，并让获客智能体在「AI 模块模型 + 用户浏览器搜索」之间协同工作。

```
公开网页 / 论坛 / 名录 / 社媒
        │  web_search / inspect_page
        ▼
AcquisitionAgent（服务端）  ── 或 ──  浏览器扩展（WASM 内核 + JS I/O）
        │  save_lead
        ▼
ai_acquisition_lead  ──► 后台「客户线索」──► 人工跟进（状态流转）
        │
        └── ai_acquisition_run / ai_acquisition_bridge_event（成本与票据）
```

## 2. 数据模型

| 表 | 说明 |
|---|---|
| `ai_acquisition_continent` | 7 洲 + 未知；线索通过 `continent_code` 归属 |
| `ai_acquisition_lead` | 客户记录：国家/地区/经纬度/时区、公司、来源 URL、公开邮箱、AI 偏好/业务方向/评分/理由、状态、去重哈希 |
| `ai_acquisition_prompt_profile` | 站点/品类专属获客提示词档案；内置通用 B2B 提示词作为兜底 |
| `ai_acquisition_run` | 智能体运行：模型、提示词快照、票据、搜索/线索计数、token/成本、状态 |
| `ai_acquisition_bridge_event` | 浏览器桥 SSE 事件队列，支持断线重放 |

线索去重优先级：邮箱 → 公司域名 → 来源 URL 的 SHA-256。重复命中只更新非空字段并累加 `seen_count`，不覆盖人工状态。

## 3. 智能体

- 代码：`ai_acquisition`；场景：`ai_acquisition`、`lead_generation`。
- 工具：`web_search`、`inspect_page`、`save_lead`、`list_existing_leads`。
- 资格：5 轴各 0–2 分（品类/采购意图/B2B 属性/数量或时间/公开渠道），≥6 才入库；证据原文、来源 URL、公开邮箱必须齐全。
- 默认提示词只写通用 B2B 获客方法论；站点专属内容（例如某站品类、发件身份）必须通过「提示词档案」录入，不写进模块代码。
- 模型：只列 `Weline_Ai` 中启用的 `text2text` 模型；每千 token 价格来自 `ai_model.token_price_input/output`，运行后写 token/成本台账。

## 4. 浏览器桥

扩展在用户浏览器内执行搜索，避免服务端 IP 被搜索引擎拦截；模型调用仍回后端，密钥不下发浏览器。

1. 后台「获客智能体」创建运行 → 得到 2 小时票据。
2. 扩展 popup 填后端地址 + 票据 → 连接。
3. 扩展拉取运行上下文（提示词、模型、估算），加载 `agent_core.wasm`。
4. 循环：`POST /bridge/step`（模型）→ 执行工具（浏览器搜索/抽页）→ `POST /bridge/lead`（入库）→ 直到达到目标或轮次上限。
5. SSE `GET /bridge/stream` 用于取消/服务端事件；`POST /bridge/event` 回传进度。
6. 结束 `POST /bridge/complete`，后台写入 token/成本与状态。

票据安全：32 字节随机 hex、2 小时过期、只对单次运行有效；不写日志；扩展不保存后台账号密码。

## 5. 后台入口

- `AI 获客 → 客户线索`：筛选、洲/国家、来源、评分、状态、增删改、批量状态/删除。
- `AI 获客 → 获客智能体`：模型价格/8 轮预估、提示词档案、签发票据、扩展安装说明、运行记录与成本。

页面只负责渲染；所有写操作经 `ai_acquisition` QueryProvider（BinQuery），不手写 REST/fetch。

## 6. 已知边界

- 不自动发信；`contacted/replied/converted` 由人工或后续邮件模块推进。
- 不绕过登录/付费墙/验证码；只采公开页面与公开商务联系方式。
- 服务端搜索依赖 Bing RSS / DuckDuckGo HTML 的公开可用性；失败时返回空结果并提示换关键词，不编造。
- 浏览器扩展需手动加载已解压目录；WASM 内核只做打分/循环预算，I/O 与模型传输由 JS 完成。
