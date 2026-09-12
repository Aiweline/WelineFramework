# AI 硬规则索引

> 任何 Weline **开发（编码/工程）**任务的**第一层路由**。非编码任务（闲聊、概念问答、与本仓实现无关说明）**禁止**调用 MCP。编码/工程任务在 `prepare_project` ready 后必须先遵守 MCP 下发的 `hard-constraints.v1`，再按本表定位必读文档。规范正文以链接文件为准；MCP `workflow_contract.v1` 为机器可读摘要。

## MCP 调用范围（`mcp_call_scope`）

| 类别 | 示例 | 是否调用 MCP |
|------|------|--------------|
| 非编码 | 闲聊、身份/概念问答、纯口头建议、与本仓改码无关 | **禁止**（含 ensure / `prepare_project` / `submit_task_plan`） |
| 打招呼 `hi`/`你好`/`hello`（无编码任务）或指令「提取技能」 | 可 `prepare_project` / `resolve_skill(list_all)` **仅列** MCP 技能+指令；禁止密封编辑 / `submit_task_plan` |
| 编码/工程 | 改代码或模块文档、诊断/评审、部署规划、项目知识检索、功能验收收口 | **必须** ensure → `prepare_project` → 既有工作流 |

宿主 `AGENTS.md` 只作指针；细则以本表与 `HardConstraintsCatalog::mcpOperationalRules()` 为准。

## 运行/状态查询默认本机（`runtime_status_query_local_first`，强制）

| 默认 | 禁止 |
|------|------|
| cron / 队列 / AI·i18n 定时翻译进度 / 日志 / DB 计数 /「还在跑吗」等**运行状态查询默认查本机**工作区库与进程 | 用户未明示时 SSH/查生产或预发；把历史会话里的「线上」当成默认 |
| 仅当用户明示「线上 / 生产 / ssh weline / aiweline.com / 预发」才查对应远端 | 把 SSH MCP **默认 profile=`weline`** 误读成「默认查生产」；发明「翻译相关必须查线上」特例 |

权威：`HardConstraintsCatalog::mcpOperationalRules()` → `runtime_status_query_local_first`。SSH 主机映射只解决「要连生产时连哪台」，不改变查询目标默认值。

## 宿主编辑器规则（`host_editor_rules_mcp_generated_only`，强制）

| 允许 | 禁止 |
|------|------|
| 规则权威维护在 MCP `hard-constraints.v1` 与 `Ai/Framework/模块 doc/` | Agent **手写/直接编辑** `.cursor/rules/*.mdc`、`.cursorrules`、`CLAUDE.md`、`.codex/*`、`.github/copilot-instructions.md` 等作为规则源 |
| 由 **MCP**（ensure/install/guidance 生成器）在有专用生成路径时写出宿主编辑器规则产物 | 把编辑器私有规则文件当成高于 `prepare_project.hard_constraints` 的权威 |
| `AGENTS.md` 仅作 MCP 接通指针 | 换项目后仍依赖本机/他仓残留的 Cursor/Codex 私有规则 |

原因：换项目后编辑器私有规则会丢失或分叉；只有 MCP + 仓库文档可随项目带走。

## MCP 技能（`mcp_skills_fetch_from_mcp`，强制）

| 允许 | 禁止 |
|------|------|
| 工程/产品技能正文由 MCP `mcp-skills.v1` 提供：`prepare_project.agent_guidance.mcp_skills` → `resolve_skill` → `get_skill` | 把 Cursor/Codex 本地 `SKILL.md` 当作高于 MCP 的权威技能源 |
| 宿主 Agent Skills 仅作**可选薄壳**（提醒去调 MCP） | 恢复 `knowledge.auto_generate_skills` / 仓库内 Skill 投影 |
| 任务文档片段继续用 `resolve_task_context` | 用静态技能文件替代 workflow surfaces / 硬约束 |

常用别名（`get_skill(skill_id=…)`）：`weline-theme-development`、`local-browser-urls`、`weline-taglib-first`（映射到对应 surface id）。

模块 doc 技能：`doc/ai/INDEX.json` + `doc/ai/skills/*/SKILL.md` 只读提取进 MCP；无 file skill 时用 `doc-index:{module}`（来自 `AI-INDEX.md`）。全量列表：`resolve_skill(list_all=true)` 或指令「提取技能」。打招呼须列技能+指令（`greeting_lists_mcp_skills_and_commands`）。

## MCP 编译（权威落点）

| 产物 | 位置 | 职责 |
|------|------|------|
| `hard-constraints.v1` | `prepare_project.agent_guidance.hard_constraints` + MCP `instructions` preamble | 全局硬约束（由本索引与交付流程编译） |
| `mcp-skills.v1` | `agent_guidance.mcp_skills` + `resolve_skill` / `get_skill` | 按任务可拉取的工程技能正文 |
| `session_startup_notices` | 同上 `agent_guidance` | **只指路**，不复制本表细则 |
| `workflow_contract.v1` surfaces | `resolve_task_context` | 按任务下发 Taglib/Theme/Hook 等细则 |
| 宿主 `AGENTS.md` / ensure | 仓库根 / 脚本 | 只负责接通 MCP，不写框架法 |

