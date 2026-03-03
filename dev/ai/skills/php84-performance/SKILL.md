---
name: php84-performance
description: PHP 8.4+ 高性能写法与严格类型最佳实践。所有 PHP 代码必须符合 PHP 8.4 严格模式！覆盖严格类型、null 安全、Property Hooks、Lazy Objects、array_find/array_any/array_all、非对称可见性、#[Deprecated] 属性、Dom\HTMLDocument、BcMath\Number、new MyClass()->method() 语法、mb_trim/mb_ucfirst、PDO 子类、get_resource_id()、spl_object_id()。禁止传 null 给非 nullable 参数，禁止 (int)$resource 强制转换。
globs:
  - "**/*.php"
alwaysApply: false
---

# PHP 8.4+ 高性能写法与严格类型（Weline Framework）

**PHP 8.4 发布于 2024-11-21，所有代码必须符合 PHP 8.4 严格模式！**

---

## 何时触发本技能（必选场景）

- **编写任何 PHP 代码**（PHP 8.4 是当前运行环境）
- **字符串函数调用**（trim、htmlspecialchars、strtolower、mb_trim 等）
- **数组操作**（foreach、array_map、in_array、array_find、array_any 等）
- **属性定义**、**getter/setter**、**访问器**、**Property Hooks**
- **Model 字段**、**实体属性**、**数据验证**
- **延迟加载**、**Lazy Object**、**按需初始化**
- **资源 ID 获取**、**流资源**、**Socket 连接**
- **PHP 8.4**、**性能优化**、**严格类型**、**null 安全**
- **标记弃用**、**#[Deprecated]**、**废弃警告**
- **HTML 解析**、**DOM 操作**、**querySelector**
- **高精度计算**、**BCMath**、**BcMath\Number**
- **PDO 连接**、**数据库**、**Pdo\MySql**、**Pdo\Sqlite**

---

## 〇、PHP 8.4 严格类型约束（最重要！）

**PHP 8.4 对类型检查更严格**，传递 `null` 给非 nullable 参数会报 Deprecated 警告或 TypeError。以下规则必须遵守：

### 0.1 字符串函数参数必须非 null

```php
// ❌ 错误：$var 可能为 null
$result = trim($var);
$safe = htmlspecialchars($text);
$lower = strtolower($str);

// ✅ 正确：使用 null 合并运算符
$result = trim($var ?? '');
$safe = htmlspecialchars($text ?? '');
$lower = strtolower($str ?? '');
```

### 0.2 数组访问前检查

```php
// ❌ 错误：数组键可能不存在
$value = $arr['key'];
foreach ($items as $item) { ... }

// ✅ 正确：使用 null 合并
$value = $arr['key'] ?? '';
$value = $arr['key'] ?? null;
foreach (($items ?? []) as $item) { ... }
```

### 0.3 方法链调用前检查

```php
// ❌ 错误：$obj 可能为 null
$name = $obj->getName();

// ✅ 正确：null 安全运算符或提前检查
$name = $obj?->getName() ?? '';
// 或
if ($obj !== null) {
    $name = $obj->getName();
}
```

### 0.4 循环内的变量初始化

```php
// ❌ 错误：$line 在循环外未定义
$line = trim($line);  // 如果不在 foreach 内，$line 可能是 null

// ✅ 正确：确保变量来源正确
foreach ($lines as $line) {
    $line = trim($line ?? '');
    // ...
}
```

### 0.5 常见需要 null 安全的函数

| 函数 | 正确写法 |
|------|----------|
| `trim($str)` | `trim($str ?? '')` |
| `htmlspecialchars($str)` | `htmlspecialchars($str ?? '')` |
| `strtolower($str)` | `strtolower($str ?? '')` |
| `strtoupper($str)` | `strtoupper($str ?? '')` |
| `strlen($str)` | `strlen($str ?? '')` |
| `substr($str, ...)` | `substr($str ?? '', ...)` |
| `explode($sep, $str)` | `explode($sep, $str ?? '')` |
| `preg_match($pat, $str)` | `preg_match($pat, $str ?? '')` |
| `json_decode($json)` | `json_decode($json ?? '{}')` |
| `count($arr)` | `count($arr ?? [])` |
| `array_keys($arr)` | `array_keys($arr ?? [])` |
| `in_array($v, $arr)` | `in_array($v, $arr ?? [])` |

