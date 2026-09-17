---
name: ecommerce-detail-suite
description: >-
  CHILD skill slot ② under parent 产品优化. SKIP MCP (content_ops_skills_skip_mcp)—
  host Read this dir + 详情优化.md. Triggers: 详情优化/商详优化/… OR /product/ with
  those intents. Parent 产品优化 launches three parallel subagents. Skip ONLY if
  data-weds="xq" AND §5.2 layout gate PASS (magazine HTML: feature/poem-aside +
  pair + checklist; Browser≥640 visible—NOT big-image stack; §5.3 native
  Flex/Grid + text-safety); else FORCE HTML relayout. NOT weline-product-knowledge.
---

<!-- weline:product-detail-optimize:hand-written -->
# 详情优化（子技能 · ecommerce-detail-suite）

**仓内权威（子）。跳过 MCP。** 宿主 Agent Store 同名 = 薄镜像。  
**父技能**：`ecommerce-product-optimize` + 指令 `dev/ai-command/product/产品优化.md`（**包含**本子）。  
**子指令**：`dev/ai-command/product/详情优化.md`。

```
产品优化（父 · 三子智能体）
├── ① 主图/画廊/规格 → ecommerce-product-image + weline-image-pipeline
├── ② 详情优化（本套件）← 你在这里
└── ③ 翻译优化 → ecommerce-product-i18n
```

| 层 | 职责 | 文件 |
|----|------|------|
| **父指令/技能** | 全套：必须并行开 ①②③ | `产品优化.md` / `ecommerce-product-optimize` |
| **① 图** | main/gallery/variant | `ecommerce-product-image` + `weline-image-pipeline.md` |
| **子指令②** | 详情何时跑、跳过、汇报 | `详情优化.md` |
| **本套件②** | 详情排版 / 卖点 / 信息烤图→HTML / `data-weds`；（单独触发时含 §六多语） | `SKILL.md`（本文件） |
| **③ 翻译** | 默认站启用语检测+字段级真译 | `翻译优化.md` / `ecommerce-product-i18n` |
| **图管线** | 像素 SOP（①主用；详情内实拍可复用） | `companions/weline-image-pipeline.md` |

冲突时：**像素以图管线为准；详情正文以本文件为准；触发/跳过以对应指令为准。**  
**禁止**把拓图 SOP 写进 `weline-product-knowledge`；**禁止**把「产品优化」写成与本子「同一入口」。

| 同伴（可选） | 何处 | 用途 |
|--------------|------|------|
| DetailFlow / 文案 FABE / 工业详情包 | **宿主 Agent Store** `companions/`（本仓目录不一定有副本） | 楼层蓝图、卖点、QC |
| **产品图处理** | **本仓** `companions/weline-image-pipeline.md` | 像素 SOP |

另齐读（按需）：`frontend-design`、`weline-theme-development`、`changan-hanfu-brand`、审图、`local-browser-urls`。

---

## 一、命中（严重）

**子指令**：`详情优化.md`（触发：`详情优化` / `商详优化` / `详情页优化` / `PDP优化`…；硬触发：`/product/` + **子**意图）。  
**父触发** `产品优化` / `商品优化` → 先跑父，再**必须**调本子——不是「只命中本套件当全套入口」。

凡「详情优化 / 商详优化 / 详情排版 / 卖点 / 烤字 / 抹三方」等 → **命中本子**。  
仅「主图/规格图处理」且无详情意图 → 走父/图管线，勿把本子当唯一入口。

### 1.1 已优化跳过标记（严重）

**只用本套件无意义标识**（对买家无文案、与货源/1688 无关）：

```html
<!--weds:xq-->
<span data-weds="xq" hidden aria-hidden="true"></span>
```

或挂在详情根节点上（**仅** `data-weds`，禁止拿 1688/货源类属性冒充本标记）：

```html
<div … data-weds="xq">
  <!--weds:xq-->
  …
</div>
```

