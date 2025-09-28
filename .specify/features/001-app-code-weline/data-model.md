# Data Model: AI助手工具模块

**Feature**: AI助手工具模块实现  
**Date**: 2024-12-19  
**Status**: Complete

## Entity Overview

基于功能规范分析，识别出以下核心实体：

### 1. AI Model (ai_model)
AI模型配置和管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `vendor` (VARCHAR(100)): 供应商名称 (OpenAI, Google, Anthropic等)
- `model_code` (VARCHAR(100)): 模型代码标识
- `model_name` (VARCHAR(255)): 模型显示名称
- `model_version` (VARCHAR(50)): 模型版本号
- `config_json` (TEXT): 配置JSON
- `token_price_input` (DECIMAL(10,6)): 输入Token价格
- `token_price_output` (DECIMAL(10,6)): 输出Token价格
- `proxy_info` (TEXT): 代理信息JSON
- `is_active` (INTEGER(1)): 是否激活
- `is_default` (INTEGER(1)): 是否默认
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 一对多: AiApiKey (一个模型可以有多个API密钥)
- 一对多: AiDefaultModel (一个模型可以被多个租户设为默认)

**Validation Rules**:
- `vendor` 不能为空
- `model_code` 必须唯一
- `token_price_input` 和 `token_price_output` 必须 >= 0
- `model_version` 格式: x.y.z

**State Transitions**:
- 创建 → 激活 → 停用
- 激活 → 设为默认 → 取消默认

### 2. Tenant (ai_tenant)
多租户管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `tenant_name` (VARCHAR(255)): 租户名称
- `tenant_code` (VARCHAR(100), UNIQUE): 租户代码
- `tenant_type` (VARCHAR(50)): 租户类型 (enterprise/individual/developer)
- `status` (VARCHAR(50)): 租户状态 (active/suspended/expired)
- `plan_type` (VARCHAR(50)): 订阅计划类型
- `resource_quota` (TEXT): 资源配额配置JSON
- `billing_info` (TEXT): 计费信息JSON
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 一对多: AiTenantUser (一个租户有多个用户)
- 一对多: AiBillingInvoice (一个租户有多个发票)
- 一对多: AiMobileDevice (一个租户有多个移动设备)

**Validation Rules**:
- `tenant_code` 必须唯一且符合命名规范
- `tenant_type` 必须是有效值
- `status` 必须是有效状态
- `resource_quota` 必须是有效JSON

**State Transitions**:
- 创建 → 激活 → 暂停 → 过期
- 暂停 → 激活 (恢复)
- 过期 → 激活 (续费)

### 3. Tenant User (ai_tenant_user)
租户用户关联

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `tenant_id` (INTEGER(11)): 租户ID
- `user_id` (INTEGER(11)): 用户ID
- `role` (VARCHAR(50)): 用户角色 (admin/member/viewer)
- `permissions` (TEXT): 权限配置JSON
- `is_active` (INTEGER(1)): 是否激活
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 多对一: AiTenant (多个用户属于一个租户)
- 多对一: User (系统用户表)

**Validation Rules**:
- `tenant_id` 和 `user_id` 组合必须唯一
- `role` 必须是有效角色
- `permissions` 必须是有效JSON数组

**State Transitions**:
- 邀请 → 激活 → 停用
- 停用 → 激活 (重新激活)

### 4. I18n Content (ai_i18n_ai_content)
国际化内容管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `content_type` (VARCHAR(50)): 内容类型 (prompt/response/error/label/message)
- `content_key` (VARCHAR(255)): 内容键
- `locale_code` (VARCHAR(10)): 语言代码
- `content_value` (TEXT): 内容值
- `context` (VARCHAR(255)): 上下文
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 无直接关系，通过content_key关联

**Validation Rules**:
- `content_key` 和 `locale_code` 组合必须唯一
- `locale_code` 必须是有效语言代码
- `content_type` 必须是有效类型

### 5. Mobile Device (ai_mobile_device)
移动端设备管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `user_id` (INTEGER(11)): 用户ID
- `device_id` (VARCHAR(255)): 设备ID
- `device_type` (VARCHAR(50)): 设备类型 (ios/android/web/desktop)
- `push_token` (VARCHAR(500)): 推送令牌
- `device_info` (TEXT): 设备信息JSON
- `is_active` (INTEGER(1)): 是否激活
- `last_active` (INTEGER(11)): 最后活跃时间
- `created_time` (INTEGER(11)): 创建时间

