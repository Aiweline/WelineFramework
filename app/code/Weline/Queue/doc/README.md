# Weline Queue 消息队列

`Weline_Queue` 提供数据库队列、类型收集、定时调度、独立 Worker 执行和运行状态记录。跨模块生产者和消费者不应引用 `Weline\Queue\Model\*`。

Queue 的后台运行控制依赖 Backend 用户数据与 Cron 进程契约，二者均为必需依赖；跨模块业务仍只通过下述 Queue 公共契约接入。

后台菜单的信息架构固定归属“系统管理 → 系统服务 → 消息队列”。队列运行、消费者、重试与
Inbox/Outbox 都是系统运行能力，禁止把 `Weline_Queue::message_service` 挂到业务运营或
“运营工具”父级；菜单位置变更后通过路由同步重新采集 ACL/Menu 关系。

Queue 内部也不能越界调用这两个模块的非公共实现类：

- 后台表单暂存数据使用 `Weline\Backend\Api\UserData\BackendCurrentUserDataInterface`，只传递当前登录用户下指定 scope 的数组。
- 普通 PID 检查和任务名规范化使用 `Weline\Cron\Api\Process\ProcessControlInterface`，
  不引用 Cron Helper；需要发送终止信号时统一进入 Queue 安全控制服务，再由
  Framework `Processer` 按 PID、进程名、launch-id 和命令参数共同验证受管身份。

后台浏览器异步操作统一使用 `Weline.Api.resource('queue_admin')`，不得直连 Queue
Controller，也不得用 `fetch`、XHR、`$.ajax`、axios 或 `Weline.Api.request/get/post`
作为回退。该 Provider 仅面向 Backend 登录态，所有 operation 都有 source 或
action param-map ACL；通用 `queue` Provider 继续保持服务端专用。

`queue_admin` 当前页面契约包括：

- `snapshot`：同一服务生成统计与列表 partial，并返回 `revision/changed`；
- `searchTypes`、`typeAttributes`：只返回表单所需的安全投影；带实体值的
  `typeAttributes` 统一要求 `Weline_Queue::form`；
- `save`：不接收可信 `module`，由 Queue Type 推导并执行原子 pending 编辑；
- `action`：仅 `delete/stop/continue/retry/reset`，按 action 映射精确 ACL，删除独立使用
  `Weline_Queue::delete`；
- `batchAction`：仅 `delete/stop/continue`，最多 100 条且逐项收口副作用；
- `setTypeEnabled`：在独立 `Weline_Queue::type_manage` ACL 下启停类型；
- `resolveAttributeDependence`：先验证两个属性 code 均属于所选 Queue Type，再通过
  `Weline_Eav` 公开服务解析选项；不接受实体 ID、model class 或任意调用目标。

Controller 只保留 SSR/详情/GET 表单渲染；旧 GET 写路由、直接表单 POST、Controller
属性读取以及 `api_action/api_batch` 别名均只返回 `410`。列表状态与 partial 渲染委托 `QueueAdminListingView`，
写操作只从 `queue_admin` 进入 `QueueAdminService`。浏览器 Provider 不发布 takeover、force、PID、dispatch token、
owner 或 manual claim。EAV 返回 HTML 中的旧 dependence `<script>` 必须剥离；
依赖联动由上述只读 operation 和页面显式绑定完成。服务端按固定 Queue 实体与 Type
attribute_id 关系建立属性映射，补齐 required/空值校验，并直接使用同一映射写值；页面
使用 JSON bootstrap 与独立 dependence 代次；旧代请求不得递减新代 pending。解析失败的
code 必须留在 unresolved 集合，阻断确认和提交，只能按 code 重试最后失败的 dependence，
不得整表重载丢失未保存输入。历史草稿的 confirm 进度必须降级回参数页，只有当前代已完成
属性加载和参数采集时才可提交。创建成功后保持终态锁定，不能因乱序响应或弹窗关闭重复建件。
快照 partial 必须使用显式 `queue/backend/queue` 路径，并把已规范化的筛选条件显式交给分页，
避免 QueryBin 的 `framework` router 或独立请求 GET 污染链接。Queue 表单位于 OffCanvas iframe
时，只复用同源父后台页面已经完成 Backend attestation 的 `Weline.load('api')`；禁止放宽 iframe
bootstrap 门禁，也禁止在父 API 不可用时降级创建 frontend session。
Queue Type 的布尔启停值必须由模型 setter 转为 `smallint` 的整数 `0/1` 后保存。删除及批量
确认文案须在服务端翻译后再替换队列名/数量令牌，不能让翻译器提前清空 `%{1}`；空筛选结果
使用显式数组判断，确保首屏和 QueryBin 快照均呈现“暂无队列数据”。

