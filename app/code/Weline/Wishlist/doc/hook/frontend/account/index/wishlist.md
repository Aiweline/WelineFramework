# Hook：Weline_Wishlist::frontend::account::index::wishlist

在前台顾客账户首页「收藏 / 愿望清单」分区注入内容。

## 实现

- 模板：`view/hooks/Weline_Wishlist/frontend/account/index/wishlist.phtml`
- 宿主：账户侧栏/分区通过 `<w:hook name="Weline_Wishlist::frontend::account::index::wishlist"/>` 挂载
- 主体复用：`view/templates/frontend/wishlist/index.phtml`
