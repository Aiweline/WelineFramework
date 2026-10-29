# Weline 产品图处理管线（主图 / 规格图 / 详情图）

**本文件 = 图处理专规（分步 SOP）。**  
- **父入口**：`dev/ai-command/product/产品优化.md`（父必须并行开 3 子智能体；本文件 = 槽 ① 像素权威）  
- **槽 ① 技能**：`ecommerce-product-image/SKILL.md`  
- **子入口**：`dev/ai-command/product/详情优化.md`（详情内实拍可复用本管线）  
- **翻译入口**：`dev/ai-command/product/翻译优化.md`（不做像素）  
- 详情排版/卖点：子套件 `ecommerce-detail-suite/SKILL.md`  
冲突时：本文件管「像素怎么改」；详情正文以详情套件为准；多语检测以翻译分支为准。

---

## 0. 一句话口令

**含人模特图（默认 · 已验正确做法）**：**衣锁死本品**（1688/原实拍作 `reference_image_paths`）→ AI **只换人与背景** → 落盘后 **必须改 `object_key` 新文件名破图缓存**（`method=ai_full_model`）。  
**无人静物 / 规格净衣 / 信息烤图**：仍走「裁缺陷 → 真 outpaint 装 `target_ar`」或 textify。  
禁止：改衣换款；只改字节不改 URL（`immutable` 图 HIT 假绿）；漏换 **variant**；扒图换脸洗权；cover/色垫/空放大。

### 0‑A 顾问决策门（严重 · 含人图）