### 0.6 AI 生成代码约束

当生成包含 PHP 代码的组件或模板时，必须：

1. **php_variables 只允许简单赋值**：
   ```php
   // ✅ 正确
   $title = $getConfig('hero.title', '欢迎');
   $items = $getConfig('nav.items', '');
   
   // ❌ 禁止 - 控制结构不属于 php_variables
   foreach ($items as $item) { ... }
   if (empty($line)) continue;
   ```

2. **控制结构放在 html_content 中**：
   ```php
   // 在 html_content 中使用 foreach/if
   <?php foreach (($items ?? []) as $item): ?>
       <li><?= htmlspecialchars($item['name'] ?? '') ?></li>
   <?php endforeach; ?>
   ```

3. **字符串函数始终使用 null 合并**：
   ```php
   $line = trim($line ?? '');
   $text = htmlspecialchars($value ?? '');
   ```

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

## 六、#[Deprecated] 属性（PHP 8.4 新增）

**适用**：标记弃用的方法、函数、类常量，替代 `@deprecated` 注释。

### 6.1 基本用法

```php
class PhpVersion
{
    #[\Deprecated(
        message: "use PhpVersion::getVersion() instead",
        since: "8.4",
    )]
    public function getPhpVersion(): string
    {
        return $this->getVersion();
    }

    public function getVersion(): string
    {
        return '8.4';
    }
}

// 调用时会触发 Deprecated 警告：
// Deprecated: Method PhpVersion::getPhpVersion() is deprecated since 8.4, 
// use PhpVersion::getVersion() instead
$phpVersion = new PhpVersion();
echo $phpVersion->getPhpVersion();
```

### 6.2 类常量弃用

```php
class Config
{
    #[\Deprecated(message: "Use Config::NEW_VALUE instead", since: "2.0")]
    public const OLD_VALUE = 'old';
    
    public const NEW_VALUE = 'new';
}
```

### 6.3 优点

- PHP 原生支持，IDE 和静态分析工具可识别
- 运行时触发 `E_USER_DEPRECATED` 警告
- 比 `@deprecated` 注释更可靠，不会因注释丢失而失效

---

## 七、新 DOM API 和 HTML5 支持（PHP 8.4 新增）

**适用**：HTML/XML 解析、DOM 操作、querySelector 查询。

### 7.1 新的 Dom 命名空间

```php
// ❌ 旧写法（仍可用但不推荐）
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOERROR);
$xpath = new DOMXPath($dom);
$node = $xpath->query(".//main/article")[0];

// ✅ PHP 8.4 新写法
$dom = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
$node = $dom->querySelector('main > article:last-child');

// 使用 classList
var_dump($node->classList->contains("featured")); // bool(true)
```

### 7.2 新类和方法

| 类/方法 | 说明 |
|---------|------|
| `Dom\HTMLDocument::createFromString()` | 从 HTML 字符串创建文档 |
| `Dom\HTMLDocument::createFromFile()` | 从文件创建文档 |
| `Dom\XMLDocument::createFromString()` | 从 XML 字符串创建文档 |
| `$node->querySelector()` | CSS 选择器查询单个元素 |
| `$node->querySelectorAll()` | CSS 选择器查询多个元素 |
| `$node->classList` | 类名列表操作 |

### 7.3 优点

- 符合 HTML5 标准规范
- 支持 CSS 选择器（类似 JavaScript）
- 修复了旧 DOM API 的许多合规性问题

---

## 八、BcMath\Number 对象 API（PHP 8.4 新增）

**适用**：高精度数学运算、金融计算。

### 8.1 对象式 BCMath

