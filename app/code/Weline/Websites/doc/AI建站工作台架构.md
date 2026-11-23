# AI 建站工作台与建站智能体：架构说明

> 模块：`Weline_Websites`（工作台/会话/持久化）+ `Weline_Ai`（通用 Agent 框架）
> 定位：统一说明「AI 建站」这条产品线的分层、关键文件、两条执行路径与边界。
> 相关文档：`计划-AI建站工作台-Websites侧.md`（本地域名策略）、`需求.md`；
> 通用 Agent 框架见 `app/code/Weline/Ai/doc/智能体(Agent)增强计划.md` 与 `app/code/Weline/Ai/doc/开发/后台智能体管理.md`。

## 一、总体分层

```
后台入口（Websites）
  SiteBuilderAgent Controller：hub(index) + workspace 两个页面，ACL `site_builder_agent`
        │
        ▼
工作台服务层 Service/AiWorkbench/*
  ProviderRegistry（AiSiteBuilderProvider 扩展点装配）
  ProviderWorkbenchService（scope/provider_state/工具/阶段引导）
  Session / Message / EventStream / Artifact / PlanDraft / PlanGeneration
  VirtualTheme / DomainPurchase / DomainLifecycleBridge
        │ 需要 LLM「思考/执行」时
        ▼
Weline_Ai 通用 Agent 框架
  AgentInterface 契约 + AgentScanner 扫描注册（ai_agent 表）
  ScenarioAdapter（site_builder 场景）、AiModel、ProviderFactory（流式）
        │
        ▼
具体 Agent + 工具
  extends/module/Weline_Ai/Agent/WebsiteBuilderAgent.php（手写 ReAct 循环）
  Service/AI/Tool/*（ToolInterface，7 个实现，3 个挂载）
        │
        ▼
真实执行 WebsiteAgentService::buildFromDescription
  查可用性 → DomainPurchaseService::createAndProcessOrder（买域名+DNS+建站）
  → server.requestCertificate（HTTPS）
  本地域名/demo 走 simulated 分支 + 本地泛域名证书兜底
```

## 二、两条执行路径（重要）

「AI 建站」不是单一 agent 循环，而是两条路径共用底层执行服务：

### 路径 A：工作台向导（人在环，主路径）

1. `SiteBuilderAgent::index` / `postCreateSession` 创建 `AiSiteBuilderSession`（scope + provider_state JSON）。
2. `SiteBuilderPlan` / `SiteBuilderWorkbenchQueryHandler`（22 个 command）驱动：
   `preparePlan` → `completePlan`（LLM 生成方案，SSE 流式）→ `confirmPlan`
   → `listLocalPool`/`reserveLocalPool`/`selectDomain`（选域名）
   → `createSession` → `startDomainPurchase`（排队）→ `generateVirtualTheme`/页面布局保存。
3. 每一步把状态写进 session scope、Message、Event（SSE 用 `Last-EventId` 续传）、Artifact。
4. 域名购买由 `AiSiteProvisioningService`/`AiSiteProvisioningJobHandler` 队列异步执行。

### 路径 B：Agent 一键建站（ReAct 循环）

- `WebsiteBuilderAgent implements AgentInterface`（code=`website_builder`，scenarios=`website_builder|site_builder`）。
- `execute()` 是手写循环：system prompt + tool defs → `provider->generateStreamFull` →
  LLM `tool_calls` → `ToolInterface::execute($args)` → 结果回填 messages → 下一轮；
  无 tool_calls 或 `purchase_domain_and_build_site` 成功即收敛；轮次默认 12、硬顶 30
  （`websites.ai.website_builder.max_iterations` 可配）。
- 流式事件：`agent_status` / `tool_call` / `tool_result` / `ai_response` / `chunk` / `heartbeat`，
  由 `streamCallback` 透传给 SSE 前端。

两条路径最终都落到 `WebsiteAgentService`：A 路径通过 `DomainPurchaseWorkbenchService` 排队，
B 路径通过 `purchase_domain_and_build_site` 工具直接调用。

## 三、关键文件地图

### Websites 侧（工作台）

