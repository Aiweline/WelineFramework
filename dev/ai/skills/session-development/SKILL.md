# Session 开发技能

Session 模块开发规范，基于 SOLID 原则的组合式架构。

## 触发词

session, Session, 会话, 登录, 登出, 认证, authentication, login, logout, 
SessionFactory, AuthenticatedSession, AreaConfig, CartSession, WishlistSession,
业务 Session, 购物车 Session, 愿望清单 Session, 自定义区域, 区域隔离

## 核心概念

### 架构分层

| 层级 | 接口/类 | 职责 |
|------|---------|------|
| **认证层** | `AuthenticatedSessionInterface` | 用户登录/登出/获取用户 |
| **会话层** | `SessionInterface` | 数据存取/生命周期 |
| **策略层** | `SessionStrategyInterface` | FPM/WLS 运行策略 |
| **存储层** | `SessionStorageInterface` | 文件/Redis/WLS 存储 |
| **业务层** | `AbstractBusinessSession` | 购物车/愿望清单等 |

### 两种隔离机制

| 机制 | 用途 | 实现 |
|------|------|------|
| **认证区域隔离** | 前台/后台/API 用户分离 | `AreaConfig` + 不同 `login_key` |
| **业务数据隔离** | 购物车/愿望清单数据分离 | `AbstractBusinessSession` + `PREFIX` |

## 使用方式

### 控制器中使用（自动注入）

```php
class MyController extends BackendController
{
    public function index(): string
    {
        // $this->session 已自动注入
        if ($this->session->isLoggedIn()) {
            $user = $this->session->getUser();
            $userId = $this->session->getUserId();
        }
        
        // 存取数据（推荐直接调用，无需 getSession()）
        $this->session->set('key', 'value');
        $value = $this->session->get('key');
        $this->session->delete('key');
        $sessionId = $this->session->getId();
    }
}
```

### 手动创建 Session

```php
use Weline\Framework\Session\SessionFactory;

// 后台
$session = SessionFactory::backend();
$session = SessionFactory::getInstance()->createBackendSession();

// 前台
$session = SessionFactory::frontend();
$session = SessionFactory::getInstance()->createFrontendSession();

// 纯数据 Session（无认证）
$session = SessionFactory::session();
```

### 用户登录/登出

```php
// 登录
$user = ObjectManager::getInstance(BackendUser::class)->load($id);
$session = SessionFactory::backend();
$session->login($user);  // $user 必须实现 AuthenticableInterface

// 登出
$session->logout();
```

## 扩展方式

### 添加新认证区域

```php
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\SessionFactory;

// 方式1：注册自定义区域
AreaConfig::registerArea('api_v2', [
    'login_key' => 'WF_API_V2_USER',
    'login_id_key' => 'WF_API_V2_USER_ID',
    'user_model_key' => 'WF_API_V2_USER_MODEL',
]);
$session = SessionFactory::getInstance()->createAuthenticatedSession('api_v2');

// 方式2：一步到位
$session = SessionFactory::getInstance()->createCustomSession('api_v2', [
    'login_key' => 'WF_API_V2_USER',
]);
```

### 添加业务 Session（数据隔离）

```php
use Weline\Framework\Session\Business\AbstractBusinessSession;

class CompareSession extends AbstractBusinessSession
{
    protected const PREFIX = 'compare_';  // 所有数据自动添加此前缀

    public function getItems(): array
    {
        return $this->get('items') ?? [];
    }

    public function addItem(int $productId): void
    {
        $items = $this->getItems();
        if (!in_array($productId, $items)) {
            $items[] = $productId;
            $this->set('items', $items);
        }
    }

    public function clearCompare(): void
    {
        $this->clear();  // 清空所有 compare_ 前缀数据
    }
}

// 使用
$compare = new CompareSession();
$compare->addItem(123);
```

## 用户模型要求

用户模型必须实现 `AuthenticableInterface`：

```php
use Weline\Framework\Session\Auth\AuthenticableInterface;

class Customer extends Model implements AuthenticableInterface
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
        return $this->getData('sess_id') ?? '';
    }

    public static function getAuthModelClass(): string
    {
        return self::class;
    }
}
```

## 预置区域

| 区域 | 方法 | login_key |
|------|------|-----------|
| `backend` | `createBackendSession()` | `WF_BACKEND_USER` |
| `frontend` | `createFrontendSession()` | `WF_FRONTEND_USER` |
| `api` | `createApiSession()` | `WF_API_USER` |
| `checkout` | `createCheckoutSession()` | `WF_CHECKOUT_USER` |
| `rest_backend` | `createAuthenticatedSession('rest_backend')` | `WF_REST_BACKEND_USER` |

## 预置业务 Session

| 类 | 前缀 | 用途 |
|----|------|------|
| `Weline\Checkout\Session\CartSession` | `cart_` | 购物车 |
| `Weline\Framework\Session\Business\WishlistSession` | `wishlist_` | 愿望清单 |

## WLS 模式注意事项

- `SessionFactory` 请求级实例通过 `StateManager` 自动重置
- 使用 `WlsSharedStorage` 实现跨 Worker 共享
- 降级模式：Session Server 不可用时自动回退文件存储

## 兼容方法（已废弃）

`AuthenticatedSession` 提供以下兼容方法：

| 旧方法 | 新方法 |
|--------|--------|
| `isLogin()` | `isLoggedIn()` |
| `getLoginUser()` | `getUser()` |
| `getLoginUserID()` | `getUserId()` |
| `getLoginUsername()` | `getUsername()` |
| `getLoginUserData($key)` | `getUser()->getData($key)` |
| `getData($key)` | `get($key)` 或 `getSession()->get($key)` |
| `setData($key, $val)` | `set($key, $val)` 或 `getSession()->set($key, $val)` |

## 禁止事项

- ❌ 直接实例化旧 Session 类（`BackendSession`、`FrontendSession` 等已删除）
- ❌ 使用 `Env::getInstance(XxxSession::class)` 获取 Session
- ❌ 在 Session 类中混入业务逻辑
- ❌ 在业务 Session 中存储敏感认证信息

## 相关文档

- [Session README](../../../app/code/Weline/Framework/Session/README.md)
- [Session 重构计划](../plans/session-solid-refactor.plan.md)