| 规则 | 说明 |
|------|------|
| **本标记唯一** | 跳过标记 **只认** `data-weds="xq"` 或 `<!--weds:xq-->`。`data-weline-product-description="1688"` **不是**本标记，禁止写成「已优化」依据，也禁止为做跳过而新增/强调 1688 |
| **写入** | 本套件/指令**成功收口详情正文后**（须过 **§5.2 排版硬闸**），必须写入 `data-weds="xq"`；建议同步 `<!--weds:xq-->`（详情区靠前、不可见） |
| **检测** | 动手前读各语种（至少默认语）详情 HTML：**有标记 ≠ 可跳过**。必须再跑 **§5.2**（结构计数 + Browser 可见排版）。仅当「有标记 **且** §5.2 PASS」才视为已优化 |
| **兼容** | 历史 `data-weline-detail-suite=` 可参与标记检测；**绝不**用 1688 属性做兼容；**绝不**因有标记就跳过排版闸 |
| **跳过** | 标记齐 **且** §5.2 PASS，且用户未强制 → 可跳过；汇报须写 `跳过：data-weds=xq + 排版闸PASS` |
| **强制重做** | `强制重做` / `重跑优化` / `忽略已优化标记` / `--force`；或用户点名 **糊图未处理 / 千篇一律大图 / 没设计 / 相册滑梯 / 没有任何排版 / 没有布局效果 / 全是大图 / 一张大图从上到下 / 翻译没做 / 其它语言仍中文或英文 / 启用语漏译 / 尺码表还是图 / 诗句侧栏 / 文图拼版 / 竖排烤字 / 布局太单一 / 没用上排版**（即使已有 `data-weds`）→ **必须重做 HTML 杂志排版**；重做后仍须写回 `data-weds="xq"` |
| **自动强制（严重）** | 只要 §5.2 FAIL（含「只有通栏大图竖叠、无左右/双列/清单等可见版式」），**即使已有 `data-weds`** → **视为未优化，强制重做详情 HTML**；禁止「修糊边/换图」冒充排版完成 |
| **禁止** | 把 `xq` 写成买家可见文案；禁止用 `1688` / 货源 / 营销 token 当跳过标记；**禁止**糊图/相册模板/漏译/信息烤图残留/诗侧栏拼版/**纯大图竖墙无排版**仍盖 `data-weds` 交差 |

---

## 二、硬标记（Weline）

| 任务 | 只做 | 不做 |
|------|------|------|
| 详情排版/卖点 | 详情正文：图结果、旁文、卖点、尺码语义、详情内排版 | 顶栏/买卖区/SEO/价格/规格轴/主题全局 |
| **主图/规格图处理** | `main`/`gallery`/`variant` 像素与 FileAsset | 勿把主图缺陷留给详情；规格图规则=主图（仅尺寸可不同） |

汇报：`表面：主图|规格|详情` · `target_ar：…` · `裁后补回：是` · `图处理：已做` · `类审：N→0` ·（详情另加）`风格：古风` · `排版：多样（非纯竖叠）` · `三方：已抹` · `卖点：已表`。

- 禁编造不可见图参数；字体色仅 Theme Token；禁 Ollama。

---

## 三、图处理（严重 · 组合能力）

**权威细则在 `companions/weline-image-pipeline.md`。** 下节为索引；冲突以专规为准。

产品/详情任务**必须先做图分流与处理**，再排版。不可「只改文案、脏图原样上」。

### 3.0‑S 表面（主图 / 规格 / 详情）

| 表面 | 画幅 | 说明 |
|------|------|------|
| 主图 `main`+`gallery` | **锁目录 target_ar**（缺陷前 canvas；**店面方卡/原图方 canvas→1:1**） | 裁白/剥框后 **真·生图 AI outpaint** 把全图装进画幅；**禁止** cover 裁窄、色垫/反射糊边假拓 |
| 规格 `variant` | **同主图规则** | 仅输出尺寸可按槽位缩小；同样裁后补回 |
| 详情正文图 | 审美灵活 | 不强制主图 AR；按原型节奏，禁止纯竖叠图交差 |

### 3.0 合法手段（严重 · 禁抠图）

详情图处理**只允许**：

1. **高清化**（换更大原图 / ffmpeg lanczos / Real-ESRGAN）  
2. **真·拓展图**（§3.1‑F：outpaint / 边缘内容生成续墙续景——**主体仍占满可读幅面**）  
3. **按图排版**（竖图单列 stack/fullbleed；无真横图就不要硬凑横槽）  
4. 抹三方/删字板、裁**均匀死白边**（裁框）

#### 硬禁（负面已验）

| 禁止 | 用户可见败因 |
|------|----------------|
| **抠图** rembg / 贴纸毛边 / **纯黑虚空底**（边框近黑占比高、无脚底接触影） | 锯齿、残影、假宣纸、发丝晕边；1688 源若已是黑底抠图也**不得入架**，须换连续棚景整幅或丢弃 |
| **纯色垫边** `#f7f4ef`/灰条 | 中间真图、两边色砖 |
| **blur-fill 糊边凑画幅** | 中间一小块清晰图、左右大面积模糊同源图、主体缩到看不清 |

正向只有：**高清放大（主体仍大）**，或 **真拓展（场景像素真正往外长，主体不缩成邮票）**。无 outpaint 能力时 → **改竖排用整幅 HD**，禁止糊边凑横。

### 3.1 分流（每图一行）

`序号 | 类型 | 三方？ | 清晰度 | 画幅 | shot_grade | frame_box | 建议原型 | 动作`

类型：`photo` / `caption_board` / `text_board` / `info_chart` / `third_party` / `soft_blur`。

orientation：`portrait`（h/w≥1.15）/ `squareish`（0.85–1.15）/ `landscape`（h/w≤0.85）。

### 3.1‑B 审图画幅 → 定版（严重 · 竖屏优先）

**排版跟着图走，不是先定左右再硬塞图。** 汉服主图常见 3:4 / 2:3 **竖屏全身**——必须在分流表写清 AR，并按下表锁原型；禁止「先一律 feature_lr / 先一律裁方」再补救。

| 源图画幅 | 默认原型（优先） | 禁止 | 若必须横幅楼层 |
|----------|------------------|------|----------------|
| **竖屏** portrait | `stack_caption` · `fullbleed_hero` · `editorial`+通栏 · 竖对竖 `pair` | 抠图；纯色条；**blur-fill 糊边缩主体**；未有真横硬塞 `feature_lr` | 仅 **真 outpaint** 扩到横幅且主体仍大；否则 **改竖排 HD** |
| squareish | `macro` · 异质特写 · `pair` | 同棚三联；blur-fill | — |
| landscape（真横实拍/真裁切满幅） | `feature_lr` · 宽 `fullbleed` | 竖图糊边凑横；抠图 | — |

硬规则：

1. 全身竖拍保衣长证据 → 单列上图下文。  
2. **扩图 = 内容延展**，≠ 裁方 ≠ 抠图贴色。  
3. 落码前标注 orientation；`feature_lr`≤2 且仅用真横或内容延展横图。  

### 3.1‑C 货盘棚拍怎么处理（严重 · 不抠图）

货盘特征：白帘皱褶、绿植、木地板、无头紧裁、多帧同背景。

| 做 | 不做 |
|----|------|
| HD 高清化，保留整幅实拍 | rembg / 抠衣 / 贴宣纸 |
| 一屏一图纵向叙事；禁同棚三联 | 三张竖条并排 |
| 需要横槽 | **真 outpaint**；无能力则改竖排 HD | 糊边凑横、抠图、纯色条 |
| 排版限宽居中消化竖图 | 为「大气」毁掉边缘完整性 |

口令：**棚丑用排版躲，不用抠图救。**

### 3.1‑D 内容居中 + 异色垫边（左右 / 上下）→ 裁掉或真拓展（严重）

用户常见败因：**主体在中间，左右或上下出现与画面内容不一致的死白 / 浅灰 / 藕灰垫色**（letterbox / pillarbox / 假画布）。**上下与左右同等审查，禁止只扫左右。**

`frame_box` = `tight` | `pillarbox_pad` | `letterbox_pad` | `padded_ok`（场景本身浅色但有纹理/纵深，非死色板）。

| 检测 | 判定 |
|------|------|
| 左右 **或上下** ≥6–8% 为近匀色且低方差，并与邻接内容色差明显 | **异色垫边**（失败） |
| 浅色但是墙/帘/地板纹理，与场景连续 | 保留，走内容延展或原图排版 |
| 方图 blur-fill 后再竖裁，仍残留顶底灰条 | 仍算 letterbox，须再裁 |

```
1. 审图：标 frame_box；上下+左右都扫；先锁定 target_ar（缺陷前规范比；方 canvas/方卡→1:1；禁用裁后细长比）
2. 裁到内容外接矩形（去死白/异色板/拼版外框；保留全身）
3. 【必做】装进 target_ar：**真·生图 AI outpaint**（`GenerateImage`+参考图+目标 `aspect_ratio`）；主体完整不裁窄
4. 禁止：cover 裁人凑方；色垫/黑棚续黑/反射糊边假拓；只裁交细长条；抠图；blur-fill
```

验收：预览/DevTools——**AR≈target_ar**；方图须 1:1；**无新刷色垫**；method=`outpaint`（不得冒充）。
### 3.1‑F 真·拓展图（严重 · 禁糊边缩主体）

**拓展图 ≠ 把竖图缩到画布中间再糊一圈。**

| 合法（正向） | 非法（负面） |
|--------------|----------------|
| **高清化**：主体仍占画幅主要面积，细节可辨 | blur-fill：清晰区缩成中间一条，两侧大糊 |
| **真·生图 outpaint**：图像模型生成墙/帘/地/光影外延，衣身比例接近原图 | 纯色/`黑棚续黑`/场景连续色 pad；blur-fill |
| **真横裁**：从原图裁出本就横构图的满幅区域再 HD | 抠图贴纸 |
| 无可靠 **真·生图** outpaint | **主图/规格且 target 为方卡/须补宽** → 如实阻断，不得裁窄/色垫交差；**详情楼层**可改 `stack`/`fullbleed` 竖排 HD | 为凑 `feature_lr` 任何糊边/色条；假装已优化方卡比例 |

验收：眯眼看——衣身应大且清；若「中间邮票 + 周围糊影」= 失败。

**与图管线一致**：主图/规格装 `target_ar`（尤其 1:1）以 `companions/weline-image-pipeline.md` Step E 为准；本节约索引，禁止写「无 outpaint 就竖裁交差」。

### 3.1‑E 图片怎么放（排版方法 · 严重）

**先 HD；有真横或真 outpaint 才横放；否则竖排放大。**

| 图内容形态 | 先做什么 | 怎么放 | 禁止 |
|------------|----------|--------|------|
| 竖屏衣长 | HD | 单列 stack/fullbleed，图要大 | 糊边凑横；抠图 |
| 居中+死白 | 裁白 → HD 或真 outpaint | 满幅可读 | blur-fill；色条 |
| 真横满幅实拍 | HD | feature_lr≤2 或宽 fullbleed | 再糊一圈 |
| 多张同棚竖图 | HD | 一屏一张竖叠 | 三联；糊边 |

决策树：`能真拓展？→拓展且主体仍大｜否则→整幅 HD 竖排｜禁止糊边缩图`

### 3.2 三方与烤字（文+图）

抹除：1688/阿里/淘宝天猫/拼多多/抖音带货、旺旺、二维码、**上家/厂家/货源/一件代发/混批**、他店字板与角标。

| 情况 | 动作 |
|------|------|
| 实拍上角标/水印 | 裁掉/修掉/换无痕图 |
| 整张字板（如「设计灵感」青绿底） | **删图**；文案抽出到自有古风旁文 |
| 图上叠卖点框/图标墙 | 卖点进**卖点表**；图裁为净特写或删框换净图 |
| **尺码表 / 规格参数 / 宝贝信息 / 面料护理表等「信息烤图」** | **见 §3.2‑A（严重）**：审图 → **删 `<img>`** → 语义 HTML；禁止图留在详情 |

### 3.2‑A 尺码·规格·信息烤图 → HTML（严重 · 审图硬闸）

详情里凡**以表格/键值/参数墙呈现信息**的图片（常见：尺码表、大袖衫/襦裙分表、胸围衣长通袖、产品信息、面料成分、洗涤护理、温馨提示误差说明），**不是实拍货品图**，一律当 **info_chart**，不得当主图/画廊/详情实拍楼层。

#### 正向（必须）

1. **审图分类**：分流表写 `kind=info_chart`（子类：`size_chart` / `product_info` / `care_chart` / `param_wall`）。  
2. **抽数**：按图中可见行列/键值抄录（表头、尺码行、单位、脚注）；优先肉眼对齐图；OCR 仅辅助，**乱码则按图重抄**，禁止把 OCR 垃圾当表。  
3. **删图**：从详情 HTML **移除该 `<img>`**（及只包这张信息图的空 figure）；不得升清、不得 outpaint、不得当 fullbleed。  
4. **落语义 HTML**（复用店面已有样式，禁止另起一套无名 class）：  
   - 尺码/测量分表 → `DetailDescriptionTextifier::buildMeasurementSizeChart*` →  
     `div.weline-detail-text.weline-detail-text--size-chart[data-weline-detail-text=measurement-chart]`（多表用 `__columns` / `__col` + `<table>`）  
   - 建议体重/尺码对照 → `…--size-chart[data-weline-detail-text=size-chart]`  
   - 产品信息/舒适度 → `…--product-info`  
5. **多语**：表头、段题、脚注进默认站各启用 locale **真译**；数字/尺码码（S/M/L、cm）可保留；禁止整表用英文包映射。  
6. **类审复扫**：详情内 **0 张** 尺码/规格信息烤图残留才可写 `data-weds`。

**正向样例（用户已验形态）**：白底浅蓝表头的「大袖衫尺码」「襦裙尺码」双表 +「温馨提示…1–2CM」→ 删 JPG → 两张语义 `<table>`（胸围/衣长/1/2袖口/通袖长/腰头/裙长…）+ note 段落，**不是**再贴一张更清晰的表图。

#### 负向（禁止）

| 禁止 | 败因 |
|------|------|
| **详情保留尺码表/规格信息 JPG/PNG** | 不可真译、不可无障碍、像货盘烤字；多语站空白或糊字 |
| 对信息烤图做 HD / outpaint / 换棚景 | 浪费；信息仍锁死在像素里 |
| 把尺码表当 `fullbleed` / `pair` 实拍楼层 | 版式假杂志、实质仍是字板 |
| OCR 乱码原文入库 / 半截表交差 | 买家不可用；比留图更糟 |
| 手写无关 class 或截图表再嵌 `<img>` | 绕过店面 `weline-detail-text--*` CSS |
| 「先留图、以后再转 HTML」仍盖 `data-weds` | **未完工** |

#### 与实拍分流（勿误伤）

| 是 info_chart（转 HTML） | 不是（走实拍图处理） |
|--------------------------|----------------------|
| 整张几乎只有表格/键值/参数条 | 模特/静物实拍（可含小角标另裁） |
| 标题含「尺码」「规格」「产品信息」「洗涤」「参考表」且无衣身主体 | 绣片/面料微距特写 |
| 白底蓝灰表头 Excel 风长图 | 场景大片上的装饰字（裁字后仍是实拍） |

口令：**信息在图里 = 先抽后删再表；表图不得入架。**

### 3.2‑B 文图拼版 / 诗句竖排侧栏烤字（严重 · 审图硬闸）

货盘详情常见「左（或右）奶油竖栏 + 竖排古诗/情句烤字 ± 竹月云纹装饰 + 对侧模特实拍 + 底白条」整张 JPG。分流写 `kind=caption_board` / `text_board`，子类 **`poem_sidebar_collage`**。**不是**杂志美学，不得当 fullbleed/pair 实拍楼层。

#### 判据（任一即命中）

| 信号 | 说明 |
|------|------|
| 侧栏近匀色竖条 | 左或右 ≥18–30% 近白/奶油/浅灰，方差低，与实拍区色差明显 |
| 竖排汉字烤在图里 | 诗句/情句沿竖向排列，买家无法选中、无法多语真译 |
| 装饰底纹 | 竹叶/月轮/云纹淡印叠在侧栏 |
| 底白条 / 圆框底句 | 整图外框白垫，或圆 vignette + 底下一行烤字 |

#### 正向（必须）

1. **审图分类**：分流表写 `poem_sidebar_collage`（或同类 `caption_board`）。  
2. **抽文**：抄录可见诗句/段题（OCR 仅辅；乱码按图重抄）。  
3. **删图**：从详情 HTML **移除整张拼版 `<img>`**；禁止升清、禁止 outpaint「修美」后留拼版。  
4. **落 HTML 排版**（主题 Token，禁止私造 hex）：  
   - 竖排诗 → `weline-detail-prose weline-detail-prose--verse-vertical`（`writing-mode: vertical-rl`）  
   - 文图对照 → `weline-detail-feature weline-detail-feature--poem-aside`（文窄栏 + 净实拍）  
5. **净实拍**：裁切拼版中的实拍区另存 FileAsset，或换同品净帧；侧栏烤字区不得入裁切结果。  
6. **多语**：段题/旁文进启用语真译；古诗原文可保留中文（专名/古典引用），但不得整页只挂烤字 JPG。  
7. 类审复扫：**0 张**诗侧栏/文图拼版烤字残留才可写 `data-weds`。

#### 负向（禁止）

| 禁止 | 败因 |
|------|------|
| **详情保留诗侧栏拼版 JPG** | 不可真译、像货盘海报；「没用上排版」 |
| 把侧栏烤字当古风杂志美学 | 假排版；用户已验漏检 |
| 对拼版做 HD / outpaint 后仍入架 | 文字仍锁在像素里 |
| 只删图不落 HTML 竖排/旁文 | 丢失叙事；版式更空 |
| 仍盖 `data-weds` | **未完工** |

口令：**诗在图里 = 抽诗删拼版 → HTML 竖排 + 净实拍；侧栏烤字不得入架。**

### 3.3 高清化（模糊 / 低清 / 噪点 · 严重）

排版修好、糊边去掉之后，**清晰度与干净度仍是硬门槛**。用户常见败因：

1. 「不糊边了，但不够清」  
2. 「分辨率放大了还是糊」← **空放大**（仅 lanczos/几何放大软图）  
3. 「噪点很严重」← 强 unsharp / 空放大把颗粒打爆

#### 审图硬闸（严重 · 先审源图再动手）

详情每张商品图在分流表必须写清 **`source_grade`**，禁止「先放大交差、再靠用户吐槽」：

| source_grade | 判定（目测优先，脚本辅证） | 允许动作 |
|--------------|----------------------------|----------|
| **sharp_ok** | 绣线/面料纹理可读；平坦区无明显颗粒；**bpp 够**（见下） | 可轻度去噪后按需放大到短边≥1200 |
| **soft_blur** | 源图本身糊（对焦软、运动糊、过度压缩）；或 **低码率 JPEG**（`bytes*8/(w*h) < 1.2` 且短边≥900）即使 mid-lap 虚高 | **禁止空放大**；换更清原图，或去噪 + **Real-ESRGAN 真超分**；无 ESRGAN → 去噪后近原生，**禁止**当 sharp 交差 |
| **grainy** | 帘/墙/暗部颗粒、色斑噪点重 | **先去噪**（hqdn3d/nlmeans 等），再极轻锐化或不再锐化；禁止未去噪狂 unsharp |
| **soft_and_grainy** | 又糊又噪 | 去噪优先 +（换原图或 ESRGAN）；禁止只拉分辨率 |

分流表示例列：`序号 | … | source_grade | bpp | 噪点 | 动作`

**低码率 soft 闸（严重 · 用户已验 #200）**：大图文件很小（常见 ≤160KB@≥1200 边）→ 观感必糊。脚本 mid-lap 可能因噪点虚高，**目测/bpp 优先**。此类必须 ESRGAN 或换清原图，**禁止**只 crop+lanczos 后盖 `data-weds`。

**审图不过 = 未完工**：眯眼看平坦区颗粒、看绣线是否仍糊成一团。**糊图未处理 = 不得写 `data-weds`。**

#### 硬禁（升清反模式）

| 禁止 | 为何失败 |
|------|----------|
| **空放大** | 源图已糊，只把像素网格拉大 → 更大的糊图 |
| **先猛锐化再放大** / 对噪点图狂 `unsharp` | 颗粒、蚊香噪点爆炸（审图必抓） |
| 只改 `width/height` 或短边数字交差 | 观感仍糊/仍噪 |
| 不写 `source_grade` 就批量 lanczos | 把 soft/grainy 当 sharp_ok |

#### 合法升清路径（按序）

1. **审图定级** `source_grade`（上表）。  
2. **优先换更清晰的更大原图**（CDN/源站；去缩略后缀）。  
3. **sharp_ok**：可整幅放大到短边≥1200（竖图优先约 1600–1800 短边），**先轻度去噪再极轻锐化**。  
4. **soft_blur / grainy / soft_and_grainy**：必须 **去噪 +（换原图或真超分）**；**无 ESRGAN 时禁止空放大**——去噪后保持裁切区接近原生分辨率，宁小而净，勿大而糊。  
5. 本仓：`remediate-product-image-hd.php` / 详情 suite `replaceContent`；记录 method。  
6. **升清 ≠ 糊边扩画幅**；整幅处理，主体仍满幅。

#### 审图必过（目测）

- 帘/墙平坦区：**无明显颗粒/色斑噪点**  
- 绣线/褶影：**比处理前更可读**，不是「更大但一样糊」  
- 汇报：`清晰度：source_grade=…；method=…；空放大=否；去噪=是/否；短边=…`

### 3.4 画幅不合适 → 拓展（竖→横 / 窄条 / 去白后扩）

| 问题 | 工具动作 |
|------|----------|
| **左右/上下死白** | 裁白 → 主图/规格按图管线 Step E 真 outpaint 装 `target_ar`；详情可竖排 HD；**禁 blur-fill** |
| **竖屏要横楼层** | 有真 outpaint 且主体仍大才横放；否则改竖排 HD |
| **方卡/目录 1:1** | **必须** AI outpaint 装方；禁止 cover 裁成 3:4 冒充 |
| 货盘竖图 | HD + 单列；禁抠图、禁糊边凑横 |
| 拼版被裁切 | 丢弃拼版；换净单帧 |

无可靠生图 outpaint：**不得声称主图/规格比例已优化**；详情可暂竖排 HD。

### 3.5 图处理验收

- 无三方字板；清晰度达标。  
- **无抠图、无纯色条、无「中间小图+两侧大糊」**。  
- **无尺码/规格/产品信息烤图残留**（须已 §3.2‑A 转 HTML）。  
- 主图衣身大且可读。  
- 竖屏单列；`feature_lr` 仅真横满幅。

### 3.5‑A 全量类审（严重 · 禁止等用户点图）

详情图处理**不得**等用户截图/点名某一张才改。完工前必须对**详情正文内全部商品图**做同一类缺陷扫描，按类批量修：

| 负面类 | 判定（目测或脚本） | 正向动作 |
|--------|--------------------|----------|
| **blur-fill** | 中间清晰条 + 左右大面积同源糊边，主体缩成邮票 | 裁清晰主体 → HD 竖幅满幅；或真 outpaint；否则改竖排 |
| **纯色垫边** | 左右/上下 `#f7f4ef`/灰条色砖 | 裁死色 → HD 满幅；禁再垫 |
| **抠图贴纸** | rembg 毛边、假宣纸底；或 **纯黑/纯色虚空底**（边框近黑占比高、无地面接触影、发丝晕边） | **整张丢弃**或换成**连续棚景整幅实拍**（墙+地连续、有脚底阴影）；禁止再当主图/画廊/详情；禁止「抠完贴黑底」交差 |
| **假横凑槽** | 竖图糊边/色条硬塞 `feature_lr` | 改 `stack`/`fullbleed` |
| **异色垫边** | 上下或左右匀色条与内容不连续（letterbox/pillarbox/假画布） | **裁到内容**或真 outpaint；禁留灰条交差 |
| **清晰度不足** | 绣线/面料糊；或空放大后仍糊；**含源图本身 soft_blur** | 定 `source_grade`；换清原图；或去噪+真超分；**禁空放大** |
| **噪点爆炸** | 平坦区颗粒/色斑重（常被强锐化放大）；**含源图 grainy** | 定级后先去噪再极轻锐化；禁止对噪点图狂 unsharp |
| **框底/描边相框** | figure/media 灰米色垫底或 1px 描边；图内货盘板边 | CSS 透明+无边；裁掉板边；禁 contain 造色框 |
| **货盘拼版/多宫格** | 一张图里上下/左右多块实拍 + 白缝/色块遮罩/角标；或 `cover` 后只剩细条/切脸 | **整张丢弃**；改用净单帧实拍 HD 竖排；禁止把拼版当「杂志美学」 |
| **诗侧栏/文图拼版烤字** | 左/右奶油竖栏竖排诗 + 实拍；或圆框底句烤字（§3.2‑B） | **删拼版**；抽诗→HTML 竖排 + 净实拍 `poem-aside`；禁当美学 |
| **文案切脸/缝插文** | 旁文/白底段落落在拼版白缝里，看起来像脸被拦腰切断 | 禁止拼版入楼层；文案只在图外独立 prose；禁止负 margin 叠进图 |
| **细条裁尸** | 图容器高度塌成几像素，只剩一条绿/棕/花色线 | 查 `object-fit:cover`+限高/错 AR；改 `height:auto` 满宽；换净单图 |
| **尺码/规格信息烤图** | 详情 `<img>` 主体为尺码表、规格参数墙、产品信息/护理表（白底表头、行列数字） | **删图** → §3.2‑A 语义 HTML（`weline-detail-text--size-chart` / `--product-info`）；禁升清留图 |

硬规则：

1. **一类问题修一类**，不是「用户指哪修哪」。  
2. 扫描范围 = 详情正文 `product-description` 内全部 `<img>`（含 asset 解析后的 media），不含 og/twitter 元图、推荐位。  
3. 脚本辅助可有（左右 Laplacian vs mid、paper_frac、**顶底 row_std 垫带**、**中缝白带**），**目测仍要过**：衣身是否大且清、源图是否糊/颗粒、外缘有无灰框、**整文件有无上下/左右异色垫**、**有无拼版白缝切主体**、**有无尺码/规格信息烤图残留**。  
4. 复扫至该类 **0 BAD** 才可进排版验收/交付。  
5. 汇报须写：`类审：blur-fill/色条/抠图/清晰度/噪点/框底/异色垫/拼版切脸/细条/信息烤图/诗侧栏 = N→0`（frame_box；空放大=否；method=裁切|outpaint|**textify**|**poem_html**）。

### 3.5‑B 负面提示词（严重 · 写入技能 · 非美学）

下列是**失败观感**，**不是**「技能美学 / 杂志拼贴」。生成图、扩图、选图、排版验收一律当 **negative**：

```
货盘拼版, 多宫格详情图, 上下两截人脸, 白缝拦腰切脸, 文案插进图缝,
细条残图, 几像素高的图, object-fit cover 切主体, 白块遮罩拼贴,
诗句竖排侧栏烤字, 文图左右拼版海报, 奶油竖栏古诗烤在图里, 圆框底句烤字,
布局太单一, 没用上排版, 纯竖叠相册详情,
异常隐藏五官, 头顶与下巴分属两块板, 1688 详情拼板, 角标红章压图,
中间白条海报缝, 左右半身拼版被裁成一条线, glitch crop, face cut by banner,
collage seam through face, paper-white mask blocks, ultra-thin image strip,
细长条主图, 裁白边不补比例, 书签形货架图, 纯竖叠相册详情, 只有图没有旁文节奏,
宣纸灰异色垫边凑画幅, blur-fill 糊边补比例, 色垫补背景, 黑棚续黑凑画幅,
场景连续色对称补边, 纯色 pad 假 outpaint,
尺码表烤图, 规格参数墙 JPG, 产品信息字板图, 洗涤护理表截图, 白底蓝表头 Excel 长图留在详情
```

正向对照：

- 主图/规格：剥框 → **真生图 AI outpaint** 装进目录 AR（方图画幅→1:1）；衣身完整；无色垫/反射假拓；无死白柱  
- 详情：一屏一职；图文交错；≥4 种原型；旁文在图外；无白缝穿过主体

---

## 四、卖点整理（严重 · 必出表）

在排版前必须产出**卖点表**（可写入脚本注释或开发日志）：

| 卖点名 | 对应痛点/欲求 | 证据（visible/confirmed） | 视觉方向 | 落屏原型 |
|--------|----------------|---------------------------|----------|----------|
| 例：交领绣花 | 细节显气质 | 图可见绣花 | macro 特写 | `macro_annotate` |
| 例：马面印花 | 华贵主视觉 | 裾边狐莲纹 | 通栏/特写 | `fullbleed` / `stack` |

规则：

- 只写有证据的卖点；烤字板上的句子可**改写**为古风，不可照搬批发腔。  
- 每个卖点最多占一屏主职；禁止同一卖点左右刷两遍。  
- 细节介绍 = 卖点表展开的 1–2 句旁文，不是 OCR 复读。

齐读：`companions/product-description-generator/SKILL.md`（FABE）+ `ecommerce-detail-page-generator/references/claim-evidence-rules.md`。

---

## 五、版式多样性（反左右疲劳 · 反纯竖叠）

### 5.0 详情排版分步 SOP（严重 · 按序）

```
Step L1  卖点表（FABE）→ 每点一句证据，禁止无证据编造
Step L2  书面锁原型序列（≥4 种，密→疏→密）；写出后再落 HTML
Step L3  按图选槽：竖图走 stack/fullbleed/editorial；真横才 feature_lr
Step L4  图文交错：通栏后接 prose；要点旁文；pair 双色；quiet 透气；checklist/spec 收束
Step L5  字阶地板（§五‑B）+ §5.3 原生布局/CSS Token + 禁图容器框底描边
Step L6  Browser 禁缓存验收：非相册滑梯、可读、无小字巨图、§5.2‑D 不裁脸
```

推荐序列示例（汉服竖图多）：

`editorial_prose` → `stack_caption`/`fullbleed_hero` → `poem-aside`/`feature` → `pair_gallery` → `stack_caption`×1–2 → `quiet_spacer` → `checklist_trust` → `spec_panel`

（`feature_lr` 仅当真横可用，且 ≤2、不相邻。开篇优先 caption/散文，少用首屏超高 fullbleed。）

### 5.1 问题 → 解法（排版）

| 遇到的问题 | 怎么判断 | 怎么解决 | 禁止再做 |
|------------|----------|----------|----------|
| 详情像相册、无审美 | 连续只有图无旁文 | 插入 `editorial_prose` / 段题+要点；混 ≥4 原型 | 继续只叠 `figure-stack--solo` |
| 小字配巨图空洞 | 旁文高度 ≪ 图 | 改 `stack_caption` 或抬字阶+加厚旁文 | 维持蝇头字装饰感 |
| 假横槽难看 | 竖图硬塞左右栏 | 改竖排 HD；有真横再用 `feature_lr` | blur-fill / 色条凑横 |
| 图外灰框相框感 | figure 有底色/描边 | `background:transparent`；无 border | contain+垫色塞满 |
| 节奏闷 | 密密密无透气 | 加 `quiet_spacer`；密→疏→密 | 全程同构 |

### 正向（要做）

- 落码前书面锁**原型序列**，混用 **≥4** 种原型，有开合节奏（密→疏→密）。  
- 图与文**交错**：通栏巨图后接散文心源；要点用 `macro_annotate` / 短旁文；双色用 `pair`；收束用 `spec_panel` / `checklist_trust` / `quiet_spacer`。  
- `feature_lr` **≤2** 且不相邻；仅真横或真拓展横图。  
- 可用原型：`fullbleed_hero` · `editorial_prose` · `macro_annotate` · `pair_gallery` · `stack_caption` · `triptych` · `spec_panel` · `quiet_spacer` · `checklist_trust` ·（偶发）`feature_lr`

### 负面（禁止 · 用户已验败因）

| 禁止 | 用户可见败因 |
|------|----------------|
| **纯竖叠图** | 详情区只有「图、图、图…」自上而下单张铺满，无文图节奏 |
| **千篇一律大图到底** | 通栏 `fullbleed`/`solo` 一张接一张滑到页底，中间只有短句或空壳旁文；像相册、没有设计 |
| **批量同构模板** | 全库共用「lead→hero→verse→pair→solo×N→checklist→spec→close」且无按品卖点换节奏；脚本可生成起点，**不得**当终态验收 |
| **布局太单一 / 没用上排版** | 仅同构相册栈、无 `poem-aside` / `feature` / 竖排诗 / 密疏开合；用户点名即强制重做（§3.2‑B） |
| **无散文段落** | 缺少卷名/心源/要点等可读旁文，像货盘相册 |
| **同构重复** | 连续 ≥3 个相同 `figure-stack--solo`/`fullbleed` 且无交错实质 prose（≥2 句卖点） |
| **假杂志** | 用拼版/白缝冒充版式变化（见 §3.5‑B） |
| **整页左右刷** | `feature_lr` 刷屏或相邻 |

口令：**详情是杂志卷轴，不是相册滑梯。**

**版式验收闸（严重）**：落码后自检——从首屏到页底，是否出现「连续大图滑梯」？若是，**拆开**：每 1 通栏巨图后必须接实质 prose / checklist / spec / quiet；中段至少 1 个 `pair`/`triptych`/`macro_annotate`；收束不得再叠一张无文 fullbleed。未过闸禁止写 `data-weds`。

落码前书面锁原型序列。详见 DetailFlow。

### 5.2 排版硬闸（严重 · 强制 · 用户已验「全是大图从上到下」）

> **目标**：买家在店面看到的是 **HTML 杂志排版**（文图交错、双列/诗侧栏、清单表），**不是**「一张大图接一张大图竖滑到页底」。  
> 只改像素、只盖 `data-weds`、只堆 `figure-stack--solo` / 通栏 `<img>` = **未完工**。

#### A. 结构最低配（默认语 HTML · 缺一 FAIL）

| # | 必有 | 判定（源码） |
|---|------|----------------|
| 1 | 开篇 prose | ≥1 个 `.weline-detail-prose--lead`（或等价 lead `h3`+≥2 句 `p`） |
| 2 | **至少 1 个左右/图文对照楼层** | `.weline-detail-feature` 或 `.weline-detail-feature--poem-aside`（含 `__copy` + `__media`） |
| 3 | **至少 1 个双列图** | `.weline-detail-figure-row--pair`（或 `triptych`）；**仅当可用净实拍 unique ≥2**；若整品仅 1 张净图 → 须 `poem-aside`+`feature` 齐，pair 可豁免并在汇报注明 `pair=豁免(单图)` |
| 4 | 清单或规格语义块 | `.weline-detail-prose--checklist` **或** `.weline-detail-bento`（§5.3 卖点墙）**或** `.weline-detail-text--*`（尺码/信息 HTML，非 JPG） |
| 5 | 原型种类 | 可辨原型 **≥4**（lead / verse / feature|poem-aside / pair|triptych / checklist|spec / quiet…） |
| 6 | 反滑梯 | **禁止**连续 ≥3 个 `--solo`/`--fullbleed` 且中间无实质 prose（`p`≥2 句或 checklist） |

#### B. 店面可见验收（Browser · 禁缓存 · 缺一 FAIL）

在 **桌面宽 ≥640px** 打开 PDP 详情区（`.product-native-detail__description-body`）：

| # | 必须看见 | FAIL 现象 |
|---|----------|-----------|
| 1 | 文案块与图片**交错**出现，不是「只有图」 | 整段详情像相册：图、图、图… |
| 2 | 至少一处 **左右分栏**（诗侧栏或 feature：文|图并排） | 所有 feature/诗栏也变成上下叠，像没 CSS |
| 3 | 至少一处 **双列图**（pair 两图并排） | pair 也变成单列大图墙 |
| 4 | 清单/规格是 **文字/表格 HTML** | 仍是整张尺码 JPG；或几乎无字只有图 |
| 5 | **实拍人脸/头脚完整**（诗侧栏/feature 右图） | 限高 + `object-fit:cover` 横切脸、砍头砍脚 |

若 HTML class 齐全但 Browser **仍**呈「大图竖墙」→ **CSS/挂载 FAIL**：修 `product-info.phtml` 内联样式或确认描述落在 `description-body` 内；**禁止**声称排版已完成。  
改 CSS 后必须清 **`view/tpl/**/com_product-info.phtml`**（或 `template:clear` / 删模块 `view/tpl`）再禁缓存验收——**编译模板残留旧 `cover` 会继续裁脸**。

#### C. 与跳过 / `data-weds` 的关系（严重）

```
有 data-weds？
  ├─ 否 → 必须做详情（含本闸）
  └─ 是 → 跑 §5.2 A+B+D
        ├─ PASS → 才可跳过（除非用户强制）
        └─ FAIL → 自动强制重做 HTML 排版（无视标记）
