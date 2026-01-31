# PHP 8.4+ 特性应用

## 概述

Taglib v2 充分利用 PHP 8.4+ 的新特性，实现极致性能和代码简洁性。

## Property Hooks

Property Hooks 是 PHP 8.4 引入的重要特性，允许在属性访问时定义自定义逻辑。

### 基本用法

```php
class Node
{
    public bool $isDynamic {
        get => false;
    }
}
```

### 在 Taglib 中的应用

#### TagNode 计算属性

```php
class TagNode extends Node
{
    // 自动计算是否为动态节点
    public bool $isDynamic {
        get => $this->hasDynamicAttrs || $this->hasDynamicChildren;
    }
    
    // 属性映射延迟计算
    public array $attrMap {
        get {
            $map = [];
            foreach ($this->attributes as $attr) {
                $map[$attr->name] = $attr;
            }
            return $map;
        }
    }
}
```

#### AttrNode 静态值计算

```php
class AttrNode extends Node
{
    public ?string $staticValue {
        get {
            if (is_string($this->value)) {
                return $this->value;
            }
            if ($this->isDynamic) {
                return null;
            }
            return implode('', array_map(...));
        }
    }
}
```

### 优势

- **延迟求值** - 只在需要时计算
- **代码简洁** - 无需手动定义 getter
- **缓存友好** - 可配合缓存使用

## readonly 属性

### 构造函数属性提升

```php
class Node
{
    public function __construct(
        public readonly int $line,
        public readonly int $column = 0,
    ) {}
}
```

### 不可变数据结构

```php
class TextNode extends Node
{
    public function __construct(
        int $line,
        public readonly string $value,
    ) {
        parent::__construct($line);
    }
}
```

### 优势

- **线程安全** - 不可变对象天然线程安全
- **避免副作用** - 防止意外修改
- **优化潜力** - JIT 可进行更激进的优化

## 新数组函数

PHP 8.4 引入了新的数组函数，Taglib 中广泛使用。

### array_any()

检查是否有任意元素满足条件：

```php
// 检查是否有动态属性
$hasDynamic = array_any(
    $this->attributes,
    static fn(AttrNode $a): bool => $a->isDynamic
);
```

### array_all()

检查是否所有元素都满足条件：

```php
// 检查是否所有子节点都是静态的
$allStatic = array_all(
    $this->children,
    static fn(Node $n): bool => !$n->isDynamic
);
```

### array_find()

查找第一个满足条件的元素：

```php
// 查找第一个 PHP 占位符
$placeholder = array_find(
    $nodes,
    static fn(Node $n): bool => $n instanceof PhpPlaceholder
);
```

## match 表达式

### 替代 switch

```php
$type = match ($token->type) {
    TokenType::Text => 'text',
    TokenType::OpenTag => 'open',
    TokenType::CloseTag => 'close',
    default => 'unknown',
};
```

### 在代码生成中的应用

```php
$code = match ($node::class) {
    TextNode::class => $node->value,
    PhpPlaceholder::class => $this->generatePlaceholder($node),
    TagNode::class => $this->generateTagNode($node),
    default => '',
};
```

## 命名参数

### 提高代码可读性

```php
$node = new TagNode(
    line: 10,
    name: 'block',
    attributes: $attrs,
    selfClosing: true,
);
```

## 类型系统增强

### 交集类型

```php
function process(Node&Serializable $node): void { }
```

### 独立类型

```php
function handle(string|array $value): string|array { }
```

## 性能考虑

### JIT 友好代码

1. **避免动态调用** - 使用静态方法
2. **类型声明** - 完整的类型注解
3. **final 类** - 允许更多内联优化
4. **readonly 属性** - 减少运行时检查

### 示例

```php
final class StringInterner
{
    private static array $strings = [];
    
    public static function intern(string $s): string
    {
        return self::$strings[$s] ??= $s;
    }
}
```

## 最佳实践

1. **使用 readonly** - 所有不可变属性标记为 readonly
2. **Property Hooks** - 计算属性使用 Property Hooks
3. **final 类** - 不需要继承的类标记为 final
4. **静态方法** - 无状态操作使用静态方法
5. **类型声明** - 完整的类型注解
