# Taglib 标签库开发技能

## 触发关键词

Taglib, 标签, 标签库, tag, 自定义标签, TaglibInterface, 模板标签, <w:xxx>, @tag, 标签解析, callback, taglib:collect, 创建标签

## 适用场景

- 创建自定义模板标签
- 使用框架内置标签（lang, block, if, foreach 等）
- 标签解析和渲染
- 标签属性定义

---

## 1. Taglib 接口规范

所有自定义标签必须实现 `Weline\Taglib\TaglibInterface` 接口：

```php
interface TaglibInterface
{
    static function name(): string;              // 标签名称
    static function tag(): bool;                 // 是否支持成对标签
    static function attr(): array;               // 属性定义
    static function tag_start(): bool;           // 是否处理标签开始
    static function tag_end(): bool;             // 是否处理标签结束
    static function callback(): callable;        // 匹配处理回调
    static function tag_self_close(): bool;      // 是否支持自闭合
    static function tag_self_close_with_attrs(): bool;  // 自闭合是否带属性
    static function parent(): ?string;           // 父标签依赖
    static function document(): string;          // 标签文档
}
```

---

## 2. 创建自定义标签

### 2.1 基础标签模板

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Taglib;

use Weline\Taglib\TaglibInterface;

class YourTag implements TaglibInterface
{
    // 标签名称（模板中：<your-tag> 或 <w:your-tag>）
    public static function name(): string
    {
        return 'your-tag';  // 推荐小写连字符命名
    }

    // 是否支持成对标签 <tag>content</tag>
    public static function tag(): bool
    {
        return true;
    }

    // 属性定义：true=必需，false=可选
    public static function attr(): array
    {
        return [
            'required-attr' => true,   // 必需属性
            'optional-attr' => false,  // 可选属性
        ];
    }

    // 标签开始处理（一般为 false）
    public static function tag_start(): bool
    {
        return false;
    }

    // 标签结束处理（一般为 false）
    public static function tag_end(): bool
    {
        return false;
    }

    // 回调处理函数
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // $tag_key: 'tag', 'tag-start', 'tag-end', 'tag-self-close-with-attrs'
            // $config: 标签配置
            // $tag_data: [0]=原始标签, [1]=属性/内联内容, [2]=子内容
            // $attributes: 解析后的属性数组
            
            $content = $tag_data[2] ?? '';
            $myAttr = $attributes['required-attr'] ?? '';
            
            return "<div class='my-tag' data-attr='{$myAttr}'>{$content}</div>";
        };
    }

    // 是否支持自闭合 <tag/>
    public static function tag_self_close(): bool
    {
        return true;
    }

    // 自闭合标签是否支持属性
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    // 父标签依赖（可选）
    public static function parent(): ?string
    {
        return null;  // 无依赖
        // return 'parent-tag';  // 单个依赖
        // return 'tag1,tag2';   // 多个依赖（逗号分隔）
    }

    // 标签使用文档
    public static function document(): string
    {
        return <<<DOC
## your-tag 标签

### 用法
<your-tag required-attr="value">内容</your-tag>

### 属性
- required-attr: 必需，描述...
- optional-attr: 可选，描述...
DOC;
    }
}
```

### 2.2 带命名空间的标签

```php
public static function name(): string
{
    return 'theme:css';  // 使用 <theme:css> 或 <w:theme:css>
}
```

---

## 3. callback 实现模式

### 3.1 返回 HTML

```php
public static function callback(): callable
{
    return function ($tag_key, $config, $tag_data, $attributes) {
        $content = $tag_data[2] ?? '';
        return "<div class='wrapper'>{$content}</div>";
    };
}
```

### 3.2 返回 PHP 代码（编译时）

```php
public static function callback(): callable
{
    return function ($tag_key, $config, $tag_data, $attributes) {
        $source = $attributes['source'] ?? '';
        $content = $tag_data[2] ?? '';
        
        // 生成 PHP 代码
        return "<?php if (\\Weline\\Acl\\Taglib\\Acl::hasPermission('{$source}')): ?>" .
               $content .
               "<?php endif; ?>";
    };
}
```

### 3.3 使用 ObjectManager

```php
public static function callback(): callable
{
    return function ($tag_key, $config, $tag_data, $attributes) {
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);
        
        $content = trim($tag_data[2] ?? $tag_data[1] ?? '');
        
        try {
            $href = $template->fetchTagSource(DataInterface::dir_type_THEME, $content);
            return "<link href='{$href}' rel='stylesheet' type='text/css'/>";
        } catch (\Exception $e) {
            throw $e;
        }
    };
}
```

---

## 4. 模板中使用标签

### 4.1 成对标签

```html
<!-- 基础用法 -->
<lang>翻译文本</lang>

<!-- 带属性 -->
<acl source="Weline_Backend::setting">
    <div>受权限保护的内容</div>
</acl>

<!-- 带前缀 -->
<w:block class="Weline\Admin\Block\Backend\Page\Topbar"/>
```

### 4.2 自闭合标签

```html
<!-- 基础自闭合 -->
<file-manager target="#demo" title="文件管理器" path="uploads/"/>

<!-- 带前缀 -->
<w:widget type="header" name="default"/>
```

### 4.3 内联标签语法（@tag）

```html
<!-- 内联翻译 -->
@lang{允许的文件类型：}

<!-- 内联 URL -->
@backend-url('path/to/action')

