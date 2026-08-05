# Queue operations and backend protocol reference

Prefer the framework query provider and queue CLI over direct database access unless source-level diagnosis requires model internals.

## First Pass

1. Read the current queue source before assuming docs are current:
   - `app/code/Weline/Queue/extends/module/Weline_Framework/Query/QueueQueryProvider.php`
   - `app/code/Weline/Queue/Console/Queue/Collect.php`
   - `app/code/Weline/Queue/Console/Queue/Run.php`
   - `app/code/Weline/Queue/Console/Queue/Type/Listing.php`
   - `app/code/Weline/Queue/Model/Queue.php`
2. Use `w_query('queue', ...)` for runtime queue reads and business-level writes. Direct DB reads can miss framework casting, EAV/model behavior, event dispatching, and current query-provider semantics.
3. Preserve side-effect boundaries. `stats`, `get`, `getByBizKey`, `list`, `getTypeIdByClass`, and `queue:type:listing` are diagnostic. `create`, `update`, `delete`, `queue:collect`, and `queue:run` change state or execute work.
4. New cross-module consumers implement `Weline\Queue\Api\QueueConsumerInterface` and receive `QueueTaskContextInterface`. `Weline\Queue\QueueInterface` remains a runtime compatibility boundary for existing third-party consumers only.

## Queue CLI

Use these commands from the repository root.

```powershell
php bin/w queue:collect
```

Collect queue types from modules into `weline_queue_type`. Run this after adding or changing queue classes, or when `getTypeIdByClass` cannot resolve a class. The collector only registers instantiable classes that implement either the public `Weline\Queue\Api\QueueConsumerInterface` or the legacy `Weline\Queue\QueueInterface`; helper/static classes in a `Queue/` directory are not queue implementations.

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

- `--force --takeover-only` / `--no-execute`, or ordinary force against another live PID,
  safely takes over the exact managed generation and returns; the system scheduler owns later execution.
- A clean pending or inactive terminal row is claimed directly by the current CLI in one CAS.
  That same CAS injects `_force_rebuild=1` when content is JSON and clears previous `result/process`.
- Unknown identity, tokenless live workers, self-termination, and generation changes fail closed.
- Run stop, takeover, delete, dispatch, manual claim, and transport termination outside any
  caller-owned database transaction. They fail closed when a transaction is already active so
  an outer rollback cannot resurrect Queue state after an OS Worker side effect.

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
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue','update',['queue_id'=>77,'patch'=>['name'=>'Renamed task','result'=>'','process'=>'']]));"
```

`update` only accepts a clean pending row (`finished=0`, `pid=0`, no dispatch fence).
Status, completion, PID, and dispatch-fence fields require dedicated control operations.

Delete only when intended:

```powershell
php -r "require __DIR__ . '/app/bootstrap.php'; print_r(w_query('queue','delete',['queue_id'=>77]));"
```

Use `force => true` only when an active/dirty attempt is explicitly intended for deletion; force
still requires safe release of the exact managed Worker generation.
After a release is confirmed, entering exact lease cleanup is a `finally` invariant even when a
derived update, CAS, or conflict read throws. Cleanup stays scoped to the released
PID/name/launch-id; a lease-store failure remains best-effort and must not follow a newer claim.

## Provider Operations

`w_query('queue', OP, PARAMS)` currently supports:

- `get` / `load`: require `queue_id` or `id`; return one queue row or `null`.
- `getByBizKey`: require `biz_key`; return the latest matching row.
- `list`: filters include `page`, `page_size`, `module`, `status`, `type_id`, `queue_id`, `biz_key`, and `q`; returns `items` and `pagination`.
- `stats`: return counts for `all`, `pending`, `running`, `done`, `error`, and `stop`.
- `getTypeIdByClass`: resolve a public or legacy queue consumer class to `type_id`; if missing, it runs collection internally.
- `create`: require `name`, `module`, and either `type_id` or `class`; optional `content`, `auto`, and `biz_key`. If supplied, `status` must be `pending`.
- `dispatch`: explicitly request dispatch. Inside a managed caller transaction it registers an
  after-commit callback and returns `dispatch_deferred=true`; rollback starts no Worker.
- `update`: locate by `queue_id`/`id` or `biz_key`; pass `patch` or top-level fields. Safe patch fields are `name`, `module`, `content`, `result`, `process`, `biz_key`, `auto`, and `type_id`, and only on clean pending rows.
- `takeover`: safely release an active managed generation and return it to pending ownership.
- `delete`: locate by `queue_id`/`id` or `biz_key`; any active/dirty attempt requires `force`, and force remains fail-closed.

Queue rows still use `pending`, `running`, `done`, `error`, and `stop`, but public creation only
accepts `pending`; other states are entered through dedicated controls and Worker terminal writes.
Queue add/edit events are after-commit notifications. Provider reads after raw CAS use fresh
queries, so consumers should use returned `data` or the event's Queue payload rather than reloading
through a request-local identity map.

## Backend Browser Operations

Backend Queue pages use `Weline.Api.resource('queue_admin')`; this provider is not a cross-module
producer API. It is authenticated as Backend and publishes only `snapshot`, `searchTypes`,
`typeAttributes`, `save`, `action`, `batchAction`, `setTypeEnabled`, and
`resolveAttributeDependence` with explicit source/param-map ACL policies.

- Never add `frontend=true` to the general `queue` provider for page convenience.
- Browser action allowlists exclude takeover, force, PID, dispatch token, owner, and manual claim.
- `delete` uses `Weline_Queue::delete`; type toggles use `Weline_Queue::type_manage`; entity-valued
  `typeAttributes` requires `Weline_Queue::form`. Do not reuse view/menu ACLs for writes.
- `save` derives `module` from the selected Queue Type, builds one fixed-entity Type attribute map,
  enforces required values, and uses that map for EAV writes. Main-row CAS, EAV writes, and consumer
  validation commit atomically; post-commit event/draft failures return warnings, never false rollback.
- EAV dependence resolution validates both codes against the selected Queue Type and fixes the
  Queue EAV entity server-side; the client cannot select a model class or entity.
- Queue templates must contain no direct Controller business request or native/Ajax fallback. Legacy
  GET mutation routes, direct form POST, Controller attribute reads, and `api_action/api_batch`
  aliases are retired `410` responses only.
- Form bootstrap uses JSON and never restores a draft directly into confirm. Dependence requests carry
  a separate generation so an old type cannot decrement the current pending count; failed codes remain
  unresolved and retry only the last failed dependence for that code, without reloading the whole form.
  Confirm/submit share the current-page, loaded, collected, pending and unresolved gates. A successful
  create remains terminal to prevent duplicates.

Validate discovery with:

```powershell
php bin/w framework:compile
php bin/w query:help queue_admin
```

## Validation

After queue registration or consumer-contract filtering changes:

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
