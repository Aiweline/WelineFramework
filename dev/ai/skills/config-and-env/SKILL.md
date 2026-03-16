---
name: config-and-env
description: 配置与环境。系统/模块 env.php、SystemConfig、getConfig；PHP 扩展 env:check/env:install、requirements.php。
globs:
  - "**/etc/env.php"
  - "**/env/requirements.php"
alwaysApply: false
---

# config-and-env（极简版·配置+扩展）

## 何时使用

- 读取配置、env.php、SystemConfig；PHP 扩展、env:check、env:install、extension_loaded

## 1) 配置

- 系统 app/etc/env.php；模块 app/code/Vendor/Module/etc/env.php；动态配置 SystemConfig；getConfig/setConfig

## 2) PHP 扩展

- 检查 `php bin/w env:check`；安装 `php bin/w env:install -y`；模块 requirements.php 声明依赖

## 最小示例

```php
return ['router' => 'module_name', 'backend_router' => 'module_name'];
```

```bash
php bin/w env:check
php bin/w env:install -y
```

## 禁止

- 硬编码配置值；扩展缺失不先 env:check；模块要扩展不声明 requirements.php
