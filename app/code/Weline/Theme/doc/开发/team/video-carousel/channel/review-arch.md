# channel: review-arch

## msg-1 | 2026-09-22T00:14:00+08:00 | from:架构师 | to:* | thread:review-arch | kind:stance
agent_id: arch-review-video-carousel
body:
**stance=同意** · **verdict=pass** · **result=closed**

只读对照 `contracts.md` / `surfaces.md` / `components.md`；未改生产码。

硬抽检：
1. `widgets/video/video-carousel/default.phtml` 存在；`data-site-block="video-carousel"`；保留 `video-player`
2. Theme + hanfu 双 `homepage/default.phtml`：`accept`∋`video-carousel`；默认 `<w:widget type="video" name="video-carousel" …>`；无 `default_injections`
3. `ArrayType::renderItemField` `case 'product_picker'` 委托 `ProductPickerType`
4. `StorefrontProductWidgetCatalog::cardsByIds` 存在；部件 SSR 调用 → `w-dialog` 内 `<w:product:card>`；JS=`Weline.UI.dialog`
5. ParamSchema `video_carousel_items` + `widget.php` 登记；Resolver/CSP bilibili 三处同扩消费

非阻断：轮播 chrome 未开 `component-negotiate`，但是部件内 N1 轻量控件（非全站库/非平行浮层）。纪要：`meetings/复审-架构.md`。
---
