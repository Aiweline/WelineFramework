# Task: 缓存运行时统一与后台缓存管理优化

- Started: 2026-03-21 20:36
- Updated: 2026-03-21 22:15
- Status: in_progress

## Goal

在兼容现有缓存调用方式的前提下：
- 统一 WLS / 共享内存 / FPM 下的缓存清理语义
- 让后台使用者无需关心底层缓存实现
- 重做 `Weline_CacheManager` 后台页的体验、结构和交互反馈

## Completed

- 运行时统一清理方向已接通：
  - `CachePool::clear()` 会派发 `Weline_Framework_Cache::integration::cache_flushed`
  - `CacheManager::clearAll()` / `flushAll()` 面向“全部已注册池”而不再只依赖已实例化池
  - `CacheAdminService` 已统一处理缓存池清理、共享命名空间清理与 WLS 运行时同步
- 后台控制器与数据模型已重构：
  - `Weline\CacheManager\Controller\System\Cache`
  - `Weline\CacheManager\Service\CacheAdminService`
- 缓存后台新模板已落地，并改为轻模板 + 外置 CSS/JS：
  - `app/code/Weline/CacheManager/view/templates/System/Cache/index.phtml`
  - `app/code/Weline/CacheManager/view/statics/system/cache-admin.css`
  - `app/code/Weline/CacheManager/view/statics/system/cache-admin.js`
- 后台新交互已实现：
  - 单池状态切换
  - 单池清理 / 持久池强制清理
  - 批量启用 / 禁用 / 清理
  - 清理全部非持久缓存
  - 强制清理全部缓存
  - 单池统计刷新
  - 清理任务手动执行
  - 汇总指标与运行时概览刷新
- `UpgradeCache` 已修正：
  - 状态写库改为使用真实 `enabled`
  - 尽量保留已有记录的模块/文件/类型/说明
  - 名称友好化回落到适配器短名
- 路由已刷新并确认新接口已注册：
  - `admin/system/cache/status-batch`
  - `admin/system/cache/pool-stats`
  - `admin/system/cache/run-cron-task`
- 新增单测：
  - `app/code/Weline/Framework/Cache/test/CachePoolTest.php`
  - `app/code/Weline/Framework/Cache/test/CacheManagerBehaviorTest.php`

## Verification

- PHP 语法检查：
  - `app/code/Weline/CacheManager/Observer/UpgradeCache.php`
  - `app/code/Weline/Framework/Cache/test/CachePoolTest.php`
  - `app/code/Weline/Framework/Cache/test/CacheManagerBehaviorTest.php`
  - `app/code/Weline/CacheManager/view/templates/System/Cache/index.phtml`
- JS 语法检查：
  - `app/code/Weline/CacheManager/view/statics/system/cache-admin.js`
- PHPUnit：
  - `CacheManagerRoutingTest`
  - `CachePoolTest`
  - `CacheManagerBehaviorTest`
  - 结果：通过
  - 附加告警：本地无 code coverage driver
- 路由检查：
  - `route:list` 已确认 `status-batch` 出现

## Notes

- `setup:upgrade` 在当前 CLI 下有参数重复解析问题，直接使用 `--route` 形式会被误报“未知参数”。
- 本轮通过 `route=1 module=Weline_CacheManager skip-classmap=1 skip-reflection-compile=1 sync=1` 的键值参数形式完成路由刷新。
- 路由写入之后，升级流程在 ACL 清理阶段抛出了一个与本任务无关的已有异常：
  - `Weline\Acl\Service\AclOrphanCleanupService::buildNonUserAclQuery()` 返回类型错误
  - 该异常未影响本轮 `Weline_CacheManager` 路由写入结果

## Remaining

- 继续补充 `Weline_CacheManager` 的英文翻译词条，尤其是新模板中的完整文案
- 如需联调，再补实际后台页面截图和交互验收记录
