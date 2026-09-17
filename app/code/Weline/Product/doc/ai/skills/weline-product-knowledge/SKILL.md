---
name: weline-product-knowledge
description: >-
  Deterministic documentation and source locator for Weline_Product.
  Use for Product module ownership, shard/identity/provider/copy docs.
  Do NOT put 详情优化 / 主图拓图 SOP here — parent ecommerce-product-optimize
  + child ecommerce-detail-suite.
---

# Weline_Product knowledge locator

## Role

Route work to the exact Product module documentation and indexed source facts.
This skill is a **locator**, not the product-detail-optimize policy source.

## When To Use

Use for tasks whose owning path or symbol is inside `app/code/Weline/Product`
**except** 产品优化 / 商品优化 / 详情优化 / 商详优化 / 主图拓图 / PDP 图处理——那些走
父 `ecommerce-product-optimize`（含图管线 + 子详情）或子 `ecommerce-detail-suite`。

## Load First

- `app/code/Weline/Product/doc/AI-INDEX.md`
- `app/code/Weline/Product/doc/README.md`
- `app/code/Weline/Product/doc/万能产品完善计划.md`（若触及 V2/分片/身份）
- `app/code/Weline/Product/doc/provider-guide.md` / `copy-guide.md`（按任务）

## 产品/详情优化分流（严重）

| 意图 | 去哪 |
|------|------|
| **产品优化 / 商品优化**（全套） | **父** `ecommerce-product-optimize` + `产品优化.md` → 图管线后再调子 |
| **详情优化 / 商详优化**（仅详情） | **子** `ecommerce-detail-suite` + `详情优化.md` |
| 主图·规格 AI 拓图 | 父或 `weline-image-pipeline.md` |
| 规格修复（缺属性回填） | 指令/技能「规格修复」 |
| 分片 / SKU / Provider / Store Copy | 本定位器 + AI-INDEX |

禁止把主图 outpaint / 禁裁窄 / 禁色垫等 SOP 写进本知识技能正文。

## Workflow

1. Confirm owning module is `Weline_Product`.
2. If task is 产品/详情优化意图 → stop using this locator; Read 父或子技能。
3. Otherwise read exact doc paths from AI-INDEX; do not invent Product SOP here.

## Guardrails

- Never treat vector similarity alone as proof that documentation is stale.
- Never embed ecommerce image-pipeline SOP in this knowledge skill.
- Draft/stale skills are not actionable guidance.
