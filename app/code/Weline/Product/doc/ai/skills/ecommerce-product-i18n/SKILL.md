---
name: ecommerce-product-i18n
description: >-
  BRANCH under parent 产品优化: 翻译优化. SKIP MCP (content_ops_skills_skip_mcp)—
  host Read this dir + 翻译优化.md. Detect default-website locales and
  field-complete true-translate. Standalone: 翻译优化/商品翻译/多语补全/locale leak.
---

# 翻译优化（子技能分支 · ecommerce-product-i18n）

**仓内权威。跳过 MCP。** 宿主 Agent Store 同名 = 薄镜像。  
**父**：`ecommerce-product-optimize` + `dev/ai-command/product/产品优化.md`。  
**指令**：`dev/ai-command/product/翻译优化.md`。

```
产品优化（父 · 必须开 3 子智能体）
├── ① 主图/规格 → ecommerce-product-image + weline-image-pipeline
├── ② 详情优化 → ecommerce-detail-suite
└── ③ 翻译优化（本技能）← 你在这里
```

## 一、命中

| 触发 | 行为 |
|------|------|
| 父 `产品优化` / `商品优化` | 父**必须**把本分支派给第 ③ 子智能体（与图/详情并行） |
| `翻译优化` / `商品翻译` / `多语补全` / `locale leak` / 「翻译没做」 | **仅本分支**（不宣称主图/详情已全套） |

## 二、范围（做 / 不做）

| 做 | 不做 |
|----|------|
| 读默认站 `Website::getLanguageCodes()`（+`''`）打印启用语全表 | 扩到未启用上百语 |
| **检测**各语种商品信息是否字段完整真译（见 §三） | 只译段题留英文/中文正文 |
| 缺口 → **本回合模型真译落盘**（禁为交差启 Ollama，除非用户明示） | 只读 DB 不打开 `/{locale}/product/` 就宣称完成 |
| 至少 1 个非中英启用语店面抽检 | 把 1688/货源属性当「已译」 |

## 三、检测闸（严重）

对每个启用 locale（含 `''`）至少覆盖：

| 表面 | 检测 |
|------|------|
| `name` / 短标题 | 非专名残留错误语种大段 |
| `description` 详情 | §6.0 字段族（intro/inspire/look/macro/checklist/wash/info/size…）；禁 EN dump / ZH 渗漏 |
| 列表/规格可见文案（若本品写入 locale） | 与启用语一致 |
| SEO title/description（若站点有且属本品） | 非空且非英包渗其它语 |

**负向样例（=未完成）**：`/bn_BD/product/…` 段题孟加拉 + 正文 `Full and mid shots…`；非中文 locale 大段「设计心源」「唐制」制式句（专名可留）。

**收口**：检测全绿才允许向父汇报「翻译优化=完成」。父未开本智能体 → 父任务**不得**标产品优化完成。

## 四、与详情分支分工

| 详情优化 | 翻译优化（本） |
|----------|----------------|
| 版式 / 卖点 / 烤图→HTML / `data-weds` 结构 | 启用语**检测+补全**；字段级真译；`/{locale}/product/` 闸 |
| 父并行时：可先落源语/结构，把多语正文交给本分支或与本分支协同 | 以本分支检测结果为多语收口权威 |

单独触发「详情优化」时：详情套件仍须自带 §六多语（见 `ecommerce-detail-suite`）；父「产品优化」则**必须**另开本智能体，不得用详情分支冒充已做翻译检测。

## 五、Agent 必做

1. Read 本文件 + `翻译优化.md`。  
2. 解析 product_id / URL。  
3. 打印启用语列表。  
4. 逐语种检测 → 列缺口表 → 真译落盘。  
5. `/{locale}/product/` 禁缓存抽检（≥1 非中英）。  
6. 汇报给父（见指令模板）。

冲突：多语检测/补全以本技能+指令为准；详情楼层像素/版式以详情套件与图管线为准。
