<!-- weline:module-ai-index:auto-generated -->
# Weline_Base AI 开发入口

> 本文件由 `dev/ai/scripts/generate-module-docs.php` 根据当前代码结构生成。它是 AI 进入模块前的导航入口；细节仍以本模块 `doc/`、实际源码和全局规则为准。

## 必读顺序

1. `AI-ENTRY.md`
2. 全局硬规则与任务路由：`app/code/Weline/Ai/doc/AI硬规则索引.md`
3. 本文件：`app/code/Weline/Base/doc/AI-INDEX.md`
4. 模块说明：`app/code/Weline/Base/doc/README.md`
5. `app/code/Weline/Theme/doc/AI-INDEX.md`
6. `app/code/Weline/Frontend/doc/AI-INDEX.md`
7. `app/code/Weline/Taglib/doc/AI-INDEX.md`
8. 只读取本次任务相关源码、配置和验证入口

## 模块身份

- 模块代码：`Weline_Base`
- 目录：`app/code/Weline/Base`
- Vendor：`Weline`
- Module：`Base`

## 代码面清单

入口/配置文件：
- `app/code/Weline/Base/composer.json`


## 从源码识别到的开发提示

- 存在 `view/tpl`，这是编译/生成产物面，禁止直接修改。

## doc 目录

- `app/code/Weline/Base/doc/README.md`
- `app/code/Weline/Base/doc/功能现状.md`
- `app/code/Weline/Base/doc/开发日志.md`
- `app/code/Weline/Base/doc/需求.md`

## 开发前门禁

- 先声明本次任务命中的模块、代码面和应读文档；没有命中文档时先补读源码，不要按通用经验猜。
- 涉及浏览器前后端业务请求时，只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 涉及跨模块读数据时，先查 `php bin/w query:help <provider|Weline_Base> [operation]` 或对应 `w_query` 帮助。
- 涉及模板、主题、slot、widget、taglib 或 `view/theme` 时，必须先读 `app/code/Weline/Theme/doc/AI-INDEX.md`。
- 禁止直接修改 `generated/`、`view/tpl/`、`routes.xml` 或复制旧文档里的过时路径。
- 如果本文件与源码冲突，以源码为准，并在同次任务中修正模块文档。
