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

公开的后台开始路由配置常量位于 `Weline\Backend\Api\Config\KeysInterface`。
`Weline\Backend\Config\KeysInterface` 仅保留为兼容别名，跨模块新代码只能使用 `Api` 契约。

后台公共头尾模板、Block 和运行期静态文件由
`Api/View/ViewWarmupContributionProvider.php` 以 Framework 数据契约声明。WLS 启动时由
Theme 的通用执行器从编译 Provider 索引统一预热，Theme 不硬编码 Backend 路径。

### 后台配置公开边界

- 跨模块读写后台区域的全局配置时，只能使用
  `Weline\Backend\Api\Config\BackendConfigStore`。该 facade 固定
  `area=backend`、`scope=global`、`locale=default`，保证读、写、删除使用同一个配置身份，
  并复用 SystemConfig 的请求缓存失效机制。
- 用户级后台配置只能使用
  `Weline\Backend\Api\Config\BackendUserConfigStore`。公共方法只返回标量，查询始终约束
  `user_id + key`，不向模块外暴露 ORM、Query Builder 或可变 Model 状态。
- 当前登录后台用户的临时 scope 数据使用
  `Weline\Backend\Api\UserData\BackendCurrentUserDataInterface`。Provider 通过
  `BackendUserContextProvider` 确定当前用户，只返回 JSON 解码后的数组或清理结果；
  调用模块不得引用 `BackendUserData` Model。
- `getDefaultConfig()` 为了 Setup 兼容保留旧行为：读取第一条 `user_id=0` 记录。
  新代码需要精确默认键时必须使用 `getDefaultConfigForKey()`。
- `Backend\Model\Config` 和 `Backend\Model\BackendUserConfig` 仅保留给 Backend 内部及旧代码兼容；
  新的跨模块引用必须指向 `Backend\Api\*`，并在 `etc/module.php` 声明 `Weline_Backend` 依赖。

跨模块只读 WLS 后台预热请求态时，使用
`Weline\Backend\Api\Runtime\BackendWarmupContext`。用户装载、上下文安装和清理仍由
Backend 内部 Service 与 Framework 注册的 Runtime Provider 负责，调用模块不能直接操作内部实现。

后台 REST 令牌登录、刷新、撤销、用户摘要和委托 actor 装载通过
`Weline\Backend\Api\Auth\BackendApiAuthenticationInterface` 发布；调用模块不得引用
`BackendTokenService` 或 `BackendUser` Model。需要后台用户下拉或按 ID 查询时使用
`BackendUserDirectoryInterface`，它只返回 `BackendUserContext` 数据对象。
客服人员等跨模块列表应使用该目录按用户 ID 补充用户名，不得为展示字段加载 BackendUser Model。

可信站内身份桥需要搜索、按用户名/邮箱映射或建立后台 Session 时，使用
`Weline\Backend\Api\Auth\BackendAccountFacadeInterface`。该 facade 只返回
`BackendUserIdentity` / `BackendUserSearchResult` 数据对象，ORM、删除标记过滤、Session
写入、登录 IP、失败次数复位和头像持久化全部留在 Backend 内部。`loginTrustedIdentity()`
只能在调用模块已经完成签名 token 或等价可信断言校验后调用，不能替代密码认证。

Admin UI 的管理员 CRUD、分页和角色关联使用
`Weline\Backend\Api\User\BackendUserAdministrationInterface`，只返回不可变
`BackendUserRecord` / `BackendUserPage`。交互式密码登录、尝试次数、Session 安装和
remember token 使用 `Weline\Backend\Api\Auth\BackendInteractiveAuthInterface`，其
`BackendLoginAccount` 不含密码哈希或 token 密文，`BackendRememberToken` 只暴露用户 ID 和过期时间。
密码校验、ORM 还原、角色关联查询、token 密文清理和 Session 底层身份键写入全部留在
Backend 实现内；Admin 不得引用 `BackendUser`、`BackendUserToken`、`UserRole` 或内部 Service。
后台历史日志需要按用户名搜索或批量补全用户名时，使用同一 facade 的
`idsMatchingUsername()` / `usernamesByIds()`。这两个只读方法会包含已删除用户，以保持
历史审计显示，但只返回 ID/用户名标量；密码、Session ID、登录 IP 等字段不会跨模块。

