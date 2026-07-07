# Module Doc Audit - 2026-07-06

## 背景

用户指出：技能只能给大方向，无法让 AI 清楚理解模块内开发约束。尤其是主题继承、文件式约定、模板覆盖、`view/theme` 与 `view/tpl` 边界等内容，如果没有模块本地文档入口，AI 容易按通用经验开发。

本次审查目标：

- 每个模块先有可命中的 `doc/AI-INDEX.md`。
- 旧文档入口从“读 README”升级为“先读 AI-INDEX，再读 README/专项文档”。
- 把主题继承和文件落点从源码实现抽成结构化文档。
- 把 skills 和 Codex 插件路由衔接到模块 doc。

## 审查结论

当前 `app/code` 下识别到 91 个模块目录。

- 变更前：没有 `doc/AI-INDEX.md`。
- 变更后：91 个模块都有 `doc/AI-INDEX.md`。
- 变更前：模块根目录 `doc/README.md` 只有 37 个。
- 变更后：91 个模块都有模块根目录 `doc/README.md`。
- 其中当前手写/人工维护 README：40 个。
- 当前仍为结构化生成 README：51 个。

`AI-INDEX.md` 是 AI 开发入口，记录模块身份、代码面、现有 doc 清单、源码识别提示和开发前门禁。当前剩余 51 个生成 README 仍只是结构化模块说明，不等于已经完成深度业务文档；后续发生稳定行为/接口/约定变更时，仍应继续补模块本地专项文档。

## 已补齐的直接缺文档约定

新增 `app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`，把以下源码约定文档化：

- `app/design/{Vendor}/{theme}/{frontend|backend}` 是当前设计主题主路径。
- `theme/{area}` 与 `view/theme/{area}` 是兼容结构，不是首选新写法。
- 默认主题源位于 `app/code/Weline/Theme/view/theme/{area}`。
- 模块贡献主题资源位于 `app/code/{Vendor}/{Module}/view/theme/{area}`。
- 普通模块模板源位于 `app/code/{Vendor}/{Module}/view/templates/{area}`。
- 普通模块模板的主题覆盖优先使用 `app/design/{Vendor}/{theme}/{Module_Code}/templates/{area}`。
- `view/tpl` 是编译/生成产物，禁止直接修改。
- 同一主题资源 logical key 的优先级是当前主题、父主题、默认 `Weline_Theme`、其他模块贡献层。
- `layouts`、`partials`、`components`、`widgets`、`variables`、`colors` 的文件式 logical key 规则。

这些约定之前分散在 `ThemeDirectoryResolver`、`ThemePathResolver`、`LayoutPathResolver`、`ThemeResourceCatalog`、`TemplateFetchFile` 等源码中，没有形成 AI 可执行的决策表。

## 已纠正的不完整/旧入口

- `dev/ai/diagrams/08-module-docs-index.txt`
  从旧的“57 modules / README first”改为“91 modules / AI-INDEX first”协议。

- `dev/ai/diagrams/00-INDEX.txt`
  同步为模块工作先读 `doc/AI-INDEX.md`。

- `AI-ENTRY.md`
  Reading Order 改为模块任务先读 `08-module-docs-index.txt` 和 owning module `doc/AI-INDEX.md`。

- `dev/ai/AI-RULES-PACK.md`
  Must Load 与 Authority Map 改为模块 `doc/AI-INDEX.md`。

- `dev/ai/global-constraints.md`
  加强“先理解再修改”里的模块 AI 入口要求。

- `dev/ai/skills/_index.md`
  增加任意 `app/code/{Vendor}/{Module}` 任务必须先读模块 `doc/AI-INDEX.md` 的组合触发规则。

- Theme / Frontend / Taglib / Widget README
  入口补上对应 `AI-INDEX.md` 和 Theme 继承/文件约定文档。

