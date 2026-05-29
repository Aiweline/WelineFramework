# 产品详情推荐区域内容 Hook

- **Hook 名称**：`WeShop_Product::frontend::layouts::product::recommendations-content`
- **使用位置**：商品详情页推荐区域整体容器内部
- **默认行为**：未实现该 Hook 时，布局继续渲染下列小粒度接入点：相关产品、热销商品、最近浏览、搭配推荐。

## 使用场景

- 完整接管商品详情页推荐区域
- 按行业模板重排多个推荐模块
- 使用第三方推荐系统输出完整推荐内容

## 建议

如果只需要追加一个推荐组件，优先使用更小的 Hook 或 Slot：

- `WeShop_Product::frontend::layouts::product::related-products`
- `WeShop_Product::frontend::layouts::product::bestsellers`
- `WeShop_Product::frontend::layouts::product::recently-viewed`
- `WeShop_Product::frontend::layouts::product::cross-sell`