写 data-weds 前：A+B+D 必须 PASS；否则禁止盖章。
```

口令：**有标记不够；看不见杂志排版 = 强制重做。**

#### D. 详情区展示 CSS 硬闸（严重 · 用户已验「诗侧栏横切脸」）

> 目标：限高可以，**裁切主体不行**。详情描述区（`.product-native-detail__description-body`）排版楼层的实拍 **禁止用 cover 切脸**。

| 规则 | 要求 | 禁止 |
|------|------|------|
| **feature / poem-aside 右图** | `object-fit: **contain**`；`overflow: **visible**`；可有 `max-height: min(70vh, 40rem)` 等比缩小 | `object-fit: cover` + 限高（横切脸/砍头脚）；`overflow: hidden` 藏裁切 |
| **figure-stack / pair / solo** | `width:auto` + `max-width:100%` + `height:auto`；限高时**整图等比缩小**；figure **`overflow: visible`** | `width:100%` + `max-height` + 父级 `overflow:hidden`（竖裁主体）；`max-height: none !important` 无限拉高竖墙；cover 塞满裁主体 |
| **开篇** | 优先 caption 栈 + 文案；**不要**开篇 fullbleed 巨图墙 | 首屏只有一张超高通栏图 |
| **图少时** | 诗侧栏用 hero；**优先凑 pair**（可 hero+下一张），再 feature；仅 1 张净图时可豁免 pair（须 poem+feature） | 图少时只堆 solo、漏 pair 且无豁免说明 |
| **改样式后** | 删/重建 `Product/view/tpl/**/com_product-info.phtml`，禁缓存重开 PDP | 只改源 `templates/` 却验旧编译稿 |
| **写库后** | 同一 `entity_id+locale` 若并存短「浏览…」与 `data-weds` 长 HTML → **删短留长** | 双行并存导致店面读到短桩 |

Browser 抽检：桌面宽打开诗侧栏，`getComputedStyle(img).objectFit === 'contain'`，人脸/伞面/裙摆应完整可见（可缩小，不可被框裁断）。

口令：**限高用缩小（contain），不用裁切（cover）。**

### 5.3 原生 HTML+CSS 杂志审美（严重 · 指南落地 · Weline 适配）

> 吸收外部「AI 驱动电商详情原生 HTML+CSS」指南的可执行部分：**结构化布局指令、高级感参数、三大组件、微调三法则**。  
> **冲突裁决**：本仓 **§5.2 / §5.2‑D**、**Theme Token**、古风品牌（`changan-hanfu-brand`）> 指南原文。指南里的 **Apple 系统字体 / 纯科技灰 `#f5f5f7` / Hero 用 `object-fit:cover` 裁产品脸** → **一律不照抄**。

