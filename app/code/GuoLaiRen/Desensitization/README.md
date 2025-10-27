# GuoLaiRen 数据脱敏模块

## 概述

GuoLaiRen_Desensitization 是一个基于Weline框架开发的数据脱敏模块，提供了多种脱敏方式，包括：

- **正则表达式脱敏** - 基于规则的高性能脱敏
- **AI智能脱敏** - 调用AI模块接口实现智能脱敏
- **自定义脱敏** - 支持用户自定义脱敏规则

## 功能特性

### 1. 三种工作模式

- **脱敏模式** - 对敏感数据进行脱敏处理，保护隐私信息
- **检测模式** - 检测内容中是否包含敏感信息，并提供详细位置信息
- **重写模式** - 脱敏后使用AI重写润色，使内容更自然流畅

### 2. 多种脱敏方式

- **正则表达式脱敏（regex）** - 默认方式，基于正则表达式快速脱敏
- **AI智能脱敏（ai）** - 使用AI理解上下文并进行智能脱敏
- **自定义脱敏（custom）** - 使用自定义规则集脱敏

### 2. 内置脱敏规则

模块内置了以下常用的脱敏规则：

- 邮箱脱敏：example***@domain.***
- 手机号脱敏：138****5678
- 身份证号脱敏：370123********1234
- 银行卡号脱敏：6222************1234
- 信用卡号脱敏：6222****1234

### 3. 规则管理

- 可视化规则管理界面
- 支持添加、编辑、删除脱敏规则
- 支持规则启用/禁用
- 规则优先级配置
- 在线测试规则

### 4. 批量处理

- 支持批量脱敏处理
- 可配置批量处理延迟
- 支持大数据量处理

### 5. 日志记录

- 完整的脱敏操作日志
- 记录执行时间
- 支持日志查询和分析

## 安装

### 1. 安装模块

```bash
php bin/w setup:upgrade
```

### 2. 验证安装

```bash
php bin/w module:list | grep "Desensitization"
```

应该显示：`GuoLaiRen_Desensitization    # 开启`

### 3. 检查数据库表

模块会自动创建以下表：
- `desensitization_rule` - 脱敏规则表
- `desensitization_log` - 脱敏日志表

## 配置

### AI模型配置

模块配置文件位于 `app/code/GuoLaiRen/Desensitization/etc/env.php`，可以配置：

```php
'ai' => [
    // 使用的AI模型代码，为空则使用默认模型
    'model_code' => '',
    
    // 是否启用AI脱敏
    'enabled' => true,
    
    // 脱敏提示词模板
    'prompt_template' => '请对以下内容进行数据脱敏处理，保护敏感信息：{content}',
    
    // 脱敏场景适配器代码
    'desensitization_adapter' => 'desensitization',
    
    // 重写场景适配器代码
    'rewrite_adapter' => 'rewrite',
],
```

#### 获取可用模型列表

可以通过代码获取所有可用的AI模型：

```php
use GuoLaiRen\Desensitization\Service\DesensitizationService;

$service = ObjectManager::getInstance(DesensitizationService::class);
$models = $service->getAvailableModels();

// 返回格式：
// [
//     ['code' => 'gpt-4', 'name' => 'GPT-4', 'supplier' => 'OpenAI', 'version' => '1.0'],
//     ['code' => 'claude-3', 'name' => 'Claude 3', 'supplier' => 'Anthropic', 'version' => '3.0'],
// ]
```

#### 指定模型进行脱敏

在调用脱敏或重写时，可以指定要使用的AI模型：

```php
// 使用特定模型进行脱敏
$result = $service->desensitize($content, 'ai', [
    'model_code' => 'gpt-4',
]);

// 使用特定模型进行重写
$result = $service->desensitizeAndRewrite($content, [
    'model_code' => 'claude-3',
    'rewrite_style' => 'natural',
]);
```

## 使用方法

### 1. 代码调用

#### 基本使用

```php
use GuoLaiRen\Desensitization\Service\DesensitizationService;

// 通过依赖注入获取服务
$desensitizationService = ObjectManager::getInstance()->get(DesensitizationService::class);

// 简单脱敏（使用默认规则）
$result = $desensitizationService->desensitize('我的邮箱是 example@domain.com');
echo $result; // 输出：我的邮箱是 example***@domain.***

// 指定脱敏方法
$result = $desensitizationService->desensitize(
    '13812345678', 
    'regex',
    ['rule_type' => 'phone']
);
echo $result; // 输出：138****5678
```

#### AI智能脱敏

```php
// 使用AI进行智能脱敏
$content = '联系人：张三，邮箱：zhangsan@example.com，电话：13812345678';
$result = $desensitizationService->desensitize(
    $content,
    'ai',
    [
        'model_code' => 'gpt-3.5-turbo', // 可选，指定AI模型
        'prompt_template' => '请对以下内容进行数据脱敏处理，保护敏感信息：{content}' // 可选
    ]
);
```

#### 敏感内容检测

