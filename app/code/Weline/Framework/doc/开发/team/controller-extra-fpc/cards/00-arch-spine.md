# 任务卡 · 接口冻结（架构师）

## 目标

冻结 Changed / Extra 公共接口与 Effect 名，供轨 A–E 对齐。

## 边界

仅接口 + 注册表骨架 + extends.php 声明；不含业务 Enricher / Capability 业务逻辑。

## 禁止

把 URL 矩阵/听众全表抄进实现卡；误删 Seo event 注册。

## 验收

- `ChangedCapabilityInterface` / `ChangedTypeInterface` / `InvalidationEffect` / `ExtraTypeProviderInterface` 落盘
- `Framework/extends.php` 声明 `Changed` 与 `ExtraType`（若 Extra 走注册表而非 Extends，则 Extra 用独立 Registry）
- Recipe 每条 Effect 必须带 `phase=sync|after_commit`

## Effect 名（冻结）

- `bump_namespaces`（sync）
- `purge_fpc_urls`（after_commit）
- `purge_fpc_all`（after_commit；仅平台白名单模块）
- `cdn_purge`（after_commit）
- `cache_ops_delete`（after_commit；承接原 ImpactObserver 非 FPC 键删）
