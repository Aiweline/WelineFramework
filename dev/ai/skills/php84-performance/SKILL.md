---
name: php84-performance
description: PHP 8.4+ 高性能写法与最佳实践。编写或更新属性、getter/setter、Model 字段、属性访问器、数组遍历查找、延迟加载对象、资源 ID 获取时必选。覆盖 Property Hooks、Lazy Objects、array_find/array_any/array_all、非对称可见性、get_resource_id()、spl_object_id()。禁止 (int)$resource 强制转换。框架参考 Php84.php、Php84PropertyHooksExample.php。
globs:
  - "**/*.php"
alwaysApply: false
---

# PHP 8.4+ 高性能写法（Weline Framework）

编写或修改 **属性**、**getter/setter**、**Model 字段**、**数组查找/遍历**、**延迟加载** 相关代码时，**必须**采用本技能中的 PHP 8.4+ 高性能写法，并优先使用框架已有封装。

---

## 何时触发本技能（必选场景）

- **编写属性**、**更新属性**、**定义类属性**
- **getter/setter**、**访问器**、**属性封装**
- **Model 字段**、**实体属性**、**数据验证**、**默认值**
- **trim、格式化、邮箱验证、价格/金额、日期属性**
- **数组查找**、**foreach 找第一个**、**是否存在/全部满足**
- **延迟加载**、**Lazy Object**、**按需初始化**
- **只读属性**、**计算属性**、**懒加载关联**
- **资源 ID 获取**、**流资源**、**Socket 连接**、**文件句柄**
- **(int)$resource**、**get_resource_id**、**spl_object_id**
- **PHP 8.4**、**性能优化**、**高性能写法**

---

## 一、Property Hooks（属性钩子）

**适用**：任何需要“赋值时处理”或“读取时计算”的 **属性**。替代手写 getXxx/setXxx。

### 1.1 规范与引用

- **完整示例**：`app/code/Weline/Framework/Support/Php84PropertyHooksExample.php`
- **语法要求**：仅 PHP 8.4+（`PHP_VERSION_ID >= 80400`）。低版本不可使用该语法，需用传统 getter/setter。

### 1.2 基础写法

```php
// 自动 trim
public string $name {
    get => $this->name;
    set => $this->name = trim($value);
}

// 默认值（需 backing property）
private string $_status = '';
public string $status {
    get => $this->_status ?: 'pending';
    set => $this->_status = $value;
}

// 验证 + 格式化（如邮箱转小写）
private string $_email = '';
public string $email {
    get => $this->_email;
    set {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("无效的邮箱格式: {$value}");
        }
        $this->_email = strtolower(trim($value));
    }
}
```

### 1.3 金额/价格、只读计算属性、只读 ID

```php
private float $_price = 0.0;
public float $price {
    get => round($this->_price, 2);
    set => $this->_price = max(0, (float) $value);
}

// 只读计算属性（仅 get）
public string $formattedPrice {
    get => '¥' . number_format($this->price, 2);
}

// 只读 ID（set 仅允许一次）
private ?int $_id = null;
public ?int $id {
    get => $this->_id;
    set {
        if ($this->_id !== null) {
            throw new \RuntimeException('ID 一旦设置不可修改');
        }
        $this->_id = $value;
    }
}
```

### 1.4 日期、JSON 配置、懒加载

```php
private ?\DateTimeImmutable $_createdAt = null;
public \DateTimeImmutable $createdAt {
    get => $this->_createdAt ?? new \DateTimeImmutable();
    set {
        if ($value instanceof \DateTimeImmutable) {
            $this->_createdAt = $value;
        } elseif (is_string($value)) {
            $this->_createdAt = new \DateTimeImmutable($value);
        }
        // ... 其他类型转换见 Php84PropertyHooksExample
    }
}

private string $_configJson = '{}';
public array $config {
    get => json_decode($this->_configJson, true) ?: [];
    set => $this->_configJson = json_encode($value, JSON_UNESCAPED_UNICODE);
}

// 懒加载（仅 get，首次访问加载）
private ?array $_relatedItems = null;
public array $relatedItems {
    get {
        if ($this->_relatedItems === null) {
            $this->_relatedItems = $this->loadRelatedItems();
        }
        return $this->_relatedItems;
    }
}
```

### 1.5 注意

- 需要存真实值时用 **backing property**（如 `$_status`、`$_email`），避免与 hook 属性同名导致循环。
- Property Hooks 为 **语法特性**，无法在 PHP &lt; 8.4 用运行时兼容，低版本需单独用 getter/setter 或 `Php84` 不涉及语法的部分。

---

## 二、新数组函数（替代 foreach + break / 条件遍历）

**适用**：**查找第一个满足条件的元素**、**是否存在任意/全部满足**。优先用框架封装以兼容 PHP &lt; 8.4。

### 2.1 使用框架封装

- 类：`\Weline\Framework\Support\Php84`
- 方法：`arrayFind`、`arrayFindKey`、`arrayAny`、`arrayAll`

