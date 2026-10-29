# 长安汉服 · 品牌调性（详情页基线）

**本文件 = 详情 HTML 的品牌底线，仓内可读、宿主无关。**

- **父技能**：`ecommerce-detail-suite/SKILL.md`（§5.3 质感 / §5.4 品牌感）
- **像素侧同伴**：`companions/weline-image-pipeline.md`（含人图换人换景 + 衣锁死）
- **Token 权威**：`app/design/Weline/hanfu/frontend/colors/_hanfu-paper.css`、`.../variables/_hanfu-ink.css`；色盘合同见 `app/code/Weline/Theme/doc/theme-semantic-color-matrix.md`，字体注册见 `app/code/Weline/Theme/doc/theme-font.md`
- **社媒同源（视频，非详情）**：`websites/changanhanfu.com/opc/playbooks/brand-visual-apple.md`

冲突裁决（硬）：**本文件底线 + Theme Token + §5.2‑D 不裁脸 > 任何第三方「高级感/炫技」模板与指南原文**。
本文件只管「像不像这个品牌」；结构能否盖章以父技能 §5.2 为准。

---

## 0. 一句话口令

**宣纸为底、玄墨为骨、朱砂为点睛；动效是卷轴呼吸，不是电商甩卖。**

---

## 1. 品牌身份

| 维度 | 本站口径 |
|------|----------|
| 品类 | 明制/宋制汉服成衣，走「日常可穿的东方形制」，不走影楼礼服、不走民俗旅游片 |
| 目标观感 | 干净的编辑式lookbook：留白有呼吸、实物是唯一英雄、细节可证 |
| 反面画像 | 货盘批发页、促销红海、滤镜大片、假古风（堆纹样边框贴纸） |
| 语言气质 | 克制、书面、有出处；禁「爆款/必入/亲测绝美」式叫卖 |

---

## 2. 色板（只准 `var()`，禁字面 hex）

真源 `_hanfu-paper.css`（下列值仅为读数，**写码不得照抄成字面色**）：

