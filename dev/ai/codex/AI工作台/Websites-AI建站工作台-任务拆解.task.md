# Websites AI建站工作台任务拆解

- 状态：not_started
- 执行原则：TDD first, SOLID first

## Epic 0. 规划与兼容入口

- [x] 0.1 明确平台归属为 `Weline_Websites`
- [x] 0.2 明确 `provider_code` 绑定流程提供者而不是工具列表
- [x] 0.3 明确 Theme 通过独立 theme source 接入
- [ ] 0.4 确认 `PageBuilder` 旧入口的兼容策略
- [ ] 0.5 确认 `Weline_Websites` 菜单与 `PageBuilder` 菜单最终入口策略

目标文件：

- `dev/ai/codex/AI工作台/Websites-AI建站工作台-总规划.plan.md`
- `dev/ai/codex/AI工作台/Websites-AI建站工作台-接口草图.md`

完成标准：

- 平台边界与扩展边界无歧义

## Epic 1. 核心扩展契约

- [ ] 1.1 在 `app/code/Weline/Websites/extends.php` 定义 `AiSiteBuilderProvider`
- [ ] 1.2 在 `app/code/Weline/Websites/extends.php` 定义 `WebsiteThemeSource`
- [ ] 1.3 新增 `Api/AiSiteBuilderProviderInterface.php`
- [ ] 1.4 新增 `Api/WebsiteThemeSourceInterface.php`
- [ ] 1.5 新增 provider registry 接口与实现
- [ ] 1.6 新增 theme source registry 接口与实现
- [ ] 1.7 为 registry 写单元测试

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ProviderRegistryTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ThemeSourceRegistryTest.php`

目标文件：

- `app/code/Weline/Websites/extends.php`
- `app/code/Weline/Websites/Api/*.php`
- `app/code/Weline/Websites/Service/AiWorkbench/ProviderRegistry.php`
- `app/code/Weline/Websites/Service/AiWorkbench/ThemeSourceRegistry.php`

完成标准：

- registry 可发现 `websites_default`
- registry 可发现 Theme source

## Epic 2. 核心持久化模型

- [ ] 2.1 新增 `AiSiteBuilderSession` 模型
- [ ] 2.2 新增 `AiSiteBuilderMessage` 模型
- [ ] 2.3 新增 `AiSiteBuilderArtifact` 模型
- [ ] 2.4 新增 `AiSiteBuilderEvent` 模型
- [ ] 2.5 新增 repository/service 封装
- [ ] 2.6 为 session service 写单元测试
- [ ] 2.7 为 message service 写单元测试
- [ ] 2.8 为 artifact service 写单元测试
- [ ] 2.9 为 event service 写单元测试

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/SessionServiceTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/MessageServiceTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/ArtifactServiceTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/EventStreamServiceTest.php`

目标文件：

- `app/code/Weline/Websites/Model/AiSiteBuilderSession.php`
- `app/code/Weline/Websites/Model/AiSiteBuilderMessage.php`
- `app/code/Weline/Websites/Model/AiSiteBuilderArtifact.php`
- `app/code/Weline/Websites/Model/AiSiteBuilderEvent.php`
- `app/code/Weline/Websites/Service/AiWorkbench/*.php`

完成标准：

- `setup:upgrade` 后模型可正常工作
- session/message/event/artifact 可独立读写

## Epic 3. 工作台控制器与 UI 壳

- [ ] 3.1 将 `SiteBuilderAgent` 升级为工作台入口控制器
- [ ] 3.2 增加 create-session API
- [ ] 3.3 增加 state-json API
- [ ] 3.4 增加 stream-sse API
- [ ] 3.5 新建工作台 `workspace.phtml`
- [ ] 3.6 建立 provider 标签/切换 UI
- [ ] 3.7 建立消息面板、顶部状态条、阶段条、右侧草稿区骨架
- [ ] 3.8 为控制器 API 写单元测试
- [ ] 3.9 用 `http:request` 做路由冒烟验证

测试先行：

- `app/code/Weline/Websites/Test/Unit/Controller/Backend/SiteBuilderAgentIndexTest.php`
- `app/code/Weline/Websites/Test/Unit/Controller/Backend/SiteBuilderAgentApiTest.php`

目标文件：

- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index.phtml`
- `app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml`
- `app/code/Weline/Websites/etc/backend/menu.xml`

完成标准：

- 后台可以创建并打开 session
- 刷新页面后会话仍可恢复

## Epic 4. 默认 provider：聊天确认站点画像

- [ ] 4.1 新增 `WebsitesDefaultProvider`
- [ ] 4.2 新增 conversation 能力接口实现
- [ ] 4.3 新增 post-message API
- [ ] 4.4 把聊天结果标准化为 `site_profile` artifact
- [ ] 4.5 支持 AI 建议输出标题、目标群体、品牌语气、页面建议
- [ ] 4.6 支持用户手工修正并回写 scope/artifact
- [ ] 4.7 补单元测试

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Provider/WebsitesDefaultProviderTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Conversation/WebsitesConversationTest.php`

目标文件：

- `app/code/Weline/Websites/Service/AiWorkbench/Provider/WebsitesDefaultProvider.php`
- `app/code/Weline/Websites/Service/AiWorkbench/Conversation/*.php`

完成标准：

- 用户发送消息后会话生成结构化画像
- 阶段推进到 `domain_candidates`

## Epic 5. 默认 provider：域名推荐、购买与生命周期

- [ ] 5.1 抽离默认 provider 的 domain workflow
- [ ] 5.2 复用 Websites 现有域名账户与可用性检查能力
- [ ] 5.3 增加 `post-select-domain`
- [ ] 5.4 增加 `post-confirm-domain-purchase`
- [ ] 5.5 增加 `domain-status-json`
- [ ] 5.6 新增 `DomainLifecycleBridgeService`
- [ ] 5.7 顶部状态条消费 domain lifecycle snapshot
- [ ] 5.8 单元测试覆盖显式确认门槛
- [ ] 5.9 单元测试覆盖 lifecycle snapshot 到 event 的转换

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Domain/DomainWorkflowTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Domain/DomainLifecycleBridgeServiceTest.php`

目标文件：

- `app/code/Weline/Websites/Service/AiWorkbench/Domain/*.php`
- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`

完成标准：

- 未确认前不触发购买
- 确认后可进入 `domain_processing`
- 顶部状态条能显示 Websites 域名处理流转

## Epic 6. Theme source 接入

- [ ] 6.1 定义 `WebsiteThemeSourceInterface`
- [ ] 6.2 `Weline_Theme` 实现默认 theme source
- [ ] 6.3 `ThemeQueryProvider` 增加列主题和主题详情查询
- [ ] 6.4 如果现有控件不够，新增 Theme 选择组件
- [ ] 6.5 工作台增加 theme source 与 theme candidate 选择
- [ ] 6.6 单元测试覆盖 Theme source registry 和 Theme source 实现

测试先行：

- `app/code/Weline/Theme/Test/Unit/Extends/Weline_Websites/WelineThemeSourceTest.php`
- `app/code/Weline/Theme/Test/Unit/Extends/Weline_Framework/Query/ThemeQueryProviderWorkbenchTest.php`

目标文件：

- `app/code/Weline/Theme/extends/module/Weline_Websites/WebsiteThemeSource/WelineThemeSource.php`
- `app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php`
- `app/code/Weline/Theme/Taglib/ThemeSelect.php` 或复用现有 `SearchSelect`

完成标准：

- 工作台可选择主题源与主题候选
- 默认 provider 不直接扫描 Theme 内部细节

## Epic 7. 页面类型与草稿生成

- [ ] 7.1 约定 Theme 布局元数据到 page type 的映射
- [ ] 7.2 工作台列出可选页面类型
- [ ] 7.3 新增 `post-generate-drafts`
- [ ] 7.4 page draft 持久化为 artifact
- [ ] 7.5 草稿生成支持额外 prompt
- [ ] 7.6 增加 `post-update-draft`
- [ ] 7.7 单元测试覆盖页面类型选择与草稿生成

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Draft/PageTypeResolverTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Draft/DraftWorkflowTest.php`

目标文件：

- `app/code/Weline/Websites/Service/AiWorkbench/Draft/*.php`
- `app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php`

完成标准：

- 用户能选择页面类型
- 生成的每个页面有独立 artifact

## Epic 8. 网站物料化与预览

- [ ] 8.1 抽象 materializer
- [ ] 8.2 默认 provider 通过 Theme source 完成主题物料化
- [ ] 8.3 调用 Websites 创建 Website 并绑定域名
- [ ] 8.4 生成 preview descriptor
- [ ] 8.5 工作台 UI 增加“预览网站”入口
- [ ] 8.6 单元测试覆盖物料化成功/失败路径

测试先行：

- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Materialize/MaterializerTest.php`
- `app/code/Weline/Websites/Test/Unit/Service/AiWorkbench/Preview/PreviewResolverTest.php`

目标文件：

- `app/code/Weline/Websites/Service/AiWorkbench/Materialize/*.php`
- `app/code/Weline/Websites/Service/AiWorkbench/Preview/*.php`

完成标准：

- 工作台可创建 Website 并预览

## Epic 9. PageBuilder provider 接入

- [ ] 9.1 新建 `PageBuilderProvider`
- [ ] 9.2 将现有 `AiSiteAgentSessionService` 所需能力适配到平台 session/event/artifact
- [ ] 9.3 抽离 PageBuilder 自己的对话、草稿、预览、物料化实现
- [ ] 9.4 `PageBuilder` 菜单指向 `Websites` 工作台并带 `provider=pagebuilder`
- [ ] 9.5 旧 `AiSiteAgent` 控制器改为兼容跳转或维护模式
- [ ] 9.6 单元测试覆盖 `PageBuilderProvider`

测试先行：

- `app/code/GuoLaiRen/PageBuilder/Test/Unit/Extends/Weline_Websites/PageBuilderProviderTest.php`

目标文件：

- `app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php`
- `app/code/GuoLaiRen/PageBuilder/etc/backend/menu.xml`
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php`

完成标准：

- `provider=pagebuilder` 能走 PageBuilder 自己的流程
- `Weline_Websites` 内部没有 PageBuilder 私有字段判断

## Epic 10. E2E 与回归验证

- [ ] 10.1 新增 Websites 默认 provider e2e
- [ ] 10.2 新增 domain flow e2e
- [ ] 10.3 新增 theme/page type/draft e2e
- [ ] 10.4 新增 preview e2e
- [ ] 10.5 新增 PageBuilder provider e2e
- [ ] 10.6 配置 fake AI responder
- [ ] 10.7 配置 fake registrar / fake lifecycle
- [ ] 10.8 写 `http:request` 验证脚本清单

建议 e2e 文件：

- `app/code/Weline/Websites/test/e2e/backend/ai-workbench-default.spec.js`
- `app/code/Weline/Websites/test/e2e/backend/ai-workbench-domain-flow.spec.js`
- `app/code/Weline/Websites/test/e2e/backend/ai-workbench-theme-page-flow.spec.js`
- `app/code/Weline/Websites/test/e2e/backend/ai-workbench-preview.spec.js`
- `app/code/GuoLaiRen/PageBuilder/test/e2e/backend/ai-workbench-pagebuilder-provider.spec.js`

完成标准：

- 所有 e2e 都使用 fake 外部依赖
- 主路径自动化可回归

## Epic 11. 文档与收尾

- [ ] 11.1 更新 `Weline_Websites/extends.md`
- [ ] 11.2 更新 Theme 主题源接入文档
- [ ] 11.3 更新 PageBuilder provider 接入文档
- [ ] 11.4 记录兼容跳转与旧入口处置策略
- [ ] 11.5 记录测试命令与调试说明

完成标准：

- 新 provider 和 theme source 的接入方式可被第三方模块复用
