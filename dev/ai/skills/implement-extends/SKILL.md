---
name: implement-extends
description: Implements Extends extension points in Weline Framework. Use when implementing extension points defined by other modules (e.g., AI adapters, SEO Providers, Feed Providers). NOTE - This is for IMPLEMENTING extensions, not defining them. 扩展, extends, adapter, provider.
globs:
  - "**/extends/**/*.php"
alwaysApply: false
---

# 实现 Extends 衍生功能（Extending）

本技能指导如何**实现**其他模块定义的扩展点。如果需要**定义新的扩展点**，请参考 `create-extends` 技能。

## 概述

Weline Framework 的 Extends 衍生功能允许模块通过实现其他模块定义的扩展点来扩展功能，无需修改原模块代码。

### 核心概念

```
[定义扩展点的模块] ← extends/module/目标模块/扩展点/ ← [实现扩展的模块]
       ↓
   extends.php (定义规约)
   Interface (定义接口)
       ↓
   自动发现和加载
```

## 何时使用

当你需要：
- 为 AI 模块添加新的场景适配器
- 为 SEO 模块添加 Sitemap Provider
- 为 SEO 模块添加 Feed Provider
- 扩展其他模块定义的任何扩展点
- 在不修改原模块的情况下添加功能

## 工作原理

### 1. 扩展点发现流程

```
1. ExtendsScanner 扫描所有模块的 extends/module/ 目录
   ↓
2. 根据目录结构识别扩展点（如 Weline_Seo/SitemapProvider/）
   ↓
3. 推断类名（根据命名空间规则）
   ↓
4. 保存到 generated/extends.php 注册表
   ↓
5. 运行时通过 ExtendsData 读取注册表
   ↓
6. 目标模块通过 Registry Service 获取所有实现
```

### 2. 类名推断规则

```
文件路径：
app/code/YourModule/extends/module/Weline_Seo/SitemapProvider/YourSitemapProvider.php

推断的命名空间：
YourModule\Extends\Module\Weline_Seo\SitemapProvider\YourSitemapProvider

注意：
- 必须包含 \Extends\Module\ 层级
- 目标模块名保持下划线格式（Weline_Seo）
```

## 实现步骤

### 步骤 1：查找扩展点定义

首先查看目标模块的 `extends.php` 和 `extends.md` 文档：

```bash
# 查看扩展点定义
cat app/code/Weline/Seo/extends.php
cat app/code/Weline/Seo/extends.md
```

关键信息：
- **path**: 扩展文件放置路径
- **interface**: 必须实现的接口
- **description**: 扩展点用途
- **required**: 是否必须实现接口
- **multiple**: 是否允许多个实现

### 步骤 2：创建扩展目录

根据扩展点定义的 `path` 创建目录：

```bash
# 示例：实现 Weline_Seo 的 SitemapProvider 扩展点
mkdir -p app/code/YourModule/extends/module/Weline_Seo/SitemapProvider
```

**目录结构规范：**
```
app/code/YourModule/
└── extends/
    └── module/                    # 扩展模块（固定）
        └── {TargetModule}/       # 目标模块名（如 Weline_Seo）
            └── {ExtensionPoint}/ # 扩展点名（如 SitemapProvider）
                └── YourProvider.php
```

### 步骤 3：实现接口

创建扩展类并实现指定的接口：

```php
<?php

declare(strict_types=1);

namespace YourModule\Extends\Module\Weline_Seo\SitemapProvider;

use Weline\Seo\Interface\SitemapProviderInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * YourModule Sitemap 提供者
 * 
 * 通过 extends 扩展点注册到 Weline_Seo 模块
 */
class YourSitemapProvider implements SitemapProviderInterface
{
    /**
     * 返回该 Provider 所属的 scope
     */
    public function getScope(): string
    {
        return 'your_scope';
    }
    
    /**
     * 返回该 Provider 所属的模块名称
     */
    public function getModule(): string
    {
        return 'YourVendor_YourModule';
    }
    
    /**
     * 生成 Sitemap 并返回 URL 列表
     */
    public function generateSitemaps(): array
    {
        $sitemapUrls = [];
        
        try {
            // 实现你的 Sitemap 生成逻辑
            // ...
        } catch (\Throwable $e) {
            // 错误处理
        }
        
        return $sitemapUrls;
    }
    
    /**
     * 返回描述信息
     */
    public function getDescription(): string
    {
        return __('YourModule Sitemap 生成器描述');
    }
}
```

