<!-- Sync Impact Report -->
<!-- Version change: 2.4.0 → 2.5.0 -->
<!-- Modified principles: -->
<!-- - Added XVII. 禁止 Magento 写法与开发文档学习要求 -->
<!-- - Strengthened XI. 框架学习要求，明确禁止 Magento 写法，要求自学并更新开发文档 -->
<!-- - 强化所有开发必须基于 WelineFramework 开发文档，缺失时自学并更新文档 -->
<!-- Added sections: Anti-Magento Pattern, Development Documentation Learning Requirement -->
<!-- Templates requiring updates: ✅ .specify/templates/plan-template.md (updated version refs) / ⚠ .specify/templates/spec-template.md / ⚠ .specify/templates/tasks-template.md -->
<!-- Follow-up TODOs: -->
<!-- - TODO(DEV_DOC_UPDATE): review and update framework dev docs with Offcanvas examples -->
<!-- - TODO(DOC_HTTP_REQUEST): add php bin/w http:request usage examples to quickstart/tests -->
<!-- - TODO(DOC_SYNC): add doc-update checklist to CONTRIBUTING.md -->
<!-- - TODO(SCOPE_NOTICE): implement PR-time detection of out-of-scope changes and require approver -->
<!-- - TODO(ANTI_MAGENTO): add Magento pattern detection to code review checklist -->

# WelineFramework AI模块开发宪法

## 核心原则

### I. 框架一致性 (Framework Compliance)
所有开发必须严格遵循WelineFramework框架规范和现有模块模式。禁止基于个人经验或外部框架模式进行开发。每个功能实现前必须：
- 阅读相关框架开发文档
- 研究现有模块的实现模式
- 遵循框架的MVC架构设计
- 使用框架提供的ORM和工具类
- 保持与现有代码风格的一致性

### II. 测试驱动开发 (Test-Driven Development - NON-NEGOTIABLE)
所有功能必须通过测试验证，采用严格的TDD流程：
- 先编写测试用例，确保测试失败
- 实现功能使测试通过
- 重构代码保持测试通过
- 测试必须覆盖主要功能流程、用户界面可用性、数据一致性、错误处理机制
- 功能测试、界面测试、数据测试、错误处理测试缺一不可

### III. 模块化设计 (Modular Architecture)
AI模块必须采用WelineFramework的模块化设计原则：
- 每个组件独立可测试
- 清晰的模块分离和依赖管理
- 遵循框架的目录结构和命名规范
- 支持模块的独立部署和维护
- 与其他模块的松耦合集成

### IV. 多租户数据隔离 (Multi-Tenant Data Isolation)
企业级多租户支持是核心要求：
- 所有数据查询必须包含租户ID过滤
- 租户级别的配置和权限管理
- 数据完全隔离，确保安全性
- 支持租户级别的资源配额管理
- 实现租户上下文中间件

### V. 国际化支持 (Internationalization Support)
完整的多语言支持：
- 依赖I18n模块的现有数据结构
- 支持多语言界面和内容
- 实现动态语言切换
- 提供多语言API响应
- 支持API版本的语言本地化

### VI. 安全与合规 (Security & Compliance)
企业级安全要求：
- API密钥和敏感信息加密存储
- 输入输出审计与内容安全检查
- 基于角色的权限控制
- 完整的操作审计日志
- 数据保护法规遵循

### VII. 性能与可扩展性 (Performance & Scalability)
高性能和可扩展架构：
- 支持1000+并发请求，响应时间<200ms
- 多级缓存策略
- 异步队列处理
- 负载均衡支持
- 支持水平扩展

### VIII. 测试组织规范 (Test Organization Standards)
所有测试文件必须按照模块化原则组织：
- 测试文件必须写在对应模块的test目录内
- 单元测试必须放在模块的tests/unit/目录下
- 集成测试必须放在模块的tests/integration/目录下
- 合约测试必须放在模块的tests/contract/目录下
- 测试文件命名必须遵循test_*.php格式
- 每个模块的测试必须独立可运行
- 测试目录结构必须与源码目录结构保持一致
- 运行单元测试命令参考：`php bin/w phpunit:run -h`

