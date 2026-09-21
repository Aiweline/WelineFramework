---
name: ecommerce-product-image-optimize
description: >-
  产品图优化：主图/画廊/规格 + 详情实拍图质量。SKIP MCP
  (content_ops_skills_skip_mcp). Queue by product_id DESC; persist ONLY
  last_product_id. MUST launch TWO parallel subagents (① main pipeline,
  ② detail photos). Triggers: 产品图优化/修产品图/低质量主图详情图/
  双层背景接缝/框中框拓边.
---

# 产品图优化（图表面专用 · 非整品翻译）

**跳过 MCP。** 只做**像素**：主图/画廊/规格 + 详情正文实拍图。  
不做字段翻译（那是 `产品优化` 槽 ③）。若用户要整品含翻译 → 改走父 `ecommerce-product-optimize`。

```
产品图优化（本技能 / 指令 产品图优化.md）
├── ① 主图 + 画廊 + 规格  → ecommerce-product-image + weline-image-pipeline.md
└── ② 详情·实拍图（含双层背景/接缝）→ pipeline §7 + ecommerce-detail-suite（图相关闸）
流程：倒序取品 → 并行②子 → 逐品目检 → 只写 last_product_id → 下一品
```

## 命中

| 触发 | 行为 |
|------|------|
| `产品图优化` / `修产品图` / `低质量主图` / `详情图优化`（图） | **本技能** |
| 点名「双层背景 / 框中框 / 左右竖缝 / 假拓边」 | **本技能**强制类审 + 真 outpaint |
| `产品优化` / 含翻译 | **不要**用本技能冒充；走父全套三子 |

## 进度台账（严重 · 只记最后一次 id）

权威文件：`app/code/Weline/Product/doc/evidence/product-image-optimize-progress.v1.md`

| 规则 | 要求 |
|------|------|
| **排序** | 待办队列按 **`product_id` 倒序**（大→小） |
| **持久化** | 台账里**只保留** `last_product_id=` 一个整数；禁止堆长完成表当唯一真相 |
| **续跑** | 读 `last_product_id`，下一波 = 积压队列中 **严格小于** 该 id 的下一批（仍倒序） |
| **本回合进度** | 可在回复里列本波 IDs；落盘仍只更新 `last_product_id` 为本波最小已完成 id（或本波最后一个收口 id） |

可选旁注（可丢）：`/tmp/p-img-opt-remain-*.tsv` 全量积压；**不得**代替台账的 `last_product_id`。

## 低质量类审（本技能加严）

在 `weline-image-pipeline` 类审之外，**必须**扫：

| 负面 | 怎么认 | 怎么修 |
|------|--------|--------|
| **双层背景未拓开 / 框中框** | 左右竖缝；中区纹理细、两侧糊或色差；后景人影/雾在中框截断 | 剥中区实拍 → **真·GenerateImage outpaint** 让雾气/后景层连续铺满画幅；禁反射糊边凑宽 |
| **假拓边** | 匀色柱、拖影、拼贴接缝 | 回退 → 真 outpaint |
| **软糊 / 低码率** | mid-lap / bpp 闸 | 按管线；禁空放大 |
| 详情信息烤图 | 尺码表/参数 JPG | **textify**，不走 outpaint |

## Agent 必做

1. Read 本文件 + `dev/ai-command/product/产品图优化.md` + 图管线全文。  
2. 读台账 `last_product_id`；从积压按 **DESC** 取本波（**每波 50 个商品**；用户另点名时以点名为准）。  
3. **必须并行**启动恰好 **2** 个子智能体，prompt 粘贴技能路径：  
   - ① `ecommerce-product-image/SKILL.md` + `weline-image-pipeline.md`  
   - ② 详情实拍：同管线 §7；版式若坏再开 `ecommerce-detail-suite` + `详情优化.md`  
4. 硬闸：`target_ar`（方卡→1.0）；真 outpaint；禁 cover/色垫/rembg/空放大；替换前备份。  
5. 逐品目检接缝=0 后：更新台账 **仅** `last_product_id=<本波收口的最小或最后一品 id>`。  
6. 汇报：本波 IDs（倒序）/ `last_product_id` / outpaint=是 / 接缝=0 / 空放大=否。

## 与父「产品优化」关系

| | 产品图优化（本） | 产品优化（父） |
|--|------------------|---------------|
| 子智能体 | **2**（图+详情图） | **3**（+翻译） |
| 双轮审查 | 建议对照图闸做 1 轮复扫 | 强制审查#1+#2 |
| 进度 | 只记 `last_product_id` | 可用总台账 |

冲突：像素 → `weline-image-pipeline`；详情楼层 HTML → detail-suite；本技能只管「图差」队列与倒序进度。
