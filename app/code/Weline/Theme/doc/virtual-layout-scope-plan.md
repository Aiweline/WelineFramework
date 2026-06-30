# Theme 虚拟布局与产品/分类布局计划

> 模块：`Weline_Theme`
> 状态：规划中
> 关联总计划：[SystemConfig 与 Theme 虚拟布局总计划](../../SystemConfig/doc/scope-config-theme-layout-master-plan.md)
> 关联配置模块子计划：[SystemConfig Scope 配置树计划](../../SystemConfig/doc/scope-config-tree-plan.md)

## 目标

Theme 模块统一支持虚拟布局。产品页、分类页、分类下产品默认布局、节日活动布局、AI 创建布局、源码编辑和可视化编辑，都作为 Theme 的布局资产能力实现。

产品和分类模块只提供对象身份和后台入口，不再自己维护布局渲染体系。

## 核心原则

- 布局属于 Theme。
- scope 属于 Framework `ScopeContext`。
- 配置读取属于 `Weline_SystemConfig`。
- 产品、分类只提供 identity。
- 定时计划只改变有效 layout option，不把业务逻辑塞进 layout 模板。

## 布局身份

Theme 解析布局时需要以下身份：

| 字段 | 示例 | 说明 |
| --- | --- | --- |
| `area` | `frontend` | 前台或后台 |
| `layout_type` | `product`、`category` | 页面布局类型 |
| `layout_option` | `default`、`mothers_day` | 布局选项 |
| `target_type` | `product`、`category`、`category_product_default`、`global` | 目标对象类型 |
| `target_id` | `123` | 目标对象 ID |
| `scope` | `default.demo` | 来自 Framework ScopeContext |

标签属性负责传身份，例如当前对象是 product 或 category。scope 不通过标签手动拼接，默认读取请求级 `ScopeContext`。

## 虚拟布局资产

Theme 需要支持虚拟布局资产和版本：

- layout asset：描述布局身份、scope、目标对象、当前发布版本。
- layout asset version：保存源码、可视化结构、AI 草稿、发布状态。
- 文件布局继续存在，作为 fallback。

建议字段方向：

| 字段 | 说明 |
| --- | --- |
| `theme_id` | 所属主题 |
| `area` | frontend/backend |
| `layout_type` | product/category/homepage 等 |
| `layout_option` | default/custom/festival 等 |
| `scope` | 当前资产适用 scope |
| `target_type` | product/category/category_product_default/global |
| `target_id` | 目标对象 |
| `source_type` | file/virtual |
| `published_version_id` | 当前发布版本 |
| `is_ai_generated` | 是否 AI 创建 |
| `meta_json` | 少量元信息 |
| `version` | 资产元信息版本，用于并发冲突检测 |
| `updated_at` / `updated_by` | 最后修改时间和操作人 |

唯一约束：

```text
theme_id + area + layout_type + layout_option + scope + target_type + target_id
```

查询索引需要覆盖：

- `theme_id + area + layout_type + target_type + target_id + scope`
- `theme_id + area + layout_type + layout_option + scope`
- `published_version_id`

这样产品、分类、分类下产品默认和全局布局都能按 fallback 链快速命中。

### 版本保存

虚拟布局必须支持版本保存：

- 每次源码保存、可视化保存、AI 生成都生成新的 draft version。
- version 保存 `source_code`、`visual_schema_json`、`ai_prompt_json`、`preview_snapshot`、`created_by`、`created_at`、`status`。
- `status` 至少包含 `draft`、`previewed`、`published`、`archived`。
- 发布只更新 layout asset 的 `published_version_id`，不覆盖历史 version。
- 发布前检查 asset `version`，如果其他管理员已发布或修改，必须提示冲突。
- 发布成功生成发布记录，记录 old published version 和 new published version。
- 回滚发布只把 `published_version_id` 指回上一版本，并生成新的发布记录。
- 回滚不能删除版本历史。
- 版本列表必须能区分草稿、已预览、已发布、回滚生成版本和失败记录。

版本保存是运营容错能力：