| 关注点 | 文件 |
|---|---|
| 后台入口 | `Controller/Backend/SiteBuilderAgent.php`、`Controller/Backend/SiteBuilderPlan.php` |
| 页面模板 | `view/templates/backend/SiteBuilderAgent/index-v1.phtml`、`workspace.phtml` |
| 命令分发 | `Service/AiWorkbench/SiteBuilderWorkbenchQueryHandler.php`（COMMANDS 白名单，拒绝 url/method/headers 传输参数） |
| Provider 扩展点 | `Api/AiSiteBuilderProviderInterface.php`、`Api/AiSiteBuilderProviderRegistryInterface.php`、`extends/module/Weline_Websites/AiSiteBuilderProvider/WebsitesDefaultProvider.php` |
| Provider 装配 | `Service/AiWorkbench/ProviderRegistry.php` + `ExtensionPointReader.php`（按 extends.php 注册表，mtime 缓存） |
| 工作台配置 | `Service/AiWorkbench/ProviderWorkbenchService.php`（buildWorkbenchConfig / ForSession） |
| 会话/消息/事件/产物 | `Service/AiWorkbench/{SessionService,MessageService,EventStreamService,ArtifactService}.php` |
| 方案草稿/生成 | `Service/AiWorkbench/{PlanDraftService,PlanGenerationService}.php`、`Model/AiSitePlanDraft.php` |
| 虚拟主题 | `Service/AiWorkbench/VirtualThemeWorkbenchService.php`、`ThemeSourceRegistry.php` |
| 域名购买/生命周期 | `Service/AiWorkbench/{DomainPurchaseWorkbenchService,DomainLifecycleBridgeService}.php` |
| 真实建站执行 | `Service/WebsiteAgentService.php`（`buildFromDescription`、`recommendAvailableDomain`、`checkCandidateAvailability`） |
| 建站队列 | `Service/AiSiteProvisioningService.php`、`AiSiteProvisioningJobHandler.php`、`AiSiteProvisioningQueueGateway.php` |
| 持久化模型 | `Model/AiSiteBuilder{Session,Message,Event,Artifact}.php`、`Model/AiSitePlanDraft.php` |

### Ai 侧（Agent 框架）

| 关注点 | 文件 |
|---|---|
| 契约 | `Weline/Ai/Api/AgentInterface.php`、`Api/ToolInterface.php`、`Api/AgentResult.php`、`Api/AiModel.php` |
| 扫描注册 | `Weline/Ai/Service/AgentScanner.php`（内置 `Agent/` + 各模块 `extends/module/Weline_Ai/Agent/`，注册 `ai_agent` 表） |
| 场景适配 | `extends/module/Weline_Ai/Adapter/SiteBuilderAdapter.php`（site_builder，零改造） |
| Agent 实现 | `extends/module/Weline_Ai/Agent/WebsiteBuilderAgent.php` |
| 工具实现 | `Service/AI/Tool/{GetRegistrarAccounts,CheckDomainAvailability,PurchaseDomainAndBuildSite,RecommendDomains,GeneratePageDraft,PreviewWebsite,ConfirmDomainPurchase}Tool.php` |
| 治理 | `Service/Agent/AgentCatalogService.php`、`AgentGovernance.php`（工具启停/描述覆盖；直接 `$agent->execute()` 旁路不受治理） |

## 四、Provider 扩展点

- `AiSiteBuilderProvider` 是 Websites 侧声明的扩展点：provider 自报 code、scope 结构、
  tools、阶段引导（stage guides）、welcome message、native entry URL、handoff label。
- `ProviderRegistry` 经 `ExtensionPointReader` 读 `extends.php` 注册表实例化，按
  `sort_order + code` 排序；registry 文件 mtime 未变时走缓存。
- 当前 `websites_default`；PageBuilder AI 建站已分离（`postCreateSession` 显式拒绝
  `provider_code=pagebuilder`），两线只共用 AI 调用、不互跳。

## 五、本地/演示分流（不要改错）

- 本地域名：`*.test.weline.com`（开发/测试）、`*.weline.localhost`（本地回环生产入口）、
  `.local`/`.localhost` 后缀。命中后跳过真实注册商购买，走本地域名池 + 共享泛域名证书。
- demo 账号：`account_id >= 900000`，可用性检查返回 `simulated=true`。
- `fake_mode`：前端/命令显式传入，作用于推荐域名、可用性检查、建站执行各分支。
- 真实域名链路不变：推荐 → 可用性 → `DomainPurchaseService::createAndProcessOrder`
  （订单+解析+自动建站）→ `server.requestCertificate`。

细节与验收口径以 `计划-AI建站工作台-Websites侧.md` 为准。

## 六、已知边界与坑

1. `WebsiteBuilderAgent::getTools()` 只挂 3 个工具；`RecommendDomains`、`GeneratePageDraft`、
   `PreviewWebsite`、`ConfirmDomainPurchase` 已实现但未注册进 agent（UI 路径走
   `SiteBuilderWorkbenchQueryHandler` 的专用命令，不经 agent）。
2. Agent 循环是单目标 ReAct，收敛靠硬编码（`purchase_domain_and_build_site` 成功即 break），
   不是通用 runner；`max_iterations` 触顶返回 failure。
3. Agent 治理（AgentCatalog/AgentGovernance 的工具启停、描述覆盖）只对
   `AiService::executeAgent` 路径生效；直接 `$agent->execute()` 旁路不受治理。
4. 工作区前端一律用 path-only URL（`getBackendUrlPath`），避免 E2E 代理下 Cookie 丢失。
5. `SiteBuilderWorkbenchQueryHandler` 拒绝 `url/method/headers` 传输参数——它是命令
   白名单分发，不是通用 HTTP 代理。
