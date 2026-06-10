# SystemConfig 与 Theme 虚拟布局总计划

> 状态：规划中
> 配置模块子计划：[SystemConfig Scope 配置树计划](./scope-config-tree-plan.md)
> Theme 子计划：[Theme 虚拟布局与产品/分类布局计划](../../Theme/doc/virtual-layout-scope-plan.md)

## 总目标

用 `Weline_SystemConfig` 统一配置入口和 scope 读取规则，用 `Weline_Theme` 承载虚拟布局、布局版本、AI 生成、源码编辑和可视化编辑。产品、分类、店铺、站点只提供身份和 scope 上下文，不把布局业务逻辑塞进模板。

版本保存是本计划的硬要求，不是增强项：

- SystemConfig 的后台配置保存必须生成配置批次版本。
- Theme 的虚拟布局源码、可视化编辑和 AI 草稿保存必须生成布局版本。
- 保存失败不能污染当前有效配置或当前已发布布局。
- 保存失败不生成已应用版本，不需要靠回滚修复；旧配置或旧发布版本必须继续生效，后台返回可解释错误。
- 保存成功必须返回可追踪版本 ID，并在后台提供立即回滚入口。
- 保存成功但内容错误时，管理员必须能从最近版本或版本列表立即回滚到上一个有效版本。
- 回滚本身也要记录 actor、时间、原因、前后版本和冲突结果，不能无审计地改历史状态。

## 模块边界

| 模块 | 职责 |
| --- | --- |
| `Weline_Framework` | 提供请求级 `ScopeContext`，维护最多三段 scope |
| `Weline_Websites` | 识别站点后设置 scope 第 1 段 |
| `WeShop_Store` | 识别店铺后设置 scope 第 2 段 |
| `Weline_SystemConfig` | 统一配置值、配置模板、scope fallback、配置树后台、全局 scope 切换、模块/配置搜索、保存、校验、`w_query('system_config', ...)` |
| `Weline_Theme` | 管理主题布局、虚拟布局、布局版本、AI 布局生成、锁定页面类型的可视化编辑；只通过 PHTML 配置模板或 adapter 接入 SystemConfig |
| `WeShop_Product` / `WeShop_Catalog` | 提供产品、分类、分类下产品默认布局的身份标记和后台入口；不实现配置保存体系 |

## 总体数据流

1. 请求进入后，Framework 创建请求级 `ScopeContext`。
2. `Weline_Websites` 根据当前站点设置 website scope 段。
3. `WeShop_Store` 根据当前店铺设置 store scope 段。
4. 商品详情页或分类页渲染时，业务模块提供身份：
   - `layout_type=product|category`
   - `target_type=product|category|category_product_default|global`
   - `target_id`
5. Theme 解析当前有效 layout option。
6. Theme 读取虚拟布局资产或主题文件布局。
7. 需要普通配置时，Theme 和业务模块统一通过 `SystemConfig` 读取 scope 配置。

## 优先级规则

产品详情页布局优先级：

```text
产品专属布局
分类下产品默认布局
全局产品布局
主题文件默认产品布局
```

分类页布局优先级：

```text
分类专属布局
全局分类布局
主题文件默认分类布局
```

scope 优先级由 `SystemConfig` 和 Framework fallback resolver 统一提供。Theme 不再自己识别站点或店铺。

## 定时布局策略

定时计划只改变“当前有效 option”，不能把业务逻辑写进布局模板。

活动开始：

- 激活计划指向活动 layout option。
- 不覆盖原产品/分类专属布局值。
- 记录活动前有效 layout option 和来源。

活动结束：

- 删除或失效活动计划。
- 自动恢复到活动前来源。
- 如果活动期间运营手动改了产品/分类布局，以手动修改后的有效配置为准，不用过期计划覆盖。
- 多个计划重叠时按 priority 和更新时间稳定选择有效计划。
- 前台解析按当前时间动态判断计划，不能依赖把活动 option 写回产品/分类配置。

这能避免 `activateSchedule()` 把活动布局写进产品专属布局后，活动结束无法回到之前产品布局的问题。

## 已发现缺陷与补齐清单

这些问题必须纳入实现验收，不能只停留在文档设计：

