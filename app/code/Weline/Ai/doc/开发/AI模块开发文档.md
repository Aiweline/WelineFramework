# AI模块开发文档

**版本**: 2.0（基于实际开发经验更新）  
**更新日期**: 2025-10-09

## 功能概述

Weline_Ai 模块是一个企业级AI服务集成平台，提供统一的AI服务接口，支持多种AI模型、场景适配器和多语言处理。

> **重要**: 本文档已根据实际开发经验更新。如与旧版本有冲突，以本版本为准。

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

### 图片生成品牌身份资产参数

文生图请求的 `params.identity_transparent_png_required=true` 或 `params.transparent_png_required=true` 会被识别为品牌身份资产生成请求，适用于网站 header、favicon、品牌识别位的 logo/icon。AI 服务层会追加 logo/icon 专用提示词约束，要求主体居中、无文字、无场景背景、适合小尺寸识别和后续透明 PNG 规范化。

返回结果会在顶层和 `metadata` 中保留以下语义字段，供 PageBuilder 判断和本地后处理：

| 字段 | 说明 |
|------|------|
| `requested_transparent_background` | 调用方是否请求透明背景 |
| `native_transparent_background` | 当前供应商/模型是否按原生透明背景参数生成 |
| `output_format` | 目标输出格式，品牌身份资产固定为 `png` |
| `identity_asset` | 是否为品牌身份资产 |
| `identity_asset_role` | `logo` 或 `icon`，默认 `logo`，可通过 `identity_asset_role`、`asset_role`、`usage` 等参数推断 |
| `native_transparency_error` | 原生透明参数失败时保留的真实供应商错误文本，成功或未尝试时为空 |

当模型明确支持原生透明背景时，适配层会传供应商对应的透明背景参数（OpenAI `gpt-image-1` 使用 `background=transparent` 并要求 `output_format=png`）。当供应商或模型不支持、能力未知，或原生透明参数返回不支持错误时，适配层不会继续硬传透明背景参数，而是生成干净、主体明确、背景简单、便于本地抠图的 logo/icon 图像，并让 PageBuilder 负责最终 alpha 校验和本地透明化处理。

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

## 实际开发经验（2025-10-09更新）

### 已实现的核心功能

#### 1. 数据库表结构
模块定义了5张核心数据表（已在 `Setup/Install.php` 中定义）:

```php
// app/code/Weline/Ai/Setup/Install.php
public function setup(Setup $setup, Context $context)
{
    // 1. ai_model - AI模型元数据
    $setup->getConnection()->createTable('ai_model', function ($table) {
        $table->addColumn('id', 'int', 10, 'PRIMARY KEY AUTO_INCREMENT')
              ->addColumn('supplier', 'varchar', 100, 'NOT NULL')
              ->addColumn('model_code', 'varchar', 100, 'NOT NULL UNIQUE')
              ->addColumn('name', 'varchar', 255, 'NOT NULL')
              ->addColumn('is_copy', 'tinyint', 1, 'NOT NULL DEFAULT 0')
              ->addColumn('origin_model_id', 'int', 10, 'NULL')
              // ... 其他字段
    });
    
    // 2. ai_api_key - API密钥管理
    // (已移除 ai_assistant)
    // 4. ai_tenant - 多租户管理
    // 5. ai_model_monitoring - 模型性能监控
}
```

#### 2. Model实体实现

所有Model实体必须实现三个接口方法：

```php
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

public function setup(ModelSetup $setup, Context $context): void {}
public function upgrade(ModelSetup $setup, Context $context): void {}
public function install(ModelSetup $setup, Context $context): void {}
```

**注意事项**:
- ✅ 必须使用 `ModelSetup`，不是 `Setup`
- ✅ 必须使用 `Context` from `Weline\Framework\Setup\Data`
- ✅ 必须有 `: void` 返回类型声明

#### 3. REST API实现

API控制器必须继承 `FrontendRestController` 或 `BackendRestController`：

