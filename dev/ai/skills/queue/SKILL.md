---
name: queue
description: Diagnose Weline Queue registration, rows, consumers, provider operations, CLI execution, and worker-state failures. Use for queue:collect/queue:run, queue_id/biz_key, w_query('queue', ...), or explicit queue mutation; diagnosis is read-only unless the requested scope clearly includes a state change.
---

# Queue diagnosis and operations

## Safety boundary

- Start read-only with current Queue source, `query:help queue`, `stats`, `get`, `getByBizKey`, `list`, type listing, and logs.
- `create`, `update`, `delete`, `dispatch`, `takeover`, `queue:collect`, `queue:run`, and `--force` mutate state or execute work. Use them only when the request scope explicitly includes that operation, after resolving the exact row/type/generation.
- Never force/delete/take over unknown or live work merely to simplify diagnosis. Respect transaction, lease, PID, token, and generation fail-closed behavior.
- New consumers implement `Weline\Queue\Api\QueueConsumerInterface`; legacy `QueueInterface` remains a compatibility boundary.

## Workflow

1. Inspect current QueryProvider, Queue Model, consumer contract, and relevant CLI source.
2. Identify the exact queue by `queue_id` or `biz_key`, its type, status, owner/generation, and side-effect risk.
3. Use the smallest read operation that proves the diagnosis.
4. If mutation is authorized, preview/record the target and execute the narrowest dedicated control outside caller-owned transactions.
5. Validate returned state plus worker/lease/event effects and report cleanup/recovery.

## Validation

- Registration/consumer changes: compile or collect, then list/resolve the exact type.
- Command changes: inspect `--help` and run the narrowest safe scenario.
- Backend changes: verify `queue_admin` descriptor/ACL and the real authenticated operation; never expose the general queue provider to frontend for convenience.
- Report exact IDs, operation, before/after state, command/provider result, and any partial side effect.

Read [references/operations-and-backend.md](references/operations-and-backend.md) only when exact CLI/provider parameters, mutation semantics, or backend protocol details are needed.