- 源码保存失败、可视化保存失败、AI 生成失败，都不能改变当前 `published_version_id`。
- 保存失败不生成可发布的已应用版本；可以记录 failed 审计，但前台继续使用旧 published version。
- 草稿保存成功后返回 `layout_version_id`，后台可立即预览、继续编辑、另存或丢弃。
- 发布失败必须保持旧发布版本继续生效。
- 发布成功后后台必须提供“立即回滚到上一发布版本”入口，并能从版本列表选择任一可发布历史版本恢复。
- 回滚发布只改变有效发布指针，并记录 `rollback_of_version_id`、actor、reason、old/new published version。
- 如果回滚目标版本不存在、已损坏或被标记不可发布，必须拒绝回滚并提示原因。
- 同一个虚拟布局的源码编辑和可视化编辑共享版本模型，保存时必须检查 base version，不能互相静默覆盖。
- AI 生成只允许创建 draft version，不能直接成为 published version。

保存和发布必须按双阶段处理：

- 保存源码、可视化结构或 AI 结果只创建 draft version，返回 `layout_version_id`。
- 发布 draft version 时才更新 asset 的 `published_version_id`。
- 如果“保存并发布”在同一个后台动作里执行，也必须分别返回 draft save 结果和 publish 结果。
- draft save 成功但 publish 失败时，draft version 保留为可继续编辑状态，当前已发布版本继续生效。
- publish 之前必须快照 asset 的旧发布指针、asset version 和该 asset 下版本状态。
- publish 中途失败必须补偿恢复旧发布指针和旧版本状态，并失效相关 runtime cache。
- rollback 只能发布属于同一 asset、同一 identity、同一 scope 的历史版本；不允许跨产品、跨分类、跨 scope 回滚。
- rollback 本身生成审计/发布记录，记录 `rollback_of_version_id`、actor、reason、old published version 和 new published version。

### 布局选择版本

布局选择版本和虚拟布局内容版本必须分开：

- 布局内容版本回答“这个 layout option 的源码、可视化结构、AI 草稿是什么”。
- 布局选择版本回答“当前产品、分类或分类下产品默认指向哪个 layout option”。

产品、分类、分类下产品默认的选择变更需要进入选择版本或 SystemConfig 配置批次：

| 字段 | 说明 |
| --- | --- |
| `layout_type` | `product`、`category` |
| `target_type` | `product`、`category`、`category_product_default`、`global` |
| `target_id` | 产品或分类 ID |
| `scope` | 规范化 scope |
| `old_layout_option` / `new_layout_option` | 变更前后选择 |
| `old_source` / `new_source` | 产品专属、分类默认、全局或继承来源 |
| `base_selection_version` | 并发冲突检测 |
| `actor_id` / `reason` | 操作人和备注 |

选择保存规则：

- 保存前校验 option 存在、未锁定、允许当前 layout type、target type 和 scope 使用。
- 选择保存失败不能改变当前 effective option。
- 选择保存成功后返回 `selection_version_id` 或 SystemConfig `version_id`，后台提供立即回滚。
- 选择回滚只恢复 layout option 指向，不修改 Theme virtual layout `published_version_id`。
- 如果回滚目标 option 已删除、锁定或不属于当前身份，必须拒绝回滚并提示原因。
- 定时计划不生成选择版本，不写回选择；它只在解析阶段临时改变有效 option。

当前实现约定：

- 产品、分类、分类下产品默认的 layout option 选择走 `Weline_Theme` 的 SystemConfig 批次版本，`version_id` 就是选择版本 ID。
- Theme 提供 `w_query('theme', 'listLayoutSelectionVersions', ...)` 列出某个 identity 的选择版本，并可返回 rollback precheck。
- Theme 提供 `w_query('theme', 'precheckLayoutSelectionRollback', ...)` 预检版本状态、identity、scope、locale、当前 row version 和待恢复 option 可用性。
- Theme 提供 `w_query('theme', 'rollbackLayoutSelectionVersion', ...)` 通过 SystemConfig 版本回滚恢复选择，并在成功后清理 Theme/WeShop runtime cache。
- 选择版本回滚只恢复 `virtual_layout.selection.{target_type}.{target_id}.{layout_type}` 这个选择键，不修改虚拟布局内容版本和 `published_version_id`。

## 解析优先级

产品详情页：

```text
产品专属虚拟布局
分类下产品默认虚拟布局
全局产品虚拟布局
主题文件产品布局
```

分类页：

```text
分类专属虚拟布局
全局分类虚拟布局
主题文件分类布局
```

