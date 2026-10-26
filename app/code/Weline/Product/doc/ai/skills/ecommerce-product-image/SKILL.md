---
name: ecommerce-product-image
description: >-
  BRANCH ① main/gallery/variant. SKIP MCP. Authority: weline-image-pipeline
  §0-B shortest path + §1-M clothing_lock (ref orig, new person/bg, ai-lock
  object_key). Standalone: 主图优化/规格图优化. FORBID schema dig / same-path
  deliver / skip variant.
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

1. Read 图管线 **§0‑B（最短路径）+ §0‑A + §1‑M**——**先按 §0‑B 跑，禁止表结构考古**。  
2. **必须**覆盖 **main + gallery + variant**（方卡同一 `target_ar`，默认 1:1）。  
3. **分流**：  
   - **含人** → §1‑M：衣锁死 + 只换人景 + **M6 新 object_key（ai-lock-…）**。  
   - **无人** → 剥框 + 真 outpaint；同路径仍 HIT 则同样改 key。  
4. 备份 → 生图 → 新 key → pierce 页 HTML 含 `ai-lock-` → `clothing_lock=pass`。  
5. **交付格式**：终态 **WebP**，按展示尺寸压缩（1:1 主图长边 ≤1600px、单图 ≤250KB；不放大）；原图只作源，禁止直上页面。  
6. 汇报：`advisor` / `clothing_lock=pass` / `url_bust=…` / `含人 N` / `variant=已覆盖` / `format=webp`。

**禁止**：改衣；漏 variant；同路径假交付；`SHOW TABLES`/扫 EAV；假称真人实拍；交大 PNG/JPG 或原图。
