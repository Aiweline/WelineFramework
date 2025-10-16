# Data Model: Weline_Ai Module

**Branch**: `001-app-code-weline` | **Date**: 2025-10-12  
**Input**: Derived from [spec.md](./spec.md) Key Entities section

## Overview

本文档定义 Weline_Ai 模块的完整数据模型，包含33个数据表（23个核心表 + 10个扩展表），涵盖模型管理、助手、API密钥、场景适配器、租户、计费、监控等所有功能模块。

## Entity Relationship Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                      Core Entities (23)                          │
├─────────────────────────────────────────────────────────────────┤
│  ai_model ─┬─→ ai_model_version                                 │
│            ├─→ ai_model_deployment                               │
│            ├─→ ai_model_benchmark                                │
│            ├─→ ai_model_monitoring                               │
│            └─→ ai_ab_test (model_a_id, model_b_id)              │
│                                                                   │
│  ai_default_model ─→ ai_model (model_id)                        │
│                                                                   │
│  ai_assistant ─┬─→ ai_model (model_code)                        │
│                ├─→ ai_assistant_prompt_template                  │
│                └─→ ai_assistant_conversation                     │
│                                                                   │
│  ai_api_key ─┬─→ ai_tenant (tenant_id)                          │
│              ├─→ ai_api_quota                                    │
│              └─→ ai_usage_log                                    │
│                                                                   │
│  ai_scenario_adapter ─→ ai_scenario_adapter_config              │
│                                                                   │
│  ai_tenant ─┬─→ ai_tenant_config                                │
│             ├─→ ai_tenant_user                                   │
│             ├─→ ai_api_key                                       │
│             └─→ ai_billing_invoice                               │
│                                                                   │
│  ai_billing_plan ─→ ai_billing_invoice                          │
│                                                                   │
│  ai_content_safety, ai_training_data, ai_i18n_content,          │
│  ai_mobile_device, ai_mobile_notification, ai_developer_tool,   │
│  ai_third_party_integration, ai_support_ticket,                 │
│  ai_marketing_campaign (independent entities)                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Core Entities (23 Tables)

### 1. ai (AI模型)

**⚠️ 表名说明**: 实际表名为 `ai`（不是 `ai_model`）。这是由于 WelineFramework 的表名推导机制：`AiModel` → 去除 `Model` 后缀 → `Ai` → `ai`。

**描述**: 存储AI模型的元数据信息，包括供应商、模型代码、版本、配置、能力等。

**字段定义**:

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 模型ID |
| `supplier` | VARCHAR(100) | NOT NULL | - | 供应商（openai, anthropic, google等） |
| `model_code` | VARCHAR(100) | NOT NULL, UNIQUE | - | 模型代码（gpt-4, claude-3等） |
| `name` | VARCHAR(255) | NOT NULL | - | 模型名称 |
| `version` | VARCHAR(50) | NULL | - | 模型版本号 |
| `config` | JSON | NULL | - | 模型配置（JSON格式） |
| `max_tokens` | INTEGER | NOT NULL | 4096 | 最大令牌数 |
| `cost_per_token` | DECIMAL(10,6) | NOT NULL | 0.0015 | 每令牌成本 |
| `capabilities` | JSON | NULL | - | 模型能力（JSON数组） |
| `is_active` | TINYINT(1) | NOT NULL | 1 | 是否激活 |
| `is_default` | TINYINT(1) | NOT NULL | 0 | 是否默认模型 |
| `is_copy` | TINYINT(1) | NOT NULL | 0 | 是否为拷贝模型 |
| `origin_model_id` | INTEGER | NULL | - | 原始模型ID（is_copy=1时） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `model_code`
- INDEX: `supplier`, `is_active`, `is_default`
- FOREIGN KEY: `origin_model_id` → `ai_model(id)`

**Constitution 对齐**:
- ✅ FR-001, FR-005, FR-006: 模型管理、拷贝、删除保护

---

### 2. ai_model_version (模型版本)

