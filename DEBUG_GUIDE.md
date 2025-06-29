# Weline框架调试模式配置指南

## 概述

Weline框架提供了灵活的调试模式配置，可以根据不同环境动态开启或关闭PHP错误显示。

## 调试模式配置

### 1. 配置文件设置

#### 主配置文件 (`app/etc/env.php`)
```php
<?php return [
    'env' => 'local',
    'debug' => true,           // 开启调试模式
    'debug_key' => 'debug123', // 调试密钥，用于URL参数控制
    // ... 其他配置
];
```

#### 全局配置文件 (`app/code/config.php`)
```php
# 全局DEBUG模式 注释可使用url控制运行环境，设置可以强行控制DEBUG
defined('DEBUG') || define('DEBUG', 1);  // 强制开启调试模式
```

### 2. 动态控制方法

#### URL参数控制
- **开启调试**: 在URL后添加 `?debug=debug123`
- **关闭调试**: 在URL后添加 `?debug=0`

#### Cookie控制
- 系统会自动设置 `w_debug` cookie 来记住调试状态

## 错误显示行为

### 调试模式开启时 (DEBUG = true)
- 显示所有PHP错误信息
- 显示启动错误
- 记录错误到日志文件
- 错误报告级别设置为 `E_ALL`

### 调试模式关闭时 (DEBUG = false)
- 不显示PHP错误信息
- 不显示启动错误
- 仍然记录错误到日志文件
- 错误报告级别设置为 `0`

## 测试调试模式

运行测试文件 `debug_test.php` 来验证调试模式是否正常工作：

```bash
php debug_test.php
```

或者在浏览器中访问：
```
http://your-domain/debug_test.php
```

## 日志文件位置

调试日志文件位于：
- 错误日志: `var/log/error.log`
- 调试日志: `var/log/debug.log`
- 数据库日志: `var/log/db.log` (如果启用)

## 注意事项

1. **生产环境安全**: 在生产环境中请确保关闭调试模式，避免暴露敏感信息
2. **性能影响**: 调试模式会略微影响性能，建议只在开发环境使用
3. **错误日志**: 即使关闭调试模式，错误仍会记录到日志文件中
4. **权限设置**: 确保日志目录有适当的写入权限

## 常见问题

### Q: 为什么看不到错误信息？
A: 检查以下几点：
- 确认 `DEBUG` 常量是否为 `true`
- 检查 `app/etc/env.php` 中的 `debug` 设置
- 确认PHP的 `display_errors` 设置

### Q: 如何临时开启调试模式？
A: 使用URL参数：`?debug=debug123`

### Q: 调试模式影响性能吗？
A: 是的，调试模式会略微影响性能，建议只在开发环境使用。 