#### A. 生成详情 HTML 的布局指令（Prompt / 落码硬规）

写或改详情描述 HTML/CSS 时，**禁止**让模型「自由发挥堆大图」。必须显式约束：

| 维度 | 必须 | 禁止 |
|------|------|------|
| **一维排版** | **Flexbox**：feature / poem-aside 横排、spec 行、清单行 | `float`；用一堆 `margin` 假横排 |
| **二维排版** | **Grid**：`pair`/`triptych`、可选卖点「便当盒」墙（**只用 `div`**；class 须匹配店面 `safeDetailTextClass`：`weline-detail-bento*` 已放行；**禁止 `<section>`**） | 滥用 `position:absolute` 把长文死叠在实拍脸上；详情 HTML 写 `<section>` 或未放行的 class（会被剥壳） |
| **文案载体** | 卖点/诗句/参数一律 **HTML 标签**（`h3`/`p`/`table`/竖排 prose） | 长文烤进 JPG；把指南 Hero「字压在图上」当成默认（侧栏/下方 HTML 优先） |
| **呼吸留白** | 大板块上下内边距约 **3–5rem**（宽屏可至 ~5–7.5rem）；优先 Theme 间距变量 | 楼层贴死、无 `quiet_spacer`、密密密同构 |
| **字阶 / 字距** | 见 **§五‑B**；卷名可 `letter-spacing: -0.02em`；正文行高 **1.65–1.8** | 蝇头字装饰感；通栏长河无 `max-width` |
| **色与字族** | **仅 Theme Token** / 店面已有 `.weline-detail-*` | 硬编码纯黑纯白、苹果灰、系统栈当「高级感」交差 |

