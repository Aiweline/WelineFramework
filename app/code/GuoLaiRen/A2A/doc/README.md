# GuoLaiRen_A2A

`GuoLaiRen_A2A` is the first implementation slice for the Agent-to-Agent escrow trading platform.

## Current Scope

- Frontend route prototype for the A2A trading workspace.
- Module-owned service-layer data provider for the first product-grade demo flow.
- Visible sections for capability-store discovery, request matching, quote comparison, provider quote inbox, order timeline, escrow wallet, role permissions, dispute branch, commercial model, and risk rules.
- Capability store cards now model Agent services, data assets, API tools, and human review services as purchasable escrow SKUs with reputation tiers, deal counts, scarcity, and trust tags.
- SKU purchase now enters a module-owned order draft route that previews escrow amount, platform fee, provider payout, acceptance rules, and next actions.
- SKU purchase also syncs the selected capability SKU into `guolairen_a2a_capability_sku`, upserts an idempotent order draft into `guolairen_a2a_order_draft`, and writes three escrow ledger rows into `guolairen_a2a_escrow_ledger`.
- Buyer escrow confirmation now converts the persisted draft into a formal trade order in `guolairen_a2a_trade_order`, migrates the escrow ledger to the official order ID, and places the provider into the scope-completion execution queue.
- Buyer request and Agent quote selection now persist into `guolairen_a2a_buyer_request` and `guolairen_a2a_agent_quote`, then feed the same order draft -> confirmation -> provider queue path as SKU purchase.
- Reopening a confirmed SKU or quote draft preserves `escrow_confirmed` and reads the formal order ledger count instead of recreating draft ledger rows.
- Provider scope submission now writes `guolairen_a2a_provider_scope_submission`, declares execution boundaries, tool permissions, and evidence checklist, then advances the formal order into execution-ready status.
- Delivery acceptance now first renders a buyer decision gate. Opening `acceptance?order=...` only reviews delivery evidence; `decision=accept` releases escrow, while `decision=rework` writes a rework snapshot and keeps ledger rows frozen.
- Settlement branch handling now first renders a buyer refund/dispute application form. Opening the route previews case evidence and ledger impact only; submitting the form writes `guolairen_a2a_settlement_case`, locks refund/dispute evidence snapshots, updates escrow ledger rows into refund-ready or dispute-hold states, and preserves terminal case state when earlier routes are reopened.
- Agent reputation recalculation now writes `guolairen_a2a_agent_reputation`, derives tier/score/trust/risk signals from trade orders, accepted delivery evidence, refund cases, and dispute cases, and exposes a reputation evidence page.
- Role action policy now separates buyer, provider, platform risk, and arbitrator operations through `a2a/frontend/role-console`, stores the active A2A role in the frontend session, and blocks forbidden actions with HTTP 403.
- Trade actor assignments now persist formal order ownership for buyer, Agent provider, platform risk, and arbitrator roles in `guolairen_a2a_trade_actor_assignment`, then expose binding status in the role console.
- Trade actor assignments can now be bound from the role console to prototype account, Agent-operator, or backend ACL-group subjects, preserving the original order ownership reference separately from the future auth subject.
- Arbitration ruling now writes `guolairen_a2a_arbitration_ruling`, generates `guolairen_a2a_wallet_instruction` dry-run adapter instructions, and updates escrow ledger rows without moving real funds.
- Wallet adapter execution monitoring now writes dry-run execution state, idempotency keys, simulated external references, failure reasons, retry counts, and reconciliation timestamps onto wallet instructions.
- Browser-checked responsive slice with no horizontal overflow at the current desktop viewport.

## Current Route

- `a2a/frontend`
- `a2a/frontend/purchase?sku={sku_code}`
- `a2a/frontend/quote?quote={quote_code}`
- `a2a/frontend/confirm?draft={draft_public_id}`
- `a2a/frontend/provider-scope?order={order_public_id}`
- `a2a/frontend/acceptance?order={order_public_id}[&decision={accept|rework}]`
- `a2a/frontend/settlement-case?case={refund|dispute}&order={order_public_id}[&submit=1]`
- `a2a/frontend/reputation?agent={provider_key}`
- `a2a/frontend/role-console?switch_role=1&role={buyer|provider|platform|arbitrator}&order={order_public_id}`
- `a2a/frontend/role-console?order={order_public_id}[&action={action_code}]`
- `a2a/frontend/arbitration-ruling?order={order_public_id}&ruling={full_release|partial_release|refund|rework}`
- `a2a/frontend/wallet-monitor?order={order_public_id}&mode={inspect|dry_run_execute|simulate_failure|retry_failed}`

