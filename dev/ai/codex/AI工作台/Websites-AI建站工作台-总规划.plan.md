# Websites AI建站工作台总规划

- 状态：completed
- 优先级：high
- 完成日期：2026-03-28
- 规划日期：2026-03-22
- 负责模块：
  - `Weline_Websites`
  - `Weline_Theme`
  - `GuoLaiRen_PageBuilder`
- 目标产物：
  - `Weline_Websites` 统一 AI 建站工作台
  - provider 扩展机制
  - Theme 主题源接入机制
  - 默认 Websites 建站流程
  - PageBuilder provider 接入方案
  - 单元测试 + e2e 自动测试

## 1. 背景与目标

### 1.1 目标

把当前分散在 `Weline_Websites` 与 `GuoLaiRen_PageBuilder` 的 AI 建站能力，收敛成一个由 `Weline_Websites` 持有的平台级工作台：

1. 用户在 `Websites` 的 AI 建站工作台中，通过聊天与智能体完成建站
2. 平台默认流程支持：
   - 聊天确认站点定位、客户画像、标题、风格、卖点、页面建议
   - 推荐域名、选择账户、检查可用性、确认购买
   - 接入 Websites 现有域名生命周期处理与 SSE 状态展示
   - 选择主题来源与页面类型
   - 生成虚拟主题页面数据并支持微调
   - 创建网站并关联主题
   - 生成预览入口
3. 平台支持 `provider_code` 扩展：
   - `websites_default` 走 Websites 自带流程
   - `pagebuilder` 走 PageBuilder 自己的流程与工具
   - 后续模块可以继续接入

### 1.2 核心约束

1. `Weline_Websites` 不得直接知道 `PageBuilder` 内部的 `weline_theme_id`、`preview_page_id`、虚拟主题结构等私有实现细节
2. `provider_code` 绑定“流程提供者”，而不是只绑定“工具列表”
3. Theme 不直接依赖 Websites 工作台内部实现，Theme 只通过受控扩展点提供“主题源能力”
4. 所有实现遵循 SOLID 与 TDD
5. 高风险外部动作必须有明确确认门槛，尤其是域名购买

## 2. 非目标

### 2.1 本规划不做的事

1. 不在本阶段重写整个 `Weline_Ai` Agent 框架
2. 不在本阶段统一所有 PageBuilder 旧会话数据的历史迁移
3. 不在本阶段改造所有 Theme 编辑器交互，只暴露工作台所需最小能力
4. 不在本阶段做真实域名购买的自动化测试

### 2.2 可后置的事

1. 旧 PageBuilder AI 工作台历史会话迁移
2. 多租户/多管理员协作编辑
3. 更复杂的草稿 diff、版本回滚、多人审批流

## 3. 当前仓库现状

### 3.1 已有能力

1. `Weline_Websites` 已有一个平台默认建站入口：
   - `app/code/Weline/Websites/etc/backend/menu.xml`
   - `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
   - `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
2. `Weline_Websites` 已有默认 AI agent：
   - `app/code/Weline/Websites/extends/module/Weline_Ai/Agent/WebsiteBuilderAgent.php`
3. `Weline_Websites` 已有域名购买、DNS、SSL、生命周期处理能力：
   - `WebsiteAgentService`
   - `DomainPurchaseService`
   - `DomainResolveService`
   - QueryProvider / lifecycle 查询链路
4. `GuoLaiRen_PageBuilder` 已有更重的工作台雏形：
   - `Controller/Backend/AiSiteAgent.php`
   - `Service/AiSiteAgentSessionService.php`
   - `Model/AiSiteAgentSession.php`
   - `Model/AiSiteAgentSessionEvent.php`
5. `QuickBuildAggregator` 已证明“入口在一边、能力由模块注入”的模式可行
6. `Weline_Theme` 已有 QueryProvider，可扫描主题布局：
   - `getActiveTheme`
   - `scanThemeLayoutsByType`
7. 仓库已有 Playwright e2e 基建：
   - `tests/e2e/`
   - 模块级 `test/e2e/*.spec.js`

### 3.2 当前缺口