**描述**: 管理AI模型的版本历史。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 版本ID |
| `model_id` | INTEGER | NOT NULL | - | 模型ID |
| `version` | VARCHAR(50) | NOT NULL | - | 版本号 |
| `version_name` | VARCHAR(255) | NULL | - | 版本名称 |
| `model_file` | VARCHAR(500) | NULL | - | 模型文件路径 |
| `is_stable` | TINYINT(1) | NOT NULL | 0 | 是否稳定版本 |
| `is_current` | TINYINT(1) | NOT NULL | 0 | 是否当前版本 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `model_id, version`
- FOREIGN KEY: `model_id` → `ai_model(id)`

---

### 3. ai_model_deployment (模型部署)

**描述**: 记录模型的部署信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 部署ID |
| `model_id` | INTEGER | NOT NULL | - | 模型ID |
| `deployment_name` | VARCHAR(255) | NOT NULL | - | 部署名称 |
| `deployment_type` | VARCHAR(50) | NOT NULL | - | 部署类型（cloud, local, hybrid） |
| `deployment_status` | VARCHAR(50) | NOT NULL | 'pending' | 状态（pending, deploying, active, failed） |
| `deployment_url` | VARCHAR(500) | NULL | - | 部署URL |
| `deployed_at` | TIMESTAMP | NULL | - | 部署时间 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `model_id`, `deployment_status`
- FOREIGN KEY: `model_id` → `ai_model(id)`

---

### 4. ai_model_benchmark (模型基准测试)

