# AI助手租赁生态系统设计

## 1. 业务模型

### 1.1 核心概念

**用户（User）**
- 可以创建助手（成为所有者）
- 可以租用别人的助手（成为租用者）
- 可以获得租赁收入
- 可以对租用的助手评分

**租户（Tenant）**
- 组织级别的账户
- 管理多个用户
- 统一的配额和计费

**助手（Assistant）**
- 所有者：创建助手的用户
- 可租用状态：公开/私有
- 租赁类型：按次/按天/按月/永久
- 定价策略

**API密钥**
- 用户级别或租户级别
- 用于调用AI服务
- 配额和计费管理

### 1.2 业务流程

```
助手创建流程：
用户 → 创建助手 → 设置可租用 → 设定价格 → 上架

助手租赁流程：
用户B → 浏览助手 → 选择租用 → 支付费用 → 获得使用权

助手使用流程：
用户B → 使用助手 → 消耗配额 → 记录使用

助手评分流程：
用户B → 使用后评分 → 提交评论 → 更新助手评分

收入统计流程：
系统 → 统计租赁收入 → 计算排行 → 分成结算
```

## 2. 数据模型设计

### 2.1 AiAssistant（助手表 - 扩展字段）

**新增字段**：
```sql
-- 所有权和租赁设置
owner_id INT NOT NULL COMMENT '所有者用户ID',
is_rentable TINYINT(1) DEFAULT 0 COMMENT '是否可租用：0=私有，1=可租用',
rental_type ENUM('per_use', 'daily', 'monthly', 'permanent') DEFAULT 'per_use' COMMENT '租赁类型',
rental_price DECIMAL(10,4) DEFAULT 0.0000 COMMENT '租赁价格',
rental_currency VARCHAR(10) DEFAULT 'USD' COMMENT '货币类型',

-- 统计数据
rating_average DECIMAL(3,2) DEFAULT 0.00 COMMENT '平均评分（0-5）',
rating_count INT DEFAULT 0 COMMENT '评分数量',
rental_count INT DEFAULT 0 COMMENT '累计租赁次数',
usage_count INT DEFAULT 0 COMMENT '累计使用次数',
revenue_total DECIMAL(12,4) DEFAULT 0.0000 COMMENT '累计收入',

-- 展示信息
cover_image VARCHAR(255) COMMENT '封面图片',
tags JSON COMMENT '标签',
category VARCHAR(50) COMMENT '分类',

-- 审核状态
audit_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT '审核状态',
audit_note TEXT COMMENT '审核备注',

INDEX idx_owner (owner_id),
INDEX idx_rentable (is_rentable, audit_status),
INDEX idx_rating (rating_average),
INDEX idx_category (category)
```

### 2.2 AiAssistantRental（助手租赁记录表）

```sql
CREATE TABLE ai_assistant_rental (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 关联信息
    assistant_id INT NOT NULL COMMENT '助手ID',
    owner_id INT NOT NULL COMMENT '所有者用户ID',
    renter_id INT NOT NULL COMMENT '租用者用户ID',
    tenant_id INT COMMENT '租用者租户ID',
    
    -- 租赁信息
    rental_type ENUM('per_use', 'daily', 'monthly', 'permanent') NOT NULL COMMENT '租赁类型',
    price DECIMAL(10,4) NOT NULL COMMENT '租赁价格',
    currency VARCHAR(10) DEFAULT 'USD',
    
    -- 时间和状态
    start_time DATETIME NOT NULL COMMENT '开始时间',
    end_time DATETIME COMMENT '结束时间（永久租赁为NULL）',
    status ENUM('active', 'expired', 'cancelled', 'refunded') DEFAULT 'active' COMMENT '状态',
    
    -- 使用统计
    usage_count INT DEFAULT 0 COMMENT '使用次数',
    usage_limit INT COMMENT '使用次数限制（NULL=无限制）',
    
    -- 支付信息
    payment_method VARCHAR(50) COMMENT '支付方式',
    payment_transaction_id VARCHAR(100) COMMENT '支付交易ID',
    payment_time DATETIME COMMENT '支付时间',
    
    -- 分成信息
    platform_commission_rate DECIMAL(5,4) DEFAULT 0.1000 COMMENT '平台分成比例',
    owner_revenue DECIMAL(10,4) COMMENT '所有者收入',
    platform_revenue DECIMAL(10,4) COMMENT '平台收入',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_assistant (assistant_id),
    INDEX idx_owner (owner_id),
    INDEX idx_renter (renter_id),
    INDEX idx_status (status),
    INDEX idx_time (start_time, end_time),
    
    FOREIGN KEY (assistant_id) REFERENCES ai_assistant(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES frontend_user(id) ON DELETE CASCADE,
    FOREIGN KEY (renter_id) REFERENCES frontend_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='助手租赁记录表';
```