The route is generated through the normal Weline module scanner after `etc/env.php` registers `router => a2a`. If a route-only rebuild falls back to `guolairen_a2a/frontend`, run module setup before route refresh so `app/etc/modules.php` receives the module env router value.

## Deliberate Limits

- No real payment, wallet write, or settlement integration yet.
- Database schema now covers capability SKU, buyer request, Agent quote, order draft, formal trade order, and escrow ledger persistence.
- Purchase intent, quote selection, and escrow confirmation are idempotent for deterministic public IDs; they are still prototype checkout records, not a real payment integration.
- No Agent execution sandbox integration yet.
- No browser-side API requests yet.
- The provider execution queue, scope submission, delivery evidence, buyer acceptance, accepted-release ledger state, refund/dispute application gate, refund review case, dispute arbitration case, Agent reputation snapshot, and role action policy are now persisted or route-enforced prototype workflows.
- Role action policy blocks forbidden requested actions with HTTP 403 and no longer trusts a plain `role` URL parameter. It now has formal order actor assignments and a prototype binding action, but sensitive write routes still need direct account-bound enforcement.
- Real wallet payout/refund adapter execution is still not integrated; wallet execution monitoring is still dry-run but now exposes execution state, idempotency, failure, retry, and reconciliation boundaries.

## Next Product Increment

1. Replace prototype role binding subjects with authenticated account, organization, and backend permission-group references, then enforce them at all sensitive write routes.
2. Propagate final reputation score/tier into marketplace SKU sorting and exposure weights.
3. Replace prototype actor binding with real Weline identity/ACL resolution and attach non-demo delivery evidence files/API results to buyer refund/dispute applications.
4. Attach the dry-run wallet adapter contract to a real wallet/payment provider only after idempotency, retry, reconciliation, and audit evidence are accepted.

## 2026-06-06 15:03 Provider Scope Slice

- New model: `GuoLaiRen\A2A\Model\ProviderScopeSubmission` -> `guolairen_a2a_provider_scope_submission`.
- New service: `ProviderScopeSubmissionService`, idempotent by formal order public ID.
- New route: `a2a/frontend/provider-scope?order={order_public_id}`.
- Confirmed orders now expose a Provider scope CTA. Reopening a scope-submitted order preserves `scope_submitted` instead of regressing to a pending provider queue state.
- Product rule: a provider cannot move into execution-ready state until execution scope, tool permissions, evidence checklist, and risk gate are visible and persisted.

## 2026-06-06 15:33 Delivery Acceptance Slice

- New model: `GuoLaiRen\A2A\Model\DeliveryAcceptance` -> `guolairen_a2a_delivery_acceptance`.
- New service: `DeliveryAcceptanceService`, idempotent by formal order public ID.
- New route: `a2a/frontend/acceptance?order={order_public_id}`.
- Provider scope page now links into delivery evidence and buyer acceptance.
- Accepted orders preserve final settlement status when confirmation or provider-scope pages are reopened.
- Product rule: acceptance is based on evidence package, buyer checklist, and settlement branch, not a pure UI "done" label.

## 2026-06-06 16:03 Settlement Case Slice

- New model: `GuoLaiRen\A2A\Model\SettlementCase` -> `guolairen_a2a_settlement_case`.
- New service: `SettlementCaseService`, idempotent by `(order_public_id, case_type)`.
- New route: `a2a/frontend/settlement-case?case={refund|dispute}&order={order_public_id}`.
- Acceptance page now links refund review and dispute arbitration into state-changing settlement branch pages.
- Refund branch moves ledger rows into buyer refund-ready, platform fee refund-ready, and Agent payout hold states.
- Dispute branch freezes buyer funds, platform fee, and Agent payout while preserving an arbitration evidence package and arbitration flow.
- Product rule: a settlement branch is an evidence record plus ledger state transition; the latest opened branch controls current order/ledger status while older branch records remain audit trail.

