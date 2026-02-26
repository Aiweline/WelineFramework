# 配置管理技能

## 触发关键词

Config, 配置, Env, env.php, SystemConfig, 系统配置, 模块配置, 环境配置, getConfig, setConfig, module_env, 配置读取, 配置写入

## 适用场景

- 读取系统环境配置（app/etc/env.php）
- 读取模块配置（etc/env.php）
- 使用 SystemConfig 存储动态配置
- 配置的分层管理

---

## 1. 配置分层架构

| 层级 | 存储位置 | 适用场景 |
|------|---------|---------|
| 系统配置 | `app/etc/env.php` | 全局环境配置（数据库、缓存、日志等） |
| 模块配置 | `app/code/Vendor/Module/etc/env.php` | 模块静态配置（路由别名、默认值等） |
| 动态配置 | `SystemConfig` 模型（数据库） | 运行时可修改的配置（主题、功能开关等） |

---

## 2. 系统环境配置（Env）

### 2.1 配置文件位置

```
app/etc/env.php
```

### 2.2 配置结构示例

```php
<?php
return [
    'env' => 'local',  // local, development, production
    
    'event' => [
        'debug' => false,
        'scan_enabled' => false,
    ],
    
    'cache' => [
        'default' => 'file',
        'drivers' => [
            'file' => ['path' => 'var/cache/'],
            'redis' => ['server' => '127.0.0.1', 'port' => 6379],
        ],
        'status' => [
            'config' => 1,
            'framework_controller' => 1,
            'router_cache' => 1,
        ],
    ],
    
    'session' => [
        'save_type' => 'file',
        'file_path' => 'var/session',
    ],
    
    'log' => [
        'debug' => false,
        'path' => 'var/log',
    ],
    
    'db' => [
        'default' => 'default',
        'connections' => [
            'default' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'weline',
                'username' => 'root',
                'password' => '',
            ],
        ],
    ],
];
```

### 2.3 读取系统配置

```php
use Weline\Framework\App\Env;

// 方式一：静态方法
$value = Env::get('config_key');
$value = Env::get('config_key', 'default_value');  // 带默认值

// 方式二：点号分隔访问嵌套配置
$driver = Env::get('cache.default');              // 'file'
$status = Env::get('cache.status.router_cache');  // 1

// 方式三：实例方法
$value = Env::getInstance()->getConfig('config_key');
```

### 2.4 设置系统配置

```php
use Weline\Framework\App\Env;

// 设置配置（会写入 env.php）
Env::set('config_key', 'value');

// 设置嵌套配置
Env::set('cache.status.new_cache', 1);

// 实例方法
Env::getInstance()->setConfig('config_key', 'value');
```

---

## 3. 模块配置

### 3.1 配置文件位置

```
app/code/Vendor/Module/etc/env.php
```

### 3.2 模块配置示例

```php
<?php
// app/code/Weline/Ai/etc/env.php
return [
    // 路由别名
    'router' => 'ai',
    
    // 模块配置
    'config' => [
        'default_model' => [
            'vendor' => 'openai',
            'model_code' => 'gpt-3.5-turbo',
        ],
        'api' => [
            'timeout' => 30,
            'retry_times' => 3,
            'rate_limit' => 100,
        ],
        'cache' => [
            'model_list_ttl' => 3600,
            'adapter_list_ttl' => 1800,
        ],
    ],
];
```

### 3.3 读取模块配置

```php
use Weline\Framework\App\Env;

// 方式一：获取整个模块配置
$moduleConfig = Env::module_env('Weline_Ai');

// 方式二：获取嵌套配置
$timeout = Env::module_env('Weline_Ai', 'config.api.timeout');  // 30
$defaultModel = Env::module_env('Weline_Ai', 'config.default_model');

// 带默认值
$value = Env::module_env('Weline_Ai', 'config.not_exists', 'default');
```

---

## 4. 动态配置（SystemConfig）

### 4.1 适用场景

- 需要在后台界面修改的配置
- 运行时动态变化的配置
- 用户/商户级别的配置

### 4.2 使用 SystemConfig

```php
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Framework\Manager\ObjectManager;

$systemConfig = ObjectManager::getInstance(SystemConfig::class);

// 获取配置
$value = $systemConfig->getConfig(
    'theme_color',           // 配置键
    'Weline_Theme',          // 模块名
    SystemConfig::area_BACKEND  // 区域：backend/frontend
);

// 设置配置
$systemConfig->setConfig(
    'theme_color',           // 配置键
    '#3b82f6',               // 配置值
    'Weline_Theme',          // 模块名
    SystemConfig::area_BACKEND  // 区域
);

// 获取模块所有配置
$configs = $systemConfig->getConfigByModule(
    'Weline_Theme',
    SystemConfig::area_FRONTEND
);
```

