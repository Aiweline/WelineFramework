# AI模块开发文档

## 功能概述

Weline_Ai 模块是一个企业级AI服务集成平台，提供统一的AI服务接口，支持多种AI模型、场景适配器和多语言处理。

### 主要功能

1. **AI模型管理**
   - 自动扫描和注册AI模型配置
   - 支持多种AI供应商（OpenAI、Claude等）
   - 模型状态管理和版本控制

2. **场景适配器系统**
   - 专门优化不同使用场景的AI生成
   - 内置翻译和代码生成适配器
   - 支持自定义适配器开发

3. **默认模型管理**
   - 为不同服务类型配置默认模型
   - 模型删除保护机制
   - 智能模型选择和回退

4. **双服务模式**
   - HTTP API接口模式
   - PHP静态方法调用模式
   - 流式和非流式响应支持

5. **多语言支持**
   - 集成I18n模块
   - 支持内容本地化
   - 自动语言检测和转换

## 使用方法

### PHP服务模式

```php
use Weline\Ai\Service\AiService;

// 基本文本生成
$response = AiService::generateText('请介绍一下人工智能');

// 指定模型和场景
$response = AiService::generateText(
    'Translate this to Chinese: Hello World',
    'openai_gpt-3.5-turbo',  // 模型代码
    'translation',           // 场景代码
    'zh-CN',                // 目标语言
    ['target_language' => '中文'] // 额外参数
);

// 流式生成
AiService::generateTextStream(
    '写一篇关于AI的文章',
    function($chunk) {
        echo $chunk;
    },
    null,
    'text_generation'
);
```

### API接口模式

```bash
# 基本聊天接口
curl -X POST http://your-domain/ai/api/chat/generate \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "请介绍一下人工智能",
    "model_code": "openai_gpt-3.5-turbo",
    "scenario_code": "text_generation",
    "locale": "zh-CN"
  }'

# 流式接口
curl -X POST http://your-domain/ai/api/chat/stream \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "写一篇关于AI的文章",
    "scenario_code": "text_generation"
  }'
```

### CLI命令

```bash
# 收集AI模型
php bin/w ai:model:collect

# 扫描场景适配器
php bin/w ai:adapter:scan

# 管理默认模型
php bin/w ai:default-model:manage --action=list
php bin/w ai:default-model:manage --action=init
php bin/w ai:default-model:manage --action=validate
```

## 参数说明

### 核心参数

| 参数 | 类型 | 说明 | 默认值 |
|------|------|------|--------|
| `prompt` | string | 提示词内容 | 必需 |
| `model_code` | string | 指定模型代码 | 使用默认模型 |
| `scenario_code` | string | 场景适配器代码 | 无 |
| `locale` | string | 目标语言代码 | 无 |
| `params` | array | 场景适配器参数 | [] |

### 场景适配器参数

#### 翻译适配器 (translation)
- `target_language`: 目标语言
- `source_language`: 源语言（可选）
- `strategy`: 翻译策略（standard/professional/casual/technical）
- `context`: 上下文信息
- `format`: 输出格式（plain/markdown/html）

#### 代码生成适配器 (code_generation)
- `language`: 编程语言
- `style`: 代码风格
- `framework`: 使用的框架
- `include_comments`: 是否包含注释
- `include_tests`: 是否包含测试代码

## 注意事项

### 模型配置
1. 模型配置文件必须放在 `app/code/Weline/Ai/etc/models/` 目录
2. 配置文件必须是有效的JSON格式
3. 必须包含 `model_code`、`name`、`vendor` 字段

### 场景适配器开发
1. 必须实现 `ScenarioAdapterInterface` 接口
2. 类名必须以 `Adapter` 结尾
3. 必须放在 `app/code/Weline/Ai/Adapter/` 目录

### 默认模型保护
1. 被设置为默认模型的不能删除
2. 删除前会自动检查保护状态
3. 可通过后台或CLI查看保护状态

### 性能优化
1. 默认模型会被缓存，提高查询性能
2. 适配器实例会被缓存，避免重复创建
3. 建议定期清理无效的适配器和模型

## 故障排除

### 常见问题

1. **模型不存在或未激活**
   - 检查模型配置文件是否正确
   - 运行 `ai:model:collect` 重新收集模型
   - 确认模型状态为 `active`

2. **适配器不工作**
   - 检查适配器类是否实现了正确的接口
   - 运行 `ai:adapter:scan` 重新扫描适配器
   - 查看适配器是否被激活

3. **默认模型配置问题**
   - 运行 `ai:default-model:manage --action=validate` 验证配置
   - 使用 `ai:default-model:manage --action=init` 初始化配置
   - 检查模型是否存在且激活

4. **API调用失败**
   - 检查请求参数是否正确
   - 验证模型和适配器是否存在
   - 查看错误日志获取详细信息

### 调试技巧

1. 启用调试模式查看详细错误信息
2. 使用CLI命令验证配置状态
3. 检查数据库表中的数据完整性
4. 查看系统日志文件

## 扩展开发

### 自定义适配器

```php
<?php
namespace Weline\Ai\Adapter;

use Weline\Ai\Api\ScenarioAdapterInterface;

class CustomAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'custom';
    }

    public function getName(): string
    {
        return '自定义适配器';
    }

    // 实现其他必需方法...
}
```

### 自定义模型配置

```json
{
  "model_code": "custom_model",
  "name": "自定义模型",
  "vendor": "Custom",
  "config": {
    "api_url": "https://api.example.com/v1/chat",
    "api_key_env": "CUSTOM_API_KEY",
    "max_tokens": 4096
  },
  "token_price": 0.001,
  "status": "active"
}
```

### 集成第三方服务

可以通过实现自定义适配器来集成第三方AI服务，只需要：

1. 创建适配器类实现接口
2. 在适配器中调用第三方API
3. 处理响应格式转换
4. 运行扫描命令注册适配器

## API参考

详细的API接口文档请参考：
- [API接口文档](./API接口文档.md)
- [场景适配器开发指南](./场景适配器开发指南.md)
- [模型配置指南](./模型配置指南.md)
