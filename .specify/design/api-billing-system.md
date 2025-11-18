# API计费系统设计

## 1. 业务模型

### 1.1 核心概念

**用户账户（User Account）**
- 账户余额（balance）
- 充值记录
- 消费记录
- 账单统计

**API密钥（API Key）**
- 用户创建的调用凭证
- 每个密钥可设置自己的配额限制
- 配额是成本控制，不是预付费
- 密钥状态管理

**API调用（API Call）**
- 选择模型
- 发送请求
- 消耗Token
- 扣除余额

**计费规则**
- 按Token计费
- 不同模型不同价格
- 输入Token和输出Token分别计价
- 实时扣费

### 1.2 业务流程

```
用户充值流程：
用户 → 选择充值金额 → 支付 → 到账 → 更新余额

API密钥创建流程：
用户 → 创建密钥 → 设置配额限制 → 生成密钥 → 可以调用

API调用流程：
用户 → 使用API密钥调用 → 检查余额 → 调用模型 → 统计Token → 扣除费用 → 返回结果

余额不足：
用户 → API调用 → 检查余额不足 → 拒绝调用 → 提示充值
```

## 2. 数据模型设计

### 2.1 FrontendUser（用户表 - 扩展字段）

```sql
ALTER TABLE frontend_user ADD COLUMN balance DECIMAL(12,4) DEFAULT 0.0000 COMMENT '账户余额';
ALTER TABLE frontend_user ADD COLUMN total_recharge DECIMAL(12,4) DEFAULT 0.0000 COMMENT '累计充值';
ALTER TABLE frontend_user ADD COLUMN total_consumption DECIMAL(12,4) DEFAULT 0.0000 COMMENT '累计消费';
ALTER TABLE frontend_user ADD COLUMN currency VARCHAR(10) DEFAULT 'CNY' COMMENT '货币类型';

CREATE INDEX idx_balance ON frontend_user(balance);
```

### 2.2 AiApiKey（API密钥表 - 扩展字段）

```sql
-- 现有字段保留，新增/修改：
ALTER TABLE ai_api_key MODIFY COLUMN quota_daily INT COMMENT '每日配额限制（成本控制，单位：元）';
ALTER TABLE ai_api_key MODIFY COLUMN quota_monthly INT COMMENT '每月配额限制（成本控制，单位：元）';
ALTER TABLE ai_api_key ADD COLUMN usage_daily DECIMAL(10,4) DEFAULT 0.0000 COMMENT '今日已使用额度';
ALTER TABLE ai_api_key ADD COLUMN usage_monthly DECIMAL(10,4) DEFAULT 0.0000 COMMENT '本月已使用额度';
ALTER TABLE ai_api_key ADD COLUMN last_used_time DATETIME COMMENT '最后使用时间';
ALTER TABLE ai_api_key ADD COLUMN call_count INT DEFAULT 0 COMMENT '累计调用次数';
```

### 2.3 AiUserRecharge（用户充值记录表）

```sql
CREATE TABLE ai_user_recharge (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 用户信息
    user_id INT NOT NULL COMMENT '用户ID',
    tenant_id INT COMMENT '租户ID',
    
    -- 充值信息
    amount DECIMAL(12,4) NOT NULL COMMENT '充值金额',
    currency VARCHAR(10) DEFAULT 'CNY' COMMENT '货币类型',
    
    -- 支付信息
    payment_method ENUM('alipay', 'wechat', 'bank', 'paypal', 'balance') NOT NULL COMMENT '支付方式',
    payment_transaction_id VARCHAR(100) COMMENT '支付交易ID',
    payment_status ENUM('pending', 'success', 'failed', 'refunded') DEFAULT 'pending' COMMENT '支付状态',
    payment_time DATETIME COMMENT '支付时间',
    
    -- 到账信息
    balance_before DECIMAL(12,4) COMMENT '充值前余额',
    balance_after DECIMAL(12,4) COMMENT '充值后余额',
    
    -- 优惠信息
    bonus_amount DECIMAL(12,4) DEFAULT 0.0000 COMMENT '赠送金额',
    promotion_id INT COMMENT '优惠活动ID',
    
    -- 备注
    remark TEXT COMMENT '备注',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_status (payment_status),
    INDEX idx_time (created_time),
    
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户充值记录表';
```

### 2.4 AiApiCallLog（API调用日志表）