口令：**Flex 管横排，Grid 管拼墙；字在 HTML，图只承载实拍。**

#### B. 三大黄金组件 ↔ 本仓原型（开箱映射）

| 指南组件 | 本仓落点（复用现有 class） | 相对指南的硬改 |
|----------|----------------------------|----------------|
| **1. Hero 落地大图** | `.weline-detail-prose--lead` + `stack_caption` / 偶发 `fullbleed`；情绪锤用 **段题+旁文** 而非烤字 | 开篇**不要**默认 80vh cover 巨图墙；实拍人脸/全身：**contain**（§5.2‑D），禁止 cover 切脸；文案在图外或诗侧栏，利于 SEO/多语 |
| **2. 便当盒卖点墙 (Bento)** | 有 **≥3 条有证据卖点** 时：用 **Grid 2×2 均布短卡**（可落在 checklist 前/后一屏）；首卡可用左边线轻强调 | **禁止**无证据刷空卡；**禁止**苹果风大黑框 `span 2×2` + `space-between` 把短文顶到四角（用户已验「黑框太空」）；禁止标题=正文重复写两遍；禁 `#f5f5f7` 硬底 |
| **3. 极简参数表 (Spec)** | `.weline-detail-text--*` / checklist / info 面板；数值可放大（`font-variant-numeric: tabular-nums`） | **禁止**粗框 Excel 风表图留 JPG（仍走 §3.2‑A）；行内 **`align-items: baseline`**（标签↔大数字基线齐） |

