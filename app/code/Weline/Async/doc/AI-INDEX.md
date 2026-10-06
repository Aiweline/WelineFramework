<!-- weline:module-ai-index:auto-generated -->
# Weline_Async AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-docs.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`
3. 本文件：`app/code/Weline/Async/doc/AI-INDEX.md`
4. 模块说明：`app/code/Weline/Async/doc/README.md`
5. `app/code/Weline/Theme/doc/AI-INDEX.md`
6. `app/code/Weline/Frontend/doc/AI-INDEX.md`
7. `app/code/Weline/Taglib/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Async`
- 目录：`app/code/Weline/Async`
- Vendor：`Weline`
- Module：`Async`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Async/etc/backend/menu.xml`
- `app/code/Weline/Async/etc/module.xml`

- `Console`：php bin/w 命令入口。新增/变更命令后用真实 CLI 验证。 文件数：7
- `Controller`：HTTP/后台/前台控制器入口。新增控制器后优先跑完整 `setup:upgrade`；仅需重建路由图时可用 `setup:upgrade --route`（选填）。 文件数：5
- `Model`：ORM 数据模型与字段 schema。字段结构用 #[Col]/#[Index] 后执行 setup:upgrade。 文件数：2
- `Service`：模块内业务编排层。跨模块读取数据优先发布/使用 w_query。 文件数：3
- `Setup`：安装/升级装配。不要手改 generated，也不要在 Setup/Upgrade.php 做字段 CRUD。 文件数：1
- `etc`：模块配置。禁止 routes.xml；路由由控制器发现，完整 `setup:upgrade` 会同步；仅路由图变更时可用 `--route`（选填）。 文件数：5
- `view/templates`：模块模板源文件。可编辑源模板；不要改 view/tpl 编译产物。 文件数：7

## 从源码识别到的开发提示

- 存在 `view/templates`，说明有模块模板源文件；主题覆盖要走 Theme 路径解析规则。

## doc 目录

- `app/code/Weline/Async/doc/README.md`
- `app/code/Weline/Async/doc/phar使用指南.md`
- `app/code/Weline/Async/doc/windows-service.md`
- `app/code/Weline/Async/doc/功能现状.md`
- `app/code/Weline/Async/doc/开发日志.md`
- `app/code/Weline/Async/doc/需求.md`
- `app/code/Weline/Async/doc/项目配置文件使用指南.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Async> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