```sql
CREATE TABLE ai_api_call_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- 调用信息
    api_key_id INT NOT NULL COMMENT 'API密钥ID',
    user_id INT NOT NULL COMMENT '用户ID',
    request_id VARCHAR(64) NOT NULL COMMENT '请求ID（唯一）',
    
    -- 模型信息
    model_id INT NOT NULL COMMENT '模型ID',
    model_code VARCHAR(100) NOT NULL COMMENT '模型代码',
    
    -- 请求信息
    endpoint VARCHAR(100) COMMENT '接口端点',
    request_method VARCHAR(10) COMMENT '请求方法',
    request_ip VARCHAR(50) COMMENT '请求IP',
    
    -- Token统计
    prompt_tokens INT DEFAULT 0 COMMENT '输入Token数',
    completion_tokens INT DEFAULT 0 COMMENT '输出Token数',
    total_tokens INT DEFAULT 0 COMMENT '总Token数',
    
    -- 计费信息
    prompt_cost DECIMAL(10,6) DEFAULT 0.000000 COMMENT '输入成本',
    completion_cost DECIMAL(10,6) DEFAULT 0.000000 COMMENT '输出成本',
    total_cost DECIMAL(10,6) DEFAULT 0.000000 COMMENT '总成本',
    
    -- 余额信息
    balance_before DECIMAL(12,4) COMMENT '调用前余额',
    balance_after DECIMAL(12,4) COMMENT '调用后余额',
    
    -- 响应信息
    response_status INT COMMENT '响应状态码',
    response_time INT COMMENT '响应时间（毫秒）',
    status ENUM('success', 'failed', 'timeout', 'insufficient_balance') DEFAULT 'success' COMMENT '调用状态',
    error_message TEXT COMMENT '错误信息',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_api_key (api_key_id),
    INDEX idx_user (user_id),
    INDEX idx_request (request_id),
    INDEX idx_model (model_id),
    INDEX idx_time (created_time),
    INDEX idx_status (status),
    
    FOREIGN KEY (api_key_id) REFERENCES ai_api_key(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE,
    FOREIGN KEY (model_id) REFERENCES ai_model(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API调用日志表';
```

### 2.5 AiUserBill（用户账单表）

```sql
CREATE TABLE ai_user_bill (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- 用户信息
    user_id INT NOT NULL COMMENT '用户ID',
    
    -- 账单周期
    bill_type ENUM('daily', 'monthly') NOT NULL COMMENT '账单类型',
    bill_date DATE NOT NULL COMMENT '账单日期',
    
    -- 统计数据
    call_count INT DEFAULT 0 COMMENT '调用次数',
    total_tokens INT DEFAULT 0 COMMENT '总Token数',
    total_cost DECIMAL(12,4) DEFAULT 0.0000 COMMENT '总费用',
    
    -- 模型统计（JSON格式）
    model_stats JSON COMMENT '各模型使用统计',
    
    -- 充值信息
    recharge_count INT DEFAULT 0 COMMENT '充值次数',
    recharge_amount DECIMAL(12,4) DEFAULT 0.0000 COMMENT '充值金额',
    
    -- 余额信息
    balance_start DECIMAL(12,4) COMMENT '期初余额',
    balance_end DECIMAL(12,4) COMMENT '期末余额',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_bill (bill_type, bill_date),
    
    UNIQUE KEY uk_user_bill (user_id, bill_type, bill_date),
    
    FOREIGN KEY (user_id) REFERENCES frontend_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户账单表';
```

### 2.6 AiRechargePromotion（充值优惠活动表）

```sql
CREATE TABLE ai_recharge_promotion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 活动信息
    name VARCHAR(100) NOT NULL COMMENT '活动名称',
    description TEXT COMMENT '活动描述',
    
    -- 优惠规则
    min_amount DECIMAL(10,4) NOT NULL COMMENT '最小充值金额',
    bonus_type ENUM('fixed', 'percentage') NOT NULL COMMENT '赠送类型：固定金额/百分比',
    bonus_value DECIMAL(10,4) NOT NULL COMMENT '赠送值',
    max_bonus DECIMAL(10,4) COMMENT '最大赠送金额',
    
    -- 活动时间
    start_time DATETIME NOT NULL COMMENT '开始时间',
    end_time DATETIME NOT NULL COMMENT '结束时间',
    
    -- 使用限制
    usage_limit INT COMMENT '总使用次数限制',
    usage_count INT DEFAULT 0 COMMENT '已使用次数',
    user_limit INT COMMENT '每个用户限制次数',
    
    -- 状态
    is_active TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    
    -- 时间戳
    created_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_active (is_active, start_time, end_time),
    INDEX idx_time (start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='充值优惠活动表';
```

