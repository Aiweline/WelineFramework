# Weline Framework 模块 - 事件文档

## 概述

本文档详细说明了 Weline Framework 模块提供的 `Weline_Framework::App::url_parsed_after` 事件及其使用方法。该事件在 URL 解析完成后触发，允许其他模块在路由处理前执行必要的检查或操作。

## 事件列表

### 1. Weline_Framework::App::url_parsed_after - URL 解析后事件

#### 基本信息

- **事件名称**：`Weline_Framework::App::url_parsed_after`
- **事件类型**：应用生命周期事件
- **触发时机**：在 `App::run()` 方法中，URL 解析完成后，路由处理前
- **触发位置**：`app/code/Weline/Framework/App.php` 第 328 行
- **触发条件**：仅在生产环境（PROD）且非后端请求时触发
- **配置文件**：`app/code/Weline/Framework/etc/event.xml`

#### 功能说明

`Weline_Framework::App::url_parsed_after` 事件在 URL 解析完成后触发，此时：
- URL 解析已完成
- `$_SERVER` 变量已更新（包含 `WELINE_AREA`、`WELINE_IS_BACKEND` 等）
- Cookie 已设置（语言、货币、网站信息等）
- 可以生成正确的缓存键
- 路由尚未处理
- 控制器尚未执行

该事件主要用于：
- 检查全页缓存（此时可以生成正确的缓存键）
- 执行权限检查
- 处理 URL 重定向
- 设置环境变量
- 执行安全检查
- 其他需要在路由处理前执行的操作

#### 触发时机

```php
// app/code/Weline/Framework/App.php
public static function run(): string
{
    // ... URL 解析 ...
    if (is_array($parse)) {
        $_SERVER = $parse['server'];
        
        // 根据 WELINE_AREA 设置后端标识
        $welineArea = $_SERVER['WELINE_AREA'] ?? 'frontend';
        $_SERVER['WELINE_IS_BACKEND'] = ($welineArea === 'admin' || $welineArea === 'api_admin');
        
        // 设置 Cookie ...
        
        // URL 解析后，再次检查全页缓存（此时 WELINE_IS_BACKEND 已设置）
        if (PROD && !($_SERVER['WELINE_IS_BACKEND'] ?? false)) {
            $eventManager->dispatch('Weline_Framework::App::url_parsed_after');  // ← 事件在此处触发
        }
    }
    
    // 路由处理...
}
```

#### 使用场景

- **全页缓存检查**：在 URL 解析完成后检查全页缓存，此时可以生成正确的缓存键
- **权限验证**：在路由处理前进行权限检查
- **URL 重定向**：根据解析后的 URL 信息进行重定向
- **环境配置**：根据 URL 信息设置环境变量
- **安全检查**：执行安全相关的检查，如访问频率限制、IP 白名单等
- **日志记录**：记录 URL 解析后的请求信息

#### 使用方法

##### 基本用法

在模块的 `etc/event.xml` 文件中注册观察者：

```xml
<?xml version="1.0"?>
<config xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"
        xs:noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"
        xmlns="urn:Weline_Framework::Event/etc/xsd/event.xsd">
    <event name="Weline_Framework::App::url_parsed_after">
        <observer name="Your_Module::your_observer_name"
                  instance="Your\Module\Observer\YourObserver"
                  disabled="false"
                  shared="true"
                  sort="100"/>
    </event>
</config>
```

