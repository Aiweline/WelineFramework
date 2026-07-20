# AI模块使用手册

## 简介

Weline_Ai 模块为您提供强大的AI服务功能，支持文本生成、翻译、代码生成等多种AI应用场景。本手册将帮助您快速上手使用AI模块的各项功能。

## 快速开始

### 1. 模块安装

AI模块已集成在Weline框架中，无需额外安装。确保您的系统已正确安装Weline框架。

### 2. 初始化配置

首次使用前，需要初始化AI模块配置：

```bash
# 收集AI模型配置
php bin/w ai:model:collect

# 扫描场景适配器
php bin/w ai:adapter:scan

# 初始化默认模型
php bin/w ai:default-model:manage --action=init
```

### 3. 基本使用

#### 通过代码调用

```php
use Weline\Ai\Service\AiService;

// 仅用于受控 CLI、Runner 或模块内部服务。
// 不得在 HTTP 请求或 SSE 控制器中直接调用后输出到浏览器。
$response = AiService::generateText('请介绍一下人工智能的发展历史');
```

#### 通过浏览器启动可恢复任务

```js
const api = await Weline.load('api');
const task = await api.resource('runtime_task').start({
  type_code: 'ai.chat_generation',
  input: {
    message: '请介绍一下人工智能的发展历史',
    request_id: crypto.randomUUID(),
  },
}, { silent: true });

const stream = api.createStream(task.stream_channel, {
  task_id: task.task_id,
  lease_id: task.lease_id,
});
stream.addEventListener('chunk', (event) => renderChunk(JSON.parse(event.data).chunk));
```

## 功能详解

以下 PHP 示例仅用于受控 CLI、Runner 或模块内部服务；浏览器交互请使用上面的可恢复任务流程。

### AI模型管理

#### 查看可用模型

在后台管理界面中：
1. 登录后台管理系统
2. 导航到「AI 中心」>「AI 管理」，在「模型」Tab 中查看
3. 查看所有已配置的AI模型

#### 模型状态管理

- **激活模型**：点击模型列表中的状态开关
- **设置默认模型**：在默认模型配置页面设置
- **测试模型连接**：点击"测试连接"按钮

### 场景适配器

#### 翻译功能

```php
// 基本翻译
$translation = AiService::generateText(
    'Hello, how are you?',
    null,                    // 使用默认模型
    'translation',           // 使用翻译适配器
    'zh-CN',                // 目标语言
    [
        'target_language' => '中文',
        'strategy' => 'standard'
    ]
);
```

#### 代码生成

```php
// 生成PHP代码
$code = AiService::generateText(
    '创建一个用户管理类，包含增删改查方法',
    null,
    'code_generation',
    null,
    [
        'language' => 'php',
        'style' => 'psr',
        'include_comments' => true
    ]
);
```

### 可恢复事件订阅

对于长文本生成，浏览器必须让任务在独立 Runner 中执行，并通过可重连的 `StreamHandle` 接收事件：

```js
const stream = api.createStream(task.stream_channel, {
  task_id: task.task_id,
  lease_id: task.lease_id,
});
stream.addEventListener('chunk', (event) => appendChunk(JSON.parse(event.data).chunk));
stream.addEventListener('runtime_snapshot', (event) => restoreFromCheckpoint(event.data));
stream.addEventListener('completed', () => stream.close());

// 页面离开或短暂网络中断只会退订或重连，不会取消后台任务。
// 只有用户明确中止时才调用：await stream.cancel('user_cancelled');
```

`AiService::generateTextStream()` 仍是模块内部的回调能力；它不能作为浏览器 SSE 输出方式，也不能用来保存或恢复 PHP 调用栈。

### 多语言支持

#### 支持的语言

- 中文 (zh-CN)
- 英文 (en-US)
- 日文 (ja-JP)
- 韩文 (ko-KR)
- 法文 (fr-FR)
- 德文 (de-DE)
- 西班牙文 (es-ES)
- 俄文 (ru-RU)

#### 语言使用示例

```php
// 生成英文内容
$englishContent = AiService::generateText(
    '介绍人工智能',
    null,
    null,
    'en-US'
);

// 生成日文内容
$japaneseContent = AiService::generateText(
    '介绍人工智能',
    null,
    null,
    'ja-JP'
);
```

## 后台管理

### AI 管理页面（AI 中心 > AI 管理）

聚合页包含三个 Tab：**模型** | **适配器** | **供应商账户**。

#### 模型 Tab

1. **模型列表**：显示所有已配置的AI模型
2. **新增模型**：点击「新增模型」按钮添加模型
3. **模型详情**：查看模型的详细配置信息
4. **状态管理**：启用/禁用模型
5. **连接测试**：测试模型API连接状态

> 提示：也可运行 `php bin/w ai:model:collect` 扫描并导入模型配置。