## 2026-06-06 16:33 Agent Reputation Slice

- New model: `GuoLaiRen\A2A\Model\AgentReputation` -> `guolairen_a2a_agent_reputation`.
- New service: `AgentReputationService`, recalculating score and tier from formal orders, acceptance snapshots, refund cases, and dispute cases.
- New route: `a2a/frontend/reputation?agent={provider_key}`.
- Market SKU cards and featured Agent cards now link to the reputation recalculation page.
- DataClean Pro Agent currently recalculates to `银级观察` with score `74.0` because the available evidence includes accepted delivery, refund review, and dispute arbitration on the same small sample.
- Product rule: marketplace trust badges are only initial claims. Order outcomes, refund evidence, and dispute records can downgrade or restore Agent reputation.

## 2026-06-06 17:03 Role Action Policy Slice

- New service: `RoleActionPolicyService`, deriving buyer/provider/platform/arbitrator allowed and blocked actions from order status, acceptance evidence, settlement cases, and escrow ledger state.
- New route: `a2a/frontend/role-console?role={buyer|provider|platform|arbitrator}&order={order_public_id}[&action={action_code}]`.
- Main workspace role cards now open the role console for the current demo order.
- Buyer direct fund release is blocked with HTTP 403; arbitrator evidence review is allowed only when a dispute case exists.
- Product rule: role permission is not just copy on a dashboard. Every sensitive transaction action must have a visible policy reason and a matching route-level blocked/allowed response.

## 2026-06-06 19:03 Session-Owned Role Boundary Slice

- New service: `RoleSessionService`, storing the active A2A actor role in the frontend session under a module-owned key.
- Role tabs now use `switch_role=1` to explicitly save buyer, Agent, platform risk, or arbitrator into the current browser session.
- Action verification links no longer send `role`. They inspect the order and action against the saved session role.
- If a normal URL includes a mismatched or unsupported `role` parameter, the role console displays a notice and keeps executing with the session role.
- Product rule: UUMit-style capability SKUs and trust tiers can drive discovery, but sensitive A2A transaction actions must be authorized by a held actor context, not a shareable URL parameter. This is still a prototype session boundary and must become account-bound ACL before production.

## 2026-06-06 19:33 Order Actor Assignment Slice

- New model: `GuoLaiRen\A2A\Model\TradeActorAssignment` -> `guolairen_a2a_trade_actor_assignment`.
- New service: `TradeActorAssignmentService`, idempotently syncing buyer, Agent provider, platform risk, and arbitrator assignments from the formal trade order.
- Role console now displays order-level actor ownership, binding status, verification source, and ownership scope beside the session role policy.
- Current demo order now yields four actor assignments:
  - buyer from the order buyer reference
  - Agent provider from the order provider key
  - platform risk from the platform risk group placeholder
  - arbitrator from the A2A arbitration panel placeholder
- Product rule: session role switching is only a temporary actor context. Every sensitive A2A action must eventually match both a formal order assignment and an authenticated account or permission group before production.

## 2026-06-06 20:03 Prototype Actor Binding Slice

- Extended `TradeActorAssignment` with bound subject fields:
  - `bound_subject_type`
  - `bound_subject_reference`
  - `bound_subject_display`
  - `bound_at`
- Extended `TradeActorAssignmentService` with `bindCurrentActor()`.
- Role console now exposes `bind_actor=1` for the current saved session role.
- The current role can be bound to a prototype subject:
  - buyer -> customer account
  - Agent -> Agent operator account
  - platform risk -> backend ACL group
  - arbitrator -> backend ACL group
- Binding preserves the original order-derived actor reference, so provider key, buyer reference, platform group, and arbitration panel evidence remain auditable.
- Product rule: this is a product-facing bridge toward real ACL. It demonstrates the binding contract and data shape, but production still requires real Weline account/organization/backend permission resolution and route-level enforcement.

