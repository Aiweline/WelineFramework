# Weline_Ai 模块扩展文档

## 概述

Weline_Ai 模块提供了多个扩展点，允许其他模块扩展 AI 功能。本文档详细说明如何使用这些扩展点。

## 快速开始

### 创建场景适配器

1. 在您的模块中创建扩展目录：`extends/module/Weline_Ai/Adapter/`
2. 创建适配器类，实现 `Weline\Ai\Interface\ScenarioAdapterInterface` 接口
3. 实现所有必需的方法

### 示例代码

```php
<?php
namespace Weline\MyModule\Extends\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class MyCustomAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'my_custom_adapter';
    }

    public function getName(): string
    {
        return '我的自定义适配器';
    }

    // ... 实现其他必需方法
}
```

## 详细说明

### Adapter 扩展点

**路径**: `extends/module/Weline_Ai/Adapter`

**接口**: `Weline\Ai\Interface\ScenarioAdapterInterface`

**用途**: 扩展 AI 场景适配功能，可以为不同的使用场景提供专门的适配器。

**要求**:
- 必须实现 `ScenarioAdapterInterface` 接口
- 必须实现所有接口方法
- 允许多个实现

### Handler 扩展点

**路径**: `extends/module/Weline_Ai/Handler`

**接口**: `Weline\Ai\Interface\HandlerInterface`

**用途**: 扩展 AI 处理逻辑，可以自定义处理流程。

**要求**:
- 必须实现 `HandlerInterface` 接口
- 允许多个实现

### Validator 扩展点

**路径**: `extends/module/Weline_Ai/Validator`

**接口**: `Weline\Ai\Interface\ValidatorInterface`

**用途**: 扩展 AI 输入验证功能。

**要求**:
- 必须实现 `ValidatorInterface` 接口
- 只允许单个实现

## 高级用法

### 多个适配器的使用

当有多个适配器时，系统会根据适配器的 `getCode()` 方法返回的代码来选择使用哪个适配器。

### 自定义处理流程

通过 Handler 扩展点，您可以完全自定义 AI 处理流程，包括：
- 输入预处理
- 输出后处理
- 错误处理
- 日志记录

## 示例代码

### 完整的适配器示例

```php
<?php
namespace Weline\MyModule\Extends\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class CodeGenerationAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'code_generation';
    }

    public function getName(): string
    {
        return '代码生成适配器';
    }

    public function getDescription(): string
    {
        return '专门用于代码生成的场景适配器';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['*'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        $language = $params['language'] ?? 'PHP';
        return "Generate {$language} code for: {$prompt}";
    }

    public function processResponse(string $response, array $params = []): string
    {
        // 提取代码块
        if (preg_match('/```(?:\w+)?\s*([\s\S]*?)```/', $response, $matches)) {
            return trim($matches[1]);
        }
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        // 验证逻辑
        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'language' => 'string',
            'style' => 'string'
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'input' => 'Create a function to calculate factorial',
                'output' => 'function factorial($n) { ... }'
            ]
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
```

## 常见问题

### Q: 如何知道我的适配器是否被加载？

A: 系统会在 `generated/extends.php` 文件中记录所有扩展信息，您可以查看该文件确认。

### Q: 多个适配器如何选择？

A: 系统会根据适配器的 `getCode()` 方法返回的代码来选择，您可以在调用时指定使用哪个适配器。

### Q: 适配器可以访问数据库吗？

A: 可以，适配器类可以正常使用框架的所有功能，包括数据库访问。

## 最佳实践

1. **命名规范**: 使用清晰的类名和代码标识
2. **错误处理**: 实现完善的错误处理机制
3. **文档注释**: 为所有方法添加详细的文档注释
4. **单元测试**: 为适配器编写单元测试
5. **版本管理**: 在适配器中实现版本管理

---

## Agent 扩展点（智能体）

### 概述

Agent 扩展点允许其他模块创建 AI 智能体。智能体与适配器不同，它拥有 Tool（工具）调用能力，可以自行编排多轮 AI 交互。

### 扩展目录

```
your_module/
└── extends/
    └── module/
        └── Weline_Ai/
            └── Agent/
                └── YourAgent.php    # 实现 AgentInterface
```

### 接口说明

智能体必须实现 `Weline\Ai\Interface\AgentInterface`：

| 方法 | 说明 |
|------|------|
| `getCode()` | 唯一标识码 |
| `getName()` | 显示名称 |
| `getDescription()` | 擅长领域描述 |
| `getVersion()` | 版本号 |
| `getScenarios()` | 支持的场景码列表 |
| `getTools()` | 返回 ToolInterface[] |
| `getSystemPrompt()` | 静态规约/系统提示词 |
| `execute()` | 执行任务（管理 Tool 调用循环） |
| `supportsModel()` | 是否支持指定模型 |
| `getMaxIterations()` | Tool 调用最大轮次 |

### Tool 接口

工具必须实现 `Weline\Ai\Interface\ToolInterface`：

| 方法 | 说明 |
|------|------|
| `getName()` | 函数名（snake_case） |
| `getDescription()` | AI 可见的描述 |
| `getParameters()` | JSON Schema 格式的参数定义 |
| `execute()` | 执行并返回结果 |
| `isEnabled()` | 是否启用 |