主图/画廊/**规格** /详情**含可辨人脸或全身模特着装**时，须有电商顾问决策（父「产品优化」默认可记本回合用户拍板；单独跑图槽时父/顾问须显式写）：

| 字段 | 默认（本站运营已定） | 说明 |
|------|----------------------|------|
| `ai_full_model` | **`approved`** | 含人图一律 AI 换**人+景** |
| `clothing_lock` | **硬锁本品** | 形制/色/绣/层与原图或 `source_url`（如 1688 offer）一致；**改衣 = FAIL** |
| `reference_image_paths` | **必须**本品原图（可含人；衣为证据） | prompt 写明 keep garment identical；只许换虚构模特+新场景 |
| `surfaces` | main+gallery+**variant**+详情含人 | 店面选规格常默认吃 variant |
| `url_cache_bust` | **必须** | 新媒体 `object_key`（如 `…/ai-lock-{sha16}.webp`）+ 升 `asset_revision` + 重算 `object_identity_hash`；仅 `replaceContent` 同路径不够 |
| `disclose` | 文案勿写「真人实拍/模特试穿」冒充 | 可标「效果示意」；图货须一致 |
| `keep` | `info_chart` / 尺码烤图 / 纯静物特写 | 烤图 textify；静物可 outpaint，**不**强行换 AI 模特 |

汇报必写：`advisor：ai_full_model=approved|denied`；`clothing_lock=pass`；`url_bust=新 object_key`；`method=ai_full_model|outpaint|textify`。

### 0‑B 少走弯路（严重 · 已验最短路径 · 禁止考古）

**禁止**：`SHOW TABLES` / 扫全库 EAV / 猜 `attribute_code` / 翻 ObjectManager / 为「找图」读半套 Schema；禁止只清 FPC 以为图也会刷新。

**默认站（长安汉服 website_id=0）直接用下面，勿另探：**

| 要什么 | 怎么拿（停） |
|--------|----------------|
| 图清单 | `w_product_ws_0_media`：`product_id` → `role,position,asset_id`（必含 **main/gallery/variant**） |
| 磁盘路径 | `w_weline_file_asset`：`asset_id` → `object_key`；文件 = `pub/media/{object_key}`；`disk_code` 通常 `local::filesystem::media` |
| 1688/货源 | 仅当要对衣：`w_product_ws_0_attribute_value` 且 `attribute_code='source_url'`（可选 `name`+`locale='zh_Hans_CN'`）；**不必**扫其它属性 |
| 店面谁在主舞台 | Browser/CDP：大图 `currentSrc`——**选规格后常是 variant**，不是 main |
| 生图 | `GenerateImage` + `reference_image_paths`=**备份后的原图**；prompt：衣不变、只换人+景 |
| 落盘破缓存 | 新文件 `…/ai-lock-{sha256前16}.webp` → UPDATE `object_key` + `object_identity_hash=sha256(disk+"\0"+key)` + `sha256/bytes/w/h` + `asset_revision+=1`（**asset_id 不变**） |
| 验收页 | `?_wb_nc={unix}&w_fpc_pierce={secret}`；secret=`FpcPierceStamp::ensureSecret()` 或 `generated/framework/fpc_pierce_secret`。**单 `_wb_nc` 不够**。HTML 须含 `ai-lock-` |

口令：**两表定位图 → 原图 reference 生图 → 新文件名 → pierce 验收。别考古。**

---

## 1. 表面分流

| 表面 | Media role | 画幅目标 | 清晰度 | 审美 |
|------|------------|----------|--------|------|
| **主图** | `main`+`gallery` | 锁 **目录 target_ar**（见 §2；店面卡常 **1:1**） | 中区 mid-lap 优先 | **含人→§1‑M 全 AI**；无人→去缺陷+真拓图+保清 |
| **规格** | `variant` | **同主图规则**（仅槽位像素可更小） | 同主图 | **含人必须 §1‑M（店面选规格常默认吃 variant；只换 main/gallery 买家仍见旧模特）**；净衣/色块同 outpaint |
| **详情·模特** | 正文插图（含人） | 不强制主图 AR；竖图常用 3:4 | 同清晰规则 | **§1‑M 全 AI**；跟楼层原型 |
| **详情·静物** | 面料/绣线/平铺 | 不强制主图 AR | 同清晰规则 | outpaint/HD；可不换模特 |
| **详情·信息烤图** | 尺码表/规格参数/产品信息/护理表 JPG；**购前须知长字板**（面料/对花/洗涤/绣花/静电/褶子+退货红字） | **不走像素管线** | N/A | **删图 → 语义 HTML**（根套件 §3.2‑A；须知板用 `buildBuyerNoticePanel`） |

**分流硬闸**：白底表头、行列数字为主的「尺码/规格/信息」图，或灰绿「面料/对花/洗涤…」告知长字板 → `kind=info_chart`（子类可 `buyer_notice_board`），**禁止** Step E outpaint / HD / `ai_full_model`；按根套件 §3.2‑A `textify`。

### 1‑M 全 AI 模特换图（`method=ai_full_model` · 严重）

**何时**：图中有可辨人脸、半身/全身着装模特（含画廊、主图、详情模特楼层）。顾问 `ai_full_model=approved`（默认）。

```
Step M1  读本商品多张原图 + `source_url`（1688 等）：锁死服装——形制、主色/渐变、绣纹位置与密度、层次、面料观感
Step M2  写生图 prompt 硬锁：KEEP EXACT SAME GARMENT as product reference；ONLY change：虚构新模特脸/身份 + 全新场景/光影；禁点名真人；禁 redesign dress
Step M3  Cursor GenerateImage：aspect_ratio=目标；**必须** `reference_image_paths`=本品原图；输出 WebP
Step M4  目检（FAIL 即重做）：与原图/1688 并排比——色相/绣纹/袖型/裙片是否同一款；人与景可变；无水印 UI
Step M5  备份原文件；写入新像素；**main+gallery+variant+详情含人**全覆盖
Step M6  【严重 · 图 URL 破缓存】店面 `/pub/media/…` 常带 `Cache-Control: immutable, max-age=31536000`。
         **禁止**只覆盖同路径还声称买家能看到新图。
         正确：把文件落到**新 object_key**（推荐 `…/ai-lock-{sha256前16}.webp`）→
         更新 `w_weline_file_asset`：`object_key`、`object_identity_hash=sha256(disk_code+"\0"+object_key)`、
         `sha256/bytes/width/height`、`asset_revision+=1`（asset_id 可不变，产品仍绑 asset://UUID）→
         清 FPC / 带 pierce 打开页 → HTML 须出现新文件名；再 Browser 硬刷验收
```

| 正向 | 禁止 |
|------|------|
| 参考本品原图锁衣 + 只换人/景 | 无参考瞎文生 / **换款衣服**（不成同一 SKU） |
| 服装与 1688/原实拍交叉核对 | 凭空改色/改绣/改形制；「好看一点」改衣 |
| 同品多张：人姿态/景可变，**衣锁死同一套** | 每张衣服长成另一套；只换 main 不换 **variant** |
| **新 object_key** 破 immutable 图缓存 | 同路径覆盖后让用户硬刷却仍 HIT 旧图；只清 FPC 不清图 URL |
| 详情含人同锁衣 + URL bust | 对 info_chart 做 AI 模特 |

口令：**衣锁死；人景可变；改衣失败；同路径覆盖不够——必须新文件名。**

---

## 2. 锁定 target_ar（第一步 · 严重）

在动任何像素前先写下 `target_ar`：

| 优先级 | 取什么 |
|--------|--------|
| 1 | **缺陷前**规范主图 canvas。拼版外框剥前若整张为 **1200² / 800²** → **target_ar=1.0（正方形）** |
| 2 | 店面商品卡 / 主图舞台若为 **1:1 方框** → 主图/规格锁 **1:1**（用户已验：方图被裁窄=败） |
| 3 | 否则品类默认：汉服全身常见 ~3:4（0.75–0.78） |
| 4 | 现网 main **无缺陷**时的 AR |

### 正向
- 全套 main/gallery/variant 对齐同一 `target_ar`
- 汇报写清：`target_ar=1.0（原图/卡位正方形）` 或 `target_ar=0.75（…）`

### 负面（禁止）
- 用「裁白边后内容外接矩形」当新 target（常变成 ~0.56 细长条）
- **把本该正方形的主图/规格 cover 裁成 3:4 窄竖图**
- 主图一套、规格又一套比例

---

## 3. 分步 SOP（主图 / 规格 · 严格按序）

```
Step A  取源：优先 CDN/更大原图；记下源 mid-lap 与缺陷前 canvas AR
Step B  类审：白柱/灰垫/blur-fill/噪点/软糊（上下=左右都扫）
Step C  裁缺陷：只裁死白/异色垫/拼版外框；过缝再内收 ~1%；保留棚内黑底与全身
Step D  测中区锐度：mid-lap（主体 60% 框）
Step E  装进 target_ar：【必做】真·生图 AI outpaint（见下）；主体完整不裁窄
Step F  定级 source_grade → 去噪策略
Step G  升清：sharp 可轻度；soft 禁空放大
Step H  replaceContent；复扫至 0 BAD（含窄竖、糊、假垫边）
```

### Step C 裁缺陷（含拼版外框）

| 现象 | 动作 |
|------|------|
| 左右/上下死白柱、匀色灰条 | 裁到内容外接框 |
| **拼版外框**：灰/米色框 + 白垫 + 中间实拍（bak 常见 1200²） | **先剥外框/白垫**，只留中间实拍区 |
| 两侧大块空黑柱（letterbox）且内侧才是棚拍 | 按「平坦近黑列」剥柱，保留棚内黑底 |
| blur-fill（中间清、两侧大糊） | 裁清晰中区 |
| 棚景浅色但有纹理/纵深 | **保留**（不是垫边） |

判定垫边列：`mean≈白且 var 低` **或** `灰框 mean∈[160,215] 且 var 低` **或** `近黑 mean≤28 且 var 低`。取**最长非垫边连续列**为实拍。

### Step E 装进画幅 = 真·生图 AI 拓图（用户已验 · 严重）

剥框后内容常变窄。**目标若是正方形（或更宽画幅），必须用 AI outpaint 把「整段实拍」装进画幅**，不是裁掉头脚/左右让比例「看起来对」。

#### 正向（唯一合法补方/补宽）

1. 剥框后得到完整实拍（人衣头脚尽量全）  
2. 调用 **真生图 outpaint**：
   - Cursor `GenerateImage`
   - `aspect_ratio` = 目标比（方图用 `1:1`）
   - `reference_image_paths` = 剥框后的实拍路径
   - prompt：**只外延背景/棚景/地面/光影**；主体姿态、衣色、镜头、构图中心不变；无文字水印 UI  
3. 输出边长建议 ≥1200（方图 1200×1200）  
4. 验收：AR≈target；**主体未被裁窄**；外延区与原棚连续（非色块）

#### 非法（负面 · 一律禁止）

| 禁止 | 用户可见败因 |
|------|----------------|
| **cover 裁切**把瘦图裁成 3:4/方图 | 头脚被切、主图变窄条 |
| 纯色 / 宣纸灰 / 死白砖 / **黑棚续黑色垫** | 一眼假背景 |
| OpenCV 反射边 + 模糊融合冒充 outpaint | 拖影糊边，不是生图 |
| blur-fill 糊圈凑方 | 中间邮票主体 |
| 无参考图纯文生一张「像」的方图 **且**未走 §1‑M（含人应全换却假装 outpaint） | 换脸换衣/假拓图，丢证据 |
| 对含人图做「换脸」或原图垫图 img2img 冒充合规 | 著作权仍在原图 |

**例外（严重）**：含人且顾问 `ai_full_model=approved` 时，**必须**走 §1‑M 纯文生全换图，**不要**对本条「禁纯文生」做 outpaint 交差。无人静物图仍禁无依据瞎文生换货。

#### 无生图能力时

如实汇报阻断；**不得**用裁切/色垫交差。可暂保留剥框竖图 + 竖排详情，但主图/规格方卡位未满足不得声称「已优化比例」。含人图无生图能力 → **阻断**，不得声称已去侵权模特图。

#### 口令

**含人：全 AI 新生；无人方卡：AI 拓边装人衣静物。禁止裁人凑方，禁止随便加背景，禁止换脸洗图。**

### Step D/F 清晰度（用户已验痛点：糊）

**用中区 mid-lap 定级，不用整图 lap。**

| mid-lap（经验） | grade | 允许 |
|-----------------|-------|------|
| ≥220 | `sharp_ok` | 可轻度升清到短边≥1200；先极轻去噪或不去噪，再极轻锐化 |
| 120–220 | `softish` | **禁止猛去噪**；最多 mild 升清 ≤1.25× + 轻锐化 |
| <120 | `soft_blur` | 优先换更大更清原图；否则轻 hqdn + **Real-ESRGAN**；无 ESRGAN → 按 §3「升清实做」2× LANCZOS+USM 补展示像素；>2× 禁止 |

**附加 soft 闸（与 mid-lap 并联，任一命中即 soft）**：

| 信号 | 判定 | 动作 |
|------|------|------|
| **低码率** | `bytes*8/(w*h) < 1.2` 且短边≥900 | 视为 soft_blur（mid-lap 虚高也算） |
| **空放大痕迹** | 本地边长 ≫ CDN 源边长且 bpp 更低 | 回退源图再 ESRGAN；禁继续 lanczos |
| **目测绣线糊** | 眯眼不可读 | soft_blur，禁止写 weds |

#### 正向
- 源图 mid 已锐 → **跳过强去噪**（hqdn/median 会把绣线打糊）
- 黑棚区整图 lap 低是正常的 → **仍看 mid-lap**
- soft → **Real-ESRGAN**（本仓可下 `var/tools/realesrgan`）后再轻度；无 ESRGAN → 按 §3「升清实做」2× LANCZOS+USM（源宽低于展示像素时必需）

#### 负面
- 对 sharp 图狂 `hqdn3d` / median → 交付发糊
- 源已糊仍 lanczos 拉到远超 2× → 更大的糊图（2× 补偿见下）
- 未去噪就狂 unsharp → 噪点爆炸
- **批量脚本跳过 soft 定级仍盖 `data-weds`** → 用户可见「图糊了没处理」

#### 源图本身糊 → 升清实做（工具 + 验收 · 严重）

**判定先算展示像素，不凭感觉**：图片按 CSS 宽渲染，视网膜需 `CSS宽 × 2` 设备像素。
源图宽 `< CSS宽 × 1.5` → 页面必糊（浏览器 bilinear 插值被放大）；`< CSS宽 × 2` → 视网膜软。
典型：详情图源 790px、页面 CSS 拉到 1000–1120px → 必糊。

| 步 | 动作 | 命令 / 参数 |
|----|------|-------------|
| 1 | 先试更大源 | 同平台换尺寸后缀；实测 1688/alicdn `_1200x1200Q90` 仍返 800、`_1500x1500Q90` 404 = 无更大源，进第 2 步 |
| 2 | Real-ESRGAN（装了就优先） | `realesrgan-ncnn-vulkan -i in -o out -n realesrgan-x4plus -s 2`（本仓 `var/tools/realesrgan`） |
| 3 | 无 ESRGAN → **2× LANCZOS + USM**（已验，允许） | Pillow `resize(2×, LANCZOS)` → `UnsharpMask(radius=1.1, percent=55, threshold=3)` |
| 4 | 编码 | 照片 `cwebp -q 90 -m 6`；JPEG 源 `quality=90, subsampling=0, progressive`；**`pngquant` 只用于纯色/文字板**（照片必出 256 色抖动噪点） |
| 5 | 体积闸 | 见 §6.5；超预算先把 q 降到 85，禁压到糊字 |
| 6 | 换图即换 URL | 新 object_key（如 `…-detext.webp`）+ 升 revision；`/pub/media` `immutable` 一年，同路径覆盖买家仍见旧图 |

**「空放大」边界（收紧）**——只有这些算违规：
- 源图已 ≥ 展示 2× 仍继续放大；
- 放大后仍 `< 展示 2×`（白拉大，没补足）；
- 只改字节不改 object_key / URL（`immutable` 假绿）。

**源图本身低于展示像素时，2× LANCZOS+USM 是必需补偿，不算空放大**；超过 2× 无真实细节，禁止。

**验收**：中区绣线 / 英文小字眯眼可读；`src W×H → out W×H` + 工具参数写进汇报；交付 URL 直连 `200` + `Content-Type: image/webp`；页面 DOM `naturalWidth = out 宽`。
---

## 4. 问题 → 解法对照表（严重）

| 遇到的问题 | 怎么判断 | 怎么解决 | 禁止再做 |
|------------|----------|----------|----------|
| 主图/规格本该方却变窄 | 原 canvas/卡位 1:1，成图 AR≪0.9 | 剥框后 **AI outpaint→1:1** | cover 裁成 3:4 窄竖 |
| 主图细长像书签 | AR≪0.72 或目测瘦高 | 查 target；方图→outpaint；竖目录→真拓或汇报 | 把裁后比当目标 |
| 拼版白框/灰框还在 | 外缘灰条+白垫+中图 | Step C 剥外框，只留实拍 | 整张拼版当货架图 |
| 「补比例」后两边假背景 | 匀色条/反射糊边 | 回退；改真 outpaint | 色垫/反射融合交差 |
| **双层背景未拓开 / 框中框** | 左右竖缝；中区细纹理、两侧糊或色差；后景层在中框截断 | 剥中区 → 真 outpaint 让雾/后景连续铺满 | 硬扩白边/反射糊边凑宽 |
| 交付发糊 | mid-lap≪源 mid | 回退强去噪；禁空放大 | 加重 hqdn |
| 需要更大更清 | 短边小、mid 软 | CDN 更大原图 / Real-ESRGAN | 空放大 |
| HTML 写死 800×800 | 属性与像素不符 | 跟真实 W×H | 假方框属性 |
| 详情像相册滑梯 | 连续 solo 叠图 | 回根套件 §五 | 只改文案 |
| 拼版白缝切脸 | 一图多宫格 | **整张丢弃**换净单帧 | 当杂志美学 |
| 诗侧栏/文图拼版烤字 | 左奶油竖栏竖排诗+右实拍；圆框底句 | **删拼版**；抽诗→HTML 竖排 + 净实拍（§3.2‑B） | 升清留拼版当美学 |
| **购前须知/护理告知长字板** | 「面料/对花/洗涤/绣花/静电/褶子」+退货红字；误作 poem-aside 右栏 | **删图** → `buildBuyerNoticePanel`（§3.2‑A） | 升清留板 / 当实拍 |
| **原创声明拼版** | 实拍+「原创声明/抄袭盗版」烤字；虚线框 | **删拼版**；`original_*` HTML；**裁净/修图**实拍（§3.2‑C） | 升清留中文烤字 |
| **设计灵感字板** | 宣纸底「设计灵感」±金花 + 短句 | **删图**；`inspire_*` HTML 真译（§3.2‑C） | 升清/生图再烤字 |

---

## 5. 高清路径（按序）

1. 审 mid-lap 定级  
2. 优先更大更清原图  
3. `sharp_ok`：可放大；默认不去噪 → 极轻锐化  
4. `soft_blur`：去噪 +（换原图或 ESRGAN）；无 ESRGAN → 按 §3「升清实做」2× LANCZOS+USM（不算空放大）；>2× 禁止  
5. 源图本身低于展示像素 → **必须**升清（§3 表 1→6 步），并换新 object_key  
6. 本仓：`replaceContent` / `remediate-product-image-hd.php`

---

## 6. 全量类审（完工前）

| 负面类 | 正向动作 |
|--------|----------|
| 方图被裁窄 | AI outpaint 回 1:1 |
| 细长条 | 按 target 真拓图 |
| 异色/死白垫 / 假反射边 | 回退 → 真 outpaint |
| blur-fill | 裁清晰区 + 真拓或竖排 |
| 发糊 | 回退去噪/换原图/真超分 |
| 拼版切脸/细条残图 | 丢弃换净帧 |
| 诗侧栏/文图拼版烤字 | 删拼版；抽诗→HTML；裁净实拍 |
| **原创声明 / 设计灵感烤字** | 删板 textify；拼版实拍裁净或修图去字；禁再烤中文 |

汇报：`target_ar=…；method=outpaint；空放大=否；假垫边=否；类审 N→0`。

---

## 6.5 交付格式与体积（所有网页图默认 WebP · 严重）

对齐 MCP 硬规则 **`web_images_webp_compressed`**（覆盖生图与普通上传/主题/CMS/博客图）：

| 默认 | 例外（才允许 PNG/JPG） |
|------|------------------------|
| 终态落盘 **`.webp`**（`pub/media` / 主题 assets / Banner·Hero·主图 / 博客配图） | 用户**明示**要 PNG/JPG |
| 生图工具若返回 PNG/JPEG → **转码 WebP 后再交付**（`cwebp -q 80` / Pillow / GD） | 确需非 WebP 透明通道的消费端（极少；WebP alpha 优先） |
| MediaManager AI 画图默认 `output_format=webp` | favicon / `.ico` / 平台硬性 PNG |
| **按展示尺寸派生**：重采样到实际显示尺寸（视网膜 ≤2×）；长边 内容/商品 ≤1600px、Hero ≤2560px；**源图低于展示像素 → 先按 §3「升清实做」补足再压**（禁止拿小图直上靠浏览器放大） | 原图直上页面 / 用浏览器缩放大图 / 源图糊还按原尺寸交付 |
| **体积预算**：内容/商品 ≤200KB、缩略图/卡片 ≤80KB、Hero ≤350KB、图标/Logo ≤30KB；编码后目检清晰度 | 大体积 PNG/JPG 当店面长期交付物；压到糊字/糊边 |

原图只作**源文件**保留，不进公开目录、不被页面直接引用。禁止把大体积 PNG/JPG 当作店面长期交付物「因为生成器默认是 PNG」。

---

## 7. 详情图

同清晰/禁令；**不强制**主图 AR。  
**源图本身糊（宽 < 页面 CSS 宽 ×1.5）→ §3「升清实做」补齐展示像素后再交付**（2× LANCZOS+USM 属补偿，非空放大）；`/pub/media` `immutable` → 必换新 object_key。  
**含人模特插图** → §1‑M `ai_full_model`（与画廊同一套服装描述锁定）。  
**静物/绣特写** → HD/outpaint。  
**信息烤图** → textify，禁止 AI 模特。  
排版节奏见根套件 §五（反纯竖叠）。无真横勿糊边凑横。

汇报加写：`含人换图：N 张 method=ai_full_model；静物：…；烤图 textify：…`。
