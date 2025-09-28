# Quickstart: AI助手工具模块

**Feature**: AI助手工具模块实现  
**Date**: 2024-12-19  
**Status**: Complete

## 快速开始指南

本指南将帮助您快速设置和测试AI助手工具模块的核心功能。

## 前置条件

### 系统要求
- PHP 8.0+
- WelineFramework 框架
- MySQL 5.7+ 或 SQLite 3.0+
- Composer 包管理器

### 依赖安装
```bash
# 安装WelineFramework依赖
composer install

# 安装AI模块依赖
composer require openai-php/openai
composer require google/cloud-aiplatform
composer require anthropic-php/anthropic
```

## 安装步骤

### 1. 数据库初始化
```bash
# 运行数据库迁移
php bin/w setup:upgrade

# 验证表结构
php bin/w db:status
```

### 2. 模块注册
```php
// app/etc/modules.php
return [
    'Weline_Ai' => [
        'name' => 'AI助手工具模块',
        'version' => '1.0.0',
        'dependencies' => ['Weline_Framework']
    ]
];
```

### 3. 配置设置
```php
// app/etc/env.php
return [
    'ai' => [
        'enabled' => true,
        'default_model' => 'gpt-3.5-turbo',
        'providers' => [
            'openai' => [
                'api_key' => 'your-openai-api-key',
                'base_url' => 'https://api.openai.com/v1'
            ],
            'google' => [
                'api_key' => 'your-google-api-key',
                'project_id' => 'your-project-id'
            ]
        ]
    ]
];
```

## 核心功能测试

### 1. AI模型管理测试

#### 测试场景：创建和配置AI模型
```php
// 测试代码
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\AiService;

// 创建AI模型
$model = new AiModel();
$model->setData('vendor', 'OpenAI')
      ->setData('model_code', 'gpt-3.5-turbo')
      ->setData('model_name', 'GPT-3.5 Turbo')
      ->setData('model_version', '1.0.0')
      ->setData('is_active', 1)
      ->save();

// 验证模型创建
assert($model->getId() > 0, 'AI模型创建失败');
assert($model->getVendor() === 'OpenAI', '供应商设置错误');
assert($model->isActive() === true, '模型未激活');
```

#### 预期结果
- ✅ AI模型成功创建
- ✅ 模型信息正确保存
- ✅ 模型状态为激活

### 2. 多租户管理测试

#### 测试场景：创建租户和用户
```php
// 测试代码
use Weline\Ai\Model\AiTenant;
use Weline\Ai\Model\AiTenantUser;
use Weline\Ai\Service\MultiTenantManager;

// 创建租户
$tenant = new AiTenant();
$tenant->setData('tenant_name', '测试企业')
       ->setData('tenant_code', 'test-company')
       ->setData('tenant_type', 'enterprise')
       ->setData('status', 'active')
       ->setData('plan_type', 'professional')
       ->save();

// 添加用户到租户
$tenantUser = new AiTenantUser();
$tenantUser->setData('tenant_id', $tenant->getId())
           ->setData('user_id', 1)
           ->setData('role', 'admin')
           ->setData('is_active', 1)
           ->save();

// 验证租户创建
assert($tenant->getId() > 0, '租户创建失败');
assert($tenant->getTenantCode() === 'test-company', '租户代码错误');
assert($tenant->isActive() === true, '租户未激活');
```

#### 预期结果
- ✅ 租户成功创建
- ✅ 用户成功添加到租户
- ✅ 权限设置正确

### 3. 国际化功能测试

#### 测试场景：多语言内容管理
```php
// 测试代码
use Weline\Ai\Model\AiI18nContent;
use Weline\Ai\Service\I18nManager;

// 创建翻译内容
$content = new AiI18nContent();
$content->setData('content_type', 'message')
         ->setData('content_key', 'welcome_message')
         ->setData('locale_code', 'zh_CN')
         ->setData('content_value', '欢迎使用AI助手')
         ->save();

// 创建英文翻译
$contentEn = new AiI18nContent();
$contentEn->setData('content_type', 'message')
           ->setData('content_key', 'welcome_message')
           ->setData('locale_code', 'en_US')
           ->setData('content_value', 'Welcome to AI Assistant')
           ->save();

// 测试翻译功能
$i18nManager = new I18nManager();
$translated = $i18nManager->translateContent('欢迎使用AI助手', 'en_US');

// 验证翻译结果
assert($content->getId() > 0, '中文内容创建失败');
assert($contentEn->getId() > 0, '英文内容创建失败');
assert($translated !== '', '翻译功能失败');
```

#### 预期结果
- ✅ 多语言内容成功保存
- ✅ 翻译功能正常工作
- ✅ 语言切换正确

### 4. 移动端功能测试

#### 测试场景：设备注册和推送通知
```php
// 测试代码
use Weline\Ai\Model\AiMobileDevice;
use Weline\Ai\Model\AiMobileNotification;
use Weline\Ai\Service\MobileManager;

// 注册移动设备
$device = new AiMobileDevice();
$device->setData('user_id', 1)
       ->setData('device_id', 'test-device-001')
       ->setData('device_type', 'ios')
       ->setData('push_token', 'test-push-token')
       ->setData('is_active', 1)
       ->save();

// 发送推送通知
$mobileManager = new MobileManager();
$result = $mobileManager->sendPushNotification(
    1, // 用户ID
    '测试通知', // 标题
    '这是一条测试通知', // 内容
    'ai_response' // 通知类型
);

// 验证设备注册
assert($device->getId() > 0, '设备注册失败');
assert($device->getDeviceType() === 'ios', '设备类型错误');
assert($result === true, '推送通知发送失败');
```

#### 预期结果
- ✅ 设备成功注册
- ✅ 推送通知成功发送
- ✅ 设备状态正确

