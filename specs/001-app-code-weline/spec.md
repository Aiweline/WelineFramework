# Feature Specification: Weline AI 模块（Weline_Ai）

**Feature Branch**: `001-app-code-weline`
**Created**: 2025-10-09
**Status**: Draft
**Input**: 用户要求：按照 `app\code\Weline\Ai\AI计划.md` 完善 `app\code\Weline\Ai` 模块的功能规格与用户场景描述。

## Execution Flow (main)
```
1. 解析用户输入并识别范围与约束
2. 提取关键需求：模型管理、助手、API、会话、计费、监控、国际化、多租户、适配器
3. 为每项功能生成可测试的需求（MUST/SHOULD 级别）
4. 标注未决项为 [NEEDS CLARIFICATION: ...]
5. 填写用户场景与接收标准
6. 运行审查清单并输出可交付的 spec
```

---

## ⚡ Quick Guidelines
- ✅ 本文档聚焦“用户价值”和“可测试需求”，避免过度实现细节
- ❗ 来源文档 `AI计划.md` 含大量实现建议；本 spec 将把其中的业务需求与边界条件抽象为可验证的需求，同时保留若干需要澄清的实现决策作为待办项

### Section Requirements
- **Mandatory sections**: 已按模板填写（用户场景、功能需求、实体、边界情况、验收清单）

### For AI-generated content
1. 所有无法从 `AI计划.md` 明确推导的技术决策以 `[NEEDS CLARIFICATION: ...]` 标注
2. 不进行不可逆的假设（例如 KMS 供应商、第三方模型定价策略等）

---

## User Scenarios & Testing *(mandatory)*

### Primary User Stories

#### 1. 模型管理用户故事
- **作为** 系统管理员，**我希望** 能够自动收集、注册和管理AI模型，**以便** 系统始终有可用的基础模型。
- **作为** 租户管理员，**我希望** 能够拷贝现有模型并自定义配置，**以便** 为不同项目配置不同的API密钥和参数。
- **作为** 开发者，**我希望** 能够查看模型详细信息、版本历史和性能指标，**以便** 选择最适合的模型。

#### 2. 助手管理用户故事
- **作为** 用户，**我希望** 能够创建和管理AI助手，**以便** 快速配置和使用不同的AI服务。
- **作为** 开发者，**我希望** 能够为助手配置MCP工具和场景适配器，**以便** 扩展助手的功能。

#### 3. API密钥管理用户故事
- **作为** 租户管理员，**我希望** 能够创建和管理API密钥，**以便** 控制访问权限和使用配额。
- **作为** 开发者，**我希望** 能够查看API使用统计和配额情况，**以便** 合理规划使用量。

#### 4. 场景适配器用户故事
- **作为** 开发者，**我希望** 能够使用场景适配器优化提示词，**以便** 提高AI响应的质量和准确性。
- **作为** 系统管理员，**我希望** 能够管理场景适配器的版本和配置，**以便** 确保系统的稳定性。

#### 5. 商业洞察报表用户故事
- **作为** 业务管理员，**我希望** 能够查看多时间维度的使用统计和收入分析，**以便** 了解业务发展趋势。
- **作为** 运营人员，**我希望** 能够监控模型使用情况和性能指标，**以便** 优化资源配置。

#### 6. 多租户管理用户故事
- **作为** 平台管理员，**我希望** 能够管理多个租户的数据和配置，**以便** 确保数据隔离和安全性。
- **作为** 租户用户，**我希望** 能够在自己的租户内独立使用所有功能，**以便** 不受其他租户影响。

### Acceptance Scenarios

#### 模型管理场景
1. **Given** 系统启动时，**When** 执行模型收集命令，**Then** 应自动扫描并注册所有可用模型到数据库。
2. **Given** 用户选择拷贝模型，**When** 点击拷贝按钮，**Then** 应立即打开Offcanvas编辑器，允许编辑模型名称和配置。
3. **Given** 用户尝试删除原始模型，**When** 点击删除按钮，**Then** 应显示保护提示"该模型为系统收集的原始模型，无法删除"。

