# Weline Frontend 前端模块

## 当前有效定位

`Weline_Frontend` 负责前端页面入口、浏览器运行时接入、前端控制器能力和浏览器请求层对接，但**当前主题源规范与默认主题目录的权威位置在 `Weline_Theme`**。

如果你的任务是：

- 开发主题
- 改页面布局
- 覆盖默认主题
- 新增 widget / slot / partial / component

请先去读：

1. [`AI-INDEX.md`](./AI-INDEX.md)
2. [`../../Theme/doc/AI-INDEX.md`](../../Theme/doc/AI-INDEX.md)
3. [`../../Theme/doc/theme-inheritance-and-file-conventions.md`](../../Theme/doc/theme-inheritance-and-file-conventions.md)
4. [`../../Theme/doc/开发/Theme开发总指南.md`](../../Theme/doc/开发/Theme开发总指南.md)
5. [`../../Theme/view/theme/README.md`](../../Theme/view/theme/README.md)

## Frontend 模块真正该看的内容

### 浏览器业务请求

站内业务请求只能走：

- [`Weline.Api使用指南.md`](./Weline.Api使用指南.md)

核心规则：

- 必须使用 `Weline.Api.resource()` / `graph()` / `stream()` / `request()`
- 禁止 `fetch`
- 禁止 `XMLHttpRequest`
- 禁止 `$.ajax`
- 禁止 `axios`
- 禁止手写 query-bin URL

### 主题设计资料

`doc/主题设计/` 目录现在只保留为**补充型资料**，主要用于理解视觉 token、变量、颜色与历史设计结构。

如果其中内容与以下文档冲突，以后者为准：

- `app/code/Weline/Theme/doc/开发/Theme开发总指南.md`
- `app/code/Weline/Theme/doc/layout-discovery-guide.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
- `dev/ai/global-constraints.md`

## 重要迁移说明

下面这些旧示例不要再当现行规范：

- 禁止复制 `$.ajax(...)`
- 禁止复制 `alert(...)`
- 直接 `/your-module/ajax`
- `design/frontend/default`
- `Weline_Frontend::theme/...` 作为默认主题主路径

这些历史示例之所以不能继续用，是因为当前框架已经明确收敛到：

- 主题目录：`Weline_Theme/view/theme`
- 设计覆盖：`app/design/**`
- 浏览器请求：`Weline.Api.*`
- 源模板优先，不改 `view/tpl` / `generated/`

## 推荐阅读顺序

1. AI 入口：[`AI-INDEX.md`](./AI-INDEX.md)
2. Theme AI 入口：[`../../Theme/doc/AI-INDEX.md`](../../Theme/doc/AI-INDEX.md)
3. Theme 总指南：[`../../Theme/doc/开发/Theme开发总指南.md`](../../Theme/doc/开发/Theme开发总指南.md)
4. 浏览器请求：[`Weline.Api使用指南.md`](./Weline.Api使用指南.md)
5. 主题资料索引：[`主题设计/README.md`](./主题设计/README.md)
6. Hook / Event / 具体前端模块文档：按任务命中相关文档

## 依赖关系

- `Weline_Framework`
- `Weline_Admin`
- `Weline_Backend`
- `Weline_SystemConfig`
- `Weline_Theme`
- 可选：`Weline_Ai`、`Weline_UrlManager`、`Weline_Websites`；缺少任一可选模块时不得触发类加载错误。

### 公开账户边界

需要读取旧 `frontend_user` 账户选择器、按 ID/用户名/邮箱做可信身份映射时，使用
`Weline\Frontend\Api\Auth\FrontendAccountFacadeInterface`。它只返回
`FrontendUserIdentity` / `FrontendUserSearchResult` 数据对象，ORM、Session 和登录状态
写入都留在 Frontend 内部；调用模块不得引用 `Frontend\Model\FrontendUser`。

纯下拉候选或筛选器应调用该 facade 的 `search()` 并读取 `FrontendUserIdentity`，不要改用后台
管理契约；这样不会为每个候选额外统计 Token。调用方可按自己的模板契约重新投影字段，但不得
依赖 Frontend ORM 行、字段常量或 Query Builder。

`loginTrustedIdentity()` 只接受已经过签名 token 或等价可信断言校验的身份，不能用作
密码登录入口。当前旧 `FrontendUser` 尚未实现 Framework `AuthenticableInterface`，因此
这条历史 Session 登录路径仍不能作为已通过的运行能力；修复该模型认证契约前只能验证
身份查询与 token 签发/校验，不能宣称旧前台账号已完成自动登录。

当前 `frontend_user` schema 也没有 `email` 列；历史非空搜索仍按原实现查询
`username,email` 并抛出数据库异常。本次 facade 保持该异常行为以避免伪造兼容性，后续应
在独立 schema/认证修复中统一决定是补列还是把旧账户体系迁到 Customer，不能在边界迁移
中静默改变身份匹配规则。

后台维护旧 `frontend_user` 账户时，跨模块只能使用
`Weline\Frontend\Api\User\FrontendUserAdministrationInterface`。列表与详情返回不可变的
`FrontendUserPage` / `FrontendUserRecord`，保存输入使用纯标量
`FrontendUserSaveCommand`，写操作结果使用 `FrontendUserMutationResult`。用户名判重、密码哈希、
登录失败次数清理、Session ID 清理、Token 统计和删除顺序都由 Frontend 模块内部实现；调用模块
不得引用 `Frontend\Model\FrontendUser`、`FrontendUserToken` 或 Frontend 内部 Service。

该管理契约与 `FrontendAccountFacadeInterface` 的可信身份边界相互独立：前者只服务已声明依赖的
后台账户维护，后者只服务身份查询与可信断言登录。不得把管理契约用于浏览器密码登录，也不得
把 ORM、Query Builder、密码哈希或 Token 实体放入公开 DTO。

## 可继续使用的资料

- [`Weline.Api使用指南.md`](./Weline.Api使用指南.md)
- [`hook/frontend/head.md`](./hook/frontend/head.md)
- [`主题设计/`](./主题设计/)

## 文档维护原则

本 README 现在只保留当前有效入口，不再继续累积过时控制器、AJAX 或主题目录示例。若要补充前端开发文档，请优先更新：

配置键提示中的通用占位模块使用 `Vendor_Module::header`；它只是格式示例，不代表对某个 `Weline_*` 模块的运行时依赖。

- Theme 总指南
- Weline.Api 使用指南
- 具体模块自己的 `doc/`

## 运行期公共边界

- 系统通知只经 Admin `SystemNotificationDirectoryInterface` 读取。
- 预览主题色系只经 Theme `PreviewThemeModeResolverInterface` 读取。
- 页面布局 Meta 只经 Theme `ComponentMetaReaderInterface` 读取。
- 可选 URL 重写只经 UrlManager `UrlRewriteDirectoryInterface` 和 Runtime Provider 解析。

Frontend 不得直接引用这些模块的 Model、Helper、Service 或 Block。
