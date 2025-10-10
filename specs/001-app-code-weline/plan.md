
# Implementation Plan: Weline_Ai Module

**Branch**: `001-app-code-weline` | **Date**: 2025-10-09 | **Spec**: `spec.md`
**Input**: Feature specification from `/specs/001-app-code-weline/spec.md`

## Execution Flow (/plan command scope)
```
1. Load feature spec from Input path
   → If not found: ERROR "No feature spec at {path}"
2. Fill Technical Context (scan for NEEDS CLARIFICATION)
   → Detect Project Type from file system structure or context (web=frontend+backend, mobile=app+api)
   → Set Structure Decision based on project type
3. Fill the Constitution Check section based on the content of the constitution document.
4. Evaluate Constitution Check section below
   → If violations exist: Document in Complexity Tracking
   → If no justification possible: ERROR "Simplify approach first"
   → Update Progress Tracking: Initial Constitution Check
5. Execute Phase 0 → research.md
   → If NEEDS CLARIFICATION remain: ERROR "Resolve unknowns"
6. Execute Phase 1 → contracts, data-model.md, quickstart.md, agent-specific template file (e.g., `CLAUDE.md` for Claude Code, `.github/copilot-instructions.md` for GitHub Copilot, `GEMINI.md` for Gemini CLI, `QWEN.md` for Qwen Code or `AGENTS.md` for opencode).
7. Re-evaluate Constitution Check section
   → If new violations: Refactor design, return to Phase 1
   → Update Progress Tracking: Post-Design Constitution Check
8. Plan Phase 2 → Describe task generation approach (DO NOT create tasks.md)
9. STOP - Ready for /tasks command
```

**IMPORTANT**: The /plan command STOPS at step 7. Phases 2-4 are executed by other commands:
- Phase 2: /tasks command creates tasks.md
- Phase 3-4: Implementation execution (manual or via tools)

## Summary
实现 WelineFramework AI 模块，提供统一的 AI 模型管理、助手工具、API 访问控制、多租户隔离、国际化支持和监控功能。采用 TDD 开发方式，严格遵循 WelineFramework 框架规范，禁止使用 Magento 等外部框架模式。基于已澄清的技术决策，使用 WelineFramework 内置 SecretStore、观察者模式进行场景适配器管理、支付宝微信支付集成、短信钉钉飞书告警通知。

## Technical Context
**Language/Version**: PHP 8.2+  
**Primary Dependencies**: WelineFramework internal modules, Redis (cache), Queue (e.g., RabbitMQ)  
**Storage**: relational DB (MySQL/SQLite) per existing project conventions  
**Testing**: PHPUnit (`php bin/w phpunit:run`)  
**Target Platform**: Linux / PHP-FPM  
**Project Type**: web (backend API + admin interface)  
**Performance Goals**: P95 <= 3s for typical text generation requests, P99 <= 5s, 1000+ concurrent users  
**Constraints**: Must not modify files outside `app/code/Weline/Ai` without approval, 数据聚合延迟 ≤ 10分钟  
**Scale/Scope**: Multi-tenant SaaS with 1000+ concurrent users, 50+ AI models, 10+ API endpoints

## Constitution Check
*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

**Constitution v2.5.0 Compliance Gates**:

✅ **I. 框架一致性**: 严格遵循 WelineFramework 规范，禁止外部框架模式  
✅ **II. 测试驱动开发**: TDD 流程，测试先行，覆盖功能/界面/数据/错误处理  
✅ **III. 模块化设计**: 独立可测试组件，清晰依赖管理  
✅ **IV. 多租户数据隔离**: 租户ID过滤，数据完全隔离  
✅ **V. 国际化支持**: 依赖 I18n 模块，多语言界面和API  
✅ **VI. 安全与合规**: API密钥加密存储，审计日志，权限控制  
✅ **VII. 性能与可扩展性**: 1000+并发，<200ms响应，多级缓存  
✅ **VIII. 测试组织规范**: 模块化测试结构，test_*.php 命名  
✅ **IX. PHP语言合规性**: PHP 8.2+严格类型，接口实现完整性  
✅ **X. ORM使用规范**: 禁止揣测函数，严格遵循框架ORM API  
✅ **XI. 框架学习要求**: 禁止 Magento 模式，自学并更新文档  
✅ **XII. Offcanvas编辑流**: 统一编辑/新建交互，立即打开编辑器  
✅ **XIII. HTTP请求测试**: 使用 `php bin/w http:request` 进行E2E验证  
✅ **XIV. 架构与数据流验证**: 验证关键字段覆盖功能需求  
✅ **XV. 变更范围限制**: 禁止超出 `app/code/Weline/Ai` 目录  
✅ **XVI. 已实现功能兼容性**: 优先保证现有功能可用  
✅ **XVII. 禁止Magento写法**: 绝对禁止Magento模式，严格遵循开发文档