```php
use Weline\Framework\Support\Php84;

// 找第一个满足条件的值（替代 foreach + break）
$first = Php84::arrayFind($items, fn($v, $k) => $v->isActive());

// 找第一个满足条件的键
$key = Php84::arrayFindKey($items, fn($v) => $v->id === $id);

// 是否存在任意满足
$has = Php84::arrayAny($headers, fn($h) => stripos($h, 'Content-Type: text/event-stream') !== false);

// 是否全部满足
$all = Php84::arrayAll($list, fn($v) => $v->valid());
```

### 2.2 PHP 8.4+ 原生（可选）

在确定运行环境为 PHP 8.4+ 时可直接用：

- `array_find(array $array, callable $callback): mixed`
- `array_find_key(array $array, callable $callback): int|string|null`
- `array_any(array $array, callable $callback): bool`
- `array_all(array $array, callable $callback): bool`

**禁止**：在未做版本判断的公共库/框架代码中仅使用原生函数而导致 PHP &lt; 8.4 报错；应使用 `Php84::*`。

---

## 三、Lazy Objects（延迟对象）

**适用**：**延迟初始化**、**按需加载**、减少启动时间和内存。

### 3.1 通过 ObjectManager（推荐）

```php
// 获取延迟实例（PHP 8.4+ 使用 newLazyGhost）
$obj = ObjectManager::getLazyInstance(SomeHeavyClass::class);
// 首次访问属性/方法时才初始化
```

### 3.2 通过 Php84 工具方法

```php
use Weline\Framework\Support\Php84;

$obj = Php84::createLazyObject(HeavyClass::class, function ($instance) {
    // 首次访问时执行
    $instance->initFromDb();
});
```

### 3.3 注意

- 仅 PHP 8.4+ 才有真正的 Lazy 语义；低版本 `Php84` 会回退为立即初始化。
- 适合“创建成本高、不一定用”的对象，例如部分 Model、服务代理。

---

## 四、非对称可见性（Asymmetric Visibility）PHP 8.4+

**适用**：**对外只读、内部可写** 的属性，减少手写 getter。

```php
// 外部只读，类内部可写
public readonly private(set) string $name;

// 或：public 读，private 写
public private(set) int $count = 0;
```

在 PHP 8.4+ 中可用于替代“只提供 getXxx() 不提供 setXxx()”的只读语义。

---

## 五、与框架其他规范的关系

- **Model/实体**：在 Model 层写“带验证、默认值、格式化”的属性时，优先用 Property Hooks（PHP 8.4+）或参考 `Php84PropertyHooksExample.php`。
- **路由/控制器**：数组“查找第一个/是否存在”用 `Php84::arrayFind` / `Php84::arrayAny` / `Php84::arrayAll`，已用于 Router SSE 检测等。
- **依赖注入/延迟创建**：需要“按需初始化”的对象用 `ObjectManager::getLazyInstance()` 或 `Php84::createLazyObject`。
- **i18n**：用户可见的报错/提示仍用 `__()`，见 i18n-internationalization 技能。
- **命名空间与严格类型**：所有新 PHP 文件保持 `declare(strict_types=1);` 与框架命名空间规范。

---

## 六、资源类型变化（PHP 8.4 严格化）

**适用**：**流资源**、**Socket 连接**、**文件句柄** 的 ID 获取。

### 6.1 禁止资源强制转换为 int

PHP 8.4 禁止 `(int)$resource` 强制转换，会报错：

```php
// ❌ PHP 8.4 报错
$conn = stream_socket_accept($socket);
$connId = (int)$conn;  // Fatal error: Object of class could not be converted to int

// ✅ 正确做法 - 使用 get_resource_id()
$connId = \get_resource_id($conn);  // PHP 8.0+ 可用
```

### 6.2 常见场景

```php
// stream_select 后获取连接 ID
foreach ($readableSockets as $conn) {
    $connId = \get_resource_id($conn);  // ✅
    // 不要用 (int)$conn
}

// 新接受的连接
$clientConn = stream_socket_accept($serverSocket);
$connId = \get_resource_id($clientConn);  // ✅

// 文件句柄
$fp = fopen('file.txt', 'r');
$fileId = \get_resource_id($fp);  // ✅
```

### 6.3 socket 扩展

对于 `socket_*` 函数返回的 `Socket` 对象（PHP 8.0+ 已是对象），使用 `spl_object_id()`：

```php
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$socketId = \spl_object_id($socket);  // Socket 是对象，用 spl_object_id
```

---

## 七、快速检查清单（写属性/数组/性能时自检）

| 场景           | 优先做法 |
|----------------|----------|
| 属性要 trim/默认值/验证 | PHP 8.4+ 用 Property Hooks；否则 getter/setter |
| 只读或计算属性 | Property Hooks 仅 get |
| 关联懒加载     | Property Hooks 仅 get + 内部 null 检查加载 |
| 找第一个/键/任意/全部 | `Php84::arrayFind` / `arrayFindKey` / `arrayAny` / `arrayAll` |
| 延迟创建对象   | `ObjectManager::getLazyInstance()` 或 `Php84::createLazyObject` |
| 只读对外、内部可写 | PHP 8.4+ `public private(set)` 等非对称可见性 |

**规范引用**：

- 属性与高性能写法示例：`app/code/Weline/Framework/Support/Php84PropertyHooksExample.php`
- 兼容封装与 Lazy/数组：`app/code/Weline/Framework/Support/Php84.php`