scope fallback 由 `SystemConfig` / Framework 统一提供。Theme 只按返回的 fallback 链查找布局资产。

## 分类下产品默认布局

例如“睡衣”分类选择了分类布局，同时选择了默认产品布局：

- 分类页布局：
  - `layout_type=category`
  - `target_type=category`
  - `target_id=睡衣分类ID`

- 分类下产品默认布局：
  - `layout_type=product`
  - `target_type=category_product_default`
  - `target_id=睡衣分类ID`

该分类下产品没有产品专属布局时，默认使用该分类下产品默认布局。

如果产品属于多个分类，需要明确解析策略：

- 优先当前访问路径中的分类。
- 没有访问路径分类时，使用产品主分类。
- 主分类不存在时，按分类排序取第一个可用布局。

解析器返回结果需要包含来源解释：

```text
layout_asset_id
layout_version_id
source=product|category_product_default|global|file
source_scope
fallback_chain
matched_category_id
schedule_id
```

后台预览和浏览器验收都要展示或记录这些来源，方便定位“为什么命中了这个布局”。

## 定时布局与恢复

定时计划不能覆盖产品或分类原布局配置。

活动开始时：

- 只激活计划对应的 layout option。
- 记录计划前有效 option、来源和 scope。
- 不写回产品专属 layout option。
- 不修改 `published_version_id`，只在解析阶段改变当前有效 option。

活动结束时：

- 当前计划自动失效。
- 解析器重新按产品、分类、全局、文件 fallback 计算有效布局。
- 如果活动期间运营手动修改了产品或分类布局，手动修改优先。

定时计划需要明确冲突规则：

- 所有计划时间按站点/店铺 scope 对应时区解释，缺省使用系统时区。
- 同一 target、scope、layout_type 下多个计划重叠时，按 `priority` 降序，优先级相同取最近创建或最近更新。
- 计划必须保存 `starts_at`、`ends_at`、`timezone`、`priority`、`status`、`created_by`、`updated_at`。
- 手动修改通过目标布局配置或资产的 `updated_at/version` 判定；活动开始后发生的手动修改优先于过期计划恢复。
- 定时任务失败时，前台解析仍按当前时间动态判断有效计划，不能依赖单次 activate 写入成功。

这个策略用于修复“活动布局写进产品专属布局后无法恢复”的风险。

定时布局版本关系：

- 定时计划只保存 `layout_option`、时间窗口、priority、scope 和 target，不保存布局源码。
- 活动布局源码仍是 Theme 虚拟布局版本或主题文件布局。
- 活动期间发布了新的活动布局版本时，计划继续指向相同 option，由 Theme 解析当前 published version。
- 活动结束不回滚布局版本，只让计划失效并重新计算有效 option。
- 如果运营需要恢复活动布局源码，使用 Theme 发布版本回滚；如果需要恢复配置选择，使用 SystemConfig 或布局选择版本回滚。

## 源码编辑

源码编辑只编辑当前虚拟布局版本：

- 不能直接改主题文件布局。
- 保存为草稿版本。
- 预览通过草稿版本渲染。
- 发布后更新 `published_version_id`。
- 保存时检查当前草稿 base version，冲突时要求刷新或另存新版本。
- 源码编辑必须限制可用标签、函数和 include 边界，不能允许任意执行后台敏感逻辑。
- 源码保存失败不影响当前已发布版本。

### 预览与回滚

- 预览必须绑定 layout asset、draft version、scope、target 和后台用户。
- 预览 URL 需要短期 token 或后台会话校验，不能让未发布草稿在公网直接成为有效布局。
- 预览 token 过期后不能继续访问草稿。
- 发布后后台保留“回滚到上一发布版本”入口。
- 回滚发布只改变 `published_version_id`，并记录 actor、时间、原因和前后版本。
- 如果上一版本已被删除或归档不可用，回滚必须拒绝并提示原因。

## 可视化编辑

可视化编辑复用 Theme 编辑器，但需要锁定布局上下文：

```text
theme_id + area + layout_type + layout_option + scope + target_type + target_id + layout_asset_id
```

锁定后：

- 只能编辑当前虚拟布局页面。
- 不能切换到其他页面或布局。
- 可以组织 slots、blocks、widgets、components。
- 预览只影响当前虚拟布局草稿。
- 可视化保存和源码保存使用同一套 version 模型，互相不能覆盖未合并的改动。

