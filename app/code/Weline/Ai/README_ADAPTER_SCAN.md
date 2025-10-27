# AI 场景适配器扫描功能说明

## 概述

AI 模块现在支持扫描其他模块中的场景适配器。其他模块只需要创建 `Ai/Adapter` 目录并在其中创建实现了 `ScenarioAdapterInterface` 接口的适配器类，系统会自动发现并注册这些适配器。

## 功能特性

### 1. 自动扫描适配器

扫描器会自动扫描以下位置的适配器：
- `app/code/Weline/Ai/Adapter/` - Weline_Ai 模块自身的适配器
- `app/code/{Vendor}/{ModuleName}/Ai/Adapter/` - 其他模块的适配器

### 2. 适配器接口

所有适配器必须实现 `Weline\Ai\Interface\ScenarioAdapterInterface` 接口。

接口位置：`app/code/Weline/Ai/Interface/ScenarioAdapterInterface.php`

### 3. 自动注册机制

当运行以下命令时，会自动触发适配器扫描：
- `setup:upgrade` - 系统升级时
- `module:upgrade` - 模块升级时
- `module:install` - 模块安装时

也可以通过命令手动扫描：
```bash
php bin/m ai:adapter:scan
```

### 4. 事件监听

`app/code/Weline/Ai/Observer/ModuleUpgradeAdapterScanObserver.php` 监听模块升级事件并自动扫描适配器。

## 如何在其他模块中创建适配器

### 步骤 1: 创建目录结构

在其他模块中创建以下目录：
```
app/code/{Vendor}/{ModuleName}/Ai/Adapter/
```

### 步骤 2: 创建适配器类

创建适配器类文件，例如：
```
app/code/{Vendor}/{ModuleName}/Ai/Adapter/MyCustomAdapter.php
```

### 步骤 3: 实现接口

```php
<?php
declare(strict_types=1);

namespace {Vendor}\{ModuleName}\Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class MyCustomAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'my_custom';
    }

    public function getName(): string
    {
        return '我的自定义适配器';
    }

    public function getDescription(): string
    {
        return '这是一个自定义场景适配器';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedModelTypes(): array
    {
        return ['gpt-4', 'gpt-3.5-turbo'];
    }

    public function adaptPrompt(string $prompt, array $params = []): string
    {
        // 自定义处理逻辑
        return $prompt;
    }

    public function processResponse(string $response, array $params = []): string
    {
        // 自定义处理逻辑
        return $response;
    }

    public function validateParams(array $params = []): array
    {
        // 验证参数
        return [];
    }

    public function getParamTemplate(): array
    {
        return [];
    }

    public function getExamples(): array
    {
        return [];
    }

    public function supportsModel(string $modelCode): bool
    {
        return in_array($modelCode, $this->getSupportedModelTypes());
    }
}
```

### 步骤 4: 运行升级

在模块升级时，适配器会自动被扫描和注册：

```bash
php bin/m setup:upgrade
```

或手动扫描：

```bash
php bin/m ai:adapter:scan
```

## 示例

### 模块结构示例

假设有一个名为 `GuoLaiRen_Translation` 的模块：

```
app/code/GuoLaiRen/Translation/
├── Ai/
│   └── Adapter/
│       └── ProductDescriptionAdapter.php
├── etc/
├── Controller/
└── Model/
```

### 适配器文件示例

`ProductDescriptionAdapter.php`:

```php
<?php
declare(strict_types=1);

namespace GuoLaiRen\Translation\Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class ProductDescriptionAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'product_description';
    }

    public function getName(): string
    {
        return '产品描述适配器';
    }

    public function getDescription(): string
    {
        return '专门用于生成产品描述的AI适配器';
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
        $productName = $params['product_name'] ?? '';
        $category = $params['category'] ?? '';
        
        return "请为以下产品生成一段吸引人的产品描述：\n产品名称：{$productName}\n分类：{$category}\n要求：{$prompt}";
    }

    public function processResponse(string $response, array $params = []): string
    {
        // 可以在这里对响应进行后处理
        return trim($response);
    }

    public function validateParams(array $params = []): array
    {
        $errors = [];
        
        if (empty($params['product_name'])) {
            $errors[] = '产品名称不能为空';
        }
        
        return $errors;
    }

    public function getParamTemplate(): array
    {
        return [
            'product_name' => [
                'type' => 'string',
                'required' => true,
                'description' => '产品名称'
            ],
            'category' => [
                'type' => 'string',
                'required' => false,
                'description' => '产品分类'
            ]
        ];
    }

    public function getExamples(): array
    {
        return [
            [
                'title' => '生成产品描述',
                'description' => '为新产品生成吸引人的描述',
                'input' => '这是一款高品质的蓝牙耳机',
                'expected_output' => '产品描述文本'
            ]
        ];
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }
}
```

## 技术细节

### AdapterScanner 类

位置：`app/code/Weline/Ai/Service/AdapterScanner.php`

主要方法：
- `scanAllAdapters()`: 扫描所有适配器（包括其他模块）
- `scanOtherModulesAdapters()`: 专门扫描其他模块的适配器
- `getClassNameFromFile()`: 从文件路径推导类名

### 类名推导规则

对于其他模块的适配器，类名推导规则为：
```
\{Vendor}\{ModuleName}\Ai\Adapter\{FileName}
```

例如：
- 文件：`app/code/GuoLaiRen/Translation/Ai/Adapter/ProductAdapter.php`
- 类名：`\GuoLaiRen\Translation\Ai\Adapter\ProductAdapter`

## 常见问题

### Q: 适配器没有被扫描到？

A: 请确认：
1. 适配器类实现了 `ScenarioAdapterInterface` 接口
2. 适配器文件位于 `{ModulePath}/Ai/Adapter/` 目录
3. 适配器文件名以 `Adapter.php` 结尾
4. 运行了 `setup:upgrade` 或 `ai:adapter:scan` 命令

### Q: 如何手动触发适配器扫描？

A: 运行以下命令：
```bash
php bin/m ai:adapter:scan
```

可以使用以下选项：
- `-v, --verbose`: 显示详细信息
- `--clean`: 清理无效的适配器

### Q: 适配器需要特定的命名空间吗？

A: 是的，适配器的命名空间必须为：
```
{Vendor}\{ModuleName}\Ai\Adapter\{ClassName}
```

### Q: 适配器需要导入接口吗？

A: 是的，需要导入：
```php
use Weline\Ai\Interface\ScenarioAdapterInterface;
```

## 日志

适配器扫描的日志会记录在：
- `var/log/error.log` - 错误日志
- `var/log/dev.log` - 开发日志

## 更新日志

- 2025/10/27: 添加多模块适配器扫描支持