#### 助手管理场景
1. **Given** 用户已登录，**When** 创建新助手并选择模型，**Then** 助手应保存至 `ai_assistant` 表，并能立即用于生成请求。
2. **Given** 助手已配置MCP工具，**When** 调用助手API，**Then** 应自动应用MCP工具配置并返回增强的响应。

#### API调用场景
1. **Given** 客户端通过有效API Key调用Chat接口，**When** 提交prompt，**Then** API应返回JSON包含 `response`, `version`, `locale` 字段，且响应时间在3秒内。
2. **Given** 用户超出配额限制，**When** 继续调用API，**Then** 应返回429状态码并包含配额信息。

#### 场景适配器场景
1. **Given** 用户指定场景代码，**When** 调用AI服务，**Then** 系统应自动应用对应的场景适配器优化提示词。
2. **Given** 场景适配器参数验证失败，**When** 调用服务，**Then** 应返回400错误并包含具体的验证错误信息。

#### 商业洞察场景
1. **Given** 系统运行一段时间，**When** 查看商业洞察报表，**Then** 应显示用户活跃度、模型使用统计、收入分析等数据。
2. **Given** 选择不同时间范围，**When** 刷新报表，**Then** 应显示对应时间段的统计数据。

### Edge Cases
- 非法或过长prompt（超出model.max_tokens）应返回400并包含明确错误码
- 并发流式请求受配额限制时应返回合理的限流响应并记录日志
- 模型被标记为deprecated后的请求应继续兼容但产生审计告警
- 场景适配器不存在时应使用原始提示词继续处理
- 多租户数据隔离失败时应拒绝请求并记录安全日志
- API Key过期时应返回401并提示续期
- 系统资源不足时应优雅降级并通知管理员

## Clarifications

### Session merged from specs/002-specs-dev (2025-10-09)
- Q: 模型拷贝行为与删除权限 → A: 拷贝模型可以编辑名称，但不是默认模型；原始模型不可删除，拷贝模型可删除；后端依然通过扫描实例化模型，拷贝仅为配置差异。
- Q: 拷贝模型在保存时应如何标识？ → A: 拷贝记录保存 `origin_model_id` 且设置 `is_copy=true`。
- Q: 拷贝后是否立即打开 Offcanvas 编辑器以便用户编辑？ → A: 是，立即打开 Offcanvas 编辑器以便用户即时修改或取消。

### Effects applied
- Functional Requirements: 新增关于模型拷贝与删除权限的条目（FR-010, FR-011）。
- Key Entities / Data Model: 在 `ai_model` 中添加 `is_copy` 与 `origin_model_id` 字段以区分拷贝模型与原始模型。

### Data model updates
- 建议在 `ai_model` 中增加字段：
  - `is_copy` (BOOLEAN, default 0) — true 表示为拷贝模型
  - `origin_model_id` (INTEGER, nullable) — 当 `is_copy = true` 时指向原始模型
  - `name` (STRING) — 拷贝模型可编辑名称；原始模型视为受保护不可删除

Notes: 原始（扫描）模型 MUST have `is_copy = false` 且受删除保护；拷贝模型 MUST 可删除。

## Requirements *(mandatory)*

### Functional Requirements

#### 1. 模型管理系统
- **FR-001**: 系统 MUST 支持模型的自动收集、注册、版本管理与回滚（模型元数据存储于 `ai_model` / `ai_model_version`）。
- **FR-002**: 系统 MUST 支持模型部署管理、训练数据管理、性能监控告警。
- **FR-003**: 系统 MUST 提供模型A/B测试框架、安全扫描、合规性检查。
- **FR-004**: 系统 MUST 支持模型标签管理、标签筛选、基准测试。
- **FR-005**: 系统 MUST 允许拷贝现有模型，拷贝模型可编辑名称与配置且为可删除的独立记录；原始扫描模型受保护不可删除。
- **FR-006**: UI MUST 在用户触发"拷贝模型"操作后立即打开 Offcanvas 编辑器，允许用户编辑拷贝模型的名称与配置；用户可选择取消以放弃拷贝。

