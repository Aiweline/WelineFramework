# Weline_Ai 模块文档中心

## 📚 文档导航

### 🚀 快速开始
- [快速开始指南](./开发/快速开始.md) - 5分钟快速上手
- [README](../README.md) - 模块概览

### 👨‍💻 开发文档
- [开发文档索引](./开发/README.md) - 开发文档总览
- [API文档](./开发/API文档.md) - REST API 完整文档
- [AI模块开发文档](./开发/AI模块开发文档.md) - 核心开发指南
- [后台控制器开发规范](./开发/后台控制器开发规范.md) - 控制器开发标准
- [单元测试指南](./开发/单元测试指南.md) - 如何编写单元测试
- [Playwright测试指南](./开发/Playwright测试指南.md) - 浏览器自动化测试

### 👤 用户文档
- [用户指南](./用户/用户指南.md) - 用户使用手册
- [AI模块使用手册](./用户/AI模块使用手册.md) - 详细使用说明

### 🧪 测试文档
- [测试报告](./测试/测试报告.md) - 最新测试结果
- [测试框架说明](./测试/测试框架说明.md) - 测试环境配置
- 其他测试报告请查看 `测试/` 目录

### 📊 项目管理
项目相关文档请查看 `项目/` 目录：
- AI计划
- 开发交付计划
- 检查清单
- 系统交付文档

### 🔌 API参考
- [API文档](./API/API文档.md) - API接口完整说明

## 🎯 模块简介

`Weline_Ai` 是一个企业级AI模型管理和服务平台，提供：

### 核心功能
1. **AI 管理** - 模型 | 适配器 | 供应商账户 聚合管理
2. **默认模型** - 在后台 AI 中心配置各服务类型的全局默认模型
3. **场景配置** - 场景适配器管理
4. **模型管理** - 多供应商、多模型统一管理
5. **REST API** - Chat、Model、API Key 等接口

### 后台入口

登录后台后进入“应用与扩展 → AI 中心 → 默认模型”，即可配置文本、图像、翻译、代码等服务类型的全局默认模型。

### 前台聊天的模型与失败提示

`/ai/frontend/chat` 仅展示并使用已启用的文本模型。未指定模型时，运行时先解析场景/全局默认模型；
若未设置全局默认模型，则稳定选择一个已启用的文本模型，而不会回退到历史硬编码模型。
供应商连接、余额或模型配置不可用时，聊天任务会以可读的 `ai_generation_failed` 结果结束，提示用户检查所选模型与供应商连接；
不会把这类配置问题笼统显示为 Runner 崩溃。

### 后台模型选择 QueryProvider 契约

默认模型选择器通过 bin-query 调用 `ai.listModels`，该 operation 仅向已登录后台会话开放，
不会回退到 Controller URL 或原生浏览器请求。浏览器调用前先加载完整 API 模块：

```javascript
const api = await Weline.load('api');
const models = await api.resource('ai').listModels({});
```

可选参数 `primary_modality`（兼容别名 `modality`）用于按主要模态过滤。返回项的稳定模型标识字段是
`model_code`，并包含 `name`、`supplier`、`primary_modality`、`capabilities`、`is_active` 与
`is_default`；选择器不得依赖历史字段 `code` 或必定存在的 `version`。

### 技术栈
- **框架**: WelineFramework
- **语言**: PHP 8.4+
- **数据库**: SQLite / MySQL
- **测试**: PHPUnit 9.6

## PHP 公开契约

