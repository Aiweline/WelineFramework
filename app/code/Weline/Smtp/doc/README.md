<!-- weline:module-readme:auto-generated -->
# Weline_Smtp 模块文档

> 本 README 由 `prepare_project 文档修复流程` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

可选：需要检索时，可调用项目 MCP `prepare_project` / `resolve_task_context`，按任务从本 README、`需求.md`、`开发日志.md` 和专题文档取上下文；**编码用宿主原生编辑**。

## 模块定位

- 模块代码：`Weline_Smtp`
- 目录：`app/code/Weline/Smtp`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 代码面概览

入口文件：
- `app/code/Weline/Smtp/composer.json`
- `app/code/Weline/Smtp/etc/backend/menu.xml`

- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：2
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：2
- `Helper`：模块内辅助能力。 文件数：2
- `Model`：ORM 模型与字段 schema。 文件数：1
- `etc`：模块配置。 文件数：2
- `extends`：扩展声明与挂载点。 文件数：1
- `i18n`：国际化资源。 文件数：2
- `view/templates`：模块模板源文件。 文件数：2
- `view/tpl`：模板编译/生成产物。 文件数：0

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 公共邮件发送边界

跨模块邮件只能依赖 `Weline\Smtp\Api\MailSenderInterface` 或 Query `smtp.send`。
有 `channel` 时以 `SmtpMailTemplate` 为模板权威（`vars` 渲染）；账户绑定仍走既有范围配置就近向上。
调用模块通过 Extends `MailChannelProviderInterface` 注册 `variables` / `default_templates`，不得硬拼 HTML 绕过模板。

## 邮件模板 / 渠道管理（1.4.x）

- 后台菜单「渠道管理」：`smtp/backend/template/listing`（仅 `target_scope`；展开渠道看各语言模板）
- 编辑往返：`q` / `locales` / `channel` / `focus_locale` 保持搜索、语言筛选、展开渠道与焦点行
- 一语言一套；listing 按渠道聚合当前 scope 下全部语言；发送 Resolver 就近向上
- 通知域：`Module::notify_*` 回退 `Weline_Backend::notification_email`
- **硬规则：邮件正文禁止 JavaScript**（无 `<script>` / 事件处理器 / `javascript:`）；仅 table + 内联样式
- **固定页头/页尾**：`Smtp/view/email/shell.phtml`（`<lang>` + Theme `brand_*`）；业务 `view/email/**` 只写正文；发信按 locale 组装
- **品牌发信架构（全渠道）**：From 显示名与 Subject 必须带站点品牌，见 [`邮件品牌发信架构.md`](%E9%82%AE%E4%BB%B6%E5%93%81%E7%89%8C%E5%8F%91%E4%BF%A1%E6%9E%B6%E6%9E%84.md)（`REQ-SMTP-0035`）
- **编辑工作区**：顶栏 CTA；变量分组；左编辑右 sticky 实时预览；**预览站址取 WebsiteDomain；Logo 上溯 Website appearance brand（`/pub/media/websites/...`，非 Theme 默认标）**
- 发信自动注入站店渠信任变量：`site_name` / `store_name` / `channel_name` / `site_logo_img` / `contact_*` / `brand_primary` 等（`MailBrandContextService`）

## 本模块文档资产

- 本模块长期知识由当前 `doc/` 文档维护；新增稳定行为、接口或配置约定时继续补到本目录，由 MCP 动态索引。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
