---
name: queue
description: WelineFramework queue diagnostics and operations guide. Use when working on Weline\Queue, queue CLI commands, queue registration, queue_id/biz_key lookups, w_query('queue', ...) CRUD/stat queries, or failures involving queue:collect, queue:run, QueueInterface, and framework queue rows.
---

# Queue

Use this skill for WelineFramework queue inspection, repair, and operation in this source repository. Prefer the framework query provider and queue CLI over direct database poking unless source-level diagnosis requires model internals.

PageBuilder-specific queue knowledge has moved with `app/code/GuoLaiRen/PageBuilder` to `E:\公司\远程\src\weline`. If a task mentions PageBuilder queues, switch to the target repository and use its PageBuilder and queue skills there.

## First Pass

1. Read the current queue source before assuming docs are current:
   - `app/code/Weline/Queue/extends/module/Weline_Framework/Query/QueueQueryProvider.php`
   - `app/code/Weline/Queue/Console/Queue/Collect.php`
   - `app/code/Weline/Queue/Console/Queue/Run.php`
   - `app/code/Weline/Queue/Console/Queue/Type/Listing.php`
   - `app/code/Weline/Queue/Model/Queue.php`
2. Use `w_query('queue', ...)` for runtime queue reads and business-level writes. Direct DB reads can miss framework casting, EAV/model behavior, event dispatching, and current query-provider semantics.
3. Preserve side-effect boundaries. `stats`, `get`, `getByBizKey`, `list`, `getTypeIdByClass`, and `queue:type:listing` are diagnostic. `create`, `update`, `delete`, `queue:collect`, and `queue:run` change state or execute work.

## Queue CLI

Use these commands from the repository root.

```powershell
php bin/w queue:collect
```

Collect queue types from modules into `weline_queue_type`. Run this after adding or changing queue classes, or when `getTypeIdByClass` cannot resolve a class. The collector must only register classes that implement `Weline\Queue\QueueInterface`; helper/static classes in a `Queue/` directory are not automatically queue implementations.

```powershell
php bin/w queue:type:listing
php bin/w queue:type:listing ExampleQueue
php bin/w queue:type:listing Vendor_Module
```

List registered queue types. Extra arguments are search terms matched against name, module, and class.

```powershell
php bin/w queue:run --id=77
php bin/w queue:run --id=77 -f
php bin/w queue:run --id=77 --force
```

Run one queue item by `weline_queue.queue_id`.

Use `-f/--force` only when intentionally taking over or rebuilding the same queue item. Current behavior:

- If the same queue is marked `running`, force mode terminates the old same-ID process before continuing.
- It injects `_force_rebuild=1` into queue content when content is JSON.
- It clears previous `result` and `process` so new logs are readable.

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
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue', 'getByBizKey', ['biz_key' => 'example:1']));"
```

List recent queues without dumping huge stream logs:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; $r=w_query('queue','list',['page_size'=>10]); foreach($r['items'] as $it){ $row=is_object($it)&&method_exists($it,'getData')?$it->getData():$it; echo json_encode(['queue_id'=>$row['queue_id']??null,'status'=>$row['status']??null,'module'=>$row['module']??null,'name'=>$row['name']??null,'biz_key'=>$row['biz_key']??null], JSON_UNESCAPED_UNICODE), PHP_EOL; }"
```

Filter by status or search text:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; $r=w_query('queue','list',['status'=>'error','q'=>'example','page_size'=>20]); foreach($r['items'] as $it){ $row=is_object($it)&&method_exists($it,'getData')?$it->getData():$it; echo ($row['queue_id']??'') . ' ' . ($row['status']??'') . ' ' . ($row['name']??'') . PHP_EOL; }"
```

Resolve a type id by class:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; var_export(w_query('queue','getTypeIdByClass',['class'=>'Vendor\\Module\\Queue\\ExampleQueue']));"
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

## Validation

After queue registration or QueueInterface filtering changes:

```powershell
php -l app/code/Weline/Queue/Helper/Helper.php
php bin/w queue:collect
php bin/w queue:type:listing
```

After command behavior changes:

```powershell
php bin/w queue:run --help
php bin/w queue:collect --help
php bin/w queue:type:listing --help
```