创建观察者类：

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class YourObserver implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        // 此时可以获取 Request 对象
        $uri = $this->request->getUri();
        $method = $this->request->getMethod();
        
        // 执行你的逻辑
        // 例如：检查缓存、权限验证、重定向等
    }
}
```

#### 事件数据

`Weline_Framework::App::url_parsed_after` 事件不传递额外的数据，观察者可以通过以下方式获取信息：

1. **通过 `Request` 对象**：
   ```php
   /** @var Request $request */
   $request = ObjectManager::getInstance(Request::class);
   $uri = $request->getUri();
   $method = $request->getMethod();
   ```

2. **通过 `$_SERVER` 超全局变量**：
   - `$_SERVER['WELINE_AREA']` - 区域（frontend/admin/api_admin）
   - `$_SERVER['WELINE_IS_BACKEND']` - 是否是后端请求
   - `$_SERVER['WELINE_USER_LANG']` - 用户语言
   - `$_SERVER['WELINE_USER_CURRENCY']` - 用户货币
   - `$_SERVER['WELINE_WEBSITE_ID']` - 网站 ID
   - `$_SERVER['WELINE_WEBSITE_CODE']` - 网站代码
   - `$_SERVER['REQUEST_URI']` - 请求 URI

3. **通过 `App::Env()` 方法**获取环境配置

#### 使用示例

##### 示例 1：检查全页缓存

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Cache\RouterCache;

class CheckFullPageCacheObserver implements ObserverInterface
{
    private Request $request;
    private CacheInterface $cache;

    public function __construct(Request $request, CacheInterface $cache)
    {
        $this->request = $request;
        $this->cache = $cache->create();
    }

    public function execute(Event &$event): void
    {
        // 只在生产环境检查
        if (!PROD) {
            return;
        }
        
        // 只处理 GET 请求
        if ($this->request->getMethod() !== 'GET') {
            return;
        }
        
        // 生成缓存键（此时可以生成正确的缓存键）
        $cacheKey = RouterCache::buildUnifiedRequestCacheKey('', $this->request->getMethod(), $this->request);
        
        // 检查缓存
        $cachedContent = $this->cache->get($cacheKey);
        
        if ($cachedContent !== false && is_array($cachedContent)) {
            // 如果存在全页缓存，直接输出并退出
            if (isset($cachedContent[RouterCache::UNIFIED_CACHE_FPC_KEY]) && 
                !empty($cachedContent[RouterCache::UNIFIED_CACHE_FPC_KEY])) {
                
                // 恢复响应头
                if (isset($cachedContent[RouterCache::UNIFIED_CACHE_HEADERS_KEY]) && 
                    is_array($cachedContent[RouterCache::UNIFIED_CACHE_HEADERS_KEY])) {
                    foreach ($cachedContent[RouterCache::UNIFIED_CACHE_HEADERS_KEY] as $header) {
                        header($header);
                    }
                }
                
                // 添加缓存命中标志
                header('X-Weline-FPC: HIT');
                
                // 输出缓存内容
                echo $cachedContent[RouterCache::UNIFIED_CACHE_FPC_KEY];
                exit(0);
            }
        }
    }
}
```

##### 示例 2：权限检查

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Acl\Model\Acl;

class PermissionCheckObserver implements ObserverInterface
{
    private Request $request;
    private Acl $acl;

    public function __construct(Request $request, Acl $acl)
    {
        $this->request = $request;
        $this->acl = $acl;
    }

    public function execute(Event &$event): void
    {
        // 只检查后端请求
        if (!$this->request->isBackend()) {
            return;
        }
        
        $uri = $this->request->getUri();
        
        // 检查权限
        if (!$this->acl->hasPermission($uri)) {
            // 如果没有权限，重定向到登录页
            header('Location: /admin/login');
            exit;
        }
    }
}
```

##### 示例 3：访问频率限制

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class RateLimitObserver implements ObserverInterface
{
    private Request $request;
    private CacheInterface $cache;

    public function __construct(Request $request, CacheInterface $cache)
    {
        $this->request = $request;
        $this->cache = $cache->create();
    }

    public function execute(Event &$event): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $uri = $this->request->getUri();
        
        // 生成限制键
        $limitKey = 'rate_limit_' . md5($ip . $uri);
        
        // 获取当前访问次数
        $count = $this->cache->get($limitKey) ?: 0;
        
        // 设置限制：每分钟最多 60 次
        $maxRequests = 60;
        $timeWindow = 60; // 秒
        
        if ($count >= $maxRequests) {
            // 超过限制，返回 429 状态码
            http_response_code(429);
            header('Retry-After: ' . $timeWindow);
            echo json_encode([
                'error' => 'Too Many Requests',
                'message' => '请求过于频繁，请稍后再试'
            ]);
            exit;
        }
        
        // 增加计数
        $this->cache->set($limitKey, $count + 1, $timeWindow);
    }
}
```

##### 示例 4：URL 重定向

```php
<?php

namespace Your\Module\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class UrlRedirectObserver implements ObserverInterface
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function execute(Event &$event): void
    {
        $uri = $this->request->getUri();
        
        // 检查是否需要重定向（例如：旧 URL 重定向到新 URL）
        $redirectMap = [
            '/old-page' => '/new-page',
            '/old-product' => '/new-product',
        ];
        
        if (isset($redirectMap[$uri])) {
            // 301 永久重定向
            header('Location: ' . $redirectMap[$uri], true, 301);
            exit;
        }
        
        // 检查是否需要添加尾部斜杠
        if (!str_ends_with($uri, '/') && !str_contains($uri, '.')) {
            // 重定向到带斜杠的 URL
            header('Location: ' . $uri . '/', true, 301);
            exit;
        }
    }
}
```

