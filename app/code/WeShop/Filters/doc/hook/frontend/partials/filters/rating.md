# Hook: WeShop_Filters::frontend::partials::filters::rating

## 说明

用户评分筛选组件，显示星级评分选项。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$ratingOptions` | array | 评分选项 |
| `$currentRating` | int | 当前选中评分 |

## 使用示例

```php
<div class="rating-filter">
    <h4><?= __('评分') ?></h4>
    <?php foreach ($ratingOptions as $option): ?>
        <label>
            <input type="radio" name="rating" value="<?= $option['value'] ?>">
            <?= str_repeat('★', $option['value']) ?> 及以上 (<?= $option['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