实现类：`app/code/Weline/Ai/Mcp/src/HardConstraintsCatalog.php`、`McpSkillCatalog.php`。改本索引或硬约束摘要后，须跑 `php app/code/Weline/Ai/Mcp/tests/guidance-workflow-contract.php` 与 `mcp-skills-catalog.php`。

## 使用方式

1. 从任务描述提取**关键词**（下表第一列）。
2. 阅读对应**必须先读**文档（不得跳过）。
3. 遵守**禁止**列；完成后跑**验证**命令（如有）。
4. 写代码前完成 [扩展点选型](../Framework/doc/3-开发/扩展点选型.md)。

## 工作区不可丢弃规则（`preserve_dirty_workspace`，严重）

MCP 的自愈、宿主重载、插件代次刷新、密封编辑、验证回滚和崩溃恢复必须保留任务开始前已经存在的 tracked、staged、untracked 与 ignored 脏改。

**宿主 Agent / Shell 同等禁止（硬）**：不得为「方便 MCP 密封 / get_edit_bundle 对齐 HEAD / 重做 apply」而对工作区执行 `git checkout -- <path>`、`git restore`、`git reset`、`git clean`、`git stash` 或任何等价擦脏。密封编辑必须**脏改加载**：以当前磁盘文件的精确哈希做 `expected_file_sha256`，在脏改上合并 apply；Hash 漂移只能 fail-closed 保留现场，禁止拿 HEAD、索引、旧 journal 快照或「先恢复再改」覆盖当前文件。丢代码风险优先于密封便利。

MCP 子进程只允许只读 Git 检查，禁止上述全部 Git 写操作，禁止 config/helper/pager 命令注入及 force/discard 变体。分支切换只能由工作区所有者显式执行。

## 任务路由表

