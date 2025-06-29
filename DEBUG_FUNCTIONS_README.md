# 🐛 Weline Framework 调试函数使用指南

## 概述

Weline Framework 提供了一套功能丰富的调试函数，支持 CLI 和 Web 环境，具有美观的输出格式和丰富的功能特性。

## 🎨 主要特性

- **双环境支持**: 同时支持 CLI 和 Web 环境
- **彩色输出**: CLI 使用 ANSI 颜色，Web 使用 HTML 样式
- **多种主题**: 支持 default、success、warning、error、info 等主题
- **性能监控**: 内置执行时间和内存使用监控
- **表格展示**: 支持数组数据的表格化显示
- **JSON 美化**: 自动格式化 JSON 数据
- **SQL 美化**: 美化 SQL 查询语句
- **日志记录**: 支持调试信息写入日志文件
- **调用栈追踪**: 显示详细的调用信息

## 📋 函数列表

### 基础调试函数

#### `p($data, $pass = false, $trace_deep = 2)`
标准调试输出函数
- `$data`: 要调试的数据
- `$pass`: 是否跳过终止程序（默认 false）
- `$trace_deep`: 追踪层数（默认 2，最大 3）

```php
p($variable);           // 输出并终止
pp($variable);          // 输出但不终止
p($variable, true);     // 输出但不终止
```

#### `p_light($data, $pass = false, $trace_deep = 1)`
轻量级调试函数，只显示基本调用信息
```php
p_light($variable);     // 轻量级输出
```

#### `p_fast($data, $pass = false)`
快速调试函数，不显示追踪信息
```php
p_fast($variable);      // 快速输出
```

### 彩色主题函数

#### `p_color($data, $theme = 'default', $pass = false)`
彩色主题调试函数
- `$theme`: 主题类型 (default, success, warning, error, info)

```php
p_color($data, 'success');  // 绿色主题
p_color($data, 'warning');  // 橙色主题
p_color($data, 'error');    // 红色主题
p_color($data, 'info');     // 蓝色主题
```

### 数据展示函数

#### `p_table($data, $pass = false)`
表格形式显示数组数据
```php
$data = [
    ['id' => 1, 'name' => '张三', 'age' => 25],
    ['id' => 2, 'name' => '李四', 'age' => 30],
];
p_table($data);
```

#### `p_json($data, $pass = false)`
JSON 格式化输出
```php
$jsonData = ['status' => 'success', 'data' => [1, 2, 3]];
p_json($jsonData);
```

### 性能监控函数

#### `p_perf($label = '性能监控', $pass = false)`
性能监控函数，显示内存使用和执行时间
```php
p_perf('数据库查询');  // 显示性能指标
```

### SQL 调试函数

#### `p_sql($sql, $params = [], $pass = false)`
SQL 查询美化输出
```php
$sql = "SELECT * FROM users WHERE status = ? AND age > ?";
$params = ['active', 18];
p_sql($sql, $params);
```

### 日志函数

#### `p_log($data, $level = 'debug', $file = null)`
调试信息写入日志文件
```php
p_log('调试信息', 'debug');
p_log(['user_id' => 123], 'info');
```

### 其他函数

#### `d($data, $trace_deep = 2)`
使用 `dump()` 函数的调试输出
```php
d($variable);
```

#### `dd($data)`
调试输出并终止程序
```php
dd($variable);  // 输出并终止
```

#### `w($data)`
绿色主题调试输出并终止
```php
w($variable);   // 绿色主题输出并终止
```

#### `cli_d($data)`
仅 CLI 环境调试输出
```php
cli_d($variable);  // 仅在 CLI 环境输出
```

## 🎯 使用示例

### 基础使用
```php
// 引入调试函数
require_once 'app/code/Weline/Framework/Common/func_debug.php';

// 基础调试
$data = ['name' => '张三', 'age' => 25];
p($data);

// 不终止的调试
pp($data);
```

### 数组数据展示
```php
$users = [
    ['id' => 1, 'name' => '张三', 'email' => 'zhangsan@example.com'],
    ['id' => 2, 'name' => '李四', 'email' => 'lisi@example.com'],
];

// 表格形式显示
p_table($users);

// JSON 格式化
p_json($users);
```

### 性能监控
```php
// 开始监控
p_perf('开始处理');

// 执行一些操作
for ($i = 0; $i < 1000; $i++) {
    // 一些操作
}

// 查看性能
p_perf('处理完成');
```

### SQL 调试
```php
$sql = "SELECT u.id, u.name, p.phone 
        FROM users u 
        LEFT JOIN profiles p ON u.id = p.user_id 
        WHERE u.status = ? AND u.created_at > ?";
$params = ['active', '2023-01-01'];

p_sql($sql, $params);
```

### 日志记录
```php
// 记录调试信息
p_log('用户登录', 'info');
p_log(['user_id' => 123, 'ip' => '192.168.1.1'], 'debug');
```

## 🎨 主题样式

### CLI 环境颜色
- **info**: 青色
- **note**: 蓝色
- **success**: 绿色
- **warning**: 黄色
- **error**: 红色
- **file**: 紫色
- **class**: 青色
- **function**: 黄色

### Web 环境样式
- **default**: 蓝灰色渐变
- **success**: 绿色渐变
- **warning**: 橙色渐变
- **error**: 红色渐变
- **info**: 蓝色渐变
- **light**: 浅蓝色渐变

## ⚙️ 配置选项

### 追踪层数限制
为了避免浏览器卡顿，追踪层数被限制为最大 3 层：
```php
p($data, false, 3);  // 最大追踪 3 层
```

### 样式自定义
可以通过修改 `debug_get_style()` 函数来自定义样式：
```php
// 在 func_debug.php 中修改样式数组
$styles = [
    'custom' => 'background: linear-gradient(135deg, #your-color, #your-color); ...',
];
```

## 🚀 最佳实践

1. **开发环境使用**: 这些函数主要用于开发调试，生产环境应禁用
2. **性能考虑**: 使用 `p_light()` 或 `p_fast()` 进行轻量级调试
3. **日志记录**: 使用 `p_log()` 记录重要的调试信息
4. **表格展示**: 对于结构化数据，优先使用 `p_table()`
5. **JSON 数据**: 使用 `p_json()` 格式化 JSON 数据

## 🔧 故障排除

### 常见问题

1. **语法错误**: 确保 PHP 版本 >= 7.4
2. **权限问题**: 日志文件需要写入权限
3. **内存限制**: 大数据量时使用 `p_light()` 或 `p_fast()`

### 调试技巧

1. 使用 `pp()` 而不是 `p()` 避免意外终止程序
2. 使用 `p_perf()` 监控性能瓶颈
3. 使用 `p_log()` 记录关键调试信息
4. 使用 `p_table()` 展示结构化数据

## 📝 更新日志

- **v1.0**: 基础调试函数
- **v1.1**: 添加彩色主题支持
- **v1.2**: 添加表格和 JSON 美化
- **v1.3**: 添加性能监控和 SQL 美化
- **v1.4**: 添加日志记录功能

---

**作者**: 秋枫雁飞 (Aiweline)  
**邮箱**: aiweline@qq.com  
**网址**: aiweline.com  
**论坛**: https://bbs.aiweline.com 