```php
use BcMath\Number;

// ❌ 旧写法
$num1 = '0.12345';
$num2 = '2';
$result = bcadd($num1, $num2, 5);

// ✅ PHP 8.4 新写法
$num1 = new Number('0.12345');
$num2 = new Number('2');
$result = $num1 + $num2;  // 支持运算符重载！

echo $result; // '2.12345'
var_dump($num1 > $num2); // false（支持比较运算符）
```

### 8.2 新 BCMath 函数

| 函数 | 说明 |
|------|------|
| `bcceil($num)` | 向上取整 |
| `bcfloor($num)` | 向下取整 |
| `bcround($num, $precision, $mode)` | 四舍五入 |
| `bcdivmod($num1, $num2)` | 同时返回商和余数 |

### 8.3 特点

- `BcMath\Number` 是不可变对象
- 实现 `Stringable` 接口，可直接用于字符串上下文
- 支持运算符重载（+、-、*、/、%、**、<、>、==）

---

## 九、new MyClass()->method() 语法（PHP 8.4 新增）

**适用**：链式调用、简化对象实例化后的方法调用。

```php
// ❌ PHP < 8.4 需要括号
var_dump((new PhpVersion())->getVersion());
$result = (new Calculator())->add(1, 2)->multiply(3)->getResult();

// ✅ PHP 8.4 无需括号
var_dump(new PhpVersion()->getVersion());
$result = new Calculator()->add(1, 2)->multiply(3)->getResult();

// 也支持属性访问
$name = new User()->name;
```

**注意**：这是语法糖，不影响功能，但使代码更简洁。

---

## 十、新 Mbstring 函数（PHP 8.4 新增）

**适用**：多字节字符串处理（中文、日文等 Unicode 字符）。

### 10.1 新增函数

| 函数 | 说明 | 示例 |
|------|------|------|
| `mb_trim($str)` | 多字节 trim | `mb_trim("　你好　")` → `"你好"` |
| `mb_ltrim($str)` | 多字节左 trim | 去除左侧空白（含全角空格） |
| `mb_rtrim($str)` | 多字节右 trim | 去除右侧空白（含全角空格） |
| `mb_ucfirst($str)` | 首字母大写 | `mb_ucfirst("über")` → `"Über"` |
| `mb_lcfirst($str)` | 首字母小写 | `mb_lcfirst("Über")` → `"über"` |

### 10.2 使用场景

```php
// 处理包含全角空格的中文输入
$input = "　　用户名　　";  // 包含全角空格
$clean = mb_trim($input);  // "用户名"

// 德语/法语等的首字母大写
$title = mb_ucfirst("überschrift"); // "Überschrift"
```

---

## 十一、PDO 驱动子类（PHP 8.4 新增）

**适用**：数据库连接、PDO 特定驱动方法。

### 11.1 新的 PDO 子类

```php
// ❌ PHP < 8.4
$connection = new PDO('sqlite:foo.db', $user, $pass); // object(PDO)
$connection->sqliteCreateFunction(...); // 需要知道是 SQLite 才能调用

// ✅ PHP 8.4
$connection = PDO::connect('sqlite:foo.db', $user, $pass); // object(Pdo\Sqlite)
$connection->createFunction(...); // 类型安全，非 SQLite 连接会报错
```

### 11.2 可用子类

| 子类 | 数据库 |
|------|--------|
| `Pdo\MySql` | MySQL |
| `Pdo\Sqlite` | SQLite |
| `Pdo\Pgsql` | PostgreSQL |
| `Pdo\Dblib` | SQL Server (via FreeTDS) |
| `Pdo\Firebird` | Firebird |
| `Pdo\Odbc` | ODBC |

### 11.3 优点

- 类型安全：IDE 可识别驱动特定方法
- 驱动专用方法不再需要前缀（如 `sqliteCreateFunction` → `createFunction`）

---

## 十二、新 round() 取整模式（PHP 8.4 新增）

**适用**：精确的数值取整。

