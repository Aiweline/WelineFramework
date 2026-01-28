# PageBuilder 模板规约

创建新模板时必须遵循以下规范，确保可视化编辑器中的组件拖拽替换功能正常工作。

## 目录结构

```
style/{template_code}/
├── components/
│   └── component.json          # 组件配置（必需）
├── header/
│   └── nav.phtml               # Header 组件
├── content/
│   ├── hero.phtml              # Content 组件
│   └── ...
├── footer/
│   └── links.phtml             # Footer 组件
├── layouts/
│   └── default/
│       └── home_page.json      # 默认布局配置
├── layout.phtml                # 主布局文件（必需）
└── asset/
    ├── css/
    └── img/
```

## component.json 必需字段

### 1. regions 区域配置

每个区域（header/content/footer）必须包含以下字段：

```json
{
    "regions": {
        "header": {
            "name": "头部区域",
            "name_en": "Header",
            "description": "页面顶部区域",
            "multiple": false,
            "required": true,
            "accepts": ["header"],           // 必需：接受的组件类别
            "default_component": "header-nav" // 必需：默认组件
        },
        "content": {
            "name": "内容区域",
            "name_en": "Content",
            "description": "页面主要内容区域",
            "multiple": true,
            "required": false,
            "accepts": ["content", "widget"], // 必需：接受的组件类别
            "default_components": ["hero", "features"] // 必需：默认组件列表
        },
        "footer": {
            "name": "底部区域",
            "name_en": "Footer",
            "description": "页面底部区域",
            "multiple": false,
            "required": true,
            "accepts": ["footer"],            // 必需：接受的组件类别
            "default_component": "footer-links" // 必需：默认组件
        }
    }
}
```

**关键字段说明：**
- `accepts`: 定义该区域接受哪些 category 的组件，用于拖拽时验证
- `default_component` / `default_components`: 新页面使用的默认组件

### 2. components 组件配置

每个组件必须包含以下字段：

```json
{
    "components": {
        "header-nav": {
            "name": "导航头部",
            "name_en": "Navigation Header",      // 推荐：英文名
            "description": "页面顶部导航组件",
            "region": "header",                  // 必需：所属区域
            "category": "header",                // 必需：组件类别（与 region 对应）
            "type": "section",                   // 必需：组件类型
            "sort_order": 1,                     // 必需：排序顺序
            "is_default": true,                  // 必需：是否为默认组件
            "compatible_styles": ["*"],          // 必需：兼容的样式（"*" 表示全部）
            "config_groups": ["header", "navigation"], // 推荐：配置分组
            "file": "header/nav.phtml",          // 必需：组件文件路径
            "default_config": {}                 // 可选：默认配置
        },
        "footer-links": {
            "name": "页脚链接",
            "name_en": "Footer with Links",
            "description": "包含链接的底部组件",
            "region": "footer",                  // 必需：设为 "footer"
            "category": "footer",                // 必需：设为 "footer"
            "type": "section",
            "sort_order": 1,
            "is_default": true,
            "compatible_styles": ["*"],
            "config_groups": ["style", "content"],
            "file": "footer/links.phtml"
        }
    }
}
```

**关键字段说明：**
- `region`: 组件所属区域，必须是 "header" / "content" / "footer" 之一
- `category`: 组件类别，必须与 `region` 对应：
  - header 组件的 category 必须是 "header"
  - footer 组件的 category 必须是 "footer"
  - content 组件的 category 是 "content" 或 "widget"
- `type`: 固定为 "section"
- `compatible_styles`: 设为 `["*"]` 表示兼容所有样式模板

## layout.phtml 必需属性

布局文件中的区域容器必须包含正确的 data 属性：

```php
<!-- Header 区域 -->
<header data-region="header" data-multiple="false">
    <div class="tpmst-component-wrapper" 
         data-component="<?= $component['code'] ?>"
         data-region="header">
        <!-- 组件内容 -->
    </div>
</header>

<!-- Content 区域 -->
<main data-region="content" data-multiple="true">
    <div class="tpmst-component-wrapper" 
         data-component="<?= $component['code'] ?>"
         data-region="content"
         data-index="<?= $index ?>">
        <!-- 组件内容 -->
    </div>
</main>

<!-- Footer 区域 -->
<footer data-region="footer" data-multiple="false">
    <div class="tpmst-component-wrapper" 
         data-component="<?= $component['code'] ?>"
         data-region="footer">
        <!-- 组件内容 -->
    </div>
</footer>
```

**关键属性说明：**
- `data-region`: 区域标识，拖拽时用于验证
- `data-multiple`: 是否允许多个组件
- `data-component`: 组件代码
- `data-index`: 仅 content 区域需要，用于排序

## 常见问题

### 1. 组件无法拖拽到对应区域
检查 component.json 中：
- 组件的 `category` 是否正确（header/content/footer）
- 区域的 `accepts` 是否包含该 category

### 2. 替换 header/footer 组件失败
检查：
- 组件的 `region` 和 `category` 是否都设置为 "header" 或 "footer"
- layout.phtml 中区域容器是否有 `data-region` 属性

### 3. 组件不显示在面板中
检查：
- `compatible_styles` 是否设置为 `["*"]` 或包含当前模板代码
- `sort_order` 是否设置（用于排序显示）

## 模板检查清单

创建新模板时，请确认以下项目：

- [ ] component.json 中每个 region 都有 `accepts` 和 `default_component`
- [ ] 每个组件都有 `region`、`category`、`type`、`sort_order`、`is_default`、`compatible_styles`
- [ ] header 组件的 region 和 category 都是 "header"
- [ ] footer 组件的 region 和 category 都是 "footer"
- [ ] layout.phtml 中所有区域容器都有 `data-region` 属性
- [ ] 组件包装器都有 `tpmst-component-wrapper` class 和 `data-component`、`data-region` 属性
