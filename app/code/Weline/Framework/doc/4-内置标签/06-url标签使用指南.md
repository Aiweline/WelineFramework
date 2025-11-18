# url 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `url`、`frontend-url`、`backend-url`、`admin-url` 标签的使用方法。这些标签用于在模板中生成 URL 链接。

## 什么是 url 标签

`url` 标签是 WelineFramework 提供的 URL 生成标签，用于在模板中生成各种类型的 URL 链接。框架提供了多个 URL 标签，分别用于生成不同类型的 URL。

## 为什么需要 url 标签

在模板中使用 URL 标签提供了以下优势：

- **URL 生成**：自动生成正确的 URL 链接
- **路由支持**：支持框架的路由系统
- **参数传递**：支持 URL 参数传递
- **类型区分**：区分前端、后端、API 等不同类型的 URL

## 标签类型

框架提供以下 URL 标签：

- **`url`**：生成通用 URL（根据当前上下文自动判断）
- **`frontend-url`**：生成前端 URL
- **`backend-url`** 或 **`admin-url`**：生成后端/管理后台 URL
- **`api`**：生成 API URL
- **`backend-api`**：生成后端 API URL

## 语法格式

### 1. `<url>` 标签格式

```html
<url path="/module/controller/action"/>
<url path="/module/controller/action" params="id=1&name=test"/>
```

### 2. `@url()` 格式

```html
@url(/module/controller/action)
@url(/module/controller/action|['id' => 1, 'name' => 'test'])
```

### 3. `@url{}` 格式

```html
@url{/module/controller/action}
@url{/module/controller/action|['id' => 1, 'name' => 'test']}
```

## 使用方法

### url 标签

生成通用 URL（根据当前上下文自动判断）：

```html
<!-- 基本用法 -->
<a href="@url('/')">首页</a>
<a href="@url('/product/list')">商品列表</a>

<!-- 带参数 -->
<a href="@url('/product/view', ['id' => '{{product.id}}'])">查看商品</a>
```

### frontend-url 标签

生成前端 URL：

```html
<!-- 基本用法 -->
<a href="@frontend-url('/')">首页</a>
<a href="@frontend-url('/product/list')">商品列表</a>

<!-- 带参数 -->
<a href="@frontend-url('/product/view', ['id' => '{{product.id}}'])">查看商品</a>
```

### backend-url 或 admin-url 标签

生成后端/管理后台 URL：

```html
<!-- 基本用法 -->
<a href="@backend-url('/admin/dashboard')">管理后台</a>
<a href="@admin-url('/admin/user/list')">用户列表</a>

<!-- 带参数 -->
<a href="@backend-url('/admin/user/edit', ['id' => '{{user.id}}'])">编辑用户</a>
```

### api 标签

生成 API URL：

```html
<!-- 基本用法 -->
<script>
    var apiUrl = '@api(/api/user/info)';
    fetch(apiUrl).then(response => response.json());
</script>

<!-- 带参数 -->
<script>
    var apiUrl = '@api(/api/product/list, ["page" => 1, "size" => 20])';
</script>
```

### backend-api 标签

生成后端 API URL：

```html
<!-- 基本用法 -->
<script>
    var apiUrl = '@backend-api(/admin/api/backend/user/list)';
    fetch(apiUrl).then(response => response.json());
</script>
```

## 参数传递

### 使用 @url() 格式传递参数

```html
<!-- 数组参数 -->
@url(/product/view|['id' => 1, 'name' => 'test'])

<!-- 使用模板变量 -->
@url(/product/view|['id' => '{{product.id}}', 'name' => '{{product.name}}'])
```

**语法说明**：
- 使用 `|` 分隔路径和参数
- 参数使用 PHP 数组格式
- 可以使用模板变量

### 使用标签属性传递参数

```html
<url path="/product/view" params="id=1&name=test"/>
```

## 完整示例

### 示例 1：导航菜单