#### 适配器 Tab（场景配置）

1. **适配器列表**：显示所有场景适配器
2. **适配器详情**：查看适配器的参数模板和使用示例
3. **状态管理**：启用/禁用适配器
4. **扫描更新**：扫描新的适配器

### 默认模型配置

1. **服务类型配置**：为不同服务类型设置默认模型
2. **优先级设置**：配置模型选择优先级
3. **保护状态查看**：查看哪些模型受到删除保护

## 浏览器 / HTTP 交互

聊天生成不再采用 HTTP 请求内执行或旧的直接生成、流式生成端点。浏览器通过
`Weline.Api.resource('runtime_task').start()` 启动 `ai.chat_generation`，再使用
`Weline.Api.createStream(task.stream_channel, { task_id, lease_id })` 接收持久事件。

任务句柄包含 `task_id`、`lease_id`、`stream_channel` 和租约到期时间。前端每次重连都会申请新的
SSE ticket，并从最后持久事件 ID 补发；关闭订阅只会退订，明确调用 `stream.cancel(reason)` 才会取消任务。

### 获取适配器列表

**端点**：`GET /ai/api/chat/adapters`

**响应格式**：
```json
{
  "success": true,
  "data": [
    {
      "code": "translation",
      "name": "翻译适配器",
      "description": "专门用于AI翻译任务",
      "version": "1.0.0",
      "supported_models": ["chat", "completion"]
    }
  ]
}
```

## 常见使用场景

### 1. 内容翻译

```php
// 将英文文档翻译为中文
$chineseDoc = AiService::generateText(
    $englishDocument,
    null,
    'translation',
    'zh-CN',
    [
        'target_language' => '中文',
        'strategy' => 'professional',
        'context' => '技术文档'
    ]
);
```

### 2. 代码生成

```php
// 生成数据库模型类
$modelCode = AiService::generateText(
    '创建一个Product模型类，包含name、price、description字段',
    null,
    'code_generation',
    null,
    [
        'language' => 'php',
        'framework' => 'laravel',
        'include_comments' => true,
        'include_tests' => true
    ]
);
```

### 3. 内容创作

```php
// 生成营销文案
$marketingContent = AiService::generateText(
    '为一款智能手表写一段营销文案，突出健康监测功能',
    null,
    'text_generation',
    'zh-CN'
);
```

### 4. 技术文档生成

```php
// 生成API文档
$apiDoc = AiService::generateText(
    '为用户管理API生成详细的接口文档',
    null,
    'code_generation',
    null,
    [
        'language' => 'markdown',
        'include_examples' => true
    ]
);
```

## 最佳实践

### 1. 提示词优化

- **明确具体**：提供清晰、具体的指令
- **上下文信息**：提供必要的背景信息
- **格式要求**：明确输出格式要求
- **示例引导**：提供期望输出的示例

### 2. 模型选择

- **任务匹配**：根据任务类型选择合适的模型
- **成本考虑**：平衡质量和成本
- **速度要求**：考虑响应速度需求
- **准确性要求**：根据准确性要求选择模型

### 3. 场景适配器使用

- **参数配置**：正确配置适配器参数
- **错误处理**：处理适配器验证错误
- **性能优化**：合理使用缓存机制
- **结果验证**：验证适配器输出结果

### 4. 错误处理

```php
try {
    $response = AiService::generateText($prompt, $modelCode, $scenarioCode);
    // 处理成功响应
} catch (\Exception $e) {
    // 记录错误日志
    error_log('AI服务调用失败: ' . $e->getMessage());
    
    // 提供备用方案
    $response = '抱歉，AI服务暂时不可用，请稍后重试。';
}
```

## 故障排除

### 常见问题

1. **API调用失败**
   - 检查网络连接
   - 验证API密钥配置
   - 确认模型状态正常

2. **响应质量不佳**
   - 优化提示词内容
   - 尝试不同的模型
   - 调整适配器参数

3. **响应速度慢**
   - 使用流式接口
   - 选择更快的模型
   - 优化提示词长度

4. **多语言问题**
   - 确认语言代码正确
   - 检查I18n模块配置
   - 验证模型多语言支持

### 获取帮助

如果遇到问题，可以：

1. 查看系统日志文件
2. 使用CLI命令验证配置
3. 联系技术支持团队
4. 查阅在线文档和FAQ

## 更新日志

### v1.1.0 (2026-03)
- 精简后台菜单：仅保留「AI 管理」「场景配置」
- AI 管理聚合页：模型 | 适配器 | 供应商账户 三 Tab
- 移除：营销工具、内容安全、A/B测试、训练数据等独立页面

### v1.0.0
- 初始版本发布
- 支持基本AI文本生成
- 内置翻译和代码生成适配器
- 提供完整的后台管理界面
- 支持CLI命令管理