## 公共契约

- `Weline\Queue\Api\QueueConsumerInterface`：新队列消费者契约。
- `Weline\Queue\Api\QueueTaskContextInterface`：执行时任务上下文，不暴露 Queue ORM 模型。
- `Weline\Queue\Api\QueueStatus`：`pending/running/done/error/stop` 的稳定常量。
- `Weline\Queue\QueueInterface`：旧版第三方兼容契约，仍可被收集和执行，新代码不再使用。

`queue:collect` 和 `queue:run` 同时识别新旧两套契约。旧接口的签名保持不变，不需要第三方模块立即迁移。

## 创建任务

跨模块写入统一通过 `w_query('queue', 'create', ...)` 或幂等 `createIfAbsent`：

```php
use Vendor\Module\Queue\EmailQueue;
use Weline\Queue\Api\QueueStatus;

$result = w_query('queue', 'create', [
    'class' => EmailQueue::class,
    'name' => (string)__('发送邮件'),
    'module' => 'Vendor_Module',
    'content' => [
        'to' => 'user@example.com',
        'subject' => '通知',
    ],
    'status' => QueueStatus::PENDING,
    'auto' => true,
    'biz_key' => 'mail:notice:1001',
    // 唯一 Scope 入参；省略时新建行在 save_before 捕获当前请求 Scope（无上下文→global）
    'scope_envelope' => \Weline\Framework\Runtime\ScopeEnvelope::of(
        \Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'default', 'default', 'normal')
    )->toArray(),
]);

$queueId = (int)($result['queue_id'] ?? 0);
```

公开 `create/createIfAbsent` 只接受 `status=pending`；`running/done/error/stop`
必须由专用控制操作或 Worker 终态协议进入。Scope 身份只允许通过单个 `scope_envelope`
入参写入并展开为 Queue v1 固定列；禁止平行散落 Scope 列，也禁止在 `content`
中混入 `scope_kind` / `scope_envelope` 等协议字段。`website_id=0` 的 channel
与 `scope_kind=global` 不是同一身份。`auto=true` 的 pending
新任务默认落库后**立即尝试派发**；显式 `dispatch=false` 时只建件，
后续再调用 `w_query('queue', 'dispatch', ...)`。分钟级 cron 仍是兜底调度。
派发使用 `Processer::createDetachedPhpArgv()`（POSIX `setsid` / Windows
Start-Process），避免在 WLS HTTP Worker 内用 `nohup ... &` 留下幽灵 PID。
如果 create/update/dispatch 加入调用方的受管事务，add/edit 事件和 Worker 派发
都通过 `afterCommit` 延后；rollback 不派发事件、不启动 Worker。显式 dispatch
在返回时尚未物理提交会返回 `dispatch_deferred=true`。QueryProvider 的 get、
update 和 dispatch 回读统一使用 fresh query，不命中 raw CAS 前的 model identity map。

`createIfAbsent` 的并发 loser 只在确认是 `idempotency_key` 唯一冲突时回读 winner。PostgreSQL/MySQL 的物理索引名可能经过 Schema 编译器重命名，判定必须使用 SQLSTATE 加逻辑约束/表/列诊断，不能只比较字面索引名；连接、权限或其他唯一列错误不会被当成幂等成功吞掉。

`createIfAbsent` 自己创建根事务时使用加法式 `WriteIntentTransactionCoordinatorInterface::runWrite()`。SQLite 因而在第一次幂等读取前以受框架 busy deadline 管理的 `BEGIN IMMEDIATE` 取得 writer reservation，避免并发 loser 带着旧快照反复得到 `SQLITE_BUSY_SNAPSHOT`；普通 `run()` 与只读事务仍保持 deferred。若调用方已经开启 SQLite 外层事务，该事务也必须从根边界使用 `runWrite()`，否则本操作 fail-fast，禁止在已有读快照上伪装升级。MySQL/PostgreSQL 唯一冲突后的锁定回读必须生成 `LIMIT/OFFSET ... FOR UPDATE`，不能生成非法的 `FOR UPDATE LIMIT ...`。

```php
w_query('queue', 'dispatch', ['queue_id' => $queueId]);
```

派发采用持久 fencing：调度器先以条件更新把 `pending` claim 为 `running`，写入
`dispatch_token`、30 秒 `dispatch_until` 与 `pid=0`，成功后才创建进程；Worker
必须携带并校验同一 token，校验成功后才写入自己的 PID。启动失败只允许相同
token 且仍为 `pid=0` 的调度者恢复 `pending`，不会覆盖已经被 Worker 推进的状态。
因此 `running+pid=0` 的有效 claim 也占用并发槽。

