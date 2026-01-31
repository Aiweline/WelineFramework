# 04 - Asymmetric Visibility（非对称可见性）⭐⭐

[← 索引](README.md)

**性能影响**：无直接性能收益；提升封装与类型安全。

---

## 特性说明

允许属性的读取和写入有不同的可见性，例如 `public` 读取但 `private` 写入。

## 语法

```php
// 公开读取，私有写入
public private(set) int $id;

// 公开读取，受保护写入（子类可写）
public protected(set) string $status;
```

## 应用场景

| 场景 | 传统实现 | v3 实现 |
|------|---------|---------|
| Model ID | `private $id` + `getId()` | `public private(set) int $id` |
| 配置只读 | `readonly` 属性 | `public private(set)` 更灵活 |
| 状态机 | setter 中检查权限 | `protected(set)` 限制修改范围 |
| 缓存属性 | private + lazy getter | `public private(set)` + 延迟计算 |

## 实现规范

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\App;

/**
 * 应用配置类 - 使用非对称可见性
 */
class Config
{
    /**
     * 环境（只能在初始化时设置）
     */
    public private(set) string $environment;
    
    /**
     * 调试模式（只能内部修改）
     */
    public private(set) bool $debug = false;
    
    /**
     * 配置数据（子类可扩展）
     */
    public protected(set) array $data = [];
    
    /**
     * 模块配置（延迟加载）
     */
    public private(set) ?array $modules = null {
        get {
            if ($this->modules === null) {
                $this->modules = $this->loadModules();
            }
            return $this->modules;
        }
    }
    
    public function __construct(string $environment = 'production')
    {
        $this->environment = $environment;
        $this->debug = $environment !== 'production';
    }
    
    private function loadModules(): array
    {
        // 延迟加载模块配置
        return include BP . '/etc/modules.php';
    }
}

// 使用
$config = new Config('development');
echo $config->environment;  // ✅ development
echo $config->debug;        // ✅ true
$config->debug = false;     // ❌ Error: Cannot modify private(set) property
```

## Entity/DTO 模式

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\DataObject;

/**
 * 不可变数据传输对象
 */
readonly class ImmutableDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public array $data = []
    ) {}
}

/**
 * 部分可变实体
 */
class Entity
{
    // ID 创建后不可变
    public private(set) int $id;
    
    // 状态只能通过特定方法修改
    public private(set) string $status = 'draft';
    
    // 普通可变属性
    public string $title;
    public string $content;
    
    public function __construct(int $id)
    {
        $this->id = $id;
    }
    
    public function publish(): void
    {
        if ($this->status !== 'draft') {
            throw new \LogicException('Only draft can be published');
        }
        $this->status = 'published';
    }
    
    public function archive(): void
    {
        $this->status = 'archived';
    }
}
```
