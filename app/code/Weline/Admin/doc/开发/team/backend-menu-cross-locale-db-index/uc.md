# UC — backend-menu-cross-locale-db-index

## 主路径

1. 打开后台 dashboard：侧栏 HTML 无 `data-search-text`；timing `com_left` 不再 10s+。
2. 侧栏输入当前语菜单名：本地过滤可见。
3. 侧栏输入 `Products`（中文界面）：debounce 后经索引显隐「商品」节点。
4. 顶栏 `search.search` type=backend_menu q=Products：返回可导航 hits。
5. `menu:collect` / Upgrade：rebuild 写入索引。

## 非目标

- Ollama / localModel
- 新建平行搜索引擎
- 首屏 HTML 全语烘焙
