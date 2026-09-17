---
name: ecommerce-product-image
description: >-
  BRANCH under parent 产品优化: main/gallery/variant only. SKIP MCP
  (content_ops_skills_skip_mcp). Authority: companions/weline-image-pipeline.md.
  Standalone: 主图优化/规格图优化/修主图. Lock target_ar; TRUE AI outpaint;
  FORBID cover-crop/rembg/solid pads/empty upscale.
---

# 主图/规格图优化（子技能分支 · ecommerce-product-image）

**跳过 MCP。** 仓内权威像素 SOP：  
`app/code/Weline/Product/doc/ai/skills/ecommerce-detail-suite/companions/weline-image-pipeline.md`  
本文件 = 父「产品优化」第 ① 子智能体入口；**不要**在此复制拓图全文。

```
产品优化（父 · 必须开 3 子智能体）
├── ① 主图/画廊/规格（本技能）← 你在这里
├── ② 详情优化 → ecommerce-detail-suite
└── ③ 翻译优化 → ecommerce-product-i18n
```

## 命中

| 触发 | 行为 |
|------|------|
| 父 `产品优化` / `商品优化` | 父**必须**派本分支给第 ① 子智能体 |
| `主图优化` / `规格图优化` / `修主图` / `优化主图`（无「整品/产品优化」） | **仅本分支**（不宣称详情/翻译已做） |

## Agent 必做

1. Read `weline-image-pipeline.md` 全文并执行。  
2. **必须**覆盖 **main + gallery + variant**（规格与主图同一 `target_ar`）。  
3. 锁 `target_ar`（方卡/方 canvas → **1.0**）；剥框 → 真·生图 AI outpaint；禁 cover 裁窄/色垫/rembg/空放大。  
4. `replaceContent`；备份；类审 0 BAD。  
5. 汇报：`target_ar` / outpaint=是 / 空放大=否 / 处理张数。

**禁止**：只做详情却声称产品优化完成；跳过规格图；父未开本智能体却标父任务完成。
