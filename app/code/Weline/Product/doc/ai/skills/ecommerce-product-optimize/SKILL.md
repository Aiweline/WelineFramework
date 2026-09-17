---
name: ecommerce-product-optimize
description: >-
  PARENT skill 产品优化 / 商品优化 / product optimize. SKIP MCP
  (content_ops_skills_skip_mcp)—host Read this file + 产品优化.md only.
  MUST launch THREE parallel subagents: (1) ecommerce-product-image +
  weline-image-pipeline; (2) ecommerce-detail-suite + 详情优化; (3)
  ecommerce-product-i18n + 翻译优化. Parent MUST run TWO review passes against
  child skill gates; fail → named rework to that subagent; only after review#2
  PASS may claim done. HARD HIT on 产品优化/商品优化 or /product/ with those intents.
---

# 产品优化（父技能）

**仓内权威父技能。跳过 MCP。** 全套 = **主图/规格 + 详情 + 翻译**；缺一不可。  
**收口 = 三子交付 + 父按子技能闸门审查×2**（见 §双轮审查）。

## MCP 排斥（严重）

- **禁止** `prepare_project` / `resolve_skill` / `get_skill` / 拉项目索引。
- 只 Read：本文件、`dev/ai-command/product/产品优化.md`、三子技能路径。
- 宿主 Store 同名 = 薄镜像。

```
产品优化（本技能 / 指令 产品优化.md）
├── ① 主图 + 画廊 + 规格图  → ecommerce-product-image
│                              + ecommerce-detail-suite/companions/weline-image-pipeline.md
├── ② 详情优化（子）        → ecommerce-detail-suite + 指令 详情优化.md
└── ③ 翻译优化（子）        → ecommerce-product-i18n + 指令 翻译优化.md
```

| 触发 | 跑谁 |
|------|------|
| `产品优化` / `商品优化` / `product optimize` | **本父全套**：并行 3 子智能体 + **双轮审查** |
| `详情优化` / … | 仅 ② |
| `翻译优化` / … | 仅 ③ |
| `主图优化` / `修主图` / … | 仅 ① |

## 三子智能体（严重 · 强制）

父 Agent **禁止**单线程串完三项后假装「已派分支」。  
**本会话必须**用宿主 Task/子智能体能力 **一次并行启动恰好 3 个**子智能体，并在各自 prompt 中 **粘贴对应技能+指令路径与硬闸**：

| 槽 | 子智能体职责 | 必传权威 |
|----|--------------|----------|
| ① | main/gallery/**variant** 图管线 | `ecommerce-product-image/SKILL.md` + `weline-image-pipeline.md` |
| ② | 详情正文：版式/烤图→HTML/卖点/`data-weds` | `ecommerce-detail-suite/SKILL.md` + `详情优化.md` |
| ③ | 默认站启用语 **检测+字段级真译** | `ecommerce-product-i18n/SKILL.md` + `翻译优化.md` |

公共上下文：`product_id` / 店面 URL / 仓库绝对路径 / 是否强制重做 / 验收 Host。

## 双轮审查（严重 · 强制 · 父亲自做）

三子回报「完成」**不等于**父任务完成。父必须按**各子技能硬闸**做审查，且 **至少两轮**：

```
并行①②③ → 审查#1（对照子技能清单）
  ├─ 全 PASS → 审查#2（复扫/复验，防漏）
  │     ├─ 全 PASS → 才可交付收口
  │     └─ 有 FAIL → 点名返工 → 再审查#2
  └─ 有 FAIL → 点名返工（写明槽位+缺陷）→ 子智能体改完 → 审查#1 重跑 → 再审查#2
```

### 审查对照表（父必勾）

| 槽 | 对照权威 | 最低检查项（FAIL 即返工） |
|----|----------|---------------------------|
| ① | 图管线 + `ecommerce-product-image` | main+gallery+variant 齐；`target_ar`（方卡→1:1）；真 outpaint 非 cover；无抠图虚空/拖影糊边/色垫；空放大=否；备份有 |
| ② | `详情优化` + detail-suite | 烤字/诗侧栏/信息表已 textify；**§5.2+D PASS**（杂志 HTML + Browser 左右栏/pair + **contain 不裁脸**；清 tpl）；`data-weds=xq`；无相册滑梯 |
| ③ | `翻译优化` + i18n | 启用语全表已检；字段级真译无 EN/ZH 渗漏；`/{locale}/product/` 抽检过 |

### 返工规则

1. **必须写明**：槽位（①/②/③）、对照哪条技能闸、具体缺陷（路径/asset/locale/现象）。  
2. **只派失败槽**返工（可并行多个失败槽）；prompt 附审查# N 原文。  
3. 禁止笼统「再优化一下」；禁止父自己偷改却不记审查。  
4. **审查#2** 必须重新跑对照表（可抽检+复测），不得复制审查#1 结论交差。  
5. 任一轮未记「审查#1/#2 + PASS/FAIL 表」→ **不得**声称产品优化完成。

## Agent 必做（父）

1. Read 本文件 + `产品优化.md`。  
2. 跳过检测：② **仅当** `data-weds` **且** 详情套件 **§5.2 排版硬闸 PASS** 且无强制 → 可跳过派 ②；凡「大图竖墙 / 无布局效果」→ **强制派 ② 重做 HTML**；①③ 仍按缺口；整单跳过仅当三面均已过闸。  
3. **并行启动 ①②③**。  
4. **审查#1** → 失败则点名返工 → 再审至 #1 PASS。  
5. **审查#2** → 失败则点名返工 → 再审至 #2 PASS。  
6. 交付；关 Browser；开发日志；汇报（含两轮审查表）。

冲突：像素→图管线；详情楼层→详情套件；多语→翻译分支；三智能体+双轮审查→本父/指令。