### 2.3 AiAssistantRating（助手评分表）

```sql
CREATE TABLE ai_assistant_rating (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 关联信息
    assistant_id INT NOT NULL COMMENT '助手ID',
    user_id INT NOT NULL COMMENT '评分用户ID',
    rental_id INT COMMENT '租赁记录ID',
    
    -- 评分信息
    rating TINYINT NOT NULL COMMENT '评分（1-5星）',
    comment TEXT COMMENT '评论内容',
    
    -- 评分维度（可选）
    accuracy_rating TINYINT COMMENT '准确度评分',
    speed_rating TINYINT COMMENT '速度评分',
    usefulness_rating TINYINT COMMENT '实用性评分',
    
    -- 状态和审核
    status ENUM('visible', 'hidden', 'reported') DEFAULT 'visible' COMMENT '状态',
    is_verified TINYINT(1) DEFAULT 0 COMMENT '是否已验证（真实租用者）',
    
    -- 互动统计
    helpful_count INT DEFAULT 0 COMMENT '有帮助数',
    report_count INT DEFAULT 0 COMMENT '举报数',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_assistant (assistant_id),
    INDEX idx_user (user_id),
    INDEX idx_rating (rating),
    INDEX idx_created (created_time),
    
    UNIQUE KEY uk_user_assistant (user_id, assistant_id),
    
    FOREIGN KEY (assistant_id) REFERENCES ai_assistant(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE,
    FOREIGN KEY (rental_id) REFERENCES ai_assistant_rental(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='助手评分表';
```

### 2.4 AiAssistantRevenue（助手收入统计表）

```sql
CREATE TABLE ai_assistant_revenue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 统计维度
    user_id INT NOT NULL COMMENT '用户ID（所有者）',
    assistant_id INT COMMENT '助手ID（NULL表示用户总计）',
    period_type ENUM('daily', 'monthly', 'yearly', 'total') NOT NULL COMMENT '统计周期',
    period_date DATE NOT NULL COMMENT '统计日期',
    
    -- 收入数据
    rental_count INT DEFAULT 0 COMMENT '租赁次数',
    usage_count INT DEFAULT 0 COMMENT '使用次数',
    gross_revenue DECIMAL(12,4) DEFAULT 0.0000 COMMENT '总收入',
    platform_commission DECIMAL(12,4) DEFAULT 0.0000 COMMENT '平台分成',
    net_revenue DECIMAL(12,4) DEFAULT 0.0000 COMMENT '净收入',
    
    -- 用户统计
    new_renters INT DEFAULT 0 COMMENT '新增租用者',
    active_renters INT DEFAULT 0 COMMENT '活跃租用者',
    
    -- 评分统计
    rating_average DECIMAL(3,2) DEFAULT 0.00 COMMENT '平均评分',
    rating_count INT DEFAULT 0 COMMENT '评分数量',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_assistant (assistant_id),
    INDEX idx_period (period_type, period_date),
    INDEX idx_revenue (net_revenue DESC),
    
    UNIQUE KEY uk_stats (user_id, assistant_id, period_type, period_date),
    
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE,
    FOREIGN KEY (assistant_id) REFERENCES ai_assistant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='助手收入统计表';
```

### 2.5 AiAssistantUsageLog（助手使用日志表）

```sql
CREATE TABLE ai_assistant_usage_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- 关联信息
    assistant_id INT NOT NULL COMMENT '助手ID',
    rental_id INT COMMENT '租赁记录ID',
    user_id INT NOT NULL COMMENT '使用者用户ID',
    
    -- 使用信息
    request_content TEXT COMMENT '请求内容',
    response_content TEXT COMMENT '响应内容',
    tokens_used INT COMMENT '使用的Token数',
    cost DECIMAL(10,6) COMMENT '费用',
    
    -- 性能数据
    response_time INT COMMENT '响应时间（毫秒）',
    status ENUM('success', 'failed', 'timeout') DEFAULT 'success' COMMENT '状态',
    error_message TEXT COMMENT '错误信息',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_assistant (assistant_id),
    INDEX idx_rental (rental_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_time),
    
    FOREIGN KEY (assistant_id) REFERENCES ai_assistant(id) ON DELETE CASCADE,
    FOREIGN KEY (rental_id) REFERENCES ai_assistant_rental(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='助手使用日志表';
```

## 3. 功能模块设计

### 3.1 助手市场（Assistant Marketplace）

**前端页面**：
- 助手浏览列表（支持筛选、排序）
- 助手详情页（包含评分、评论、使用案例）
- 租赁购买页
- 我的租赁管理

