# 数据脱敏模块配置说明

## 概述

本文档详细说明数据脱敏模块的配置方法和选项。

## 配置文件

模块配置文件：`app/code/GuoLaiRen/Desensitization/etc/env.php`

## 配置结构

```php
return [
    'desensitization' => [
        'ai' => [...],           // AI模型配置
        'default_rules' => [...], // 默认脱敏规则
        'strategies' => [...],    // 脱敏策略
        'rewrite_styles' => [...], // 重写风格
        'batch' => [...],         // 批量处理配置
        'logging' => [...],       // 日志配置
    ]
];
```

## AI模型配置

### 配置项说明

```php
'ai' => [
    // 使用的AI模型代码，为空则使用默认模型
    // 可在代码中通过 getAvailableModels() 获取可用模型列表
    'model_code' => '',
    
    // 是否启用AI脱敏功能
    'enabled' => true,
    
    // 脱敏提示词模板，{content} 会被替换为实际内容
    'prompt_template' => '请对以下内容进行数据脱敏处理，保护敏感信息：{content}',
    
    // 脱敏场景适配器代码，用于AI模块的场景适配
    'desensitization_adapter' => 'desensitization',
    
    // 重写场景适配器代码，用于AI重写功能的场景适配
    'rewrite_adapter' => 'rewrite',
]
```

### 获取可用模型列表

```php
use GuoLaiRen\Desensitization\Service\DesensitizationService;
use Weline\Framework\Manager\ObjectManager;

$service = ObjectManager::getInstance(DesensitizationService::class);
$models = $service->getAvailableModels();

/*
返回格式：
[
    [
        'code' => 'gpt-4',
        'name' => 'GPT-4',
        'supplier' => 'OpenAI',
        'version' => '4.0'
    ],
    [
        'code' => 'claude-3-opus',
        'name' => 'Claude 3 Opus',
        'supplier' => 'Anthropic',
        'version' => '3.0'
    ],
    // ...
]
*/
```

### 使用指定模型

#### 方式1：在配置文件中设置

```php
'ai' => [
    'model_code' => 'gpt-4', // 指定使用 GPT-4 模型
    // ... 其他配置
]
```

#### 方式2：在代码中动态指定

```php
use GuoLaiRen\Desensitization\Service\DesensitizationService;

$service = ObjectManager::getInstance(DesensitizationService::class);

// AI脱敏时指定模型
$result = $service->desensitize($content, 'ai', [
    'model_code' => 'gpt-4',
    'adapter_code' => 'desensitization',
]);

// AI重写时指定模型
$result = $service->desensitizeAndRewrite($content, [
    'model_code' => 'claude-3-opus',
    'rewrite_style' => 'natural',
    'adapter_code' => 'rewrite',
]);
```

### 场景适配器

场景适配器是AI模块的功能，用于根据不同场景优化AI的提示词和处理逻辑。

- **desensitization**: 用于脱敏场景，系统会自动优化提示词以更好地识别和处理敏感信息
- **rewrite**: 用于重写场景，系统会自动优化提示词以更好地重写和润色内容

可以在调用时指定适配器：

```php
$result = $service->desensitize($content, 'ai', [
    'adapter_code' => 'custom_adapter', // 使用自定义适配器
]);
```

## 脱敏规则配置

### 默认规则

模块内置了以下默认脱敏规则：

```php
'default_rules' => [
    'email' => [
        'pattern' => '/([a-zA-Z0-9._-]+)@([a-zA-Z0-9.-]+)\.([a-zA-Z]{2,})/',
        'replacement' => '$1***@$2.***',
        'type' => 'email',
        'description' => '邮箱脱敏：保留@前部分首尾，中间用***替换'
    ],
    'phone' => [
        'pattern' => '/(\d{3})\d{4}(\d{4})/',
        'replacement' => '$1****$2',
        'type' => 'phone',
        'description' => '手机号脱敏：保留前3位和后4位，中间用****替换'
    ],
    // ... 更多规则
]
```

### 自定义规则

可以在后台管理界面添加自定义规则，也可以直接在配置文件中添加：

