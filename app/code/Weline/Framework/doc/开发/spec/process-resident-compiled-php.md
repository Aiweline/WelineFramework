# 规格：进程常驻编译 PHP 门闩（CompiledPhpRegistry）

```yaml
slug: process-resident-compiled-php
module: Weline_Framework
work_kind: feature
fe_be_scope: backend_only
ui_skill: skip
status: acceptance_passed
team: dev/team/process-resident-compiled-php/
contracts: dev/team/process-resident-compiled-php/contracts.md
session: dev/session/process-resident-compiled-php.md
module_version_note: 本波未 bump etc/module.php（无 Schema/路由/注册面；磁盘 2.5.412）
```

## 1. 背景

框架 `generated/**/*.php`（返回数组的编译注册表 / 元数据）在 WLS 持久 Worker 下若按 `filemtime` 或「每实例」再次 `include`，会复制巨型 PHP 数组进进程堆。历史上 c1000 已因 `query_providers.php` 二次 include OOM（约 1G）。

多套 loader 策略曾不一致：有的进程只载一次，有的 SoftSurvivor 已登记但仍 mtime 再 include。本规格冻结统一门闩 **`CompiledPhpRegistry`** 与 SoftSurvivor **`ROLE_REGISTRY`** 双义务。

## 2. 用户故事

作为平台运行时，我希望返回数组的 generated 编译表在持久进程内只 `include` 一次并常驻，以便 Worker 不因二次加载 OOM；同时明确排除 `generated/language/**` 整袋常驻。

## 3. SoftSurvivor 角色（耐久语义）

权威矩阵：`Weline\Framework\Runtime\SoftSurvivorCatalog`。

| 角色 | soft 压力 | 二次 `include` 大编译表 | 典型袋 |
|------|-----------|-------------------------|--------|
| **`ROLE_CONTENT`** | **可卸**（可重建） | 不适用 / 卸后可从 Shared 或源重建 | Phrase 词典袋、NodePool、TemplateCache、FPC process payload |
| **`ROLE_REGISTRY`** | **禁卸** | **禁二次 include**（WLS / `Runtime::isPersistent()`） | `CompiledPhpRegistry::$entries`、QueryProvider / Widget / Event / Extends / CompiledExtension 等编译注册表袋 |

一句话：**`ROLE_REGISTRY` = soft 禁卸且禁二次 include 大编译表**（对照 CONTENT）。

仅把符号登记为 REGISTRY **不足以**防二次 include——领域 loader 必须经 `CompiledPhpRegistry`（或等价 once 门闩）。本波已把门闩袋本身登记为 REGISTRY，并把高风险 Widget/Extends 迁到门闩。

## 4. language 排除（硬）

| 禁止 | 理由 |
|------|------|
| 把 `generated/language/**`（整袋 locale / words）列入 `CompiledPhpRegistry` 可载路径 | 既有 Phrase / WLS 硬门禁：持久 Worker **禁止**整袋 locale include 常驻 |
| 把 language 整袋标 SoftSurvivor `ROLE_REGISTRY`「常驻编译表」 | 与 Phrase 压力面 / MS 写预算冲突 |
| 借门闩「白名单特例」放行 language | 门闩对 `generated/language` 前缀 **硬拒绝**（抛 `RuntimeException`） |

Phrase 路径继续走既有分层词典 / 单词键；**不**经本门闩。

## 5. 门闩不变量（EARS）

1. WHEN 某绝对/规范化路径首次经 `CompiledPhpRegistry::load` 成功或因缺文件失败 THEN 该 path 标记 `loaded=true`（缺失亦记已载，避免反复 stat+include）。
2. WHEN `loaded===true` 且 `Runtime::isPersistent()`（含 WLS）THEN 后续 `load` **SHALL NOT** 再次 `include` 同一文件（mtime 变化、`forceReload` 均为 no-op / 忽略）。
3. WHEN 调用 `forgetForTests` 且 persistent THEN SHALL no-op；仅非 persistent（如 CLI PHPUnit）可卸键以便重测。
4. WHEN 路径落在 `generated/language` 前缀 THEN `load` / `isLoaded` / `peek` / `forgetForTests` SHALL 硬拒绝。
5. WHEN soft 压力回收 THEN REGISTRY 袋（含 `CompiledPhpRegistry::$entries`）SHALL 不被卸掉，且不得借 soft 清空后再 include 大表「省内存」。
6. IF 领域 Registry 需校验 shape THEN 校验留在领域类；门闩只保证 once-include 与 payload 缓存。

新鲜度：**不**靠进程内 mtime 热重载；靠 Worker recycle / `server:reload` / 显式运维工具链。

## 6. 落点与迁移

| 项 | 落点 |
|----|------|
| 门闩 | `Weline\Framework\Compilation\CompiledPhpRegistry` |
| SoftSurvivor | `CompiledPhpRegistry::$entries` → `ROLE_REGISTRY` |
| P0 委托 | `Weline\Widget\Service\WidgetRegistry`、`Weline\Framework\Extends\ExtendsData` |
| 同波薄委托 | `CompiledExtensionRegistry` → 门闩 |
| defer | QueryProvider 全量委托门闩；ExtendsRegistry 实例 mtime 旁路；Router / OM plugins 进一步收敛；FPC snapshot、Env::getCommands、classmap |

**mechanism**：`none:runtime-cache`（非业务扩展点）。  
**owning_module**：`Weline_Framework`（secondary：`Weline_Widget`）。

## 7. not_to_do

1. 禁止 language 整袋进门闩 / REGISTRY 常驻编译表。  
2. 禁止在 `WlsRuntime` / 编排层硬编码业务 slug 特例放行二次 include。  
3. 禁止 soft 卸 REGISTRY 后再 include 大表。  
4. 禁止业务模块私造第二套 CompiledPhpRegistry。  
5. 禁止以 mtime 热更新为 PROD/WLS 默认。  
6. 本波不改 FPC invalidate / commands / classmap（已 defer）。

## 8. 验收（UC · 已 pass）

| ID | 描述 | 证据指针 |
|----|------|----------|
| UC-1 | persistent 下同一路径只 include 一次；二次 load 同引用 | `CompiledPhpRegistryTest::testSecondLoadDoesNotReinclude` |
| UC-2 | `generated/language/` 硬拒绝 | `testLanguagePathIsHardRejected` |
| UC-3 | WidgetRegistry / ExtendsData 经门闩；persistent 下 forceReload/mtime 不二次 include | 委托源码 + 门闩契约 |
| UC-4 | SoftSurvivor 登记 `CompiledPhpRegistry::$entries` = `ROLE_REGISTRY` | `testSoftSurvivorRegistersCompiledPhpRegistryBag` + SoftSurvivor matrix |

复跑：`php bin/w phpunit:run --name=CompiledPhpRegistryTest`（5 tests / 22 assertions）。

## 9. 相关

- SoftSurvivor CONTENT 对照（FPC 不得标 REGISTRY）：[`process-l1-pressure-eviction.md`](./process-l1-pressure-eviction.md) §5b  
- 进程/Fiber 缓存边界：[`../../3-开发/WLS-Fiber上下文与进程缓存边界.md`](../../3-开发/WLS-Fiber上下文与进程缓存边界.md)  
- 模块版本门禁（本波为何未 bump）：[`../../3-开发/模块版本与升级门禁.md`](../../3-开发/模块版本与升级门禁.md)