```php
use Weline\Framework\App\Controller\FrontendRestController;

class Chat extends FrontendRestController
{
    // ✅ 使用父类方法
    return $this->success('消息', $data);
    return $this->error('错误消息', $code);
    
    // ❌ 不要重写这些方法
    // private function success() {} // 会导致访问级别冲突
}
```

#### 4. 路由配置

```xml
<!-- app/code/Weline/Ai/etc/routes.xml -->
<config>
    <router area="frontend_rest_api">
        <route path="/api/v1/chat" method="POST">
            <module>Weline_Ai</module>
            <controller>Api\Chat</controller>
            <action>post</action>
        </route>
    </router>
</config>
```

### 已知问题和解决方案

#### 问题1: 数据库表未自动创建

**症状**: 运行 `setup:upgrade` 后表未在数据库中创建

**原因**: Setup/Install.php 中的 `createTable()` 可能需要特定的调用方式

**待验证解决方案**:
1. 检查其他模块（如 `Weline\Backend\Model\Menu`）如何创建表
2. 可能需要在Model的 `setup()` 方法中创建表
3. 或使用原生SQL创建表

**临时解决方案**: 
```bash
# 创建测试脚本验证功能
php test_ai_module.php
```

#### 问题2: Model接口签名错误

**错误信息**: `Declaration of Model::setup() must be compatible with...`

**解决方案**: 使用正确的参数类型
```php
// ❌ 错误
use Weline\Framework\Setup\Setup;
public function setup(Setup $setup, Context $context) {}

// ✅ 正确
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;
public function setup(ModelSetup $setup, Context $context): void {}
```

#### 问题3: Controller方法访问级别冲突

**错误信息**: `Access level to Controller::success() must be protected`

**解决方案**: 不要重写父类的 `success()` 和 `error()` 方法，直接使用

### 开发进度

**已完成** (32/77 tasks, 41.6%):
- ✅ 模块结构和注册
- ✅ 5个Model实体（~475 LOC）
- ✅ 5个Service服务（~395 LOC）
- ✅ 3个API控制器（~450 LOC）
- ✅ 8个REST API端点
- ✅ 13个测试文件（~600 LOC）
- ✅ 路由配置
- ✅ 模块成功安装

**待完成**:
- ⏳ 数据库表实际创建验证
- ⏳ Chat Service集成真实AI API
- ⏳ API认证中间件
- ⏳ 测试执行和验证

### 使用建议

#### 1. 测试API端点

```bash
# 确保模块已安装
php bin/w setup:upgrade

# 测试API（需要先创建测试数据）
php bin/w http:request POST /api/v1/chat \
  -d '{"prompt":"你好","model_code":"gpt-3.5-turbo","session_id":"test"}'

php bin/w http:request GET /api/v1/model/1
```

#### 2. 创建测试数据

使用提供的测试脚本：
```bash
php test_ai_module.php
```

#### 3. 验证安装

```bash
# 检查模块状态
php bin/w module:list | grep "Ai"

# 查看路由
grep -r "api/v1" generated/routers/
```

## API参考

### 已实现的REST API端点

#### 1. Chat API
```
POST /api/v1/chat
Content-Type: application/json

{
  "prompt": "你的问题",
  "model_code": "gpt-3.5-turbo",
  "session_id": "unique_session_id"
}
```

#### 2. Model Management API
```
GET    /api/v1/model/{id}           # 获取模型信息
POST   /api/v1/model/{id}/copy      # 拷贝模型
DELETE /api/v1/model/{id}           # 删除拷贝模型
```

#### 3. API Key Management
```
POST   /api/v1/api-key              # 创建API密钥
GET    /api/v1/api-key              # 获取密钥列表
GET    /api/v1/api-key/{id}         # 获取单个密钥
DELETE /api/v1/api-key/{id}         # 撤销密钥
```

## 开发文档

详细的开发指南请参考：
- [WelineFramework模块开发完整指南](../../../Framework/doc/模块开发完整指南.md) - **新增，必读**
- [Weline_Ai最终状态报告](../../docs/FINAL_STATUS.md)
- [Weline_Ai实施进度](../../docs/IMPLEMENTATION_PROGRESS.md)