#### 注意事项

1. **触发条件**：该事件仅在生产环境（PROD）且非后端请求时触发
2. **执行顺序**：观察者按照 `sort` 属性排序执行，数值越小越先执行
3. **性能考虑**：该事件在每次请求时都会触发，应避免执行耗时操作
4. **退出控制**：如果需要阻止后续处理，可以使用 `exit` 或 `header('Location: ...')` 重定向
5. **Request 对象**：此时可以安全地使用 `Request` 对象获取请求信息
6. **缓存键生成**：此时可以生成正确的缓存键，因为 URL 已完全解析
7. **错误处理**：观察者中的异常不会被框架捕获，应自行处理错误

#### 系统行为说明

1. **默认观察者**：
   - `Weline_Framework_Router::check_full_page_cache_after_parse` - 检查全页缓存（sort: 100）

2. **执行顺序**：
   - 观察者按照 `sort` 属性从小到大排序执行
   - 如果某个观察者使用 `exit` 退出，后续观察者不会执行

3. **触发条件**：
   - 仅在 `PROD` 为 `true` 时触发
   - 仅在非后端请求时触发（`!$_SERVER['WELINE_IS_BACKEND']`）

#### 与 Weline_Framework::App::run_before 的区别

| 特性 | Weline_Framework::App::run_before | Weline_Framework::App::url_parsed_after |
|------|----------------|----------------------|
| 触发时机 | URL 解析前 | URL 解析后 |
| `$_SERVER['WELINE_IS_BACKEND']` | 未设置 | 已设置 |
| `Request` 对象 | 可能未初始化 | 已初始化 |
| 缓存键生成 | 无法生成正确键 | 可以生成正确键 |
| 触发条件 | 所有请求 | 仅生产环境非后端请求 |

#### 相关文件

- **事件配置**：`app/code/Weline/Framework/etc/event.xml`
- **事件定义**：`app/code/Weline/Framework/event.php`
- **触发位置**：`app/code/Weline/Framework/App.php`（第 328 行）
- **框架开发文档**：`docs/dev/开发文档.md`

#### 故障排查

##### 问题：观察者未执行

**可能原因：**
1. 不在生产环境（`PROD` 为 `false`）
2. 是后端请求（`$_SERVER['WELINE_IS_BACKEND']` 为 `true`）
3. 观察者未正确注册到 `etc/event.xml`
4. 观察者被禁用（`disabled="true"`）
5. 事件缓存未更新

**解决方法：**
1. 检查是否在生产环境
2. 检查是否是后端请求
3. 检查 `etc/event.xml` 中的观察者配置
4. 检查 `disabled` 属性是否为 `false`
5. 运行 `php bin/w event:cache -f` 清除事件缓存

##### 问题：无法生成正确的缓存键

**可能原因：**
1. 在 `Weline_Framework::App::run_before` 中尝试生成缓存键（此时 URL 未解析）
2. `Request` 对象未正确初始化

**解决方法：**
1. 在 `Weline_Framework::App::url_parsed_after` 事件中生成缓存键
2. 确保 `Request` 对象已正确初始化

##### 问题：权限检查不生效

**可能原因：**
1. 在 `Weline_Framework::App::run_before` 中检查权限（此时无法判断是否是后端请求）
2. `Request` 对象未正确初始化

**解决方法：**
1. 在 `Weline_Framework::App::url_parsed_after` 事件中检查权限
2. 使用 `$request->isBackend()` 方法判断是否是后端请求

## 扩展开发

如果需要扩展 URL 解析后的功能，可以：

1. **创建新的观察者**：实现 `ObserverInterface` 接口
2. **注册观察者**：在模块的 `etc/event.xml` 中注册
3. **设置执行顺序**：通过 `sort` 属性控制执行顺序
4. **处理异常**：确保观察者中的异常被正确处理
5. **性能优化**：避免在观察者中执行耗时操作

## 更新日志

- **2024-12-19**：初始版本，添加 `Weline_Framework::App::url_parsed_after` 事件文档

## 相关资源

- [Weline Framework 事件系统文档](../../doc/开发/服务器事件系统.md)
- [事件调试功能使用指南](../../../../../docs/事件调试功能使用指南.md)
- [观察者模式最佳实践](../../../../../docs/dev/开发文档.md)