## 3. API接口设计

### 3.1 充值相关API

```php
// 获取充值套餐列表
GET /ai/api/recharge/packages

// 创建充值订单
POST /ai/api/recharge/create
{
    "amount": 100.00,
    "payment_method": "alipay",
    "promotion_id": 1
}

// 获取支付二维码/链接
GET /ai/api/recharge/{order_id}/payment

// 查询支付状态
GET /ai/api/recharge/{order_id}/status

// 获取充值记录
GET /ai/api/recharge/history?page=1&limit=20
```

### 3.2 余额相关API

```php
// 获取账户余额
GET /ai/api/account/balance

// 获取消费统计
GET /ai/api/account/statistics?period=monthly

// 获取账单
GET /ai/api/account/bills?year=2025&month=1

// 下载账单
GET /ai/api/account/bills/{bill_id}/download
```

### 3.3 API密钥相关API

```php
// 创建API密钥（前面已有，增强配额字段）
POST /ai/api/key/create
{
    "name": "我的API密钥",
    "quota_daily": 100.00,     // 每日消费限额（元）
    "quota_monthly": 3000.00   // 每月消费限额（元）
}

// 查看密钥使用情况
GET /ai/api/key/{key_id}/usage?period=daily
```

### 3.4 AI服务调用API

```php
// 文本生成（类似OpenAI API）
POST /ai/api/v1/chat/completions
Headers: Authorization: Bearer {api_key}
{
    "model": "gpt-4",
    "messages": [
        {"role": "user", "content": "Hello"}
    ],
    "temperature": 0.7,
    "max_tokens": 1000
}

Response:
{
    "id": "chatcmpl-xxx",
    "model": "gpt-4",
    "usage": {
        "prompt_tokens": 10,
        "completion_tokens": 50,
        "total_tokens": 60
    },
    "cost": {
        "prompt_cost": 0.0003,
        "completion_cost": 0.0015,
        "total_cost": 0.0018
    },
    "choices": [...]
}

// 使用助手（增强版）
POST /ai/api/v1/assistant/chat
Headers: Authorization: Bearer {api_key}
{
    "assistant_id": 123,
    "message": "Hello",
    "stream": false
}
```

## 4. 计费逻辑

### 4.1 计费规则

```php
class BillingService
{
    // 计算费用
    public function calculateCost(
        string $modelCode,
        int $promptTokens,
        int $completionTokens
    ): array {
        $model = $this->getModel($modelCode);
        
        // 输入Token费用
        $promptCost = ($promptTokens / 1000) * $model->input_token_price;
        
        // 输出Token费用
        $completionCost = ($completionTokens / 1000) * $model->output_token_price;
        
        // 总费用
        $totalCost = $promptCost + $completionCost;
        
        return [
            'prompt_cost' => $promptCost,
            'completion_cost' => $completionCost,
            'total_cost' => $totalCost
        ];
    }
    
    // 扣除费用
    public function deductBalance(int $userId, float $amount): bool
    {
        // 使用数据库事务
        DB::beginTransaction();
        try {
            $user = User::lockForUpdate()->find($userId);
            
            if ($user->balance < $amount) {
                throw new InsufficientBalanceException();
            }
            
            $user->balance -= $amount;
            $user->total_consumption += $amount;
            $user->save();
            
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
```

### 4.2 API调用中间件

```php
class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 1. 验证API密钥
        $apiKey = $this->extractApiKey($request);
        if (!$apiKey || !$apiKey->isValid()) {
            return $this->error('Invalid API key');
        }
        
        // 2. 检查用户余额
        $user = $apiKey->user;
        if ($user->balance <= 0) {
            return $this->error('Insufficient balance');
        }
        
        // 3. 检查配额限制
        if (!$this->checkQuota($apiKey)) {
            return $this->error('Quota exceeded');
        }
        
        // 4. 记录请求开始
        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('start_time', microtime(true));
        
        return $next($request);
    }
    
    private function checkQuota(ApiKey $apiKey): bool
    {
        // 检查每日配额
        if ($apiKey->quota_daily && $apiKey->usage_daily >= $apiKey->quota_daily) {
            return false;
        }
        
        // 检查每月配额
        if ($apiKey->quota_monthly && $apiKey->usage_monthly >= $apiKey->quota_monthly) {
            return false;
        }
        
        return true;
    }
}
```

