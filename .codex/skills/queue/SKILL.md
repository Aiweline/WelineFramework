---
name: queue
description: WelineFramework queue diagnostics and operations guide. Use when working on Weline\Queue, PageBuilder queue/SSE flows, queue CLI commands, queue registration, queue_id/biz_key lookups, w_query('queue', ...) CRUD/stat queries, or failures involving queue:collect, queue:run, QueueInterface, AiSitePlanQueue, AiSiteTaskPlanQueue, or AiSiteBuildQueue.
---

# Queue

Use this skill for WelineFramework queue inspection, repair, and operation. Prefer the framework query provider and queue CLI over direct database poking unless source-level diagnosis requires model internals.

## First Pass

1. Read the current queue source before assuming docs are current:
   - `app/code/Weline/Queue/extends/module/Weline_Framework/Query/QueueQueryProvider.php`
   - `app/code/Weline/Queue/Console/Queue/Collect.php`
   - `app/code/Weline/Queue/Console/Queue/Run.php`
   - `app/code/Weline/Queue/Console/Queue/Type/Listing.php`
   - `app/code/Weline/Queue/Model/Queue.php`
2. For PageBuilder queue work, also inspect:
   - `app/code/GuoLaiRen/PageBuilder/Queue/AiSitePlanQueue.php`
   - `app/code/GuoLaiRen/PageBuilder/Queue/AiSiteTaskPlanQueue.php`
   - `app/code/GuoLaiRen/PageBuilder/Queue/AiSiteBuildQueue.php`
   - `app/code/GuoLaiRen/PageBuilder/Http/Sse/QueueDbWriter.php`
3. Use `w_query('queue', ...)` for runtime queue reads and business-level writes. Direct DB reads can miss framework casting, EAV/model behavior, event dispatching, and current query-provider semantics.
4. Preserve side-effect boundaries. `stats`, `get`, `getByBizKey`, `list`, `getTypeIdByClass`, and `queue:type:listing` are diagnostic. `create`, `update`, `delete`, `queue:collect`, and `queue:run` change state or execute work.
5. PageBuilder queue execution is system-scheduler owned. Controllers, SSE endpoints, observers, recovery helpers, and browser-triggered flows must create or update queue rows only; they must not call `queue:run`, spawn PHP queue workers, call `Weline\Cron\Helper\Process::create`, or otherwise self-dispatch queues. Violating this can run long AI work inside the wrong worker/request lifecycle and cause OOM.

## Queue CLI

Use these commands from the repository root.

```powershell
php bin/w queue:collect
```

Collect queue types from modules into `weline_queue_type`. Run this after adding or changing queue classes, or when `getTypeIdByClass` cannot resolve a class. The collector must only register classes that implement `Weline\Queue\QueueInterface`; helper/static classes in a `Queue/` directory are not automatically queue implementations.

```powershell
php bin/w queue:type:listing
php bin/w queue:type:listing PageBuilder
php bin/w queue:type:listing AiSitePlanQueue
```

List registered queue types. Extra arguments are search terms matched against name, module, and class.

```powershell
php bin/w queue:run --id=77
php bin/w queue:run --id=77 -f
php bin/w queue:run --id=77 --force
```

Run one queue item by `weline_queue.queue_id`. `--id` is the queue primary key, not a PageBuilder `session_id`.

Do not use `queue:run` as the normal PageBuilder test path. PageBuilder plan/task/build queues are expected to be picked up by the system scheduler, usually within about one minute. In PageBuilder workflows, create or reset the queue to `pending` and observe it through `w_query`, SSE, or polling instead of starting it manually.

Use `-f/--force` only when intentionally taking over or rebuilding the same queue item. Current behavior:

- If the same queue is marked `running`, force mode terminates the old same-ID process before continuing.
- It injects `_force_rebuild=1` into queue content when content is JSON.
- It clears previous `result` and `process` so new logs are readable.
- PageBuilder plan/task/build queues use force mode to change execution tokens and avoid `duplicate_stream` short-circuit behavior.

Do not cite `queue:status` as a real command unless the current source contains a command class for it.

## w_query Examples

Bootstrap the app for ad hoc diagnostics:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; var_export(w_query('queue', 'stats'));"
```

Read one queue:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue', 'get', ['queue_id' => 77]));"
```

Find latest queue by business key:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue', 'getByBizKey', ['biz_key' => 'site:abc:plan']));"
```

List recent PageBuilder queues without dumping huge AI stream logs:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; $r=w_query('queue','list',['module'=>'GuoLaiRen_PageBuilder','page_size'=>10]); foreach($r['items'] as $it){ $row=is_object($it)&&method_exists($it,'getData')?$it->getData():$it; echo json_encode(['queue_id'=>$row['queue_id']??null,'status'=>$row['status']??null,'name'=>$row['name']??null,'biz_key'=>$row['biz_key']??null], JSON_UNESCAPED_UNICODE), PHP_EOL; }"
```

