<!-- weline:module-readme:auto-generated -->
# Weline_Customer 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/Customer/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_Customer`
- 目录：`app/code/Weline/Customer`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。
- 账户壳固定依赖 Backend、Framework、Frontend；Order、Shipping、I18n、Currency、Seo 与 Theme 只贡献可选词典或预览集成，缺失时不得产生类加载错误。

## 代码面概览

入口文件：
- `app/code/Weline/Customer/composer.json`
- `app/code/Weline/Customer/etc/backend/menu.xml`

- `Api`：公开接口契约与对外能力发布面。 文件数：12
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：8
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Model`：ORM 模型与字段 schema。 文件数：3
- `Observer`：事件观察者与订阅逻辑。 文件数：1
- `Service`：业务编排与模块服务层。 文件数：5
- `Setup`：安装/升级装配。 文件数：1
- `etc`：模块配置。 文件数：3
- `extends`：扩展声明与挂载点。 文件数：1
- `i18n`：国际化资源。 文件数：2
- `view/statics`：浏览器静态资源源文件。 文件数：4
- `view/templates`：模块模板源文件。 文件数：8
- `view/theme`：主题资源贡献层。 文件数：1
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `view/theme`，说明模块向主题资源层贡献 layout/partial/component/widget；先读 Theme 模块文档。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

委托 API 需要按 ID 恢复客户 actor 时，只能解析
`Weline\Customer\Api\Auth\CustomerIdentityProviderInterface`；它返回 Framework
`AuthenticableInterface` 而不暴露 Customer Model，未安装 Customer 时调用方必须按可选能力处理。

跨模块需要读取当前客户、按 ID/邮箱映射、注册、更新头像或建立客户 Session 时，使用
`Weline\Customer\Api\Auth\CustomerAccountFacadeInterface`。返回值固定为 data-only
`CustomerIdentity`；密码只作为注册输入进入 Customer 内部，密码哈希、ORM、Session、
登录 IP、失败次数和登录事件不会越过模块边界。Facade 复用现有
`CustomerAccountService`，不另建事务或改变其异常类型。
客服会话等跨模块邮箱绑定只使用该 facade 的 `findByEmail()` 和返回的 `CustomerIdentity` ID；
会话与语言配置写入仍由调用模块所有，Customer ORM 行和 Query Builder 不得越过边界。

可选登录挑战（例如 2FA）拆分为两个 Customer 所有的公开契约：

- `CustomerLoginChallengeCreatorInterface` 仅接收 customer ID、站内回跳路径和 remember 时长，登录控制器与 Account QueryProvider 不得把 Customer Model 传给扩展模块。
- `CustomerLoginChallengeHandlerInterface` 只负责查询挑战过期时间和完成挑战；未安装扩展时仍由 null handler 保持可选能力。

扩展验证成功后，只能把 `CustomerIdentity` 传给
`CustomerAccountFacadeInterface::login()` 和 `issueRememberToken()`。remember token 的生成、旧记录删除、
新记录写入、过期时间与 `w_ut` Cookie 由 Customer 内部以原有顺序执行；扩展模块不得
引用 `CustomerToken`、`CustomerAccountService` 或 Customer ORM。`rememberDuration <= 0` 时必须保持无写入。

后台“前端客户”控制器只负责 Customer 路由、ACL、请求参数、响应状态与用户提示。对旧
`frontend_user` 账户的列表、详情、保存、删除、令牌重置和密码重置统一调用
`Weline\Frontend\Api\User\FrontendUserAdministrationInterface`；Customer 不得直接引用
Frontend 的 Model、Token Model、ORM 或内部 Service。Frontend 负责用户名判重、密码哈希、
Session/Token 清理及原有写操作顺序，Customer 只把公开结果状态映射为现有 JSON 消息，确保
模块边界收口不会改变控制器路由、参数、分页和状态语义。

账户页 `account.sidebar.content` Hook 的扩展模块不得读取 Customer Model、Session 或内部
`AccountSidebarContentGate`。跨模块只解析
`Weline\Customer\Api\View\AccountSidebarProjectionProviderInterface`；命中请求分区时得到
final readonly `AccountSidebarProjection`，其中只包含请求 section、合法的 website ID（包括系统
默认站点 `website_id=0`）和可空 customer ID。Provider 在 Customer 模块内完成 gate 与登录态
投影，不会把 ORM、密码、Session 或 Query Builder 暴露给 Hook 模板。

## 本模块文档资产

- `app/code/Weline/Customer/doc/hook/frontend/account/index/orders.md`
- `app/code/Weline/Customer/doc/hook/frontend/account/index/subscriptions.md`
- `app/code/Weline/Customer/doc/hook/frontend/account/login/providers.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