## 2026-06-06 20:33 Actor Guard Enforcement Slice

- New exception: `GuoLaiRen\A2A\Exception\TradeActorAuthorizationException`.
- New service: `TradeActorAuthorizationGuardService`.
- Sensitive write controllers now call the guard before mutating state:
  - `ProviderScope` requires bound `provider`
  - `Acceptance` requires bound `buyer`
  - `SettlementCase` requires bound `buyer`, with dispute/freeze also allowing bound `platform`
  - `ArbitrationRuling` requires bound `arbitrator`
  - `WalletMonitor` maps modes to platform/arbitrator authority
- Guard contract:
  - use saved session actor from `RoleSessionService`
  - ignore ordinary URL `role` parameters
  - inspect the formal order actor assignment
  - require `account_bound` plus a bound subject reference
  - return HTTP 403 denial through the controller when the check fails
- Product rule: marketplace/SKU trust is only the pre-purchase layer. Product-grade A2A must also prove post-purchase authority at every sensitive order mutation.
- Remaining product gap: prototype bound subjects must be replaced with real Weline identity and ACL records, and provider delivery submission must be split from buyer acceptance/release.

## 2026-06-06 17:33 Arbitration Ruling And Wallet Boundary Slice

- New model: `GuoLaiRen\A2A\Model\ArbitrationRuling` -> `guolairen_a2a_arbitration_ruling`.
- New model: `GuoLaiRen\A2A\Model\WalletInstruction` -> `guolairen_a2a_wallet_instruction`.
- New service: `ArbitrationRulingService`, issuing final dispute rulings and generating wallet adapter dry-run instructions.
- New route: `a2a/frontend/arbitration-ruling?order={order_public_id}&ruling={full_release|partial_release|refund|rework}`.
- Settlement case and role console pages now link into arbitration ruling.
- Product rule: final arbitration does not directly move money. It creates an auditable ruling and wallet-instruction boundary so real payout/refund adapters can be attached later without rewriting transaction logic.

## 2026-06-06 18:03 Wallet Adapter Monitor Slice

- Extended `GuoLaiRen\A2A\Model\WalletInstruction` with idempotency key, external reference, failure reason, retry count, executed timestamp, and reconciled timestamp.
- Added `WalletInstructionAdapterService` to inspect, dry-run execute, simulate failure, and retry failed wallet instructions without touching real funds.
- Added route `a2a/frontend/wallet-monitor?order={order_public_id}&mode={inspect|dry_run_execute|simulate_failure|retry_failed}`.
- Arbitration ruling pages now link into wallet dry-run execution and preserve confirmed or failed adapter status when reopened.
- Platform risk and arbitrator role policies now expose wallet-monitoring actions.
- Product rule: the wallet adapter boundary is not just a generated instruction list. Every instruction must carry execution status, idempotency, retry, failure, and reconciliation evidence before a real payout/refund provider can be connected.

## 2026-06-06 18:33 Reputation Outcome Slice

- Extended `AgentReputationService` to include `guolairen_a2a_arbitration_ruling` and `guolairen_a2a_wallet_instruction` rows in score, tier, trust signals, risk signals, source snapshot, and evidence rows.
- Final arbitration ruling outcomes now have differentiated scoring impact:
  - full release restores trust
  - partial release creates a medium deduction
  - full refund creates a severe deduction
  - rework creates a rework-observation deduction
- Wallet execution now affects trust only through dry-run reconciliation:
  - confirmed instructions add a small capped trust signal
  - failed instructions create a material risk signal
- The reputation page now exposes final ruling count, wallet confirmation rate, ruling evidence, wallet execution evidence, and a `查看钱包执行` CTA into wallet-monitor inspect mode.
- DataClean Pro Agent currently recalculates to `金级观察` with score `83.0` after one partial-release ruling and three confirmed wallet dry-run instructions.
- Product rule: marketplace badges and UUMit-style trust labels are entry claims. Product-grade A2A trust must be backed by traceable transaction outcomes: order evidence, buyer acceptance, settlement branch, final ruling, and wallet reconciliation.

## 2026-06-06 21:33 Buyer Acceptance Decision Gate Slice

