---
name: pagebuilder-ai-site-plan-json
description: PageBuilder AI site generation data schema guardrail. Use when modifying PageBuilder AI site planning, prompts, generated plan_json, build queues, SSE/workspace state, frontend status display, docs, or tests so all page and block generation data stays in plan_json.pages.{page_type}.{block_key} instead of new truth sources like build_plan_v2, page_plans, plan_workbench, stage1_contract, execution_blueprint, or build_tasks.
---

# PageBuilder AI Site Plan JSON

## Core Rule

Keep PageBuilder AI site generation state in one persisted tree:

```js
plan_json.pages.{page_type}.{block_key}
```

Do not create a new truth source for new data. Add a key directly to the nearest owner node.

## Where New Fields Go

Use these locations:

```js
// Site-wide context
plan_json.site_name
plan_json.theme
plan_json.content_locale

// Page-wide data
plan_json.pages.home_page.name
plan_json.pages.home_page.status
plan_json.pages.home_page.seo

// Block-wide data
plan_json.pages.home_page.hero.status
plan_json.pages.home_page.hero.html
plan_json.pages.home_page.hero.fields
plan_json.pages.home_page.hero.error
plan_json.pages.home_page.hero.demo
```

If a new concept belongs to a page, put it directly under `plan_json.pages.{page_type}`. If it belongs to a block, put it directly under `plan_json.pages.{page_type}.{block_key}`.

## Status Contract

Block status is numeric:

| Value | Meaning |
| --- | --- |
| `0` | not generated |
| `2` | generating |
| `1` | success |
| `-1` | failed |

`page.status` is a rollup from the page's block statuses. The block remains the smallest execution unit.

## Forbidden Patterns

Do not introduce or depend on these as truth sources:

```js
build_plan_v2
plan_workbench
stage1_contract
page_plans
execution_blueprint
build_tasks
pages.home_page.blocks[]
block_state_map
page_state_map
generated_blocks
```

Compatibility shells may remain only as deprecated view/debug artifacts. They must not decide whether a page exists, a block should run, a block succeeded, a block failed, or a publish gate passes.

## Implementation Checklist

When changing PageBuilder AI generation data:

1. Pick the nearest owner: root `plan_json`, page node, or block node.
2. Add the new field there directly.
3. Update prompts to ask AI to output that exact path.
4. Update queue/build/SSE/frontend readers to read that exact path.
5. Update writers to write back to the same node.
6. Add tests that prove old sources do not unlock gates or drive generation.
7. Update docs that still describe a side table or derived plan as truth.

## Prompt Guidance

Tell AI generators to output dynamic page and block keys directly:

```js
{
  "plan_json": {
    "pages": {
      "home_page": {
        "name": "Home",
        "status": 0,
        "hero": {
          "status": 0,
          "title": "Hero",
          "demo": "any new block field goes here"
        }
      }
    }
  }
}
```

Never ask the AI to output a separate page table, build plan, workbench, execution blueprint, or task list for generated state.

## No Migration Rule

Do not migrate old data. If old scopes only contain `pages.home_page.blocks[]`, `page_plans`, `plan_workbench`, `stage1_contract`, or `build_plan_v2`, treat them as invalid for the new build path and regenerate the plan.