### 4.3 区域常量

```php
SystemConfig::area_BACKEND   // 后台配置
SystemConfig::area_FRONTEND  // 前台配置
```

---

## 5. 配置读取最佳实践

### 5.1 Service 中封装配置访问

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Service;

use Weline\Framework\App\Env;
use Weline\SystemConfig\Model\SystemConfig;

class ConfigService
{
    private SystemConfig $systemConfig;
    
    // 模块名常量
    private const MODULE = 'Weline_YourModule';
    
    public function __construct(SystemConfig $systemConfig)
    {
        $this->systemConfig = $systemConfig;
    }
    
    // 获取静态配置（etc/env.php）
    public function getApiTimeout(): int
    {
        return (int) Env::module_env(self::MODULE, 'config.api.timeout', 30);
    }
    
    // 获取动态配置（数据库）
    public function getThemeColor(): string
    {
        return $this->systemConfig->getConfig(
            'theme_color',
            self::MODULE,
            SystemConfig::area_BACKEND
        ) ?: '#3b82f6';
    }
    
    // 设置动态配置
    public function setThemeColor(string $color): bool
    {
        return $this->systemConfig->setConfig(
            'theme_color',
            $color,
            self::MODULE,
            SystemConfig::area_BACKEND
        );
    }
}
```

### 5.2 配置缓存

SystemConfig 内部已实现缓存：

```php
public function getConfig(string $key, string $module, string $area): mixed
{
    $cacheKey = 'system_config_cache_' . $key . '_' . $area . '_' . $module;
    
    // 先从缓存获取
    $result = $this->_cache->get($cacheKey);
    if ($result) {
        return $result;
    }
    
    // 查询数据库
    $result = $this->queryFromDb($key, $module, $area);
    
    // 写入缓存
    $this->_cache->set($cacheKey, $result);
    
    return $result;
}
```

---

## 6. 常见配置项说明

### 6.1 env.php 主要配置项

| 配置项 | 说明 |
|-------|------|
| `env` | 环境：local/development/production |
| `cache` | 缓存配置：驱动、状态 |
| `session` | 会话配置：存储类型、路径 |
| `log` | 日志配置：调试开关、路径 |
| `db` | 数据库配置：连接信息 |
| `server` | WLS 服务器配置（见 weline-server 技能） |

### 6.2 模块 env.php 常用配置

| 配置项 | 说明 |
|-------|------|
| `router` | 路由别名（如 `'router' => 'ai'`） |
| `backend_router` | 后台路由别名 |
| `config` | 模块业务配置 |

---

## 7. 常见错误

### 7.1 直接修改配置数组

```php
// ❌ 错误：直接修改不会持久化
$config = Env::getInstance()->getConfig();
$config['new_key'] = 'value';

// ✅ 正确：使用 setConfig 方法
Env::getInstance()->setConfig('new_key', 'value');
```

### 7.2 配置键名不规范

```php
// ❌ 错误：使用非规范键名
Env::set('MyConfig', $value);

// ✅ 正确：使用小写下划线分隔
Env::set('my_config', $value);
Env::set('module.sub_config', $value);
```

### 7.3 混淆静态配置和动态配置

```php
// ❌ 错误：运行时频繁修改 env.php
// env.php 适合静态配置，不适合频繁修改

// ✅ 正确：需要动态修改的配置用 SystemConfig
$systemConfig->setConfig('feature_enabled', '1', 'Module', 'backend');
```

### 7.4 忽略配置默认值

```php
// ❌ 错误：配置不存在时返回 null 可能导致问题
$timeout = Env::module_env('Module', 'config.timeout');
// 如果配置不存在，$timeout 为 null

// ✅ 正确：提供默认值
$timeout = Env::module_env('Module', 'config.timeout', 30);
```

---

## 8. 配置层级选择指南

| 场景 | 推荐存储 |
|------|---------|
| 数据库连接、缓存驱动 | `app/etc/env.php` |
| 模块路由别名、API 超时 | `模块 etc/env.php` |
| 用户可修改的主题颜色 | `SystemConfig` |
| 功能开关、AB 测试配置 | `SystemConfig` |
| 第三方 API 密钥 | `app/etc/env.php`（敏感信息） |

---

## 9. 规范总结

| 项目 | 规范 |
|------|------|
| 系统配置 | `Env::get('key')` 或 `Env::get('a.b.c')` |
| 模块配置 | `Env::module_env('Module', 'config.key')` |
| 动态配置 | `SystemConfig->getConfig/setConfig` |
| 配置键名 | 小写下划线分隔 |
| 默认值 | 始终提供合理的默认值 |
| 敏感信息 | 存储在 env.php，不提交到版本库 |