落地检查（结构闸之外的**审美加分硬建议**，有素材则尽量做）：

1. **Hero 屏**：lead 可读 + 一张净实拍（caption 栈优先）；不是首屏超高 cover。  
2. **卖点屏**：要么 checklist 实质条目，要么 1 屏 Grid 卖点墙（条目来自卖点表）。  
3. **参数屏**：尺码/面料/护理已是语义 HTML，数字醒目、基线齐。

#### C. 微调三法则（落码后必扫）

指南「微调黄金法则」→ 本仓强制：

| # | 法则 | 怎么验 | FAIL |
|---|------|--------|------|
| 1 | **对齐（Alignment）** | 参数行 / 大数字行：Flex 容器 `align-items: baseline` | 数字头顶与标签错位、像廉价表 |
| 2 | **文字安全（Text Safety）** | 背景/实拍归图；标题正文归 HTML；诗侧栏已抽诗删拼版（§3.2‑B） | 长文死写在图里；多语无法真译；拉伸变形 |
| 3 | **行宽限制（Max-Width）** | 长正文父级约 **600–700px** 或 **~50–60ch**（与 §五‑B 一致） | 宽屏正文拉成横断长河、阅读疲劳 |

#### D. 与 §5.2 / `data-weds` 的关系

- §5.2 A+B+D = **能否盖章**的硬闸（结构 + Browser + 不裁脸）。  
- §5.3 = **杂志质感**硬约束：无 Flex/Grid 意图、无文字安全、无行宽、Hero 用 cover 裁脸、Bento 空卡刷屏 → 即使 class 凑齐也视为 **审美未完工**，应在重做 HTML 时一并修，**禁止**「结构过了就交苹果风 cover Hero」。  
- 汇报建议加一行：`§5.3：Flex/Grid=是；文字安全=是；行宽=是；Hero/Bento/Spec=…`。

