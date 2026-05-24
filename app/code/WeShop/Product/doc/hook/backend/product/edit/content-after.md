# WeShop_Product::backend::product::edit::content-after

后台产品编辑页内容尾部扩展点。模块可在这里追加与当前产品相关的管理面板，通常应与 `nav-after` 中新增的标签入口配套使用。

实现文件示例：

`view/hooks/WeShop_Product/backend/product/edit/content-after.phtml`

约束：

- 不要在 Product 模块中直接耦合业务模块服务。
- 面板内按钮需使用 `type="button"`，避免误触发产品保存表单。
