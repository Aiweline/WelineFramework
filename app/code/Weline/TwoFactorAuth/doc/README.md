<!-- weline:module-readme:auto-generated -->
# Weline_TwoFactorAuth 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/TwoFactorAuth/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Customer/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_TwoFactorAuth`
- 目录：`app/code/Weline/TwoFactorAuth`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 依赖契约

- 必需依赖仅为 `Weline_Framework`、`Weline_Customer`；TwoFactorAuth 不再引用 Frontend 的 Model、Service 或其他内部类。
- `Controller/Api/CheckLogin` 只消费 Framework `AuthenticatedSessionInterface`。当前用户 ID/用户名由 `AuthenticableInterface` 投影，邮箱仅在身份对象公开 `getEmail()` 时返回，不固定任何具体账户 Model。已登录返回 HTTP 200 和 `data.logged_in=true`；未登录返回 HTTP 401、`data.logged_in=false` 及原登录地址。
- Customer 密码校验后通过 `CustomerLoginChallengeCreatorInterface` 传入标量 customer ID、站内回跳路径和 remember 时长；Customer ORM 对象不会跨越模块边界。
- 挑战完成时仅使用 `CustomerAccountFacadeInterface` 查找 data-only `CustomerIdentity`、建立登录态并委托 remember token。CustomerToken ORM、旧 token 清理、新 token 持久化及 `w_ut` Cookie 全部由 Customer 模块所有。
- 固定时序为：挑战校验 → TOTP/备份码校验 → 客户存在性 → 登录 → remember token → 移除挑战。

## 代码面概览

入口文件：
- `app/code/Weline/TwoFactorAuth/composer.json`
- `app/code/Weline/TwoFactorAuth/.module_config.json`
- `app/code/Weline/TwoFactorAuth/etc/backend/menu.xml`

- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：12
- `Controller/Api`：API 控制器入口；涉及外部接入时同步检查 API 契约与安全约束。 文件数：4
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：4
- `Helper`：模块内辅助能力。 文件数：2
- `Model`：ORM 模型与字段 schema。 文件数：3
- `Observer`：事件观察者与订阅逻辑。 文件数：2
- `Service`：业务编排与模块服务层。 文件数：2
- `Setup`：安装/升级装配。 文件数：1
- `etc`：模块配置。 文件数：3
- `extends`：扩展声明与挂载点。 文件数：1
- `i18n`：国际化资源。 文件数：2
- `view/statics`：浏览器静态资源源文件。 文件数：10
- `view/templates`：模块模板源文件。 文件数：9
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Api`，说明模块可能对外暴露 API；补文档时应记录认证、参数和边界。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/TwoFactorAuth/doc/event/2FA验证前.md`
- `app/code/Weline/TwoFactorAuth/doc/event/2FA验证失败.md`
- `app/code/Weline/TwoFactorAuth/doc/event/2FA验证成功.md`
- `app/code/Weline/TwoFactorAuth/doc/event/强制2FA设置.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