### 12.1 新增 RoundingMode 枚举

```php
// 使用枚举替代常量
round(1.5, 0, RoundingMode::HalfUp);      // 2（PHP_ROUND_HALF_UP）
round(1.5, 0, RoundingMode::HalfDown);    // 1（PHP_ROUND_HALF_DOWN）
round(1.5, 0, RoundingMode::HalfEven);    // 2（PHP_ROUND_HALF_EVEN）
round(1.5, 0, RoundingMode::HalfOdd);     // 1（PHP_ROUND_HALF_ODD）
```

### 12.2 新增模式

| 模式 | 说明 |
|------|------|
| `RoundingMode::TowardsZero` | 向零舍入（截断） |
| `RoundingMode::AwayFromZero` | 远离零舍入 |
| `RoundingMode::NegativeInfinity` | 向负无穷舍入（floor） |
| `RoundingMode::PositiveInfinity` | 向正无穷舍入（ceil） |

```php
round(1.5, 0, RoundingMode::TowardsZero);      // 1
round(-1.5, 0, RoundingMode::TowardsZero);     // -1
round(1.5, 0, RoundingMode::AwayFromZero);     // 2
round(-1.5, 0, RoundingMode::AwayFromZero);    // -2
```

---

## 十三、Lazy Objects 延迟对象（PHP 8.4 新增）

**适用**：延迟初始化、按需加载、减少启动时间和内存。

### 13.1 两种创建方式

```php
// Ghost 模式：初始化器原地修改对象
$reflector = new ReflectionClass(Example::class);
$ghost = $reflector->newLazyGhost(function (Example $object) {
    $object->__construct(1); // 原地初始化
});

// Proxy 模式：工厂返回真实实例
$proxy = $reflector->newLazyProxy(function (Example $proxy): Example {
    return new Example(1); // 返回新实例
});
```

### 13.2 Ghost vs Proxy

| 特性 | Ghost | Proxy |
|------|-------|-------|
| 初始化方式 | 原地修改 | 返回新实例 |
| 对象身份 | 始终同一个 | 代理和真实实例不同 |
| 适用场景 | 大多数情况 | 需要替换整个实例时 |

### 13.3 初始化触发

```php
// 以下操作会触发初始化：
$ghost->property;           // 读取属性
$ghost->property = 'value'; // 写入属性
$ghost->method();           // 调用方法
isset($ghost->property);    // 检查属性
```

### 13.4 通过 ObjectManager（框架推荐）

```php
// 获取延迟实例（PHP 8.4+ 使用 newLazyGhost）
$obj = ObjectManager::getLazyInstance(SomeHeavyClass::class);
// 首次访问属性/方法时才初始化
```

### 13.5 跳过序列化时初始化

```php
$ghost = $reflector->newLazyGhost(
    function ($object) { /* ... */ },
    ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE
);
// 序列化时不会触发初始化
```

---

## 十四、其他新特性

### 14.1 新函数

| 函数 | 说明 |
|------|------|
| `array_find($arr, $callback)` | 查找第一个满足条件的元素 |
| `array_find_key($arr, $callback)` | 查找第一个满足条件的键 |
| `array_any($arr, $callback)` | 是否存在任意满足条件的元素 |
| `array_all($arr, $callback)` | 是否所有元素都满足条件 |
| `grapheme_str_split($str)` | 按 grapheme 分割字符串 |
| `request_parse_body()` | 解析 PUT/PATCH 请求体 |
| `http_get_last_response_headers()` | 获取最后一次 HTTP 响应头 |
| `http_clear_last_response_headers()` | 清除响应头缓存 |
| `fpow($base, $exp)` | 浮点数幂运算 |

### 14.2 DateTime 新方法

```php
// 从时间戳创建
$dt = DateTime::createFromTimestamp(1234567890);
$dt = DateTimeImmutable::createFromTimestamp(1234567890.123456);

// 微秒操作
$dt = new DateTime();
$microsecond = $dt->getMicrosecond();
$dt->setMicrosecond(500000);
```

