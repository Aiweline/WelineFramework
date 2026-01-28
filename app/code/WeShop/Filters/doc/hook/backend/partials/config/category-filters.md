# Hook: WeShop_Filters::backend::partials::config::category-filters

## 说明

在分类编辑页面配置可筛选属性。

## 位置

后台分类编辑表单

## 类型

普通 Hook

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$category` | array | 分类数据 |
| `$availableAttributes` | array | 可用属性列表 |
| `$configuredFilters` | array | 已配置的筛选 |

## 使用示例

```php
<div class="category-filters-config">
    <h3><?= __('筛选配置') ?></h3>
    <table>
        <thead>
            <tr>
                <th><?= __('属性') ?></th>
                <th><?= __('启用') ?></th>
                <th><?= __('排序') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($availableAttributes as $attr): ?>
            <tr>
                <td><?= $attr['label'] ?></td>
                <td><input type="checkbox" name="filter[<?= $attr['code'] ?>][enabled]"></td>
                <td><input type="number" name="filter[<?= $attr['code'] ?>][sort]"></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
```

## 相关 Hook

- `WeShop_Filters::frontend::partials::filters::container`