<!-- 在属性中使用 -->
<form action="@backend-url('save')">
```

### 4.4 条件标签

```html
<if condition="empty($posts)">
    <p>暂无数据</p>
</if>

<if condition="meta.showHeader">
    <header>...</header>
</if>
```

### 4.5 Hook 和 Block 标签

```html
<!-- Hook 扩展点 -->
<w:hook>Weline_Theme::frontend::layouts::base::head-before</w:hook>

<!-- Block 渲染 -->
<w:block class="Weline\Theme\Block\Partials" area="frontend" type="head"/>

<!-- 模板包含 -->
<w:template>Weline_Admin::Backend/page-layout/main-content-before.phtml</w:template>
```

### 4.6 DataTable 组件标签

```html
<!-- 自动生成结构 -->
<w:d-table model="WeShop\Store\Model\Store" scope="store-listing"></w:d-table>

<!-- 手动配置 -->
<w:d-table model="Weline\Demo\Model\Demo" scope="demo-table" editable="true">
    <w:t-header>
        <w:field belong="t-header" name="id" sortable="true" width="80">ID</w:field>
        <w:field belong="t-header" name="name" sortable="true">名称</w:field>
    </w:t-header>
    <w:t-filter>
        <w:field belong="t-filter" name="name" type="text" placeholder="搜索名称"></w:field>
    </w:t-filter>
</w:d-table>
```

---

## 5. 标签注册流程

### 5.1 创建步骤

1. **创建 Taglib 类**
   ```
   app/code/Vendor/Module/Taglib/YourTag.php
   ```

2. **实现 TaglibInterface**

3. **收集标签**
   ```bash
   php bin/w taglib:collect
   # 或指定模块
   php bin/w taglib:collect -m Vendor_Module
   ```

4. **清理缓存**（自动）

### 5.2 标签注册表

收集后保存到 `generated/taglibs.php`：

```php
return [
    'tags' => [
        'tag-name' => [
            'tag' => true,
            'attr' => ['attr1' => true, 'attr2' => false],
            'tag-start' => false,
            'tag-end' => false,
            'tag-self-close' => true,
            'tag-self-close-with-attrs' => true,
            'is_custom' => true,
            'module_name' => 'Vendor_Module',
            'doc' => '标签文档...',
            'class' => 'Vendor\\Module\\Taglib\\YourTag',
            'parent' => null,
        ],
    ],
];
```

---

## 6. 父标签依赖

当标签需要嵌套在特定父标签内时：

```php
public static function parent(): ?string
{
    return 't-header,t-filter,d-form';  // 多个父标签用逗号分隔
}
```

示例：`<w:field>` 必须在 `<w:t-header>` 或 `<w:t-filter>` 内使用。

---

## 7. 典型示例

### 7.1 简单翻译标签

```php
class Local implements TaglibInterface
{
    public static function name(): string
    {
        return 'local';
    }

    public static function tag(): bool
    {
        return true;
    }

    public static function attr(): array
    {
        return ['model' => true, 'id' => true, 'field' => true];
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $model = $attributes['model'];
            $id = $attributes['id'];
            $field = $attributes['field'];
            return "<?= \\Weline\\I18n\\Taglib\\Local::getLocal('{$model}', '{$id}', '{$field}') ?>";
        };
    }
    
    // ... 其他方法
}
```

### 7.2 自闭合文件管理器标签

```php
class FileManager implements TaglibInterface
{
    public static function name(): string
    {
        return 'file-manager';
    }

    public static function tag(): bool
    {
        return false;  // 不支持成对标签
    }

    public static function tag_self_close(): bool
    {
        return true;  // 支持自闭合
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return true;  // 自闭合带属性
    }

    public static function attr(): array
    {
        return [
            'target' => true,
            'title' => false,
            'path' => false,
            'ext' => false,
        ];
    }
    
    // ... callback 等
}
```

### 7.3 权限控制标签

```php
class Acl implements TaglibInterface
{
    public static function name(): string
    {
        return 'acl';
    }

    public static function attr(): array
    {
        return ['source' => true];  // 权限源标识
    }

    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $source = $attributes['source'] ?? '';
            $content = $tag_data[2] ?? '';
            
            // 运行时权限检查
            return "<?php if (\\Weline\\Acl\\Taglib\\Acl::hasPermission('{$source}')): ?>" .
                   $content .
                   "<?php endif; ?>";
        };
    }
}
```

---

## 8. 注意事项

| 特性 | 说明 |
|------|------|
| **标签前缀** | 支持 `<tag>` 和 `<w:tag>` 两种写法 |
| **内联语法** | 支持 `@tag{...}` 和 `@tag(...)` |
| **编译时/运行时** | callback 可返回 HTML 或 PHP 代码 |
| **父标签依赖** | 通过 `parent()` 声明，系统自动排序 |
| **属性验证** | `attr()` 返回 `true` 表示必需属性 |
| **标签收集** | 修改后必须执行 `taglib:collect` |

---

## 9. 常见错误

### 9.1 忘记收集标签

```bash
# 创建或修改标签后必须执行
php bin/w taglib:collect
```

### 9.2 属性名大小写

```html
<!-- ❌ 错误：属性名应小写 -->
<file-manager Target="#demo"/>

<!-- ✅ 正确 -->
<file-manager target="#demo"/>
```

### 9.3 自闭合标签格式

```html
<!-- ❌ 错误：漏掉斜杠 -->
<file-manager target="#demo">

<!-- ✅ 正确 -->
<file-manager target="#demo"/>
```