**Relationships**:
- 多对一: User (多个设备属于一个用户)
- 一对多: AiMobileNotification (一个设备有多个通知)

**Validation Rules**:
- `user_id` 和 `device_id` 组合必须唯一
- `device_type` 必须是有效类型
- `push_token` 格式验证

### 6. Mobile Notification (ai_mobile_notification)
移动端通知管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `user_id` (INTEGER(11)): 用户ID
- `device_id` (VARCHAR(255)): 设备ID
- `notification_type` (VARCHAR(50)): 通知类型
- `title` (VARCHAR(255)): 通知标题
- `content` (TEXT): 通知内容
- `data` (TEXT): 通知数据JSON
- `status` (VARCHAR(50)): 通知状态
- `sent_time` (INTEGER(11)): 发送时间
- `created_time` (INTEGER(11)): 创建时间

**Relationships**:
- 多对一: User (多个通知属于一个用户)
- 多对一: AiMobileDevice (多个通知发送到一个设备)

**Validation Rules**:
- `notification_type` 必须是有效类型
- `status` 必须是有效状态
- `data` 必须是有效JSON

### 7. Billing Plan (ai_billing_plan)
计费计划管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `plan_name` (VARCHAR(255)): 计划名称
- `plan_type` (VARCHAR(50)): 计划类型 (free/paid/enterprise)
- `price` (DECIMAL(10,2)): 价格
- `currency` (VARCHAR(3)): 货币
- `billing_cycle` (VARCHAR(20)): 计费周期
- `features` (TEXT): 功能列表JSON
- `limits` (TEXT): 限制配置JSON
- `is_active` (INTEGER(1)): 是否激活
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 一对多: AiTenant (多个租户使用一个计划)

**Validation Rules**:
- `plan_name` 不能为空
- `plan_type` 必须是有效类型
- `price` 必须 >= 0
- `currency` 必须是有效货币代码

### 8. Billing Invoice (ai_billing_invoice)
计费发票管理

**Fields**:
- `id` (INTEGER, PRIMARY KEY): 主键
- `tenant_id` (INTEGER(11)): 租户ID
- `invoice_number` (VARCHAR(100), UNIQUE): 发票号
- `amount` (DECIMAL(10,2)): 金额
- `currency` (VARCHAR(3)): 货币
- `status` (VARCHAR(50)): 发票状态
- `due_date` (INTEGER(11)): 到期日期
- `paid_date` (INTEGER(11)): 支付日期
- `payment_method` (VARCHAR(50)): 支付方式
- `transaction_id` (VARCHAR(255)): 交易ID
- `items` (TEXT): 发票项目JSON
- `tax_amount` (DECIMAL(10,2)): 税费金额
- `discount_amount` (DECIMAL(10,2)): 折扣金额
- `total_amount` (DECIMAL(10,2)): 总金额
- `created_time` (INTEGER(11)): 创建时间
- `updated_time` (INTEGER(11)): 更新时间

**Relationships**:
- 多对一: AiTenant (多个发票属于一个租户)

**Validation Rules**:
- `invoice_number` 必须唯一
- `amount` 必须 > 0
- `status` 必须是有效状态
- `total_amount` = `amount` + `tax_amount` - `discount_amount`

## Database Schema

