# Team:架构师: 回帖 — 拼装是否系统级、为何布局表现不一

> 通道：`pm-arrange-probe.md`  
> 席位：Team:架构师:（只读探查，本波未改生产码）  
> 依据：通道已核实证据 + 源码 `ThemeLayoutEntitySlotFiller` / `LayoutSlotRenderer` / `RequiredDefaultInjectionStorefrontOverlay` / `ThemeLayoutEntityPublishedSlotHost`

---

## 对外结论（给用户）

**拼装是系统级统一管线，不是「每个布局一套算法」。**  
各布局看起来不一样，是因为：**同一套发布烘焙 → 运行时零补槽** 算法下，**每个 `layout_type` 的烘焙结果（页实体 / 槽内 HTML）不同**；店面已发布路径刻意**不再**做运行时 `fill` / `default_injections` overlay。  
若某页（如 `/products`）只剩模板 `slot-placeholder`、没有 `w-filters`，那不是「products 被特判跳过拼装」，而是：**该页交付壳已无 reactive marker，运行时假定壳已烘焙完备 → 直接 no-op，残留占位再也补不回来**。

---

## 1. 系统级拼装管线（发布烘焙 vs 运行时）

```text
┌─ 发布 / bake（重路径，允许写完备 HTML）────────────────────────────┐
│  publishBatch / bake coordinator                                          │
│    → 物化页实体 phtml（structure → r{release}）                           │
│    → ThemeLayoutEntitySlotFiller::fill（editor/preview/bake 等）          │
│         · includeEntityPhtml                                              │
│         · RequiredDefaultInjectionStorefrontOverlay::append               │
│           （按 pageType 读 JSON default_injections 计划 → 写入槽）         │
│         · spliceSolidifiedSlots 进壳                                      │
│    ※ renderPublishedSolidifiedFragments 明确：只 include + chrome 投影，  │
│      「no splice / required overlay」——运行时碎片路径不跑 overlay。        │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼  已发布店面（wave8-8s…8s4 定案）
┌─ 运行时（真零补槽）──────────────────────────────────────────────────────┐
│  ControllerFetchFileBefore                                                │
│    → ThemeLayoutEntityPublishedSlotHost::primeStorefront                  │
│         · loadFragments ← renderPublishedSolidifiedFragments              │
│         · CTX_USE_REACTIVE=false（bake 可用时）                           │
│  Taglib w:slot                                                            │
│    → publishedInner(slotId, defaultHtml)：优先 bake 槽内；否则模板 default │
│    → 交付壳无 data-wslot（published wrapper）                             │
│  LayoutSlotRenderer（fetch_file_after）                                   │
│    → shouldForcePublishedZeroRuntimeFill() == true                        │
│         · strip markers + **跳过 fill**（不按 layout_type 分支）            │
│  若仍误入 fill：                                                          │
│    ThemeLayoutEntitySlotFiller::fill                                      │
│         · published 且无 data-wslot / widget-slot-area → **直接 return**   │
│           （假定壳已烘焙；overlay 也不再跑）                               │
└───────────────────────────────────────────────────────────────────────────┘
```

| 阶段 | 系统职责 | `default_injections` / overlay |
|------|----------|--------------------------------|
| 发布 bake / fill 重路径 | 把跨模块部件写进实体/壳 | **会跑** `RequiredDefaultInjectionStorefrontOverlay` |
| 已发布店面 | `PublishedSlotHost` 投 bake + `shouldForcePublishedZeroRuntimeFill` 禁 fill | **不跑**（设计如此） |

引用钉点：

- `ThemeLayoutEntitySlotFiller::fill` wave8-8s：published 且无 `data-wslot` → no-op（通道证据 #5）。
- `LayoutSlotRenderer::shouldForcePublishedZeroRuntimeFill`：frontend + 非 editor/preview → strip + 跳过 fill（通道证据 #6）。
- `renderPublishedSolidifiedFragments` 注释：非 shell fill，无 chrome inject / splice / required overlay。
- Overlay 仅挂在 `SlotFiller::fill` / `fillRequiredDefaultsOnShell` 等重路径上。

