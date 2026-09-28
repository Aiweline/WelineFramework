# FPC 上收 Framework · Theme 旁路 · WLS 存储适配器

## 边界

| 职责 | 归属 |
|------|------|
| Extra `type=fpc`、旁路规则收集、Evaluator、Store 注册表、`FpcCapability` | **Framework** |
| 编辑器 / 预览 query·Cookie·env 旁路声明 | **Theme** `FpcBypassRuleProvider` |
| **CDN Scope「开发模式」临时旁路 FPC**（SystemConfig + Provider `env_flags`；facts 由 Coordinator 按 Scope 注入） | **Weline_Cdn** Provider + Framework facts 组装（规格：`Weline_Cdn/doc/开发/spec/cdn-route-realtime-fpc-devmode.md`） |
| 传输层 bypass 头 + 默认 Store（L1+L2+外置）+ Worker 早路径 HIT | **WLS / Server** |
| 方法 / 登录态 / `no-store` / 静态 path 等 serve 策略 | **Coordinator**（非 BypassProvider） |

## 读策略主链（店面是否 FPC）

控制器 **`@Extra type=fpc enabled=…`** → `CollectControllerExtra`（`after_route_collection`）→ `controller_extra.php` → `ExtraPolicyResolver` → Coordinator。  
**禁止**用「仅有 CDN default-rules / 仅有 `@Cdn`」冒充已启用源站 FPC。

## 热路径

1. Worker 入口：`WorkerFullPageCacheFastPath` → `FpcBypassEvaluator`（只读侧车/内置回退，无 OM/Event）→ Store HIT 则出站。
2. App：`FullPageCacheCoordinator::isEditorOrPreviewRequest` 同源 Evaluator（含 Theme `editor_mode`、将来 CDN `cdn_fpc_dev_mode` 等 env_flags）。
3. 失效：`FpcCapability` **只**调活跃 `FpcStoreAdapter`；无适配器则 no-op。无任何 Store 扩展时 `canServe`/`canBuild` 关闭 FPC。

## 扩展点

- `extends/module/Weline_Framework/Fpc/Bypass/{Name}.php`
- `extends/module/Weline_Framework/Fpc/Store/{Name}.php`（配置 `wls.fpc.store_adapter`，默认 `wls`）
- 升级观察者 `CollectFpcBypassRules` → `generated/framework/fpc_bypass_rules.php`
- Extra：`CollectControllerExtra` → `generated/framework/controller_extra.php`

## 编辑器口径

预览/编辑器 **bypass**，禁止每次编辑 `purgeAll`。详见 Theme `preview-and-runtime-modes.md`「FPC 旁路」。  
CDN Scope 开发模式与编辑器旁路**分开关**，不得合并为一个全局 DEV 开关。
