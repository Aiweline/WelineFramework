# Hook: WeShop_Filters::frontend::partials::filters::brand

## 说明

品牌筛选组件，支持EAV属性或独立品牌模块。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$brands` | array | 品牌列表 |
| `$selectedBrands` | array | 已选品牌 |

## 使用示例

```php
<div class="brand-filter">
    <h4><?= __('品牌') ?></h4>
    <?php foreach ($brands as $brand): ?>
        <label>
            <input type="checkbox" name="brand[]" value="<?= $brand['id'] ?>">
            <?= $brand['name'] ?> (<?= $brand['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
