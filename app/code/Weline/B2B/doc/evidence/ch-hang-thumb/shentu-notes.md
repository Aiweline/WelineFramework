# 审图 — 尾款订单行缩略图

- 分流：web_ui + ui_shot（订单行仅有文字无图）
- 线稿：行 = [56px thumb] + [名称/SKU] + [数量/行金额]
- 原型调整：grid 三列；缺图占位 is-empty；image_url 经 Media+StorefrontProductMediaUrlResolver
- 结论：pass
- 已修：无商品缩略图导致信息密度偏空
- 证据：真单 product 117 → `/pub/media/catalog/hanfu/1688/factory-yueya/730813804749/01-73d593058034.jpg`；E2E thumb-img PASS