## 安全编辑、重排队、停止、接管与删除

队列状态、业务字段和 Worker ownership 的写入统一由
`Weline\Queue\Service\QueueDispatchService` 提供：

- `updatePendingQueueSafely()`：只允许
  `pending + finished=0 + pid=0 + token/until=NULL` 的 clean pending 行修改
  `type_id/name/module/biz_key/content/result/process/auto`，业务字段和完整快照共同 CAS；
- `requeueQueueSafely()`：仅对无 active/dirty attempt 的行执行
  `pending + finished=0`，并清理 PID/fence；reset/continue/retry 共用该边界，
  不探测、不终止 Worker；
- `stopQueueSafely()`：确认当前 Worker 已释放后，把同一执行代次条件更新为 `stop`；
- `takeoverQueueSafely()`：确认释放后，把同一执行代次重置为 `pending`，写入接管证据，
  再交给系统调度器或显式指定的 owner；
- `deleteQueueSafely()`：按完整 fence 与业务快照条件删除。任何
  active/dirty attempt 默认拒绝，只有显式 `force=true` 才尝试安全释放 Worker；
- `claimQueueForManualRun()`：clean pending 或无活动代次终态直接以单次 CAS
  进入 `running + 当前 CLI PID`；force 重建标记和清空输出与认领同一次落库；
- `markQueueWorkerExecutingSafely()` / `completeQueueWorkerSafely()` /
  `failQueueWorkerSafely()`：按 PID 与 dispatch-token 代次写入执行中、成功或失败终态；
- `releaseClaimedWorkerLease()`：Worker 退出时只清理自身 PID、队列名与 dispatch token
  精确匹配的受管租约，不发送信号。

控制操作遵循以下不变量：

1. `running + pid=0 + 有效 dispatch_token` 代表 Worker 启动窗口。控制方先以完整 fence
   做条件更新；若同 token 的 Worker 恰好完成 PID attach，只允许重新加载一次并验证
   该代次，绝不跟随变化后的 token 或 PID。
2. `pid>0 + 有效 dispatch_token` 只通过受管终止契约处理。实时进程必须同时匹配
   Queue ID、规范化名称、launch-id 与 dispatch-token；只有返回 `released=true` 才能
  更新 Queue 或删除记录，并且只清理该精确租约。
3. 未携带 dispatch token 的存活旧 Worker、当前进程自身，以及进程状态
   `UNKNOWN` 都 fail closed：不发信号、不改状态、不删除，调用方可根据
   `retryable/error_code` 决定重试或人工处理。
4. 状态更新和删除都携带 `id/type/name/module/biz_key/status/pid/token/until`
   以及 `finished/auto/content/result/process` 快照条件，避免覆盖新代次或并发业务编辑。
5. 后台表单编辑在同一 write-intent 根事务中完成主表 CAS、固定实体/Type 属性映射写入、
   required 校验和消费者校验。CAS 后重读绕过 model identity map，已有外层事务
   时拒绝嵌套；commit 后才派发 edit/add，Worker 只能看到旧版本或完整新版本。
6. `Weline_Queue::stop/takeover/edit/delete/reset/continue` 只在对应安全操作
   confirmed 且物理 commit 后派发；rollback 路径不产生成功事件。commit 后观察者或
   表单草稿清理异常只记录 warning，不得把已经成功的状态返回成失败而诱发重复操作。
7. stop/takeover/delete/dispatch/manual claim/transport terminate 等含 Worker 副作用的
   入口拒绝在已有数据库事务内执行，避免外层 rollback 恢复 Queue 行而
   OS 进程已被终止。
8. Worker 已确认释放后，即使派生业务更新、CAS 或冲突重读抛异常，也会通过 `finally`
   进入旧 PID/name/launch-id 的精确租约清理路径，且不会跟随新代次；租约存储自身故障
   按 fail-soft 契约返回失败，不把清理异常覆盖为业务状态写入成功。

带 dispatch token 的 `queue:run` Worker 会注册 shutdown 清理器，正常返回、异常和
PHP fatal 都会尝试移除自身精确租约。所有 Worker 的执行中、成功和失败写入
均校验当前 PID 与 token 代次，并以完整 Queue 快照 CAS。成功默认写
`done + finished=1`，失败写 `error`，两者清 PID/token/until；消费者显式留下
未完成的 `pending/error/stop` 时，成功收口会保留该业务状态。代次变化时旧 Worker
不写任何终态；shutdown 仍作为精确租约清理的幂等兜底。

