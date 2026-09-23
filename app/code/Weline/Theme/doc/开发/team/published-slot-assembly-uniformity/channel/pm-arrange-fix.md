# PM 安排：施工 — 发布槽拼装系统级修复

## 目标
店面 `/products`（及同类 listing）required 槽不得只剩 `slot-placeholder`；须出现 Filters `w-filters` / `storefront-filters-panel`。

## 已钉死根因（探查 + 父会话复核）
1. 拼装是系统级统一管线；非逐布局算法。
2. 线上 HTML **无** `theme-published-slot` → 当时 `useReactiveMarkers()===true`（bake 投影未用上）。
3. `LayoutSlotRenderer` wave8-8s4 **强制** `shouldForcePublishedZeroRuntimeFill` → strip + **跳过 fill**，即使 `CTX_USE_REACTIVE===true`（注释写明故意不顾 CTX，避免 584ms fill）。
4. 结果：reactive 壳上的占位被 strip 掉 marker 后原样出站，安全网被切断。
5. 实体 `theme1 …/b5e7a8f02e3b88a9/r279` 已有 `category-filters`→`list-filters`；CLI WidgetRenderer 可渲。

## 施工落点（Theme · theme_module_runtime）
**禁止**改 Filters 部件 JSON/模板当根因；**禁止**只改 products 布局文案。

### P0 实现
在 `LayoutSlotRenderer` 强制 zero-fill 分支：

- **仅当**可证明 required 槽已无占位 / bake 已投影（例如无 `slot-placeholder`/`data-placeholder` 于 inventory required 槽，或已有对应 `data-widget-code`/`w-filters`）时，才 strip+skip fill。
- **否则**窄安全网：走既有 `fill` / `fillRequiredDefaultsOnShell` / entity splice（重路径），再 strip outbound markers。
- 保持 editor/preview 不受影响；勿恢复「全页 40× data-wslot 常态」。

### 契约 UT
新增/扩展契约：模拟「壳有 list-filters 占位 + force zero-fill 条件 + 实体可渲 Filters」→ 出站 HTML 必须含筛选面板根，且不得仅余 `data-placeholder="list-filters"`。

同步核对/更新：
- `PublishedStorefrontForcedZeroFillContractTest`（门禁语义变更须对齐，禁止「永远 skip」硬断言若与新语义冲突）
- Theme `etc/module.php` 升版 + 开发日志一行

### 验收
本机 `https://p05113ef3.test.weline.com:9555/products`（及 `/categories`）侧栏出现 `storefront-filters-panel` / `w-filters`，无 Filters 占位句。

## 回帖
写 `channel/theme-fix-done.md`，含改文件列表、UT 结果、Browser/curl 证据。
notify_pm: true @项目经理：本席已交付/上报，请检查并更新 SESSION

work_mode: theme_module_runtime
