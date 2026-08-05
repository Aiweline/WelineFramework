# 后台菜单、ACL 与入口约定

## 1. 后台入口必须携带 area key

后台 HTML 路由只能通过配置的 `area_routes.backend.prefix` 进入，并且 key 必须是 URL 的第一段：

- 允许：`/{backend_key}/admin/login`
- 允许：`/{backend_key}/{currency}/{locale}/admin/login`
- 拒绝：`/admin/login`
- 拒绝：`/{currency}/{locale}/admin/login`

缺少 key、key 错误或大小写不一致时统一返回 404，不建立后台 Session，不执行登录、事件或业务控制器。路由解析层与 `BackendController` 入口层都必须保持这个不变量，禁止根据 `admin` 或 `backend` 路径字面量推断后台区域。

## 2. 菜单源只有 `menu.xml`

后台菜单声明源固定是：

- `etc/backend/menu.xml`

收集器会把它同步进 ACL 存储，但数据库不是手写源。任何“菜单没出来，直接改库”都是错误修法。

相关入口：

- `app/code/Weline/Backend/Observer/UpgradeMenu.php`
- `app/code/Weline/Backend/Service/MenuCollector.php`
- `app/code/Weline/Backend/Config/MenuXmlReader.php`

## 3. 父级链必须闭合

`MenuCollector` 会强校验 `parent_source`。如果某个菜单的父级不存在，会直接抛异常：

- 不会自动创建缺失父级
- 不会静默跳过
- 不会默认挂到根节点

所以新增菜单前，先确认父级 source 真存在。

## 4. 菜单与 `#[Acl]` 要成对看

一个后台能力通常至少有两层入口：

- 菜单入口：决定它在哪个后台分组出现。
- 控制器 `#[Acl]`：决定它是否可访问、权限如何细分。

只补一边会导致：

- 页面能访问但菜单不出现
- 菜单出现但动作被拒
- 子动作权限粒度失控

## 5. 后台前端请求协议

后台页面也属于浏览器页面，业务请求仍然只能走：

- `Weline.Api.resource()`
- `Weline.Api.graph()`
- `Weline.Api.createStream()` / `Weline.Api.stream()`（仅已开放的非后台权威流）

不要因为代码在后台模板里，就写原生 `fetch/ajax`。

后台 Worker operation 必须同时声明身份与资源：

```php
[
    'name' => 'saveSomething',
    'frontend' => true,
    'auth' => 'backend',
    'backend_acl' => [
        'kind' => 'source',
        'source_id' => 'Vendor_Module::exact_source',
    ],
]
```

- `auth=backend` 是现行契约；`backend=true` 只保留给旧 descriptor 兼容，但同样强制 `backend_acl`。
- `kind=source` 使用编译期固定的精确 `source_id`；禁止父级、前缀或 route 猜测。
- 一个 operation 复用多种动作时使用 `kind=param_map`，参数值必须在静态 exact map 中；未知值在 provider 执行前 403。
- 仅操作当前 binding 用户自身数据、且没有 `user_id/role_id/website_id` 等主体切换参数时，才允许 `kind=self`。
- 资源必须真实存在、启用、属于后台，并且当前实时角色有精确 `RoleAccess`。即使是超级管理员，拼错或不存在的 source 也拒绝。
- same-origin 只证明传输来源，不证明后台权限。`resource()` 和 graph 的每个节点都会先恢复页面证明，再做 ACL；graph 任一节点失败时不执行任何节点。
- `auth=backend` stream 当前 fail-closed 返回 `backend_stream_disabled`。例外：后台页面带着有效 Worker binding 时，可使用 `runtime_task.events`（授权仍走 type 的 `areas + backend_acl` 与 task owner/lease）；不得把任意 `auth=backend` QueryProvider stream 当作已开放。

## 6. 页面落点

模板与资源优先落在：

- `view/templates/Backend/*`
- `view/statics/*`

不要直接改：

- `view/tpl/*`

## 7. 扩展入口

后台模块已提供一些稳定扩展面：

- `hook.php`
- `doc/hook/*`
- 通知渠道适配器
- 通知 topic provider

需要扩展后台头部、局部面板、消息通知时，优先挂这些入口，避免直接侵入通用骨架模板。