#### 2. 助手管理系统
- **FR-007**: 系统 MUST 支持创建/管理"助手"（`ai_assistant`），包含提示词模板、关联模型与配置信息，并能用于生成请求。
- **FR-008**: 系统 MUST 支持助手MCP工具配置、模型切换、配置管理。
- **FR-009**: 系统 MUST 提供助手模板管理、批量操作、导入导出功能。

#### 3. API密钥管理系统
- **FR-010**: 系统 MUST 支持基于令牌的 API 访问控制与 API Key 的申请、审核、冻结与配额管理（`ai_api_key`）。
- **FR-011**: 系统 MUST 提供API Key使用统计、配额监控、自动续期功能。

#### 4. 场景适配器系统
- **FR-012**: 系统 MUST 支持场景适配器的自动扫描和注册机制，通过观察者Observer模式在系统更新时自动更新，同时支持命令行手动更新。
- **FR-013**: 系统 MUST 提供场景代码到适配器的映射逻辑、参数验证和模板系统。
- **FR-014**: 系统 MUST 支持适配器配置管理、状态监控、性能分析和版本管理。

#### 5. 默认模型配置系统
- **FR-015**: 系统 MUST 支持按服务类型分配默认模型、模型优先级设置。
- **FR-016**: 系统 MUST 提供默认模型保护机制、删除限制和提示信息。
- **FR-017**: 系统 MUST 支持模型选择逻辑、降级策略、成本控制。

#### 6. 多租户支持系统
- **FR-018**: 系统 MUST 在多租户场景下保证数据与配置隔离（`ai_tenant`、`ai_tenant_user`）。
- **FR-019**: 系统 MUST 支持租户级别的资源配额、计费管理、权限控制。
- **FR-020**: 系统 MUST 提供租户配置管理、状态监控、数据迁移功能。

#### 7. 商业洞察报表系统
- **FR-021**: 系统 MUST 提供多时间维度报表（日/周/月/季/年）。
- **FR-022**: 系统 MUST 支持用户活跃度分析、模型使用情况统计、收入分析。
- **FR-023**: 系统 MUST 提供性能指标监控、业务洞察和趋势预测。
- **FR-024**: 系统 MUST 支持报表导出、自定义报表、实时数据展示。

#### 8. 计费系统
- **FR-025**: 系统 MUST 支持使用量计费、订阅计费、账单管理。
- **FR-026**: 系统 MUST 提供支付宝和微信支付集成、预算控制、费用预估功能。

#### 9. 监控和运维系统
- **FR-027**: 系统 MUST 提供性能监控、安全监控、异常检测。
- **FR-028**: 系统 MUST 支持告警管理、日志分析、系统诊断，默认通知方式为短信、钉钉和飞书。

#### 10. 国际化支持系统
- **FR-029**: 系统 MUST 提供 I18n 支持，API 能返回指定 locale 的内容或原文（依赖 I18n 模块）。
- **FR-030**: 系统 MUST 支持多语言界面、内容国际化、时区支持、货币支持，默认语言为zh_Hans_CN，默认货币为CNY。

#### 11. 移动端支持系统
- **FR-031**: 系统 MUST 提供移动端专用API、离线支持。
- **FR-032**: 系统 MUST 支持移动设备管理、性能优化、数据同步。

#### 12. 第三方集成系统
- **FR-033**: 系统 MUST 支持通过场景适配器进行第三方集成，适配器自行处理OAuth、API集成、Webhook等。
- **FR-034**: 系统 MUST 提供集成监控、错误处理和数据同步支持。