```php
// 检测内容中是否包含敏感信息
$result = $desensitizationService->detectSensitive(
    '联系邮箱：test@example.com，电话：13812345678',
    [
        'rule_types' => ['email', 'phone'], // 指定检测类型
        'return_positions' => true // 返回敏感信息的位置
    ]
);

/*
返回结果：
[
    'has_sensitive' => true,
    'sensitive_types' => ['email', 'phone'],
    'positions' => [
        [
            'type' => 'email',
            'match' => 'test@example.com',
            'start' => 5,
            'end' => 22
        ],
        [
            'type' => 'phone',
            'match' => '13812345678',
            'start' => 25,
            'end' => 36
        ]
    ]
]
*/
```

#### AI重写润色

```php
// 脱敏并使用AI重写润色
$content = '联系人张三的邮箱是zhangsan@example.com，电话13812345678';
$result = $desensitizationService->desensitizeAndRewrite(
    $content,
    [
        'model_code' => 'gpt-3.5-turbo', // 可选
        'rewrite_style' => 'natural', // natural, formal, casual, professional, concise
        'preserve_format' => true // 是否保持原有格式
    ]
);
// 输出：经过AI重写润色后的自然流畅文本
```

#### 批量脱敏

```php
$contents = [
    'example1@domain.com',
    '13812345678',
    '370123199001011234'
];

$results = $desensitizationService->desensitizeBatch($contents);
print_r($results);
```

### 2. API调用

#### 单条内容脱敏

```bash
curl -X POST http://your-domain/desensitization/api/process \
  -H "Content-Type: application/json" \
  -d '{
    "content": "我的邮箱是 example@domain.com",
    "method": "regex",
    "options": {
      "rule_type": "email"
    }
  }'
```

#### 批量脱敏

```bash
curl -X POST http://your-domain/desensitization/api/batch \
  -H "Content-Type: application/json" \
  -d '{
    "contents": ["example@domain.com", "13812345678"],
    "method": "regex"
  }'
```

#### 获取可用方法列表

```bash
curl http://your-domain/desensitization/api/methods
```

#### 获取规则列表

```bash
curl http://your-domain/desensitization/api/rules
```

#### 检测敏感内容

```bash
curl -X POST http://your-domain/desensitization/api/detect \
  -H "Content-Type: application/json" \
  -d '{
    "content": "联系邮箱：test@example.com，电话：13812345678",
    "rule_types": ["email", "phone"],
    "return_positions": true
  }'
```

**响应：**
```json
{
    "success": true,
    "message": "检测成功",
    "data": {
        "has_sensitive": true,
        "sensitive_types": ["email", "phone"],
        "positions": [...]
    }
}
```

#### AI重写润色

```bash
curl -X POST http://your-domain/desensitization/api/rewrite \
  -H "Content-Type: application/json" \
  -d '{
    "content": "用户张三的邮箱是zhangsan@example.com，电话13812345678",
    "model_code": "gpt-3.5-turbo",
    "rewrite_style": "natural",
    "preserve_format": true
  }'
```

**响应：**
```json
{
    "success": true,
    "message": "重写润色成功",
    "data": {
        "original": "原始内容",
        "rewritten": "AI重写后的内容"
    }
}
```

### 3. 后台管理

访问后台管理界面：

1. **规则管理**: `/desensitization/backend/rule/index`
   - 查看所有脱敏规则
   - 添加、编辑、删除规则
   - 启用/禁用规则
   - 测试规则

2. **脱敏测试**: `/desensitization/backend/test/index`
   - 在线测试脱敏效果
   - 查看执行时间
   - 支持不同脱敏方法

## 配置说明

配置文件：`app/code/GuoLaiRen/Desensitization/etc/env.php`

### AI配置

```php
'ai' => [
    // AI模型代码，为空则使用默认模型
    'model_code' => '',
    
    // 是否启用AI脱敏
    'enabled' => true,
    
    // 脱敏提示词模板
    'prompt_template' => '请对以下内容进行数据脱敏处理，保护敏感信息：{content}',
],
```

### 批量处理配置

```php
'batch' => [
    // 单次处理的最大记录数
    'max_records' => 1000,
    
    // 批量处理延迟（秒）
    'delay' => 0.1,
    
    // 是否异步处理
    'async' => false
],
```

### 日志配置

```php
'logging' => [
    // 是否记录脱敏日志
    'enabled' => true,
    
    // 日志保留天数
    'retention_days' => 30
]
```

## 工作模式详解

### 1. 脱敏模式

对敏感数据进行脱敏处理，保护隐私信息。

**脱敏方式：**

#### regex（正则表达式）
- **优点**：速度快，资源占用少
- **缺点**：需要预先定义规则，无法处理复杂场景
- **适用场景**：结构化数据脱敏（邮箱、手机号等）

#### ai（AI智能脱敏）
- **优点**：理解上下文，处理复杂场景
- **缺点**：速度相对较慢，需要调用AI服务
- **适用场景**：非结构化数据脱敏，需要上下文理解的场景

