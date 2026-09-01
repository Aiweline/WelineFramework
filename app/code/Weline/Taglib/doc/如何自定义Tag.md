# 如何自定义 Weline Taglib 标签

权威开发与目录维护流程由本模块文档和 MCP 动态检索共同提供：

1. **场景映射（先查再用）**：`app/code/Weline/Taglib/doc/场景映射表.md`
2. **全量标签目录**：`app/code/Weline/Taglib/doc/标签全量索引.md`（`php bin/w taglib:catalog:generate`）
3. **开发与维护门禁**：`app/code/Weline/Taglib/doc/README.md`

## 摘要

1. 在**拥有该领域数据的模块**下创建 `Taglib/YourTag.php`
2. 实现 `Weline\Framework\Taglib\TaglibInterface`
3. `name()` 使用稳定名（如 `websites:website:select`）
4. 运行 `php bin/w taglib:collect`（或含收集的 `setup:upgrade`）
5. 模板使用 `<w:your-tag .../>`
6. **同一任务内**更新 [场景映射表.md](./场景映射表.md) 及相关专题文档，并运行 `php bin/w taglib:catalog:generate`

## 不要做

- 不要用手写 `<select>` + 跨模块 Model 替代已有官方选择器标签
- 不要改 `generated/` 或收集产物
- 不要在 `<w:*>` 属性里写 `<?= ?>`
- 不要把所有 UI 都做成 Taglib（优先 layout / partial / component / widget）
- **不要在 Taglib `callback()` / `runtime_callback()` 返回的 HTML 字符串里写裸 `@static(...)`**

## 静态资源：`@static` 与 Taglib callback（硬规则）

### 现象

页面加载即 404，Network 出现类似：

```text
GET .../admin-prefix/@static(Weline_Module::css/foo.css) 404
```

常见于 `<w:product:admin:picker>` 等**在 callback 里拼接 `<link>` / `<script>`** 的标签；`.phtml` 顶部的 `@static` 可能已正常，404 来自 callback 输出。

### 原因

- `.phtml` 里的 `@static(...)` 由 Taglib **编译期 AST** 解析为 `/Vendor/Module/view/statics/...`。
- Taglib `callback()` 返回的是**普通 PHP 字符串**，编译器**不会**对其中的 `@static` 二次解析；运行期浏览器把 `@static(...)` 当相对路径请求 → 404。

### 正确做法

在 callback 执行时解析静态 URL（与 `Weline\I18n\Taglib\Local::resolveModuleStaticUrl` 同款）：

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

private static function resolveModuleStaticUrl(string $source): string
{
    $template = ObjectManager::getInstance(Template::class);

    return (string) $template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
}

// callback 内：
$cssUrl = htmlspecialchars(
    self::resolveModuleStaticUrl('Weline_Module::css/widget.css') . '?v=20260827-1',
    ENT_QUOTES,
);
$html[] = '<link rel="stylesheet" href="' . $cssUrl . '" data-no-extract="true">';
```

或在 callback 输出中嵌入运行期 PHP（适合需按请求上下文解析时）：

```php
$html[] = '<link rel="stylesheet" href="<?php echo htmlspecialchars($this->fetchTagSource(\Weline\Framework\View\Data\DataInterface::dir_type_STATICS, \'Weline_Module::css/widget.css\'), ENT_QUOTES); ?>" data-no-extract="true">';
```

### 禁止

```php
// ❌ 会原样进 com_*.phtml，浏览器 404
return '<link href="@static(Weline_Module::css/widget.css)">';
```

### 验证

- 契约测试：`assertStringNotContainsString('@static(Weline_Module::', $taglibSource)`
- 浏览器 Network：静态资源 URL 为 `/Weline/Module/view/statics/...`，不得含 `@static(` 字面量

## 深入阅读

- `app/code/Weline/Framework/doc/2-快速开始/06-自定义标签.md`
- `app/code/Weline/Framework/View/doc/Taglib/使用指南.md`
- `app/code/Weline/Taglib/doc/README.md`
- 范例：`Weline\Websites\Taglib\WebsiteSelect`、`Weline\I18n\Taglib\LanguageSelect`
