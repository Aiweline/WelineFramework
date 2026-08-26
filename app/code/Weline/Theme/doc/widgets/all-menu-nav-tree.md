# all-menu 部件与导航树

## 部件

- **code**：`all-menu`
- **槽**：`all-menu`（Header「全部」）
- **配置字段**：`menu_tree`（ParamSchema `all_menu_tree` → `nav_tree`）

节点本质是 **名字 + URL + 标签 + 父子位置**。页面 / 分类 / 自定义可在同一棵树中任意交叉嵌套，硬上限 **3 级**。

```json
{
  "id": "stable-uid",
  "tag": "page|category|custom",
  "name": "关于我们",
  "url": "/about",
  "children": [],
  "description": "",
  "image": "",
  "ref": "router:about",
  "meta": {}
}
```

### 名称与 i18n

- 配置里 `name` 存**中文源串**（翻译键）；各语言展示名写在节点 `i18n.name.{locale}`。
- `description` 为中文源描述；各语言写在 `i18n.description.{locale}`。
- `MenuTreeNormalizer::toNavItems()` 按**当前语言**优先读 `i18n.name` / `i18n.description`，再回退 `WidgetI18n` / `__()`。
- ParamSchema `item_schema.name` / `item_schema.description` 标记 `i18n` + `translatable`；编辑器「编辑」弹层按已安装语言填写翻译。

- 编辑器：左侧页面/分类**点击或拖拽**进树；**添加自定义**后自动打开编辑；行可拖拽排序/嵌套（上缘=前、中间=子项、下缘=后）；点名称或「编辑」改链接、描述、图片与各语言翻译。

默认种子为 Theme 壳页节点（`tag=page`），可删可改，可再拖入分类或自定义。

## 事件

| 事件 | 用途 |
|------|------|
| `Weline_Theme::all_menu_page_candidates` | 页面候选（壳页 + CMS）；拖入时 `tag=page` |
| `Weline_Theme::all_menu_category_candidates` | 分类候选；`Weline_Product` 观察者按 catalog 提供；未接入时面板为空，不影响页面与自定义 |

数据载荷：`candidates`（嵌套数组，形状同节点）。

## 编辑器

- ParamType：`nav_tree`（`Weline\Widget\Ui\ParamType\NavTreeType`）
- UI：`data-w-component="nav-tree"` / `w-nav-tree-editor`（`widget-param-types.js`）
- 右侧菜单树支持拖拽归组（最多三级）：
  - 行**上缘** = 插入到该节点前（同级）
  - 行**中间** = 归组为该节点子项
  - 行**下缘** = 插入到该节点后（同级）
  - 虚线子区域 / 底部「拖放到此处」= 追加子项或末尾
- 左侧候选可拖拽或点击 `+` 快速加入

## 运行时

部件渲染时调用 `AllMenuTreeRegistry::publish($menuTree)`；Header `#categories-sidebar` 优先消费该树（小屏汉堡与桌面「全部」共用），列表文案经 `headerEsc` 按当前语言翻译。