1. `Websites` 工作台还是“一次性表单 + 一次性 SSE”，不是会话式工作台
2. 缺少 provider 注册表与 provider 抽象
3. Theme 还没有“向建站工作台提供主题源能力”的正式扩展点
4. 页面类型、虚拟主题生成、页面微调还没有统一落在 `Websites` 工作台的默认流程里
5. `PageBuilder` 自己的 AI 工作台与 `Websites` 没有统一抽象
6. 域名处理流还没转换成工作台顶部状态条与事件流模型
7. 缺少完整的 TDD 方案与 e2e 分层测试矩阵

## 4. 目标架构

### 4.1 总体分层

#### A. 平台壳层：`Weline_Websites`

负责：

1. AI 建站工作台入口
2. provider 注册与选择
3. 统一 session/message/event/artifact 持久化
4. 通用阶段管理、SSE 事件分发、权限、路由、菜单
5. 默认 `websites_default` provider
6. 主题源注册表

#### B. 默认流程层：`Weline_Websites`

负责：

1. 对话确认站点画像
2. 域名推荐与购买确认
3. 域名生命周期展示
4. 主题源选择
5. 页面类型选择
6. 默认虚拟主题草稿生成
7. 网站物料化与预览

#### C. 主题源层：`Weline_Theme` 等

负责：

1. 向 Websites 工作台暴露可选主题来源
2. 提供主题候选项、布局元数据、默认 page types
3. 提供“如何创建/绑定主题产物”的能力

#### D. 外部流程提供者层：`GuoLaiRen_PageBuilder` 等

负责：

1. 注册自己的 `provider_code`
2. 定义自己的阶段与流程
3. 持有自己的 AI 工具与预览逻辑
4. 通过平台 session/event/artifact 运行，而不是把私有字段塞进 `Weline_Websites`

### 4.2 provider_code 方案

建议至少支持以下 provider：

1. `websites_default`
   - Platforms 核心默认流程
   - 主题来自 `WebsiteThemeSourceInterface` 注册表
2. `pagebuilder`
   - PageBuilder 自己的 AI 建站流程
   - 可走自己的页面草稿生成与可视化编辑

### 4.3 关键边界

1. `Weline_Websites` 只知道 `provider_code`
2. `Weline_Websites` 只知道通用 `artifact`、`event`、`message`
3. `Weline_Websites` 不知道 PageBuilder 专用主题主键
4. provider 自己决定如何把私有引用放进 `provider_state_json` 或 artifact payload

## 5. 默认 Websites 流程设计

### 5.1 阶段设计

建议阶段 code：

1. `profile_chat`
   - 聊天确认标题、行业、客户画像、目标群体、卖点、品牌风格、转化目标、页面建议
2. `domain_candidates`
   - 推荐域名、账号选择、可用性检测、用户确认
3. `domain_purchase`
   - 执行购买
4. `domain_processing`
   - 展示 Websites 现有域名生命周期状态
5. `theme_selection`
   - 选择主题来源与主题方案
6. `page_type_selection`
   - 选择要生成的页面类型
7. `draft_generation`
   - 生成各页面草稿与虚拟主题结构
8. `page_refinement`
   - 微调各页面数据
9. `materialization`
   - 创建网站、绑定域名、关联主题
10. `preview_ready`
   - 生成预览地址
11. `completed`
   - 完成

### 5.2 默认用户路径

1. 用户进入 Websites AI 建站工作台
2. 选择 provider，默认 `websites_default`
3. 创建 session
4. 在聊天区描述站点需求
5. AI 补全结构化 site profile
6. 系统推荐域名并让用户选择账号
7. 用户确认某个可用域名后，系统执行购买
8. 顶部状态区持续显示域名生命周期
9. 用户选择主题来源与主题方案
10. 用户选择页面类型
11. AI 生成页面草稿与虚拟主题结构
12. 用户逐页微调
13. 系统创建 Website 并关联主题
14. 工作台显示预览地址与完成状态

## 6. 数据模型建议

### 6.1 Session

新建 `AiSiteBuilderSession`

建议字段：

1. `session_id`
2. `public_id`
3. `admin_user_id`
4. `provider_code`
5. `title`
6. `current_stage`
7. `status`
8. `website_id`
9. `selected_domain`
10. `registrar_account_id`
11. `scope_json`
12. `provider_state_json`
13. `preview_url`
14. `create_time`
15. `update_time`

### 6.2 Message

