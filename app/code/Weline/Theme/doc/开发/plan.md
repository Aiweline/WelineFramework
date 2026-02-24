# Theme 模块：媒体选择与分辨率配置

本模块子计划，总计划见 [.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md](../../../../.cursor/plans/媒体选择与分辨率配置_676aad06.plan.md)。

## 目标

- hero-slider 的 slides 使用 item_schema，image 字段为 media_image，默认目录 banner、建议尺寸 1920×600。
- hero-slider 增加部件级分辨率配置（image_recommend_width、image_recommend_height）。
- ThemeEditor 注入 file manager connector base URL，供 JS 拼出完整选择器 URL。

## 已完成

- [x] widget.php（hero-slider）：slides 增加 item_schema（image => media_image，media_options 含 default_directory、recommend_width/height）；顶层增加 image_recommend_width、image_recommend_height。
- [x] ThemeEditor index.phtml：`#themeEditor` 增加 `data-file-manager-connector-base`（getBackendUrl elfinder/backend/connector/manager）。
- [x] widget-param-types.js 在 Widget 模块中实现：打开选择器、回填、预览、清除；依赖 ThemeEditor 提供的 data-file-manager-connector-base。

## 涉及文件

- `app/code/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php`
- `app/code/Weline/Theme/view/templates/backend/ThemeEditor/index.phtml`

---

## banner_items schema 与 hero-slider 简化

总计划见 [.cursor/plans/widget_语义化_paramschema_架构.plan.md](../../../../.cursor/plans/widget_语义化_paramschema_架构_c2eaa33f.plan.md)。

### 目标

- 新增 `Ui/ParamSchema/banner_items.php`，提供轮播横幅项目的语义化 schema 定义。
- hero-slider 的 widget.php 配置中 slides 从 `type=array` + 内联 item_schema 简化为 `type=banner_items`。

### 已完成

- [x] 新增 `Ui/ParamSchema/banner_items.php`：base_type=array，item_schema 含 image(media_image)、title、subtitle、link、button_text、text_position，sortable=true，max_items=10。
- [x] widget.php hero-slider slides 改为 `type => 'banner_items'`、`label => '轮播图片'`，删除内联 item_schema / sortable / max_items。

### 涉及文件

- `app/code/Weline/Theme/Ui/ParamSchema/banner_items.php`（新增）
- `app/code/Weline/Theme/extends/module/Weline_Widget/Weline_Theme/widget.php`