| 任务关键词 | 必须先读 | 禁止 | 验证 |
|-----------|---------|------|------|
| 规格修复、技术细节补全、listing 规格缺失、PDP 只有尺码/类型 | [dev/ai-command/product/规格修复.md](../../../../../dev/ai-command/product/规格修复.md)；`Product/scripts/remediate-product-listing-spec-attrs.php` | 只口头解释不扫库；无快照伪造属性；改 combination_key/轴矩阵冒充补全；**修完不报明细、不给每品交付地址** | `php app/code/Weline/Product/scripts/remediate-product-listing-spec-attrs.php --scan --dry-run` → `--apply`；汇报含修复表 + 每品 Markdown URL；Browser PDP 技术细节 |
| **壳+Provider、万能支付、货源代发、对接供应商/支付方式、业务写在 Controller** | [Payment/payment-shell.md](../Payment/doc/payment-shell.md)、[Payment/provider-development.md](../Payment/doc/provider-development.md)、[Dropship/dropship-shell.md](../Dropship/doc/dropship-shell.md)、[Dropship/provider-development.md](../Dropship/doc/provider-development.md)；MCP `shell_provider_business_isomorph`（**强制**） | 在壳 Controller/Service **重写**某供应商 API/凭证解析/履约/目录/支付生命周期；为每个供应商新写一套业务控制器；绕过 Provider 接口硬编码网关 | Extends Provider 一文件按能力接口实现；壳只编排；对接交付=Provider+配置模板 |
| `.phtml`、模板、Taglib、`<w:` | [Taglib/doc/README.md](../Taglib/doc/README.md)、[场景映射表.md](../Taglib/doc/场景映射表.md)、[如何自定义Tag.md](../Taglib/doc/如何自定义Tag.md) | 手写领域 select/input；`w:*` 属性内 `<?=` / `<?php`；**Taglib callback 返回 HTML 里写裸 `@static(...)`** | — |
| **配置、统一配置、统一配置中心、系统配置、嵌入配置、配置嵌入、`<w:config:*>`、SystemConfig、Weline_SystemConfig** | [SystemConfig README](../SystemConfig/doc/README.md)、[config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；MCP `systemconfig_unified_config_terms` / `SystemConfigTermRouting` | 自造业务配置表/私有 Config Service/平行设置页；把「配置」落到 MCP host/`env.php`/模块 `etc` 语义而忽略统一配置中心 | 业务配置走 SystemConfig；业务页用 `<w:config:embed>`；检索词表命中 SystemConfig 文档 |
| **范围、Scope、配置范围、继承、网站/店铺/渠道、target_scope、`<w:scope>`、路径过滤** | [store-saleschannel-scope.md](../Websites/doc/store-saleschannel-scope.md)；[SystemConfig README 继承](../SystemConfig/doc/README.md)；MCP `weline_business_scope_hierarchy`；后台范例 Shipping `scope-toolbar` / Visitor 事件供应商 | 把「范围」当成 URL 路径通配；忽略 **channel←store←website←global** 继承；把站店渠塞进 `path_include`/`scope_json`；手写站店渠 `<select>` 替代 `<w:scope>`；跨模块依赖 ShippingConfigScopeService | 工具条左上 `<w:scope>`；文案含继承；路径控件为「路径过滤」 |
| **`<w:config:embed>`、配置嵌入、没有这个字段、undeclared、业务页 SystemConfig** | [config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；范例 Affiliate/B2B/Dropship `Backend/Config` | embed `field`/`fields` 自造短名或与 `<w:config:field key>` 不一致；未先写 Extends 模板就 embed；与 Affiliate「分销」配置混用货源键 | embed 字符串≡声明 key；先声明再消费；对照 Affiliate/B2B 页壳 |
| 图片、`<img>`、`file:image`、CLS、宽高比 | [file-image-cls-尺寸与响应式.md](../FileManager/doc/file-image-cls-尺寸与响应式.md)；MCP `image_explicit_width_height_css` | 只写响应式 CSS 不写 HTML width/height；裸 `<img src>` 替代 `<w:file:image>`（动态业务图） | 源码含 width/height 或 aspect_ratio；主题 `.w-file-image` / foundation `height:auto` |
| 注释、文件头、`<?=` / `<?php` 开标签 | 本文；MCP `no_php_tags_in_comments`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | **注释**（`//` `#` `/* */` `/** */` `<!-- -->`）内出现 `<?=` / `<?php` / 短开标签；文件头残留生成器短回显；用 `<?php /* ?>…<?=…*/` 包死代码 | 检索注释内开标签；**非**禁止 `// $x = 1;` 这类普通注释掉语句 |
| 注释语言、代码风格、可读性 | 本文；MCP `chinese_comments_friendly_style`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | 新增说明性注释/PHPDoc 却用英文堆砌；过度巧妙/过度抽象/工业式套话导致难读；无视周围既有风格 | Diff 抽查注释语言与命名/控制流可读性 |
| `@static`、静态资源 404、`/@static(` | [Framework/doc/static-resource-versioning.md](../Framework/doc/static-resource-versioning.md)、[09-static标签](../Framework/doc/4-内置标签/09-static-template-js-css标签使用指南.md) | 在 Taglib callback 字符串里用 `@static`（不会二次编译） | 浏览器 Network 无 `.../@static(` 字面量 |
| 写 HTML 标签、页面控件、**选择性输入**（下拉/单选/多选/chips/枚举） | [场景映射表.md](../Taglib/doc/场景映射表.md)、[标签全量索引.md](../Taglib/doc/标签全量索引.md)、[Framework/doc/4-内置标签/README.md](../Framework/doc/4-内置标签/README.md)；MCP `taglib_before_hand_rolled_controls` | **未先查标签库**就手写 `<select>` / ISO text / 自造 chips；能用 `<w:*>` / `<lang>` / `<w:hook>` 时用裸 HTML | 架构上选择性控件优先标签；无标签则在数据拥有模块新增 Taglib，禁止业务页临时拼装 |
| 主题、layout、widget、partial | [Theme/doc/AI-INDEX.md](../Theme/doc/AI-INDEX.md)、[Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) | 改 `generated/`、`view/tpl`；layout 内嵌非 `Weline_Theme` 部件 | `php bin/w frontend:check-theme-layout-widgets` |
| 前台部件 JS、`Weline.declare`、`data-weline-load`、`weline.modules.js` | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)、[Theme.js使用指南.md](../Theme/doc/Theme.js使用指南.md)、[Theme开发总指南.md §2.6](../Theme/doc/开发/Theme开发总指南.md)；MCP `theme_js_module_declare_only` / `weline_js_loader_framework_only`（**强制**） | 部件/布局 `<script src="@static(...js)">` / 裸 `<js>` 直拉模块；不注册 modules 就加载；**改登记不 `resource:compile welineModules`**；**在 `weline.js` 写死业务名/业务逻辑或路径启发式预载**；把核心 i18n.js 放进外置 `Weline_I18n` | 仅 `declare` + `data-weline-load|declare`；改模块后 `php bin/w resource:compile welineModules`；`weline.js` 仅 ModuleLoader + 维护懒加载；核心 `i18n` 由 Framework 登记 |
| 前端 UI、Weline UI、CSS 变量、地址表单、结账样式 | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md)、[theme-semantic-color-matrix.md](../Theme/doc/theme-semantic-color-matrix.md)、[场景映射表.md](../Taglib/doc/场景映射表.md)；**MCP** `get_skill(weline-theme-development)`；MCP `weline_ui_theme_first` / `theme_base_components_token_only` / `ui_skill_requires_theme_skill` | 第三方 UI（Bootstrap/Element/Ant）；硬编码色值/间距；**基础组件（`w-button`/`w-input`/`w-alert` 等）私写 hex/rgb 或平行色变量**；手写国家/省/市 input 替代 `<w:theme:address>`；**只用 frontend-design/UI 技能却不从 MCP 取主题技能**；自造 hex/rgb 色板或 px 间距阶梯 | 对照主题组件类名与 Token；Browser 多断点 |
| UI 技能、frontend-design、审美/配色、自造设计系统 | 同上；**必须先** `get_skill(weline-theme-development)` + Theme Token 文档 | 按通用 UI 技能发明私有 palette/spacing/radius/shadow；用 UI 技能覆盖主题 Token；给基础组件另写颜色；把宿主 `SKILL.md` 当权威 | 主题 Token 优先；UI 技能仅构图/层次/文案；基础组件只消费 `--weline-theme-*` / `--color-*` |
| 审图、截图、发图审查、**用户附图/粘贴图**、**截图不说**、**线稿/抽取线图**、**原型调整** | [dev/ai-command/theme/审图.md](../../../../../dev/ai-command/theme/审图.md)；**MCP** `user_image_attachment_triggers_shentu` / `image_attachment_shentu_bundle`；**任意附图即审**（含后台/CMS/错误页，不必再说「审图」）；**非报错 `ui_shot` 默认=改 UI**（禁止只点评）；**同回合联合**：线稿抽取 → `prototype` 调整 → `frontend-design` → `weline-theme-development` CSS/Token；`error_shot` 优先修异常且错误页可读；缺 UI/原型技能须提示并自行装入 Agent Store；fail 必须改到 pass | 非本仓前端图仍套店面修复；`ui_shot` 只点评不改；跳过线稿/原型直接糊 CSS；把产品 UI 截图当闲聊插图；缺技能仍写 E/F pass | 分流（含 error/ui）+ 线稿 + 原型调整 + 技能门禁 + 检查清单；Browser 验收 |
| 地区筛选、国家/省/市/区选择、地址多选 chips、系统禁运新增国家 | [场景映射表.md](../Taglib/doc/场景映射表.md)；MCP `theme_address_for_region_pickers` / `taglib_before_hand_rolled_controls` | 手写国家/地区 `<select>`；**手写 ISO 国家码 text input**；自造筛选芯片行；绕开 `<w:theme:address>` 的级联 input | 列表/表单筛选用 `selection=single\|multi`；仅选国可用 `levels=country`；chips/菜单走标签与浮层内核 |
| 浮层、下拉、menu、popover、tooltip、combobox、anchored-float、边界翻转、portal | [Theme开发总指南.md §通用浮层](../Theme/doc/开发/Theme开发总指南.md)、[anchored-float.md](../Theme/doc/widgets/anchored-float.md)、MCP `weline_ui_floating_primitives` | **手写 `left/top`**；自研 flip/边界检测；私有 portal 栈；地址多选菜单绕开 `UI.floating.attach` | 对照 `data-w-component` / `UI.floating.attach`；Browser 底部展开上翻 |
| 版心、内容区宽度、页面容器、`.w-container`、`max-width`、gutter | [theme-layout-content-width.md](../Theme/doc/theme-layout-content-width.md)；MCP `frontend_unified_content_container` | **自写一套页面容器**；`1440px`/`1200px`/`1180px`/`90rem` 私有壳；已在 `.w-container` 内再写 `max-width`+`padding-inline`（双重 gutter）；`var(--weline-layout-content-max-width, 1440px)` | ThemeFrontendLayoutsContentWidthContractTest / ThemeStorefrontModuleContentWidthContractTest；对照顶栏左右沿 |
| section、`weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) | 缺/空 `weline-code`；无语义名如 `section1`；同文件重复 code | `php bin/w frontend:check-section-code` |
| 前台文案、翻译、i18n | [Theme开发总指南.md §i18n](../Theme/doc/开发/Theme开发总指南.md)、[01-lang标签](../Framework/doc/4-内置标签/01-lang标签使用指南.md) | `.phtml` HTML 正文/属性内 `<?= __('...') ?>` | — |
| **定时翻译进度、队列/cron 是否在跑、翻译记录有没有新增、运行状态查询** | 本文 `runtime_status_query_local_first`；MCP `hard_constraints.mcp_operational` | **未明示就查生产/SSH weline**；把 SSH 默认 profile 当默认查线上；「翻译特例必须查生产」 | 默认查本机 DB/进程；仅用户说线上/生产/ssh weline/aiweline.com 才查远端 |
| i18n CSV、中英翻译、collect | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md) | 只改 CSV 不 `i18n:collect`；缺 en_US 或前后台词条不对齐 | `php bin/w i18n:collect Weline_Module` |
| 新建 Hook、`view/hooks` | [Hook创建规范.md](../Hook/doc/Hook创建规范.md)、[Hook使用指南.md](../Theme/doc/Hook使用指南.md) | 只有 `.phtml` 无 `hook.php` + `doc/hook/*.md`；**type 段发明 `theme-editor`/`checkout` 等功能名**（须 `partials` 或 `layouts`） | `php bin/w setup:upgrade --route` |
| 新建 Event、Observer、`event.xml` | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md)、[event/README.md](../Framework/doc/event/README.md) | 发明未文档化事件名；跨模块直调 Service | 检索 `doc/event/` 与 dispatch 一致 |
| 跨模块读/写 | [扩展点选型.md](../Framework/doc/3-开发/扩展点选型.md) | 跨模块 `new` 对方 Service/Model | — |
| 浏览器 AJAX、表单提交 | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md) | raw `fetch` / `axios` / `$.ajax` | — |
| 后台页面、Toast、Confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；**MCP** `no_native_js_dialogs`（独立高压线，勿埋进 `no_generated_no_routes_xml`） | JS `alert` / `confirm` / `prompt`；业务页用原生对话框代替 `Weline.UI.toast` / `Weline.UI.dialog.confirm` / `Theme.Notice` | 契约断言模板/脚本无 `window.alert(` / `window.confirm(` / `window.prompt(` |
| 缓存、HotCache、WLS、CachePool、进程内 memo、清理不同步 | [统一缓存范围与性能优化.md](../Framework/doc/统一缓存范围与性能优化.md)、[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) | **业务类再自做一层进程内缓存**（绕开 CachePool/Adapter）；读写/清理 **key 不一致**；清理只清驱动不清进程内（或反之）；可变 Model/个性化 HTML/草稿/未提交事务读进共享缓存 | 可缓存事实走 Framework **缓存类**：进程内存储 → 驱动存储；**同 key** 读写与清理同步；单次读命中进程内则不再打驱动。`cache_lookup_tier_process_shared_db` **已取消**（勿再当 MCP 硬规则要求业务类自做三层） |
| ORM、Model、Schema | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md)、[模块开发完整指南.md](../Framework/doc/3-开发/模块开发完整指南.md) | 改 Model/Controller 不 bump `etc/module.php` version；密封编辑缺 bump → `EDIT_MODULE_VERSION_REQUIRED` | `php bin/w setup:upgrade -m Weline_Module` |
| 新建 Controller、路由、后台链接 | 同上 §控制器；**URL 动作为 `edit`/`add`/`save`，禁止写成 `getEdit`/`getAdd`/`postSave`** | 把 `get*`/`post*` 方法前缀拼进 URL | `php bin/w setup:upgrade --route`；对照 [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md) |
| 交付、验收 URL、Browser 自测、**自行验证**、**功能 e2e**、**章通路+计划组套件**、**禁止甩测给用户** | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)、[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)、[AI工程交付流程.md](./AI工程交付流程.md) §6–§7；MCP `agent_self_verify_before_done` / `browser_operator_self_test` / **`ui_feature_requires_e2e`** / **`plan_full_pathway_e2e_suite`** / **`forbid_user_manual_test_handoff`** / `feature_delivery_urls` / `closeout_delivery_reminder` / `browser_cache_disabled_on_open` / `browser_release_after_delivery` | **只改代码不跑 UT/RT/WB**；无 evidence 标 acceptance passed；单测/curl/**仅 CDP** 冒充 e2e 完成；**请用户测试/刷新再试**；**只做部分章节不测就汇报完成**；省略「交付地址」；臆造路由；写死某一 IDE Browser；**主 Host 用 `*.weline.test` 或在有 `*.test.weline.com` 时强行 `127.0.0.1`**；**带着默认缓存验本回合静态资源**；**写完交付地址仍不关验收 Browser**；**任何 feature 无章通路 `type=e2e` / 无 `e2e-plan-suite` / 未跑 `php bin/w e2e:run` PASS / 用 skipped 冒充** | 实现后 Agent **亲自**按验收层级验证；`passed/skipped/na` 须带 evidence；**feature 每章通路 e2e + 计划组套件必须 status=passed**；宿主可用真实 Browser：**打开即禁用缓存**后跑用例 + curl 探活；**任何 feature 须 Playwright 章 e2e + 收口组套件 PASS，禁止甩测给用户**；本机主链默认 `{project_hash}.test.weline.com`；汇报「交付地址」后立即关闭本回合验收标签 |
| Cursor 调试 ingest、`127.0.0.1:7277`、`#region agent log`、CSP `connect-src` 拦截调试 | [安全响应头策略.md](../Framework/doc/3-开发/安全响应头策略.md)「开发态 CSP 工具链片段」；MCP `cursor_debug_csp_developer_tooling` | 把 Cursor localhost 写进 Framework `SecurityHeaderDefaults` / Extends `Security/Csp` 应用默认；生产基线永久放行 7277；依赖调试记录却不配 Env | 本机 `app/etc/env.php` → `security.headers.csp_developer_tooling` = `connect-src http://127.0.0.1:7277 http://localhost:7277`；仅 DEV/DEBUG 响应时 union；控制台不再 CSP 拦 ingest |
| 多 todo 计划收口、进度汇报 | [AI工程交付流程.md](./AI工程交付流程.md) §7；MCP `plan_todo_evidence_closeout` | 计划未逐项举证就宣称「已完成」；Cursor todo 无证据标 completed；隐瞒未清库/未删代码/未跑 Factory Reset | 对每个 todo 给出路径/DB/命令/Browser 证据；部分完成须列「未完成清单」并写入 `doc/开发日志.md` |
| 计划合规审核、章节 e2e 闭环、计划组套件、串行进度 | [AI工程交付流程.md](./AI工程交付流程.md) §3–§6；MCP `task_plan_compliance_review` / `chapter_ut_rt_wb_dl` / `plan_full_pathway_e2e_suite` | 计划不审架构/解耦/电商合规/原型/e2e/体量/闭环；多任务无章节；章节无 `acceptance_ids`；多章共用同一 e2e；缺 `e2e-plan-suite`；未完成绑定验收就标 done/开下一章；并行多个 in_progress；半截汇报/甩人测 | `dev_tasks.acceptance_ids` 硬绑定；feature 章独立通路 e2e + 收口组套件；passed+evidence 后才 done；`review_task_plan.compliance_dimensions` |
| 密封编辑、写码前计划、`PLAN_REQUIRED`、每条编码需求完整工作流、**TDD**、**计划合规审核（架构/解耦/电商合规/原型/e2e/体量/闭环）**、**章节=e2e闭环**、**框架审视纠偏**、**功能判定/隐形需求分析/原型·UI 按分析决策**、**加功能先审当前图**、**功能页一页宜简顶部 Tab**、**验收审图**、**结束汇审**、**架构层映射需求**、**框架解耦/禁止耦合** | [AI工程交付流程.md](./AI工程交付流程.md) §1–§4 / §6–§7；[扩展点选型.md](../Framework/doc/3-开发/扩展点选型.md)；MCP `user_requirement_full_workflow` / `task_plan_compliance_review` / `requirement_framework_scrutiny` / `requirement_feature_kind_gate` / `requirement_implicit_analysis_skill_decision` / `feature_add_requires_current_ui_review` / `feature_ui_keep_simple_top_tabs` / `acceptance_phase_requires_shentu` / `closeout_requires_huishen` / `architecture_first_for_requirements` / `framework_decoupled_only` / `plan_then_tdd_required` / `mcp_call_scope` | 编码需求提出后不立即 `submit_task_plan`；**跳过 `work_kind`/`requirement_scrutiny`/`architecture`/`coupling_findings` 直接改码**；计划未按七维合规审核；多任务无章节细节；章节无 e2e 闭环；未标进度就开下一章；未写 `implicit_requirements`/`ui_skill_decision`；凡 feature 一律强制原型+UI；participate 却不让 `prototype`+`frontend-design` 参与；**既有页加功能却不截当前图/现页已乱仍硬塞控件**；**进度+配置等不同职责堆同一长页**；验收阶段不做审图；结束无汇审；**字面照做不合理需求**；**耦合写法**；计划无 `type=unit`；先堆业务代码后补测；**未实际跑测就宣称完成**；有纠偏却不在汇报「需求纠偏」列出；发现耦合却不在汇报「耦合提示」列出；非编码却强调 MCP；把 `PLAN_REQUIRED` 当完成 | `php app/code/Weline/Ai/Mcp/tests/task-plan-gate.php`；计划含 `work_kind` +（功能时）`skill_participation`+`type=shentu` + requirement_scrutiny + architecture + coupling_findings + unit；`review_task_plan` 含 `compliance_dimensions`；多任务章节化且每章闭环；验收含审图证据；收口含 `huishen_notes` 汇审；汇报含「需求纠偏」「耦合提示」「汇审」；红→绿→跑通并写 PASS evidence |

## 写 HTML / 模板前决策流（硬规则）

```text
1. 用户可见文案？     → <lang> / @lang()；禁止 HTML 内 __()
   · 源文含逗号？     → 必须 <lang>…</lang> 或加引号 @lang('a, b')；禁止 @lang{a, b}（逗号当参数分隔，编译 ParseError）
2. 领域/选择性控件？ → **先**查 Taglib 场景映射表 + 标签全量索引；禁止裸 <select>/<input>/ISO text；无现成标签则在拥有模块新增 Taglib
3. 页面插槽/可运营块？ → Hook 或 Widget
4. 布局骨架？         → layout/partial/component/widget 分层，读 Theme 总指南
5. 内容区宽度/容器？  → 先读 theme-layout-content-width.md：已在 .w-container 内用壳层 A（width:100% + padding-inline:0）；独立壳用壳层 B（--weline-layout-content-* / .w-theme-content-width）。禁止自写第三套容器或像素字面量版心
6. 浮层/下拉/工具条？ → menu/popover/tooltip/combobox/anchored-float 或 UI.floating.attach；禁止手写 left/top 与自研边界翻转
7. 图片？             → <w:file:image> 设 width+height 或 aspect_ratio（HTML 占位防 CLS）+ 主题 CSS max-width:100%;height:auto；禁止无尺寸裸 img
8. 以上都不满足？     → 才写原生 HTML，TaskContract 说明原因
```

## 会话反复纠正清单

| 纠正点 | 权威文档 |
|--------|----------|
| Hook 只有 phtml、无规约 | [Hook创建规范.md](../Hook/doc/Hook创建规范.md) |
| Hook type 段非 partials/layouts | [Hook创建规范.md](../Hook/doc/Hook创建规范.md) §type 段 |
| Event 名未文档化就 dispatch | [事件命名与注册规范.md](../Framework/doc/3-开发/事件命名与注册规范.md) |
| 缺 `weline-code` | [frontend-section-weline-code.md](../Theme/doc/frontend-section-weline-code.md) |
| 手写 select 代替 Taglib | [场景映射表.md](../Taglib/doc/场景映射表.md) |
| 把「范围」写成路径通配 / 忽略站店渠继承 | [store-saleschannel-scope.md](../Websites/doc/store-saleschannel-scope.md)；MCP `weline_business_scope_hierarchy`；SystemConfig `getFallbackScopes` |
| 业务「配置」另造私有表/Service 或忽略统一配置中心 | [SystemConfig README](../SystemConfig/doc/README.md)；MCP `systemconfig_unified_config_terms` / `SystemConfigTermRouting` |
| embed `fields` 与声明 `key` 不一致 / 「没有这个字段」红标 | [config-embed标签使用指南.md](../SystemConfig/doc/config-embed标签使用指南.md)；Affiliate/B2B/Dropship Config |
| 前端不用自研主题 / 硬编码视觉 / 手写地址级联 | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md)、[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md) |
| 用 UI/frontend-design 技能却自造色板间距、不从 MCP 取主题技能 | MCP `get_skill(weline-theme-development)`；`ui_skill_requires_theme_skill`；[theme-css-variables-only.md](../Theme/doc/theme-css-variables-only.md) |
| 提到 CSS/主题却不读 UI+原型+主题三技能 | MCP `css_or_theme_requires_ui_prototype_theme_skills`；`frontend-design` + `prototype` + `get_skill(weline-theme-development)` |
| 把宿主 SKILL.md 当工程技能权威 | 本文 `mcp_skills_fetch_from_mcp`；`resolve_skill` / `get_skill` |
| 主题开发却给基础组件私写颜色 | MCP `theme_base_components_token_only`；[theme-semantic-color-matrix.md](../Theme/doc/theme-semantic-color-matrix.md) |
| 地区筛选/国家省市区手写 select 或自造 chips | [场景映射表.md](../Taglib/doc/场景映射表.md)；MCP `theme_address_for_region_pickers` |
| 浮层手写 left/top / 自研 flip / 绕开 Weline.UI | [anchored-float.md](../Theme/doc/widgets/anchored-float.md)；MCP `weline_ui_floating_primitives` |
| 自写一套页面/模块版心容器 / 双重 gutter / `1440px` 私有壳 | [theme-layout-content-width.md](../Theme/doc/theme-layout-content-width.md)；MCP `frontend_unified_content_container` |
| 写 HTML 不查 Taglib | 本文 + [标签全量索引.md](../Taglib/doc/标签全量索引.md) |
| 业务类自做进程内缓存 / 清理与驱动 key 不一致 | [统一缓存范围与性能优化.md](../Framework/doc/统一缓存范围与性能优化.md)（缓存类进程内+驱动同 key）；勿再引用已取消的 `cache_lookup_tier_process_shared_db` |
| 图片缺 HTML 宽高 / 只靠 CSS 声称响应式 | [file-image-cls-尺寸与响应式.md](../FileManager/doc/file-image-cls-尺寸与响应式.md)；MCP `image_explicit_width_height_css` |
| 前台 phtml 用 `__()` | [Theme开发总指南.md §i18n](../Theme/doc/开发/Theme开发总指南.md) |
| `@lang{含,逗号}` 编译 ParseError | [01-lang标签使用指南.md](../Framework/doc/4-内置标签/01-lang标签使用指南.md)：逗号为参数分隔；改 `<lang>` 或加引号 |
| Theme layout 内嵌他模块 widget | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| 部件直接 `@static` / `<script src>` 拉 JS 模块 | [前端JS模块加载规范.md](../Theme/doc/前端JS模块加载规范.md)（`data-weline-load` / `Weline.declare`） |
| 在 `weline.js` 写死 cart/account/compare 等业务名、业务代理或兑券/加购/维护 UI；把核心 i18n.js 放进外置 Weline_I18n | 同上；MCP `weline_js_loader_framework_only`（强制）；核心 i18n 归 `Weline_Framework::js/i18n.js`；Theme declare 加载；`Weline_I18n` 仅增强；勿把 Phrase 改名为 I18n（与小写 `i18n/` CSV 冲突） |
| `w:*` 属性写 PHP | [Theme开发总指南.md](../Theme/doc/开发/Theme开发总指南.md) |
| 注释内写 `<?=` / `<?php`（含文件头日期短回显） | 本文 `no_php_tags_in_comments`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) |
| 新增注释用英文堆砌 / 代码过度巧妙难读 | 本文 `chinese_comments_friendly_style`；[开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md) |
| raw fetch/ajax | [Weline.Api使用指南.md](../Frontend/doc/Weline.Api使用指南.md) |
| alert/confirm | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；MCP `no_native_js_dialogs` → `Weline.UI.toast` / `dialog.confirm` |
| routes.xml / 改 generated | [AI-ENTRY.md](../../../AI-ENTRY.md) |
| 交付 URL 格式错误 / 省略交付地址 | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md)；MCP `feature_delivery_urls` |
| 验收 Browser 未禁用缓存就验本回合 UI | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) 门禁 A WB-CACHE；MCP `browser_cache_disabled_on_open` |
| 写完交付地址仍不关验收 Browser | [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) 门禁 D；MCP `browser_release_after_delivery` |
| 主验收 Host 写成 `*.weline.test` 或强行 `127.0.0.1` | 同上「本机默认 Host」；默认 `{project_hash}.test.weline.com` |
| 未自行验证就宣称完成 / acceptance 无 evidence / 功能缺审图 / 缺汇审 | [开发标准与验收.md](../Framework/doc/3-开发/开发标准与验收.md)；MCP `agent_self_verify_before_done` / `acceptance_phase_requires_shentu` / `closeout_requires_huishen`；`review_task_plan` 缺 evidence/汇审 → closeout_allowed=false |
| 未规划 / 未 TDD / unit 无真实跑测 evidence | [AI工程交付流程.md](./AI工程交付流程.md)；MCP `plan_then_tdd_required`（计划须含 unit；红→绿；PASS evidence） |
| 未 Browser 自测就宣称完成 | 同上；只能报「代码已改，WebUI 验收未完成」 |
| 多 todo 计划未逐项举证就说「已完成」 | [AI工程交付流程.md](./AI工程交付流程.md) §7（`plan_todo_evidence_closeout`）；须报「部分完成」+ 未完成清单 | |
| 计划未按七维合规审核 / 多任务无章节 e2e 闭环 / 未标进度就下一章 | [AI工程交付流程.md](./AI工程交付流程.md) §3（`task_plan_compliance_review`）；`review_task_plan.compliance_dimensions`；`dev_tasks.acceptance_ids` 硬绑定 | |
| 让用户手写 MCP Settings | [AGENTS.md](../../../AGENTS.md) |
| 手写 `.cursor/rules` / Codex 私有规则当权威 | 本文 `host_editor_rules_mcp_generated_only`；仅允许 MCP 生成宿主规则产物 |
| 查翻译/cron/队列状态默认去生产 / 把 SSH 默认 profile 当默认查线上 | 本文 `runtime_status_query_local_first`；无翻译特殊线上规定 |
| 改 Model 字段不 bump 模块 version | [模块版本与升级门禁.md](../Framework/doc/3-开发/模块版本与升级门禁.md)（MCP 密封编辑强制 `EDIT_MODULE_VERSION_REQUIRED`） |
| 无会话计划就密封编辑 | [AI工程交付流程.md](./AI工程交付流程.md)（`submit_task_plan` → `task-plan.v1` 含 `requirements`；否则 `PLAN_REQUIRED`） |
| 用户提出需求后不建完整工作流 / 功能不标 work_kind / 验收无审图 / 结束无汇审 | 同上 §1–§7（`user_requirement_full_workflow` / `requirement_feature_kind_gate` / `acceptance_phase_requires_shentu` / `closeout_requires_huishen`） |
| 新建 Controller 不 upgrade/route | 同上 |
| URL 写成 `/getEdit`、`/getAdd`、`/postSave` | [03-自定义控制器.md §HTTP方法](../Framework/doc/2-快速开始/03-自定义控制器.md)：`getEdit()`→路径 `/edit`，`postSave()`→`/save`；前缀只约束请求方法 |
| 改 CSV 不 collect / en_US 未译 | [模块翻译CSV规范.md](../I18n/doc/模块翻译CSV规范.md) |
| 前台加词后台 CSV 不补 | 同上 |
| Taglib callback 里写 `@static(...)` 导致 404 | [如何自定义Tag.md §静态资源](../Taglib/doc/如何自定义Tag.md)、[I18n/Taglib/Local.php](../I18n/Taglib/Local.php) `resolveModuleStaticUrl` |
| 控制台 `.../@static(Weline_*.css)` 404 | 同上；查 `<w:*>` 标签 callback 是否裸输出 `@static` |

## MCP 检索

```text
resolve_task_context(task="...", kinds=["doc","rule"])
get_indexed_document(path="app/code/Weline/Ai/doc/AI硬规则索引.md")
```

会话完整硬约束由 `prepare_project.agent_guidance.hard_constraints` 提供。后续 `guidance-bundle.v1.workflow_contract`（`workflow-contract.v1`）只返回任务匹配的规范和权威文档入口；正常响应不再重复下发全量工作流、固定 `pinned_fragments` 或前端兼容别名。`rules` 可携带验证后的学习规则，`sources`、`pinned_fragments` 仅为可选旧版字段。

`fragments` 自带路径、行号与来源 hash。`content_hash` 标识完整索引源片段，不是返回摘要的 hash；最终预算再次裁剪正文时标记 `content_truncated=true`，来源 hash 保持不变。编辑符号须使用 `get_edit_bundle` 的完整区域和编辑 guard。

`token_usage.estimated` 按完整序列化工具响应的 Unicode 字符数除以 4 估算，包含封装；不是模型 tokenizer 实测。若预算小于必要约束和会话元数据的固定成本，返回真实估算及 `budget_exceeded=true`，不新增执行门禁。

## 相关

- [文档索引.md](./文档索引.md)
- [AI工程交付流程.md](./AI工程交付流程.md)