## Scope v1 固定列边界

Queue 当前把 Scope 写入 `scope_kind`、Website/Store/Channel、`store_mode` 和
`scope_envelope_version` 固定列；这些 v1 列尚未单独持久化 `context_version`。
因此旧数据库标量的空值/整数字符串规范化只允许发生在
`ScopeEnvelope::fromV1StorageArray()`，核心 `ScopeIdentity::fromArray()` 始终要求
七个 identity 字段完整且类型规范。写入统一经过 `toV1StorageArray()`；只允许
envelope v1 + context v1，未来版本必须先扩展存储契约，禁止静默丢失版本信息。
v1 读取适配器只接受上述七个固定列的精确字段集合；额外传入
`context_version` 或其他未知字段同样拒绝，不能用 adapter 覆盖未来版本声明。
非空字符串必须已经规范，不执行 trim/lowercase 猜测。当前
`scope_website_code` 固定列上限为 64 字节；超过该上限的合法 Website code 会在
适配器中提前一致拒绝，正式支持需由后续 P1B/MIG 扩列到 Website 契约上限后再开放。

`website_id=0` 是合法系统默认站，必须按整数 0 往返；Global 使用
`scope_kind=global` 且 Website/Store/Channel/`store_mode` 全为 `NULL`，两者不可互换。
只有全部 Scope v1 固定列均为空的 pre-P1B 历史行可由迁移工具分类；
`queue:run` 不得把这种未迁移行猜成 Global，Scope 感知消费者必须在执行前拒绝；
任一列已有值时，未知版本、部分写入或非法字段组合必须抛错并停止 Scope 感知消费，
禁止通过 `null` 回退把损坏/未来信封降级成 Global。

遗留行工具（P1B-002，禁止信封 apply/cutover）：

```bash
php bin/w queue:scope:migrate help
php bin/w queue:scope:migrate preflight
php bin/w queue:scope:migrate quarantine
php bin/w queue:scope:migrate verify
```

- `help`：打印冻结 producer → kind 契约；
- `preflight`：只读分类 `mappable + cancelled + quarantine = legacy_rows`；
- `quarantine`：对歧义 unfinished 行写入 `SCOPE_QUARANTINE:` 标记并 `auto=0/stop`，不可领取；
- `verify`：未标记的 unfinished 遗留行必须为 0；
- 信封回填/cutover 归属 `TASK-MIG-P1A`，`QueueScopeMigrationService::apply()` 硬拒绝盲回填 global。
- 可从冻结业务聚合恢复 Scope 的旧 handler 实现
  `LegacyQueueScopeProviderInterface`；恢复方法只能读取不可变 Queue payload/聚合快照，
  必须确定性、只读、无外部副作用。返回空值或抛错一律 quarantine，不得猜 Global 或零号站。

按业务键去重时先读取：

```php
$existing = w_query('queue', 'getByBizKey', [
    'biz_key' => 'mail:notice:1001',
]);
```

查询、统计和更新的完整参数以以下命令为准：

```bash
php bin/w query:help queue
```

## 新消费者

普通消费者实现 `QueueConsumerInterface`。需要按 Scope kind/维度 fail-closed 的
消费者另实现 `ScopedQueueConsumerInterface`，声明 `acceptedScopeKinds()` 与
`requiredScopeDimensions()`；`queue:run` 在执行前经 `ScopedQueueConsumerGuard`
校验，拒绝时不进入 `execute()`。消费者可通过
`QueueTaskContextInterface::getScopeEnvelope()` 读取 typed 信封。

```php
namespace Vendor\Module\Queue;

use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

final class EmailQueue implements QueueConsumerInterface
{
    public function name(): string
    {
        return (string)__('邮件发送队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('异步发送邮件');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        $content = json_decode($queue->getContent(), true);
        if (!is_array($content) || empty($content['to'])) {
            $queue->setResult((string)__('缺少收件人'));
            return false;
        }

        return true;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = json_decode($queue->getContent(), true, flags: JSON_THROW_ON_ERROR);
        // 执行模块自身的发送服务。
        $queue->setProcess((string)__('邮件发送完成'));
        $queue->persist();

        return (string)__('执行成功');
    }
}
```

消费者可在自身当前 attempt 内更新并通过 `persist()` 保存
`content/result/process`；需要表达后续业务态时，可显式留下
`pending/error/stop + finished=false`，Worker 成功收口会在同一 fence 下保留。
`pid/dispatch_token/dispatch_until` 属于 Worker ownership，消费者不得改写；
`running/done` 由认领与终态协议写入。消费者不应依赖具体 Queue 模型、表名、
查询对象或 EAV 内部类。