### 步骤 4：依赖注入（如需要）

如果扩展类需要使用其他服务，使用构造函数注入：

```php
class YourSitemapProvider implements SitemapProviderInterface
{
    private YourService $yourService;
    
    public function __construct(
        YourService $yourService
    ) {
        $this->yourService = $yourService;
    }
    
    // ... 接口方法实现
}
```

### 步骤 5：刷新扩展注册表

创建扩展类后，刷新框架的扩展注册表：

```bash
# 删除缓存触发重新扫描
rm -rf generated/extends.php

# 或运行刷新脚本（如果有）
php regenerate_extends.php
```

### 步骤 6：验证注册

验证扩展是否被正确注册：

```php
<?php
// 测试脚本
require 'app/bootstrap.php';

use Weline\Framework\Extends\ExtendsData;

// 检查是否注册
$extendedBy = ExtendsData::getExtendedBy('Weline_Seo', true);
print_r($extendedBy['YourModule']);
```

## 完整示例

### 示例 1：实现 Sitemap Provider

**场景**：为 PageBuilder 模块实现 SEO Sitemap 生成功能

**1. 查看 Weline_Seo 的扩展点定义：**

```php
// app/code/Weline/Seo/extends.php
return [
    'extends' => [
        'SitemapProvider' => [
            'path' => 'extends/module/Weline_Seo/SitemapProvider',
            'interface' => 'Weline\Seo\Interface\SitemapProviderInterface',
            'description' => 'Sitemap 提供扩展点',
            'required' => false,
            'multiple' => true
        ]
    ]
];
```

**2. 创建目录结构：**

```bash
app/code/GuoLaiRen/PageBuilder/
└── extends/
    └── module/
        └── Weline_Seo/
            └── SitemapProvider/
                └── PageBuilderSitemapProvider.php
```

**3. 实现 Provider：**

```php
<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Extends\Module\Weline_Seo\SitemapProvider;

use GuoLaiRen\PageBuilder\Service\SitemapService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SitemapProviderInterface;

class PageBuilderSitemapProvider implements SitemapProviderInterface
{
    private SitemapService $sitemapService;
    
    public function __construct()
    {
        $this->sitemapService = ObjectManager::getInstance(SitemapService::class);
    }
    
    public function getScope(): string
    {
        return 'page_builder';
    }
    
    public function getModule(): string
    {
        return 'GuoLaiRen_PageBuilder';
    }
    
    public function generateSitemaps(): array
    {
        try {
            return $this->sitemapService->generateForAllWebsites();
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                error_log('PageBuilderSitemapProvider error: ' . $e->getMessage());
            }
            return [];
        }
    }
    
    public function getDescription(): string
    {
        return __('PageBuilder 页面构建器 Sitemap 生成器');
    }
}
```

**4. 刷新注册表：**

```bash
rm generated/extends.php
php regenerate_extends.php
```

### 示例 2：实现 AI 适配器

**场景**：为模块实现自定义 AI 场景适配器

```php
<?php

declare(strict_types=1);

namespace YourModule\Extends\Module\Weline_Ai\Adapter;

use Weline\Ai\Interface\ScenarioAdapterInterface;

class CustomScenarioAdapter implements ScenarioAdapterInterface
{
    public function getCode(): string
    {
        return 'custom_scenario';
    }
    
    public function getName(): string
    {
        return __('自定义场景适配器');
    }
    
    public function getDescription(): string
    {
        return __('处理自定义场景的 AI 适配器');
    }
    
    public function adaptRequest(array $request): array
    {
        // 适配请求格式
        return $request;
    }
    
    public function adaptResponse(array $response): array
    {
        // 适配响应格式
        return $response;
    }
}
```

