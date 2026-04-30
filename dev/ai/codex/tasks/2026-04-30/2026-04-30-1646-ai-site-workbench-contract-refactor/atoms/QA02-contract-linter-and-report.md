# QA02 Contract Linter and Report

## Background Snapshot

Contract consistency is separate from design/copy quality. It validates source links, frozen fields, and permissions.

## Goal

Add contract linter and aggregate QA Report contract.

## Non-goals

Do not implement Repair Patch application.

## Touch Points

- Contract validators
- QA service namespace
- Build output persistence

## Implementation Steps

1. Run source contract validation.
2. Run frozen field validation where previous/next data exists.
3. Aggregate all QA findings into a QA Report contract.
4. Mark QA gates pass/fail/warn based on findings.
5. Add tests.

## Acceptance

- QA Report identifies contract violations separately from content quality issues.

## Rollback

Remove report aggregation and keep individual linter output.
