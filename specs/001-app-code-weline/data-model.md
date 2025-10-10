# Data Model: Weline_Ai Module

**Date**: 2025-10-09  
**Purpose**: Define entities, fields, relationships, and validation rules

## Core Entities

### 1. ai_model
**Purpose**: AI模型元数据管理

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | INTEGER | PRIMARY KEY, AUTO_INCREMENT | 模型ID |
| supplier | VARCHAR(100) | NOT NULL | 供应商 (OpenAI, Anthropic, etc.) |
| model_code | VARCHAR(100) | NOT NULL, UNIQUE | 模型代码 (gpt-3.5-turbo, claude-3, etc.) |
| name | VARCHAR(255) | NOT NULL | 模型显示名称 |
| version | VARCHAR(50) | NOT NULL | 模型版本 |
| is_copy | BOOLEAN | NOT NULL, DEFAULT 0 | 是否为拷贝模型 |
| origin_model_id | INTEGER | NULLABLE, FK to ai_model.id | 原始模型ID (当is_copy=true时) |
| config | JSON | NULLABLE | 模型配置参数 |
| capabilities | JSON | NULLABLE | 模型能力描述 |
| max_tokens | INTEGER | NULLABLE | 最大token数 |
| cost_per_token | DECIMAL(10,6) | NULLABLE | 每token成本 |
| status | ENUM('active','deprecated','maintenance') | NOT NULL, DEFAULT 'active' | 模型状态 |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**Validation Rules**:
- 原始模型 (is_copy=false) 的 origin_model_id 必须为 NULL
- 拷贝模型 (is_copy=true) 的 origin_model_id 必须指向有效的原始模型
- model_code 在供应商范围内必须唯一
- cost_per_token 必须 >= 0

**State Transitions**:
- active → deprecated (模型弃用)
- deprecated → maintenance (维护模式)
- maintenance → active (恢复服务)

### 2. ai_api_key
**Purpose**: API密钥管理和访问控制

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | INTEGER | PRIMARY KEY, AUTO_INCREMENT | API密钥ID |
| name | VARCHAR(255) | NOT NULL | 密钥名称 |
| token | VARCHAR(255) | NOT NULL, UNIQUE | 加密后的API令牌 |
| user_id | INTEGER | NOT NULL, FK to users.id | 用户ID |
| tenant_id | INTEGER | NOT NULL, FK to ai_tenant.id | 租户ID |
| status | ENUM('pending','approved','suspended','revoked') | NOT NULL, DEFAULT 'pending' | 密钥状态 |
| quota_daily | INTEGER | NULLABLE | 每日配额限制 |
| quota_monthly | INTEGER | NULLABLE | 每月配额限制 |
| usage_daily | INTEGER | NOT NULL, DEFAULT 0 | 当日使用量 |
| usage_monthly | INTEGER | NOT NULL, DEFAULT 0 | 当月使用量 |
| last_used_at | TIMESTAMP | NULLABLE | 最后使用时间 |
| expires_at | TIMESTAMP | NULLABLE | 过期时间 |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**Validation Rules**:
- token 必须使用 SecretStore 加密存储
- quota_daily 和 quota_monthly 必须 > 0 (如果设置)
- expires_at 必须 > created_at (如果设置)
- 用户只能在自己的租户内创建API密钥

**State Transitions**:
- pending → approved (审核通过)
- approved → suspended (临时暂停)
- suspended → approved (恢复使用)
- approved → revoked (永久撤销)

### 3. ai_assistant
**Purpose**: AI助手定义和管理

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | INTEGER | PRIMARY KEY, AUTO_INCREMENT | 助手ID |
| name | VARCHAR(255) | NOT NULL | 助手名称 |
| description | TEXT | NULLABLE | 助手描述 |
| prompt_template | TEXT | NOT NULL | 提示词模板 |
| model_id | INTEGER | NOT NULL, FK to ai_model.id | 关联模型ID |
| user_id | INTEGER | NOT NULL, FK to users.id | 创建用户ID |
| tenant_id | INTEGER | NOT NULL, FK to ai_tenant.id | 租户ID |
| config | JSON | NULLABLE | 助手配置参数 |
| is_public | BOOLEAN | NOT NULL, DEFAULT 0 | 是否公开 |
| usage_count | INTEGER | NOT NULL, DEFAULT 0 | 使用次数 |
| status | ENUM('active','inactive','archived') | NOT NULL, DEFAULT 'active' | 助手状态 |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**Validation Rules**:
- prompt_template 不能为空
- 用户只能在自己的租户内创建助手
- 公开助手需要特殊权限