## 命名空间规则

### ✅ 正确的命名空间

```php
// 文件：app/code/Vendor/Module/extends/module/Weline_Seo/SitemapProvider/Provider.php
namespace Vendor\Module\Extends\Module\Weline_Seo\SitemapProvider;
//      ^^^^^^^^^^^^^^ 你的模块  ^^^^^^^^^^^^^^ 固定层级 ^^^^^^^^^^^ 目标模块 ^^^^^^^^^^^^^^^ 扩展点

class Provider implements SitemapProviderInterface { }
```

### ❌ 错误的命名空间

```php
// 错误 1：缺少 Module 层级
namespace Vendor\Module\Extends\Weline_Seo\SitemapProvider;
//                              ^^^ 缺少 Module 层级

// 错误 2：目标模块名格式错误
namespace Vendor\Module\Extends\Module\Weline\Seo\SitemapProvider;
//                                      ^^^^^^^^^^^ 应该是 Weline_Seo（下划线）

// 错误 3：路径层级错误
namespace Vendor\Module\Extends\SitemapProvider;
//                              ^^^ 缺少 Module\Weline_Seo 层级
```

## 接口实现检查清单

在实现扩展前，确保：

- [ ] 查看了目标模块的 `extends.php` 定义
- [ ] 阅读了目标模块的 `extends.md` 文档
- [ ] 目录结构符合扩展点定义的 `path`
- [ ] 命名空间包含 `\Extends\Module\` 层级
- [ ] 实现了指定的接口的所有方法
- [ ] 使用了依赖注入（如需要其他服务）
- [ ] 添加了适当的错误处理
- [ ] 刷新了扩展注册表
- [ ] 验证了扩展被正确发现

## 常见扩展点

### Weline_Seo 模块

| 扩展点 | 接口 | 用途 |
|--------|------|------|
| SitemapProvider | `SitemapProviderInterface` | 生成 Sitemap XML |
| FeedProvider | `FeedProviderInterface` | 提供 SEO Feed 数据 |

### Weline_Ai 模块

| 扩展点 | 接口 | 用途 |
|--------|------|------|
| Adapter | `ScenarioAdapterInterface` | AI 场景适配器 |

### 查看所有扩展点

```php
<?php
require 'app/bootstrap.php';

use Weline\Framework\Extends\ExtendsData;

$registry = ExtendsData::getRegistry();
foreach ($registry as $moduleName => $data) {
    if (!empty($data['extends']['extends'])) {
        echo "模块: {$moduleName}\n";
        foreach ($data['extends']['extends'] as $extName => $extConfig) {
            echo "  - {$extName}: {$extConfig['description']}\n";
            echo "    接口: {$extConfig['interface']}\n";
            echo "    路径: {$extConfig['path']}\n\n";
        }
    }
}
```

## 故障排查

### 问题 1：扩展未被发现

**症状**：Registry Service 找不到你的扩展

**检查：**
```bash
# 1. 检查目录结构
ls -la app/code/YourModule/extends/module/

