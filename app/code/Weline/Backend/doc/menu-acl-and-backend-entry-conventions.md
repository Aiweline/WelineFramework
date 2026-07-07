# 后台菜单、ACL 与入口约定

## 1. 菜单源只有 `menu.xml`

后台菜单声明源固定是：

- `etc/backend/menu.xml`

收集器会把它同步进 ACL 存储，但数据库不是手写源。任何“菜单没出来，直接改库”都是错误修法。

相关入口：

- `app/code/Weline/Backend/Observer/UpgradeMenu.php`
- `app/code/Weline/Backend/Service/MenuCollector.php`
- `app/code/Weline/Backend/Config/MenuXmlReader.php`

## 2. 父级链必须闭合

`MenuCollector` 会强校验 `parent_source`。如果某个菜单的父级不存在，会直接抛异常：

- 不会自动创建缺失父级
- 不会静默跳过
- 不会默认挂到根节点

所以新增菜单前，先确认父级 source 真存在。

## 3. 菜单与 `#[Acl]` 要成对看

一个后台能力通常至少有两层入口：

- 菜单入口：决定它在哪个后台分组出现。
- 控制器 `#[Acl]`：决定它是否可访问、权限如何细分。

只补一边会导致：

- 页面能访问但菜单不出现
- 菜单出现但动作被拒
- 子动作权限粒度失控

## 4. 后台前端请求协议

后台页面也属于浏览器页面，业务请求仍然只能走：

- `Weline.Api.resource()`
- `Weline.Api.graph()`
- `Weline.Api.stream()`

不要因为代码在后台模板里，就写原生 `fetch/ajax`。

## 5. 页面落点

模板与资源优先落在：

- `view/templates/Backend/*`
- `view/statics/*`

不要直接改：

- `view/tpl/*`

## 6. 扩展入口

后台模块已提供一些稳定扩展面：

- `hook.php`
- `doc/hook/*`
- 通知渠道适配器
- 通知 topic provider

需要扩展后台头部、局部面板、消息通知时，优先挂这些入口，避免直接侵入通用骨架模板。