| 缺陷 | 影响 | 计划内修正 |
| --- | --- | --- |
| 旧 `activateSchedule()` 可能把活动 layout option 写入产品专属布局 | 活动结束后无法自动回到之前布局 | 定时计划只参与解析有效 option，结束后由解析器重新走产品、分类、全局、文件 fallback |
| 定时计划如果只靠 cron 改 `status` | cron 失败或状态滞后时前台命中错误布局 | 前台解析按当前时间、scope、priority 动态计算有效计划 |
| 产品/分类模块自己保存 layout 文件或 layout 表 | Theme、SystemConfig、scope、版本体系被绕开 | 自定义布局源码和版本迁移到 Theme 虚拟布局资产；产品/分类只保存 identity 选择 |
| 分类下产品默认布局没有清晰来源解释 | 多分类商品可能命中不可解释 | 解析器返回 source、matched_category_id、source_scope、schedule_id 和 fallback_chain |
| scope 由模块拼接 | 站点、店铺、预览上下文容易不一致 | Framework 提供请求级 `ScopeContext`，`Weline_Websites` 和 `WeShop_Store` 只负责设置段 |
| 配置模板如果靠运行时扫描 | 性能和启停一致性不可控 | 通过 Extends 注册收集 PHTML 配置模板，不做 Web 运行时全仓扫描 |
| 保存失败或并发保存没有版本保护 | 半批次配置、误覆盖和无法快速恢复 | SystemConfig 批次版本 + base version 冲突检测 + 失败事务回滚 + 成功后立即回滚 |
| 保存成功但内容错误没有恢复入口 | 运营误保存后只能人工找历史或改库 | 后台保留最近版本和历史版本列表，支持一键回滚到可用版本 |
| 虚拟布局源码、可视化、AI 草稿没有统一版本 | 不同编辑方式互相覆盖 | Theme 统一 layout asset version，发布只切 `published_version_id` |
| 可视化编辑可切换到其他页面 | 可能误改真实主题或其他布局 | 可视化编辑锁定 layout type、option、scope、target 和 asset |
| 预览没有绑定草稿和权限 | 未发布草稿可能被公网访问 | 预览绑定 draft version、scope、target、后台权限或短期 token |
| `w_query` 无法解释布局命中 | 后台和调试无法查有效布局数据 | Theme/SystemConfig 增加只读查询能力，返回有效 option、来源、版本和 fallback |
| 后台引用不存在的产品分类类 | 产品后台可能抛 `Class "WeShop\Product\Model\Category" does not exist` | 产品模块使用 Catalog 分类契约或 query provider，不直接反射不存在类 |
| 产品/分类页 payload 缓存只按 option 区分 | 同一 option 保存新版本或回滚后可能继续返回旧 HTML | 缓存键必须纳入 Theme virtual layout asset/version 运行时指纹 |
| 创建多条布局计划时复用模型对象 | 后台连续创建计划可能返回错误 ID，甚至误判多计划能力失败 | 创建计划前清空模型状态，保存后返回独立对象；多计划按 priority/time 动态解析 |
| 后台源码编辑只保存源码但不展示版本历史 | 运营保存错误后无法立即回滚，必须靠人工找历史 | 产品/分类后台只传 identity，由 Theme version 列表展示版本，并调用 Theme rollback 发布新回滚版本 |
| 可视化锁定模式仍可能触发真实主题写入接口 | 锁了下拉框但拖拽、配置保存、发布仍可能误改真实主题 | ThemeEditor 锁定模式禁用真实主题保存/发布，并在统一 API 层拦截非 `virtual-theme` 写接口 |
| 回滚、发布和计划切换后缓存未带版本指纹 | 旧 payload 可能遮住新发布或回滚后的布局 | 产品/分类页 payload cache key 纳入 layout source、selection version、schedule id、virtual asset/version 指纹 |

## 配置系统原则

- 统一入口使用 `Weline_SystemConfig`。
- 适合 key/value 的模块配置写入 `system_config`。
- 配置模板由模块通过 `extends/module/Weline_SystemConfig/Config/{area}/{code}.phtml` 提供。
- 模块只提供 PHTML 配置模板；scope 切换、模块搜索、配置搜索、继承开关、保存和校验都由 SystemConfig 配置中心完成。
- 后台配置保存生成版本批次，保存失败整体回滚，保存成功可立即按版本回滚。
- 配置中心需要 ACL、审计、敏感值脱敏和并发版本冲突检测。
- PHTML 配置模板解析模式不能执行副作用逻辑，渲染模式只允许只读查询。
- 复杂对象保留模块业务表，通过 adapter 接入配置树。
- `env.php` 继续承载启动级、部署级、密钥级配置。
- 敏感配置必须 encrypted、secret_ref 或 env locked。

