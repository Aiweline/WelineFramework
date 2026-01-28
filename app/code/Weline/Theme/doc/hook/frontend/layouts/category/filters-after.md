# Hook: Weline_Theme::frontend::layouts::category::filters-after

## 说明

在分类筛选区域之后触发，允许其他模块在筛选区域结束处注入内容。

## 位置

分类页面筛选侧边栏的底部，在筛选内容之后

## 类型

普通 Hook - 允许追加内容

## 使用示例

```php
// 在其他模块的 hook 模板中
<div class="filter-help-text">
    <p>找不到想要的商品？<a href="/contact">联系客服</a></p>
</div>
```

## 可用数据

| 变量名 | 类型 | 说明 |
|--------|------|------|
| `$category` | array | 当前分类数据 |
| `$request` | Request | 请求对象 |

## 注意事项

1. 此 Hook 在筛选主内容之后渲染
2. 适合用于添加帮助链接、反馈入口等

## 相关 Hook

- `Weline_Theme::frontend::layouts::category::filters-sidebar`
- `Weline_Theme::frontend::layouts::category::filters-before`
