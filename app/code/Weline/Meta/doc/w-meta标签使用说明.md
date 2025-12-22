# w:meta 标签使用说明

## 概述

`w:meta` 标签用于显示和翻译 meta 信息，支持 scope 属性用于区分不同范围的配置。

## 基本语法

### 1. 直接显示翻译值（不写 type）

```html
<w:meta>info.name</w:meta>
<w:meta prefix="theme.component.pagination">info.name</w:meta>
```

### 2. 显示翻译按钮（type="translate"）

```html
<w:meta type="translate">info.name</w:meta>
<w:meta type="translate" prefix="theme.component.pagination">info.name</w:meta>
```

### 3. 使用 scope 属性区分配置范围

```html
<!-- 使用字符串 scope -->
<w:meta type="translate" scope="homepage">info.name</w:meta>

<!-- 使用 PHP 变量 scope -->
<w:meta type="translate" scope="<?= $theme->getScope() ?>">info.name</w:meta>
<w:meta type="translate" scope="{{theme.scope}}">info.name</w:meta>
```

## 属性说明

| 属性 | 类型 | 必填 | 默认值 | 说明 |
|------|------|------|--------|------|
| `type` | string | 否 | - | 设置为 `translate` 时显示翻译按钮和模态框 |
| `prefix` | string | 否 | - | meta key 的前缀，用于自动补全路径 |
| `scope` | string/PHP变量 | 否 | `default` | 用于区分不同范围的配置，支持 PHP 变量 |

## Scope 说明

`scope` 属性用于区分同一个主题在不同地方使用时的不同配置值。例如：

- 同一个主题可以在首页、产品页、详情页等不同地方使用
- 每个地方可以有不同的配置值
- `scope` 用于区分这些不同的配置范围

### Scope 默认值

如果不填写 `scope` 属性，默认值为 `default`。

### Scope 存储格式

翻译值会按照以下格式存储：

- 不带 scope：`@meta::theme.component.pagination.info.name`
- 带 scope：`@meta::theme.component.pagination.info.name|scope:homepage`

### Scope 读取优先级

1. 优先读取带 scope 的翻译值
2. 如果不存在，读取不带 scope 的默认值

## 使用示例

### 示例 1：基本使用

```html
<!-- 直接显示翻译值 -->
<w:meta prefix="theme.component.pagination">info.name</w:meta>

<!-- 显示翻译按钮 -->
<w:meta type="translate" prefix="theme.component.pagination">info.name</w:meta>
```

### 示例 2：使用 scope

```html
<!-- 首页配置 -->
<w:meta type="translate" scope="homepage" prefix="theme.component.pagination">info.name</w:meta>

<!-- 产品页配置 -->
<w:meta type="translate" scope="product" prefix="theme.component.pagination">info.name</w:meta>

<!-- 使用 PHP 变量 -->
<w:meta type="translate" scope="<?= $pageType ?>" prefix="theme.component.pagination">info.name</w:meta>
```

### 示例 3：在组件预览中使用

```php
<w:meta type="translate" prefix="<?= $metaKeyPrefix ?>" scope="<?= $theme->getScope() ?>">info.name</w:meta>
```

## 注意事项

1. **Scope 支持 PHP 变量**：`scope` 属性支持 PHP 变量，如 `<?= $theme->getScope() ?>` 或 `{{theme.scope}}`
2. **默认值回退**：如果带 scope 的翻译值不存在，会自动回退到默认值（不带 scope）
3. **路径补全**：可以省略 `@meta` 前缀，标签会自动补全
4. **翻译存储**：翻译值存储在 I18n 字典表中，支持多语言

## 技术实现

- 翻译值存储在 `i18n_locale_dictionary` 表中
- 使用 MD5 哈希作为唯一标识
- 支持多语言翻译管理
- 翻译模态框使用 Bootstrap Offcanvas 组件