## Theme 原则

- 虚拟布局由 Theme 模块支持。
- 产品和分类不直接实现布局存储，只管理 Theme scope 资产。
- 身份靠标签属性提供，scope 靠 Framework `ScopeContext` 提供。
- 可视化编辑支持锁定页面类型、layout option、scope、target，只编辑当前虚拟布局。
- AI 创建布局复用 Theme/Widget 的 AI 生成思路：先生成草稿，再预览，再发布版本。
- 虚拟布局保存必须生成版本，发布只更新 `published_version_id`。
- 发布后可以回滚到上一发布版本，回滚本身需要记录审计。
- 预览必须绑定草稿版本、scope、target 和权限 token，不能让未发布草稿绕过后台权限。

## 阶段计划

### 阶段一：Framework Scope 与 SystemConfig v2

- 新增请求级 `ScopeContext`。
- `Weline_Websites` 和 `WeShop_Store` 写入 scope 段。
- 升级 `system_config` 表和 API。
- 增加 Extends 配置模板注册、PHTML 模板解析器、配置树、scope fallback、来源解释。
- 模块配置模板只声明本模块配置，复杂对象通过 `<w:config:adapter>` 进入配置树，不处理保存和 scope 写入。
- 后台配置中心支持全局、站点、店铺 scope 选择，支持模块搜索和模块锁定。
- 后台保存使用显式选择的 scope，不使用后台请求的隐式 runtime scope。
- 后台保存支持版本批次、事务回滚、立即回滚、ACL、审计和并发冲突检测。
- 扩展 `w_query('system_config', ...)`。
- 明确保存失败恢复策略：同批次任一 key 失败时回滚全批次，不生成已应用版本；保存成功后保留 `version_id`，管理员可立即回滚。

### 阶段二：Theme 虚拟布局基础

- Theme 新增虚拟布局资产和版本模型。
- 支持源码编辑、预览、发布。
- 支持发布记录、上一版本回滚、预览 token、安全校验和并发发布冲突检测。
- 支持 layout type、layout option、target identity、scope。
- 现有主题文件布局作为 fallback。
- 支持保存版本恢复策略：草稿保存失败不影响已发布版本；发布失败保持旧 `published_version_id`；发布成功但内容错误时可立即回滚到上一发布版本或版本列表中的可用版本。

### 阶段三：产品/分类布局接入

- 产品后台和分类后台改成 Theme 布局资产管理入口。
- 产品详情页接入产品专属布局和分类下产品默认布局。
- 分类页接入分类专属布局。
- 定时计划只影响有效 option，不写回产品专属布局。
- 后台源码编辑页展示 Theme 虚拟布局版本历史，保存后留在编辑页，可立即回滚。
- 修复产品后台对分类模型的错误引用，避免反射不存在的 `WeShop\Product\Model\Category`。

### 阶段四：可视化编辑和 AI 创建

- Theme 可视化编辑器支持锁定布局上下文。
- 锁定模式下禁止真实主题保存/发布/切换，并只允许 VirtualTheme draft/AI 接口写入当前虚拟布局身份。
- AI 根据虚拟布局标签和页面类型生成布局草稿。
- 草稿支持源码编辑、可视化编辑、预览、发布。

### 阶段五：实测验收

- 后台配置树按模块、area、scope 筛选。
- 后台配置中心可选择全局、站点、店铺配置，并显示继承来源和覆盖状态。
- 模块配置可搜索并锁定，保存时只允许写当前模块定义内的 key。
- 商品详情页默认布局、自定义布局、分类默认产品布局、定时布局全链路浏览器验证。
- 分类页默认布局、自定义布局、定时布局浏览器验证。
- 活动结束恢复策略浏览器验证。
- SystemConfig 保存失败整体回滚、保存成功立即回滚、并发冲突、ACL 和审计验证。
- 虚拟布局源码/可视化/AI 草稿版本保存、发布、预览 token 和发布回滚验证。

## 不做的事

- 不新增平行 `ScopedConfig` 系统。
- 不把支付、搜索、布局版本等复杂业务对象强行塞进 `system_config`。
- 不在 layout 模板内写促销、定时、产品选择等业务逻辑。
- 不让 Theme 自己识别站点或店铺 scope。