```html
<nav>
    <ul>
        <li><a href="@url('/')">首页</a></li>
        <li><a href="@url('/product/list')">商品列表</a></li>
        <li><a href="@url('/about')">关于我们</a></li>
        <li><a href="@url('/contact')">联系我们</a></li>
    </ul>
</nav>
```

### 示例 2：商品列表

```html
<div class="product-list">
    <foreach name="products" item="product">
        <div class="product">
            <h3><var>product.name</var></h3>
            <p>价格：¥<var>product.price</var></p>
            <a href="@url('/product/view', ['id' => '{{product.id}}'])">查看详情</a>
            <a href="@url('/product/add-to-cart', ['id' => '{{product.id}}'])">加入购物车</a>
        </div>
    </foreach>
</div>
```

### 示例 3：分页链接

```html
<div class="pagination">
    <if condition="$currentPage > 1">
        <a href="@url('/product/list', ['page' => '{{currentPage - 1}}'])">上一页</a>
    </if>
    
    <span>第 <var>currentPage</var> 页，共 <var>totalPages</var> 页</span>
    
    <if condition="$currentPage < $totalPages">
        <a href="@url('/product/list', ['page' => '{{currentPage + 1}}'])">下一页</a>
    </if>
</div>
```

### 示例 4：管理后台链接

```html
<div class="admin-menu">
    <ul>
        <li><a href="@backend-url('/admin/dashboard')">仪表盘</a></li>
        <li><a href="@backend-url('/admin/user/list')">用户管理</a></li>
        <li><a href="@backend-url('/admin/product/list')">商品管理</a></li>
        <li><a href="@backend-url('/admin/order/list')">订单管理</a></li>
    </ul>
</div>
```

### 示例 5：AJAX 请求

```html
<script>
    // 前端 API
    function loadProducts() {
        var url = '@api(/api/product/list, ["page" => 1, "size" => 20])';
        fetch(url)
            .then(response => response.json())
            .then(data => {
                // 处理数据
            });
    }
    
    // 后端 API
    function loadUsers() {
        var url = '@backend-api(/admin/api/backend/user/list)';
        fetch(url)
            .then(response => response.json())
            .then(data => {
                // 处理数据
            });
    }
</script>
```

## URL 路径格式

### 路由格式

URL 路径支持框架的路由格式：

```html
<!-- 模块/控制器/操作 -->
@url(/module/controller/action)

<!-- 路由别名 -->
@url(/product-list)

<!-- 完整路径 -->
@url(/product/list?page=1)
```

### 参数格式

参数可以使用数组或查询字符串格式：

```html
<!-- 数组格式（推荐） -->
@url(/product/view|['id' => 1, 'name' => 'test'])

<!-- 查询字符串格式 -->
<url path="/product/view" params="id=1&name=test"/>
```

## 注意事项

### 1. 路径格式

- 路径可以以 `/` 开头，也可以不以 `/` 开头
- 框架会自动处理路径格式
- 支持路由别名和完整路径

### 2. 参数传递

- 数组格式：`['key' => 'value']`
- 查询字符串格式：`key=value&key2=value2`
- 可以使用模板变量

### 3. URL 类型

- `url`：根据当前上下文自动判断
- `frontend-url`：强制生成前端 URL
- `backend-url` / `admin-url`：强制生成后端 URL
- `api`：生成 API URL
- `backend-api`：生成后端 API URL

### 4. 安全性

- URL 标签会自动处理特殊字符
- 参数会被正确编码
- 防止 XSS 攻击

## 常见问题

### Q1: URL 生成不正确？

**A**: 检查以下几点：
1. 确保路径格式正确
2. 检查路由配置
3. 确保模块、控制器、操作存在

### Q2: 参数未传递？

**A**: 检查以下几点：
1. 确保参数格式正确
2. 检查参数名是否正确
3. 确保模板变量已传递

### Q3: 如何生成带锚点的 URL？

**A**: 在路径后添加锚点：

```html
<a href="@url('/product/list')#section1">跳转到章节1</a>
```

## 相关文档

- [var 标签使用指南](02-var标签使用指南.md)
- [if 标签使用指南](03-if-elseif-else标签使用指南.md)

