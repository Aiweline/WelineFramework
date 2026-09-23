# Team:主题开发工程师: 探查回帖 — published bake / zero-runtime-fill

> work_mode：`theme_module_runtime`（只读探查，本波禁改生产码）  
> 通道：`pm-arrange-probe.md`  
> notify_pm: true @项目经理：本席已交付/上报，请检查并更新 SESSION

---

## 结论（先给 PM）

拼装**是**系统级同一管线，**不是**「每个布局一套算法」。  
products 店面仍见 `products-layout__placeholder`，是因为 **publish 只烘焙实体槽位碎片，不烘焙整页壳**；店面假定「壳已烘焙」仅在请求期 `PublishedSlotHost::publishedInner` 投影成功时成立。wave8-8s4 **强制 zero-runtime-fill** 关掉了 reactive 时的 `fill`/`splice` 安全网——投影失败时占位会原样出站。

---

## 1. 发布店面假定「壳已烘焙」是否成立于 products？

**不成立（作为 publish 后验 / 交付后验）。**

| 层 | products 现状 | 含义 |
|---|---|---|
| 实体 `…/b5e7a8f02e3b88a9/r279` | `structure.json` 有 `list-filters` → `Weline_Filters::category-filters`；`layout.phtml` 有 `WidgetRenderer::render(…)`；`current.json` → `r279` | **实体烘焙完整**（槽碎片级） |
| 模块壳 `Product/.../layouts/products/default.phtml` | `w:slot id="list-filters"` 体内故意留 `products-layout__placeholder` | **壳默认仍是占位文案**，publish 不会改这份模板 |
| 店面响应 | 无 `data-wslot` / `w-filters` / `theme-layout-entity-slot`，仅占位 | **请求期未把实体 inner 投影进壳**（或投影回落 default） |

权威设计（wave8-8s）：

- `ThemeLayoutEntityMaterializer::materializePage`：**只**写 content nodes → 按槽 `layout.phtml`（非整页壳）。
- 「壳已烘焙」= 店面 `w:slot` 走 `useReactiveMarkers()===false` 时，`publishedInner(slotId, default)` 用 fragments 的 bake inner **替换** 壳 default。
- `ThemeLayoutEntitySlotFiller::fill` early return（无 `data-wslot` / `widget-slot-area`）与 `LayoutSlotRenderer::shouldForcePublishedZeroRuntimeFill` 都是在**假定上述投影已完成**后的 no-op/硬跳过，不是「publish 已保证壳满」。

对本席：products 上「壳已烘焙」是 **运行时后验**，不是 **发布后验**。当前证据表明后验失败。

---

## 2. 为何实体有 widget、响应仍是占位？（断点）

### 管线（同一算法）

```
publish/materialize → 实体 r*/layout.phtml + structure.json
        ↓
ControllerFetchFileBefore::primeStorefront → loadFragments(include 实体)
        ↓
壳 w:slot：useReactiveMarkers?
   false → theme-published-slot + publishedInner(bake∥default)
   true  → data-wslot + 壳 default（占位）
        ↓
LayoutSlotRenderer（frontend 非 editor/preview）：
   shouldForcePublishedZeroRuntimeFill → strip + return（不 fill/splice）
```

### 断点判定（按优先级）

1. **Host / publishedInner（主断点）**  
   - `bakeInner === null` → 直接 `sanitize(defaultHtml)` → **占位存活**。  
   - 实体有 widget 只证明 `structure`/`layout.phtml` 在磁盘上；**不等于** fragments 已成功 include，也不等于 `extractSlotInner(page_html, list-filters)` 命中。  
   - 线上无 `theme-layout-entity-slot`：与「未注入 bake inner」一致（bake 包装类名是 `theme-layout-entity-slot`；壳 published 包装是 `theme-published-slot`）。

2. **zero-runtime-fill / strip（锁死断点）**  
   - 8s4：`shouldForcePublishedZeroRuntimeFill()` 在 frontend 几乎恒 true → **强制** `SlotBoundaryMarkers::strip` + **跳过** `fill`/`spliceSolidifiedSlots`。  
   - 即便 Host 因 miss 走 reactive（本应靠 fill 把实体 splice 进壳），**安全网已被切断**。  
   - strip 只剥 `<!--@weline-slot-->` 与 `data-wslot*`，**不删** `products-layout__placeholder`。

3. **fill early return（次断点，同假定）**  
   - `fill()`：published 且无 `data-wslot` → 原样 return。  
   - 与 zero-fill 同一哲学：默认壳里已经有 bake。products 实际没有 → 两处都「正确 no-op」地放过脏壳。

4. **splice（本路径未执行）**  
   - `spliceSolidifiedSlots` 只在 `fill()` 重路径里跑。published 店面被 8s4 跳过 → **splice 不是本波失败步，而是被门禁关掉的愈合步**。

