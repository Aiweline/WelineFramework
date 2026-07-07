> 警告：本文是历史主题设计资料，仅用于理解早期设计思路，不是当前开发规范。当前主题开发先读 `app/code/Weline/Theme/doc/AI-INDEX.md`、`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`、`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`；浏览器业务请求只使用 `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`。

# config/ 目录文档

## 目录概述

`config/` 目录包含主题的配置文件，用于存储主题的元数据、配置信息等。这些配置文件可以被PHP和JavaScript读取，用于主题的初始化和配置。

## 目录结构

```
config/
└── theme.json              # 主题配置文件（元数据）
```

---

## 文件说明

### `theme.json` - 主题配置文件

**作用**：存储主题的元数据和配置信息

**内容结构**：
```json
{
    "name": "Weline Default Theme",
    "version": "1.0.0",
    "description": "Weline Frontend 默认主题，参考Amazon简约风格",
    "author": "Weline Team",
    "license": "MIT",
    "homepage": "https://aiweline.com",
    
    "variables": {
        "primary-color": "#f0c14b",
        "font-family": "-apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif",
        "container-max-width": "1200px"
    },
    
    "themes": [
        {
            "name": "light",
            "label": "亮色主题",
            "default": true
        },
        {
            "name": "dark",
            "label": "暗色主题",
            "default": false
        },
        {
            "name": "amazon",
            "label": "Amazon风格",
            "default": false
        }
    ],
    
    "components": [
        {
            "name": "button",
            "file": "components/button.phtml",
            "description": "按钮组件"
        },
        {
            "name": "input",
            "file": "components/input.phtml",
            "description": "输入框组件"
        }
    ],
    
    "layouts": [
        {
            "name": "default",
            "file": "layouts/default.phtml",
            "description": "默认布局"
        },
        {
            "name": "auth",
            "file": "layouts/auth.phtml",
            "description": "认证页面布局"
        }
    ],
    
    "features": {
        "rtl": false,
        "responsive": true,
        "dark-mode": true,
        "custom-colors": true
    }
}
```

**字段说明**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `name` | string | 主题名称 |
| `version` | string | 主题版本 |
| `description` | string | 主题描述 |
| `author` | string | 作者 |
| `license` | string | 许可证 |
| `homepage` | string | 主页URL |
| `variables` | object | 主题变量配置 |
| `themes` | array | 可用主题列表 |
| `components` | array | 组件列表 |
| `layouts` | array | 布局列表 |
| `features` | object | 功能特性 |

---

## 使用方式

### 1. 在PHP中读取配置

```php
<?php
// 读取主题配置
$configPath = __DIR__ . '/theme/config/theme.json';
$config = json_decode(file_get_contents($configPath), true);

// 使用配置
$themeName = $config['name'];
$version = $config['version'];
$themes = $config['themes'];
?>
```

### 2. 在JavaScript中读取配置

```javascript
// 通过AJAX加载配置
fetch('/static/Weline_Frontend/theme/config/theme.json')
    .then(response => response.json())
    .then(config => {
        console.log('主题名称:', config.name);
        console.log('可用主题:', config.themes);
    });
```

### 3. 在模板中使用配置

```php
<?php
$config = json_decode(file_get_contents(__DIR__ . '/theme/config/theme.json'), true);
?>
<meta name="theme-name" content="<?= htmlspecialchars($config['name']) ?>">
<meta name="theme-version" content="<?= htmlspecialchars($config['version']) ?>">
```

---

## 配置扩展

### 添加新配置项

```json
{
    "custom-setting": {
        "value": "custom-value",
        "description": "自定义配置项"
    }
}
```

### 添加新主题

```json
{
    "themes": [
        {
            "name": "your-theme",
            "label": "你的主题",
            "default": false
        }
    ]
}
```

---

## 最佳实践

1. **版本管理**：配置变更时更新版本号
2. **文档化**：配置项应有清晰的说明
3. **验证**：读取配置时进行验证
4. **缓存**：配置可以缓存以提高性能

---

## 相关文档

- [theme/ 目录详细说明](./theme目录详细说明.md)
- [主题扩展指南](./主题扩展指南.md)（待创建）

