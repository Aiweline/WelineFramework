<!-- weline:module-readme:auto-generated -->
# Weline_Visitor 模块文档

> 本 README 由 `dev/ai/scripts/generate-missing-module-readmes.php` 根据当前代码结构自动生成。它提供模块级结构说明和开发入口，不替代后续人工补充的业务规则、接口契约和专项设计文档。

## 当前入口

开发前先读：

1. `app/code/Weline/Visitor/doc/AI-INDEX.md`
2. `dev/ai/diagrams/08-module-docs-index.txt`
3. `dev/ai/global-constraints.md`
4. `app/code/Weline/Theme/doc/AI-INDEX.md`
5. `app/code/Weline/Frontend/doc/AI-INDEX.md`
6. `app/code/Weline/Taglib/doc/AI-INDEX.md`

## 模块定位

- 模块代码：`Weline_Visitor`
- 目录：`app/code/Weline/Visitor`
- 当前状态：结构化模块概览已补齐；稳定业务规则仍应继续沉淀到本模块 `doc/`。
- Backend 提供菜单与 ACL 父资源，是必需依赖；Frontend 登录/注册事件、Dashboard 页面发布和 Server WLS 热缓冲均为可选集成，缺失时保持基础访客统计可加载。

## 代码面概览

入口文件：
- `app/code/Weline/Visitor/composer.json`
- `app/code/Weline/Visitor/etc/backend/menu.xml`

- `Api`：公开接口契约与对外能力发布面。 文件数：5
- `Controller`：前后台 HTTP 控制器与路由入口。 文件数：5
- `Controller/Backend`：后台控制器入口；变更前同步检查 ACL、菜单和返回路径。 文件数：1
- `Model`：ORM 模型与字段 schema。 文件数：5
- `Observer`：事件观察者与订阅逻辑。 文件数：4
- `Service`：业务编排与模块服务层。 文件数：10
- `Setup`：安装/升级装配。 文件数：2
- `Taglib`：模板标签扩展。 文件数：1
- `etc`：模块配置。 文件数：3
- `extends`：扩展声明与挂载点。 文件数：3
- `i18n`：国际化资源。 文件数：2
- `view/statics`：浏览器静态资源源文件。 文件数：2
- `view/templates`：模块模板源文件。 文件数：10
- `view/tpl`：模板编译/生成产物。 文件数：5

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

- `app/code/Weline/Visitor/doc/event/访客像素标签.md`
- `app/code/Weline/Visitor/doc/像素拓展使用指南.md`
- `app/code/Weline/Visitor/doc/功能完善总结-数据分析.md`
- `app/code/Weline/Visitor/doc/功能完善总结.md`
- `app/code/Weline/Visitor/doc/功能校验最终报告.md`
- `app/code/Weline/Visitor/doc/功能检验完整报告.md`
- `app/code/Weline/Visitor/doc/功能检验总结.md`
- `app/code/Weline/Visitor/doc/功能检验报告-详细.md`
- `app/code/Weline/Visitor/doc/功能检验报告.md`
- `app/code/Weline/Visitor/doc/功能检验最终报告.md`
- `app/code/Weline/Visitor/doc/升级故障排除-traffic_split.md`
- `app/code/Weline/Visitor/doc/多站点事件监听看板.md`
- `app/code/Weline/Visitor/doc/数据分析功能使用指南.md`
- `app/code/Weline/Visitor/doc/站点ID功能完善说明.md`

## 维护规则

- 不直接修改 `generated/`、`view/tpl/`、`routes.xml`。
- Framework 是直接必需依赖；像素热缓冲只依赖 Framework 的
  `SharedBufferStateFactoryInterface`。Server 是可选 Provider，缺失或不可用时像素事件必须回落到数据库持久化。
- 共享态创建或健康检查失败后使用 2 秒进程内冷却，期间直接回落数据库；成功重连会清除冷却，禁止每请求重建客户端或永久熔断。
- 涉及浏览器业务请求时，只使用 `Weline.Api.*` / QueryProvider 链路。
- 涉及字段结构时，用 `#[Col]` / `#[Index]` 和 `php bin/w setup:upgrade`。
- 涉及控制器路由时，用 `php bin/w setup:upgrade --route`。
- 本 README 目前是结构稿；后续功能稳定后，应继续补模块职责、关键流程、接口与反例。
