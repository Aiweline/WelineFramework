# Research: Decisions and Rationale for Weline_Ai

## Purpose
Capture decisions required to resolve NEEDS_CLARIFICATION items in `spec.md` prior to design and implementation.

## Topics

### 1) SecretStore (KMS vs Local)
- **Decision (proposed)**: Use local encrypted store with optional KMS integration later.
- **Rationale**: Lower initial operational complexity; architecture provides abstraction to swap to KMS. Mark as TODO to evaluate KMS providers in Phase 0 (security owner).

### 2) Data retention / audit logs
- **Decision (proposed)**: Audit logs retention 90 days by default; archival policy to long-term storage for compliance set TBD.
- **Rationale**: Balance storage cost and operational needs. Confirm with product/compliance.

### 3) Performance SLO
- **Decision (proposed)**: Set P95 <= 3s for typical text generation API (non-streaming). Streaming endpoints measured differently. Confirm with SRE.

### 4) Default model priority & cost control
- **Decision (proposed)**: Default model per service type stored in `ai_default_model` with `priority` numeric field. Cost control via `ai_api_key` quota and per-model cost metadata.

## Next actions
1. Confirm KMS decision with Security team (TODO: owner).
2. Confirm retention and SLO with Product/SRE.
3. Convert these decisions into `data-model.md` and migration plans.


