# Session SOLID 重构计划

**状态**：🟢 已完成（status: completed）
**完成时间**：2026-03-01
**完成度**：100%（14/14 阶段完成）

## 概述

将 Weline Framework 的 Session 模块从深层继承架构重构为符合 SOLID 原则的组合式架构。

## 涉及模块及子计划

| 模块 | 子计划 | 任务进度 | 状态 | 完成度 |
|------|--------|----------|------|--------|
| Weline_Framework (Session) | [plan.md](../../app/code/Weline/Framework/Session/doc/开发/plan.md) | [task.md](../../app/code/Weline/Framework/Session/doc/开发/task.md) | 🟢 已完成 | 100% |

## 核心变更

### 新增接口（7个）

- `SessionDataInterface` - 数据存取
- `SessionLifecycleInterface` - 生命周期
- `SessionInterface` - 完整会话
- `AuthenticatedSessionInterface` - 认证会话
- `AuthenticableInterface` - 可认证用户
- `SessionStorageInterface` - 存储抽象
- `SessionStrategyInterface` - 策略抽象

### 新增实现

| 类 | 用途 |
|----|------|
| `Session` | 纯数据会话（无认证） |
| `AuthenticatedSession` | 认证会话（组合模式） |
| `AreaConfig` | 区域配置（替代继承） |
| `SessionFactory` | 工厂类（替代 SessionManager） |
| `FileStorage` | 文件存储 |
| `RedisStorage` | Redis 存储 |
| `WlsSharedStorage` | WLS 共享存储 |
| `FpmStrategy` | FPM 策略 |
| `WlsStrategy` | WLS 策略 |
| `AbstractBusinessSession` | 业务 Session 抽象基类 |
| `CartSession` | 购物车 Session |
| `WishlistSession` | 愿望清单 Session |
| `BackendTokenService` | 后台 Token 服务 |

### 删除类

- `Weline\Backend\Session\BackendSession`
- `Weline\Backend\Session\AdminSession`
- `Weline\Frontend\Session\FrontendSession`
- `Weline\Frontend\Session\FrontendUserSession`
- `Weline\Framework\App\Session\BackendApiSession`
- `Weline\Framework\App\Session\FrontendApiSession`
- 等其他旧 Session 类

## 扩展机制

### 添加新认证区域

```php
AreaConfig::registerArea('checkout', [...]);
$session = SessionFactory::getInstance()->createCustomSession('checkout');
```

### 添加新业务 Session

```php
class CartSession extends AbstractBusinessSession
{
    protected const PREFIX = 'cart_';
    // ...
}
```

## 迁移兼容

`AuthenticatedSession` 提供以下兼容方法（标记 `@deprecated`）：

- `isLogin()` → `isLoggedIn()`
- `getLoginUser()` → `getUser()`
- `getLoginUserID()` → `getUserId()`
- `getLoginUsername()` → `getUsername()`
- `getLoginUserData($key)` → `getUser()->getData($key)`

## 相关文档

- [Session README](../../app/code/Weline/Framework/Session/README.md)
- [模块计划](../../app/code/Weline/Framework/Session/doc/开发/plan.md)
- [任务清单](../../app/code/Weline/Framework/Session/doc/开发/task.md)