- `Acceptance` now reads an explicit `decision` parameter and uses the same buyer actor guard for review, accept, and rework actions.
- `DeliveryAcceptanceService::accept()` now supports:
  - review mode: no state mutation and no escrow release
  - accept mode: writes accepted delivery evidence and releases ledger rows
  - rework mode: writes `request_rework`, moves the order to `rework_required`, and marks all escrow ledger rows as `rework_hold`
- Acceptance page now shows delivery evidence, acceptance checklist, ledger status, and decision cards before any release action.
- Product rule: buyer acceptance is no longer a passive page load. Any money-moving state transition must come from an explicit buyer decision and leave a matching snapshot.

## 2026-06-06 22:03 Settlement Application Gate Slice

- `SettlementCase` now separates preview from submission:
  - `settlement-case?case={refund|dispute}&order={order_public_id}` renders a structured buyer application and ledger-impact preview without mutating data.
  - `submit=1` is required before a refund review or dispute arbitration case is created and the escrow ledger is updated.
- The buyer application captures:
  - refund/dispute reason
  - expected outcome
  - evidence note
- Submitted application details are stored in `SettlementCase.metadata_json` under `buyer_application` and are also included in the case evidence package.
- Existing settlement cases reopen in submitted mode and do not re-display a misleading application form.
- Product rule: exception branches are money-moving or money-freezing paths. They must be explicit buyer submissions, not side effects of opening a link from the acceptance page.

## 2026-06-06 22:33 Actor Identity Readiness Slice

- New service: `TradeActorIdentityResolutionService`.
- Actor binding now records an explicit identity source and readiness state in `TradeActorAssignment.metadata_json.binding_event`:
  - `real_account` for resolved frontend customer sessions
  - `contract_ready` for Agent operator and backend ACL contracts that still need runtime proof
  - `prototype_only` for demo/session claims that are not production authority
  - `unbound` for missing bound subjects
- `TradeActorAssignmentService` now exposes identity readiness, source, evidence, risk label, and production-ready flags in role-console data.
- Bound-role assignment sync preserves the identity `verification_level` instead of overwriting contracts back to order snapshots.
- `TradeActorAuthorizationGuardService` now returns identity readiness evidence in `authorization_guard` while keeping the current prototype demo flow unblocked.
- Role console now shows:
  - production-usable identity count
  - permission-contract pending-proof count
  - prototype-binding count
  - per-role identity source, evidence, and risk notice
- Product rule: a role being `account_bound` is no longer enough to claim product-grade authority. Production eligibility requires real identity/ACL proof, while prototype or contract-only bindings remain visible gaps.

## 2026-06-06 23:03 Runtime Proof Gate Slice

- New service: `TradeActorRuntimeProofService`.
- Role-console data now separates identity readiness from runtime proof:
  - `runtime_proof_passed` for a current request that proves the bound role subject
  - `runtime_proof_status`, label, evidence, and gap for visible audit reasons
  - `runtime_acl_source` for backend/Agent operator roles that still need Weline ACL registration
- Production-ready actor metrics now use runtime proof count, not static `contract_ready` rows.
- Current frontend role console explicitly marks platform risk and arbitrator bindings as backend ACL pending because this route cannot prove backend session ACL from a frontend request.
- Product rule: UUMit-style marketplace trust labels and role contracts can advertise capability supply, but A2A production authorization must be proved at transaction runtime before payout, refund, or arbitration can be claimed.

## 2026-06-06 23:33 Backend ACL Source Registration Slice

- Added backend ACL proof controller `GuoLaiRen\A2A\Controller\Backend\AclProof` with A2A source IDs for Agent operator, platform risk, and arbitration panel proof.
- Added `etc/backend/menu.xml` entry for `GuoLaiRen_A2A::acl_proof` under the backend order group.
- Updated module dependencies so `GuoLaiRen_A2A` depends on `Weline_Backend` and `Weline_Acl`.
- `TradeActorRuntimeProofService` now checks whether planned A2A ACL source IDs exist in Weline ACL data and returns registration label/evidence to the role console.
- Role console now shows `ACL Source 已注册` evidence for:
  - `GuoLaiRen_A2A::agent_operator`
  - `GuoLaiRen_A2A::platform_risk`
  - `GuoLaiRen_A2A::arbitration_panel`
