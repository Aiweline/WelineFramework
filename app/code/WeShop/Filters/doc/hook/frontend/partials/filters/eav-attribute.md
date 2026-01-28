# Hook: WeShop_Filters::frontend::partials::filters::eav-attribute

## 说明

动态EAV属性筛选组件，根据分类配置的可筛选属性动态渲染。

## 位置

筛选组区域

## 类型

Slot Hook - 允许替换

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$attribute` | array | 属性信息 |
| `$options` | array | 属性选项 |
| `$selectedValues` | array | 已选值 |
| `$attributeCode` | string | 属性代码 |

## 使用示例

```php
<div class="eav-attribute-filter" data-attribute="<?= $attributeCode ?>">
    <h4><?= $attribute['label'] ?></h4>
    <?php foreach ($options as $option): ?>
        <label>
            <input type="checkbox" name="attr[<?= $attributeCode ?>][]" value="<?= $option['value'] ?>">
            <?= $option['label'] ?> (<?= $option['count'] ?>)
        </label>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
- `WeShop_Filters::frontend::partials::filters::attribute-group`
