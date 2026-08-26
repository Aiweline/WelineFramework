# 事件：all_menu_category_candidates

- **名称**：`Weline_Theme::all_menu_category_candidates`
- **用途**：为 all-menu / `nav_tree` 编辑器提供分类候选池
- **载荷**：`candidates`（嵌套数组，`tag=category`）
- **默认观察者**：`Weline\Product\Observer\AllMenuCategoryCandidates`（从 CategoryRepository 建树；无分类时为空）

Catalog 未接入或列表为空时，分类面板为空；页面候选与「添加自定义」仍可完整配置导航树。