### IX. PHP语言合规性 (PHP Language Compliance - NON-NEGOTIABLE)
必须严格按照PHP 8.2以上语法开发，严格遵守PHP语言特性：
- 必须使用PHP 8.2+的严格类型声明 (declare(strict_types=1))
- 继承接口或抽象类必须实现所有必需方法
- 必须正确实现抽象方法的签名和返回类型
- 必须使用PHP 8.2+的新特性（如readonly属性、枚举等）
- 禁止使用已废弃的PHP语法和函数
- 所有类必须正确实现其继承的接口或抽象类
- 方法签名必须与父类或接口完全匹配
- 必须处理所有可能的异常情况

### X. ORM使用规范 (ORM Usage Standards - NON-NEGOTIABLE)
禁止在ORM使用时自己揣测函数，必须严格遵循框架规范：
- 禁止基于个人经验或外部框架（如Magento）进行ORM操作
- 必须深入学习WelineFramework的ORM实现和API文档
- 所有ORM操作必须基于框架提供的实际方法
- 禁止使用未在框架文档中明确说明的方法
- 必须通过阅读源码和文档确认ORM方法的正确用法
- 遇到不确定的ORM操作时必须查阅框架源码和文档
- 禁止参考Magento或其他框架的ORM模式
- 必须使用框架自研的ORM特性和方法

### XI. 框架学习要求 (Framework Learning Requirements - NON-NEGOTIABLE)
这是自研框架，必须深入学习框架本身而非外部参考：
- **MUST**: 禁止参考Magento或其他外部框架的结构和模式
- **MUST**: 必须深入学习WelineFramework的源码和架构设计
- **MUST**: 所有开发必须基于对WelineFramework框架的深入理解
- **MUST**: 必须阅读框架的开发文档和API文档
- **MUST**: 必须研究现有模块的实现模式和最佳实践
- **MUST**: 禁止基于外部框架经验进行开发决策
- **MUST**: 必须通过框架源码学习正确的实现方式
- **MUST**: 所有功能实现必须符合WelineFramework的设计理念
- **MUST**: 当开发文档缺失时，必须通过自学（阅读源码、现有模块示例）掌握正确写法
- **MUST**: 自学完成后，必须在PR中记录学习要点并询问是否更新到开发文档

### XIV. 架构与数据流验证 (Architecture & Data-flow Validation)

在开始任何开发工作前，必须验证架构逻辑、数据流与字段定义能满足规格中列明的功能需求。

- **MUST**: 在进入实现阶段前（Phase 1 设计完成后），团队必须证明关键路径的架构与数据字段覆盖所有功能需求（例如：租户ID、origin_model_id、is_copy 等字段的存在与约束）。
- **MUST**: 任何新增的数据字段或架构调整必须在 `data-model.md` 中记录，并由设计审核通过后才能进入实现。
- **SHOULD**: 使用简单的数据流图或表格在 `research.md` 中描述端到端数据流与关键字段，以便在代码实现前验证一致性。

Rationale: 提前验证架构与数据流可以显著减少实现阶段的返工与数据一致性问题，确保开发与测试能直接对齐验收条件。

### XV. 变更范围限制 (Change Scope Constraint)

为降低大范围影响和意外变更的风险，本次特性相关的代码修改**禁止超出** `app\code\Weline\Ai` 目录范围，除非在设计评审中获得明确批准并记录在 `research.md` 中。

- **MUST**: 所有 PR 的变更集默认应仅包含 `app\code\Weline\Ai` 目录内的文件。
- **MUST**: 若确需修改其他目录（例如共享库或 infra 配置），开发团队必须在设计阶段提交变更影响分析并由技术负责人批准，批准记录需附在 PR 描述中。
- **SHOULD**: CI/PR 模板需自动检测超出目录的改动并将 PR 标为需额外审批。

Rationale: 限定初始变更范围可以防止大范围非预期影响，并使代码审查集中于模块边界和兼容性。

### XVI. 已实现功能兼容性 (Existing Feature Compatibility)

当新的宪法条款引入更严格的实现或流程要求时，必须优先保证已存在且运行中的功能继续可用与兼容。

- **MUST**: 在修改现有功能或引入新约束前，进行兼容性评估并在 `research.md` 中记录回归风险与迁移步骤。
- **MUST**: 对已实现功能的适配变更必须提供回退方案，以便出现兼容性问题时能迅速恢复服务。
- **SHOULD**: 对生产中已存在的关键路径功能进行额外的回归测试，确保新变更不会破坏当前行为。