```php
'default_rules' => [
    'custom_rule' => [
        'pattern' => '/自定义正则表达式/',
        'replacement' => '替换内容',
        'type' => 'custom_type',
        'description' => '自定义规则描述'
    ],
]
```

## 批量处理配置

```php
'batch' => [
    // 单次处理的最大记录数
    'max_records' => 1000,
    
    // 批量处理时每条记录之间的延迟（秒）
    'delay' => 0.1,
    
    // 是否启用异步处理
    'async' => false
]
```

### 使用示例

```php
// 批量处理（遵循max_records限制）
$results = $service->desensitizeBatch([
    'content1',
    'content2',
    // ...
], 'regex');
```

## 日志配置

```php
'logging' => [
    // 是否记录脱敏日志
    'enabled' => true,
    
    // 日志保留天数
    'retention_days' => 30
]
```

### 查看脱敏日志

可以通过后台管理界面查看脱敏操作日志，或通过代码查询：

```php
use GuoLaiRen\Desensitization\Model\DesensitizationLog;

$logModel = ObjectManager::getInstance(DesensitizationLog::class);
$logs = $logModel->select()->fetch();
```

## 重写风格配置

```php
'rewrite_styles' => [
    'natural' => '自然流畅',      // 保持自然流畅的语言风格
    'formal' => '正式专业',      // 使用正式、专业的语言
    'casual' => '轻松随意',      // 使用轻松、随意的语言
    'professional' => '专业严谨', // 使用专业、严谨的语言
    'concise' => '简洁精炼'       // 使用简洁、精炼的语言
]
```

### 使用示例

```php
$result = $service->desensitizeAndRewrite($content, [
    'rewrite_style' => 'formal', // 使用正式专业的风格
    'preserve_format' => true,   // 保持原有格式
]);
```

## 配置优先级

配置项的优先级（从高到低）：

1. 代码调用时传入的选项参数
2. 配置文件中的设置
3. 模块默认值

示例：

```php
// 配置文件设置 model_code 为 'gpt-3'
// 但代码中指定为 'gpt-4'
$result = $service->desensitize($content, 'ai', [
    'model_code' => 'gpt-4', // 优先使用此值
]);

// 结果：使用 gpt-4 模型
```

## 最佳实践

### 1. 选择合适的模型

- **高性能场景**：使用 `gpt-3.5-turbo` 等轻量级模型
- **高质量场景**：使用 `gpt-4` 或 `claude-3-opus` 等高级模型
- **成本敏感**：优先使用正则脱敏，必要时才使用AI

### 2. 模型配置建议

```php
'ai' => [
    'model_code' => '', // 留空使用默认模型，系统会根据场景自动选择
    'enabled' => true,  // 根据实际需求决定是否启用
]
```

### 3. 批量处理建议

```php
'batch' => [
    'max_records' => 500, // 根据服务器性能调整
    'delay' => 0.2,       // 避免API限流
    'async' => false      // 大数据量建议启用异步处理
]
```

### 4. 日志管理

```php
'logging' => [
    'enabled' => true,
    'retention_days' => 90 // 根据存储空间调整保留天数
]
```

## 故障排除

### 1. AI模型不可用

**问题**: 提示"无法选择合适的AI模型"

**解决方案**:
- 检查AI模块是否正常安装
- 确认有激活状态的AI模型：访问 `/backend/ai/model`
- 检查 `model_code` 是否正确

### 2. 场景适配器不存在

**问题**: 提示"场景适配器不存在"

**解决方案**:
- 确认场景适配器代码正确
- 检查AI模块是否支持该场景
- 查看AI模块日志确认

### 3. 获取模型列表失败

**问题**: `getAvailableModels()` 返回空数组

**解决方案**:
- 检查数据库连接
- 确认 `ai` 表存在且有数据
- 检查模型是否激活（is_active=1）

## 相关链接

- [README.md](./README.md) - 模块概览和使用指南
- [USAGE.md](./USAGE.md) - 详细使用说明
- [QUICKSTART.md](./QUICKSTART.md) - 快速入门指南
- [Weline AI模块文档](../Weline/Ai/doc/用户/AI模块使用手册.md) - AI模块使用手册
