# FPC 上收 Framework · Theme 旁路 · WLS 存储适配器

## 边界

| 职责 | 归属 |
|------|------|
| Extra `type=fpc`、旁路规则收集、Evaluator、Store 注册表、`FpcCapability` | **Framework** |
| 编辑器 / 预览 query·Cookie·env 旁路声明 | **Theme** `FpcBypassRuleProvider` |
| 传输层 bypass 头 + 默认 Store（L1+L2+外置）+ Worker 早路径 HIT | **WLS / Server** |
| 方法 / 登录态 / `no-store` / 静态 path 等 serve 策略 | **Coordinator**（非 BypassProvider） |

## 热路径

1. Worker 入口：`WorkerFullPageCacheFastPath` → `FpcBypassEvaluator`（只读侧车/内置回退，无 OM/Event）→ Store HIT 则出站。
2. App：`FullPageCacheCoordinator::isEditorOrPreviewRequest` 同源 Evaluator。
3. 失效：`FpcCapability` **只**调活跃 `FpcStoreAdapter`；无适配器则 no-op。无任何 Store 扩展时 `canServe`/`canBuild` 关闭 FPC。

## 扩展点

- `extends/module/Weline_Framework/Fpc/Bypass/{Name}.php`
- `extends/module/Weline_Framework/Fpc/Store/{Name}.php`（配置 `wls.fpc.store_adapter`，默认 `wls`）
- 升级观察者 `CollectFpcBypassRules` → `generated/framework/fpc_bypass_rules.php`

## 编辑器口径

预览/编辑器 **bypass**，禁止每次编辑 `purgeAll`。详见 Theme `preview-and-runtime-modes.md`「FPC 旁路」。