5. **solidify（实体 include）**  
   - 本机 r279 `layout.phtml` 形态正常；PM 已核实 CLI WidgetRenderer 可出 `w-filters`。  
   - 若线上/请求期 `includeEntityPhtml` 瞬时失败：8s4 起不再 sticky-miss，但 **仍强制 skip fill** → 仍可能只剩壳占位。需用 `CTX_ZERO_FILL_REASON` / `CTX_PRIME_TRANSIENT_ERROR` 观测（本波未改码、未打生产）。

**一句话**：断在 **Host 投影未把 bake 写入壳**，再被 **zero-runtime-fill 禁止 splice 补救**；不是 Filters 没进实体，也不是「占位组件」本身。

---

## 3. 「每个布局算法不同」还是「同一算法、烘焙完整度不同」？

**同一算法；差异来自烘焙/投影完整度与壳 default 可见性。**

- 全布局共用：`materialize` → `primeStorefront` → `publishedInner` → `zero-runtime-fill`。  
- 无 per-layout XOR 分支（主题席范围内未见 products 特判算法）。  
- 观感差来自：  
  - 某 layout 的实体槽是否齐全、prime/`pageType` 是否对上；  
  - `publishedInner` 是否对该槽拿到非空 bake；  
  - 壳 default：**products 故意大声占位**；其它布局可能空 body / 弱占位 → 失败时「看起来不一样」。  
- homepage 等若偶发正常：是 **投影成功** 的同管线样本，不是另一套拼装器。

---

## 4. 线上壳仍含 `products-layout__placeholder` 的含义

- **不是** Filters 渲染失败的替代 UI。  
- **是** `products/default.phtml` 里写给编辑器/空槽的 **故意 default**（注释已写：筛选由 default_injections 注入）。  
- 出现在公网 HTML = **系统未用实体 bake 覆盖该槽 default**（外加 strip 清掉 reactive 痕迹后，更像「干净的空壳占位」）。

---

## 5. 系统级修复建议（门禁，不写码）

1. **publish 门禁**  
   - 发布后验：对每个声明槽（含 default_injections 目标如 `list-filters`）断言实体 `structure` + `layout.phtml` 含对应 `widget_code`/`node_uid`；失败禁止标 published。  
   - 明确文档：publish ≠ 壳 HTML 已含部件；壳投影是 storefront 契约。

2. **solidify / Host 门禁**  
   - `primeStorefront` 失败或 `publishedInner` 回落 default 时：对 **required / default_injection 槽** 记硬失败或降级到可观测告警（禁止静默占位出站）。  
   - 抽检：响应内 required 槽不得残留 `slot-placeholder` / `data-placeholder`（至少 inventory 页）。

3. **fill / zero-runtime-fill 门禁（关键）**  
   - 「强制 skip fill」仅当可证明：**required 槽已无占位且 bake 已投影**（例如存在 `data-widget-code` / `theme-layout-entity-slot`，或显式 empty-OK 白名单）。  
   - 否则保留 **窄安全网**：仅对缺 bake 的 required 槽走一次 `splice`/`fillRequiredDefaultsOnShell`，避免 8s4「为杀 40× data-wslot」误杀愈合。  
   - 契约 UT：模拟「实体有 category-filters + 壳有 placeholder + force zero-fill」→ 必须 FAIL 当前行为并钉修复后 PASS。

4. **观测**  
   - 店面落 `CTX_ZERO_FILL_REASON` / prime 成败 / `list-filters` bake hit|miss 结构化日志，便于区分「实体缺」vs「投影 miss」vs「强制 skip」。

---

## 本席证据锚点（只读）

- `ThemeLayoutEntitySlotFiller::fill` L104–111：published 无 `data-wslot` → return。  
- `LayoutSlotRenderer` L169–227 + `shouldForcePublishedZeroRuntimeFill`：强制 strip + skip fill。  
- `ThemeLayoutEntityPublishedSlotHost::publishedInner`：`bakeInner===null` → default（占位）。  
- `ThemeLayoutEntityMaterializer::materializePage`：content nodes only → 槽碎片 phtml。  
- 本机实体：`var/runtime/theme-layout-entities/1/.../b5e7a8f02e3b88a9/r279/{structure.json,layout.phtml}`。  
- 壳源：`Weline/Product/view/theme/frontend/layouts/products/default.phtml` L130–140。

---

## @项目经理

notify_pm: **true**  
请更新 SESSION `published-slot-assembly-uniformity`：主题席探查完成；判定为 **同一系统管线 + 壳投影/零补槽门禁缺口**，非布局分叉算法。后续修码需另开波次（本席本波只读）。