### 14.3 XMLReader/XMLWriter 新方法

```php
// 从流/URI/字符串创建
$reader = XMLReader::fromStream($stream);
$reader = XMLReader::fromUri('file.xml');
$reader = XMLReader::fromString($xmlString);

$writer = XMLWriter::toStream($stream);
$writer = XMLWriter::toUri('output.xml');
$writer = XMLWriter::toMemory();
```

### 14.4 Reflection 新方法

```php
$refConst = new ReflectionClassConstant(Foo::class, 'BAR');
$refConst->isDeprecated(); // 检查常量是否弃用

$refProp = new ReflectionProperty(Foo::class, 'bar');
$refProp->isDynamic(); // 检查是否动态属性

$refGen = new ReflectionGenerator($generator);
$refGen->isClosed(); // 检查生成器是否已关闭
```

---

## 十五、弃用和移除

### 15.1 已弃用

| 项目 | 说明 |
|------|------|
| `E_STRICT` 常量 | 已弃用，将在未来版本移除 |
| 隐式 nullable 参数 | `function f(Type $p = null)` 应改为 `?Type $p = null` |
| `session_set_save_handler()` 多参数形式 | 应使用对象形式 |
| `CURLOPT_BINARYTRANSFER` | 已弃用 |
| `_` 作为类名 | 已弃用 |

### 15.2 行为变更

| 项目 | 变更 |
|------|------|
| `exit()`/`die()` | 现在是函数而非语言结构，接受严格类型检查 |
| `round()` 无效模式 | 抛出 `ValueError` 异常 |
| Bcrypt 默认 cost | 从 10 增加到 12 |
| libcurl 最低版本 | 要求 7.61.0+ |
| OpenSSL 最低版本 | 要求 1.1.1+ |

### 15.3 已移除

| 扩展 | 说明 |
|------|------|
| OCI8 | 移至 PECL |
| PDO_OCI | 移至 PECL |
| IMAP | 移至 PECL |
| Pspell | 移至 PECL |

---

## 十六、资源类型变化（PHP 8.4 严格化）

**适用**：**流资源**、**Socket 连接**、**文件句柄** 的 ID 获取。

### 16.1 禁止资源强制转换为 int

PHP 8.4 禁止 `(int)$resource` 强制转换，会报错：

```php
// ❌ PHP 8.4 报错
$conn = stream_socket_accept($socket);
$connId = (int)$conn;  // Fatal error: Object of class could not be converted to int

// ✅ 正确做法 - 使用 get_resource_id()
$connId = \get_resource_id($conn);  // PHP 8.0+ 可用
```

### 16.2 常见场景

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

### 16.3 socket 扩展

对于 `socket_*` 函数返回的 `Socket` 对象（PHP 8.0+ 已是对象），使用 `spl_object_id()`：

```php
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
$socketId = \spl_object_id($socket);  // Socket 是对象，用 spl_object_id
```

---

## 十七、快速检查清单（写任何 PHP 代码时自检）

### 17.1 严格类型检查（最重要）

| 场景 | 检查项 | 正确做法 |
|------|--------|----------|
| 字符串函数 | 参数可能为 null？ | 使用 `$str ?? ''` |
| 数组访问 | 键可能不存在？ | 使用 `$arr['key'] ?? ''` |
| foreach | 变量可能不是数组？ | 使用 `($arr ?? [])` |
| 方法调用 | 对象可能为 null？ | 使用 `$obj?->method()` |
| 控制语句 | continue/break 在循环内？ | 确保在 foreach/while/for 内 |

### 17.2 PHP 8.4 新特性使用检查