### 4. ai_tenant
**Purpose**: 多租户管理

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | INTEGER | PRIMARY KEY, AUTO_INCREMENT | 租户ID |
| name | VARCHAR(255) | NOT NULL | 租户名称 |
| domain | VARCHAR(255) | NULLABLE, UNIQUE | 租户域名 |
| config | JSON | NULLABLE | 租户配置 |
| quota_monthly | INTEGER | NULLABLE | 每月配额限制 |
| usage_monthly | INTEGER | NOT NULL, DEFAULT 0 | 当月使用量 |
| billing_plan | ENUM('free','basic','premium','enterprise') | NOT NULL, DEFAULT 'free' | 计费计划 |
| status | ENUM('active','suspended','cancelled') | NOT NULL, DEFAULT 'active' | 租户状态 |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 创建时间 |
| updated_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP ON UPDATE | 更新时间 |

**Validation Rules**:
- 租户名称必须唯一
- 域名必须唯一 (如果设置)
- 配额限制必须 > 0 (如果设置)

### 5. ai_model_monitoring
**Purpose**: 模型性能监控

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| id | INTEGER | PRIMARY KEY, AUTO_INCREMENT | 监控记录ID |
| model_id | INTEGER | NOT NULL, FK to ai_model.id | 模型ID |
| tenant_id | INTEGER | NOT NULL, FK to ai_tenant.id | 租户ID |
| request_count | INTEGER | NOT NULL, DEFAULT 0 | 请求次数 |
| success_count | INTEGER | NOT NULL, DEFAULT 0 | 成功次数 |
| error_count | INTEGER | NOT NULL, DEFAULT 0 | 错误次数 |
| avg_response_time | DECIMAL(10,3) | NULLABLE | 平均响应时间(秒) |
| p95_response_time | DECIMAL(10,3) | NULLABLE | P95响应时间(秒) |
| p99_response_time | DECIMAL(10,3) | NULLABLE | P99响应时间(秒) |
| total_cost | DECIMAL(10,6) | NOT NULL, DEFAULT 0 | 总成本 |
| date | DATE | NOT NULL | 统计日期 |
| created_at | TIMESTAMP | NOT NULL, DEFAULT CURRENT_TIMESTAMP | 创建时间 |

**Validation Rules**:
- 每个模型每天只能有一条监控记录
- 成功率 = success_count / request_count
- 响应时间必须 >= 0

## Relationships

### Primary Relationships
1. **ai_model** → **ai_model** (self-reference)
   - origin_model_id → id (拷贝模型指向原始模型)

2. **ai_api_key** → **ai_tenant**
   - tenant_id → id (API密钥属于租户)

3. **ai_assistant** → **ai_model**
   - model_id → id (助手关联模型)

4. **ai_assistant** → **ai_tenant**
   - tenant_id → id (助手属于租户)

5. **ai_model_monitoring** → **ai_model**
   - model_id → id (监控记录关联模型)

6. **ai_model_monitoring** → **ai_tenant**
   - tenant_id → id (监控记录属于租户)

### Indexes
```sql
-- Performance indexes
CREATE INDEX idx_ai_model_supplier_code ON ai_model(supplier, model_code);
CREATE INDEX idx_ai_model_is_copy ON ai_model(is_copy);
CREATE INDEX idx_ai_api_key_token ON ai_api_key(token);
CREATE INDEX idx_ai_api_key_tenant ON ai_api_key(tenant_id);
CREATE INDEX idx_ai_assistant_tenant ON ai_assistant(tenant_id);
CREATE INDEX idx_ai_model_monitoring_date ON ai_model_monitoring(date);

-- Unique constraints
CREATE UNIQUE INDEX idx_ai_model_supplier_code_unique ON ai_model(supplier, model_code);
CREATE UNIQUE INDEX idx_ai_api_key_token_unique ON ai_api_key(token);
CREATE UNIQUE INDEX idx_ai_tenant_domain_unique ON ai_tenant(domain);
```

## Data Retention Policies

### Retention Periods
- **审计日志**: 90天
- **模型训练数据**: 30天  
- **API调用日志**: 7天
- **性能监控数据**: 365天 (用于趋势分析)

### Cleanup Procedures
- 每日自动清理过期数据
- 保留关键统计数据用于报表
- 支持手动数据导出

## Migration Strategy

### Initial Setup
1. 创建所有核心表
2. 插入默认模型数据
3. 创建系统租户
4. 设置初始索引

### Version Management
- 使用 WelineFramework 的 Setup/Install.php 模式
- 支持增量迁移
- 提供回滚机制

## Security Considerations

### Data Encryption
- API密钥使用 SecretStore 加密
- 敏感配置字段加密存储
- 审计日志不可篡改

### Access Control
- 租户级别数据隔离
- 用户级别权限控制
- API密钥访问限制

### Compliance
- 支持数据导出 (GDPR)
- 审计日志完整性
- 数据保留策略执行