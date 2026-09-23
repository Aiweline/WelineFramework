# 套件补充（验收清单）

**权威**：同目录 `SKILL.md` + `companions/weline-image-pipeline.md` + 子指令 `详情优化.md`；父入口见 `产品优化.md`。  
本文件只做勾选；冲突时以上述为准。

## 层级（勿混）

| 层 | 是什么 | 路径 |
|----|--------|------|
| **父指令/技能** | 产品优化 / 商品优化 → 图管线 + **必须调子** | `产品优化.md` / `ecommerce-product-optimize` |
| **子指令** | 详情优化 / 商详优化… | `详情优化.md` |
| **子套件（详情 SOP）** | 排版·卖点·烤图 HTML·多语·跳过标记 | `SKILL.md` |
| **图管线（像素）** | 主图/规格 target_ar · 剥框 · AI outpaint | `companions/weline-image-pipeline.md` |

「产品优化」**包含**「详情优化」；**不是**同一入口同义词。

## 已优化跳过

- [ ] 仅认 `data-weds="xq"` / `<!--weds:xq-->`（兼容 `data-weline-detail-suite=`；**不认** 1688）
- [ ] 有标记 **且** §5.2 A+B+D PASS **且** §5.3 文字安全/行宽未翻车 **且** §5.4 滚轮入场/品牌动效 PASS，且无强制 → 可跳过
- [ ] 强制：`强制重做` / `--force` / 点名糊图·相册滑梯·大图竖墙·裁脸·翻译没做·没用上排版·没动画·滚轮没效果·没高级感·没品牌感·页面死板
- [ ] §5.2 或 §5.4 FAIL（即使已有标记）→ **强制重做 HTML/动效**；糊图/相册/漏译/信息烤图残留 → **禁写标记**

## 主图 / 规格图

- [ ] `target_ar` = **缺陷前目录画幅**（方 canvas/方卡 → **1:1**）；**不是**裁白后细长比；**不是**「随便用现网裁后 AR」
- [ ] 剥框后真·生图 AI outpaint；禁 cover 裁窄 / 色垫 / blur-fill / 空放大
- [ ] mid-lap 保清；类审 0 BAD

## 详情正文

- [ ] info_chart → 删图转语义 HTML；诗侧栏拼版 → 抽诗删图 → HTML
- [ ] §5.2：lead + feature/poem-aside + pair（或单图豁免）+ checklist/spec；Browser≥640 可见左右栏
- [ ] §5.2‑D：feature/poem 右图 `object-fit:contain`；清 `view/tpl`
- [ ] §5.3：Flex 横排 / Grid 拼墙；文案在 HTML；正文 max-width；Hero≠cover 切脸；Spec 基线齐
- [ ] **设计三件套已 Read**：`frontend-design`（审美细节）+ `ui-ux-pro-max`（风格质感）+ `interaction-design`（交互）
- [ ] §5.4：滚轮入场≥3 楼层；高级克制（慢稳小位移）；品牌气质贴品类；`prefers-reduced-motion` 静态终态
- [ ] 多样版式；字段级多语；`/{locale}/product/` 抽检
- [ ] 闸过才写 `data-weds="xq"`
- [ ] 观感非「排版太弱」：字阶/留白/质感/滚轮呼吸均可辨
