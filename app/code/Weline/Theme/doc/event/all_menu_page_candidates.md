# 事件：all_menu_page_candidates

- **名称**：`Weline_Theme::all_menu_page_candidates`
- **用途**：为 all-menu / `nav_tree` 编辑器提供页面候选池
- **载荷**：`candidates`（list of `{id,tag,name,url,ref?,children?}`）
- **默认观察者**：`Weline\Theme\Observer\AllMenuPageCandidates`（空列表时回填壳页 + CMS）

拖入编辑器后节点标签为 `page`。与 `all_menu_category_candidates`、自定义节点可交叉进同一棵 `menu_tree`。
