# Weline Taglib 自定义标签模块

## 模块概述

Weline Taglib 是系统的自定义标签库模块，提供了灵活的模板标签扩展机制。该模块允许开发者创建自定义的模板标签，实现模块化、可复用的前端组件，支持标签嵌套、属性传递、依赖管理等功能。

## 主要功能

### 1. 自定义标签创建
- 标签接口定义
- 标签属性配置
- 标签回调处理
- 标签文档生成

### 2. 标签类型支持
- 成对标签 `<tag></tag>`
- 自闭合标签 `<tag/>`
- 带属性标签 `<tag attr="value">`
- 嵌套标签支持

### 3. 标签处理机制
- 标签开始处理
- 标签结束处理
- 标签内容处理
- 属性验证

### 4. 依赖管理
- 父标签依赖
- 标签渲染顺序
- 依赖关系检测
- 循环依赖预防

### 5. 标签扩展
- 动态标签注册
- 标签缓存机制
- 标签测试工具
- 标签文档生成

## 使用方法

### 创建自定义标签
```php
namespace Your\Module\Taglib;

use Weline\Taglib\TaglibInterface;

class CustomTag implements TaglibInterface
{
    /**
     * 标签名称
     */
    static function name(): string
    {
        return 'custom_tag';
    }
    
    /**
     * 是否支持成对标签
     */
    static function tag(): bool
    {
        return true;
    }
    
    /**
     * 标签属性定义
     */
    static function attr(): array
    {
        return [
            'title' => true,    // 必需属性
            'class' => false,   // 可选属性
            'style' => false    // 可选属性
        ];
    }
    
    /**
     * 是否处理标签开始
     */
    static function tag_start(): bool
    {
        return false;
    }
    
    /**
     * 是否处理标签结束
     */
    static function tag_end(): bool
    {
        return false;
    }
    
    /**
     * 标签处理回调函数
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $title = $attributes['title'] ?? '默认标题';
            $class = $attributes['class'] ?? '';
            $style = $attributes['style'] ?? '';
            $content = $tag_data[1] ?? '';
            
            return "<div class='custom-tag {$class}' style='{$style}' title='{$title}'>{$content}</div>";
        };
    }
    
    /**
     * 是否支持自闭合
     */
    static function tag_self_close(): bool
    {
        return true;
    }
    
    /**
     * 自闭合标签是否支持属性
     */
    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }
    
    /**
     * 父标签依赖
     */
    static function parent(): ?string
    {
        return null; // 无依赖
    }
    
    /**
     * 标签文档
     */
    static function document(): string
    {
        return '自定义标签使用说明：<custom_tag title="标题" class="样式类">内容</custom_tag>';
    }
}
```

### 高级标签示例
```php
namespace Your\Module\Taglib;

use Weline\Taglib\TaglibInterface;

class AdvancedTag implements TaglibInterface
{
    static function name(): string
    {
        return 'advanced_tag';
    }
    
    static function tag(): bool
    {
        return true;
    }
    
    static function attr(): array
    {
        return [
            'condition' => true,  // 条件属性
            'loop' => false,      // 循环属性
            'data' => false       // 数据属性
        ];
    }
    
    static function tag_start(): bool
    {
        return true; // 处理标签开始
    }
    
    static function tag_end(): bool
    {
        return true; // 处理标签结束
    }
    
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            return match ($tag_key) {
                'tag_start' => $this->handleTagStart($attributes),
                'tag_end' => $this->handleTagEnd(),
                'tag' => $this->handleTag($tag_data, $attributes),
                default => ''
            };
        };
    }
    
    static function tag_self_close(): bool
    {
        return false;
    }
    
    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }
    
    static function parent(): ?string
    {
        return 'container_tag'; // 依赖容器标签
    }
    
    static function document(): string
    {
        return '高级标签：支持条件渲染和循环处理';
    }
    
    private function handleTagStart($attributes): string
    {
        $condition = $attributes['condition'] ?? '';
        return "<?php if ({$condition}): ?>";
    }
    
    private function handleTagEnd(): string
    {
        return "<?php endif; ?>";
    }
    
    private function handleTag($tag_data, $attributes): string
    {
        $content = $tag_data[1] ?? '';
        $loop = $attributes['loop'] ?? '';
        $data = $attributes['data'] ?? '';
        
        if ($loop) {
            return "<?php foreach ({$loop} as {$data}): ?>{$content}<?php endforeach; ?>";
        }
        
        return $content;
    }
}
```

### 标签注册
```php
// 在模块的 register.php 中注册标签
use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Your_Module',
    __DIR__,
    '1.0.0',
    '自定义模块',
    [],
    [
        'taglibs' => [
            'custom_tag' => 'Your\\Module\\Taglib\\CustomTag',
            'advanced_tag' => 'Your\\Module\\Taglib\\AdvancedTag'
        ]
    ]
);
```

### 模板中使用标签
```html
<!-- 基本使用 -->
<w:custom_tag title="我的标题" class="highlight">这是标签内容</w:custom_tag>

<!-- 自闭合标签 -->
<w:custom_tag title="自闭合标签" class="auto-close"/>

<!-- 条件标签 -->
<w:advanced_tag condition="$user->isLoggedIn()">
    <p>欢迎回来，{$user->getName()}！</p>
</w:advanced_tag>

<!-- 循环标签 -->
<w:advanced_tag loop="$products" data="$product">
    <div class="product">
        <h3>{$product->getName()}</h3>
        <p>{$product->getDescription()}</p>
    </div>
</w:advanced_tag>

<!-- 嵌套标签 -->
<w:container_tag>
    <w:advanced_tag condition="$showContent">
        <p>嵌套内容</p>
    </w:advanced_tag>
</w:container_tag>
```

