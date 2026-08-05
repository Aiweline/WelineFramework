# 文件所有权与冲突锁

## 当前锁

| 路径/模块 | 当前 Owner | 状态 | 商城候选 Owner | 商城动作 |
|---|---|---|---|---|
| `app/code/Weline/Framework/Database/**` | 上游异步事件工程 | LOCKED | PRJ-MIG/Order/Payment | 等上游 ACCEPTED；重新 impact 后分文件锁 |
| `app/code/Weline/Framework/Event/**` | 上游异步事件工程 | LOCKED | PRJ-ASYNC/Payment | 等公开契约冻结 |
| `app/code/Weline/Framework/Cache/**` | 上游异步事件工程 | LOCKED | PRJ-SCOPE/CONFIG/Search | 等 namespace/FPC 基线接受；`KeyBuilder.php` 不在独占修复窗口 |
| `app/code/Weline/Framework/Runtime/ScopeIdentity.php` | P1a `0900` → remediation `0946` | WRITE_LOCK_0946 | PRJ-SCOPE | 可修严格 identity；与 RequestContext 安装串行 |
| `app/code/Weline/Framework/Runtime/**`（除上项） | 上游异步事件工程 | LOCKED | PRJ-SCOPE | 等 WLS Runtime 基线接受 |
| `app/code/Weline/Framework/Router/FullPageCacheCoordinator.php` | 上游异步事件工程 | LOCKED | PRJ-SCOPE | P1A-006 后接，不覆盖 namespace FPC |
| `app/code/Weline/Framework/Http/Url.php` | 上游异步事件工程 | LOCKED | PRJ-SCOPE | P1A-004 前先合并上游 URL 事实 |
| `app/code/Weline/Queue/**` | 上游异步事件工程 | LOCKED | PRJ-ASYNC | 上游 Queue schema/transport 先验收 |
| `app/code/Weline/Websites/{Model/Store.php,Model/SalesChannel.php,Api/Catalog/**,Service/{StoreCatalog,SalesChannelCatalog,ScopeResolver,ScopeTokenService,StoreChannelSeedService}.php}` | P1a `0900` → remediation `0946` | WRITE_LOCK_0946 | PRJ-SCOPE | 只改独占新文件；逐文件验证 |
| `app/code/Weline/Websites/{Model/Website.php,Observer/DetectWebsite.php,etc/module.php,doc/README.md}` | 上游异步任务 + P1a 混写 | MERGE_LOCKED | PRJ-SCOPE | 等双方 manifest，人工合并 |
| `app/code/Weline/Websites/**`（其他） | 上游异步事件工程或 UNKNOWN | LOCKED | PRJ-SCOPE | 未列入独占清单即保持只读 |
| `app/code/Weline/SystemConfig/**` | 上游异步事件工程 | LOCKED | PRJ-CONFIG | 精准失效/atomic producer 作为输入 |
| `app/code/Weline/Server/**` | 上游异步事件工程 | LOCKED | PRJ-SCOPE/WLS QA | 只消费正式 IPC/READY 契约 |
| `app/code/Weline/Cdn/**`、`Seo/**`、`Geo/**` | 上游异步事件工程 | LOCKED | PRJ-SEC | 上游 accepted 后按模块串行 |
| `app/code/Weline/Payment/doc/**` 等前序文档 | R4 修订任务 | PRESERVE | Payment docs Owner | 实施时合并，不回退命令口径 |

## 商城 L4 文件锁规则

1. 每个文件同一时刻只有一个 TASK/Slice 写 Owner；
2. 文件从上游工程移交时记录前/后 hash 和 accepted commit；
3. 同模块 `register.php`、`composer.json`、`etc/module.php`、`etc/event.xml`、`doc/AI-INDEX.md`、`doc/README.md` 设单独整合窗口；
4. 迁移 TASK 只拥有 Console/MigrationService/checkpoint 相关文件，不顺手修改功能实现；
5. 发现必须改上游仍锁定文件时停止并提交 C1/C2，不复制一份平行 Service；
6. 任何 UNKNOWN_OWNER 文件保持只读。
7. `0946` 写锁只转移 P1a 独占新路径，不转移上游任务对混写文件的所有权。
