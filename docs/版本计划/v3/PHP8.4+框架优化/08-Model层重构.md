# 08 - Model 层重构 ⭐⭐

[← 索引](README.md)

**性能影响**：以代码简化为主，与 getter/setter 性能等价，无显著运行时提升。

---

## 目标

- 使用 Property Hooks 替代 `getData()`/`setData()`
- 使用非对称可见性保护关键属性
- 支持虚拟属性（计算字段）
- 自动类型转换和验证

## 架构

```
AbstractModel (Property Hooks + Asymmetric Visibility)
    ├── EntityModel (业务实体)
    ├── DataModel (数据传输)
    └── ValueObject (值对象)
```

## 实现要点

```php
<?php
declare(strict_types=1);

namespace Weline\Framework\Database\Model;

abstract class AbstractModel
{
    // 核心属性
    public private(set) ?int $id = null;
    public private(set) string $created_at;
    public string $updated_at;
    
    // 数据容器
    protected array $data = [];
    protected private(set) array $originalData = [];
    
    // 脏数据检测
    public bool $isDirty { get => $this->data !== $this->originalData; }
    
    // 表名（子类定义）
    abstract public static function getTableName(): string;
    
    // 字段定义（通过属性注解）
    abstract public static function getFields(): array;
}
```
