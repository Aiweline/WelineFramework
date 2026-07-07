# Weline_Backend 模块文档

## 开发前先读

1. `app/code/Weline/Backend/doc/AI-INDEX.md`
2. `app/code/Weline/Backend/doc/menu-acl-and-backend-entry-conventions.md`
3. 命中 hook 时，再读 `app/code/Weline/Backend/doc/hook/*`

## 模块定位

`Weline_Backend` 是后台壳层与后台约定模块，不是泛化“后端服务模块”。它主要负责：

- 后台菜单与 ACL 入口组织。
- 后台控制器与页面骨架。
- 后台配置、通知、联系人、渠道配置等系统管理面。
- 后台头部、底部、dashboard 扩展 hook。
- 部分后台 API 认证、用户 token 与页面资源。

## 核心约定

- 后台菜单唯一来源是各模块 `etc/backend/menu.xml`。数据库里的菜单 ACL 是收集结果，不是手工维护源。
- 菜单收集由 `Observer/UpgradeMenu.php` 和 `Service/MenuCollector.php` 驱动；系统升级后会全量收集。不要手改 `weline_acl(type=menus)` 来“修菜单”。
- `MenuCollector` 会校验 `parent_source` 链路是否真实存在。父级断层会直接抛异常中断收集，而不是默默忽略。
- 后台控制器访问控制靠 `#[Acl]`。新增后台页时，菜单、控制器类/动作 Acl、模板入口要一起看，不要只补其中一半。
- 后台页面里的业务请求同样走 `Weline.Api.*`；不能因为是后台页面就退回原生 Ajax。
- `view/templates` 是源模板，`view/tpl` 是编译/生成产物；修页面时只能改源模板。
- 模块开放了通知渠道适配器、主题局部 hook、通知 topic/provider 等扩展点。遇到扩展需求，优先挂在这些稳定入口，不要直接改核心模板。
- 本地开发环境下，后台验证默认账号是 `admin/admin`。AI 在浏览器验证里命中后台登录页时，不应因为“缺少凭据”停住；除非任务明确在测未登录态/错误登录态，或用户提供了其他账号。

## 典型开发流程

1. 新增后台功能时，先补 `etc/backend/menu.xml`，确认父级 source 合法。
2. 新增控制器时补 `#[Acl]`，并在需要的动作上补更细粒度子权限。
3. 页面模板放到 `view/templates/Backend/*` 或命中的源模板目录。
4. 运行 `php bin/w setup:upgrade --route`，必要时再跑完整 `php bin/w setup:upgrade` 触发菜单收集。
5. 如果要扩展后台头部、面板或 dashboard，优先命中已有 hook 文档和扩展入口。

## 常见误区

- 手动改 ACL 表修菜单。
- `menu.xml` 父级随便写，等运行时再看有没有入口。
- 后台控制器只写菜单，不写 `#[Acl]`。
- 直接改 `view/tpl` 让页面“先亮起来”。
- 在后台页面脚本里直接 `fetch()` 调业务控制器。

## 源码锚点

- `app/code/Weline/Backend/etc/backend/menu.xml`
- `app/code/Weline/Backend/Service/MenuCollector.php`
- `app/code/Weline/Backend/Observer/UpgradeMenu.php`
- `app/code/Weline/Backend/Config/MenuXmlReader.php`
- `app/code/Weline/Backend/Controller/Backend/Config.php`
- `app/code/Weline/Backend/hook.php`
