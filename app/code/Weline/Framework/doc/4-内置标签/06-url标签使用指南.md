# url 标签使用指南

## 摘要

模板中生成路由 URL 有**两种一等写法**，效果等价，按场景选用：

1. **XML 标签**：`<url>` / `<frontend-url>` / `<backend-url>` / `<admin-url>` / `<api>` / `<backend-api>`
2. **`@` 内联**：`@url(...)` / `@url{...}` / `@url{'...'}`（以及 `@frontend-url`、`@backend-url`、`@api` 等同族）

前端主题与业务模板**优先**使用 `@url{'path'}`（花括号 + 单引号路径）。禁止在 HTML 属性里硬编码 `/path`，也禁止用 `<?= $this->getUrl() ?>` 代替标签。`<?php ?>` 数据准备块里若必须先算出完整 URL 再注入变量，可保留 `$this->getUrl()` / `$this->getFrontendUrl()`。

权威对照：[Taglib 场景映射表](../../../Taglib/doc/场景映射表.md)。

## 为什么需要 url 标签

- 自动走框架路由与站点前缀（含语言/货币等上下文）
- 区分前台 / 后台 / API
- 参数编码与 XSS 防护由框架处理

## 标签族

| 名称 | 作用 |
|------|------|
| `url` | 按当前区域上下文生成 URL |
| `frontend-url` | 强制前台 URL |
| `backend-url` / `admin-url` | 强制后台 URL |
| `api` / `frontend-api` | API URL |
| `backend-api` | 后台 API URL |

## 两种用法（同等合法）

### A. XML 标签

适合独立输出、或需要 `path` / `params` 属性拆分的场景：

```html
<url path="product/list"/>
<url path="product/view" params="id=1&name=test"/>
<frontend-url path="/"/>
<backend-url path="admin/dashboard"/>
```

也支持标签体写法（少见，多用于把路径交给标签体）：

```html
<a href="<url>product/list</url>">商品列表</a>
```

### B. `@` 内联（推荐用于属性）

三种括号形态均可，**推荐花括号 + 引号路径**（与 Theme 现网一致）：

```html
<!-- 推荐：花括号 + 单引号 -->
<a href="@url{'product/list'}">商品列表</a>
<a href="@url{'customer/account/index'}#orders">我的订单</a>

<!-- 圆括号 -->
<a href="@url('product/list')">商品列表</a>
<a href="@url('/product/list')">商品列表</a>

<!-- 花括号无引号（字面路径） -->
<a href="@url{product/list}">商品列表</a>
```

变量路径（PHP 变量名，不要加引号包住变量）：

```html
<a href="@url{$guideRoute}">指南</a>
<a href="@url{$policyRoute}">政策</a>
```

带参数（`|` 右侧为 PHP 数组字面量）：

```html
<a href="@url{'product/view'|['id' => 1, 'name' => 'test']}">查看</a>
<a href="@url(/product/view|['id' => '{{product.id}}'])">查看</a>
```

同族内联：

```html
<a href="@frontend-url{'/'}">首页</a>
<a href="@backend-url{'admin/dashboard'}">后台</a>
<script>
  const api = "@api{'api/framework/query-bin'}";
  const backendApi = "@backend-api{'admin/api/backend/user/list'}";
</script>
```

> 写在 HTML 属性或 `<script>` 输出上下文中的 `@url{'...'}`，会在模板编译期展开为 `<?= $this->getUrl('...') ?>`，运行时写入页面。不要把 `@url` 塞进 `<?php $x = "..."; ?>` 字符串赋值。

## 选用建议

| 场景 | 写法 |
|------|------|
| `href` / `action` / `data-*-url` | `@url{'path'}` |
| 需要强制前台 | `@frontend-url{'path'}` |
| 后台链接 | `@backend-url{'path'}` / `@admin-url{'path'}` |
| fetch / Weline.Api endpoint | `@api{'...'}` |
| 独立块、属性拆分 | `<url path="..."/>` |
| 路径来自 PHP 变量 | `@url{$routeVar}` |

## 禁止

```html
<!-- 禁止：硬编码站点路径 -->
<a href="/blog">博客</a>
<form action="/search">

<!-- 禁止：HTML 属性里手写 getUrl -->
<a href="<?= $this->getUrl('cart') ?>">购物车</a>
```

应改为：

```html
<a href="@url{'blog'}">博客</a>
<form action="@url{'search'}">
<a href="@url{'cart'}">购物车</a>
```

JS 输出上下文（`<script>` 内）同样用 `@` 内联，编译后会变成 PHP echo 写入字符串：

```html
<script>
  const home = "@url{'/'}";
  const api = "@api{'api/framework/query-bin'}";
</script>
```

**不要**在 `<?php ?>` 赋值里写 `$url = "@url{'products'}";`（标签会落在 PHP 字符串字面量内，无法执行）。数据准备应二选一：

```php
// A. 只存路径，输出时用标签
$productPath = 'product/' . $productSlug;
```
```html
<a href="@url{$productPath}">详情</a>
```

```php
// B. 必须先持有完整 URL 时，PHP 块内调用 getUrl
$checkoutUrl = $this->getUrl('checkout');
```

静态资源（css/js/图片）用 `@static()` / `<css>` / `<js>` / `file` 标签，**不要**用 `url` 标签。

## 完整示例

### 导航

```html
<nav>
  <a href="@url{'/'}">首页</a>
  <a href="@url{'product/list'}">商品</a>
  <a href="@url{'contact'}">联系</a>
</nav>
```

### 表单

```html
<w:form action="@url{'search'}" method="get">
  <input name="q" type="search"/>
</w:form>
```

### AJAX

```html
<script>
  fetch("@api{'api/product/list'|['page' => 1]}")
    .then((r) => r.json());
</script>
```

### 后台

```html
<a href="@backend-url{'admin/user/list'}">用户</a>
```

## 路径与参数

- 路径可带或不带前导 `/`；框架会规范化
- 锚点写在标签外：`@url{'customer/account/index'}#orders`
- 查询串可写在路径后，或用 `|['k' => 'v']` 数组参数

## 常见问题

**Q: 生成结果不对？** 检查路由是否存在、是否误用了后台/前台族标签。

**Q: 动态 slug？** 先拼 `$path`，再用 `@url{$path}`，不要把 PHP 表达式塞进引号路径里。

**Q: 和 `$this->getUrl` 的关系？** 标签编译结果就是调用 `getUrl` / `getFrontendUrl` / `getApi` 等；模板层请写标签，不要直接调 PHP。

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [static 标签使用指南](09-static-template-js-css标签使用指南.md)
- [Taglib 场景映射表](../../../Taglib/doc/场景映射表.md)