### 5. 计费系统测试

#### 测试场景：创建计费计划和发票
```php
// 测试代码
use Weline\Ai\Model\AiBillingPlan;
use Weline\Ai\Model\AiBillingInvoice;
use Weline\Ai\Service\BillingManager;

// 创建计费计划
$plan = new AiBillingPlan();
$plan->setData('plan_name', '专业版')
     ->setData('plan_type', 'paid')
     ->setData('price', 99.00)
     ->setData('currency', 'USD')
     ->setData('billing_cycle', 'monthly')
     ->setData('is_active', 1)
     ->save();

// 生成发票
$billingManager = new BillingManager();
$invoice = $billingManager->generateInvoice(
    1, // 租户ID
    99.00, // 金额
    'USD', // 货币
    [['item' => '专业版订阅', 'amount' => 99.00]] // 项目
);

// 验证计费计划
assert($plan->getId() > 0, '计费计划创建失败');
assert($plan->getPrice() === 99.00, '价格设置错误');
assert($invoice->getId() > 0, '发票生成失败');
```

#### 预期结果
- ✅ 计费计划成功创建
- ✅ 发票成功生成
- ✅ 金额计算正确

## API测试

### 1. AI服务API测试

#### 测试端点：POST /api/ai/generate
```bash
# 测试请求
curl -X POST http://localhost/api/ai/generate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer your-token" \
  -d '{
    "prompt": "请帮我写一个PHP函数",
    "model_code": "gpt-3.5-turbo",
    "scenario_code": "code_generation",
    "locale": "zh_CN"
  }'
```

#### 预期响应
```json
{
  "success": true,
  "data": {
    "content": "生成的PHP函数代码",
    "model": "gpt-3.5-turbo",
    "usage": {
      "input_tokens": 50,
      "output_tokens": 200,
      "total_tokens": 250
    }
  }
}
```

### 2. 多租户API测试

#### 测试端点：GET /api/tenant/info
```bash
# 测试请求
curl -X GET http://localhost/api/tenant/info \
  -H "Authorization: Bearer your-token" \
  -H "X-Tenant-Code: test-company"
```

#### 预期响应
```json
{
  "success": true,
  "data": {
    "tenant": {
      "id": 1,
      "name": "测试企业",
      "code": "test-company",
      "type": "enterprise",
      "status": "active"
    },
    "users": {
      "total": 5,
      "active": 5
    },
    "quota": {
      "api_calls": {
        "limit": 10000,
        "used": 150
      }
    }
  }
}
```

### 3. 国际化API测试

#### 测试端点：GET /api/i18n/content
```bash
# 测试请求
curl -X GET "http://localhost/api/i18n/content?key=welcome_message&locale=en_US" \
  -H "Authorization: Bearer your-token"
```

#### 预期响应
```json
{
  "success": true,
  "data": {
    "key": "welcome_message",
    "locale": "en_US",
    "content": "Welcome to AI Assistant",
    "context": "greeting"
  }
}
```

## 性能测试

### 1. 并发测试
```bash
# 使用Apache Bench进行并发测试
ab -n 1000 -c 10 -H "Authorization: Bearer your-token" \
   http://localhost/api/ai/generate
```

### 2. 响应时间测试
```bash
# 测试API响应时间
curl -w "@curl-format.txt" -o /dev/null -s \
  "http://localhost/api/ai/generate"
```

### 3. 数据库性能测试
```sql
-- 测试查询性能
EXPLAIN SELECT * FROM ai_model WHERE is_active = 1;
EXPLAIN SELECT * FROM ai_tenant WHERE status = 'active';
```

## 错误处理测试

### 1. 无效请求测试
```bash
# 测试无效的API密钥
curl -X POST http://localhost/api/ai/generate \
  -H "Authorization: Bearer invalid-token" \
  -d '{"prompt": "test"}'
```

#### 预期响应
```json
{
  "success": false,
  "error": {
    "code": "UNAUTHORIZED",
    "message": "无效的认证令牌"
  }
}
```

### 2. 配额超限测试
```bash
# 测试超出配额限制
curl -X POST http://localhost/api/ai/generate \
  -H "Authorization: Bearer your-token" \
  -d '{"prompt": "test"}' # 重复请求直到超出配额
```

#### 预期响应
```json
{
  "success": false,
  "error": {
    "code": "QUOTA_EXCEEDED",
    "message": "已超出使用配额限制"
  }
}
```

## 监控和日志

### 1. 应用日志
```bash
# 查看应用日志
tail -f var/log/ai.log
```

### 2. 错误日志
```bash
# 查看错误日志
tail -f var/log/error.log
```

### 3. 性能监控
```bash
# 查看性能指标
php bin/w monitor:status
```

## 故障排除

### 常见问题

#### 1. 数据库连接失败
```bash
# 检查数据库配置
php bin/w config:show database
```

#### 2. AI模型API调用失败
```bash
# 检查API配置
php bin/w config:show ai
```

#### 3. 权限问题
```bash
# 检查文件权限
chmod -R 755 app/code/Weline/Ai
```

### 调试模式
```php
// 启用调试模式
// app/etc/env.php
return [
    'debug' => true,
    'ai' => [
        'debug' => true,
        'log_level' => 'debug'
    ]
];
```

## 下一步

1. **生产环境部署**: 配置生产环境参数
2. **监控设置**: 设置性能监控和告警
3. **安全加固**: 实施安全最佳实践
4. **扩展功能**: 添加更多AI模型和功能
5. **用户培训**: 提供用户使用指南

## 支持

- **文档**: 查看完整API文档
- **社区**: 参与社区讨论
- **支持**: 联系技术支持团队
- **反馈**: 提交功能建议和问题报告