# 2. 检查命名空间
grep "namespace" app/code/YourModule/extends/module/.../*.php

# 3. 检查注册表
cat generated/extends.php | grep "YourModule"

# 4. 手动刷新
rm generated/extends.php
php regenerate_extends.php
```

### 问题 2：类名推断失败

**症状**：`class_exists()` 返回 false

**原因**：命名空间不符合推断规则

**解决**：
- 确保命名空间包含 `\Extends\Module\`
- 目标模块名使用下划线格式（如 `Weline_Seo`）
- 文件路径与类名一致

### 问题 3：接口方法缺失

**症状**：实例化时报错

**解决**：
```php
// 查看接口定义
cat app/code/Weline/Seo/Interface/SitemapProviderInterface.php

// 确保实现了所有方法
class YourProvider implements SitemapProviderInterface
{
    public function getScope(): string { }
    public function getModule(): string { }
    public function generateSitemaps(): array { }
    public function getDescription(): string { }
}
```

## 最佳实践

### 1. 错误处理

```php
public function generateSitemaps(): array
{
    try {
        // 主要逻辑
        return $this->doGenerate();
    } catch (\Throwable $e) {
        // 不要让异常传播到外部
        if (defined('DEV') && DEV) {
            error_log('Provider error: ' . $e->getMessage());
        }
        return []; // 返回空数组而非抛出异常
    }
}
```

### 2. 依赖注入

```php
// ✅ 使用构造函数注入
public function __construct(
    SomeService $service
) {
    $this->service = $service;
}

// ❌ 不要在方法中使用 ObjectManager
public function someMethod() {
    $service = ObjectManager::getInstance(SomeService::class); // 不推荐
}
```

### 3. 返回值类型

```php
// ✅ 明确返回类型
public function generateSitemaps(): array
{
    return ['url1', 'url2']; // 总是返回数组
}

// ❌ 不要返回 null 或其他类型
public function generateSitemaps(): array
{
    return null; // 违反接口约定
}
```

### 4. 文档注释

```php
/**
 * YourModule Sitemap 提供者
 * 
 * 功能：
 * - 为所有站点生成 sitemap.xml
 * - 自动收集已发布页面
 * - 设置优先级和更新频率
 * 
 * 通过 extends 扩展点注册到 Weline_Seo 模块
 * 
 * @see SitemapProviderInterface
 * @see SitemapService
 */
class YourSitemapProvider implements SitemapProviderInterface
{
    // ...
}
```

## 测试你的扩展

### 单元测试

```php
<?php

namespace YourModule\Test\Unit\Extends;

use PHPUnit\Framework\TestCase;
use YourModule\Extends\Module\Weline_Seo\SitemapProvider\YourProvider;
use Weline\Framework\Manager\ObjectManager;

class YourProviderTest extends TestCase
{
    private YourProvider $provider;
    
    protected function setUp(): void
    {
        $this->provider = ObjectManager::getInstance(YourProvider::class);
    }
    
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            \Weline\Seo\Interface\SitemapProviderInterface::class,
            $this->provider
        );
    }
    
    public function testGetScope(): void
    {
        $scope = $this->provider->getScope();
        $this->assertIsString($scope);
        $this->assertNotEmpty($scope);
    }
    
    public function testGenerateSitemaps(): void
    {
        $sitemaps = $this->provider->generateSitemaps();
        $this->assertIsArray($sitemaps);
    }
}
```

### 集成测试

```php
<?php
// 测试扩展是否被正确发现和加载

require 'app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Service\SitemapRegistryService;

$registry = ObjectManager::getInstance(SitemapRegistryService::class);
$providers = $registry->getProviders(true);

echo "找到 " . count($providers) . " 个 Provider\n";

foreach ($providers as $provider) {
    echo "- " . $provider->getModule() . " (" . $provider->getScope() . ")\n";
    echo "  描述: " . $provider->getDescription() . "\n";
}
```

## 相关技能

- `create-extends` - 定义新的扩展点（而非实现）
- `module-development` - 模块开发完整流程
- `php-unit-testing` - PHPUnit 测试
- `error-learning` - 错误自学习

## 快速参考

```bash
# 查看扩展点定义
cat app/code/{TargetModule}/extends.php
cat app/code/{TargetModule}/extends.md

# 创建扩展目录
mkdir -p app/code/YourModule/extends/module/{TargetModule}/{ExtensionPoint}

# 实现接口
# - 命名空间: YourModule\Extends\Module\{TargetModule}\{ExtensionPoint}
# - 实现接口: extends.php 中定义的接口

# 刷新注册表
rm generated/extends.php
php regenerate_extends.php

# 测试
php test_your_extension.php
```
