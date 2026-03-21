# Active Task

- Updated: 2026-03-21 22:15
- Task File: `dev/ai/codex/tasks/2026-03-21/2026-03-21-2036-cache-runtime-admin-unification.md`
- Status: in_progress

## Current Goal

继续收口缓存运行时统一与后台缓存管理页优化：
- 让 WLS / 共享内存 / FPM 下的缓存清理行为对使用者透明
- 完成新缓存后台的静态资源、交互与状态刷新
- 修正缓存池元数据同步与路由注册收口

## Latest Progress

- 已补齐缓存后台新页面的静态资源：
  - `app/code/Weline/CacheManager/view/statics/system/cache-admin.css`
  - `app/code/Weline/CacheManager/view/statics/system/cache-admin.js`
- 已接通页面交互：
  - 单池启用/禁用
  - 单池清理与持久池强制清理
  - 批量启用/禁用/清理
  - 清理全部 / 强制清理全部
  - 单池统计刷新
  - 运行定时任务
  - 汇总指标刷新
- 已修复 `UpgradeCache`：
  - 数据库存储的 `status` 现在跟随真实 `enabled`
  - 现有记录的 `module/file/type/description` 会尽量保留
  - 名称会在“空值/原始适配器类名”场景下回落到更友好的适配器短名
- 已新增缓存单测：
  - `CachePoolTest`
  - `CacheManagerBehaviorTest`
- 已刷新 `Weline_CacheManager` 路由，确认 `status-batch` / `pool-stats` / `run-cron-task` 已注册

## Verification

- `php -l app/code/Weline/CacheManager/Observer/UpgradeCache.php`
- `php -l app/code/Weline/Framework/Cache/test/CachePoolTest.php`
- `php -l app/code/Weline/Framework/Cache/test/CacheManagerBehaviorTest.php`
- `php -l app/code/Weline/CacheManager/view/templates/System/Cache/index.phtml`
- `node --check app/code/Weline/CacheManager/view/statics/system/cache-admin.js`
- `php vendor/bin/phpunit --configuration phpunit.xml app/code/Weline/Framework/Cache/test/CacheManagerRoutingTest.php app/code/Weline/Framework/Cache/test/CachePoolTest.php app/code/Weline/Framework/Cache/test/CacheManagerBehaviorTest.php`
  - 结果：通过
  - 附带告警：本地无代码覆盖驱动
- `php bin/w route:list | Select-String 'admin/system/cache'`
  - 已确认出现 `status-batch`

## Risks / Notes

- `setup:upgrade` 这条 CLI 在当前工作区有参数解析怪异点：
  - 直接传 `--route` / `--sync` 会被重复解析成未知参数
  - 本轮通过 `route=1 module=Weline_CacheManager ...` 的键值格式绕过
- 路由文件写入完成后，升级流程又触发了一个与本任务无关的已有异常：
  - `Weline\Acl\Service\AclOrphanCleanupService::buildNonUserAclQuery()` 返回类型错误
  - 该异常发生在 ACL 清理阶段，未阻止本次路由写入落盘

## Next

- 视需要继续补充 `Weline_CacheManager` 的英文翻译词条
- 如果要做页面联调，可直接进后台验证新交互和 WLS 模式下的清理反馈
