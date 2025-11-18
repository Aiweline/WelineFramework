# Weline Frontend 前端模块

## 模块概述

Weline Frontend 是系统的前端展示模块，负责用户界面的渲染、前端交互逻辑处理和用户体验优化。

## 主要功能

### 1. 页面渲染
- 模板引擎支持
- 响应式布局
- 多主题切换

### 2. 前端交互
- JavaScript 框架集成
- AJAX 请求处理
- 表单验证

### 3. 用户体验
- 页面加载优化
- 动画效果
- 用户反馈

### 4. 静态资源管理
- CSS/JS 文件管理
- 图片资源优化
- CDN 支持

### 5. SEO 优化
- 页面标题管理
- Meta 标签生成
- 结构化数据

## 使用方法

### 控制器开发
```php
namespace Your\Module\Controller\Frontend;

use Weline\Frontend\Controller\AbstractFrontendController;

class YourController extends AbstractFrontendController
{
    public function index()
    {
        $this->assign('title', '页面标题');
        $this->assign('data', $this->getData());
        return $this->fetch('index');
    }
    
    public function ajax()
    {
        $data = $this->getRequest()->getPost();
        return $this->json(['success' => true, 'data' => $data]);
    }
}
```

### 模板开发
```html
<!-- 模板文件: view/frontend/index.html -->
<!DOCTYPE html>
<html>
<head>
    <title>{$title}</title>
    <meta name="description" content="页面描述">
</head>
<body>
    <div class="container">
        <h1>{$title}</h1>
        <div class="content">
            {foreach $data as $item}
                <div class="item">{$item.name}</div>
            {/foreach}
        </div>
    </div>
    
    <script>
        // AJAX 请求示例
        $.ajax({
            url: '/your-module/ajax',
            method: 'POST',
            data: {id: 1},
            success: function(response) {
                console.log(response);
            }
        });
    </script>
</body>
</html>
```

### 静态资源管理
```php
// 在控制器中加载静态资源
public function index()
{
    $this->addCss('static/css/style.css');
    $this->addJs('static/js/app.js');
    $this->addMeta('keywords', '关键词1,关键词2');
    return $this->fetch('index');
}
```

## 配置说明

### 前端配置
在 `app/etc/frontend.php` 中配置前端相关设置：

```php
'frontend' => [
    'theme' => 'default',
    'layout' => 'default',
    'cache' => true,
    'minify' => true,
    'cdn' => [
        'enabled' => false,
        'domain' => 'https://cdn.example.com'
    ]
]
```

### 主题配置
```php
'themes' => [
    'default' => [
        'name' => '默认主题',
        'path' => 'design/frontend/default',
        'assets' => 'static/frontend/default'
    ]
]
```

## 依赖关系

- Weline_Framework
- Weline_Theme

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 性能优化

### 1. 资源优化
- 启用 Gzip 压缩
- 合并压缩 CSS/JS 文件
- 使用 CDN 加速

### 2. 缓存策略
- 浏览器缓存设置
- 静态资源缓存
- 页面缓存

### 3. 加载优化
- 图片懒加载
- 异步加载非关键资源
- 预加载关键资源

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
```

### 触摸优化
```javascript
// 触摸事件处理
$(document).on('touchstart', '.touchable', function(e) {
    // 触摸开始处理
});

$(document).on('touchend', '.touchable', function(e) {
    // 触摸结束处理
});
```

## SEO 优化

### Meta 标签管理
```php
// 在控制器中设置 SEO 信息
public function index()
{
    $this->setTitle('页面标题');
    $this->setDescription('页面描述');
    $this->setKeywords('关键词1,关键词2');
    $this->setCanonical('https://example.com/page');
    return $this->fetch('index');
}
```

### 结构化数据
```html
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "文章标题",
    "author": {
        "@type": "Person",
        "name": "作者姓名"
    }
}
</script>
```

## 用户体验

### 加载状态
```javascript
// 显示加载状态
function showLoading() {
    $('.loading').show();
}

function hideLoading() {
    $('.loading').hide();
}

// 使用示例
$.ajax({
    url: '/api/data',
    beforeSend: showLoading,
    complete: hideLoading,
    success: function(data) {
        // 处理数据
    }
});
```

### 错误处理
```javascript
// 全局错误处理
$(document).ajaxError(function(event, xhr, settings, error) {
    if (xhr.status === 404) {
        alert('请求的资源不存在');
    } else if (xhr.status === 500) {
        alert('服务器内部错误');
    } else {
        alert('请求失败，请稍后重试');
    }
});
```

## 测试

### 前端测试
```javascript
// 使用 Jest 进行测试
describe('Frontend Tests', function() {
    test('should render page correctly', function() {
        // 测试页面渲染
    });
    
    test('should handle form submission', function() {
        // 测试表单提交
    });
});
```

### 兼容性测试
- 支持主流浏览器
- 移动端兼容性
- 不同分辨率适配 