**Status**: ✅ PASS - All constitutional requirements satisfied

## Project Structure

### Documentation (this feature)
```
specs/001-app-code-weline/
├── plan.md              # This file (/plan command output)
├── research.md          # Phase 0 output (/plan command)
├── data-model.md        # Phase 1 output (/plan command)
├── quickstart.md        # Phase 1 output (/plan command)
├── contracts/           # Phase 1 output (/plan command)
└── tasks.md             # Phase 2 output (/tasks command - NOT created by /plan)
```

### Source Code (repository root)
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
    ├── Backend/        # 后台模板
    ├── Frontend/       # 前端模板
    └── Api/            # API模板
```

**Structure Decision**: 采用 WelineFramework 标准模块结构，包含完整的 MVC 层次、服务层、测试层和视图模板。遵循框架的目录命名规范和模块化设计原则。

## Phase 0: Outline & Research
1. **Extract unknowns from Technical Context** above:
   - ✅ All NEEDS CLARIFICATION resolved in spec.md clarifications section
   - ✅ WelineFramework internal modules dependency patterns
   - ✅ Redis cache integration patterns
   - ✅ Queue system integration patterns

2. **Research findings consolidated** in `research.md`:
   - ✅ **SecretStore 实现**: 使用 WelineFramework 内置的 SecretStore 模块进行本地加密存储
   - ✅ **数据保留期**: 审计日志 90天，模型训练数据 30天，API调用日志 7天，性能监控数据 365天
   - ✅ **性能 SLO**: P95 响应时间 ≤ 3秒，P99 响应时间 ≤ 5秒，支持 1000+ 并发用户
   - ✅ **默认模型策略**: 成本优先 → 性能优先 → 质量优先，支持自动降级和成本控制
   - ✅ **场景适配器扫描机制**: 系统命令更新时自动通过观察者Observer更新，也支持命令行手动更新
   - ✅ **商业洞察报表实时性**: 数据聚合和报表更新延迟 ≤ 10分钟
   - ✅ **移动端推送通知**: 不实现推送通知功能
   - ✅ **第三方OAuth集成**: 通过场景适配器自行接入第三方，系统无需关心具体提供商
   - ✅ **营销推荐算法**: 按照常规推荐算法实现即可
   - ✅ **客户支持工单分配**: 采用均分策略分配工单
   - ✅ **开发者工具SDK语言**: 默认按照浏览器语言，不支持则使用AI解读语言返回对应语言
   - ✅ **计费系统支付网关**: 支持支付宝和微信支付
   - ✅ **监控告警配置**: 推荐配置阈值，默认通知方式为短信、钉钉和飞书
   - ✅ **国际化默认设置**: 可配置，默认语言为zh_Hans_CN，默认货币为CNY

**Output**: ✅ research.md with all NEEDS CLARIFICATION resolved

## Phase 1: Design & Contracts
*Prerequisites: research.md complete*

1. **Extract entities from feature spec** → `data-model.md`:
   - ✅ 23个核心数据表：ai_model, ai_assistant, ai_api_key, ai_scenario_adapter, ai_tenant, ai_audit_log, ai_performance_metric, ai_billing_record, ai_mobile_device, ai_third_party_integration, ai_marketing_campaign, ai_customer_support_ticket, ai_developer_tool, ai_monitoring_alert, ai_internationalization_config, ai_model_version, ai_assistant_prompt_template, ai_api_quota, ai_scenario_adapter_config, ai_tenant_config, ai_audit_log_detail, ai_performance_metric_detail, ai_billing_record_detail
   - ✅ 10个扩展数据表：ai_model_training_data, ai_assistant_conversation, ai_api_key_usage, ai_scenario_adapter_usage, ai_tenant_usage, ai_audit_log_export, ai_performance_metric_export, ai_billing_record_export, ai_mobile_device_usage, ai_third_party_integration_usage
   - ✅ 字段定义、关系、验证规则、状态转换

2. **Generate API contracts** from functional requirements:
   - ✅ Chat API: POST /api/v1/chat (流式/非流式)
   - ✅ Model API: GET/POST/PUT/DELETE /api/v1/model/{id}
   - ✅ Model Copy API: POST /api/v1/model/{id}/copy
   - ✅ API Key API: GET/POST/PUT/DELETE /api/v1/api-key
   - ✅ Assistant API: GET/POST/PUT/DELETE /api/v1/assistant
   - ✅ 输出到 `/contracts/` 目录

3. **Generate contract tests** from contracts:
   - ✅ chat_post.json, model_get.json, model_copy.json, api_key_post.json
   - ✅ 请求/响应模式验证
   - ✅ 测试必须失败（无实现）

4. **Extract test scenarios** from user stories:
   - ✅ 模型管理用户故事 → 集成测试场景
   - ✅ 助手管理用户故事 → 集成测试场景
   - ✅ API Key管理用户故事 → 集成测试场景
   - ✅ 场景适配器用户故事 → 集成测试场景
   - ✅ 商业洞察报表用户故事 → 集成测试场景
   - ✅ 多租户管理用户故事 → 集成测试场景

5. **Update agent file incrementally** (O(1) operation):
   - ✅ 运行 `.specify/scripts/powershell/update-agent-context.ps1 -AgentType cursor`
   - ✅ 添加新的技术栈信息
   - ✅ 保持手动添加内容
   - ✅ 更新最近变更（保留最后3个）
   - ✅ 保持在150行以内
   - ✅ 输出到仓库根目录

**Output**: ✅ data-model.md, ✅ /contracts/*, ✅ failing tests, ✅ quickstart.md, ✅ agent-specific file

## Phase 2: Task Planning Approach
*This section describes what the /tasks command will do - DO NOT execute during /plan*

**Task Generation Strategy**:
- Load `.specify/templates/tasks-template.md` as base
- Generate tasks from Phase 1 design docs (contracts, data model, quickstart)
- Each contract → contract test task [P]
- Each entity → model creation task [P] 
- Each user story → integration test task
- Implementation tasks to make tests pass
- HTTP request verification tasks for each endpoint
- Offcanvas UI implementation tasks
- WelineFramework compliance validation tasks

**Ordering Strategy**:
- TDD order: Tests before implementation 
- Dependency order: Models before services before UI
- Mark [P] for parallel execution (independent files)
- Constitution compliance: Anti-Magento pattern validation
- Framework learning: Documentation review and self-learning tasks

**Estimated Output**: 55+ numbered, ordered tasks in tasks.md covering:
- Phase 1: 核心实现 (模型管理、API Key、基础 API)
- Phase 1: 集成测试 (数据库连接、中间件、端到端验证)
- Phase 1: 完善 (单元测试、文档、性能优化)
- Phase 2: 助手管理系统 (助手 CRUD、MCP 配置)
- Phase 2: 多租户支持 (租户隔离、权限管理)
- Phase 2: 监控系统 (性能监控、告警、报表)
- Phase 3: 高级功能 (场景适配器、版本管理、国际化)

**IMPORTANT**: This phase is executed by the /tasks command, NOT by /plan

## Phase 3+: Future Implementation
*These phases are beyond the scope of the /plan command*

**Phase 3**: Task execution (/tasks command creates tasks.md)  
**Phase 4**: Implementation (execute tasks.md following constitutional principles)  
**Phase 5**: Validation (run tests, execute quickstart.md, performance validation)

## Complexity Tracking
*Fill ONLY if Constitution Check has violations that must be justified*

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., 4th project] | [current need] | [why 3 projects insufficient] |
| [e.g., Repository pattern] | [specific problem] | [why direct DB access insufficient] |


## Progress Tracking
*This checklist is updated during execution flow*

**Phase Status**:
- [x] Phase 0: Research complete (/plan command)
- [x] Phase 1: Design complete (/plan command)
- [x] Phase 2: Task planning complete (/plan command - describe approach only)
- [ ] Phase 3: Tasks generated (/tasks command)
- [ ] Phase 4: Implementation complete
- [ ] Phase 5: Validation passed

**Gate Status**:
- [x] Initial Constitution Check: PASS
- [x] Post-Design Constitution Check: PASS
- [x] All NEEDS CLARIFICATION resolved
- [x] Complexity deviations documented

---
*Based on Constitution v2.5.0 - See `/memory/constitution.md`*