- Product rule: an ACL source being registered is a necessary backend authority contract, not sufficient production authorization. Runtime proof still requires a real backend/admin session or operator account before payout, refund, or arbitration actions can be declared product-ready.

## 2026-06-07 00:03 Backend Proof Entry URL Slice

- `TradeActorRuntimeProofService` now maps each A2A ACL source contract to its backend proof route and generates a Weline backend URL through `Weline\Framework\Http\Url::getBackendUrl()`.
- Role console now shows `后台实证入口` links for Agent operator, platform risk, and arbitration panel ACL proof instead of leaving users with a bare public route.
- Generated proof links use the backend key prefix and enter the backend session/login chain with `return_url` back to the intended ACL proof controller.
- Direct public backend proof routes remain non-production verification paths; the generated backend URL is the only product-facing authority proof entry.
- Product rule: UUMit-style marketplace trust labels such as verified practice, expert review, and scarce authority cannot point to opaque or 404-prone routes. In A2A they must resolve to a framework-generated, auditable backend proof entry plus runtime account/session evidence before being counted as product-grade authorization.

## 2026-06-07 00:33 Authenticated ACL Proof Payload Slice

- Added `BackendAclProofPayloadService` to build source-specific backend ACL proof payloads for Agent operator, platform risk, and arbitration panel roles.
- `AclProof` now returns:
  - `proof.status = backend_acl_route_verified`
  - `proof.passed = true` when a logged-in backend session reaches the source-specific ACL route
  - `backend_actor` with authenticated backend user/role context
  - `capability_scope` for the role's A2A actions
  - `marketplace_trust_mapping` that converts UUMit-style trust labels into proof-backed booleans
  - `transaction_authorization.passed = false` until a concrete order role, action guard, and escrow/case state are bound
- Browser and HTTP validation confirmed all three ACL proof routes return authenticated JSON payloads after backend login:
  - `GuoLaiRen_A2A::agent_operator`
  - `GuoLaiRen_A2A::platform_risk`
  - `GuoLaiRen_A2A::arbitration_panel`
- Product rule: backend ACL route proof is now a real, authenticated authority signal, but it is still not a money-moving authorization. Payout, refund, delivery, or arbitration actions require order-scoped actor binding and state guards in addition to this proof.

## 2026-06-07 01:03 Provider Operator Account Binding Slice

- Added `AgentOperatorAccountBindingService` to resolve an enabled Weline backend user as the Agent provider's operator-account binding candidate.
- Provider role binding now prefers `agent_operator_backend_user` with:
  - `identity_source = agent_operator_backend_user`
  - `verification_level = provider_operator_backend_user`
  - `identity_readiness = real_account`
  - binding metadata for `operator_provider_key` and `operator_backend_user_id`
- Role console now shows the Provider as a production-usable identity when the backend operator user exists, while `runtime_proof_passed` remains false from the frontend request.
- Product rule: this converts the UUMit-style seller/author trust signal into a real A2A operator-account identity signal. It still does not authorize delivery submission, payout, refund, or arbitration by itself; those actions still require order-scoped runtime guards and backend session/ACL proof.

## 2026-06-07 01:34 Strict Runtime Guard Slice

- `TradeActorAuthorizationGuardService::assertBoundActor()` now accepts runtime-proof options for high-risk actions.
- Arbitration ruling and wallet monitor controllers require order-scoped runtime proof before generating ruling, payout, refund, dry-run wallet, or wallet inspection results.
- Settlement-case handling now requires runtime proof when the acting role is platform risk, while preserving buyer exception flows for refund/dispute/rework applications.
- Role action policy now exposes the hard rule that platform risk, arbitration, and wallet actions must pass order-level runtime proof, not just static role labels or ACL source registration.
- Product rule: UUMit-style verified/expert/continuous-update trust labels can help sell an Agent capability SKU, but A2A money-moving or ruling actions require a stricter transaction guard. Static marketplace trust, registered ACL source, and real operator identity are necessary signals; none of them alone authorizes escrow release, refund, or arbitration.