#### custom（自定义脱敏）
- **优点**：灵活，可根据需求定制
- **缺点**：需要自行实现规则
- **适用场景**：特定业务场景的脱敏需求

### 2. 检测模式

检测内容中是否包含敏感信息，提供详细的检测结果和位置信息。

**功能：**
- 快速检测敏感内容是否存在
- 返回敏感信息类型
- 提供敏感内容在文本中的位置（起始/结束位置）
- 支持指定检测类型

**适用场景：**
- 内容审核前检查
- 敏感信息统计
- 违规内容标记

### 3. 重写模式

脱敏后使用AI重写润色，使内容更自然流畅。

**重写风格：**
- **natural** - 自然流畅（默认）
- **formal** - 正式专业
- **casual** - 轻松随意
- **professional** - 专业严谨
- **concise** - 简洁精炼

**功能：**
- 自动脱敏敏感信息
- AI理解上下文并重写
- 保持原意不变
- 可选的格式保持

**适用场景：**
- 内容发布前的处理
- 数据展示优化
- 文档美化

## 添加自定义规则

### 通过代码添加

```php
use GuoLaiRen\Desensitization\Model\DesensitizationRule;

$rule = ObjectManager::getInstance()->get(DesensitizationRule::class);

$rule->setData([
    'name' => '自定义规则',
    'type' => 'custom',
    'pattern' => '/pattern/',
    'replacement' => '***',
    'description' => '自定义脱敏规则',
    'is_active' => 1,
    'priority' => 10
]);

$rule->save();
```

### 通过后台添加

1. 访问 `/desensitization/backend/rule/add`
2. 填写规则信息：
   - 规则名称
   - 规则类型
   - 匹配模式（正则表达式）
   - 替换内容
   - 描述
   - 优先级
3. 点击保存

## API参考

### POST /desensitization/api/process

单条内容脱敏

**请求参数：**
```json
{
    "content": "脱敏内容",
    "method": "regex|ai|custom",
    "options": {
        "rule_type": "email",
        "model_code": "gpt-3.5-turbo",
        "prompt_template": "自定义提示词"
    }
}
```

**响应：**
```json
{
    "success": true,
    "message": "脱敏成功",
    "data": {
        "original": "原始内容",
        "desensitized": "脱敏后内容"
    }
}
```

### POST /desensitization/api/batch

批量脱敏

**请求参数：**
```json
{
    "contents": ["内容1", "内容2"],
    "method": "regex",
    "options": {}
}
```

**响应：**
```json
{
    "success": true,
    "message": "批量脱敏成功",
    "data": {
        "results": ["结果1", "结果2"]
    }
}
```

### GET /desensitization/api/methods

获取可用方法列表

**响应：**
```json
{
    "success": true,
    "message": "获取成功",
    "data": {
        "methods": [
            {
                "code": "regex",
                "description": "正则表达式脱敏"
            },
            {
                "code": "ai",
                "description": "AI智能脱敏"
            }
        ]
    }
}
```

### GET /desensitization/api/rules

获取规则列表

**请求参数：**
- `type`（可选）：规则类型

**响应：**
```json
{
    "success": true,
    "message": "获取成功",
    "data": {
        "rules": [
            {
                "rule_id": 1,
                "name": "email",
                "type": "email",
                "pattern": "/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\\.([a-zA-Z]{2,})/",
                "replacement": "$1***@$2.***",
                "description": "邮箱脱敏",
                "priority": 5,
                "is_active": 1
            }
        ]
    }
}
```

## 性能优化

1. **缓存规则**：规则会被缓存，减少数据库查询
2. **批量处理**：使用批量接口处理大量数据
3. **异步处理**：配置异步处理可提高响应速度
4. **规则优先级**：合理配置规则优先级可加快匹配速度

## 注意事项

1. AI脱敏需要先配置AI模块
2. 正则表达式规则需要严格测试
3. 建议对敏感数据先备份再脱敏
4. 大批量数据处理建议使用批量接口
5. 日志记录会影响性能，生产环境可适当关闭

## 故障排查

### 1. AI脱敏失败

**检查项：**
- AI配块是否已安装
- AI模型是否配置正确
- AI服务是否可用

### 2. 规则不生效

**检查项：**
- 规则是否正确激活
- 正则表达式是否正确
- 规则优先级是否正确

### 3. 性能问题

**优化建议：**
- 检查规则数量，删除无用规则
- 调整批量处理参数
- 关闭日志记录
- 使用缓存

## 依赖模块

- Weline_Framework - 框架核心
- Weline_Backend - 后台管理
- Weline_Ai - AI服务（可选，用于AI脱敏）

## 更新日志

### v1.0.0（2025-01-XX）

- 初始版本发布
- 支持正则表达式脱敏
- 支持AI智能脱敏
- 支持自定义规则
- 提供完整的后台管理界面
- 提供REST API接口

## 贡献

欢迎提交Issue和Pull Request。

## 许可证

本项目采用 MIT 许可证。

