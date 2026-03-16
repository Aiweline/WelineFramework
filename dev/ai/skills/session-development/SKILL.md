---
name: session-development
description: Session 开发。SessionFactory、AreaConfig 区域隔离、AbstractBusinessSession 业务隔离、登录/认证。
globs: []
alwaysApply: false
---

# session-development（极简版）

## 何时使用

- Session、会话、登录、认证
- 购物车、愿望清单等业务 Session
- 区域隔离（前台/后台/API）

## 必做

- 用 SessionFactory::backend()、SessionFactory::frontend() 创建
- 认证区域隔离用 AreaConfig + 不同 login_key
- 业务 Session 继承 AbstractBusinessSession，用 PREFIX 隔离
- 控制器中 $this->session 已注入，直接 set/get/delete

## 最小示例

```php
$session = SessionFactory::backend();
$session->set('key', 'value');
$value = $session->get('key');
if ($session->isLoggedIn()) {
    $user = $session->getUser();
}
```

## 禁止

- 直接操作 $_SESSION 不通过 Session 接口
- 业务 Session 不继承 AbstractBusinessSession
