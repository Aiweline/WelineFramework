# Weline_CustomerAsset

`Weline_CustomerAsset` owns customer asset accounts, immutable ledger events,
reservations and committed-reservation returns. New tender defaults to rollout
mode `off`; the Website allowlist is persisted in PostgreSQL so migration
commands and fresh WLS workers share one fail-closed decision. Existing
settlement/refund obligations continue to converge.

- Current contract: [customer-asset.md](customer-asset.md)
- AI 上下文：`prepare_project` 就绪后由 `resolve_task_context` 动态返回。

P4D-002 adds Payment reserve-before-cash integration and the official
`Weline_Customer` account-layout read projection. Order snapshots and refund
outbox orchestration remain owned by `Weline_Order`.

MIG-P4D adds the registered-full-clone-only
`commerce:migrate-p4d-customer-asset` command. It replays every account ledger,
checks reservation obligations, creates an immutable checkpoint, enters shadow,
requires fresh verification, and only then opens one exact Website allowlist.
Rollback closes new tender without deleting ledger or existing obligations.
