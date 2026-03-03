# Weline Framework Session 模块

Session 管理模块，遵循 SOLID 原则设计，提供清晰的职责分离和灵活的扩展机制。

## 架构概述

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                         │
│  (Controllers, Services)                                     │
│                         │                                    │
│            ┌───────────────────────────┐                     │
│            │   AuthenticatedSession    │ ← 认证层            │
│            │   (login/logout/getUser)  │                     │
│            └───────────────────────────┘                     │
│                         │                                    │
│            ┌───────────────────────────┐                     │
│            │        Session            │ ← 会话层            │
│            │   (get/set/start/destroy) │                     │
│            └───────────────────────────┘                     │
│                    │           │                             │
│      ┌─────────────┘           └─────────────┐               │
│      ▼                                       ▼               │
│ ┌─────────────┐                      ┌─────────────┐         │
│ │  Strategy   │                      │   Storage   │         │
│ │ FPM / WLS   │                      │ File/Redis  │         │
│ └─────────────┘                      └─────────────┘         │
└─────────────────────────────────────────────────────────────┘
```

## 核心接口

| 接口 | 职责 | 位置 |
|------|------|------|
| `SessionDataInterface` | 数据存取 (get/set/has/delete) | `Session/` |
| `SessionLifecycleInterface` | 生命周期 (start/destroy/regenerate) | `Session/` |
| `SessionInterface` | 完整会话 (组合上述两个) | `Session/` |
| `AuthenticatedSessionInterface` | 用户认证 (login/logout/getUser) | `Session/Auth/` |
| `AuthenticableInterface` | 可认证用户模型 | `Session/Auth/` |
| `SessionStorageInterface` | 数据持久化 | `Session/Storage/` |
| `SessionStrategyInterface` | 运行策略 | `Session/Strategy/` |

## 快速开始

### 后台控制器中使用

```php
use Weline\Framework\App\Controller\BackendController;

class MyController extends BackendController
{
    public function index(): string
    {
        // $this->session 已自动注入为 AuthenticatedSessionInterface
        
        // 检查登录状态
        if (!$this->session->isLoggedIn()) {
            return $this->redirect('/admin/login');
        }
        
        // 获取当前用户
        $user = $this->session->getUser();
        $userId = $this->session->getUserId();
        $username = $this->session->getUsername();
        
        // 存取 Session 数据
        $this->session->getSession()->set('last_visit', time());
        $lastVisit = $this->session->getSession()->get('last_visit');
        
        return $this->fetch('index');
    }
}
```

### 手动创建 Session

```php
use Weline\Framework\Session\SessionFactory;

// 创建后台认证 Session
$session = SessionFactory::getInstance()->createBackendSession();

// 创建前台认证 Session
$session = SessionFactory::getInstance()->createFrontendSession();

// 创建纯数据 Session（不含认证）
$session = SessionFactory::getInstance()->createSession();

// 静态便捷方法
$session = SessionFactory::backend();
$session = SessionFactory::frontend();
$session = SessionFactory::session();
```

### 用户登录

```php
use Weline\Framework\Session\SessionFactory;
use Weline\Backend\Model\BackendUser;

// 获取用户模型
$user = ObjectManager::getInstance(BackendUser::class);
$user->load($userId);

// 用户模型必须实现 AuthenticableInterface
$session = SessionFactory::backend();
$session->login($user);

// 验证登录状态
if ($session->isLoggedIn()) {
    $username = $session->getUsername();
}
```

### 用户登出

```php
$session = SessionFactory::backend();
$session->logout();
```

## 存储后端

| 存储类型 | 类 | 适用场景 |
|----------|-----|----------|
| File | `FileStorage` | 单机部署、FPM 模式 |
| Redis | `RedisStorage` | 分布式部署 |
| WLS | `WlsSharedStorage` | WLS 常驻内存模式 |

### 配置示例 (etc/env.php)

```php
return [
    'session' => [
        'default' => 'file', // file, redis, wls
        'lifetime' => 3600,
        'cookie_path' => '/',
        'cookie_secure' => true,
        'cookie_httponly' => true,
        
        'drivers' => [
            'file' => [
                'path' => 'var/session/',
            ],
            'redis' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'database' => 0,
                'prefix' => 'weline_sess:',
            ],
        ],
        
        'wls' => [
            'enabled' => true,
            'backend' => 'wls', // wls, redis
            'wls_server' => [
                'host' => '127.0.0.1',
                'port' => 19970,
            ],
        ],
    ],
];
```

## 运行策略

| 策略 | 类 | 说明 |
|------|-----|------|
| FPM | `FpmStrategy` | 传统 PHP-FPM 模式，使用 session_*() 函数 |
| WLS | `WlsStrategy` | WLS 常驻内存模式，直接操作 Storage |

策略自动根据 `Runtime::isPersistent()` 选择，无需手动配置。

## 用户模型要求

用户模型必须实现 `AuthenticableInterface`：

```php
use Weline\Framework\Session\Auth\AuthenticableInterface;