## 配置说明

### 标签库配置
在 `app/etc/taglib.php` 中配置标签库相关设置：

```php
'taglib' => [
    'prefix' => 'w', // 标签前缀
    'cache' => true, // 启用标签缓存
    'cache_time' => 3600, // 缓存时间
    'auto_register' => true, // 自动注册标签
    'strict_mode' => false // 严格模式
]
```

### 标签注册配置
```php
'taglibs' => [
    'custom_tag' => 'Your\\Module\\Taglib\\CustomTag',
    'advanced_tag' => 'Your\\Module\\Taglib\\AdvancedTag',
    'container_tag' => 'Your\\Module\\Taglib\\ContainerTag'
]
```

## 依赖关系

- Weline_Framework

## 版本信息

- 当前版本：1.0.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 标签接口详解

### 必需方法

#### `name()`: 标签名称
```php
static function name(): string
{
    return 'my_tag'; // 标签名，使用时为 <w:my_tag>
}
```

#### `tag()`: 是否支持成对标签
```php
static function tag(): bool
{
    return true; // true支持 <tag></tag>，false只支持 <tag/>
}
```

#### `callback()`: 处理回调
```php
static function callback(): callable
{
    return function ($tag_key, $config, $tag_data, $attributes) {
        // 处理逻辑
        return $processed_content;
    };
}
```

### 可选方法

#### `attr()`: 属性定义
```php
static function attr(): array
{
    return [
        'required_attr' => true,   // 必需属性
        'optional_attr' => false   // 可选属性
    ];
}
```

#### `tag_start()` 和 `tag_end()`: 标签开始/结束处理
```php
static function tag_start(): bool
{
    return true; // 处理标签开始
}

static function tag_end(): bool
{
    return true; // 处理标签结束
}
```

#### `parent()`: 依赖管理
```php
static function parent(): ?string
{
    return 'parent_tag'; // 依赖父标签
}
```

## 标签处理机制

### 回调函数参数
```php
function ($tag_key, $config, $tag_data, $attributes) {
    // $tag_key: 标签键值（'tag', 'tag_start', 'tag_end'）
    // $config: 标签配置信息
    // $tag_data: 标签数据 [0=>完整标签, 1=>标签内容]
    // $attributes: 标签属性数组
}
```

### 标签处理流程
1. **标签解析**: 解析模板中的标签
2. **属性验证**: 检查必需属性
3. **依赖检查**: 验证标签依赖关系
4. **回调执行**: 执行标签处理回调
5. **内容替换**: 替换模板中的标签

## 依赖管理

### 单依赖
```php
static function parent(): ?string
{
    return 'container_tag';
}
```

### 多依赖
```php
static function parent(): ?string
{
    return 'parent_tag1,parent_tag2'; // 逗号分隔
}
```

### 依赖检测
```php
// 系统会自动检测循环依赖
// 确保父标签在子标签之前渲染
// 支持多层依赖关系
```

## 标签测试

### 单元测试
```php
use PHPUnit\Framework\TestCase;

class CustomTagTest extends TestCase
{
    public function testTagRendering()
    {
        $tag = new CustomTag();
        $callback = $tag::callback();
        
        $result = $callback('tag', [], ['<w:custom_tag>', '内容'], ['title' => '测试']);
        
        $this->assertStringContains('测试', $result);
        $this->assertStringContains('内容', $result);
    }
    
    public function testRequiredAttributes()
    {
        $tag = new CustomTag();
        $attrs = $tag::attr();
        
        $this->assertTrue($attrs['title']); // 必需属性
        $this->assertFalse($attrs['class']); // 可选属性
    }
}
```

### 集成测试
```php
// 测试标签在模板中的渲染
public function testTemplateRendering()
{
    $template = '<w:custom_tag title="测试标题">测试内容</w:custom_tag>';
    $expected = '<div class="custom-tag" title="测试标题">测试内容</div>';
    
    $result = $this->renderTemplate($template);
    $this->assertEquals($expected, $result);
}
```

## 性能优化

### 1. 标签缓存
- 启用标签缓存机制
- 合理设置缓存时间
- 及时清理过期缓存

### 2. 标签优化
- 避免复杂的标签逻辑
- 减少标签嵌套层级
- 优化标签属性处理

### 3. 内存优化
- 及时释放标签资源
- 避免内存泄漏
- 优化标签实例化

## 最佳实践

### 1. 标签设计
- 标签名称要有意义
- 属性设计要合理
- 支持多种使用方式

### 2. 代码质量
- 完善的错误处理
- 详细的文档说明
- 充分的测试覆盖

### 3. 性能考虑
- 避免复杂的计算
- 合理使用缓存
- 优化渲染性能

### 4. 维护性
- 清晰的代码结构
- 良好的注释
- 版本兼容性

## 常见问题

### Q: 如何创建自闭合标签？
A: 设置 `tag_self_close()` 返回 `true`，并在回调中处理自闭合逻辑。

### Q: 如何处理标签嵌套？
A: 使用 `parent()` 方法定义依赖关系，系统会自动处理渲染顺序。

### Q: 如何验证标签属性？
A: 在 `attr()` 方法中定义属性要求，系统会自动验证。

### Q: 如何调试标签问题？
A: 启用调试模式，查看标签处理日志，使用测试工具验证。

### Q: 如何优化标签性能？
A: 启用缓存、减少复杂计算、优化属性处理逻辑。 