---
name: php84-performance
description: PHP 8.4+ 严格类型、null 安全、Property Hooks、array_find/array_any、mb_trim、get_resource_id。禁止传 null 给非 nullable 参数。
globs:
  - "**/*.php"
alwaysApply: false
---

# php84-performance（极简版）

## 何时使用

- 编写任何 PHP 代码
- 字符串函数（trim、htmlspecialchars、strtolower）
- 数组操作、属性定义、Model 字段
- 资源 ID 获取、延迟加载

## 必做

- 文件头 `declare(strict_types=1);`
- 字符串函数参数可能为 null 时用 `$str ?? ''`
- 数组访问用 `$arr['key'] ?? ''`，foreach 用 `($arr ?? [])`
- 对象可能为 null 用 `$obj?->method()`
- 资源 ID 用 `get_resource_id()`，禁止 `(int)$resource`
- 隐式 nullable 改为 `?Type $p = null`

## 最小示例

```php
$result = trim($var ?? '');
$value = $arr['key'] ?? '';
foreach (($items ?? []) as $item) { }
$connId = get_resource_id($conn);
```

## 禁止

- 传 null 给非 nullable 参数
- `(int)$resource` 强制转换
- `function f(Type $p = null)` 隐式 nullable
- php_variables 中写 if/foreach/continue（放 html_content）