Rationale: 保证对现有用户服务不中断是首要责任，宪法要求应引导但不破坏当前稳定运行的功能。

### XII. 编辑与新建（Offcanvas 编辑流）
编辑与新建交互必须采用框架统一的 Offcanvas（侧出式）编辑流以保证一致的用户体验与可复用组件。

- **MUST**: 在实现任何后台或前端的“新建/编辑”界面时，优先使用框架提供的 Offcanvas 组件或官方推荐的实现模式。
- **MUST**: 实施前必须查阅框架内的开发文档中关于 Offcanvas/侧出式组件的使用说明与示例。
- **MUST**: 若开发文档中未包含 Offcanvas 使用说明，开发者必须通过阅读源码、现有模块示例或相关 view/templates 自学并记录学习要点（简短文档或 PR 描述中的学习摘要）。
- **MUST**: 完成首个 Offcanvas 实现后，开发者须在 PR 中提交学习摘要并在 PR/Issue 中显式询问：是否将该示例与使用指南合并回框架开发文档（"是否更新到开发文档"）。
- **SHOULD**: Offcanvas 实现必须满足可访问性要求（键盘导航、焦点管理、屏幕阅读器标签）。

- **MUST**: 对于与模型相关的所有交互（新建 / 编辑 / 拷贝），UI 必须统一使用 Offcanvas 编辑流实现，保证交互一致性与行为可预测性。

Rationale: 统一的编辑/新建交互有助于降低维护成本、提升用户一致性并避免重复实现。将学习过程纳入开发流程并在 PR 中触发文档更新请求，能确保知识沉淀到框架层并被后续开发复用。

### XIII. 快速 E2E 测试指引（HTTP 请求测试建议）

在给定路径或 API endpoint 时，鼓励使用框架内置或推荐的 `php bin/w http:request` 命令/工具进行快速端到端测试。

- **SHOULD**: 在编写集成测试或手动验证 API 时，使用 `php bin/w http:request`（或等效工具）发起请求并验收响应状态码、响应体和头部信息。
- **MUST**: 在测试敏感操作（例如：修改/删除资源）时，确保使用测试环境或隔离租户，并清理测试数据。
- **SHOULD**: 将常用的 `php bin/w http:request` 示例放入 `quickstart.md` 或 `tests/` 示例文件中，供开发者快速复制运行。

- **MUST**: 开发完成且本地/CI 测试通过后，必须使用 `php bin/w  http:request` 或等效工具对相关路径执行端到端验证，验证返回内容符合规格（状态码、响应结构、关键字段）。验证通过后方可继续部署或更新文档。

Rationale: 明确的 E2E 测试建议能加速开发验证，同时降低误用生产资源的风险。

### XVII. 禁止 Magento 写法与开发文档学习要求 (Anti-Magento Pattern & Documentation Learning - NON-NEGOTIABLE)

**绝对禁止使用 Magento 框架的任何写法和模式**，所有开发必须严格遵循 WelineFramework 开发文档：

- **MUST**: 绝对禁止使用 Magento 的 module.xml、registration.php、di.xml 等配置文件写法
- **MUST**: 绝对禁止使用 Magento 的 Setup/InstallSchema.php、Setup/UpgradeSchema.php 等数据库迁移写法
- **MUST**: 绝对禁止使用 Magento 的 Model、ResourceModel、Collection 等 ORM 写法
- **MUST**: 绝对禁止使用 Magento 的 Controller、Block、Helper 等 MVC 写法
- **MUST**: 绝对禁止使用 Magento 的 layout.xml、phtml 等视图模板写法
- **MUST**: 任何写法必须严格依照 WelineFramework 开发文档
- **MUST**: 当开发文档缺失时，必须通过自学（阅读 WelineFramework 源码、现有模块示例）掌握正确写法
- **MUST**: 自学完成后，必须在 PR 中记录学习要点并询问："是否更新到开发文档？"
- **MUST**: 所有代码审查必须检查是否包含 Magento 模式，发现即拒绝
- **MUST**: 在实现任何功能前，必须先查阅 WelineFramework 开发文档或通过自学掌握正确写法

**Rationale**: WelineFramework 是自研框架，与 Magento 完全不同。使用 Magento 写法会导致架构不兼容、功能异常、维护困难。必须通过严格的学习和文档更新机制确保所有开发都基于正确的框架模式。

## WelineFramework开发标准

