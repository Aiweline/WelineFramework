# 背景

编辑画布 `editor_mode=1` 上，`ThemeDiskHeadService::getOverrideHref` 调用 `ThemeData::getConfigList('frontend','disk_bundle')` 约 7534ms。同一次请求里 `shouldRefreshProcessCache` 因 `editor_mode` 为真，先 `clearProcessMemoryCache()`（约 0.01ms），再读配置。

源码复核（本波只读，行号以当前工作区为准）：

- `ThemeData::performanceLoad`（`Helper/ThemeData.php` 1415–1518）按 `namespace + metaIdentify + scope + locale + themeId` 做一次快照。键是 1458–1464 的 sha256。`w_meta_config` 没有版本列；这次 search 也不带 `theme_layout_version.version_id`。scope 链上每个候选 scope 各一条 `MetaConfigRepository::search`（1487–1493），不是按配置行 N+1。
- `MetaConfigRepository::search`（`Meta/Service/Repository/MetaConfigRepository.php` 28–56）是一条 SQL。`MetaConfig` 字段只有 `config_id / identify_id / meta_id / meta_identify / namespace / config_key / config_value / scope / locale / identity_fingerprint`。
- `getConfigList`（1965–2026）只有 `performanceKey` 仍匹配时才 `filterThemeConfigsByType`（1989–1998）。否则按 type 前缀、按 scope 链自己 search（2006–2018）。
- head 在读之前调用 `setCurrentTheme`（`ThemeDiskHeadService.php` 35–36、48–49）。`setCurrentTheme`（1614–1625）把 `performanceKey` 置空（1618），并把 `initialized` 置假。head 自己不调用 `performanceLoad`。`getConfigList` 入口的 `ensureInitialized`（775–804）在 `initialized` 为假时会先 `performanceLoad()`，但用的是当前区域默认 scope，不一定等于本次 `getConfigList` 的 scope。
- `shouldRefreshProcessCache`（90–115）：`area=backend` 无条件为真；query 里 `editor_mode` / `preview` / `visual_editor` / `theme_disk_refresh` 为 `1` 或 `true` 也为真。为真则 `clearProcessMemoryCache`（1532–1536），清掉进程 L1 `$runtimeCache` 并 `resetCurrentState`。
- 写入：`ThemeData::set`（885–944）对 `theme.{area}.{configKey}.value` 做单行 `upsert`（939），成功后 `clearCache()`（940）清整池并清共享命名空间。`deleteParamValue`（1283）也调用 `clearCache()`，不在本接口内。
- 发布：`ThemeRuntimeCacheCleaner` 的 `theme_data_runtime` 步骤调用 `ThemeData::clearCache()`（111–112，以及 216–217）。保持这条路径。
- 热路径契约已有：`doc/runtime-cache-invalidation.md` 47–51。ThemeData 热路径只进程 L1，禁止请求内 SharedState get/set；head 禁止 `clearNamespace`。该文档第 50 行仍写「head 刷新时只 `clearProcessMemoryCache()`」，与本冻结冲突，留给文档席，本波与施工波都不得改这份文档。
- 本机只读规模：`w_meta_config` 约 310 行，其中 `disk_bundle` 约 207 行。数秒不是这张表的堆大小，也不连生产核实。
- `ThemeData`、`ThemeDiskHeadService`、`MetaConfigSearch`、`MetaConfigRepository` 均无 `version_id`。身份模型仍是模块版本 2.2.471。

## 需求纠偏

用户说的「某个版本的主题数据，按主题和版本分开进程内存缓存」不对应 `theme_layout_version.version_id`。

对应物是 `performanceLoad` 已经存在的一次性快照桶：`themeId + namespace + scope + locale`（外加装入时的 `metaIdentify`，默认 `namespace.*`）。scope 已经区分默认与预览作用域。布局版本、版本继承 popover、编辑锁、ThemeEditor 的 `theme_id` 都不在 `disk_bundle` 读路径上。

不给 `w_meta_config` 加版本列。不新建 Cache 类，不新建 Service。两套桶不是互斥且都必须选的扩展点：`version_id` 新桶既不在实测慢路径上，又会变成不可逆表结构，否决。

# 方案

采纳现有主题桶。进程内一份快照服务同一 `themeId + namespace + scope + locale` 的全部 type。读未命中只装入这一份，再按 type 过滤。写一条只改这一条数据库行和已装入桶里的对应键。整池清空只发生在发布和显式进程重置。

## 冻结接口

1. 热路径读。`getConfigList` 在请求态 `performanceCache` 与进程 L1 都未命中时，禁止再按 type、按 `configKeyPrefix`、按单条配置调用 `search`（删除 2006–2018 这条回退）。只允许再调用一次 `performanceLoad($namespace, null, $effectiveScope, $locale)`，或直接使用与这次调用同一份 map，然后 `filterThemeConfigsByType`。`ensureInitialized` 里那次默认 scope 的 `performanceLoad` 若与本次 `effectiveScope` / area / locale 不一致，不算命中。`setCurrentTheme` 仍可把 `performanceKey` 置空；置空后必须能靠进程 L1 的 `performance:{sha256}` 命中，不得因此重新 search。scope 链仍留在 `performanceLoad` 内部：每个候选 scope 至多一条 SQL，这算「一次装入」，不是按 type 再查。