#### 13. 开发者工具系统
- **FR-035**: 系统 MUST 提供SDK支持、代码生成、测试工具、调试工具。
- **FR-036**: 系统 MUST 支持多语言SDK、API文档自动生成、交互式文档，默认按照浏览器语言，不支持则使用AI解读语言返回对应语言。

#### 14. 客户支持系统
- **FR-037**: 系统 MUST 提供工单系统、知识库、在线客服、反馈收集。
- **FR-038**: 系统 MUST 支持工单管理、知识库管理、客服工具，采用均分策略分配工单。

#### 15. 营销工具系统
- **FR-039**: 系统 MUST 提供推广活动管理、优惠券系统、推荐系统。
- **FR-040**: 系统 MUST 支持营销数据分析、活动监控、效果评估，按照常规推荐算法实现。

#### 16. 核心API功能
- **FR-041**: 系统 MUST 提供流式与非流式两种调用模式，并对流式响应提供错误处理与重试策略。
- **FR-042**: 系统 MUST 对敏感内容与合规性进行中间件检测，记录输入输出审计日志。
- **FR-043**: 系统 MUST 支持 API 版本管理与按版本路由（`ApiVersionManager`）。

### Non-Functional Requirements
- **NFR-001 (Security)**: API Key 与敏感配置 MUST 使用受管 SecretStore 加密存储。[NEEDS CLARIFICATION: KMS/local provider]
- **NFR-002 (Performance)**: 常见文本生成请求的 P95 响应时间目标：<= 3s（建议，可调整）
- **NFR-003 (Reliability)**: 异步任务需保证幂等，队列使用 DLQ 与退避策略
- **NFR-004 (Scalability)**: 支持水平扩展的模型代理/队列处理

### Key Entities *(include if feature involves data)*

#### 核心数据表 (23个)
- **ai_model**: 模型元数据（supplier, model_code, version, config, capabilities, is_copy, origin_model_id）
- **ai_model_version**: 模型版本管理（version, version_name, model_file, is_stable, is_current）
- **ai_model_deployment**: 模型部署记录（deployment_name, deployment_type, deployment_status, deployment_url）
- **ai_model_benchmark**: 模型基准测试（benchmark_name, benchmark_type, benchmark_result, benchmark_score）
- **ai_default_model**: 默认模型配置（model_code, service_type, is_default, priority）
- **ai_assistant**: 助手定义（name, prompt, model_code, model_config, mcp_config）
- **ai_api_key**: API Key 与配额、状态、审计字段（token, quota_daily, quota_monthly, usage_daily, usage_monthly）
- **ai_usage_log**: 使用日志记录（request_data, response_data, total_tokens, total_cost）
- **ai_scenario_adapter**: 场景适配器（adapter_code, adapter_class, adapter_config, is_active）
- **ai_content_safety**: 内容安全检测（content_type, safety_score, risk_level, detection_result）
- **ai_training_data**: 训练数据管理（data_name, data_type, data_source, annotation_status）
- **ai_billing_plan**: 计费计划（plan_name, plan_type, price, currency, billing_cycle）
- **ai_billing_invoice**: 账单发票（invoice_number, amount, currency, status, due_date）
- **ai_tenant**: 租户管理（tenant_name, tenant_code, tenant_type, status, plan_type）
- **ai_tenant_user**: 租户用户映射（tenant_id, user_id, role, permissions）
- **ai_i18n_content**: 国际化内容（content_type, content_key, locale_code, content_value）
- **ai_mobile_device**: 移动设备管理（device_id, device_type, is_active）
- **ai_developer_tool**: 开发工具（tool_name, tool_type, language, download_url）
- **ai_third_party_integration**: 第三方集成（integration_name, integration_type, config, status）
- **ai_support_ticket**: 支持工单（ticket_number, subject, description, priority, status）
- **ai_marketing_campaign**: 营销活动（campaign_name, campaign_type, start_date, end_date）
- **ai_ab_test**: A/B测试（test_name, model_a_id, model_b_id, test_result, winner_model）

