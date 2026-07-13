<!-- weline:module-readme:auto-generated -->
# Weline_Multipass 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/Multipass/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_Multipass`
- 目录：`app/code/Weline/Multipass`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。
- 身份桥接固定依赖 Backend、Customer、Framework、Frontend 与 SystemConfig；开发者应用自动审批读取 `Weline\SystemConfig\Api\ConfigReader`，因此 SystemConfig 是显式必需依赖。
- Multipass 的 PHP Controller/Service 只能通过
  `BackendAccountFacadeInterface`、`FrontendAccountFacadeInterface` 和
  `CustomerAccountFacadeInterface` 读取或登录账号；跨模块只接收 data-only Identity DTO，
  不引用 Backend/Frontend/Customer Model、Query Builder 或内部 Service。
- AES-128-CBC + HMAC-SHA256 Multipass token 的签发/校验仍由本模块
  `MultipassService` 完成；账户 facade 只在 token 校验成功后执行身份映射与 Session 写入，
  不接收或传播密码哈希。
- 旧 `frontend_user` 自动登录链仍受其未实现 Framework `AuthenticableInterface` 的历史
  限制；本轮只保持该路径原有行为并清除内部 API 耦合，不能把 token 加解密通过等同于
  旧前台 Session 登录已通过。
- 旧前台账户非空搜索仍会因 `frontend_user` 缺少 `email` 列而抛数据库异常；当前 facade
  保持相同异常类型与错误码。该 schema/身份体系缺口必须独立修复，不能用 fallback 或
  模糊字段降级掩盖。
- `account.sidebar.content` Hook 只解析 Customer 发布的
  `AccountSidebarProjectionProviderInterface`，通过 data-only projection 取得当前请求分区、
  customer ID 与 website ID；模板不得引用 Customer Model、Session 或内部 gate。系统默认站点
  `website_id=0` 保持有效，不会被当成缺失站点。

## 代码面概览

入口文件：
- `app/code/Weline/Multipass/etc/backend/menu.xml`

- `Api`：公开接口契约与对外能力发布面。 文件数：1
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：9
- `Controller/Api`：API 控制器入口；涉及外部接入时同步检查 API 契约与安全约束。 文件数：2
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：5
- `Model`：ORM 模型与字段 schema。 文件数：6
- `Service`：业务编排与模块服务层。 文件数：3
- `Setup`：安装/升级装配。 文件数：1
- `etc`：模块配置。 文件数：2
- `extends`：扩展声明与挂载点。 文件数：1
- `i18n`：国际化资源。 文件数：2
- `view/statics`：浏览器静态资源源文件。 文件数：1
- `view/templates`：模块模板源文件。 文件数：7
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Api`，说明模块可能对外暴露 API；补文档时应记录认证、参数和边界。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/Multipass/doc/identity-bridge-product-development-plan.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
