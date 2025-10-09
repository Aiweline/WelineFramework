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

### Primary User Story
作为平台用户（租户管理员或普通开发者），我希望能在控制台注册/管理 AI 助手与 API 密钥，选择或切换模型，并通过 REST API 或 PHP 服务调用生成文本/翻译/代码等结果，系统应按租户隔离使用配额与审计所有调用。

### Acceptance Scenarios
1. **Given** 租户管理员已注册并登录，**When** 在后台创建一个新助手并选择模型，**Then** 助手应保存至 `ai_assistant`，并能立即用于生成请求（返回 success=true）。
2. **Given** 客户端通过有效 API Key 调用 `Chat` 接口并指定 `version=2024-01-15`，**When** 提交 prompt，**Then** API 返回 JSON 包含 `response`, `version`, `locale` 字段，且响应时间在 3 秒内（示例可量化目标，具体阈值需团队同意）。
3. **Given** 某模型出现高错误率或成本超阈值，**When** 系统检测到异常，**Then** 自动触发降级策略并记录告警（见 P1 优化清单）。

### Edge Cases
- 非法或过长 prompt（超出 model.max_tokens）应返回 400 并包含明确错误码
- 并发流式请求受配额限制时应返回合理的限流响应并记录日志
- 模型被标记为 deprecated 后的请求应继续兼容但产生审计告警

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
- **FR-001**: 系统 MUST 支持模型的注册、版本管理与回滚（模型元数据存储于 `ai_model` / `ai_model_version`）。
- **FR-002**: 系统 MUST 支持创建/管理“助手”（`ai_assistant`），包含提示词模板、关联模型与配置信息，并能用于生成请求。
- **FR-003**: 系统 MUST 支持基于令牌的 API 访问控制与 API Key 的申请、审核、冻结与配额管理（`ai_api_key`）。
- **FR-004**: 系统 MUST 在多租户场景下保证数据与配置隔离（`ai_tenant`、`ai_tenant_user`）。
- **FR-005**: 系统 MUST 提供流式与非流式两种调用模式，并对流式响应提供错误处理与重试策略。
- **FR-006**: 系统 MUST 提供模型性能监控与告警（`ai_model_monitoring`），并支持 A/B 测试记录（`ai_model_ab_test`）。
- **FR-007**: 系统 MUST 提供 I18n 支持，API 能返回指定 locale 的内容或原文（依赖 I18n 模块）。
- **FR-008**: 系统 MUST 对敏感内容与合规性进行中间件检测，记录输入输出审计日志。
- **FR-009**: 系统 MUST 支持 API 版本管理与按版本路由（`ApiVersionManager`）。
- **FR-010**: 系统 MUST 允许拷贝现有模型，拷贝模型可编辑名称与配置且为可删除的独立记录；原始扫描模型受保护不可删除。
- **FR-011**: UI MUST 在用户触发“拷贝模型”操作后立即打开 Offcanvas 编辑器，允许用户编辑拷贝模型的名称与配置；用户可选择取消以放弃拷贝。

### Non-Functional Requirements
- **NFR-001 (Security)**: API Key 与敏感配置 MUST 使用受管 SecretStore 加密存储。[NEEDS CLARIFICATION: KMS/local provider]
- **NFR-002 (Performance)**: 常见文本生成请求的 P95 响应时间目标：<= 3s（建议，可调整）
- **NFR-003 (Reliability)**: 异步任务需保证幂等，队列使用 DLQ 与退避策略
- **NFR-004 (Scalability)**: 支持水平扩展的模型代理/队列处理

### Key Entities *(include if feature involves data)*
- **ai_model**: 模型元数据（supplier, model_code, version, config, capabilities）
- **ai_assistant**: 助手定义（name, prompt, model_code, model_config）
- **ai_api_key**: API Key 与配额、状态、审计字段
- **ai_tenant / ai_tenant_user**: 多租户与用户映射
- **ai_model_monitoring / ai_model_performance**: 性能与告警数据

---

## Review & Acceptance Checklist
### Content Quality
- [x] 聚焦用户价值与可测试需求
- [ ] 避免实现细节 —— 已保留若干实现决策为 [NEEDS CLARIFICATION]

### Requirement Completeness
- [ ] 所有 [NEEDS CLARIFICATION] 已被回答（待办）
- [x] 需求以可验证的方式描述（见 Acceptance Scenarios）
- [ ] 关键非功能阈值需团队确认（性能/安全/退避策略等）

---

## Execution Status
- [x] User description parsed
- [x] Key concepts extracted
- [x] Ambiguities marked
- [x] User scenarios defined
- [x] Requirements generated
- [x] Entities identified
- [ ] Review checklist passed

---

## Clarifications / Pending Decisions
- [NEEDS CLARIFICATION] 数据保留期（审计 / 模型训练数据）应为多少天？
- [NEEDS CLARIFICATION] SecretStore 使用哪种 KMS 或本地加密实现？
- [NEEDS CLARIFICATION] API 性能 SLO（P95/P99）和成本阈值由产品或 SRE 确定
- [NEEDS CLARIFICATION] 默认模型优先级与成本控制策略的实施细节

---

## Next steps (for planning)
1. 团队评审本 spec 并回答 Clarifications 列表中的问题
2. 基于确认结果，运行 `/plan` 生成详细技术计划与阶段性任务
3. 由产品与 SRE 定义性能/成本 SLO 并在 plan 中落地


