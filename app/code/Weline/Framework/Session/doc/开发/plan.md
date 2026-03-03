# Session SOLID 重构计划

**状态**：🟢 已完成（status: completed）
**完成时间**：2026-03-01
**完成度**：100%

## 目标

将原有的 Session 继承链重构为符合 SOLID 原则的组合式架构：

- **SRP**：Session（数据存取）与认证（登录/登出）职责分离
- **OCP**：通过配置和接口扩展，无需修改核心代码
- **LSP**：所有存储实现可互换
- **ISP**：细粒度接口，按需依赖
- **DIP**：依赖抽象接口，支持依赖注入

## 架构变更

### 原架构（已废弃）

```
Session (基类)
  └── BackendSession
        └── AdminSession
  └── FrontendSession
        └── FrontendUserSession
              └── CustomerSession
        └── FrontendApiSession
  └── BackendApiSession
```

**问题**：
- 继承链过深（5层）
- 职责混杂（数据+认证+区域配置）
- 难以扩展新区域

### 新架构（已实现）

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                        │
│  (Controllers, Services)                                    │
│                         │                                   │
│      ┌─────────────────────────────────────┐                │
│      │     AuthenticatedSession            │ ← 认证层       │
│      │  + AreaConfig (区域配置)             │                │
│      │  + AuthenticableInterface (用户)    │                │
│      └─────────────────────────────────────┘                │
│                         │                                   │
│      ┌─────────────────────────────────────┐                │
│      │           Session                   │ ← 会话层       │
│      │  SessionDataInterface (数据存取)    │                │
│      │  SessionLifecycleInterface (生命周期)│                │
│      └─────────────────────────────────────┘                │
│                    │           │                            │
│      ┌─────────────┘           └─────────────┐              │
│      ▼                                       ▼              │
│ ┌─────────────┐                      ┌─────────────┐        │
│ │  Strategy   │                      │   Storage   │        │
│ │ FPM / WLS   │                      │ File/Redis  │        │
│ └─────────────┘                      └─────────────┘        │
│                                                             │
│      ┌─────────────────────────────────────┐                │
│      │   AbstractBusinessSession           │ ← 业务层       │
│      │  CartSession / WishlistSession      │                │
│      └─────────────────────────────────────┘                │
└─────────────────────────────────────────────────────────────┘
```

## 实施阶段（全部完成）

### 阶段 1-3：接口与基础设施 ✅

- [x] `SessionDataInterface` - 数据存取
- [x] `SessionLifecycleInterface` - 生命周期
- [x] `SessionInterface` - 完整会话
- [x] `AuthenticatedSessionInterface` - 认证会话
- [x] `AuthenticableInterface` - 可认证用户
- [x] `SessionStorageInterface` - 存储抽象
- [x] `SessionStrategyInterface` - 策略抽象
- [x] `FileStorage` / `RedisStorage` / `WlsSharedStorage`
- [x] `FpmStrategy` / `WlsStrategy`

### 阶段 4-6：核心实现 ✅

- [x] `Session` - 纯数据会话（无认证逻辑）
- [x] `AuthenticatedSession` - 认证会话（组合 Session + AreaConfig）
- [x] `AreaConfig` - 区域配置（替代继承链）
- [x] `SessionFactory` - 工厂类（替代 SessionManager）

### 阶段 7-8：集成 ✅

- [x] `BackendController` / `FrontendController` 使用新接口
- [x] `BackendUser` / `Customer` 实现 `AuthenticableInterface`

### 阶段 9-11：清理与迁移 ✅

- [x] 删除旧 Session 类
- [x] `SessionDriverInterceptor` 标记废弃
- [x] 全局替换旧类引用
- [x] 添加兼容方法（`isLogin()`、`getLoginUser()` 等）

### 阶段 12-14：WLS 支持与测试 ✅

- [x] `StateManager` 注册 `SessionFactory::resetRequestInstances()`
- [x] 单元测试
- [x] 文档更新

## 扩展机制（新增）

### 认证区域扩展

```php
// 注册新区域
AreaConfig::registerArea('checkout', [
    'login_key' => 'WF_CHECKOUT_USER',
    'login_id_key' => 'WF_CHECKOUT_USER_ID',
]);

// 使用
$session = SessionFactory::getInstance()->createCustomSession('checkout');
```

### 业务数据隔离

```php
class CartSession extends AbstractBusinessSession
{
    protected const PREFIX = 'cart_';
    
    public function getItems(): array
    {
        return $this->get('items') ?? [];
    }
}
```

## 新增文件

| 文件 | 用途 |
|------|------|
| `Session/Business/AbstractBusinessSession.php` | 业务 Session 抽象基类 |
| `Session/Business/WishlistSession.php` | 愿望清单 Session |
| `Checkout/Session/CartSession.php` | 购物车 Session |
| `Backend/Service/BackendTokenService.php` | 后台 Token 服务 |

## 删除文件

- `Weline\Backend\Session\BackendSession`
- `Weline\Backend\Session\AdminSession`
- `Weline\Frontend\Session\FrontendSession`
- `Weline\Frontend\Session\FrontendUserSession`
- `Weline\Framework\App\Session\*`

## 相关文档

- [Session README](../README.md) - 使用指南与扩展方法