| 角色 | Token | 用途 |
|------|-------|------|
| 页面画布（宣纸） | `--color-bg-canvas` (#f7f4ef) | 详情区底色，通栏楼层同底 |
| 纸面（次级卡片） | `--color-bg-primary` (#fffefa) / `--color-bg-secondary` (#eeeae1) | spec 面板、checklist 卡背 |
| 主文字（玄墨） | `--color-text` → `--hanfu-ink-primary` (#1c1c1c) | 正文、段题 |
| 次文字 | `--color-text-secondary` → `--hanfu-ink-secondary` (#4a4a48) | 旁注、caption |
| 弱文字 | `--color-text-tertiary` / `--color-text-muted` (#5f5d58) | 规格标签、脚注（已校 ≥4.5:1） |
| 描边 | `--color-border-default` (#d4cfc5) / `--color-border-subtle` | 分隔线、表头下沿 |
| **唯一强调色（朱砂）** | `--color-primary` (#b13f3c) | 仅用于：段题起首竖线、关键数值、CTA、诗栏引号 |
| 链接（石青） | `--color-link` → `--hanfu-link` (#3d6b78) | 超链，不作装饰色 |
| 深底（顶栏/收束条） | `--color-bg-dark` (#1f2124) + `--color-text-on-dark` | 偶发全宽收束楼层 |

用色硬规：

1. **一屏内朱砂出现 ≤2 次**；禁止大面积红块、红底白字横幅、渐变红。
2. **禁止引入盘外色**（科技紫、赛博青、荧光橙、苹果灰 `#f5f5f7`、纯黑 `#000`、纯白 `#fff`）。需要新色 → 进色盘加叶子，不在详情 CSS 里写 `#hex`。
3. 详情商品图容器 **`background: transparent` + 无 border**（父技能 §3.6‑A）；不许用米色垫底造相框。

## 3. 字族与字阶

| 用途 | Token | 说明 |
|------|-------|------|
| 卷名 / 楼层大标题 | `--font-family-display`（LXGW WenKai → Kaiti 栈） | 楷体气质，只给标题，不给正文 |
| 正文 / 旁注 | `--font-family-base`（Noto Serif SC → Songti 栈） | 衬线到底，禁混系统无衬线当「现代感」 |
| UI 小件（标签、按钮） | `--font-family-ui` | 沿用店面，不自造 |

字阶地板沿用父技能 **§五‑B**；本文件只补品牌侧约束：

- 正文行高 **1.7–1.8**，行长 **50–60ch**（§5.3‑C 法则 3）。
- 竖排诗句用 `writing-mode: vertical-rl` + `--font-family-display`，字号 ≥ 正文；禁把整句压成一列蝇头装饰。
- 数字（成分百分比、尺寸）用 `font-variant-numeric: tabular-nums`，与标签基线齐（§5.3‑C 法则 1）。

## 4. 版面节奏（品牌版「疏」的定义）

本站的「高级感」来自**疏**，不来自**满**：

| 规则 | 要求 |
|------|------|
| 楼层间距 | 主楼层之间 ≥ `3rem`，quiet 楼层前后 ≥ `5rem`（优先 Theme 间距变量） |
| 开篇 | lead 文案 + 一张净实拍（caption 栈）；**不做**首屏超高通栏巨图墙 |
| 中段 | 每 1 个通栏图后必须接实质 prose；诗侧栏是本品牌默认「图文对照」形态 |
| 收束 | `spec_panel` / `checklist_trust` + 一行品牌落款（形制考据来源或产地），**不做**促销收尾 |
| 纹理 | 纸纹/底纹只走 `--weline-page-canvas-*` 五个 token（AGENTS.md 版心契约），禁 `body{background-image}` |

## 5. 动效基调（对接 §5.4）

| 要 | 不要 |
|----|------|
| 淡入 + 竖向轻移（12–20px）、0.6–0.85s ease-out，像卷轴徐徐展开 | 弹跳、缩放甩入、横向滑刷、霓虹扫光 |
| 诗栏文字比同层图片**略晚**入场（约 +80–120ms stagger） | 全同层同时闪、每张图同构弹到页底 |
| `prefers-reduced-motion: reduce` → 直接终态 | 无视系统设置 |
| 复用店面 `productDetailReveal` / `.weline-detail-reveal` | 私造第二套 observer、塞 React/Framer |

## 6. 文案调性（卖点整理 → HTML 用词）

| 允许 | 禁止 |
|------|------|
| 形制事实：领型/袖型/裙门/褶数/织金位置，能对上图 | 「仙气飘飘」「绝美」「显白一绝」等无证据形容词堆叠 |
| 工艺出处：面料成分、染色/织造方式、产地（可证才写） | 假销量、假「已售 N 件」、假大师手作（无证据） |
| 穿着场景：季节、场合、搭配建议（一句一条） | 长段抒情盖过信息层 |
| 尺码引导：指向 HTML 尺码表（§3.2‑A） | 「拍大一码」这类无依据口头建议 |

多语：每个启用 locale 都要字段完整真译（父技能 §六），禁机翻糊长度。

## 7. 图像基调（详情内实拍，像素细则见同伴文件）

- 含人图一律 AI **换人 + 换背景**，服装像素锁死本品（`method=ai_full_model` + `clothing_lock`），交付新 `object_key`（`ai-lock-{sha16}.webp`）。
- 背景取向：柔和漫色 / 极简影棚光场 / 与服饰呼应的低饱和自然色；**禁**景区旅拍、堆道具古建布景、脏橙滤镜。
- 人物：虚构成年模特，禁仿冒真人；表情安静、姿态展示形制而非摆造型。
- 画面干净：无花字、无水印、无边框贴图；文字一律后期 HTML。

## 8. 品牌 FAIL 清单（命中即「品牌感不过」，禁写 `data-weds`）

1. 详情出现盘外色（紫渐变 / 赛博青 / 大红横幅 / `#f5f5f7` 苹果灰）。
2. 朱砂一屏 >2 处，或被用作大面积底色。
3. 商品图带灰米色垫底块或 1px 相框描边。
4. 正文用无衬线系统栈；标题与正文同字族同字号（层级塌）。
5. 动效为弹跳/甩入/扫光，或全页静态硬切无入场。
6. 文案出现叫卖词库（爆款/必入/绝美/显白）或无证据销量、荣誉。
7. 开篇即超高通栏巨图墙，且其后连续 ≥2 张无旁文大图（同时触 §5.2 反滑梯）。
8. 详情内仍用原始含人实拍上页面（未换人换景、或未破 `object_key`）。
9. 烤字 JPG 承担卖点/尺码长文（应 textify 成 HTML）。
10. 引入盘外字体或未注册的 `@font-face` 私链。

## 9. 汇报格式（父技能收口时附一行）

```
§品牌：色板=Token only（朱砂出现 n 处/屏）；字族=display+base；动效=卷轴 fade；文案=形制/工艺可证；FAIL 清单 10 项=逐条 pass
```

拿不出该行的「品牌感」自述，视为未验。