| 场景 | 推荐做法 |
|------|----------|
| 标记弃用方法/常量 | 使用 `#[\Deprecated]` 属性 |
| HTML 解析 | 使用 `Dom\HTMLDocument` + `querySelector` |
| 高精度计算 | 使用 `BcMath\Number` 对象 |
| 实例化后链式调用 | `new MyClass()->method()` 无需括号 |
| 多字节 trim | 使用 `mb_trim()` / `mb_ltrim()` / `mb_rtrim()` |
| 多字节首字母转换 | 使用 `mb_ucfirst()` / `mb_lcfirst()` |
| PDO 连接 | 使用 `PDO::connect()` 获取类型化子类 |
| 数值取整 | 使用 `RoundingMode` 枚举 |

### 17.3 属性与性能

| 场景           | 优先做法 |
|----------------|----------|
| 属性要 trim/默认值/验证 | PHP 8.4+ 用 Property Hooks |
| 只读或计算属性 | Property Hooks 仅 get |
| 关联懒加载     | Property Hooks 仅 get + 内部 null 检查加载 |
| 找第一个/键/任意/全部 | `Php84::arrayFind` / `arrayFindKey` / `arrayAny` / `arrayAll` |
| 延迟创建对象   | `ObjectManager::getLazyInstance()` 或 `Php84::createLazyObject` |
| 只读对外、内部可写 | PHP 8.4+ `public private(set)` 等非对称可见性 |
| 获取资源 ID | 使用 `get_resource_id()` 或 `spl_object_id()` |

### 17.4 AI 生成代码自检

| 字段 | 禁止内容 | 允许内容 |
|------|----------|----------|
| php_variables | if/foreach/while/for/continue/break/{} | 简单赋值：`$var = ...;` |
| html_content | 注释、内联样式 | PHP 控制结构、htmlspecialchars |
| js_content | PHP 标签、var、全局函数 | const/let、箭头函数、IIFE |
| css_content | 硬编码颜色、通用类名 | 主题变量、组件前缀类名 |

**规范引用**：

- 属性与高性能写法示例：`app/code/Weline/Framework/Support/Php84PropertyHooksExample.php`
- 兼容封装与 Lazy/数组：`app/code/Weline/Framework/Support/Php84.php`

---

## 十八、常见错误与修复

### 18.1 trim(): Passing null to parameter #1

```php
// ❌ 错误代码
$line = trim($line);  // $line 可能是 null

// ✅ 修复
$line = trim($line ?? '');
```

### 18.2 'continue' not in the 'loop' context

```php
// ❌ 错误代码（continue 不在循环内）
$lines = explode("\n", $text);
$line = trim($line);
if (empty($line)) continue;  // Fatal Error!

// ✅ 修复（需要 foreach）
$lines = explode("\n", $text ?? '');
foreach ($lines as $line) {
    $line = trim($line ?? '');
    if (empty($line)) continue;
    // ...
}
```

### 18.3 Cannot use object as array

```php
// ❌ 错误代码
$value = $obj['key'];  // $obj 是对象不是数组

// ✅ 修复
$value = $obj->key ?? '';
// 或
$value = is_array($obj) ? ($obj['key'] ?? '') : ($obj->key ?? '');
```

### 18.4 Argument must be of type string, null given

```php
// ❌ 错误代码
htmlspecialchars($config['title']);  // 可能不存在

// ✅ 修复
htmlspecialchars($config['title'] ?? '');
```

### 18.5 exit() 类型错误（PHP 8.4 变更）

```php
// ❌ PHP 8.4 报错（exit 现在是函数，接受严格类型）
exit(null);  // TypeError
exit([]);    // TypeError

// ✅ 正确
exit(0);           // int
exit('error');     // string
exit();            // 无参数
```

### 18.6 隐式 nullable 参数弃用

```php
// ❌ 弃用写法（PHP 8.4 触发 Deprecated）
function foo(Type $param = null) { }

// ✅ 正确写法
function foo(?Type $param = null) { }
// 或
function foo(Type|null $param = null) { }
```

### 18.7 round() 无效模式

```php
// ❌ PHP 8.4 抛出 ValueError
round(1.5, 0, 999);  // ValueError: Invalid rounding mode

// ✅ 正确
round(1.5, 0, PHP_ROUND_HALF_UP);
round(1.5, 0, RoundingMode::HalfUp);
```