新建 `AiSiteBuilderMessage`

建议字段：

1. `message_id`
2. `session_id`
3. `role`
4. `message_type`
5. `content`
6. `tool_name`
7. `tool_payload_json`
8. `create_time`

用途：

1. 持久化聊天记录
2. 区分用户消息、AI 消息、工具消息、系统提示

### 6.3 Artifact

新建 `AiSiteBuilderArtifact`

建议字段：

1. `artifact_id`
2. `session_id`
3. `artifact_type`
4. `artifact_code`
5. `title`
6. `status`
7. `sort_order`
8. `payload_json`
9. `create_time`
10. `update_time`

建议 `artifact_type`：

1. `site_profile`
2. `domain_candidate`
3. `theme_candidate`
4. `page_type`
5. `page_draft`
6. `preview`

### 6.4 Event

新建 `AiSiteBuilderEvent`

建议字段：

1. `event_id`
2. `session_id`
3. `stage_code`
4. `event_type`
5. `level`
6. `payload_json`
7. `create_time`

用途：

1. SSE 增量推送
2. 顶部状态条
3. 域名生命周期桥接
4. 可审计的工作台事件日志

## 7. 扩展点设计

### 7.1 Websites 提供的扩展点

在 `app/code/Weline/Websites/extends.php` 新增：

1. `AiSiteBuilderProvider`
   - 路径：`extends/module/Weline_Websites/AiSiteBuilderProvider`
   - 接口：`Weline\Websites\Api\AiSiteBuilderProviderInterface`
2. `WebsiteThemeSource`
   - 路径：`extends/module/Weline_Websites/WebsiteThemeSource`
   - 接口：`Weline\Websites\Api\WebsiteThemeSourceInterface`

### 7.2 Theme 的角色

`Weline_Theme` 不做 provider，但实现 `WebsiteThemeSourceInterface`：

1. 返回可选主题方案
2. 返回支持的布局类型
3. 返回页面类型建议
4. 接收“根据站点画像创建主题产物”的请求

### 7.3 PageBuilder 的角色

`GuoLaiRen_PageBuilder` 实现 `AiSiteBuilderProviderInterface`：

1. code 为 `pagebuilder`
2. 自己控制阶段
3. 自己控制 AI 工具
4. 自己决定如何生成页面草稿与预览

## 8. 重点文件改动清单

### 8.1 `Weline_Websites`

#### 修改

1. `app/code/Weline/Websites/etc/backend/menu.xml`
   - 保留 Websites 主入口
   - 清理或弱化对 PageBuilder 菜单组的耦合
2. `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
   - 从一次性触发页升级为工作台控制器
3. `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
   - 改为会话式工作台 shell
4. `app/code/Weline/Websites/extends.php`
   - 新增 provider 与 theme source 扩展定义

#### 新增

1. `Api/AiSiteBuilderProviderInterface.php`
2. `Api/WebsiteThemeSourceInterface.php`
3. `Api/Data/*.php` 或 `Service/AiWorkbench/Data/*.php`
4. `Model/AiSiteBuilderSession.php`
5. `Model/AiSiteBuilderMessage.php`
6. `Model/AiSiteBuilderArtifact.php`
7. `Model/AiSiteBuilderEvent.php`
8. `Service/AiWorkbench/ProviderRegistry.php`
9. `Service/AiWorkbench/ThemeSourceRegistry.php`
10. `Service/AiWorkbench/SessionService.php`
11. `Service/AiWorkbench/MessageService.php`
12. `Service/AiWorkbench/ArtifactService.php`
13. `Service/AiWorkbench/EventStreamService.php`
14. `Service/AiWorkbench/DomainLifecycleBridgeService.php`
15. `Service/AiWorkbench/Provider/WebsitesDefaultProvider.php`
16. `Service/AiWorkbench/Workflow/*`
17. `Controller/Backend/Api/AiWorkbench.php` 或拆分 API 控制器
18. `view/templates/Backend/SiteBuilderAgent/workspace.phtml`
19. `view/templates/Backend/SiteBuilderAgent/partials/*.phtml`

### 8.2 `Weline_Theme`

#### 修改

1. `app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php`
   - 增加列主题、列布局、主题详情等查询能力

#### 新增

