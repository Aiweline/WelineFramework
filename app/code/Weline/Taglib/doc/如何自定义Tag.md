# 如何自定义 Weline Taglib 标签

本指南介绍如何在 Weline 框架中自定义视图标签（Taglib），实现模块化、可复用的前端组件。

---

## 1. 新建 Taglib 类

在 `app\code\Weline\Taglib` 目录下新建 PHP 类，如：
```php
namespace Weline\Taglib;

class MyTag extends \Weline\Taglib\Taglib
{
    public function render($params = [])
    {
        // 处理标签逻辑
        return '<div>' . ($params['content'] ?? '') . '</div>';
    }
}
```

## 2. 注册自定义标签

在模块注册文件或 Taglib 配置中声明新标签：
```php
// 注册标签名与类的映射
return [
    'my-tag' => \Weline\Taglib\MyTag::class,
];
```

## 3. 视图中使用自定义标签

在 phtml 文件中直接使用：
```phtml
<w:my-tag content="Hello Weline!" />
```

## 4. 支持属性、嵌套与扩展

- 标签支持传递属性（如 content、class、style 等）
- 可在 render 方法中处理嵌套标签、子内容
- 支持继承 Taglib 基类，实现更复杂的标签逻辑

## 5. 推荐实践

- 标签命名建议统一使用 w: 前缀
- 逻辑建议拆分为独立类，便于维护和复用
- 可结合 block/template 实现更复杂的页面结构

---

如需扩展更多功能，可参考框架内置标签实现方式。
