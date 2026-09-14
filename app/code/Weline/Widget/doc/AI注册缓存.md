# AI 部件注册数据缓存

2026-09-13，Widget 1.0.8。

`AiWidgetRegistrySource::getRegistryEntries()` 是 AI 公共注册定义的唯一读取拥有者。它使用现有 `StorefrontScopeHotCache::rememberPolicy()`，资源为 `widget.ai_registry`，池为 `weline_widget_ai_registry`，逻辑键为 `active-definitions.v1`，依赖 `global/widget/registry`。这些定义没有网站、店铺、渠道、语言、用户或请求 ID 维度，进程 L1 和配置的共享缓存池使用相同版本键。查询与 JSON 解码只在真正未命中时执行；成功空数组同样缓存。ORM 模型、页面布局和用户配置不进入共享值。现有生成文件注册表以及 AI 定义覆盖同代码条目的顺序保持不变。

数据库异常仍按原有入口记录并返回空数组，但异常在缓存 builder 外处理，失败不会成为权威空表，后续读取可以重试。

`AiWidget::save()/delete()` 委托同模块 `AiWidgetRegistryMutation`，沿框架现有 `TransactionCoordinator`、`ResourceRevisionService`、`ResourceChangeFactory`、`w_changed` 和 `NamespaceGenerationRepository` 处理。保存、启停和带 WHERE 的删除与缓存代次共用默认主库事务；事件显式标记全局所有者 `0/global`。模板或定义改动同时推进现有 `global/storefront/theme`，让依赖它的 HTML/FPC 使用新版本。提交回调使用仓库提供的最终完整向量，经既有运行时 publisher 广播；回滚不发布版本。没有新增私有静态缓存、失效事件名或单独清缓存协议。

定向测试：

```sh
vendor/bin/phpunit --bootstrap app/bootstrap_phpunit.php app/code/Weline/Widget/test/Unit/Service/AiWidgetRegistryCacheTest.php
```

RED：成功空表重复查询 2 次（预期 1）；失败恢复后查询 3 次（预期 2）；旧模型新增/编辑没有 changed 事件。正式源码 GREEN：3 tests / 27 assertions。SQLite 测试仅对修订版查询归一原子建件及 UPDATE affected-row，编辑使用同事务真实 SQL callback；该适配不替代下述 PostgreSQL 公开模型入口验收。

当前真实库 active AI 定义数量为 **0**，旧入口三次查询约 2.008 / 0.506 / 0.328 ms。因此本项收益是重复空查询和共享池复用，不能把 SEO observer 的 26 ms 全部归因于 JSON 解码。基线 `/tmp/weline-sept13-next-hotspots-home-before-business3.trace.json`：总 280.29 ms，95 SQL / 29.42 ms，39 WLS / 7.29 ms；其中相同 active 注册 SQL 3 次 / 1.6 ms。

正式 PostgreSQL 验收已通过，写进程 PID 47495，独立只读进程 PID 49493。经真实 `AiWidget::save()` 完成新增、启用、编辑、停用，并由真实带 WHERE 的 `delete()` 清理唯一诊断记录；外层事务回滚后数据库字段和 registry 代次均未泄漏。其他记录的前后 hash 完全相同，诊断记录已删除。证据为 `/tmp/weline-widget-registry-pg-write.json` 和 `/tmp/weline-widget-registry-pg-peer-read.json`。

| 读取场景 | 注册表 SELECT | builder | 共享池读取 |
| --- | ---: | ---: | ---: |
| 新增、启用、编辑、停用、删除后，各首次读取 | 各 1 | 各 1 | 各 1 |
| 回滚后读取 | 0 | 0 | 0 |
| 相同进程新 Source 重复读取 | 0 | 0 | 0 |
| 清本进程 L1 后新 Source | 0 | 0 | 1 |
| 独立 CLI 进程首次读取 | 0 | 0 | 1 |

上述计数来自每次读取独立重置后的完整 Trace spans，所有 `dropped_spans=0`，不是从截断的 top20 SQL 推断。独立 CLI 首次仍有 2 条初始化/代次 SQL，19.352 ms；这里的 0 仅指注册表 SELECT。同进程空表重复/L1 重置后分别 0.193/0.195 ms，编辑后重复/L1 重置后分别 0.489/0.570 ms。各 CLI 步骤 `wls_spans=0`，因此这些结果证明统一共享池的跨进程复用，**不能当作 WLS 跨 worker 失效证据**；WLS 页面验收由 Root 统一执行后补充。