1. `app/code/Weline/Theme/extends/module/Weline_Websites/WebsiteThemeSource/WelineThemeSource.php`
2. 可选：
   - `Taglib/ThemeSelect.php`
   - 或在工作台中复用 `w:theme:search-select`
3. `Service/WebsiteThemeMaterializer.php`
   - 接受站点画像与 page drafts，创建数据库主题或绑定既有主题

### 8.3 `GuoLaiRen_PageBuilder`

#### 修改

1. `app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml`
   - AI 建站工作台入口改为指向 Websites 工作台并带 `provider=pagebuilder`
2. `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`
   - 逐步退化为兼容跳转或 provider 内部调试入口

#### 新增

1. `app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php`
2. `app/code/GuoLaiRen/PageBuilder/Service/AiWorkbench/*`
   - 适配现有 `AiSiteAgentSessionService` 与工具链

## 9. 实施阶段

### Phase 0. 契约与脚手架

目标：

1. 先落接口，不先糊 UI
2. 先把 provider/theme source 两类扩展点定下来
3. 先建 session/message/event/artifact 模型与服务

验收：

1. `setup:upgrade` 成功
2. ProviderRegistry 能发现默认 provider
3. ThemeSourceRegistry 能发现 Theme 提供者

### Phase 1. 工作台会话壳

目标：

1. `Websites` 工作台具备 session 创建、加载、消息列表、事件流
2. UI 有 provider 选择、聊天区、阶段条、顶部状态区、右侧草稿区骨架

验收：

1. 后台可创建 session
2. SSE 增量事件可工作
3. 会话刷新不丢失

### Phase 2. 默认 provider 对话确认

目标：

1. 聊天输入后形成结构化 `site_profile`
2. AI 给出画像、站点标题、页面建议
3. 允许用户确认/改写

验收：

1. message 持久化
2. artifact 中产生 `site_profile`
3. 阶段能从 `profile_chat` 推进到 `domain_candidates`

### Phase 3. 域名推荐与处理流

目标：

1. 默认 provider 使用 Websites 域名能力给出候选域名
2. 用户选择账户并确认购买
3. 顶部状态区展示域名生命周期

验收：

1. 不经过确认不执行购买
2. 购买成功后 session 记录 `selected_domain`
3. domain lifecycle 可通过 event + poll 混合方式刷新

### Phase 4. 主题源与页面类型

目标：

1. Theme 作为 Websites 的 theme source 接入
2. 工作台可列出主题方案
3. 页面类型来自 Theme 布局元数据

验收：

1. ThemeSourceRegistry 能发现 Theme 模块实现
2. 用户可选主题源和页面类型
3. 页面类型 metadata 可进入 artifact

### Phase 5. 草稿生成与微调

目标：

1. 默认 provider 生成 page draft artifacts
2. 用户可逐页编辑标题、描述、区块数据、提示词

验收：

1. 每个 page type 至少产出一个 `page_draft`
2. 修改后能重新生成单页
3. 事件流能反映生成进度

### Phase 6. 网站物料化与预览

目标：

1. 创建 Website
2. 绑定域名
3. 绑定主题
4. 输出预览地址

验收：

1. Website 成功创建
2. 主题与网站关系建立成功
3. 点击预览可打开

### Phase 7. PageBuilder provider 接入

目标：

1. PageBuilder 以 `pagebuilder` provider 接入统一工作台
2. 保留它自己的工具和流程
3. Websites 不感知其私有字段

验收：

1. `provider=pagebuilder` 能进入 PageBuilder 流程
2. PageBuilder 菜单可跳到统一工作台
3. 平台 session/event/artifact 可复用

### Phase 8. 测试与硬化

目标：

1. 补齐单元测试
2. 补齐 e2e
3. 补齐 `http:request` 路由验证
4. 评估 WLS/static 状态风险

验收：

1. 核心单元测试通过
2. e2e 场景通过
3. 外部依赖均可被 fake/stub 替换

## 10. TDD 与 SOLID 实施规则

### 10.1 TDD 顺序

1. 先测 registry，再实现 registry
2. 先测 session/message/event/artifact service，再落模型
3. 先测 default provider 对站点画像与阶段推进，再接 AI
4. 先测 domain workflow orchestration，再接真实购买服务
5. 先测 theme source registry 与 Theme provider，再接 UI
6. 先测 materializer，再接 preview
7. 最后补 e2e

