# Hook: WeShop_Filters::frontend::partials::filters::attribute-group

## 说明

按属性组展示筛选属性。

## 位置

筛选组区域

## 类型

普通 Hook

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$attributeGroup` | array | 属性组信息 |
| `$attributes` | array | 组内属性列表 |

## 使用示例

```php
<div class="attribute-group">
    <h4><?= $attributeGroup['name'] ?></h4>
    <?php foreach ($attributes as $attribute): ?>
        <?= $this->hook('WeShop_Filters::frontend::partials::filters::eav-attribute', ['attribute' => $attribute]) ?>
    <?php endforeach; ?>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::eav-attribute`