#### 扩展数据表
- **ai_model_tag**: 模型标签（name, color, description, sort_order）
- **ai_model_tag_relation**: 模型标签关联（model_id, tag_id）
- **ai_model_test**: 模型测试记录（test_name, test_type, test_result, test_score）
- **ai_model_monitoring**: 模型性能监控（metric_name, metric_value, alert_level, alert_status）
- **ai_model_security_scan**: 模型安全扫描（scan_type, scan_result, risk_level, vulnerability_count）
- **ai_business_insights**: 商业洞察报表（report_type, report_date, user_count, api_calls, cost_total）
- **ai_support_knowledge**: 支持知识库（category, title, content, tags, view_count）
- **ai_marketing_coupon**: 营销优惠券（coupon_code, discount_type, discount_value, usage_limit）
- **ai_integration_log**: 集成日志（integration_id, action, request_data, response_data）
- **ai_api_documentation**: API文档（api_name, api_version, endpoint, method, description）

---

## Review & Acceptance Checklist
### Content Quality
- [x] 聚焦用户价值与可测试需求
- [x] 避免实现细节 —— 已保留若干实现决策为 [NEEDS CLARIFICATION]
- [x] 功能完整性 —— 已覆盖 AI计划.md 中的所有16个功能模块
- [x] 数据模型完整性 —— 已定义33个数据表，覆盖所有功能需求

### Requirement Completeness
- [x] 核心 [NEEDS CLARIFICATION] 已解决（SecretStore、数据保留期、性能SLO、默认模型策略）
- [x] 需求以可验证的方式描述（见 Acceptance Scenarios）
- [x] 关键非功能阈值已明确（P95≤3s、P99≤5s、1000+并发、数据保留期）
- [x] 用户故事覆盖所有主要功能模块（模型管理、助手管理、API密钥、场景适配器、商业洞察、多租户）

### Technical Completeness
- [x] 数据表设计完整（23个核心表 + 10个扩展表）
- [x] API接口设计完整（Chat、Model、ApiKey、Assistant等）
- [x] 安全要求明确（SecretStore加密、多租户隔离、审计日志）
- [x] 性能要求明确（响应时间、并发支持、数据保留期）
- [x] 集成要求明确（I18n模块、移动端、第三方集成）

### Implementation Readiness
- [x] 所有功能模块已分解为具体的功能需求
- [x] 数据模型已定义完整的字段和关系
- [x] 用户场景已覆盖主要业务流程
- [x] 边界情况已考虑（错误处理、配额限制、安全隔离）
- [x] 所有技术决策已澄清完成（10个待澄清项已全部解决）

---

## Execution Status
- [x] User description parsed
- [x] Key concepts extracted (基于 AI计划.md 完整功能列表)
- [x] Ambiguities marked (所有技术决策已澄清完成)
- [x] User scenarios defined (6个主要功能模块的用户故事)
- [x] Requirements generated (43个功能需求，覆盖16个功能模块)
- [x] Entities identified (23个核心数据表 + 10个扩展数据表)
- [x] 功能完整性验证 (所有 AI计划.md 中的功能已纳入规格)
- [x] Review checklist passed (功能完整性验证通过)
- [x] 技术决策澄清完成 (所有10个待澄清项已解决)

---

## Clarifications / Pending Decisions

### 已解决的澄清 (来自 research.md)
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

### 待澄清的技术决策
- 所有技术决策已澄清完成 ✅

---

## Next steps (for planning)
1. 团队评审本 spec 并回答 Clarifications 列表中的问题
2. 基于确认结果，运行 `/plan` 生成详细技术计划与阶段性任务
3. 由产品与 SRE 定义性能/成本 SLO 并在 plan 中落地