2. `ThemeDiskHeadService`。`editor_mode`、`preview`、`visual_editor` 不得在画布 GET 上调用 `clearProcessMemoryCache()`。`area=backend` 不得再无条件刷新。进程 L1 清空只留给：发布路径的 `clearCache()`，以及 query 显式进程重置 `theme_disk_refresh=1|true`。head 仍禁止 `clearCache()` / `clearNamespace`。`setCurrentTheme($theme)` 继续按传入主题生效，施工不得改掉这次赋值。

3. 写。`ThemeData::set` 成功路径保持单行 `upsert`（939）。去掉紧随其后的 `clearCache()`（940）。同一进程里，若对应快照桶或派生 `config_list_{area}_{type}_{scope}_{themeId}_{locale}` 已经在 L1，只替换该 `configKey`（快照 map 的键是 `resolveConfigMap` 的 `configKey`，例如 `disk_bundle.default`；派生切片是去掉 type 前缀后的键，例如 `default`）。桶尚未装入时不要为了修补去 search。不得调用 `clearProcessMemoryCache()`，不得 `clearSharedRuntimeCache()`，不得 `resetCurrentState()`。其它进程不在这次写入上收敛，只在发布时 `clearCache()` 收敛。`ThemeRuntimeCacheCleaner` → `ThemeData::clearCache()` 保持，不要改成不清。

4. 不改。ThemeEditor 的 `theme_id` 必须继续生效。编辑锁不改。版本继承 popover 不改。身份模型保持 2.2.471，本需求不升版本号。不改 `theme_layout_version`。不改 `deleteParamValue` 的 `clearCache()`（1283）。

## 不变量

- 桶身份就是现有 sha256：`namespace`、`metaIdentify`、`scope`、`locale`、`themeId`。不是 `version_id`。
- 同一 worker 内，冷启动第一次装入允许 `performanceLoad` 沿 scope 链查询；此后同一桶的 `getConfigList`（含 `disk_bundle`）不得再 `search`。
- 编辑画布 GET（`editor_mode` / `preview` / `visual_editor`）不得调用 `clearProcessMemoryCache`。
- 单条 `set` 之后：该键在已装入桶中已是新值；`$runtimeCache` 里其它键仍在；未调用 `clearCache` / `clearProcessMemoryCache`。
- 跨进程、跨 scope 合并结果的陈旧值只靠发布 `clearCache()` 或 `theme_disk_refresh` 收敛。同进程同桶必须立刻可见，因为 `ThemeDiskCompileService::compileBundle` 用 `ThemeData::set` 写 hash，紧接着的同进程 head 读必须看到新 hash。
- 热路径仍然禁止 `weline_site_runtime` get/set。写入不再借 `clearCache()` 打共享命名空间。
- 派生切片若只改快照、不改 `config_list_*`，`getConfigList` 会在 1983–1986 先返回旧切片。修补必须两处一起做，且只动这一 type 的切片。

# 细节

## 施工文件清单

下一波才改，本波不改。禁止新 Service、禁止新 Cache 类。

- `app/code/Weline/Theme/Helper/ThemeData.php`：`getConfigList` 未命中走一次 `performanceLoad` + `filterThemeConfigsByType`；`set` 去掉整池 `clearCache`，改为修补对应键。
- `app/code/Weline/Theme/Service/Disk/ThemeDiskHeadService.php`：`shouldRefreshProcessCache` 仅 `theme_disk_refresh` 为真；`editor_mode` / `preview` / `visual_editor` / 无条件 backend 为假。
- 合约测试：`app/code/Weline/Theme/test/Unit/ThemeDataHotPathCacheContractTest.php`。现有 `testDiskHeadClearsProcessCacheNotSharedNamespace` 只断言源码含 `clearProcessMemoryCache()`，施工后这条不够，必须改成下面的断言。允许同目录新增只覆盖上述冻结的合约测试，不得为此新建生产类。

施工不得改：`ThemeRuntimeCacheCleaner.php`、`runtime-cache-invalidation.md`、ThemeEditor、编辑锁、版本继承、`deleteParamValue`。

## 测试断言要点

- 同一 worker、同一 `themeId + namespace + scope + locale`：第一次 `getConfigList($area, 'disk_bundle', $scope)` 允许 `performanceLoad` 内的 scope 链 search；第二次再读 `disk_bundle` 时 `MetaConfigRepository::search` 调用次数不得增加。中间插入 `setCurrentTheme` 置空 `performanceKey` 也一样，只要没有 `clearProcessMemoryCache`。
- `editor_mode=1`、`preview=1`、`visual_editor=1` 以及 `area=backend` 且无 `theme_disk_refresh` 时，`getOverrideHref` 不得调用 `clearProcessMemoryCache`。`theme_disk_refresh=1` 仍允许清进程 L1，且仍不得 `clearCache` / `clearNamespace`。
- `ThemeData::set` 成功后：进程内该 `configKey` 已是新值；`processCacheItemCount()` 不为 0（池里仍有其它项时）；未走 `clearCache()`。数据库侧仍是单行 upsert，不新增多行写。
- 冷启动第一次装入允许查询。断言「第二次不得再 search」，不要把 scope 链上的多条 SQL 当成失败。
- 发布路径源码仍包含 `ThemeRuntimeCacheCleaner` 调用 `ThemeData::clearCache()`。本测试不改 Cleaner。
- 源码断言：`getConfigList` 在 `performanceKey` 不匹配的分支不得再出现 `configKeyPrefix`。`MetaConfigSearch` 不得新增 version 字段。