class BackendUser extends Model implements AuthenticableInterface
{
    public function getAuthIdentifier(): int|string
    {
        return $this->getId();
    }

    public function getAuthUsername(): string
    {
        return $this->getUsername();
    }

    public function getAuthSessionId(): string
    {
        return $this->getData('sess_id') ?: '';
    }

    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}
```

## WLS 模式注意事项

1. **状态重置**：SessionFactory 的请求级实例在每个请求结束时通过 `StateManager` 自动重置
2. **Session 共享**：WLS 模式下使用 Session Server 实现跨 Worker 共享
3. **降级模式**：Session Server 不可用时自动降级到文件存储

## 迁移指南

### 从旧架构迁移

```php
// 旧写法（已废弃）
use Weline\Backend\Session\BackendSession;
$session = ObjectManager::getInstance(BackendSession::class);
$session->isLogin();
$session->getLoginUserID();

// 新写法
use Weline\Framework\Session\SessionFactory;
$session = SessionFactory::backend();
$session->isLoggedIn();
$session->getUserId();
```

### 兼容方法

`AuthenticatedSession` 提供以下兼容方法（标记为 `@deprecated`）：

- `isLogin()` → 使用 `isLoggedIn()`
- `getLoginUser()` → 使用 `getUser()`
- `getLoginUserID()` → 使用 `getUserId()`
- `getLoginUsername()` → 使用 `getUsername()`
- `getLoginUserData($key)` → 使用 `getUser()->getData($key)` 或直接访问用户模型属性
- `getData()` / `setData()` → 使用 `getSession()->get()` / `getSession()->set()`

## 扩展 Session

### 添加新的认证区域

如果需要新增一种认证区域（如 `checkout`、`api_v2`），可以通过以下方式：

```php
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\SessionFactory;

// 方式1：注册自定义区域
AreaConfig::registerArea('checkout', [
    'login_key' => 'WF_CHECKOUT_USER',
    'login_id_key' => 'WF_CHECKOUT_USER_ID',
    'user_model_key' => 'WF_CHECKOUT_USER_MODEL',
    'cookie_path' => '/',
]);

// 然后创建该区域的 Session
$session = SessionFactory::getInstance()->createAuthenticatedSession('checkout');

// 方式2：一步到位
$session = SessionFactory::getInstance()->createCustomSession('checkout', [
    'login_key' => 'WF_CHECKOUT_USER',
    'login_id_key' => 'WF_CHECKOUT_USER_ID',
]);
```

### 创建业务级 Session（数据隔离）

对于购物车、愿望清单等需要数据命名空间隔离的场景，继承 `AbstractBusinessSession`：

```php
use Weline\Framework\Session\Business\AbstractBusinessSession;

class CartSession extends AbstractBusinessSession
{
    protected const PREFIX = 'cart_';  // 所有数据自动添加此前缀

    public function getItems(): array
    {
        return $this->get('items') ?? [];
    }

    public function setItems(array $items): void
    {
        $this->set('items', $items);
    }

    public function addItem(array $item): void
    {
        $items = $this->getItems();
        $items[] = $item;
        $this->setItems($items);
    }

    public function clearCart(): void
    {
        $this->clear();  // 清空所有 cart_ 前缀的数据
    }
}
```

使用方式：

```php
$cart = new CartSession();
$cart->addItem(['product_id' => 123, 'qty' => 2, 'price' => 99.00]);
$items = $cart->getItems();
$cart->clearCart();
```

### 现有业务 Session 示例

| 类 | 用途 | 前缀 |
|----|------|------|
| `Weline\Checkout\Session\CartSession` | 购物车 | `cart_` |
| `Weline\Framework\Session\Business\WishlistSession` | 愿望清单 | `wishlist_` |

### 区域隔离 vs 数据隔离

| 类型 | 用途 | 实现方式 |
|------|------|----------|
| **认证区域隔离** | 前台/后台/API 用户登录分离 | `AuthenticatedSession` + `AreaConfig` |
| **业务数据隔离** | 购物车/愿望清单数据分离 | `AbstractBusinessSession` + 命名空间前缀 |

- **认证区域**：通过不同的 `login_key` 实现用户身份隔离
- **业务数据**：通过不同的 `PREFIX` 实现数据命名空间隔离

## 测试

```bash
# 运行 Session 模块测试
php bin/w test:run Weline_Framework/Session
```

## 设计原则

- **SRP**: Session、认证、存储、策略各司其职
- **OCP**: 新增存储/策略只需实现接口，不改核心
- **ISP**: 细粒度接口，按需依赖
- **DIP**: 依赖抽象接口，支持 mock 测试
- **组合优于继承**: 用 AreaConfig 替代继承链
