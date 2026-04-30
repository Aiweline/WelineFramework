# P01 Stage1 Contract Context Input

## Background Snapshot

Stage1 currently generates an execution blueprint draft. It must start receiving explicit contract context and skill snapshots before it can emit structured contracts.

## Goal

Pass contract context and selected skill snapshots into Stage1 service input.

## Non-goals

Do not fully rewrite Stage1 prompt output in this atom.

## Touch Points

- `AiSiteExecutionBlueprintService`
- Plan queue params
- Skill snapshot builder

## Implementation Steps

1. Add an optional `contract_context` input param to Stage1.
2. Include selected skill snapshots in that context.
3. Preserve existing params for prompt mode, instruction, scope, locale, and round.
4. Make missing context fall back to default skill behavior.
5. Add tests or service-level fixture validation.

## Acceptance

- Stage1 can inspect selected skill snapshots without breaking old callers.

## Rollback

Remove the optional context param and continue using existing prompt guide injection.
