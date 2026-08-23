# Weline_Index 模块文档

> 本 README 已从自动结构稿转为长期维护文档；模块结构变化与首页稳定契约必须在同次任务同步。

## 当前入口

开发前先完成 `prepare_project` 并调用 `resolve_task_context`，再读：

1. `app/code/Weline/Index/doc/需求.md`
2. `app/code/Weline/Index/doc/开发日志.md`
3. `app/code/Weline/Ai/doc/AI开发治理.md`

## 模块定位

- 模块代码：`Weline_Index`
- 目录：`app/code/Weline/Index`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。
- 首页 Sitemap Provider 位于 `extends/module/Weline_Seo`，Seo 是可选扩展目标；未安装 Seo 时 Index 仍须独立加载。

## 代码面概览

- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：2
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Model`：ORM 模型与字段 schema。 文件数：2
- `Setup`：安装/升级装配。 文件数：1
- `etc`：模块配置。 文件数：1
- `extends`：扩展声明与挂载点。 文件数：2
- `i18n`：国际化资源。 文件数：2
- `view/statics`：浏览器静态资源源文件。 文件数：6
- `view/templates`：模块模板源文件。 文件数：9
- `view/tpl`：模板编译/生成产物。 文件数：1

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- `view/templates/Index.phtml` 自己输出完整 `<!DOCTYPE html>/<head>`，必须在 favicon、SEO 与页面私有样式前挂载 `Weline_Theme::frontend::layouts::base::head-before`，并渲染 `Weline\Theme\Block\Partials(area=frontend,type=head,default-option=default)`。该标准 head partial 负责 Theme runtime config、`theme.js`、`Weline.Api` 与 Worker bootstrap；完整页面不得假设外层 layout 会代为注入。
- 首页私有 `--wf-*` 变量只允许作为 `--weline-theme-*` 语义 Token 的布局/品牌别名；不得在页面内维护 light/dark 色盘，也不得用 `@media (prefers-color-scheme: dark)` 绕过用户的显式主题偏好。`system|light|dark` 的解析与 Bootstrap/Weline 通用组件适配由 `Weline_Theme` 统一负责。
- 官方首页导航右侧挂载 `header-language-switcher` Hook（由 `Weline_I18n` 实现）。零号站（`website_id=0`）默认至少启用 `zh_Hans_CN` 与 `en_US`，语言列表按 `WebsiteLanguage` 收窄；`<html lang>` 跟随当前 `State::getLangLocal()`。
- `/`、`/en_US`、`/USD/en_US` 等「仅本地化前缀」路径与空路径同属前台首页根：`WlsRuntime` 会走 start-page 映射（若有），`Router\Core::isFrontendRootRequest()` 亦按剥本地化后剩余空路径判定；语言切换器切到非默认语言时不得 404。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 官网首页正文词条以 `Weline_Index/i18n` 为准；`en_US` 为中文 source → 英文译文。WLS 常驻运行时只读模块 CSV（不依赖 generated/language 总表）。`i18n:collect` 会保留 CSV 中已有但静态收集未扫到的词条，避免数组字面量再经 `__($var)` 输出的首页文案被冲掉。
- 首页所有面向开发者展示的默认访问地址必须复用当前请求已解析的 canonical Origin，保留 scheme、Host 与非默认端口；不得把 WLS 内部 loopback 或某个环境域名硬编码进模板。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `需求.md`：当前确认需求与稳定约束。
- `开发日志.md`：按目标版本记录门禁与验收证据。
- `README.md`：模块职责、开发入口与长期使用约定。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
