# Team:部件开发工程师: 回帖 — default_injections 是否系统统一 / 为何布局表现不一

- 席位：Team:部件开发工程师:
- 波次：只读探查（本波禁止改生产码）
- 对应安排：`pm-arrange-probe.md`
- notify_pm: true
- @项目经理：本席已交付/上报，请检查并更新 SESSION

---

## 结论总览

1. **default_injections 是系统级统一注入**，不是各布局各自为政写死的拼装逻辑。
2. products vs category 的声明差异**主要是目标槽名不同**（同一部件 code）；算法同一条 plan 链。
3. 店面看到的「筛选器区域 - 由 Filters 部件默认注入」**是布局空槽占位文案**，预期由布局模板承载；**不是** Filters 部件本体责任。
4. 本波可见不一致（占位仍在、无 `w-filters`）应落 **Theme 发布壳拼装 / fill / zero-runtime-fill**，**不要**改 Filters 部件声明或模板当根因修复。

---

## 1. default_injections：系统统一还是布局各自为政？

**答：系统统一。**

| 层 | 职责 | 证据 |
|---|---|---|
| 部件 JSON | 按 `layout_type` 声明「哪一页型 → 哪一槽」 | `Weline_Filters` `widget.php` / `@widget.default_injections` |
| `WidgetDefaultInjectionService` | `collectDeclarations` 扫 Catalog，按 **pageType + identity** 归一化；`required` 自动/对账安装 | 无 per-layout 硬编码分支；`normalizeInjection` 用 `layout_type` 过滤 |
| `RequiredDefaultInjectionContract` | `requiredForPage($declarations, $pageType)` 统一 plan；`merge` / `requiredTargets` 同契约 | UT 锁 products→`list-filters`、category→`category-filters` |

同模块 XOR / 跨模块只走 JSON（Theme开发总指南 §部件放置）：

- Filters 是**跨模块**进 Theme/hanfu 布局 → **禁止**布局内嵌 `<w:widget name="category-filters"/>`；**只能** JSON `default_injections` + 空 `<w:slot>`。
- 布局注释与实现一致：「仅 slot + 预览占位；筛选由 Weline_Filters … 经 default_injections 注入」。

**「布局不一样」从部件席看**：不是注入算法按布局分叉，而是：

1. **声明层**：不同 `pageType` 映射不同 **slot id**（见下节）；
2. **表现层**：发布壳是否把节点烤进对应槽、运行时 fill 是否被 zero-runtime 跳过（主题席管线）——部件声明本身两边都是 `required=true` 同一 code。

---

## 2. products vs category 声明差异

**同一部件** `code=category-filters` / `Weline_Filters`，两行 required 注入：

| layout_type | slot | area | required |
|---|---|---|---|
| `category` | `category-filters` | sidebar | true |
| `products` | `list-filters` | sidebar | true |

布局空槽亦对齐：

- `layouts/category/default.phtml` → `<w:slot id="category-filters" …>`
- `layouts/products/default.phtml` → `<w:slot id="list-filters" …>`

契约锁死：`RequiredDefaultInjectionContractTest` — products plan 的 `slot_id=list-filters`、`widget_code=category-filters`；category plan 落 `category-filters` 槽。

**不仅是「文案不同」**：槽 **id 命名**不同（布局历史命名：`list-filters` vs `category-filters`），但 **部件身份相同**。无第二套 Filters 部件、无 products 专用 PHP 渲染分支。

### 有无 products 特例代码？

- **Filters**：除上述第二条 `default_injections`（换 slot）外，**无** `products` 专用服务/模板分叉。
- **WidgetDefaultInjectionService / RequiredDefaultInjectionContract**：按 `pageType` 字符串过滤声明，**无** `if ($pageType === 'products')` 特例路径。
- 父会话实体「products 结构已有 **category-filters**」若指 **slot_id** 写成 `category-filters`，则与声明目标 `list-filters` **不一致**——属 **发布实体/烘焙槽位错位或旧数据**，不是 Filters JSON 对 products 声明成 `category-filters`。本席声明源真相仍是 `list-filters`。

---

## 3. 占位文案是否部件责任？

**否。预期由布局（Theme / design 布局模板）承载。**

- 占位在布局 `<w:slot>` 内：`slot-placeholder` +「…由 Filters 部件默认注入」。
- 用途：编辑器/未注入时的空槽预览；**注入成功后应由部件 HTML（如 `w-filters`）替换/盖住**，店面不应长期只剩占位。
- Filters 部件模板责任：筛面板本体（`category-filters.phtml`），**不**负责输出该布局占位句。

因此：线上 `/products`「仅占位」= **拼装未把部件落到 `list-filters` 槽（或 fill 被跳过）**，不是 Filters 故意渲染占位。

---

## 4. 修复应落何处？

| 候选 | 本席判定 |
|---|---|
| **Theme 系统拼装**（publish bake、实体 slot 对齐 `list-filters`、published fill / zero-runtime-fill、壳 marker） | **应落这里** — 声明与槽契约已齐；表现缺口在发布壳与运行时填槽 |
| **Filters 部件**（改 JSON / 改模板） | **本波不应当根因修复** — 双页型 required 声明已正确；乱改槽名会破坏契约与 UT |

建议项目经理：主题席核对「products 实体节点 slot 是否为 `list-filters`」与「published 无 `data-wslot` 时 no-op」；部件席可在后续施工波协助复验声明/槽 accept，但**不**用 Filters 补丁绕过系统 fill。

---

## 5. 对本问的直接回答（用户原话）

> 拼装不是系统级处理的吗？为什么某一个/每一个布局都不一样？

- **注入计划是系统级的**（Catalog → DefaultInjectionService → RequiredDefaultInjectionContract，按 pageType 统一 plan）。
- **布局「看起来不一样」**：① 槽名本来不同（`list-filters` / `category-filters`）；② 某一页型发布壳未烤进 / fill 跳过 → 只剩布局占位，看起来像「这一页没走系统」——实为 **烘焙/填槽结果分叉**，不是 default_injections 算法按布局各自为政。

---

## 本席交付勾选

- [x] Filters `default_injections`（category + products）已核
- [x] WidgetDefaultInjectionService / RequiredDefaultInjectionContract 按 pageType 统一 plan 已核
- [x] 同模块 XOR / 跨模块只走 JSON 对齐总指南
- [x] 无 Filters 侧 products 渲染特例（仅 slot 声明行）
- [x] notify_pm: true @项目经理