其他模块的可选 AI 集成只能引用 `Weline\Ai\Api\*`。Agent、Model、Result、
Scenario/Skill/Style 能力和翻译服务均在该命名空间发布。
`Api\AiModel` 是不可变的模型快照，`Api\AgentResult` 是纯结果对象；它们不继承 ORM Model，
也不使用 `class_alias` 把内部实现暴露给调用方。
`Weline\Ai\Api\Configuration\ScenarioConfigurationInterface` 是通用场景控制面边界：可读取场景、
按 modality 查询不可变模型快照、校验 Provider 可用性、聚合 request-prefix usage，并原子更新场景模型绑定。
内部 `AiModel`、`AiScenarioAdapter`、`AdapterScanner`、`AccountService` 与 `UsageRecord` 不得泄露到调用模块。
公开 `Api\AiModel` 提供 modality、active/default、token 上限与价格的只读访问器；已有构造和数组数据格式保持兼容。
图片生成和模型能力探测通过 `Weline\Ai\Api\Image\ImageRuntimeInterface` 发布；可选模块不得直接注入 `AiService`。
`Weline\Ai\Api\Image\TextToImageScenarioBindingInterface` 是文生图场景的控制面命令边界：
调用方只提交 data-only `TextToImageScenarioBindingRequest`，并获取标量模型代码或
`TextToImageScenarioBindingResult`。默认图像模型、模态/供应商筛选、后台配置可用性、供应商账号修复、
场景 ORM 和幂等绑定写入全部由 Ai 内部实现所有。调用模块不得自行组装
`AiDefaultModel`、`AiModel`、`AiScenarioAdapter`、`ConfigResolver` 或 `DefaultModelManager`。
Agent 扩展需要按既定 `AiModel` 直接调用供应商时，通过
`AgentModelExecutorInterface` 执行；需要自行组织供应商会话时只使用
`Provider\ProviderRuntimeInterface` 及其返回的公开 Session 契约。供应商选择、ORM 模型还原、
流式回调适配和 `ProviderFactory` 均留在 Ai 模块内部，扩展模块不得直接触达。
Agent 执行器向扩展注入的公开会话键固定为 `provider_runtime`；不再提供或读取
`provider_factory` 别名，避免把内部 ORM ProviderFactory 边界重新泄露给扩展模块。
`Weline\Ai\Api\TranslationService` 是一版迁移桥，与旧
`Weline\Ai\Service\TranslationService` 保持同一运行时类、构造参数和策略常量；
新调用方不得再直接引用 Ai 的 `Service`/`Model` 内部命名空间。
文本生成与 Agent 执行统一注入 `Weline\Ai\Api\AiRuntimeInterface`；同名 Factory 将 DI 请求解析到
模块 Provider，调用方不得自行定位 `AiService`。Skill/Style 目录、场景 Agent 目录和样式快照
分别通过 `AiRuntimeInterface` 与 `StyleRuntimeInterface` 查询，调用方不得跨模块实例化
Resolver、Registry、Service 或 ORM Model。
跨模块加密结构化配置使用 `Weline\Ai\Api\SecretStoreInterface`；同名 Factory 与编译 Provider
均解析到 Ai 内部现有 SecretStore 实现，密文格式、主密钥派生和轮换逻辑不会暴露给调用模块。
可选 I18n 集成的默认 locale 规范化通过 `Weline\I18n\Api\Localization\LocaleRepositoryInterface`
解析，不直接调用 I18n 的综合 Model。

## 📝 文档贡献

如需添加或更新文档，请遵循以下规范：

1. **文档命名**: 使用描述性的中文名称
2. **文档结构**: 包含标题、目录、正文、相关链接
3. **文档格式**: 使用 Markdown 格式
4. **文档位置**: 按类别放到对应目录

## 🔗 相关链接

- [框架文档](../../../Framework/doc/)
- [开发文档总览](../../../../docs/开发文档.md)
- [文档整理方案](../../../../docs/文档整理方案.md)

## 📅 更新记录

- **2026-07-13**: 前台聊天仅选择已启用文本模型；未配置全局默认模型时使用稳定的活动模型回退，并将供应商失败作为可操作的 AI 生成失败反馈。
- **2026-07-12**: 依赖清单以 `etc/module.php` 为准；后台管理入口显式依赖 `Weline_Admin`、`Weline_Backend` 与 `Weline_Framework`，I18n 集成为可选依赖。
- **2025-10-26**: 创建文档中心，规范文档结构
- **2025-01-26**: 模块开发完成，通过测试

---

**维护者**: WelineFramework Team  
**最后更新**: 2025-10-26
