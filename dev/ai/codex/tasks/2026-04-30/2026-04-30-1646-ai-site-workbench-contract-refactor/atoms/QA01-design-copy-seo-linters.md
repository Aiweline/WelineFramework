# QA01 Design Copy SEO Linters

## Background Snapshot

QA should identify design, copy, and SEO issues as structured findings before repair. First version can be rules based.

## Goal

Add v1 rules-engine linters for design, copy, and SEO.

## Non-goals

Do not call AI for QA in this atom. Do not apply repairs.

## Touch Points

- New QA service namespace
- Build Render Data contract
- Unit tests

## Implementation Steps

1. Add design checks for missing tokens or inconsistent section style.
2. Add copy checks for empty, generic, or childish copy.
3. Add SEO checks for title, description, H1, and keyword basics.
4. Return structured findings with severity and target path.
5. Add tests with small fixtures.

## Acceptance

- Linter output can be rendered as structured QA findings.

## Rollback

Remove QA service and callers will skip v1 QA.
