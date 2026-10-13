# deploy:mode:set prod — staging 管道与 rename 快切

> 归属：`Weline_Framework` · `DeployStagingSession` · `Console\Deploy\Mode\Set`

## 目标

`php bin/w d:m:se prod` 期间**不删、不覆盖**活树 `pub/static`、`generated/complicate`、`generated/theme-layout-entities`；全部产物写入顶层 staging，管道成功后再 `rename` 切换，缩短请求窗口。

## 布局

```text
{BP}/var/deploy/staging/{stamp}/
  static/                    → rename → pub/static
  complicate/                → rename → generated/complicate
  theme-layout-entities/     → rename → generated/theme-layout-entities
```

环境变量：`WELINE_DEPLOY_STAGING_ROOT`（子进程继承；Upgrade / Theme 固化工人 / 模板编译均读此变量）。

## 管道顺序

1. 缓存 flush（不删编译活树）
2. **先**持久化 `system.deploy=prod`（`env.php`；子进程重读配置可见 prod）
3. `DeployStagingSession::open` — 本进程 `DEV`/`PROD` 常量仍为 define-once 旧值
4. DI `Compile`（仍写 `generated/code`）
5. `deploy:upgrade` → `staging/static`
6. `upgrade_after` 主题固化 + Taglib → staging 实体树 + `staging/complicate`  
   - 烘焙期间 **必须** `DeployStagingSession::forcesProductionPublicUrls()`：TraitTemplate / 占位图等发 `/static/{ns}/...`，禁止把 `/Weline/...` 或 `?v=dev_*` 写进 staging 树
7. `commitSwap`（失败则从 `*.prev.{stamp}` 回滚）
8. `purgePrevAndResidue`（删 prev；清模块残留 `view/tpl`）
9. `FpcStaticDiskStore::purgeAllLive` + FPC stamp + `prod_after`
10. `server:reload` — 持久 Worker 重绑 `DEV`/`PROD`（define-once；仅改 env.php 不够）

## 根因护栏（模块前缀泄漏）

`App` 的 `DEV`/`PROD` 进程内不可重定义。两层泄漏：

1. **staging 烘焙**：父进程仍 `DEV=true` → 须 `DeployPublicUrlMode` / staging 强制 `/static/`，禁止烤进 `?v=dev_*` 与模块前缀。
2. **活 Worker**：env.php 已 prod 但旧 Worker 常量仍 DEV → 须 Mode\Set 后 `server:reload`；另清 `generated/static/live` 残留 DEV 镜像。

## 单独 deploy:upgrade

无 staging 会话时仍写活树（兼容日常升级）。仅 Mode\Set prod 强制开管道。
