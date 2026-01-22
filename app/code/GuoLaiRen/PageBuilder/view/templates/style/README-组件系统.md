# PageBuilder 可视化组件系统

## 目录结构

```
style/
├── _shared/                    # 共享组件目录
│   └── components/
│       ├── component.json      # 共享组件配置
│       ├── cta-banner.phtml    # CTA 行动号召
│       └── feature-cards.phtml # 功能卡片
│
├── _layouts/                   # 布局类型目录
│   ├── layout.json             # 布局类型定义
│   └── blog/                   # 博客布局
│       ├── layout.json         # 博客布局配置
│       └── wrapper.phtml       # 博客布局包装器
│
├── default/                    # 默认模板
│   ├── header.phtml
│   ├── content.phtml
│   ├── footer.phtml
│   └── components/             # 模板专属组件（可选）
│
├── tpmst/                      # TPMST 模板
│   ├── header.phtml
│   ├── content.phtml
│   ├── footer.phtml
│   └── components/
│       ├── component.json
│       ├── slider.phtml
│       ├── advantages.phtml
│       └── ...
│
└── blog/                       # 博客模板（作为样式使用）
    ├── header.phtml
    ├── content.phtml
    └── footer.phtml
```

## 核心概念

### 1. 布局 (Layout)
布局定义了页面的整体结构，包括区域划分和组件放置规则。

| 布局类型 | 说明 | 区域 |
|---------|------|------|
| default | 默认布局 | header, content, footer |
| blog | 博客布局 | header, sidebar, content, footer |
| landing | 落地页布局 | header, hero, content, cta, footer |
| minimal | 极简布局 | content |

### 2. 模板 (Template/Style)
模板定义了页面的视觉风格，每个模板可以有自己的组件。

### 3. 组件 (Component)
组件是可复用的内容块，分为三类：
- **模板专属组件**：位于 `style/{template}/components/` 目录
- **共享组件**：位于 `style/_shared/components/` 目录
- **系统组件**：header.phtml 和 footer.phtml

### 4. 区域 (Region)
页面中可放置组件的位置，如 header、content、footer、sidebar 等。

## 组件定义

组件文件支持元数据定义：

```php
<?php
/**
 * @component_start
 * name => 组件名称
 * name_en => Component Name
 * description => 组件描述
 * category => content
 * type => section
 * thumbnail => path/to/thumb.png
 * sort_order => 10
 * compatible_styles => *
 * @component_end
 * 
 * @fields_start
 * 
 * group:section => 区域设置
 * section.title => 标题:text:默认值
 * section.color => 颜色:color:#333333
 * section.show => 显示:select:yes|yes,no
 * 
 * @fields_end
 */
```

### 组件分类 (Category)
- `header` - 头部组件
- `footer` - 底部组件
- `content` - 内容组件
- `widget` - 小部件

### 组件类型 (Type)
- `section` - 区块组件（如 Banner、Feature Cards）
- `widget` - 小部件（如 Button、Form）
- `layout` - 布局组件
- `system` - 系统组件

## 使用方式

### 1. 在可视化编辑器中
1. 打开页面编辑 > 可视化配置
2. 右侧组件面板显示所有可用组件
3. 拖拽组件到对应区域
4. 点击编辑配置组件

### 2. 组件优先级
1. **推荐组件**：当前模板专属组件（样式最契合）
2. **共享组件**：跨模板通用组件
3. **兼容组件**：其他模板的组件

### 3. 恢复默认
用户可以随时清除自定义组件配置，恢复为模板默认内容。

## API 接口

### 获取组件列表
```
GET /pagebuilder/backend/visual/api/component/list
参数：
- style_code: 模板代码
- layout_code: 布局代码（可选）
- include_compatible: 是否包含兼容组件 (1/0)
```

### 获取组件信息
```
GET /pagebuilder/backend/visual/api/component/info
参数：
- component_code: 组件代码
```

### 预览组件
```
POST /pagebuilder/backend/visual/api/component/preview
参数：
- component_code: 组件代码
- config: 配置 JSON
```

## 开发指南

### 创建新组件
1. 在模板的 `components/` 目录创建 `.phtml` 文件
2. 添加 `@component_start` 元数据
3. 添加 `@fields_start` 配置定义
4. 运行组件扫描或访问页面编辑触发自动扫描

### 创建共享组件
将组件放在 `_shared/components/` 目录，并更新 `component.json`。

### 创建新布局
1. 在 `_layouts/` 创建布局目录
2. 创建 `layout.json` 定义布局结构
3. 创建 `wrapper.phtml` 布局包装器
4. 在 `_layouts/layout.json` 中注册布局