---

## 十九、PHP 8.4 特性速查表

### 19.1 新语法

| 特性 | 语法 | 说明 |
|------|------|------|
| Property Hooks | `public string $name { get => ...; set => ...; }` | 属性钩子 |
| 非对称可见性 | `public private(set) string $name;` | 读写分离可见性 |
| 无括号实例化 | `new MyClass()->method()` | 链式调用简化 |
| Deprecated 属性 | `#[\Deprecated(message: "", since: "")]` | 标记弃用 |

### 19.2 新类

| 类 | 说明 |
|------|------|
| `Dom\HTMLDocument` | HTML5 文档解析 |
| `Dom\XMLDocument` | XML 文档解析 |
| `BcMath\Number` | 高精度数值对象 |
| `Pdo\MySql` / `Pdo\Sqlite` / ... | PDO 驱动子类 |
| `RoundingMode` | 取整模式枚举 |

### 19.3 新函数

| 函数 | 说明 |
|------|------|
| `array_find($arr, $fn)` | 查找第一个满足条件的元素 |
| `array_find_key($arr, $fn)` | 查找第一个满足条件的键 |
| `array_any($arr, $fn)` | 是否存在任意满足条件的元素 |
| `array_all($arr, $fn)` | 是否所有元素都满足条件 |
| `mb_trim($str)` | 多字节 trim |
| `mb_ltrim($str)` | 多字节左 trim |
| `mb_rtrim($str)` | 多字节右 trim |
| `mb_ucfirst($str)` | 多字节首字母大写 |
| `mb_lcfirst($str)` | 多字节首字母小写 |
| `grapheme_str_split($str)` | 按 grapheme 分割 |
| `request_parse_body()` | 解析 PUT/PATCH 请求体 |
| `bcceil($num)` | BCMath 向上取整 |
| `bcfloor($num)` | BCMath 向下取整 |
| `bcround($num, $prec, $mode)` | BCMath 四舍五入 |
| `bcdivmod($n1, $n2)` | BCMath 商和余数 |

### 19.4 新 Reflection 方法

| 方法 | 说明 |
|------|------|
| `ReflectionClass::newLazyGhost($fn)` | 创建 Ghost 延迟对象 |
| `ReflectionClass::newLazyProxy($fn)` | 创建 Proxy 延迟对象 |
| `ReflectionClassConstant::isDeprecated()` | 检查常量是否弃用 |
| `ReflectionProperty::isDynamic()` | 检查是否动态属性 |
| `ReflectionGenerator::isClosed()` | 检查生成器是否关闭 |

### 19.5 行为变更速查

| 项目 | PHP < 8.4 | PHP 8.4 |
|------|-----------|---------|
| `exit(null)` | 允许 | TypeError |
| `round(1.5, 0, 999)` | 忽略无效模式 | ValueError |
| Bcrypt cost | 10 | 12 |
| `function f(T $p = null)` | 允许 | Deprecated |
| `(int)$resource` | 返回 ID | Fatal Error |

---

## 二十、框架兼容性封装

对于需要兼容 PHP < 8.4 的代码，使用框架提供的兼容封装：

```php
use Weline\Framework\Support\Php84;

// 数组函数（兼容 PHP < 8.4）
$first = Php84::arrayFind($items, fn($v) => $v->isActive());
$key = Php84::arrayFindKey($items, fn($v) => $v->id === $id);
$has = Php84::arrayAny($items, fn($v) => $v->valid());
$all = Php84::arrayAll($items, fn($v) => $v->valid());

// 延迟对象（兼容 PHP < 8.4）
$lazy = Php84::createLazyObject(HeavyClass::class, function ($instance) {
    $instance->init();
});
```

**规范引用**：
- 属性与高性能写法示例：`app/code/Weline/Framework/Support/Php84PropertyHooksExample.php`
- 兼容封装与 Lazy/数组：`app/code/Weline/Framework/Support/Php84.php`
