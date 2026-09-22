# channel — sitewide-pixel-findings

## 2026-09-22 父会话 → 项目经理

### FAIL：PDP 真实浏览/加购未入库

- URL：`/product/hua-chao-ji-bi-yun-xia-guang-song-zhi-han-fu-yin-hua-da-xi-99e62ff2`
- WB：抹 `webdriver`；点 `data-testid=product-add-to-cart`
- DB：`pixel_id=50069` 仅 `page_view`；**无 `view_item` / `add_to_cart`**
- 预期：PDP 自动 `view_item`；加购按钮 `weline-pixel::add_to_cart` 触发入库
- 建议 rework_owner：`Team:数据分析:`（像素采集）± `Team:事件:`

### 已调度

- 项目经理：5118b63e-7b86-43df-b88a-6295b2876cc2
- 测试（结账/支付链）：705a98cd-13e3-4377-9fc4-c60f3a656cc4

### 矩阵

`doc/开发/team/event-lifecycle-closeout/sitewide-event-matrix.md`
