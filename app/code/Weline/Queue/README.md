# Weline Queue

`Weline_Queue` 提供可持久化队列、自动调度与带 fencing 的 Worker 执行。完整开发与运维文档见 [doc/README.md](doc/README.md)。

## 幂等创建

跨模块写入使用 `w_query('queue', 'createIfAbsent', ...)`，并提供最多 191 字节的 `idempotency_key`。MySQL、PostgreSQL 和 SQLite 依靠唯一索引将并发创建收敛到同一 `queue_id`。如果调用时已有外层事务，唯一键竞争在 savepoint 内回滚，不污染外层事务。物理索引名可能被数据库或 Schema 编译器改写，因此冲突归类同时校验 SQLSTATE、逻辑约束、表和 `idempotency_key` 列；不得只匹配固定索引名，普通数据库错误仍须原样抛出。

公开 `create/createIfAbsent` 只允许 `status=pending`；运行态与终态只能由专用控制或
Worker 协议进入。Scope 只接受单个 `scope_envelope` 入参并展开为 v1 固定列。
遗留行工具：`php bin/w queue:scope:migrate help|preflight|quarantine|verify`；信封 apply 归 `php bin/w scope:migrate-p1a apply --database=mig_clone_*`。

## Delivery attempt Transport

可靠异步事件使用固定键 `delivery:{delivery_id}:attempt:{attempt_no}`。Queue 行只是一轮 attempt 的 Transport 证据，Framework Delivery 才是投递权威状态。

建件时 Queue 先以 `auto=0`保持不可消费；Delivery 写入 `queue_id` 后才激活。调度器用 `dispatch_token` 和 `dispatch_until` 先抢占，Worker 只有在 token 匹配时才能执行，过期 Worker 只能 no-op。

## 受管进程控制

后台 clean-pending 编辑、reset/continue/retry、暂停、Query takeover 和删除统一通过
`QueueDispatchService`。服务以完整 Queue 快照作为并发 fence；发送终止信号前还会
同时验证 Queue ID、规范化进程名、launch-id 与 dispatch-token。身份未知、存活的
tokenless 旧 Worker、自我终止或 CAS 冲突都会 fail closed，不按裸 PID 强杀，也不会
发出成功事件。任何 active/dirty attempt 删除都默认拒绝；`force=true` 仍需安全释放。

Worker 的执行中、成功与失败终态均按 PID/token ownership 与完整快照 CAS，旧代次不会
覆盖新代次。reconcile 同样以决策快照恢复过期 claim 或收口已退出 Worker。
安全删除将主表条件 DELETE 与 Queue EAV entity 的全部属性清理置于同一事务，
不只清理当前 type 的属性。带 Worker 副作用的控制入口拒绝嵌套于已有数据库
事务；后台原子编辑使用 write-intent 根事务并绕过 CAS 后的 identity-map 旧快照。

完整状态机、错误边界与观察者语义见 [doc/README.md](doc/README.md)、
[队列停止事件](doc/event/队列停止.md) 和 [队列删除事件](doc/event/队列删除.md)。

## 后台浏览器管理契约

Queue 后台页面的异步业务请求只使用 `Weline.Api.resource('queue_admin')`。
`queue_admin` 是 Backend 登录态与 ACL 约束下的窄接口，只发布快照、类型搜索、
属性表单、原子保存、单条控制、批量控制、类型启停和属性依赖解析；通用 `queue`
Provider 的 dispatch、takeover、force、PID、dispatch token 与 owner 始终保持服务端专用。

Controller 只保留 SSR 页面、详情和 GET 表单渲染；历史 GET 写路由、直接表单 POST、
Controller 属性读取和 `api_action/api_batch` 别名均只返回 `410`，不能绕过 bin-query
的 operation ACL。列表投影、分页与
partial 渲染归 `QueueAdminListingView`，业务写入归 `QueueAdminService`。删除使用独立
`Weline_Queue::delete`，类型启停使用独立 `Weline_Queue::type_manage`，不复用查看权限。保存时
`module` 必须从服务端 Queue Type 重新推导，浏览器不能提交控制字段或伪造模块归属；
服务按 Type 关系中的 attribute_id 与固定 Queue EAV 实体生成属性映射，完整校验 required
属性后用同一映射写值；主表 pending CAS、EAV 写入和消费者校验在同一事务内提交。成功
事件与表单草稿清理属于 commit 后通知，失败只返回 warning，不能把已落库结果伪装成回滚。
Queue Type 的启停状态在模型边界把布尔值规范化为数据库 `smallint` 所需的 `0/1`，不得把
`false` 作为通用 ORM 值写成空字符串。
列表快照固定每页 10 条，并以 revision 和 mutation epoch 避免无变化或旧响应替换；查看和编辑使用列表区域外
的单例 OffCanvas，快照更新不会移除其初始化脚本。快照 partial 的统计卡和分页使用显式
`queue/backend/queue` 路径，分页筛选参数来自 operation 入参，不能依赖 QueryBin 当前请求的
router 或 GET。OffCanvas iframe 不自行申请 Backend Worker 凭证，只能复用同源父后台页面的
`Weline.load('api')`；跨源、父 API 缺失或非 iframe 顶层之外的异常上下文必须 fail-closed。
确认文案中的队列名和批量数量必须在翻译完成后由浏览器替换保留令牌，避免 `%{1}` 被服务端
提前消费；无匹配数据的完整页和实时快照都必须显示同一空态。

Queue Type 的 EAV dependence HTML 不执行内嵌旧请求脚本。页面通过
`resolveAttributeDependence` 进入 Queue Type/code 白名单校验，再调用
`Weline_Eav` 的公开只读依赖解析服务；浏览器不能选择 EAV 实体或 model class。
表单初始化数据通过 JSON bootstrap 输出；类型属性与 dependence 请求均有代次校验，
旧类型请求不得修改新代请求计数。解析失败的 code 进入 unresolved 集合，同时阻断
下一步和提交，只能重试该 code 最后失败的 dependence，不得整表重载覆盖其他输入。
历史草稿不恢复到确认页；确认页提交还必须通过当前页、属性加载、依赖和参数采集的统一门禁。
创建成功后 queue_id 立即固化且提交保持锁定，
避免乱序覆盖和重复建件。

## 运维命令

```bash
php bin/w queue:collect
php bin/w queue:type:listing AsyncEventDeliveryQueue
php bin/w event:async:relay --once --limit=100
php bin/w event:async:gc --limit=500
```

异步功能默认关闭，必须分别显式开启 producer 与 relay；不得用 Queue `error` 直接代替 Delivery `dead`。