后台菜单读取统一使用 `Weline\Backend\Api\Menu\MenuReaderInterface`。其实现通过
`Weline\Acl\Api\Resource\MenuResourceServiceInterface` 读取菜单树，并通过
`Weline\Acl\Api\Authorization\AuthorizationServiceInterface` 完成权限粗判和默认入口选择；
`Backend\Model\Menu` 只保留菜单表自身字段与 URL 行为，不再承载 ACL/Role 查询。

CLI 角色查询通过 `Weline\Acl\Api\Role\RoleCatalogInterface` 获取只读 `RoleRecord`，
禁止从 Backend 命令直接实例化 Acl Role Model。后台主题 Session 配置键由
`Weline\Backend\Api\View\BackendThemeConfigInterface::SESSION_CONFIG_KEY` 公开，Admin
等调用模块不得引用 `Backend\Block\ThemeConfig` 常量。

Theme 等必需依赖模块读写当前后台用户的外观配置时，也只能使用
`BackendThemeConfigInterface`。`reloadForCurrentUser()` 刷新当前用户上下文，
`getOriginThemeConfig()` / `getThemeConfig()` / `getThemeModel()` 只返回标量或数组，
`setThemeConfig()` 只接收配置键或纯数组；`Backend\Block\ThemeConfig`、用户配置 ORM、
Session 实现和 `__init()` 生命周期都留在 Backend 内部。
跨模块需要当前后台主题值时，使用同一接口的 `getThemeConfig()` /
`getOriginThemeConfig()`；不得实例化 `Backend\Block\ThemeConfig`，也不得跨模块手工调用 Block `__init()`。

管理员运行期角色也遵循同一边界：`BackendUser` 持有本模块的 UserRole 关联，并通过
ACL `RoleCatalogInterface` 校验和投影为 `RoleRecord`。内建超级管理员角色由
`RoleAdministrationInterface` 幂等安装；Backend Setup 不再写 ACL schema。

菜单收集器只负责读取 menu.xml、计算 diff、父子拓扑和本模块 legacy menu 同步；
所有 ACL 菜单持久化经 `MenuRegistryInterface` 完成，跨模块不传 ORM 对象。

Theme 等渲染层需要当前后台页面标题和面包屑时，使用
`Weline\Backend\Api\Menu\PageHeaderResolver`。该边界在 Backend 内部完成菜单表与
`menu.xml` 回退解析，只返回标量数组；调用模块不会获得可变 `Menu` ORM
或 `MenuXmlReader`，请求热路径也不再通过 Theme 的 ObjectManager 服务定位。

## 核心约定

- 后台菜单唯一来源是各模块 `etc/backend/menu.xml`。数据库里的菜单 ACL 是收集结果，不是手工维护源。
- 菜单收集由 `Observer/UpgradeMenu.php` 和 `Service/MenuCollector.php` 驱动；系统升级后会全量收集。不要手改 `weline_acl(type=menus)` 来“修菜单”。
- `MenuCollector` 会校验 `parent_source` 链路是否真实存在。父级断层会直接抛异常中断收集，而不是默默忽略。
- 后台控制器访问控制靠 `#[Acl]`。新增后台页时，菜单、控制器类/动作 Acl、模板入口要一起看，不要只补其中一半。
- 后台页面里的业务请求同样走 `Weline.Api.*`；不能因为是后台页面就退回原生 Ajax。
- `view/templates` 是源模板，`view/tpl` 是编译/生成产物；修页面时只能改源模板。
- `模块::key` 校验提示使用 `Vendor_Module::header` 作为占位示例；示例模块名不构成运行时依赖，也不能伪装成已安装的 `Weline_*` 模块。
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
