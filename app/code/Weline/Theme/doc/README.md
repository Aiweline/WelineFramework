# Weline Theme 主题模块

## 模块概述

Weline Theme 是系统的主题管理模块，提供了多主题支持、主题切换、主题定制等功能，让系统具有灵活的界面展示能力。

## 主要功能

### 1. 主题管理
- 多主题支持
- 主题切换
- 主题配置

### 2. 模板系统
- 模板引擎
- 布局管理
- 区块系统

### 3. 资源管理
- 静态资源管理
- 主题资源打包
- CDN 支持

### 4. 主题定制
- 主题参数配置
- 样式定制
- 功能扩展

### 5. 响应式设计
- 移动端适配
- 多设备支持
- 自适应布局

## 使用方法

### 主题创建
```php
namespace Your\Theme;

use Weline\Theme\ThemeInterface;

class YourTheme implements ThemeInterface
{
    public function getName()
    {
        return 'your_theme';
    }
    
    public function getTitle()
    {
        return 'Your Theme';
    }
    
    public function getVersion()
    {
        return '1.0.0';
    }
    
    public function getAuthor()
    {
        return 'Your Name';
    }
}
```

### 主题配置
```php
// 主题配置文件: etc/theme.xml
<?xml version="1.0"?>
<theme>
    <name>your_theme</name>
    <title>Your Theme</title>
    <version>1.0.0</version>
    <author>Your Name</author>
    
    <areas>
        <area name="frontend">
            <layout>default</layout>
        </area>
        <area name="admin">
            <layout>admin</layout>
        </area>
    </areas>
    
    <assets>
        <css>static/css/style.css</css>
        <js>static/js/app.js</js>
    </assets>
</theme>
```

### 模板开发
```html
<!-- 布局文件: design/frontend/default/layout.html -->
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {block name="head"}
        <link rel="stylesheet" href="{$theme_url}/css/style.css">
    {/block}
</head>
<body>
    <header>
        {block name="header"}
            {include file="header.html"}
        {/block}
    </header>
    
    <main>
        {block name="content"}
            {include file="$template"}
        {/block}
    </main>
    
    <footer>
        {block name="footer"}
            {include file="footer.html"}
        {/block}
    </footer>
    
    {block name="scripts"}
        <script src="{$theme_url}/js/app.js"></script>
    {/block}
</body>
</html>
```

### 区块开发
```php
namespace Your\Theme\Block;

use Weline\Theme\Block\AbstractBlock;

class YourBlock extends AbstractBlock
{
    public function render()
    {
        $data = $this->getData();
        return $this->fetch('your_block.html', $data);
    }
    
    protected function getData()
    {
        return [
            'title' => '区块标题',
            'content' => '区块内容'
        ];
    }
}
```

## 配置说明

### 主题配置
在 `app/etc/theme.php` 中配置主题相关设置：

```php
'theme' => [
    'default_frontend' => 'default',
    'default_admin' => 'admin',
    'fallback' => 'default',
    'cache' => true,
    'minify' => true
]
```

### 主题参数
```php
'theme_params' => [
    'default' => [
        'primary_color' => '#007bff',
        'secondary_color' => '#6c757d',
        'font_family' => 'Arial, sans-serif',
        'logo' => 'static/images/logo.png'
    ]
]
```

## 依赖关系

- Weline_Framework
- Weline_Frontend

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 主题结构

### 标准主题目录结构
```
your_theme/
├── etc/
│   └── theme.xml
├── design/
│   ├── frontend/
│   │   └── default/
│   │       ├── layout.html
│   │       ├── header.html
│   │       ├── footer.html
│   │       └── templates/
│   └── admin/
│       └── default/
├── static/
│   ├── css/
│   ├── js/
│   └── images/
├── Block/
├── Helper/
└── register.php
```

### 模板继承
```html
<!-- 父模板 -->
{block name="content"}
    <div class="content">
        默认内容
    </div>
{/block}

<!-- 子模板 -->
{extends file="parent.html"}

{block name="content"}
    <div class="custom-content">
        自定义内容
    </div>
{/block}
```

## 主题切换

### 程序化切换
```php
use Weline\Theme\Helper\Theme;

$theme = new Theme();
$theme->setCurrentTheme('your_theme');
```

### 用户切换
```php
// 在控制器中处理主题切换
public function switchTheme()
{
    $themeName = $this->getRequest()->getParam('theme');
    $theme = new Theme();
    $theme->setCurrentTheme($themeName);
    
    $this->redirect('frontend/index/index');
}
```

## 响应式设计

### 移动端适配
```css
/* 响应式样式 */
@media (max-width: 768px) {
    .container {
        width: 100%;
        padding: 0 15px;
    }
    
    .nav {
        display: none;
    }
    
    .mobile-nav {
        display: block;
    }
}

@media (max-width: 480px) {
    .header {
        padding: 10px 0;
    }
    
    .logo {
        max-width: 150px;
    }
}
```

### 触摸优化
```css
/* 触摸友好的按钮 */
.btn {
    min-height: 44px;
    min-width: 44px;
    padding: 12px 20px;
}

/* 触摸反馈 */
.btn:active {
    transform: scale(0.95);
}
```

## 主题定制

### 样式定制
```css
/* 主题变量 */
:root {
    --primary-color: #007bff;
    --secondary-color: #6c757d;
    --font-family: 'Arial', sans-serif;
}

/* 使用变量 */
.btn-primary {
    background-color: var(--primary-color);
    font-family: var(--font-family);
}
```

### 功能扩展
```php
// 主题助手类
namespace Your\Theme\Helper;

class ThemeHelper
{
    public function getCustomData()
    {
        return [
            'custom_setting' => 'value',
            'theme_config' => $this->getThemeConfig()
        ];
    }
}
```

## 性能优化

### 1. 资源优化
- CSS/JS 文件合并压缩
- 图片优化和压缩
- 使用 CDN 加速

### 2. 缓存策略
- 模板缓存
- 静态资源缓存
- 浏览器缓存

### 3. 加载优化
- 异步加载非关键资源
- 预加载关键资源
- 懒加载图片

## 主题开发工具

### 主题生成器
```bash
# 生成新主题
php bin/w theme:create your_theme

# 生成主题资源
php bin/w theme:assets your_theme

# 清理主题缓存
php bin/w theme:clear your_theme
```

### 主题测试
```php
// 主题功能测试
class ThemeTest extends TestCase
{
    public function testThemeRendering()
    {
        $theme = new Theme();
        $theme->setCurrentTheme('test_theme');
        
        $result = $theme->render('test_template');
        $this->assertNotEmpty($result);
    }
}
```

## 最佳实践

### 1. 主题设计
- 遵循设计规范
- 保持界面一致性
- 注重用户体验

### 2. 代码组织
- 模块化开发
- 代码复用
- 文档完善

### 3. 性能考虑
- 优化资源加载
- 减少HTTP请求
- 合理使用缓存

### 4. 兼容性
- 多浏览器支持
- 移动端适配
- 渐进增强 