## AI 创建布局

虚拟布局支持像部件一样通过标签和元信息触发 AI 创建。

AI 创建流程：

1. Theme 根据 layout 标签、页面类型、target identity、scope 生成提示上下文。
2. AI 生成虚拟布局草稿。
3. 运营可以源码编辑或可视化编辑草稿。
4. 后台预览当前 scope 和 target。
5. 发布后成为有效布局版本。

AI 生成不能直接发布到生产有效版本，必须经过预览或人工确认。

AI 草稿安全规则：

- AI 生成只能创建 draft version。
- AI 输出进入源码前必须经过标签白名单和模板安全校验。
- AI prompt、模型、输入上下文和生成时间写入 version 元信息。
- AI 草稿发布前必须经过后台预览。

## 与 SystemConfig 的关系

Theme 不新增自己的 scope 配置系统。

- 普通配置：通过 `Weline_SystemConfig` 读取。
- scope fallback：通过 Framework/SystemConfig 获取。
- 布局资产：由 Theme 自己存储，因为它包含源码、版本、发布状态和 AI 草稿。
- 后台配置树：SystemConfig 显示 Theme 普通配置和 Theme 布局管理入口。
- Theme 只通过 `extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml` 提供配置模板，或通过 `<w:config:adapter>` 提供复杂资产入口。
- 全局 scope 切换、模块搜索、配置搜索、继承开关、保存、校验和缓存失效都由 SystemConfig 处理，Theme 不接管这些通用配置能力。
- Theme 的配置模板由其他模块扩展 `Weline_SystemConfig` 的 Extends registry 提供。刷新 Theme/SystemConfig 配置中心时不能只做目标模块级刷新；必须全量执行 `php bin/w extends:rebuild` 或等价的 extenders 重建流程，避免 Theme/Payment/WeShop 等模块贡献的配置模板从 `generated/extends.php` 中丢失。

## 缺陷补齐清单

实现必须覆盖以下当前风险：

| 风险 | 修正策略 |
| --- | --- |
| 产品或分类直接写布局文件，绕过 Theme 版本 | 自定义源码写入 Theme virtual layout version，主题文件只做 fallback |
| 选择布局和编辑源码混在一个保存动作里 | layout option 选择走配置/选择版本，源码编辑走 Theme layout version |
| 活动布局写入产品布局配置 | 定时计划只参与解析有效 option，不写回产品、分类或分类下产品默认选择 |
| 活动结束后不能恢复 | 计划过期后解析器重新走产品专属、分类默认、全局、文件 fallback |
| 多分类商品命中不稳定 | 优先当前访问路径分类，其次主分类，再按排序取第一个可用分类默认产品布局，并返回来源解释 |
| scope 手动从标签拼接 | 标签只提供 identity，scope 统一来自 Framework/SystemConfig fallback |
| 后台预览草稿没有权限边界 | 预览绑定 draft version、target、scope、后台会话或短期 token |
| 可视化编辑误切页面 | 编辑器锁定当前 layout context，不能切换到其他页面或布局 |
| 锁定可视化编辑仍调用真实主题保存接口 | 锁定模式禁用真实主题保存/发布/切换，并在统一 API 层拦截非 `virtual-theme` 写接口 |
| AI 生成直接发布 | AI 只写 draft version，人工预览/确认后发布 |
| 没有查询有效布局的统一入口 | Theme 提供 `w_query('theme', ...)` 只读操作，返回 option、source、version、scope 和 fallback |
| 页面缓存不感知虚拟布局发布版本 | 同一 layout option 发布新版本或回滚后，产品/分类 payload cache key 必须纳入虚拟布局 asset/version 指纹 |
| 定时计划创建返回复用对象 | 创建计划保存后返回独立模型对象，解析仍按当前时间、priority 和 fallback 动态计算 |
| 后台源码编辑没有版本列表和回滚入口 | 产品/分类后台通过 Theme version 列表展示历史版本，回滚时校验 asset/version 属于当前 identity |
| 保存失败和误保存没有分层恢复策略 | 保存失败保持旧发布版本，误保存通过版本列表立即回滚，并记录 actor/reason/old-new version |
| 保存失败后还要求人工回滚 | 运营可能以为前台已被污染，恢复路径不清晰 | 保存失败不改变 `published_version_id` 或有效选择；只有成功保存/发布的版本才进入立即回滚 |
| 布局选择版本和布局内容版本混在一起 | 选择回滚可能误改源码发布版本，源码回滚也可能误改产品/分类选择；必须单独定义选择版本，选择回滚只恢复 option 指向，内容回滚只切换 published version |
| 回滚入口缺少预检状态 | 可能覆盖后续修改或回滚到已删除/锁定 option；回滚前返回可回滚状态、冲突 key/version、阻断原因和预计恢复结果 |
| 保存并发布时 publish 失败 | draft 已保存但前台仍应使用旧发布版本；保存结果和发布结果分开返回，publish 失败时恢复旧 `published_version_id` 并保留 draft |
| 发布中途归档旧版本后失败 | 可能出现没有有效 published version 的状态；发布前快照 asset 和版本状态，失败时补偿恢复，最后清理 runtime cache |
| 回滚目标身份不匹配 | 可能把其他产品、分类或 scope 的历史布局发布到当前对象；回滚前校验 asset id、layout type、target type、target id、scope 和版本归属 |
| 布局选择保存非法或锁定 option | 可能导致默认布局被写成对象专属自定义布局；保存前校验 option 白名单和锁定规则，失败时不改变当前 effective option |