口令：**指南学结构，不学苹果皮；高级感来自留白·字阶·基线·HTML 文案，不是 cover 切脸。**

---

## 五‑B、字阶与图文比例（严重 · 反「小字配巨图」）

用户可见问题：**字太小 + 旁边/上面一张超高实拍 → 文栏空洞、留白难看**。技能必须主动避免。

### 字阶地板（详情区，相对 root 1rem≈16px）

| 角色 | 最小建议 | 说明 |
|------|----------|------|
| 卷名 / lead `h3` | **≥1.5rem** | 主声，明显大于段题 |
| 段题 / feature `h3` | **≥1.35rem** | 一眼可辨 |
| 小节 `h4` | **≥1.05rem** | 勿缩成标签字 |
| 正文 `p` | **≥1.05rem**（禁长期停在 0.9375rem 当主阅读） | 行高约 **1.65–1.8**（§5.3） |
| 注 / note | ≥0.875rem | 唯一允许明显弱一档 |

字号用 Theme 字族；卷名/大标题可轻微 `letter-spacing: -0.02em`；可调 `product-info` 内 `.weline-detail-*` 字阶，**不得**为省事维持「小字装饰感」。长 `p` 父级 **max-width ≈ 600–700px / 50–60ch**（§5.3‑C）。

### 图文比例（防空洞）

| 症状 | 判定 | 必做动作（择一或组合） |
|------|------|------------------------|
| 竖图很高，旁文只有两三行 | 文栏高度 ≪ 图列 | **改原型**：`feature_lr` → `stack_caption`（上图下文）或 `macro_annotate` |
| 图列视觉重量压倒文字 | 眯眼只见图不见字 | **抬字阶** + 加 1 段实质旁文（仍一句一事，勿注水） |
| 通栏巨图下挂一行蝇头小字 | 图文断开 | 图下标题≥段题地板；或给图加短 caption 条，字阶够读 |
| 双列净图过高 | 屏内空白带 | **裁为中景**或改 `stack`/`fullbleed` 通栏；**禁止**用 `object-fit:contain` + 灰/米色框底「塞满」——那会造假色框 |
| 文列 `max-width:65ch` 但字过小 | 行短且稀 | 先加大字号，再考虑略放宽至 ~50–60ch（仍忌通栏长河） |

### 3.6‑A 详情图容器：禁框底 / 禁描边（严重）

用户常见败因：图比例不合容器时，CSS 给 `figure` / `feature__media` 垫 **灰/米色实心底** + **1px 描边**，看起来像廉价相框，比「露一点页底」更丑。

| 禁止（负面） | 必做（正向） |
|--------------|--------------|
| `background: #fffefa` / `#ede9e1` / `--color-bg-secondary` 垫在商品图容器里 | **`background: transparent`** |
| `border: 1px solid …` 给详情商品图套框 | **无边框**（`border: none`）；圆角可 0 |
| `object-fit: contain` + **固定高框 + 异色垫底** 造假色框 | 竖图通栏 `width:100%` + `height:auto`；限高时 **contain 缩全图**（允许透明/同页底，**禁止 cover 裁主体**） |
| 图内残留货盘青绿底/金线弧边（烤字板框） | **裁掉板边**后入图；勿靠 CSS 框「包住」掩饰 |
| **详情 feature/诗侧栏 `cover`+限高横切脸** | 改 `contain` + `overflow:visible`；清 `view/tpl` 再验（§5.2‑D） |

验收：详情任意商品图外缘 **无描边相框、无灰米色垫底块**；不合画幅时宁可透出页面底，也不造第二层框色。

**硬禁**：宽屏 `feature_lr` 里放全身竖拍 + 旁栏仅标题+一句小字。此类组合一律改 `stack_caption` / 裁中景 / 加厚文档层级。

### 验收（Browser）

1. 详情正文目测可读，不需捏屏放大。  
2. 任一图文对照屏：文栏不呈「一条细字贴在巨图腰间」。  
3. 计算或目测：对照屏文栏占位高度 roughly ≥ 媒体可视高度的 ~40%，否则改原型或抬字/限图高。

---

## 六、多语言（严重 · 默认站**全部**启用语 · **字段级**真译 · 不可跳过）

**已要求，且是收口硬闸。** 详情优化**不是**「排版好看即可」；**默认站每一个启用 locale 都必须有一份字段完整的真译详情**，否则**未完工**，**禁止**写 `data-weds`。

用户已验败因（#302 `/bn_BD/product/…`）：段题是孟加拉语，正文仍是 `Full and mid shots…` —— **只译标题 = 没做翻译**。

### 6.0 字段完整真译（严重 · 正负向）

详情文案包（或等价 HTML）对每个非源 locale **必须逐字段真译**，不得 `$locale = $en` / `$locale = $zh` 后只改几个 `*_title`。

| 字段族 | 必须目标语（示例 key） | 负向（禁止残留） |
|--------|------------------------|------------------|
| 导语 | `intro_title`（专名可保留）· **`intro_body`** | 中文制式/部件渗入；英文 cut/parts |
| 心源 | `inspire_title` · **`inspire_lines[]`** · **`inspire_note`** | `Design wellspring` / `Named for` / `Cut and air` |
| 着装/细部 | `look_title` · **`look_body`** · `macro_title` · **`macro_body`** | **`Full and mid shots`** · **`Near views show`**（用户已验） |
| 清单/护理 | `checklist_title` · **`checklist[]`** · `wash_title` · **`wash_lines[]`** | `Worth noting` · `Trust the photos` · `Hand wash separately` |
| 信息面板 | `info_title` · `info_basics` · `info_comfort` · **全部 `label_*` / `info_*`** · **`c_*` 刻度** | `At a glance` · `As shown` · `See variant axis` · `Selected fabric` · 英文 Medium/Slim |
| 尺码/原创/收束 | `size_title` · **`size_body`** · `original_*` · `quiet_line` · `close_caption` · `alt_*` | `Size guide` · `Original craft` · 英文 alt `· set` |
| 制式/部件词 | `info_style` · `info_parts` · checklist 内制式部件 | 非中文 locale 残留「唐制」「上衣与裙装」「诃子、大袖」（须本地化词典或等价） |

**专名例外**：品名短标题（如「洛青玄」「黑山茶」）可在各语保留中文专名；**不得**借此把 `intro_body` / `look_body` / `macro_body` 留英或留中。

**正向样例**：`/bn_BD/product/…` 详情可见 `ডিজাইনের উৎস` **且** `পুরো ও মাঝারি শট…`（正文也是孟加拉语）。  
**负向样例**：同页 `ডিজাইনের উৎস` + 英文 `Full and mid shots stack…` → **FAIL**，禁 `data-weds`。

### 6.1 范围

| 项 | 要求 |
|----|------|
| **语种列表** | **仅**默认站 `Website::getLanguageCodes()`（含 `''`）——**逐个**写入；**不是**全库上百语，也**不是**「只做中英」 |
| **覆盖面** | §6.0 字段表全覆盖；数字/S·M·L/cm 可保留 |
| **写法** | Agent **本回合直接真译落盘**（自模型译写）；**禁止**为交差启动 Ollama（除非用户本回合明示） |
| **脚本** | 批量包若 `$xx = $en`，**必须**覆盖 §6.0 全部正文/面板字段后再组装；落盘前 fail：正文英包标记 + 中文段题渗漏 + 制式中文渗漏 |
| **验收证据** | ① 列出启用语全表；② **每个** locale 读库抽 `intro_body`+`look_body` 为目标语；③ **至少 1 个非中英启用语**用店面 **`/{locale}/product/{slug}…`** 禁缓存打开，确认详情正文非英非中（专名除外） |

### 6.2 正向步骤

1. 打印 `codes = Website::getLanguageCodes()`（+`''`）。  
2. 源语定稿后，为**每一个**启用 locale 写**完整**文案包（§6.0），再组装 HTML 写入 description。  
3. 尺码/规格 HTML（§3.2‑A）表头/脚注同步各启用语。  
4. **闸**：非 `en_US` HTML 不得含正文英包（含 `Full and mid shots` / `Near views show` / `Design wellspring` / `Worth noting` / `At a glance` / `Close looking` / `Size guide` / `As shown` / `Trust the photos` 等）；非中文不得含「设计心源」「衣袂可记」「通身气韵」「上衣与裙装」等。  
5. **店面抽检**：`https://{host}/{locale}/product/{slug}?…`（例 `…/bn_BD/product/…`），Browser `setCacheDisabled`，读详情区正文。  
6. 全过闸才允许 `data-weds=xq`。