### 4.3 调用后处理

```php
class ApiCallLogger
{
    public function logCall(
        ApiKey $apiKey,
        Model $model,
        array $usage,
        array $cost
    ): void {
        // 1. 扣除用户余额
        $this->billingService->deductBalance(
            $apiKey->user_id,
            $cost['total_cost']
        );
        
        // 2. 更新API密钥使用量
        $apiKey->usage_daily += $cost['total_cost'];
        $apiKey->usage_monthly += $cost['total_cost'];
        $apiKey->call_count += 1;
        $apiKey->last_used_time = now();
        $apiKey->save();
        
        // 3. 记录调用日志
        ApiCallLog::create([
            'api_key_id' => $apiKey->id,
            'user_id' => $apiKey->user_id,
            'model_id' => $model->id,
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'total_tokens' => $usage['total_tokens'],
            'total_cost' => $cost['total_cost'],
            // ... 其他字段
        ]);
        
        // 4. 更新用户账单（异步）
        dispatch(new UpdateUserBillJob($apiKey->user_id));
    }
}
```

## 5. 前端页面

### 5.1 账户中心

**页面**：`/ai/account/overview`

**展示内容**：
- 账户余额（大字显示）
- 今日消费统计
- 本月消费统计
- 快速充值按钮
- 最近调用记录
- 余额变动趋势图

### 5.2 充值中心

**页面**：`/ai/account/recharge`

**功能**：
- 充值套餐选择（100/500/1000/5000/10000）
- 优惠活动展示
- 支付方式选择
- 充值记录查看

### 5.3 消费明细

**页面**：`/ai/account/consumption`

**功能**：
- 按日期筛选
- 按模型筛选
- 按API密钥筛选
- 导出明细

### 5.4 账单管理

**页面**：`/ai/account/bills`

**功能**：
- 月度账单列表
- 账单详情
- 下载账单PDF

### 5.5 API密钥管理

**页面**：`/ai/api-keys`（增强）

**新增功能**：
- 查看每个密钥的消费统计
- 设置消费限额（配额）
- 查看调用日志
- 密钥性能分析

## 6. 定时任务

```php
// 每日任务：重置每日配额使用量
php bin/w cron:schedule "0 0 * * *" "ResetDailyQuotaJob"

// 每月任务：重置每月配额使用量
php bin/w cron:schedule "0 0 1 * *" "ResetMonthlyQuotaJob"

// 每月任务：生成月度账单
php bin/w cron:schedule "0 1 1 * *" "GenerateMonthlyBillJob"

// 每小时任务：统计API调用
php bin/w cron:schedule "0 * * * *" "AggregateApiStatsJob"
```

## 7. 实施优先级

### Phase 1: 基础计费系统（高优先级）
1. ✅ 扩展用户表（balance字段）
2. ✅ 创建充值记录表
3. ✅ 创建API调用日志表
4. ✅ 实现充值功能
5. ✅ 实现余额扣除逻辑
6. ✅ 实现API调用计费

### Phase 2: API密钥增强（高优先级）
1. ✅ 修改配额字段含义（成本限制）
2. ✅ 添加使用量字段
3. ✅ 实现配额检查
4. ✅ API密钥使用统计

### Phase 3: 账单系统（中优先级）
1. ✅ 创建账单表
2. ✅ 实现账单生成
3. ✅ 账单查看和下载

### Phase 4: 优惠活动（低优先级）
1. ✅ 创建优惠活动表
2. ✅ 实现充值赠送
3. ✅ 活动管理后台

## 8. 安全考虑

### 8.1 防刷措施
- API调用频率限制
- 单次Token数量限制
- 异常调用检测
- IP黑名单机制

### 8.2 余额保护
- 余额不足立即拒绝
- 使用数据库事务
- 悲观锁防止并发
- 定期余额校验

### 8.3 支付安全
- 支付签名验证
- 回调验证
- 订单状态机
- 防止重复充值

## 9. 监控告警

```php
// 余额不足告警
if ($user->balance < 10) {
    notify($user, 'low_balance_warning');
}

// 异常消费告警
if ($todayCost > $user->avg_daily_cost * 3) {
    notify($user, 'abnormal_consumption');
}

// API调用失败告警
if ($failureRate > 0.1) {
    notifyAdmin('high_api_failure_rate');
}
```

