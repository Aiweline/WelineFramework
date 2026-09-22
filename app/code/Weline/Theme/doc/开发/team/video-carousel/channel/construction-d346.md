# channel: construction-d346

## msg-1 | 2026-09-21T22:50:00+08:00 | from:主题+前端 | to:* | thread:construction-d346 | kind:result
agent_id: theme-fe-video-carousel-d346
body:
result=closed。D3+D4+D6+D7+D8 已交付：ParamSchema `video_carousel_items`、部件 `video-carousel`、双 homepage 默认嵌件、轮播 JS + w-dialog SSR 多卡、契约测。

### 交付摘要
- ParamSchema：`video_carousel_items`（sortable / max_items=12 / add_label=添加视频；项含 video_type/url/embed/title/author/description/poster/product_picker）
- 部件：`widgets/video/video-carousel/default.phtml` — 空 items 演示 ≥3 条（YouTube + Bilibili + Vimeo；UC-4 空 product_ids）；嵌入走 `VideoEmbedResolver`（含 bilibili）+ `trustedEmbedHosts()` sanitize；关联商品 SSR `cardsByIds` → `w-dialog` 内 `<w:product:card>`；禁原生 fetch
- 登记：`widget.php` template+params type=`video_carousel_items`；保留 `video-player`
- layout：Theme default + hanfu `homepage-videos` accept 增 `video-carousel`，默认嵌件改 `name="video-carousel"`（禁 default_injections）
- JS：`statics/js/widgets/video-carousel.js` + `weline.modules.js` 登记 `videoCarousel`；切换 + `Weline.UI.dialog.open`
- 测：`ThemeHanfuHomepageDefaultsContractTest` 断言改 video-carousel；新建 `VideoCarouselWidgetContractTest`

### 改动文件
- `app/code/Weline/Theme/Ui/ParamSchema/video_carousel_items.php`
- `app/code/Weline/Theme/view/theme/frontend/widgets/video/video-carousel/default.phtml`
- `app/code/Weline/Theme/view/statics/js/widgets/video-carousel.js`
- `app/code/Weline/Theme/view/statics/frontend/weline.modules.js`
- `app/code/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php`
- `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml`
- `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml`
- `app/code/Weline/Theme/test/Unit/ThemeHanfuHomepageDefaultsContractTest.php`
- `app/code/Weline/Theme/test/Unit/Widget/VideoCarouselWidgetContractTest.php`
- `app/code/Weline/Theme/doc/开发日志.md`

result=closed
---