### 6.3 负向（禁止 · 用户已验）

| 禁止 | 为何算没做翻译 |
|------|----------------|
| **只写中英**，其它启用语空/缺/旧文 | 启用语未全覆盖 |
| **整页英包**或 **`$xx=$en` 只改 `*_title`** | 段题译了、`look_body`/`macro_body` 仍英 → 店面像没翻译（#302 bn_BD） |
| **制式/部件中文硬塞**进外语 intro/checklist（`唐制 cut: 上衣与裙装`） | 半吊子翻译 |
| 其它语种整页简体 / 繁体启用贴简体 | 未译 / 简体渗繁 |
| 「版式先交、翻译后补」仍盖 `data-weds` | 绕闸 |
| 只读 DB、**不打开 `/{locale}/product/`** 就宣称多语完成 | 验收无效 |
| 扩到未启用上百语 | 范围错误 |

### 6.4 硬规则表

| 硬规则 | 说明 |
|--------|------|
| **启用语全量写入** | `getLanguageCodes()` 每一个 locale（+`''`）各一份 |
| **字段级真译** | §6.0 表；禁止标题译、正文不译 |
| **禁 EN dump** | 非 `en_US`：段题+正文+面板+尺码+护理均不得英包 |
| **禁 ZH 渗漏** | 非中文：不得大段简体段题/制式部件句（专名可留） |
| **RTL** | `ar_SA`/`ur_PK` 等目标语全文 |
| **收口闸** | 任一启用语缺写 / 字段漏译 / EN·ZH 渗漏 → **禁止 `data-weds`**；脚本须 fail |
| **强制重做** | 用户说「翻译没做 / 其它语言仍是中文或英文」→ 即使已有标记也必须按 §6 全量补译并用 `/{locale}/product/` 复验 |

口令：**翻译 = 启用语字段级真译 + `/{locale}/product/` 店面可见；只译段题留英文正文 = 未完工。**

## 七、标准工作流（Weline 默认）

```
1. 分流；禁抠图；禁 blur-fill；禁色垫假拓；**锁目录 target_ar（方 canvas/方卡→1:1；非裁后细长比）**
2. 裁/剥框 → **真·生图 AI outpaint 装进画幅**（`GenerateImage`+参考图+目标比）→ mid-lap 保清
3. 禁 cover 裁窄；禁随便加背景；禁对 sharp 猛去噪；禁空放大；无真横勿硬凑横槽
4. 按图选槽；抹三方；卖点表；**§六字段级真译（缺一/只译标题禁 data-weds）**；**版式 SOP §五 + §5.2 硬闸（结构+Browser；大图竖墙=FAIL）+ §5.3（Flex/Grid·文字安全·行宽）**
5. **全量类审**（§3.5‑A）：blur-fill/色垫假拓/抠图/细长条/方图被裁窄/拼版/**信息烤图** → 批量修 → 复扫 0 BAD
6. 质检：… · **尺码表仍是图** · **启用语漏译 / 只译段题正文英包 / EN·ZH 渗漏**
7. **`/{locale}/product/` 禁缓存抽检** → 交付 → 关 Browser
```
图处理逐步与问题表 → `companions/weline-image-pipeline.md`。

有图像 API 且用户要分屏 JPG 长图 → 插入 DetailFlow 生图闸门；否则语义 HTML。
**尺码/规格/信息烤图永远走语义 HTML（§3.2‑A）；表头脚注进全部启用语真译（§六）。**
**多语验收不得只读 DB：必须打开 `/{locale}/product/…` 看详情正文。**

---

## 八、依赖

| 能力 | 工具 | 无则 |
|------|------|------|
| 高清 | remediate HD / lanczos / Real-ESRGAN | 禁止交糊图 |
| 裁后对齐 AR | **真·生图 AI outpaint**（`GenerateImage`+参考图+目标 `aspect_ratio`）装进画幅 | cover 裁窄；异色灰砖 / blur-fill / 反射糊边假拓；细长条 |
| 文案/楼层 | vendored 同伴 | 可用 |

---

## 九、反模式

- **色垫补背景 / 黑棚续黑 / 场景连续色对称补边**（用户已验：不是生图）  
- **blur-fill：中间小清晰图 + 两侧大糊**（主体缩到看不清）  
- 抠图 / rembg / 假宣纸贴纸  
- 纯色灰条假扩；**裁白边不齐比例 → 细长条主图**  
- **纯竖叠相册详情**（无旁文节奏）  
- **千篇一律大图到底**（通栏 solo/fullbleed 滑梯；批量同构模板当终态）  
- **「全是一张大图从上到下」仍盖 `data-weds`**（§5.2 FAIL：无 feature/poem-aside 左右栏、无 pair、Browser 看不见杂志排版）  
- **只认 `data-weds` 跳过、不跑 §5.2 结构+Browser 闸**  
- **详情区 `object-fit:cover` + 限高裁切人脸/头脚**（§5.2‑D；用户已验诗侧栏横切）  
- **照抄外部指南：Hero cover 切脸 / Apple 灰硬编码 / 系统字体栈 / 长文 absolute 压在实拍上**（§5.3：只学 Flex·Grid·留白·基线·行宽）  
- **无证据 Bento 空卡墙、宽屏正文无 max-width 拉成长河**（§5.3）  
- **改 `product-info.phtml` 却不清 `view/tpl` 编译稿，仍验旧 CSS**  
- **糊图未处理仍盖 `data-weds`**（低码率 soft / soft_blur 未 ESRGAN 或换清原图）  
- 左右刷屏；同棚三联；小字巨图  
- 无证据编造；**非英语英文兜底 / 英包映射非英语 locale**  
- **详情只写中英、其它语种 EN dump 或简体渗繁**（§六）  
- **`$locale=$en` 只改 `*_title`，`look_body`/`macro_body` 仍英**（§6.0；用户已验 bn_BD）  
- **任一默认站启用语缺真译 / 字段漏译 / 尺码表头未译仍盖 `data-weds`**（§六）  
- **只读 DB 宣称多语完成、不打开 `/{locale}/product/`**（§6.1）  
- **把货盘拼版 / 白缝切脸 / 细条残图当成「美学设计」**（§3.5‑B：一律负面，禁止）  
- **诗句竖排侧栏 / 文图拼版烤字仍以 JPG 留在详情**（§3.2‑B：抽诗→HTML 竖排 + 净实拍）  
- **尺码表 / 规格参数 / 产品信息 / 护理表仍以 JPG 留在详情**（§3.2‑A：必须删图→语义 HTML）  
- 对信息烤图做升清/outpaint 冒充「已优化」  
- OCR 乱码表原样入库  

---

## 十、口令

**主图/规格：剥框后用 AI 拓边装进目录 AR（方图画幅要装下人）；禁裁人凑方、禁随便加背景。**  
**详情：杂志卷轴非相册滑梯；§5.2 硬闸（结构 + Browser + **§5.2‑D 禁 cover 裁脸**）+ **§5.3 原生 Flex/Grid·文字安全·行宽·Hero/Bento/Spec 映射**；禁止纯竖叠与千篇一律大图到底；禁抠图与糊边；清三方；卖点表。**  
**多语：启用语字段级真译（段题+正文+清单+info+护理+尺码）；`/{locale}/product/` 店面可见；只译标题留英文正文 = 未完工。**  
**尺码·规格·信息烤图：审图抽数 → 删图 → `weline-detail-text--*` HTML；表头脚注同步全部启用语。**  
**诗侧栏文图拼版：抽诗删拼版 → HTML 竖排 + 净实拍 `poem-aside`；禁当杂志美学；右图 **contain 不裁切**。**  
**糊图未处理、相册模板未拆开、启用语漏译/标题译正文不译、信息烤图未转 HTML、诗侧栏残留、§5.2/5.2‑D FAIL = 不得写 `data-weds`；有标记不够。**