### 10.2 SOLID 约束

1. 单一职责：
   - controller 只负责协议层
   - session/message/event/artifact 各自独立 service
   - provider 只编排，不直写数据库
2. 开闭原则：
   - 新 provider、新 theme source 不修改核心分发逻辑
3. 里氏替换：
   - `pagebuilder` provider 必须完全替代默认 provider 的接口契约
4. 接口隔离：
   - conversation/domain/theme/draft/materialize/preview 分成小接口
5. 依赖倒置：
   - core 依赖接口与 DTO，不依赖 PageBuilder 实现

## 11. 测试策略

### 11.1 单元测试

目标目录：

1. `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/`
2. `app/code/Weline/Theme/Test/Unit/Extends/Weline_Websites/`
3. `app/code/GuoLaiRen/PageBuilder/Test/Unit/Extends/Weline_Websites/`

重点测试：

1. ProviderRegistry
2. ThemeSourceRegistry
3. SessionService
4. MessageService
5. ArtifactService
6. EventStreamService
7. DomainLifecycleBridgeService
8. WebsitesDefaultProvider
9. Theme source provider
10. PageBuilder provider adapter

### 11.2 路由与接口验证

建议命令：

```bash
php bin/w setup:upgrade -m Weline_Websites --yes
php bin/w http:request websites/backend/site-builder-agent/index -b
php bin/w http:request websites/backend/site-builder-agent/state-json -b
php bin/w http:request websites/backend/site-builder-agent/stream-sse -b
```

### 11.3 E2E

建议新增：

1. `app/code/Weline/Websites/test/e2e/backend/ai-workbench-default.spec.js`
2. `app/code/Weline/Websites/test/e2e/backend/ai-workbench-domain-flow.spec.js`
3. `app/code/Weline/Websites/test/e2e/backend/ai-workbench-theme-page-flow.spec.js`
4. `app/code/Weline/Websites/test/e2e/backend/ai-workbench-preview.spec.js`
5. `app/code/GuoLaiRen/PageBuilder/test/e2e/backend/ai-workbench-pagebuilder-provider.spec.js`

### 11.4 E2E 环境原则

1. 不允许真实购买域名
2. 需要 fake registrar / fake lifecycle / fake ai responder
3. 需要 deterministic seed data
4. 需要明确 testing mode 配置或 fake provider 模块

## 12. 可能遇到的问题

### 12.1 高风险问题

1. 域名购买是外部真实动作
   - 必须强制确认
   - 测试环境必须 fake 化
2. `Websites` 若直接依赖 PageBuilder 细节，会造成核心模块反向污染
3. 如果只抽“工具列表”不抽“流程 provider”，后续 provider 会再次分叉

### 12.2 中风险问题

1. Theme 当前只提供有限 query，可能不足以支撑工作台所需元数据
2. PageBuilder 旧 `AiSiteAgentSession` 与新平台 session 可能并存一段时间
3. SSE 会话与轮询混用时要注意重复事件与去重
4. WLS 长驻进程下，registry/cache 如果使用 static，必须评估 `StateManager` 重置

### 12.3 低风险问题

1. 菜单入口重叠
2. 老 URL 兼容跳转
3. i18n 文案量较多

## 13. 兼容与迁移策略

### 13.1 短期兼容

1. 保留 `Weline_Websites` 原工作台路由
2. `PageBuilder` 旧入口先保留
3. 新统一工作台上线后，`PageBuilder` 菜单可先跳转到 Websites 工作台并带 `provider=pagebuilder`

### 13.2 中期迁移

1. `PageBuilder\Controller\Backend\AiSiteAgent` 改为兼容 redirect
2. `PageBuilder` 旧会话表保留只读，不强制迁移历史数据

## 14. 完成定义

满足以下条件视为一期完成：

1. `Weline_Websites` 工作台已是会话式 AI 建站中心
2. 默认 `websites_default` 流程可完成从聊天到预览
3. Theme 已能通过扩展点向建站中心提供主题源能力
4. `pagebuilder` provider 已能接入统一工作台
5. 不存在 `Websites` 对 `PageBuilder` 私有字段的直接依赖
6. 单元测试与 e2e 已覆盖核心主路径
