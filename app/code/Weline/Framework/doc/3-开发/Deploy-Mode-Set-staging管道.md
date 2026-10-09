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

1. `DeployStagingSession::open`
2. 缓存 flush（不删编译活树）
3. DI `Compile`（仍写 `generated/code`）
4. `deploy:upgrade` → `staging/static`
5. `upgrade_after` 主题固化 + Taglib → staging 实体树 + `staging/complicate`
6. `commitSwap`（失败则从 `*.prev.{stamp}` 回滚）
7. `purgePrevAndResidue`（删 prev；清模块残留 `view/tpl`）
8. FPC stamp + `prod_after` + 持久化 `system.deploy=prod`

## 单独 deploy:upgrade

无 staging 会话时仍写活树（兼容日常升级）。仅 Mode\Set prod 强制开管道。
