# PageBuilder 组件开发规约

## 1. 组件代码命名规范

组件代码必须遵循以下规则：
- 只能使用**小写字母**、**数字**和**连字符**
- 格式：`[区域]-[名称]` 或 `[名称]`
- 示例：`header-nav`、`footer-links`、`hero-slider`、`faq`

**错误示例**：
- `HeaderNav`（不能使用大写）
- `header_nav`（不能使用下划线）
- `header.nav`（不能使用点号）

## 2. 文件目录结构

每个模板的组件必须放在 `components/` 目录下，按区域分类：

```
style/{template}/
├── components/
│   ├── component.json       # 组件配置文件（必需）
│   ├── header/              # 头部组件
│   │   ├── nav.phtml
│   │   └── simple.phtml
│   ├── content/             # 内容组件
│   │   ├── slider.phtml
│   │   ├── faq.phtml
│   │   └── ...
│   └── footer/              # 底部组件
│       ├── links.phtml
│       └── simple.phtml
├── layout.phtml             # 布局模板
└── ...
```

## 3. component.json 必需字段

每个组件在 `component.json` 中必须定义以下字段：

```json
{
  "components": {
    "header-nav": {
      "name": "导航头部",           // 必需：中文名称
      "file": "header/nav.phtml",  // 必需：文件路径（相对于 components/）
      "region": "header",          // 必需：所属区域（header/content/footer）
      "category": "header",        // 必需：分类（与 region 一致或为 widget）
      "name_en": "Navigation",     // 可选：英文名称
      "description": "...",        // 可选：描述
      "thumbnail": "asset/...",    // 可选：缩略图
      "sort_order": 1,             // 可选：排序
      "is_default": true           // 可选：是否为默认组件
    }
  }
}
```

## 4. 组件代码与布局配置对应

布局配置中的组件代码必须与 `component.json` 中定义的代码**完全一致**：

```json
// layouts/default/home_page.json
{
  "layout_config": {
    "header": [
      {"code": "header-nav", "enabled": true}  // 必须匹配 component.json 中的 key
    ],
    "content": [
      {"code": "hero-slider", "enabled": true},
      {"code": "faq", "enabled": true}
    ],
    "footer": [
      {"code": "footer-links", "enabled": true}
    ]
  }
}
```

## 5. 组件文件规范

组件 PHTML 文件应包含配置字段定义（用于可视化编辑器）：

```php
<?php
/**
 * 组件名称
 * 
 * @component_start
 * name: 组件中文名称
 * name_en: Component English Name
 * region: content
 * category: content
 * @component_end
 * 
 * @fields_start
 * group:settings => 设置
 * settings.title => 标题:text:默认值
 * settings.display => 显示:select:yes|yes,no
 * @fields_end
 */
?>
```

## 6. 验证命令

使用以下命令验证组件配置：

```bash
# 验证单个模板
php bin/m pagebuilder:component:validate tpmst

# 验证所有模板
php bin/m pagebuilder:component:validate --all
```

## 7. 常见错误

### 7.1 组件不存在
- 检查 `component.json` 中是否定义了该组件代码
- 检查组件文件是否存在于指定路径
- 检查代码是否有拼写错误

### 7.2 配置不生效
- 检查字段定义格式是否正确
- 确保配置 key 与字段定义一致（如 `group.field`）

### 7.3 hover/编辑按钮不显示
- 确保页面在可视化编辑器模式下（URL 包含 `visual_editor=1`）
- 检查组件是否被正确包装在 `.tpmst-component-wrapper` 中
- 检查 `data-region` 和 `data-component` 属性是否正确

## 8. 开发建议

1. **先定义再开发**：先在 `component.json` 中定义组件，再创建文件
2. **及时验证**：每次添加新组件后运行验证命令
3. **保持同步**：确保 `component.json`、`layout.phtml` 和布局配置三者一致
4. **使用标准结构**：遵循目录结构规范，便于维护
5. **添加元数据**：为组件添加完整的元数据（name, description, thumbnail）以提升编辑体验
