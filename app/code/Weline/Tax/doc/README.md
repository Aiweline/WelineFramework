# Weline_Tax

Website-owned Tax rules, typed Store Scope configuration, deterministic
integer-minor calculation, independent shadow verification and durable
rule-set LKG.

## P3B-001 current source

- Production `TaxEngine` reads enabled `TaxClass` and `TaxRule` rows from ORM.
- Tax business rules are exact-Website facts; they never fall back across
  Websites. `website_id=0/code=default` remains a valid exact Website.
- `TaxScopeConfig` resolves Website/Store through public catalog interfaces
  and reads ordinary values through SystemConfig typed Scope fallback.
- `half_up` is calculated per line with integer arithmetic and explicit
  multiplication/total overflow guards.
- Missing classes/rules, malformed requests, Scope failures and Schema drift
  throw stable Tax conflicts. They never become a soft zero-tax success.

## Shadow and LKG

- `TEST-P3B-01` compares two independent sources: ORM current source and an
  immutable frozen rule-set snapshot.
- A complete window contains at least 100 unique quote requests under one
  Scope and one primary rule-set hash.
- Both sides must conserve `sum(lines) == total`; line sets, amounts, rates
  and rule versions are compared symmetrically.
- Only a complete zero-diff window may write `TaxRuleSetLkg`.
- LKG stores replayable rules and Scope configuration, never a quote result.
- Reads require the same typed Scope, Tax Schema, rule-set hash and verified
  flag; the stored snapshot is hash-verified again before use.

## Checkout / Order / Invoice snapshots

- `CheckoutTaxAdvisor` calculates from the canonical server request and pins
  Website/Store/Scope, address, currency, stable `line_uuid`, Tax Schema and
  rule-set hash.
- Engine failure may replay only a verified LKG with the exact same Scope,
  Schema and rule-set hash. Missing or cross-Scope LKG blocks checkout.
- `off` and `shadow` do not change checkout money. `on` freezes conserved Tax
  totals and line snapshots into Order and OrderItem.
- Invoice copies and verifies the frozen Order Tax snapshot and never invokes
  the Tax engine. Historical empty Tax data is exposed as immutable
  `tax_engine=none` zero Tax and is never recalculated.
- Quote submission rebuilds the current request, so rule, Scope or line drift
  requires a fresh quote.

## Current boundary

`TASK-P3B-001`, `TASK-P3B-002` and `TASK-MIG-P3B` are complete.
`etc/module.php` publishes
`TaxEngineInterface => TaxEngine` and
`CheckoutTaxAdvisorInterface => CheckoutTaxAdvisor`; Checkout provides the
read-only `TaxShadowQuoteSourceInterface` adapter. The default production
rollout remains off and completing the migration task does not authorize
production `on`.

## MIG-P3B durable cutover

- Create a registry-owned **full** clone; schema-only clones cannot provide
  persisted Checkout observation facts.
- `preflight` accepts one exact `(website_id, store_id, channel_id)` and at
  least 100 unique, normalized, identity-free quote requests.
- `apply` writes an immutable checkpoint, compares current ORM rules against
  the frozen rule snapshot, persists a verified LKG and remains in `shadow`.
- A separate process must run `verify`; only then may `allowlist` persist the
  exact Scope tuple. `on` still requires a separate explicit production token.
- `rollback` writes `off` and clears allowlist while retaining checkpoint,
  journal, verified LKG and historical Tax snapshots.
- Every command rejects unknown clones, fingerprint drift, missing checkpoints,
  mixed observation windows and unavailable rollout configuration with exit
  code `2`.

```bash
php bin/w mig:foundation clone-create --mode=full --purpose=p3btax
php bin/w commerce:migrate-p3b-tax preflight --database=mig_clone_... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax apply --database=mig_clone_... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax verify --database=mig_clone_... --checkpoint=p3btax-...
php bin/w commerce:migrate-p3b-tax allowlist --database=mig_clone_... --checkpoint=p3btax-... --website=0 --store=1 --channel=1
php bin/w commerce:migrate-p3b-tax rollback --database=mig_clone_... --checkpoint=p3btax-...
```

## Verification

```bash
php bin/w setup:schema:check -m Weline_Tax
php bin/w phpunit:run --name=TaxEngineAndShadowTest
php bin/w phpunit:run --name=TaxCurrentSourceDatabaseIntegrationTest
php bin/w phpunit:run --name=CheckoutTaxIntegrationTest
php bin/w phpunit:run --module=Weline_Tax
php bin/w phpunit:run --module=Weline_Checkout
php bin/w phpunit:run --module=Weline_Order
php bin/w setup:di:compile
```

The database integration test writes unique Tax rows and a verified LKG inside
one Framework transaction, reads the LKG through a fresh store instance, then
rolls the whole transaction back and proves no fixture remains.

Module version: `2.1.3`.