## 运行命令

```bash
php bin/w queue:collect
php bin/w queue:type:listing
php bin/w queue:type:listing Vendor_Module EmailQueue
php bin/w queue:run --id=77
php bin/w queue:run --id=77 --force
```

- `queue:collect` 扫描激活模块的 `Queue/` 类，只登记实现新或旧消费契约的可实例化类。
- `queue:type:listing` 列出已登记类型，传入词条可按类名、队列名或模块名搜索。
- `queue:run` 按 `queue_id` 执行一条任务。
- `--force --takeover-only`（或 `--no-execute`）始终安全接管为 pending 后立即返回，
  由系统调度；普通 `--force` 遇到另一 PID 的 running 行也按该路径返回。
- 若行本身无 PID/fence 且可手工认领，当前 CLI 以单次 CAS 直接写
  `running + 当前 PID`，同步注入 `_force_rebuild=1`、清历史输出，并在本进程执行。
- 两条 force 路径都只会终止 PID、进程名、launch-id 与 Queue 参数全部匹配的
  受管 Worker；身份不明或未携 token 的存活旧 Worker 会 fail closed。

当前没有 `queue:status` 命令。状态查询使用 `w_query('queue', 'get'|'list'|'stats', ...)` 或后台 Queue 管理页。

## 调度与资源配置

当前生效配置：

- `queue.cron.max_concurrent`：自动队列的最大并发数。
- `queue.worker.memory_limit`：队列 Worker 默认内存上限。
- `queue.worker.memory_limit_by_class.<FQCN>`：按消费者类覆盖内存上限。

队列调度器只派发 clean `auto=1 + status=pending + finished=0` 任务。reconcile
对 `running+pid=0+有效 token` 保留至 `dispatch_until`，过期后按同一快照恢复
pending；对已 attach/PID>0 且退出或身份不符的 attempt，完成证据写 done，
显式恢复契约可写 pending，其余写 error 并保留 token 作为终止证据。历史
tokenless `running+pid=0` 行作为兼容分支重置 pending；恢复契约类型则按契约决定
pending 或 error。所有 reconcile 写入都带完整决策快照 CAS。

## 异步事件 Transport

`Weline_Queue` 为 Framework 发布 `AsyncEventTransportInterface` 的可选实现。每个
Delivery attempt 使用固定幂等键 `delivery:{delivery_id}:attempt:{attempt_no}`，先以
`dispatch=false`、`auto=false` 创建唯一 Queue，待 Framework 权威 Delivery 写入
Queue handle 后，唯一 dispatch 才以 CAS 激活 `auto=true` 并派发。这样即使在建件后、
Delivery 绑定前崩溃，普通 pending-auto scanner 也不会抢跑。Queue content 固定只含
`delivery_id` 与 `attempt_no`。

分钟级 Cron 的执行顺序固定为：Outbox relay、过期 provisioning/queued 恢复、到期
retry 建件、超时 Worker 终止确认、普通 pending Queue 派发；有界 GC 在该投递关键
路径之后执行。超时终止使用同一 dispatch token 作为 fence；未确认终止时每 10 秒
重试，第三次仍不确定由 Framework 把 Delivery 置为
`transport_termination_unconfirmed` 死信，禁止并发下一 attempt。

Delivery 是业务投递的唯一权威状态；Queue 只记录某一 attempt 的 transport 证据。
Queue Worker 只依赖 `AsyncEventDeliveryRunnerInterface`，不读取或改写 Framework
的 Outbox/Delivery ORM 模型。Observer 成功、重试或死信先由 Runner CAS 写入
Delivery，再由 Queue 命令把本 Queue 记录为 done/error。

## 模块边界

- 生产者读写队列：使用 `w_query('queue', ...)`。
- 消费者执行上下文：使用 `Weline\Queue\Api\*`。
- 使用 Queue API 的模块必须在 `etc/module.php` 的 `requires` 中声明 `Weline_Queue`，并在 Composer `require` 中声明 `weline/module-queue`。
- 模块 Setup 只安装自身 schema；队列类型由 `queue:collect` 及 `setup:upgrade` 后置收集器统一登记。
- 队列模型、Type 模型、Helper 和 Console 类都是 `Weline_Queue` 内部实现，不是跨模块 API。

## 验证

```bash
php -l app/code/Weline/Queue/Api/QueueConsumerInterface.php
php -l app/code/Weline/Queue/Api/QueueTaskContextInterface.php
php -l app/code/Weline/Queue/Api/QueueStatus.php
php bin/w queue:collect
php bin/w queue:type:listing
php bin/w query:help queue
```
