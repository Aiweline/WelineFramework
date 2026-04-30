# F01 Needs Form State Module

## Background Snapshot

Requirement fields and page selections are currently spread through large frontend script logic. Skill selection needs a clean state home.

## Goal

Extract a single needs form state module inside the existing template/script structure.

## Non-goals

Do not rewrite the frontend framework. Do not implement skill manager drawer.

## Touch Points

- Workbench `script-main.phtml`
- Plan card template
- Existing autosave hooks

## Implementation Steps

1. Identify all requirement fields and page type controls.
2. Create a state object for title, tagline, domain, locale, requirement text, page types, and selected skills.
3. Provide read/update/serialize helpers.
4. Keep existing DOM ids working.
5. Add small browser/e2e smoke coverage if available.

## Acceptance

- Generation payload can read all requirement data from one state helper.

## Rollback

Inline the helper reads back into existing script code.
