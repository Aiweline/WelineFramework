<!-- weline:module-ai-index:auto-generated -->
# Weline_Ai AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-docs.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`
3. 本文件：`app/code/Weline/Ai/doc/AI-INDEX.md`
4. 模块说明：`app/code/Weline/Ai/doc/README.md`
5. `app/code/Weline/Theme/doc/AI-INDEX.md`
6. `app/code/Weline/Frontend/doc/AI-INDEX.md`
7. `app/code/Weline/Taglib/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Ai`
- 目录：`app/code/Weline/Ai`
- Vendor：`Weline`
- Module：`Ai`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Ai/composer.json`
- `app/code/Weline/Ai/etc/backend/menu.xml`

- `Api`：公开接口契约。跨模块调用优先找已发布 Interface 或 QueryProvider，不要直接依赖对方内部 Service/Model。 文件数：45
- `Block`：视图数据块。配合模板输出页面数据，变更前要读对应模板和 layout。 文件数：1
- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：11
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`（选填）。 文件数：16
- `Helper`：模块内辅助能力。跨模块不要直接调用未发布 Helper。 文件数：2
- `Interface`：模块发布的接口契约。跨模块依赖优先使用这里的稳定契约。 文件数：8
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：19
- `Observer`：事件观察者。改事件数据前要检查 doc/event 和触发方。 文件数：3
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：74
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：8
- `Taglib`：模板标签扩展。改前读 Weline_Taglib 与 Theme 文档。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`（选填）。 文件数：26
- `extends`：模块扩展声明。优先使用 extends/module/{Module}/... 的当前约定。 文件数：2
- `i18n`：国际化资源。用户可见文案使用中文 source/key，en_US/zh_Hans_CN 对齐。 文件数：2
- `view/statics`：静态资源源文件。浏览器业务请求必须走 Weline.Api.*。 文件数：4
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：27

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。
- 存在 `extends/module`，优先使用当前扩展约定，不要回退到旧式随意扩展路径。
- 存在 `i18n`，新增用户可见文案时同步 `zh_Hans_CN.csv` 与 `en_US.csv`。
- 识别到 QueryProvider 入口：`extends/module/Weline_Framework/Query/AiProviderAccountQueryProvider.php`、`extends/module/Weline_Framework/Query/AiQueryProvider.php`；前端/跨模块读数据先查 `php bin/w query:help`。

## doc 目录

- `app/code/Weline/Ai/doc/AI工程交付流程.md`
- `app/code/Weline/Ai/doc/AI开发治理.md`
- `app/code/Weline/Ai/doc/AI硬规则索引.md`
- `app/code/Weline/Ai/doc/AOCI自动安装.md`
- `app/code/Weline/Ai/doc/API/API.md`
- `app/code/Weline/Ai/doc/MCP索引与响应性能.md`
- `app/code/Weline/Ai/doc/README.md`
- `app/code/Weline/Ai/doc/event/AI监控告警.md`
- `app/code/Weline/Ai/doc/event/AI翻译调用.md`
- `app/code/Weline/Ai/doc/功能现状.md`
- `app/code/Weline/Ai/doc/开发/AI模块开发文档.md`
- `app/code/Weline/Ai/doc/开发/API文档.md`
- `app/code/Weline/Ai/doc/开发/Playwright测试指南.md`
- `app/code/Weline/Ai/doc/开发/README.md`
- `app/code/Weline/Ai/doc/开发/session/requirement-session-dashboard.md`
- `app/code/Weline/Ai/doc/开发/session/sitewide-translation-audit.md`
- `app/code/Weline/Ai/doc/开发/team/acceptance-real-business-pathway/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/active-locale-target-language/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/api-seat-charter/channel/api-charter.md`
- `app/code/Weline/Ai/doc/开发/team/api-seat-charter/meetings/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/api-seat-charter/roster.md`
- `app/code/Weline/Ai/doc/开发/team/browser-strip-automation-flags/meetings/提示词优化-design.md`
- `app/code/Weline/Ai/doc/开发/team/browser-strip-automation-flags/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-fit-review/channel/fit-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-fit-review/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-fit-review/roster.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/buyer-show-ops-review-pointer.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/crossborder-returns-apply-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/crossborder-returns-wholesale-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-asset-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-asset-02-core-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-cart-checkout-reachability-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-chk-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-chk-browser.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-chk-payment-note.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-chk-perf-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-fe-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-home-zero-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-minicart-chrome-zero-purge-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-ops-browser-col-pdp.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-build-theme-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-defect-mall07-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/hanfu-defect-payment-hydrate-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-broken-layout-hotfix-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-broken-layout-ops-escalation.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-continuous-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-hotfix-theme-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-mall-feel-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-advisor-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-backend-02-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-theme-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave1-widget-03-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-advisor-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-p1-04-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-p2-05-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-p2-06-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-p2-07-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave2-p2-08-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-01-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-02-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-03-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-04-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-05-done.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-advisor-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/homepage-wave3-mall-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/ops-acceptance-charter-hanfu-mall.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-hanfu-defect-fix.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-hanfu-mall-build.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-homepage-p1.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-homepage-wave2.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-homepage-wave3-mall.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-sitewide-ops-acceptance.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-arrange-zero-sitewide.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-hanfu-mall-build-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-hotfix-broken-home-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-hotfix-homepage-broken-layout.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-hotfix-homepage-broken-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-sitewide-ops-acceptance-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-wave1-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-wave2-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/pm-wave3-mall-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/sitewide-ops-acceptance-report.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/sitewide-ops-acceptance-rereview.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/social-gsc-platform-register-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/social-gsc-platform-register-progress.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/social-hanfu-video-firstwave-ops-brief.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/channel/soft-tone-returns.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/提示词优化-design.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审-hanfu-mall-build.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审-hotfix-broken-home.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审-wave1.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审-wave2.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审-wave3-mall.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/ecommerce-advisor-ops-planner/roster.md`
- `app/code/Weline/Ai/doc/开发/team/engineering-team-flow-v1/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/engineering-team-roster-v2/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/engineering-team-roster-v2/roster.md`
- `app/code/Weline/Ai/doc/开发/team/one-seat-one-agent-peer-talk/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/prompt-i18n-compliance-gap/meetings/提示词优化-design.md`
- `app/code/Weline/Ai/doc/开发/team/prompt-i18n-compliance-gap/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/prompt-i18n-compliance-gap/roster.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/channel/pm-arrange-p3.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/channel/wave-chrome-en.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/channel/wave-p3-dict.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/meetings/shipping-method-label-i18n-20260922.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/meetings/翻译-review.md`
- `app/code/Weline/Ai/doc/开发/team/sitewide-translation-audit/roster.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/channel/closeout-related-web-urls.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/contracts.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/deps.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/meetings/提示词优化-design.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/team-closeout-related-web-urls/roster.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/channel/perf-to-architect.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/meetings/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/meetings/性能检查-design.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/meetings/路径memo-施工.md`
- `app/code/Weline/Ai/doc/开发/team/theme-arch-perf-review/roster.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/channel/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/channel/review-completeness.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/channel/席位底线补钉.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/席位底线补钉.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/技能完整度汇审.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/提示词优化-review-席位底线.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/汇审-席位底线补钉.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/meetings/缺口自查.md`
- `app/code/Weline/Ai/doc/开发/team/theme-engineer-charter/roster.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/channel/align.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/meetings/提示词优化-design.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/meetings/翻译-review.md`
- `app/code/Weline/Ai/doc/开发/team/translation-engineer-charter/roster.md`
- `app/code/Weline/Ai/doc/开发/team/ui-prototype-gate-before-test/channel/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/ui-prototype-gate-before-test/meetings/align-freeze.md`
- `app/code/Weline/Ai/doc/开发/team/ui-prototype-gate-before-test/meetings/提示词优化-review.md`
- `app/code/Weline/Ai/doc/开发/team/ui-prototype-gate-before-test/meetings/汇审.md`
- `app/code/Weline/Ai/doc/开发/team/ui-prototype-gate-before-test/roster.md`
- `app/code/Weline/Ai/doc/开发/单元测试指南.md`
- `app/code/Weline/Ai/doc/开发/后台控制器开发规范.md`
- `app/code/Weline/Ai/doc/开发/后台智能体管理.md`
- `app/code/Weline/Ai/doc/开发/后台风格管理CRUD.md`
- `app/code/Weline/Ai/doc/开发/快速开始.md`
- `app/code/Weline/Ai/doc/开发日志.md`
- `app/code/Weline/Ai/doc/文档索引.md`
- `app/code/Weline/Ai/doc/智能体(Agent)增强计划.md`
- `app/code/Weline/Ai/doc/模块文档总览.md`
- `app/code/Weline/Ai/doc/用户/AI模块使用手册.md`
- `app/code/Weline/Ai/doc/统一知识库与MCP协议.md`
- `app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md`
- `app/code/Weline/Ai/doc/规则/10-工程流程与验收.md`
- `app/code/Weline/Ai/doc/规则/20-前端与主题.md`
- `app/code/Weline/Ai/doc/规则/30-后端与数据.md`
- `app/code/Weline/Ai/doc/规则/40-集成与运维.md`
- `app/code/Weline/Ai/doc/规则/50-内容运营.md`
- `app/code/Weline/Ai/doc/需求.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Ai> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
