# SOLID原则重构说明

## 概述

本次重构按照SOLID原则对主题文件覆盖机制进行了优化，提高了代码的可维护性、可扩展性和可测试性。

## SOLID原则应用

### 1. 单一职责原则 (Single Responsibility Principle, SRP)

**原则**：一个类应该只有一个引起它变化的原因。

**重构前**：
- `AssetMerger` 类负责：获取主题链、扫描目录、合并资源、去重
- `TemplateFetchFile` 类负责：文件解析、路径构建、主题链查找

**重构后**：
- `ThemeChainResolver` - 只负责解析主题继承链
- `AssetScanner` - 只负责扫描资源目录
- `AssetDeduplicator` - 只负责去重资源文件
- `ThemePathResolver` - 只负责解析主题文件路径
- `AssetMerger` - 只负责合并资源（协调其他组件）
- `TemplateFetchFile` - 只负责观察者逻辑（委托给ThemePathResolver）

### 2. 开闭原则 (Open/Closed Principle, OCP)

**原则**：对扩展开放，对修改关闭。

**实现方式**：
- 使用接口定义契约：`ThemeChainResolverInterface`、`AssetScannerInterface` 等
- 通过实现接口扩展功能，无需修改现有代码
- 可以创建新的实现类替换默认实现

**示例**：
```php
// 可以创建新的扫描器实现
class FastAssetScanner implements AssetScannerInterface
{
    public function scanDirectory(string $directory): array
    {
        // 使用更快的扫描算法
    }
}
```

### 3. 里氏替换原则 (Liskov Substitution Principle, LSP)

**原则**：子类对象可以替换父类对象，而程序逻辑不变。

**实现方式**：
- 所有实现类都可以替换接口使用
- 接口的实现类必须完全实现接口契约

### 4. 接口隔离原则 (Interface Segregation Principle, ISP)

**原则**：客户端不应该依赖它不需要的接口。

**实现方式**：
- 创建小而专注的接口：
  - `ThemeChainResolverInterface` - 只包含主题链解析方法
  - `AssetScannerInterface` - 只包含目录扫描方法
  - `AssetDeduplicatorInterface` - 只包含去重方法
  - `ThemePathResolverInterface` - 只包含路径解析方法
  - `AssetMergerInterface` - 只包含资源合并方法

### 5. 依赖倒置原则 (Dependency Inversion Principle, DIP)

**原则**：高层模块不应该依赖低层模块，两者都应该依赖抽象。

**实现方式**：
- `AssetMerger` 依赖 `ThemeChainResolverInterface` 而不是具体实现
- `AssetMerger` 依赖 `AssetScannerInterface` 而不是具体实现
- `TemplateFetchFile` 依赖 `ThemePathResolverInterface` 而不是具体实现

**重构前**：
```php
class AssetMerger
{
    private function getThemeChain(...) { } // 直接实现
    private function scanAssetDirectory(...) { } // 直接实现
}
```

**重构后**：
```php
class AssetMerger implements AssetMergerInterface
{
    public function __construct(
        ThemeChainResolverInterface $themeChainResolver,
        AssetScannerInterface $assetScanner
    ) {
        // 依赖抽象接口
    }
}
```

## 重构后的类结构

### 接口层 (Interface Layer)

```
app/code/Weline/Theme/Helper/Interface/
├── ThemeChainResolverInterface.php    # 主题链解析接口
├── AssetScannerInterface.php          # 资源扫描接口
├── AssetDeduplicatorInterface.php     # 资源去重接口
├── AssetMergerInterface.php           # 资源合并接口
└── ThemePathResolverInterface.php      # 路径解析接口
```

### 实现层 (Implementation Layer)

```
app/code/Weline/Theme/Helper/
├── ThemeChainResolver.php              # 主题链解析实现
├── AssetScanner.php                    # 资源扫描实现
├── AssetDeduplicator.php               # 资源去重实现
├── AssetMerger.php                     # 资源合并实现（重构后）
└── ThemePathResolver.php               # 路径解析实现
```

### 观察者层 (Observer Layer)

```
app/code/Weline/Theme/Observer/
└── TemplateFetchFile.php              # 模板文件获取观察者（重构后）
```

## 依赖关系图

```
TemplateFetchFile
    ↓ 依赖
ThemePathResolverInterface
    ↓ 实现
ThemePathResolver
    ↓ 依赖
ThemeChainResolverInterface
    ↓ 实现
ThemeChainResolver

AssetMerger
    ↓ 依赖
ThemeChainResolverInterface + AssetScannerInterface
    ↓ 实现
ThemeChainResolver + AssetScanner
```

## 优势

### 1. 可测试性提升
- 每个类职责单一，易于单元测试
- 可以轻松创建Mock对象进行测试
- 接口可以独立测试

### 2. 可维护性提升
- 代码职责清晰，易于理解
- 修改某个功能不影响其他功能
- 代码结构清晰，易于定位问题

### 3. 可扩展性提升
- 可以轻松添加新的实现类
- 可以替换默认实现
- 符合开闭原则，扩展无需修改现有代码

### 4. 可复用性提升
- 各个组件可以独立复用
- 接口定义清晰，易于在其他场景使用

## 使用示例

### 基本使用（自动依赖注入）

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\AssetMerger;

// ObjectManager会自动注入依赖
$assetMerger = ObjectManager::getInstance(AssetMerger::class);
$jsAssets = $assetMerger->mergeAssets('js', 'frontend');
```

### 自定义实现

```php
// 创建自定义扫描器
class CustomAssetScanner implements AssetScannerInterface
{
    public function scanDirectory(string $directory): array
    {
        // 自定义扫描逻辑
    }
}

// 手动注入依赖
$customScanner = ObjectManager::getInstance(CustomAssetScanner::class);
$themeChainResolver = ObjectManager::getInstance(ThemeChainResolver::class);
$theme = ObjectManager::getInstance(WelineTheme::class);

$assetMerger = new AssetMerger($theme, $themeChainResolver, $customScanner);
```

## 重构说明

- 所有公共API保持不变
- `AssetMerger::mergeAssets()` 方法签名不变
- `TemplateFetchFile::execute()` 方法签名不变
- `ConfigMerger` 已更新为使用 `ThemeChainResolverInterface`

## 测试

重构后的代码更容易测试：

```php
// 可以轻松创建Mock对象
$mockResolver = $this->createMock(ThemeChainResolverInterface::class);
$mockScanner = $this->createMock(AssetScannerInterface::class);

$assetMerger = new AssetMerger($theme, $mockResolver, $mockScanner);
```

## 相关文件

- [接口定义](app/code/Weline/Theme/Helper/Interface/)
- [实现类](app/code/Weline/Theme/Helper/)
- [观察者](app/code/Weline/Theme/Observer/TemplateFetchFile.php)