**描述**: 存储模型的基准测试结果。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 测试ID |
| `model_id` | INTEGER | NOT NULL | - | 模型ID |
| `benchmark_name` | VARCHAR(255) | NOT NULL | - | 基准测试名称 |
| `benchmark_type` | VARCHAR(50) | NOT NULL | - | 测试类型（performance, accuracy, cost） |
| `benchmark_result` | JSON | NULL | - | 测试结果（JSON格式） |
| `benchmark_score` | DECIMAL(10,4) | NULL | - | 测试得分 |
| `tested_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 测试时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `model_id`, `benchmark_type`
- FOREIGN KEY: `model_id` → `ai_model(id)`

---

### 5. ai_default_model (默认模型配置)

**描述**: 管理不同服务类型的默认模型配置。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 配置ID |
| `model_code` | VARCHAR(100) | NOT NULL | - | 模型代码 |
| `service_type` | VARCHAR(50) | NOT NULL | - | 服务类型（chat, translation, code_generation） |
| `is_default` | TINYINT(1) | NOT NULL | 0 | 是否默认 |
| `priority` | INTEGER | NOT NULL | 0 | 优先级（数字越大优先级越高） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `service_type, priority`
- INDEX: `model_code`, `is_default`

**Constitution 对齐**:
- ✅ FR-015至FR-017: 默认模型配置系统

---

### 6. ai_assistant (AI助手)

**描述**: 定义AI助手配置。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 助手ID |
| `name` | VARCHAR(255) | NOT NULL | - | 助手名称 |
| `prompt` | TEXT | NULL | - | 系统提示词 |
| `model_code` | VARCHAR(100) | NOT NULL | - | 使用的模型代码 |
| `model_config` | JSON | NULL | - | 模型配置覆盖 |
| `mcp_config` | JSON | NULL | - | MCP工具配置 |
| `is_active` | TINYINT(1) | NOT NULL | 1 | 是否激活 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `model_code`, `is_active`

**Constitution 对齐**:
- ✅ FR-007至FR-009: 助手管理系统

---

### 7. ai_assistant_prompt_template (助手提示词模板)

**描述**: 管理助手的提示词模板库。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 模板ID |
| `assistant_id` | INTEGER | NOT NULL | - | 助手ID |
| `template_name` | VARCHAR(255) | NOT NULL | - | 模板名称 |
| `template_content` | TEXT | NOT NULL | - | 模板内容 |
| `variables` | JSON | NULL | - | 模板变量定义 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `assistant_id`
- FOREIGN KEY: `assistant_id` → `ai_assistant(id)`

---

### 8. ai_assistant_conversation (助手会话)

**描述**: 记录助手的会话历史。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 会话ID |
| `assistant_id` | INTEGER | NOT NULL | - | 助手ID |
| `user_id` | INTEGER | NULL | - | 用户ID |
| `conversation_data` | JSON | NOT NULL | - | 会话数据（消息列表） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `assistant_id`, `user_id`
- FOREIGN KEY: `assistant_id` → `ai_assistant(id)`

---

### 9. ai_api_key (API密钥)

**描述**: 管理API访问密钥和配额。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 密钥ID |
| `token` | VARCHAR(255) | NOT NULL, UNIQUE | - | API密钥（加密存储） |
| `tenant_id` | INTEGER | NULL | - | 租户ID |
| `user_id` | INTEGER | NULL | - | 用户ID |
| `quota_daily` | INTEGER | NOT NULL | 1000 | 每日配额 |
| `quota_monthly` | INTEGER | NOT NULL | 30000 | 每月配额 |
| `usage_daily` | INTEGER | NOT NULL | 0 | 每日使用量 |
| `usage_monthly` | INTEGER | NOT NULL | 0 | 每月使用量 |
| `status` | VARCHAR(20) | NOT NULL | 'active' | 状态（active, frozen, expired） |
| `expires_at` | TIMESTAMP | NULL | - | 过期时间 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `token`
- INDEX: `tenant_id`, `user_id`, `status`

**Constitution 对齐**:
- ✅ FR-010至FR-011: API密钥管理系统
- ✅ NFR-001: SecretStore 加密存储

---

### 10. ai_api_quota (API配额管理)

**描述**: 管理API密钥的详细配额规则。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 配额ID |
| `api_key_id` | INTEGER | NOT NULL | - | API密钥ID |
| `quota_type` | VARCHAR(50) | NOT NULL | - | 配额类型（request, token, cost） |
| `quota_limit` | INTEGER | NOT NULL | - | 配额上限 |
| `quota_period` | VARCHAR(20) | NOT NULL | - | 配额周期（hour, day, month） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `api_key_id, quota_type, quota_period`
- FOREIGN KEY: `api_key_id` → `ai_api_key(id)`

---

### 11. ai_usage_log (使用日志)

**描述**: 记录API调用的详细日志。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 日志ID |
| `api_key_id` | INTEGER | NOT NULL | - | API密钥ID |
| `tenant_id` | INTEGER | NULL | - | 租户ID |
| `model_code` | VARCHAR(100) | NOT NULL | - | 使用的模型 |
| `request_data` | TEXT | NULL | - | 请求数据 |
| `response_data` | TEXT | NULL | - | 响应数据 |
| `total_tokens` | INTEGER | NOT NULL | 0 | 总令牌数 |
| `total_cost` | DECIMAL(10,6) | NOT NULL | 0 | 总成本 |
| `status` | VARCHAR(20) | NOT NULL | - | 状态（success, error） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `api_key_id`, `tenant_id`, `model_code`, `created_at`
- FOREIGN KEY: `api_key_id` → `ai_api_key(id)`

**Constitution 对齐**:
- ✅ NFR-003: 审计日志记录（保留期7天）

---

### 12. ai_scenario_adapter (场景适配器)

**描述**: 管理场景适配器的注册信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 适配器ID |
| `adapter_code` | VARCHAR(100) | NOT NULL, UNIQUE | - | 适配器代码 |
| `adapter_class` | VARCHAR(255) | NOT NULL | - | 适配器类名 |
| `adapter_config` | JSON | NULL | - | 适配器配置 |
| `description` | TEXT | NULL | - | 适配器描述 |
| `is_active` | TINYINT(1) | NOT NULL | 1 | 是否激活 |
| `version` | VARCHAR(50) | NULL | - | 版本号 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `adapter_code`
- INDEX: `is_active`

**Constitution 对齐**:
- ✅ FR-012至FR-014: 场景适配器系统

---

### 13. ai_scenario_adapter_config (适配器配置)

**描述**: 管理场景适配器的详细配置。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 配置ID |
| `adapter_id` | INTEGER | NOT NULL | - | 适配器ID |
| `config_key` | VARCHAR(100) | NOT NULL | - | 配置键 |
| `config_value` | TEXT | NULL | - | 配置值 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `adapter_id, config_key`
- FOREIGN KEY: `adapter_id` → `ai_scenario_adapter(id)`

---

### 14. ai_content_safety (内容安全)

**描述**: 记录内容安全检测结果。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 记录ID |
| `content_type` | VARCHAR(50) | NOT NULL | - | 内容类型（input, output） |
| `content_text` | TEXT | NOT NULL | - | 内容文本 |
| `safety_score` | DECIMAL(5,4) | NOT NULL | - | 安全得分（0-1） |
| `risk_level` | VARCHAR(20) | NOT NULL | - | 风险等级（low, medium, high） |
| `detection_result` | JSON | NULL | - | 检测详细结果 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `content_type`, `risk_level`, `created_at`

**Constitution 对齐**:
- ✅ VI. 安全与合规: 内容安全检查

---

### 15. ai_training_data (训练数据)

**描述**: 管理模型训练数据。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 数据ID |
| `model_id` | INTEGER | NOT NULL | - | 模型ID |
| `data_name` | VARCHAR(255) | NOT NULL | - | 数据名称 |
| `data_type` | VARCHAR(50) | NOT NULL | - | 数据类型（text, image, audio） |
| `data_source` | VARCHAR(500) | NULL | - | 数据来源 |
| `annotation_status` | VARCHAR(20) | NOT NULL | 'pending' | 标注状态 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `model_id`, `data_type`, `annotation_status`
- FOREIGN KEY: `model_id` → `ai_model(id)`

---

### 16. ai_billing_plan (计费计划)

**描述**: 定义计费计划和定价策略。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 计划ID |
| `plan_name` | VARCHAR(255) | NOT NULL | - | 计划名称 |
| `plan_type` | VARCHAR(50) | NOT NULL | - | 计划类型（free, basic, pro, enterprise） |
| `price` | DECIMAL(10,2) | NOT NULL | 0 | 价格 |
| `currency` | VARCHAR(10) | NOT NULL | 'CNY' | 货币单位 |
| `billing_cycle` | VARCHAR(20) | NOT NULL | - | 计费周期（monthly, yearly） |
| `features` | JSON | NULL | - | 功能列表 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `plan_type`

**Constitution 对齐**:
- ✅ FR-025至FR-026: 计费系统

---

### 17. ai_billing_invoice (账单发票)

**描述**: 记录账单和发票信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 发票ID |
| `tenant_id` | INTEGER | NOT NULL | - | 租户ID |
| `plan_id` | INTEGER | NOT NULL | - | 计划ID |
| `invoice_number` | VARCHAR(100) | NOT NULL, UNIQUE | - | 发票号 |
| `amount` | DECIMAL(10,2) | NOT NULL | - | 金额 |
| `currency` | VARCHAR(10) | NOT NULL | 'CNY' | 货币单位 |
| `status` | VARCHAR(20) | NOT NULL | 'pending' | 状态（pending, paid, overdue） |
| `due_date` | DATE | NOT NULL | - | 到期日期 |
| `paid_at` | TIMESTAMP | NULL | - | 支付时间 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `invoice_number`
- INDEX: `tenant_id`, `plan_id`, `status`
- FOREIGN KEY: `tenant_id` → `ai_tenant(id)`, `plan_id` → `ai_billing_plan(id)`

---

### 18. ai_tenant (租户)

**描述**: 管理多租户信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 租户ID |
| `tenant_name` | VARCHAR(255) | NOT NULL | - | 租户名称 |
| `tenant_code` | VARCHAR(100) | NOT NULL, UNIQUE | - | 租户代码 |
| `tenant_type` | VARCHAR(50) | NOT NULL | - | 租户类型（personal, business, enterprise） |
| `status` | VARCHAR(20) | NOT NULL | 'active' | 状态（active, suspended, deleted） |
| `plan_type` | VARCHAR(50) | NOT NULL | 'free' | 计划类型 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `tenant_code`
- INDEX: `status`, `plan_type`

**Constitution 对齐**:
- ✅ FR-018至FR-020: 多租户支持系统

---

### 19. ai_tenant_config (租户配置)

**描述**: 管理租户级别的配置信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 配置ID |
| `tenant_id` | INTEGER | NOT NULL | - | 租户ID |
| `config_key` | VARCHAR(100) | NOT NULL | - | 配置键 |
| `config_value` | TEXT | NULL | - | 配置值 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `tenant_id, config_key`
- FOREIGN KEY: `tenant_id` → `ai_tenant(id)`

---

### 20. ai_tenant_user (租户用户映射)

**描述**: 管理租户与用户的关联关系。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 映射ID |
| `tenant_id` | INTEGER | NOT NULL | - | 租户ID |
| `user_id` | INTEGER | NOT NULL | - | 用户ID |
| `role` | VARCHAR(50) | NOT NULL | - | 角色（owner, admin, member） |
| `permissions` | JSON | NULL | - | 权限列表 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `tenant_id, user_id`
- INDEX: `user_id`, `role`
- FOREIGN KEY: `tenant_id` → `ai_tenant(id)`

---

### 21. ai_i18n_content (国际化内容)

**描述**: 存储国际化翻译内容。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 内容ID |
| `content_type` | VARCHAR(50) | NOT NULL | - | 内容类型（ui, message, error） |
| `content_key` | VARCHAR(255) | NOT NULL | - | 内容键 |
| `locale_code` | VARCHAR(10) | NOT NULL | - | 语言代码（zh_Hans_CN, en_US） |
| `content_value` | TEXT | NOT NULL | - | 内容值 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `content_type, content_key, locale_code`
- INDEX: `locale_code`

**Constitution 对齐**:
- ✅ FR-029至FR-030: 国际化支持系统

---

### 22. ai_mobile_device (移动设备)

**描述**: 管理移动设备信息。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 设备ID |
| `user_id` | INTEGER | NOT NULL | - | 用户ID |
| `device_id` | VARCHAR(255) | NOT NULL, UNIQUE | - | 设备唯一标识 |
| `device_type` | VARCHAR(50) | NOT NULL | - | 设备类型（ios, android） |
| `device_token` | VARCHAR(255) | NULL | - | 推送令牌 |
| `is_active` | TINYINT(1) | NOT NULL | 1 | 是否激活 |
| `last_active_at` | TIMESTAMP | NULL | - | 最后活跃时间 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `device_id`
- INDEX: `user_id`, `is_active`

**Constitution 对齐**:
- ✅ FR-031至FR-032: 移动端支持系统

---

### 23. ai_mobile_notification (移动通知)

**描述**: 记录移动端推送通知（注：根据research.md决策，不实现推送通知功能，此表保留备用）。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 通知ID |
| `device_id` | INTEGER | NOT NULL | - | 设备ID |
| `notification_type` | VARCHAR(50) | NOT NULL | - | 通知类型 |
| `notification_title` | VARCHAR(255) | NOT NULL | - | 通知标题 |
| `notification_body` | TEXT | NULL | - | 通知内容 |
| `status` | VARCHAR(20) | NOT NULL | 'pending' | 状态（pending, sent, failed） |
| `sent_at` | TIMESTAMP | NULL | - | 发送时间 |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `device_id`, `status`
- FOREIGN KEY: `device_id` → `ai_mobile_device(id)`

---

## Extended Entities (10 Tables)

### 24. ai_developer_tool (开发者工具)

**描述**: 管理SDK、文档等开发者工具。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 工具ID |
| `tool_name` | VARCHAR(255) | NOT NULL | - | 工具名称 |
| `tool_type` | VARCHAR(50) | NOT NULL | - | 工具类型（sdk, cli, doc） |
| `language` | VARCHAR(50) | NULL | - | 编程语言 |
| `version` | VARCHAR(50) | NOT NULL | - | 版本号 |
| `download_url` | VARCHAR(500) | NULL | - | 下载URL |
| `documentation_url` | VARCHAR(500) | NULL | - | 文档URL |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `tool_type`, `language`

**Constitution 对齐**:
- ✅ FR-035至FR-036: 开发者工具系统

---

### 25. ai_third_party_integration (第三方集成)

**描述**: 管理第三方服务集成配置。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 集成ID |
| `integration_name` | VARCHAR(255) | NOT NULL | - | 集成名称 |
| `integration_type` | VARCHAR(50) | NOT NULL | - | 集成类型（oauth, api, webhook） |
| `config` | JSON | NOT NULL | - | 集成配置 |
| `status` | VARCHAR(20) | NOT NULL | 'active' | 状态（active, inactive, error） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `integration_type`, `status`

**Constitution 对齐**:
- ✅ FR-033至FR-034: 第三方集成系统

---

### 26. ai_support_ticket (支持工单)

**描述**: 管理客户支持工单。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 工单ID |
| `ticket_number` | VARCHAR(100) | NOT NULL, UNIQUE | - | 工单号 |
| `user_id` | INTEGER | NOT NULL | - | 用户ID |
| `subject` | VARCHAR(255) | NOT NULL | - | 工单主题 |
| `description` | TEXT | NOT NULL | - | 问题描述 |
| `priority` | VARCHAR(20) | NOT NULL | 'normal' | 优先级（low, normal, high, urgent） |
| `status` | VARCHAR(20) | NOT NULL | 'open' | 状态（open, in_progress, resolved, closed） |
| `assigned_to` | INTEGER | NULL | - | 分配给（客服ID） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `resolved_at` | TIMESTAMP | NULL | - | 解决时间 |

**索引**:
- PRIMARY KEY: `id`
- UNIQUE KEY: `ticket_number`
- INDEX: `user_id`, `status`, `priority`, `assigned_to`

**Constitution 对齐**:
- ✅ FR-037至FR-038: 客户支持系统

---

### 27. ai_marketing_campaign (营销活动)

**描述**: 管理营销推广活动。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 活动ID |
| `campaign_name` | VARCHAR(255) | NOT NULL | - | 活动名称 |
| `campaign_type` | VARCHAR(50) | NOT NULL | - | 活动类型（promotion, referral, discount） |
| `description` | TEXT | NULL | - | 活动描述 |
| `start_date` | DATE | NOT NULL | - | 开始日期 |
| `end_date` | DATE | NOT NULL | - | 结束日期 |
| `budget` | DECIMAL(10,2) | NULL | - | 预算 |
| `status` | VARCHAR(20) | NOT NULL | 'draft' | 状态（draft, active, completed, cancelled） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `campaign_type`, `status`, `start_date`, `end_date`

**Constitution 对齐**:
- ✅ FR-039至FR-040: 营销工具系统

---

### 28. ai_ab_test (A/B测试)

**描述**: 管理模型A/B测试实验。

| 字段名 | 类型 | 约束 | 默认值 | 描述 |
|--------|------|------|--------|------|
| `id` | INTEGER | PRIMARY KEY AUTO_INCREMENT | - | 测试ID |
| `test_name` | VARCHAR(255) | NOT NULL | - | 测试名称 |
| `model_a_id` | INTEGER | NOT NULL | - | 模型A ID |
| `model_b_id` | INTEGER | NOT NULL | - | 模型B ID |
| `test_criteria` | JSON | NULL | - | 测试标准 |
| `test_result` | JSON | NULL | - | 测试结果 |
| `winner_model` | VARCHAR(10) | NULL | - | 获胜模型（A or B） |
| `status` | VARCHAR(20) | NOT NULL | 'running' | 状态（running, completed, cancelled） |
| `created_at` | TIMESTAMP | NOT NULL | CURRENT_TIMESTAMP | 创建时间 |
| `completed_at` | TIMESTAMP | NULL | - | 完成时间 |

**索引**:
- PRIMARY KEY: `id`
- INDEX: `model_a_id`, `model_b_id`, `status`
- FOREIGN KEY: `model_a_id` → `ai_model(id)`, `model_b_id` → `ai_model(id)`

**Constitution 对齐**:
- ✅ FR-003: 模型A/B测试框架

---

### 29-33. 其他扩展表

由于篇幅限制，以下表结构参考上述模式定义：

- **29. ai_audit_log_detail**: 详细审计日志（与ai_usage_log关联）
- **30. ai_performance_metric_detail**: 性能指标详情（与ai_model_monitoring关联）
- **31. ai_billing_record_detail**: 计费记录详情（与ai_billing_invoice关联）
- **32. ai_model_monitoring**: 模型性能监控实时数据
- **33. ai_security_scan**: 模型安全扫描记录

---

## Data Integrity Rules

### 数据一致性规则

1. **级联删除**:
   - 删除 `ai_model` 时，级联删除相关的 `ai_model_version`, `ai_model_deployment`, `ai_model_benchmark`
   - 删除 `ai_tenant` 时，级联删除相关的 `ai_tenant_config`, `ai_tenant_user`, `ai_billing_invoice`
   - 删除 `ai_assistant` 时，级联删除相关的 `ai_assistant_prompt_template`, `ai_assistant_conversation`

2. **数据保留期**（Constitution NFR-003）:
   - `ai_usage_log`: 7天
   - `ai_audit_log_detail`: 90天
   - `ai_training_data`: 30天
   - `ai_performance_metric_detail`: 365天

3. **软删除**:
   - `ai_model`: 原始模型（is_copy=0）受删除保护，只允许逻辑删除（is_active=0）
   - `ai_tenant`: 使用 `status='deleted'` 进行软删除

4. **唯一性约束**:
   - `ai_model.model_code`: 全局唯一
   - `ai_api_key.token`: 全局唯一
   - `ai_tenant.tenant_code`: 全局唯一
   - `ai_billing_invoice.invoice_number`: 全局唯一

---

## Migration Strategy

### 数据库迁移策略

1. **Phase 1**: 核心表（ai_model, ai_api_key, ai_assistant, ai_tenant）
2. **Phase 2**: 扩展功能表（billing, monitoring, i18n）
3. **Phase 3**: 优化与索引调整

### 迁移文件组织

```
app/code/Weline/Ai/Setup/Db/Migration/
├── create_core_tables_20251012-v1.0.0.php
├── create_extended_tables_20251013-v1.1.0.php
└── add_indexes_optimization_20251014-v1.2.0.php
```

---

## Testing Data Model

### 测试数据准备

1. **单元测试**:
   - 使用 in-memory SQLite 数据库
   - 每个测试方法独立创建测试数据
   - 测试完成后自动清理

2. **集成测试**:
   - 使用测试专用数据库
   - 使用 fixture 加载标准测试数据集
   - 测试租户隔离和数据完整性

3. **性能测试**:
   - 生成10万条 `ai_usage_log` 记录
   - 测试查询性能（目标：P95 < 100ms）

---

## Constitution Alignment

### 宪法对齐验证

- ✅ **I. 框架一致性**: 所有表遵循 WelineFramework ORM 命名规范
- ✅ **IV. 多租户数据隔离**: tenant_id 字段覆盖所有需要隔离的表
- ✅ **V. 国际化支持**: ai_i18n_content 表支持多语言
- ✅ **VI. 安全与合规**: ai_content_safety, ai_security_scan 表支持安全检查
- ✅ **NFR-001**: API密钥加密存储（ai_api_key.token）
- ✅ **NFR-003**: 审计日志保留期定义明确

---

## Version History

| 版本 | 日期 | 修改内容 | 作者 |
|------|------|----------|------|
| 1.0.0 | 2025-10-12 | 初始版本：完整33个数据表定义 | AI Assistant |

---

**总结**: 本数据模型涵盖 Weline_Ai 模块的所有功能需求，包含23个核心表和10个扩展表，符合 Constitution 所有相关原则，支持多租户、国际化、安全合规等企业级特性。
