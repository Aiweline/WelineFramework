# Hook: WeShop_Filters::frontend::partials::filters::new

## 说明

新品筛选组件。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$newProductCount` | int | 新品数量 |
| `$isNewSelected` | bool | 是否已选新品筛选 |

## 使用示例

```php
<div class="new-filter">
    <label>
        <input type="checkbox" name="new" value="1" <?= $isNewSelected ? 'checked' : '' ?>>
        <?= __('新品') ?> (<?= $newProductCount ?>)
    </label>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