**后端功能**：
- 助手上架/下架
- 租赁支付处理
- 租赁状态管理
- 使用权限验证

### 3.2 评分系统

**功能**：
- 五星评分
- 评论内容
- 评分维度（准确度、速度、实用性）
- 评论互动（有帮助、举报）
- 评论审核

### 3.3 收入统计和排行

**用户收入面板**：
- 总收入统计
- 各助手收入明细
- 租赁趋势图表
- 收入排行榜

**统计维度**：
- 按天/月/年统计
- 按助手统计
- 按租赁类型统计

### 3.4 用户管理增强

**新增功能**：
- 查看用户拥有的助手
- 查看用户租用的助手
- 查看用户收入统计
- 查看用户在租户中的角色
- 查看租户租用的助手列表

## 4. API设计

### 4.1 助手租赁API

```php
// 获取可租用助手列表
GET /ai/api/assistant/marketplace?category=&rating_min=&price_max=&sort=

// 获取助手详情
GET /ai/api/assistant/{id}/detail

// 租用助手
POST /ai/api/assistant/{id}/rent
{
    "rental_type": "monthly",
    "payment_method": "alipay"
}

// 取消租赁
POST /ai/api/assistant/rental/{rental_id}/cancel

// 获取我的租赁列表
GET /ai/api/assistant/my-rentals?status=active
```

### 4.2 评分API

```php
// 提交评分
POST /ai/api/assistant/{id}/rating
{
    "rating": 5,
    "comment": "非常好用",
    "accuracy_rating": 5,
    "speed_rating": 4,
    "usefulness_rating": 5
}

// 获取助手评分列表
GET /ai/api/assistant/{id}/ratings?page=1&limit=20

// 标记评论有帮助
POST /ai/api/assistant/rating/{id}/helpful
```

### 4.3 收入API

```php
// 获取用户收入统计
GET /ai/api/user/revenue?period=monthly&start_date=&end_date=

// 获取助手收入明细
GET /ai/api/assistant/{id}/revenue?period=daily

// 获取收入排行榜
GET /ai/api/revenue/ranking?period=monthly&limit=100
```

## 5. 权限控制

### 5.1 助手权限

```php
class AssistantPermission
{
    // 只有所有者可以修改
    canEdit(user, assistant) -> boolean
    
    // 只有所有者可以设置租赁
    canSetRentable(user, assistant) -> boolean
    
    // 租用者或所有者可以使用
    canUse(user, assistant) -> boolean
    
    // 只有租用者可以评分
    canRate(user, assistant) -> boolean
}
```

### 5.2 租户权限

```php
class TenantPermission
{
    // 租户管理员可以查看成员租用情况
    canViewMemberRentals(user, tenant) -> boolean
    
    // 租户管理员可以统一租用助手
    canRentForTenant(user, tenant) -> boolean
}
```

## 6. 实施计划

### Phase 1: 基础数据模型
1. 扩展AiAssistant表字段
2. 创建AiAssistantRental表
3. 创建AiAssistantRating表
4. 创建AiAssistantRevenue表
5. 创建AiAssistantUsageLog表

### Phase 2: 助手租赁功能
1. 助手上架设置（可租用、定价）
2. 助手市场浏览
3. 租赁购买流程
4. 使用权限验证

### Phase 3: 评分系统
1. 评分提交
2. 评分展示
3. 评论互动
4. 评分统计

### Phase 4: 收入统计
1. 收入数据统计
2. 收入面板展示
3. 排行榜功能
4. 收入报表

### Phase 5: 用户管理增强
1. 用户助手管理
2. 用户租赁管理
3. 用户收入查看
4. 租户关联查看

## 7. 技术要点

### 7.1 支付集成
- 支持支付宝
- 支持微信支付
- 支持PayPal（国际）
- 支持账户余额

### 7.2 权限验证
- 使用权验证（中间件）
- 配额检查
- 租赁状态检查

### 7.3 收入结算
- 自动分成计算
- 定期结算
- 提现管理

### 7.4 缓存优化
- 助手列表缓存
- 评分统计缓存
- 排行榜缓存

## 8. 业务规则

### 8.1 租赁规则
- 按次：单次使用，用完即止
- 按天：24小时内无限使用
- 按月：30天内无限使用
- 永久：永久使用权

### 8.2 评分规则
- 只有实际租用并使用过的用户才能评分
- 每个用户每个助手只能评分一次
- 可以修改评分
- 恶意评分可被举报

### 8.3 收入规则
- 平台分成比例：默认10%
- 所有者收入：90%
- 结算周期：每月一次
- 最低提现金额：100元

### 8.4 审核规则
- 新上架助手需审核
- 评论内容需审核（可选）
- 举报内容需人工审核