---

## 2. 算法特判还是「统一算法 + 每页烘焙不同」？

**结论：统一算法 + 每页烘焙结果不同。不是按 `layout_type` 分叉拼装引擎。**

| 点 | 判定 |
|----|------|
| `shouldForcePublishedZeroRuntimeFill` | 只认「是否 editor_canvas / editor_mode / preview Token」，**不读** `layout_type` |
| `fill` 早退 | 只认 published + 有无 reactive marker，**不读** layout 名 |
| `PublishedSlotHost::primeStorefront` | 用 `theme_id` + `layout_type` **选哪份页实体**，算法本身相同 |
| `RequiredDefaultInjectionStorefrontOverlay` | **统一** Inventory → Planner → `executeOne`；计划项按 JSON 里声明的 `layout_type` **过滤目标**，不是引擎 if/else 特判 products |
| 仅有的局部启发式 | overlay 内 `content` 槽保留 `homepage-*` 嵌套（防首页被整区替换）——属注入安全细节，**不是**「某布局不做拼装」 |

因此：homepage / category / products **走同一条系统管线**；差异来自：

1. **各 layout 的 published 页实体 bake HTML 不同**（槽内是否已有 Filters 等）；
2. **各 layout 的模板 default（含故意留的 `slot-placeholder`）不同**；
3. **`default_injections` 计划按 layout_type 声明不同槽**（如 products→`list-filters`，category→`category-filters`）——声明分叉，算法不分叉。

用户感知的「每一个布局都不一样」= **烘焙完备度不一致在零补槽门禁下被放大**，不是「系统没做拼装」。

---

## 3. products 占位残留 — 一句话根因

**根因：已发布零运行时补槽假定「壳/bake 已含 required 注入」；products 交付 HTML 仍是模板 `list-filters` 占位且无 `data-wslot`，fill/overlay 被系统级跳过，占位无法在店面自愈。**

（与通道证据对齐：实体结构/CLI 可渲 ≠ 店面零补槽路径会再注一遍；历史日志亦曾点名 products 发布/主题绑定漏 bake，属同一类「烘焙结果缺口」。）

---

## 4. 建议修复落点（只建议，不写码）

**优先：系统级一处修，禁止逐布局打补丁。**

| 优先级 | 落点 | 理由 |
|--------|------|------|
| **P0（推荐）** | **发布 bake / 物化完备性**：保证写入 `r{release}` 的页 HTML（及 `renderPublishedSolidifiedFragments` 所 include 的内容）在 **bake 时**已跑过与 `fill` 等价的 required overlay（或等价地把 Filters 节点固化进 `list-filters` 等槽） | 与 wave8-8s「店面零 fill」契约一致；修一处，所有 layout_type 受益 |
| **P0 验收闸** | 发布后契约：对每个有 required `default_injections` 的 `layout_type`，店面 HTML 抽检槽内须有目标 `data-widget-code` / 部件根类，**禁止**仅余 `slot-placeholder` | 防止再漏 products 一类页 |
| **P1（慎用）** | 运行时「占位自愈」安全网（无 marker 仍 overlay） | 与 8s4 强制零补槽目标冲突，易回 584ms fill / marker 泄漏；仅作临时热修且须带明确退出条件 |
| **不建议** | 只改 products 模板文案、或只给 products 开特例 fill | 掩盖系统契约缺口，必然再现于下一 layout |

**架构裁定：** 拼装责任在系统；表现差异来自烘焙结果。修复应落在「发布物化必须满足 required 注入完备」，而不是「每个布局单独修店面」。

---

## 5. 给项目经理

本席探查结论已落盘本文件。请：

1. 汇收部件席（default_injections XOR / 是否有布局特判）与主题席（bake 何时写壳、products 为何无 marker 仍占位）回帖；
2. 更新 SESSION `published-slot-assembly-uniformity.md`：`plan_arch` → 完成，记下 P0「bake 完备 + 发布闸」；
3. 勿在本波改生产码，待三席对齐后再排施工席。

notify_pm: true @项目经理：本席已交付/上报，请检查并更新 SESSION
