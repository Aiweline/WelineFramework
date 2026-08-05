---
name: framework-taglib-development
description: 如何新增/修改 Weline Taglib 标签，以及强制同步标签映射目录。
---

# Taglib 标签开发与目录维护

本文件是 `framework-taglib-catalog` 技能的开发篇。创建或变更标签时必须同时遵守场景映射技能与本流程。

## 何时新建标签

Taglib 适合扩展模板声明语义。优先判断：

| 需求 | 优先方案 |
|---|---|
| 页面骨架 | layout |
| 局部片段 | partial |
| 基础 UI 原语 | component（`w-*`） |
| 可运营内容块 | widget |
| 需要新的模板语法/领域选择器协议 | **Taglib** |

## 实现契约

1. 类放在拥有该领域的模块：`app/code/{Vendor}/{Module}/Taglib/YourTag.php`
2. 实现 `Weline\Framework\Taglib\TaglibInterface`（推荐；勿依赖未发布的运行时细节仅为满足接口）
3. 必须实现：`name()`、`tag()`、`attr()`、`callback()`、`document()`、`tag_start()`、`tag_end()`、`tag_self_close()`、`tag_self_close_with_attrs()`、`parent()`
4. `name()` 使用稳定的 `domain:entity:action` 风格（如 `websites:website:select`）
5. `document()` 写清用途与最小示例；用户可见文案走 `__()` / `<lang>`
6. 禁止在标签属性协议里鼓励模板写 `<?= ?>`；动态值走 AttributeCodeCompiler / 框架约定
7. 跨模块读数据用 `w_query` / 已发布 Interface，不要在标签里 `use` 对方 Model/Service

### 最小骨架

```php
<?php
declare(strict_types=1);

namespace Weline\YourModule\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

class YourSelect implements TaglibInterface
{
    public static function name(): string
    {
        return 'yourmodule:entity:select';
    }

    public static function tag(): bool
    {
        return false;
    }

    public static function attr(): array
    {
        return [
            'id' => true,
            'name' => true,
            'value' => false,
        ];
    }

    public static function tag_start(): bool { return false; }
    public static function tag_end(): bool { return false; }
    public static function tag_self_close(): bool { return true; }
    public static function tag_self_close_with_attrs(): bool { return true; }
    public static function parent(): ?string { return null; }

    public static function callback(): callable
    {
        return static function ($tag_key, $config, $tag_data, $attributes): string {
            // 返回编译期 PHP/HTML 字符串
            return '';
        };
    }

    public static function document(): string
    {
        return '<w:yourmodule:entity:select id="x" name="x" />';
    }
}
```

模板使用：

```phtml
<w:yourmodule:entity:select id="x" name="x" value="" />
```

## 收集与缓存

```bash
php bin/w taglib:collect
php bin/w taglib:collect Weline_YourModule
```

- `setup:upgrade` 也会触发 taglib registry collect（见 `Weline_Framework_Setup::collect_taglib_registry`）
- 收集后会清理 taglib / template 相关缓存；不要手改 `generated/` 或收集产物
- WLS 验证：代码变更后对测试实例 `server:reload {instance}`

## 强制文档同步（完成门禁）

新增、更名、删除、改属性语义或改推荐用法后，**同一任务内**必须：

1. 更新 `dev/ai/skills/framework-taglib-catalog/tag-catalog.md`
   - 模块表中的标签名、类、属性、场景说明
2. 若属于选择器/表单/布局/控件场景，更新 `dev/ai/skills/framework-taglib-catalog/SKILL.md` 的「场景 → 标签」表
3. 如有模块专项文档，在 `app/code/.../doc/` 增加交叉引用
4. 过时示例文档不得与目录冲突；冲突时以源码 + `tag-catalog.md` 为准并修正旧文

未完成以上同步，不得将「新增标签」任务标为完成。

## 验证

1. `php bin/w taglib:collect [Module]` 成功
2. 后台 Taglib 列表或模板编译能识别新标签（无未知标签错误）
3. 在真实页面用对应 `w:` 标签渲染；选择器类验证选项与零号站点/语言等边界
4. 确认 `tag-catalog.md` 与 `SKILL.md` 已含新条目

## 参考

- `app/code/Weline/Framework/doc/2-快速开始/06-自定义标签.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Taglib/doc/README.md`
- 成熟选择器范例：`Weline\Websites\Taglib\WebsiteSelect`、`Weline\I18n\Taglib\LanguageSelect`