### Indexes
```sql
-- AI Model indexes
CREATE INDEX idx_ai_model_vendor ON ai_model(vendor);
CREATE INDEX idx_ai_model_code ON ai_model(model_code);
CREATE INDEX idx_ai_model_active ON ai_model(is_active);

-- Tenant indexes
CREATE INDEX idx_ai_tenant_code ON ai_tenant(tenant_code);
CREATE INDEX idx_ai_tenant_type ON ai_tenant(tenant_type);
CREATE INDEX idx_ai_tenant_status ON ai_tenant(status);

-- Tenant User indexes
CREATE INDEX idx_ai_tenant_user_tenant ON ai_tenant_user(tenant_id);
CREATE INDEX idx_ai_tenant_user_user ON ai_tenant_user(user_id);
CREATE INDEX idx_ai_tenant_user_role ON ai_tenant_user(role);

-- I18n Content indexes
CREATE INDEX idx_ai_i18n_content_key ON ai_i18n_ai_content(content_key);
CREATE INDEX idx_ai_i18n_content_locale ON ai_i18n_ai_content(locale_code);
CREATE INDEX idx_ai_i18n_content_type ON ai_i18n_ai_content(content_type);

-- Mobile Device indexes
CREATE INDEX idx_ai_mobile_device_user ON ai_mobile_device(user_id);
CREATE INDEX idx_ai_mobile_device_type ON ai_mobile_device(device_type);
CREATE INDEX idx_ai_mobile_device_active ON ai_mobile_device(is_active);

-- Mobile Notification indexes
CREATE INDEX idx_ai_mobile_notification_user ON ai_mobile_notification(user_id);
CREATE INDEX idx_ai_mobile_notification_status ON ai_mobile_notification(status);
CREATE INDEX idx_ai_mobile_notification_created ON ai_mobile_notification(created_time);

-- Billing Plan indexes
CREATE INDEX idx_ai_billing_plan_type ON ai_billing_plan(plan_type);
CREATE INDEX idx_ai_billing_plan_active ON ai_billing_plan(is_active);

-- Billing Invoice indexes
CREATE INDEX idx_ai_billing_invoice_tenant ON ai_billing_invoice(tenant_id);
CREATE INDEX idx_ai_billing_invoice_status ON ai_billing_invoice(status);
CREATE INDEX idx_ai_billing_invoice_due ON ai_billing_invoice(due_date);
```

### Constraints
```sql
-- Foreign Key Constraints
ALTER TABLE ai_tenant_user ADD CONSTRAINT fk_tenant_user_tenant 
    FOREIGN KEY (tenant_id) REFERENCES ai_tenant(id) ON DELETE CASCADE;

ALTER TABLE ai_mobile_device ADD CONSTRAINT fk_mobile_device_user 
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE ai_mobile_notification ADD CONSTRAINT fk_notification_user 
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE ai_billing_invoice ADD CONSTRAINT fk_invoice_tenant 
    FOREIGN KEY (tenant_id) REFERENCES ai_tenant(id) ON DELETE CASCADE;

-- Unique Constraints
ALTER TABLE ai_tenant ADD CONSTRAINT uk_tenant_code UNIQUE (tenant_code);
ALTER TABLE ai_tenant_user ADD CONSTRAINT uk_tenant_user UNIQUE (tenant_id, user_id);
ALTER TABLE ai_mobile_device ADD CONSTRAINT uk_user_device UNIQUE (user_id, device_id);
ALTER TABLE ai_i18n_ai_content ADD CONSTRAINT uk_content_locale UNIQUE (content_key, locale_code);
ALTER TABLE ai_billing_invoice ADD CONSTRAINT uk_invoice_number UNIQUE (invoice_number);
```

## Data Validation Rules

### Business Rules
1. **租户隔离**: 所有数据查询必须包含租户ID过滤
2. **权限控制**: 用户只能访问所属租户的数据
3. **资源配额**: 租户使用量不能超过配额限制
4. **计费规则**: 发票金额必须与使用量匹配
5. **多语言**: 内容键必须存在默认语言版本

### Technical Rules
1. **数据完整性**: 外键约束确保数据一致性
2. **唯一性**: 业务键必须唯一
3. **非空约束**: 必填字段不能为空
4. **格式验证**: 邮箱、电话等格式验证
5. **长度限制**: 字符串字段长度限制

## Performance Considerations

### Query Optimization
- 所有查询都包含适当的索引
- 分页查询使用LIMIT和OFFSET
- 复杂查询使用EXPLAIN分析
- 避免N+1查询问题

### Caching Strategy
- 模型配置缓存
- 租户信息缓存
- 翻译内容缓存
- 用户权限缓存

### Data Archiving
- 历史数据归档策略
- 日志数据清理规则
- 备份和恢复策略
- 数据迁移计划

## Security Considerations

### Data Protection
- 敏感数据加密存储
- 传输过程加密
- 访问日志记录
- 数据脱敏处理

### Access Control
- 基于角色的权限控制
- 租户数据隔离
- API访问控制
- 操作审计日志

### Compliance
- 数据保护法规遵循
- 隐私政策实施
- 数据保留政策
- 安全事件响应
