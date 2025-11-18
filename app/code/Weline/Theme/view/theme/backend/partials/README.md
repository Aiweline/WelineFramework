# Backend Partials 目录说明

## 目录结构

`partials/` 目录包含后端管理界面的可复用片段：

```
partials/
├── header/              # 后端头部片段
│   └── default.phtml   # 默认头部
├── footer/              # 后端底部片段
│   └── default.phtml   # 默认底部
└── sidebar/             # 后端侧边栏片段
    └── default.phtml   # 默认侧边栏
```

## 使用方式

与前端 partials 使用方式相同，只需将 `area` 参数改为 `backend`：

```php
<?php
$partialsBlock = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Block\Partials::class);
$headerPath = $partialsBlock->getPartialsPath('backend', 'header', 'default');
if ($headerPath) {
    echo $this->fetch($headerPath);
}
?>
```

## 配置

在后台主题配置页面可以分别为前端和后端配置不同的 partials 选项。

