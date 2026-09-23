# channel — WO-BUILD-ASSET-01 完成（Hero P0 + 货架样张 · 实挂验收）

日期：2026-09-23  
席位：内容运营「主图优化 / 出图」（SKIP MCP）  
工单：`WO-BUILD-ASSET-01`  
权威规格：`sitewide-ops-acceptance-report.md` · HOME/ASSETS 图片规格表  
验收 Host：`https://p05113ef3.test.weline.com:9555/`  
旁注首页：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`

## 总评

| 字段 | 值 |
|------|-----|
| **asset_p0** | **done**（Hero 桌面 1920×900 ×3 帧 + 移动 4:5；已覆盖烘焙 Layout 首屏路径） |
| 货架样张 | **done**（4 张 1200×1200 + sample 别名；未批量换真实 SKU） |
| PDP 主图 | **queued**（本波未批量落盘） |
| Browser 审图 | **N/A 降级**（本回合以文件落盘 + HTTP 探活收口） |
| **notify_pm** | **true** |

**@项目经理：** Hero P0 已出图并**实挂**到首页烘焙媒体 `taoyuan-qingmeng` / `shenlong-yin` / `zuimeng-xifeng`（及主题默认 `peach-garden-scene.webp`）。请派测试禁缓存 Browser 气质审图；货架/PDP 全量换图另开 `WO-BUILD-ASSET-02`。

---

## 规格对照表

| 用途 | 规格要求 | 本波交付 | 实测尺寸 | 气质自检 |
|------|----------|----------|----------|----------|
| 首页 Hero Banner（桌面） | 1920×800～1080；水墨汉服；禁框中框/脏边/西式 stock/纯抽象渐变 | **PASS** ×3 帧 | **1920×900** | 宣纸留白 + 汉服主体；图内无字/无 Logo；无框中框 |
| Hero 移动安全区 | 中心 4:5 | **PASS** ×3 | **1080×1350** | 中心裁切 |
| 货架主图样张 | 1:1 **1200×1200** | **PASS** ×4（+ sample=01 别名） | **1200×1200** | 浅宣纸底、整件可见、无嵌套白边 |
| PDP 主图 | 1:1 ≥1600 | **QUEUED** | — | — |

---

## 落盘路径（Channel 源资产包）

目录：`websites/changanhanfu.com/assets-storefront-build/`（本机网站柜，不进 Git）

### Hero

| 文件 | 尺寸 | 说明 |
|------|------|------|
| `changan-hanfu-hero-desktop-1920x900.{png,webp}` | 1920×900 | 桌面 Hero 主交付（→ 直播帧 1） |
| `changan-hanfu-hero-mobile-safe-4x5-1080x1350.{png,webp}` | 1080×1350 | 移动 4:5（→ 直播帧 1 mobile） |
| `changan-hanfu-hero-slide2-1920x900.{png,webp}` | 1920×900 | 轮播帧 2 |
| `changan-hanfu-hero-slide2-mobile-4x5-1080x1350.{png,webp}` | 1080×1350 | 帧 2 mobile |
| `changan-hanfu-hero-slide3-1920x900.{png,webp}` | 1920×900 | 轮播帧 3 |
| `changan-hanfu-hero-slide3-mobile-4x5-1080x1350.{png,webp}` | 1080×1350 | 帧 3 mobile |
| `changan-hanfu-hero-*-source*.png` | 1280×720 等 | 生图源（升采样前） |

### 货架样张

| 文件 | 尺寸 |
|------|------|
| `changan-hanfu-shelf-01-1200x1200.{png,webp}` | 1200×1200 |
| `changan-hanfu-shelf-02-1200x1200.{png,webp}` | 1200×1200 |
| `changan-hanfu-shelf-03-1200x1200.{png,webp}` | 1200×1200 |
| `changan-hanfu-shelf-04-1200x1200.{png,webp}` | 1200×1200 |
| `changan-hanfu-shelf-sample-1200x1200.{png,webp}` | 1200×1200（= shelf-01 别名） |

---

## 挂载点（本席已挂）

### A. 首页烘焙 Layout 实挂（验收可见）

首页 SSR 首屏 `hero-slider` 使用的是 **烘焙媒体**，非主题空 slides 默认：

| 直播路径 | 映射资产 | 实测 |
|----------|----------|------|
| `pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp` | Hero 桌面主帧 | **1920×900** |
| `pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng-mobile.webp` | 移动 4:5 | **1080×1350** |
| `pub/media/catalog/hanfu/r2/homepage/shenlong-yin.webp` | Hero 帧 2 | **1920×900** |
| `pub/media/catalog/hanfu/r2/homepage/shenlong-yin-mobile.webp` | 帧 2 mobile | **1080×1350** |
| `pub/media/catalog/hanfu/r2/homepage/zuimeng-xifeng.webp` | Hero 帧 3 | **1920×900** |
| `pub/media/catalog/hanfu/r2/homepage/zuimeng-xifeng-mobile.webp` | 帧 3 mobile | **1080×1350** |

HTML 仍声明 `width="1920" height="600"` / `--wc-hero-aspect:1920/600`（烘焙配置）。**像素已按运营规格升到 900 高**；主题/部件席可后续把 aspect 声明对齐 1920/900，非本席阻塞。

### B. 主题默认首帧（空 slides 种子路径）

| 路径 | 尺寸 |
|------|------|
| `app/design/Weline/hanfu/frontend/assets/images/homepage/peach-garden-scene.webp` | 1920×900 |
| `pub/static/Weline/hanfu/Weline/Theme/view/theme/frontend/assets/images/homepage/peach-garden-scene.webp` | 1920×900 |
| `app/design/Weline/hanfu/frontend/widgets/banner/hero-slider/default.phtml` 默认首帧 `width/height` | **1920 / 900** |

### C. 命名媒体副本（运营图库）

| 路径 | 用途 |
|------|------|
| `pub/media/catalog/hanfu/r2/homepage/hero/changan-hanfu-hero-desktop-1920x900.webp` | 命名 Hero |
| `pub/media/catalog/hanfu/r2/homepage/hero/changan-hanfu-hero-mobile-safe-4x5-1080x1350.webp` | 命名 mobile |
| `pub/media/catalog/hanfu/r2/shelf-samples/changan-hanfu-shelf-0{1..4}-1200x1200.{png,webp}` | 货架样张 |
| `pub/media/catalog/hanfu/r2/shelf-samples/changan-hanfu-shelf-sample-1200x1200.{png,webp}` | 样张别名 |

### 备份

| 备份 | 内容 |
|------|------|
| `var/backup/hanfu-build-asset-01-20260923_020301/` | 旧 `peach-garden-scene.webp` |
| `var/backup/hanfu-build-asset-01-replace-20260923_020518/` | 替换前资产快照 |
| `var/backup/hanfu-build-asset-01-live-mount-20260923_020859/` | 直播帧替换前 `taoyuan-qingmeng` / `shenlong-yin` / `zuimeng-xifeng`（+ mobile） |

边缘缓存：已按文件名清理 `var/server/nginx/cache` 中匹配条目，探活见下。

---

## HTTP 探活（本回合 · 验收 Host）

| URL | HTTP | size | dims | edge |
|-----|------|------|------|------|
| `/zh_Hans_CN/`（首页） | **200** | — | — | — |
| `/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp` | **200** | 125760 | **1920×900** | MISS（刷缓存后） |
| `/pub/media/catalog/hanfu/r2/homepage/shenlong-yin.webp` | **200** | 77546 | **1920×900** | MISS |
| `/pub/media/catalog/hanfu/r2/homepage/zuimeng-xifeng.webp` | **200** | 114034 | **1920×900** | MISS |
| `/static/.../peach-garden-scene.webp` | **200** | 125760 | **1920×900** | HIT |
| `/media/catalog/hanfu/r2/homepage/hero/changan-hanfu-hero-desktop-1920x900.webp` | **200** | 125760 | **1920×900** | MISS |
| `/media/catalog/hanfu/r2/shelf-samples/changan-hanfu-shelf-01-1200x1200.webp` | **200** | 142558 | **1200×1200** | HIT |

可点击旁注：

- [首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)
- [Hero 帧1](https://p05113ef3.test.weline.com:9555/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp)
- [货架样张01](https://p05113ef3.test.weline.com:9555/media/catalog/hanfu/r2/shelf-samples/changan-hanfu-shelf-01-1200x1200.webp)

---

## 货架 / PDP 排队

| 优先级 | 项 | 规格 | 状态 | 建议下一工单 |
|--------|----|------|------|--------------|
| P0 | 首页 Hero | 1920×800～1080 + 4:5 | **本波完成（实挂）** | 测试 Browser 审图 |
| P0 | 精选/特价货架主图（全量 SKU） | 1:1 1200 | **样张×4；全量排队** | `WO-BUILD-ASSET-02` |
| P0 | PDP 主图 | 1:1 ≥1600 | **排队** | 并入 ASSET-02 / PDP-01 |
| P1 | 细节/工艺图 | 4:5 或 1:1 长边 1200 | **排队** | 同上 |

样张用途：气质基准与卡面媒体框审图；**不得**宣称全站货架/PDP 已换图完成。

---

## 非本席范围

- `$0.00` 价签噪声 → 前端/商品数据席  
- Hero HTML `height="600"` / aspect 声明对齐 900 → 主题/部件（可选）  
- COLLECTION/PDP Browser 运营签收 → 测试 + 顾问复审  
- 社媒店招（`websites/changanhanfu.com/assets-brand-social/`）与店面 Hero **勿混用同一裁切**