### 版本保存验收矩阵

| 操作 | 必须生成的版本 | 何时生效 | 失败恢复 | 回滚范围 |
| --- | --- | --- | --- | --- |
| 源码保存 | layout draft version | 不影响前台，预览时按 draft token 生效 | 不改变当前发布版本 | 回滚发布版本，不回滚草稿列表 |
| 可视化保存 | layout draft version，带 visual schema | 不影响前台，预览时按 draft token 生效 | 不改变当前发布版本 | 回滚发布版本，不回滚真实主题 |
| AI 生成 | layout draft version，带 prompt/model meta | 只进入草稿，不能直接发布 | 不改变当前发布版本 | 丢弃草稿或回滚后续发布 |
| 发布草稿 | publish record，更新 asset `published_version_id` | 发布后前台解析命中 | 恢复旧 `published_version_id` 和旧版本状态 | 只能切回同一 asset/identity/scope 的版本 |
| 布局选择保存 | selection version 或 SystemConfig batch version | 解析器读取 option 时生效 | 保存失败不改变 effective option | 只恢复 option 指向，不改布局源码 |
| 定时计划保存 | schedule version/audit | 当前时间命中计划时生效 | 保存失败不参与解析 | 回滚或禁用计划，不改产品/分类原选择 |

## 验收项

- 默认产品布局可渲染。
- 产品专属虚拟布局优先渲染。
- 分类下产品默认布局可被商品详情页继承。
- 分类专属虚拟布局可渲染。
- 定时布局开始后生效。
- 定时布局结束后恢复到原有效布局。
- 源码编辑保存草稿、预览、发布可用。
- 可视化编辑锁定当前布局，不能误切其他页面。
- 锁定可视化编辑不能触发真实主题保存、发布、拖拽写入或配置写入，只允许当前虚拟布局 draft/AI 写入。
- AI 创建草稿可预览，不直接发布。
- 虚拟布局每次保存生成版本。
- 源码保存、可视化保存和 AI 生成都只生成 draft version，不能直接覆盖已发布版本。
- 源码保存失败、可视化保存失败、AI 生成失败不影响当前发布版本。
- 保存并发布必须分别返回 draft save 结果和 publish 结果；publish 失败时前台继续使用旧发布版本。
- 发布后可以立即回滚到上一发布版本。
- 后台可以从版本列表恢复任一属于当前 identity 的可发布历史版本。
- 布局选择保存生成独立选择版本，选择回滚只恢复 option 指向。
- 产品/分类后台源码编辑页保存后能显示版本列表，并能回滚到属于当前对象 identity 的历史版本。
- 回滚发布生成审计记录，且不会删除历史版本。
- 同一 option 发布新版本或回滚后，产品页和分类页缓存必须自动区分新旧版本。
- 同一对象可保存多条计划，过期高优先级计划不能覆盖当前有效计划。
- 预览 token 不能让未发布草稿绕过后台权限。
- 定时计划重叠时按优先级稳定解析。
- 并发保存或发布冲突能被识别并阻止误覆盖。