- `dev/ai/codex/plugins/weline-codex-plugin/skills/*`
  同步高风险技能和插件路由，避免 Codex 插件加载到旧技能副本。

## 本轮继续深补

本轮继续把 4 个高频约束模块从“结构稿/泛文档”升级为人工维护文档：

- `Weline_Eav`
  重写模块 README，并新增 `eav-entity-and-attribute-conventions.md`，把实体注册、`attribute_id`/`eav_entity_id` 语义、值表命名、SchemaRegistry 入口、过滤/搜索元数据边界抽出来。

- `Weline_Api`
  把模块 README 从结构稿升级为人工维护版，并新增 `framework-api-and-auth-contract.md`，明确浏览器业务 API、REST、`#[Acl]` 公开性判定、统一鉴权门和白名单/UA 限制能力。

- `Weline_Websites`
  把模块 README 从结构稿升级为人工维护版，并新增 `default-website-and-request-detection.md`，明确 `website_id=0/code=default`、请求命中、`WebsiteData`、`w_query('websites', ...)` 边界。

- `Weline_Backend`
  把模块 README 从结构稿升级为人工维护版，并新增 `menu-acl-and-backend-entry-conventions.md`，明确 `etc/backend/menu.xml` 唯一来源、菜单收集、`parent_source` 闭合约定、后台 `#[Acl]` 与页面入口协同。

## 现在仍需要深补的模块

README 层已经补齐，但目前分成两种状态：

- `manual`：模块 README 是手写或原有人工维护内容。
- `generated`：模块 README 是本轮按代码结构自动补出的结构稿。

真正还要继续深补的是 `generated` 这批模块，尤其分两类：

- `structural_plus_docs`
  说明：已经有不少专题文档或计划文档，但缺一个真正人工整理过的模块 README。
  典型模块：
  - `Weline_Captcha`
  - `Weline_Component`
  - `Weline_Cron`
  - `Weline_Currency`
  - `Weline_Customer`
  - `Weline_DataTable`
  - `Weline_Database`
  - `Weline_Layout`
  - `Weline_Marketing`
  - `Weline_Meta`
  - `Weline_Seo`
  - `Weline_Visitor`

- `structural_only`
  说明：目前只有 `AI-INDEX.md` 和本轮结构稿 README，几乎没有其他模块文档。
  典型模块：
  - `WeShop_Analytics`
  - `WeShop_Cart`
  - `WeShop_Catalog`
  - `WeShop_Customer`
  - `WeShop_Filters`
  - `WeShop_Product`
  - `WeShop_Search`
  - `Weline_Base`
  - `Weline_Benchmark`
  - `Weline_Code`
  - `Weline_Dashboard`
  - `Weline_Extends`
  - `Weline_Hook`
  - `Weline_Index`
  - `Weline_Indexer`

完整矩阵见：

- `dev/ai/docs/module-doc-audit-matrix.md`

当前矩阵摘要：

- `91` / `91` 模块具备 `AI-INDEX.md`
- `40` 个模块 README 已是人工维护
- `51` 个模块 README 仍是结构生成稿
- `29` 个模块仍处于 `structural_only`
- `22` 个模块仍处于 `structural_plus_docs`

## 后续维护规则

新增/删除模块、模块目录结构明显变化、模块 doc 大幅变化后运行：

```bash
php dev/ai/scripts/generate-module-ai-indexes.php
php dev/ai/scripts/generate-missing-module-readmes.php
php dev/ai/scripts/generate-module-doc-audit-report.php
```

先预览：

```bash
php dev/ai/scripts/generate-module-ai-indexes.php --dry-run
php dev/ai/scripts/generate-missing-module-readmes.php --dry-run
php dev/ai/scripts/generate-module-doc-audit-report.php --dry-run
```

如果某模块需要手写 AI 入口，删除该文件里的自动生成 marker：

```text
<!-- weline:module-ai-index:auto-generated -->
```

生成器会跳过手写入口，除非显式传 `--force`。
