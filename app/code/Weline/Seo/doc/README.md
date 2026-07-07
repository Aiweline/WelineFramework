<!-- weline:module-readme:auto-generated -->
# Weline_Seo 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/Seo/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`
6. `app/code/Weline/Taglib/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_Seo`
- 目录：`app/code/Weline/Seo`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。

## 代码面概览

入口文件：
- `app/code/Weline/Seo/etc/module.xml`
- `app/code/Weline/Seo/etc/backend/menu.xml`

- `Block`：视图数据块与模板输出辅助层。 文件数：2
- `Console`：php bin/w 命令入口。 文件数：3
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：8
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：6
- `Interface`：已发布接口契约；跨模块依赖优先使用这里。 文件数：11
- `Model`：ORM 模型与字段 schema。 文件数：10
- `Observer`：事件观察者与订阅逻辑。 文件数：5
- `Service`：业务编排与模块服务层。 文件数：39
- `Setup`：安装/升级装配。 文件数：1
- `Taglib`：模板标签扩展。 文件数：3
- `etc`：模块配置。 文件数：4
- `i18n`：国际化资源。 文件数：2
- `view/blocks`：区块模板/片段视图。 文件数：1
- `view/statics`：浏览器静态资源源文件。 文件数：2
- `view/templates`：模块模板源文件。 文件数：12
- `view/tpl`：模板编译/生成产物。 文件数：2

## 开发关注点

- 存在 `Controller/`，说明模块有 HTTP 入口；控制器变更后记得同步路由升级和最接近的真实入口验证。
- 存在 `Controller/Backend`，后台页面/行为变更时应同时检查菜单、ACL、返回地址和用户提示。
- 存在 `Model/`，字段或索引变更需走模型 attribute + `setup:upgrade`，不要手改生成物。
- 存在 `Service/`，这里通常是模块业务编排层；跨模块协作优先通过已发布契约和 `w_query`。
- 存在 `Observer/`，改事件数据前应同步检查触发点和消费点。
- 存在模板源文件；出现页面问题时先追源码，不要直接改 `view/tpl`。
- 存在浏览器静态资源；业务请求必须走 `Weline.Api.*`，不要直接写 raw fetch/ajax。
- 存在 `i18n`，用户可见文案改动要同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 存在测试目录，但默认不要新增测试产物；只有用户明确要求时才进入测试修改。

## 本模块文档资产

- `app/code/Weline/Seo/doc/SEO结构化数据说明.md`
- `app/code/Weline/Seo/doc/SeoProfileExtensionGuide.md`
- `app/code/Weline/Seo/doc/Sitemap前端改进说明.md`
- `app/code/Weline/Seo/doc/Sitemap扩展开发指南.md`
- `app/code/Weline/Seo/doc/event/application/trend_sync_completed.md`
- `app/code/Weline/Seo/doc/event/domain/keywords_extracted.md`
- `app/code/Weline/Seo/doc/event/domain/subject_created.md`
- `app/code/Weline/Seo/doc/event/domain/subject_updated.md`
- `app/code/Weline/Seo/doc/event/domain/suggestion_generated.md`
- `app/code/Weline/Seo/doc/event/integration/feed_collect.md`
- `app/code/Weline/Seo/doc/event/integration/task_completed.md`
- `app/code/Weline/Seo/doc/event/integration/task_enqueued.md`
- `app/code/Weline/Seo/doc/event/integration/url_change_processed.md`
- `app/code/Weline/Seo/doc/event/integration/url_changed.md`
- `app/code/Weline/Seo/doc/event/integration/url_submit_request.md`
- `app/code/Weline/Seo/doc/hook/seo/body.md`
- `app/code/Weline/Seo/doc/hook/seo/footer.md`
- `app/code/Weline/Seo/doc/hook/seo/head.md`
- `app/code/Weline/Seo/doc/url-rewrite-cron-submit.md`
- `app/code/Weline/Seo/doc/事件系统设计文档.md`
- `app/code/Weline/Seo/doc/前端使用指南.md`
- `app/code/Weline/Seo/doc/平台绑定功能说明.md`
- `app/code/Weline/Seo/doc/开发/plan.md`
- `app/code/Weline/Seo/doc/开发/task.md`
- `app/code/Weline/Seo/doc/扩展规约说明.md`
- `app/code/Weline/Seo/doc/站点SEO配置说明.md`
- `app/code/Weline/Seo/doc/设计文档.md`
- `app/code/Weline/Seo/doc/账户与定时任务简要说明.md`
- `app/code/Weline/Seo/doc/队列化架构说明.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