Filter by status or search text:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; $r=w_query('queue','list',['status'=>'error','q'=>'第一阶段','page_size'=>20]); foreach($r['items'] as $it){ $row=is_object($it)&&method_exists($it,'getData')?$it->getData():$it; echo ($row['queue_id']??'') . ' ' . ($row['status']??'') . ' ' . ($row['name']??'') . PHP_EOL; }"
```

Resolve a type id by class:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; var_export(w_query('queue','getTypeIdByClass',['class'=>'GuoLaiRen\\PageBuilder\\Queue\\AiSitePlanQueue']));"
```

Create a queue through the provider:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue','create',['class'=>'Vendor\\Module\\Queue\\ExampleQueue','name'=>'Example task','module'=>'Vendor_Module','content'=>['foo'=>'bar'],'biz_key'=>'example:1']));"
```

Update a safe subset of fields:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue','update',['queue_id'=>77,'patch'=>['status'=>'pending','pid'=>0,'result'=>'','process'=>'']]));"
```

Delete only when intended:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue','delete',['queue_id'=>77]));"
```

Use `force => true` only when the queue is running and deletion is explicitly intended.

## Provider Operations

`w_query('queue', OP, PARAMS)` currently supports:

- `get` / `load`: require `queue_id` or `id`; return one queue row or `null`.
- `getByBizKey`: require `biz_key`; return the latest matching row.
- `list`: filters include `page`, `page_size`, `module`, `status`, `type_id`, `queue_id`, `biz_key`, and `q`; returns `items` and `pagination`.
- `stats`: return counts for `all`, `pending`, `running`, `done`, `error`, and `stop`.
- `getTypeIdByClass`: resolve a `QueueInterface` class to `type_id`; if missing, it runs collection internally.
- `create`: require `name`, `module`, and either `type_id` or `class`; optional `content`, `status`, `auto`, `biz_key`.
- `update`: locate by `queue_id`/`id` or `biz_key`; pass `patch` or top-level fields. Safe patch fields include `name`, `module`, `status`, `content`, `result`, `process`, `biz_key`, `auto`, `finished`, `pid`, and `type_id`.
- `delete`: locate by `queue_id`/`id` or `biz_key`; running queues require `force`.

Valid statuses are `pending`, `running`, `done`, `error`, and `stop`.

## PageBuilder Queue Notes

Registered PageBuilder queue implementations:

- `GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue`: stage-one plan generation.
- `GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue`: stage-two task-plan generation.
- `GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue`: site build execution.

`GuoLaiRen\PageBuilder\Queue\AiSiteAgentForQueue` is a static helper/factory, not a `QueueInterface` implementation. Do not treat every class under `Queue/` as executable queue type.

PageBuilder queues write lifecycle/progress logs into `weline_queue.result` and sometimes `process` through `w_query('queue', 'update', ...)` and `QueueDbWriter`. When diagnosing SSE or background progress, inspect the queue row and the session scope together; SSE should display telemetry, not become a separate execution engine.

For PageBuilder reruns, prefer scheduler-safe recovery:

1. Find the real `queue_id` with `w_query('queue','list', ...)` or `getByBizKey`.
2. Inspect the queue row and logs.
3. If the queue should retry, update or recreate the row as `pending`, `pid=0`, `finished=0`, with an operator-visible `process` such as `等待系统定时任务调度，通常约 1 分钟内开始执行。`.
4. Wait at least one scheduler interval, usually about one minute, then re-read the queue row and session scope.
5. Do not run `php bin/w queue:run --id=<queue_id>` or `-f` for PageBuilder normal flow validation unless the user explicitly asks for a manual scheduler bypass. If such a bypass is requested, call out that it is not the production path and can mask controller/SSE dispatch bugs.

When fixing PageBuilder code, treat direct queue execution as a bug:

- Start endpoints such as `post-start-plan`, `post-start-task-plan`, and `post-start-build` should create the queue row and return queue metadata only. They must not start, probe, heal, reset, or otherwise manage queue execution.
- Block-level AI operations follow the same rule: refine, rebuild, create, delete, regenerate, or multi-block partial rebuild must enqueue work with explicit scope metadata such as `stage`, `page_key` / `page_type`, `block_key`, `task_key`, `bucket`, `target_scope`, and operation details. The queue may target a single block or a small set of blocks, but execution still belongs to the system scheduler.
- SSE observers should wait, poll, and display `pending` / `running` / `done` / `error`; while `pending`, `queued`, or `running` with `pid<=0`, they should tell the user the system scheduler will usually dispatch within about one minute and that the current progress window can be closed while they continue other work.
- Controller/SSE recovery paths must not self-heal queue execution. If an operator or backend recovery flow intentionally retries work, it should create or update the queue row/state and then wait for the system scheduler; it still must not launch a worker from the request lifecycle.
- Queue classes may execute work only when invoked by the framework queue runner/system scheduler.

## Validation

After queue registration or QueueInterface filtering changes:

```powershell
php -l app/code/Weline/Queue/Helper/Helper.php
php bin/w queue:collect
php bin/w queue:type:listing PageBuilder
```

After command behavior changes:

```powershell
php bin/w queue:run --help
php bin/w queue:collect --help
php bin/w queue:type:listing --help
```

For PageBuilder queue fixes, add or run targeted PageBuilder unit/integration tests when available, then verify with `w_query('queue','stats')` or a focused `list` query. Avoid notification-capable unrelated queue flows unless the user explicitly asks.
