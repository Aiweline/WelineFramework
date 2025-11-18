# Partials 目录说明

## 目录结构

`partials/` 目录包含可复用的页面片段，按类型分类组织：

```
partials/
├── header/              # 头部片段
│   ├── default.phtml   # 默认头部（Logo左侧，导航中间，功能右侧）
│   ├── minimal.phtml   # 极简头部（只包含Logo和导航）
│   └── centered.phtml  # 居中头部（Logo居中，导航在下方）
├── footer/              # 底部片段
│   ├── default.phtml   # 默认底部（包含链接和版权）
│   └── minimal.phtml   # 极简底部（只包含版权）
├── sidebar/             # 侧边栏片段
│   └── default.phtml   # 默认侧边栏
├── breadcrumb/          # 面包屑导航
│   └── default.phtml   # 默认面包屑
└── pagination/          # 分页组件
    └── default.phtml   # 默认分页
```

## 使用方式

### 1. 在布局中使用配置的 partials

```php
<?php
// 获取 Partials Block
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);

// 获取配置的 header 路径（会自动根据主题配置选择）
$headerPath = $partialsBlock->getPartialsPath('frontend', 'header', 'default');

// 渲染 header
if ($headerPath) {
    echo $this->fetch($headerPath, [
        'logo' => $logo,
        'logoText' => $logoText,
        'navItems' => $navItems
    ]);
}
?>
```

### 2. 使用 Block 标签

```php
<w:block class="Weline\Theme\Block\Partials" template="Weline_Theme::theme/frontend/partials/header/default.phtml"/>
```

### 3. 直接使用 renderPartials 方法

```php
<?php
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);
echo $partialsBlock->renderPartials('frontend', 'header', [
    'logo' => $logo,
    'navItems' => $navItems
], 'default');
?>
```

## 后台配置

在后台管理界面可以配置每个主题使用的 partials 选项：

1. 进入主题管理页面
2. 选择要配置的主题
3. 进入 "Partials 配置" 页面
4. 为每个 partials 类型选择对应的选项
5. 保存配置

配置会保存在主题的 `config` 字段中（JSON格式）。

## 添加新的 partials 选项

1. 在对应的类型目录下创建新的 `.phtml` 文件
2. 文件名即为选项名称（如 `minimal.phtml` 对应选项 `minimal`）
3. 系统会自动扫描并显示在配置选项中

## 注意事项

- 每个 partials 类型至少需要一个 `default.phtml` 文件
- 如果配置的选项不存在，会自动回退到 `default`
- 支持主题继承，子主题可以覆盖父主题的配置

