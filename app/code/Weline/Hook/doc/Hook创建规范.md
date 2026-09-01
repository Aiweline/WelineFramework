# Hook 创建规范

> `setup:upgrade` / `setup:upgrade --route` 会校验 Hook **三件套**；缺规约将**致命中断**（见 `Weline\Hook\HookRegistry`）。

## 三件套（缺一不可）

| 步骤 | 位置 | 说明 |
|------|------|------|
| 1 规约声明 | `{OwnerModule}/hook.php` | 键名 `{Module}::{area}::{...}`；含 `name`、`description`、`doc`（相对 `doc/hook/`） |
| 2 规约文档 | `{OwnerModule}/doc/hook/{doc}.md` | Hook 名称、用途、实现路径示例 |
| 3 实现模板 | `{ImplModule}/view/hooks/{HookPath}.phtml` | `::` → `/`；文件头 Hook 元数据注释 |

**所有权**：Hook **名**由**定义方模块**（Owner）在 `hook.php` 声明；**实现**可在其他模块的 `view/hooks/` 下挂载。

## 命名格式

```text
{ModuleName}::{area}::{type}::{component}::{position}
```

示例：

- `Weline_Product::frontend::product::detail::after-add-to-cart`
- `Weline_Theme::frontend::layouts::base::head-after`

短名 Hook（如 `header-currency-switcher`）由 Theme 在 `hook.php` 中声明，模板用 `<w:hook>header-currency-switcher</w:hook>`。

## hook.php 示例

参照 [Product/hook.php](../../Product/hook.php)：

```php
return [
    'Weline_Product::frontend::product::detail::after-add-to-cart' => [
        'name' => (string)__('商品详情加购之后'),
        'description' => (string)__('加购区域之后的扩展槽。'),
        'doc' => 'frontend/product/detail/after-add-to-cart.md',
    ],
];
```

## doc/hook/*.md 示例

参照 [Product/doc/hook/.../after-add-to-cart.md](../../Product/doc/hook/frontend/product/detail/after-add-to-cart.md)：

- Hook 名称（与 `hook.php` 键一致）
- 功能说明
- 实现文件路径：`view/hooks/Weline_Product/frontend/product/detail/after-add-to-cart.phtml`

## view/hooks 路径规则

- Hook 名：`Weline_Theme::frontend::layouts::base::head-after`
- 文件：`view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml`
- 规则：`::` → 目录分隔符 `/`

## 反例（禁止）

- 只有 `view/hooks/*.phtml`，Owner 模块无 `hook.php` 条目
- `hook.php` 的 `doc` 字段与 `doc/hook/` 实际路径不一致
- 在**实现方**模块的 `hook.php` 声明**他人拥有**的 Theme 布局 Hook（应改 Owner 或扩展现有 Hook）
- 使用 Hook 却未在 `setup:upgrade` 前补全 doc

## 验证

```bash
php bin/w setup:upgrade --route
```

失败时查看「Hook 规约缺失」列表，按提示补 `hook.php` 与 `doc/hook/*.md`。

## 相关

- [Theme Hook 使用指南](../../Theme/doc/Hook使用指南.md)
- [Hook点位索引.md](../../Theme/doc/Hook点位索引.md)
- [AI硬规则索引.md](../../Ai/doc/AI硬规则索引.md)