### 代码规范
- 遵循PSR-4自动加载规范
- 使用框架提供的ORM链式操作（禁止揣测函数）
- 遵循框架的命名约定
- 使用框架的异常处理机制
- 保持代码注释的完整性
- 必须使用PHP 8.2+语法特性
- 所有类必须正确实现继承的接口或抽象类
- 必须使用严格类型声明
- 禁止使用已废弃的PHP语法
- **绝对禁止使用 Magento 或其他外部框架的任何写法和模式**
- 必须深入学习WelineFramework的ORM实现
- 所有写法必须严格依照WelineFramework开发文档
- 开发文档缺失时必须自学并更新文档

### 目录结构规范
```
app/code/Weline/Ai/
├── Controller/          # 控制器层
│   ├── Backend/        # 后台管理
│   ├── Frontend/       # 前端用户
│   └── Api/            # API接口
├── Model/              # 数据模型层
├── Service/            # 服务层
├── Adapter/            # 场景适配器
├── Helper/             # 辅助类
├── Cache/              # 缓存层
├── Queue/              # 队列系统
├── Event/              # 事件系统
├── Middleware/         # 中间件
├── Setup/              # 安装脚本
├── tests/              # 测试文件
│   ├── unit/           # 单元测试
│   ├── integration/    # 集成测试
│   └── contract/       # 合约测试
└── view/templates/     # 视图模板
```

### 数据库设计规范
- 使用框架的ORM进行数据库操作（禁止揣测函数）
- 遵循框架的表命名约定
- 实现完整的索引设计
- 支持数据库迁移
- 实现数据验证规则
- 必须深入学习WelineFramework的ORM API
- 禁止参考Magento或其他外部框架的数据库模式

### API设计规范
- 遵循RESTful API设计原则
- 实现API版本管理
- 提供完整的API文档
- 支持流式响应
- 实现统一的错误处理

## AI模块特定要求

### 模型管理
- 自动收集和注册AI模型
- 支持模型版本控制
- 实现模型保护机制
- 支持模型A/B测试
- 提供模型性能监控

### 场景适配器
- 自动扫描和注册适配器
- 支持场景专用优化
- 提供适配器描述管理
- 实现适配器保护机制
- 支持动态适配器加载

### 服务模式
- 支持双模式：API接口和PHP静态方法
- 实现统一的AI服务接口
- 支持模型自动选择
- 提供流式响应支持
- 实现服务监控

### 多租户支持
- 租户数据完全隔离
- 租户级别的配置管理
- 资源配额控制
- 计费系统集成
- 权限管理

### 国际化支持
- 依赖I18n模块
- 多语言界面支持
- 内容国际化
- API语言本地化
- 动态语言切换

## 开发工作流

### 代码审查要求
- 所有代码必须通过框架规范检查
- 必须通过测试用例验证
- 必须遵循安全最佳实践
- 必须提供完整的文档
- 必须通过性能测试
- 必须验证ORM操作的正确性（禁止揣测函数）
- 必须确认所有方法都基于WelineFramework的实际API

### 质量门禁
- 测试覆盖率必须达到90%以上
- 代码重复率必须低于5%
- 性能指标必须满足要求
- 安全扫描必须通过
- 文档必须完整
- PHP 8.2+语法合规性检查必须通过
- 接口和抽象类实现完整性检查必须通过
- 严格类型声明检查必须通过
- ORM方法使用正确性检查必须通过（禁止揣测函数）
- WelineFramework API合规性检查必须通过
- **Magento 模式检测必须通过（发现即拒绝）**
- **WelineFramework 开发文档合规性检查必须通过**

### 部署要求
- 支持自动化部署
- 实现版本回滚机制
- 提供健康检查
- 实现监控告警
- 支持灰度发布

## 治理

本宪法是AI模块开发的最高指导原则，所有开发活动必须严格遵循。任何违反宪法的行为都将被拒绝。

**宪法修改程序**：
- 重大原则修改需要团队讨论和批准
- 技术细节调整需要技术负责人批准
- 所有修改必须记录变更原因和影响范围
- 修改后的宪法必须重新审查所有相关文档

**合规检查**：
- 每个PR必须验证宪法合规性
- 代码审查必须检查框架一致性
- 测试必须验证功能完整性
- 部署前必须进行安全检查

**Version**: 2.5.0 | **Ratified**: 2024-12-19 | **Last Amended**: 2025-10-09