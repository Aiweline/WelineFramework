# 框架内置标签使用指南

## 摘要

本文档目录包含 WelineFramework 框架所有内置标签的详细使用文档。每个标签都有独立的使用指南，包含语法说明、使用示例和注意事项。

## 标签列表

### 基础标签

1. **[lang 标签使用指南](01-lang标签使用指南.md)**
   - 多语言翻译标签
   - 支持 `<lang>`、`@lang()`、`@lang{}` 三种格式
   - 支持参数传递和占位符

2. **[var 标签使用指南](02-var标签使用指南.md)**
   - 变量输出标签
   - 支持变量路径访问
   - 自动处理空值

### 控制流标签

3. **[if/elseif/else 标签使用指南](03-if-elseif-else标签使用指南.md)**
   - 条件判断标签
   - 支持多条件判断
   - 支持 `@if()` 格式

4. **[foreach 标签使用指南](04-foreach标签使用指南.md)**
   - 循环遍历标签
   - 支持数组和对象遍历
   - 支持键值对遍历

5. **[empty/notempty/has 标签使用指南](08-empty-notempty-has标签使用指南.md)**
   - 空值检查标签
   - 条件显示内容
   - 支持多条件判断

### 组件和资源标签

6. **[block 标签使用指南](05-block标签使用指南.md)**
   - Block 组件标签
   - 支持变量传递
   - 支持缓存设置

7. **[url 标签使用指南](06-url标签使用指南.md)**
   - URL 生成标签
   - 支持前端、后端、API URL
   - 支持参数传递

8. **[static/template/js/css 标签使用指南](09-static-template-js-css标签使用指南.md)**
   - 静态资源标签
   - 模板包含标签
   - JavaScript 和 CSS 文件引入

### 工具标签

9. **[php/include 标签使用指南](07-php-include标签使用指南.md)**
   - PHP 代码嵌入标签
   - 文件包含标签

10. **[pp/dd/count/string 标签使用指南](10-pp-dd-count-string标签使用指南.md)**
    - 调试输出标签
    - 计数标签
    - 字符串截取标签

11. **[csrf/message/msg/hook 标签使用指南](11-csrf-message-msg-hook标签使用指南.md)**
    - CSRF 防护标签
    - 消息显示标签
    - 视图钩子标签

## 标签分类

### 按功能分类

#### 输出类
- `var`：输出变量
- `lang`：输出翻译文本
- `pp`：调试输出
- `dd`：调试输出并终止

#### 控制流类
- `if`、`elseif`、`else`：条件判断
- `foreach`：循环遍历
- `empty`、`notempty`、`has`：空值检查

#### 资源类
- `static`：静态资源
- `template`：模板文件
- `js`：JavaScript 文件
- `css`：CSS 文件

#### URL 类
- `url`：通用 URL
- `frontend-url`：前端 URL
- `backend-url`、`admin-url`：后端 URL
- `api`：API URL
- `backend-api`：后端 API URL

#### 工具类
- `php`：PHP 代码
- `include`：文件包含
- `block`：Block 组件
- `count`：计数
- `string`：字符串截取
- `csrf`：CSRF 令牌
- `message`、`msg`：消息显示
- `hook`：视图钩子

### 按语法格式分类

#### 标签格式
- `<tag>content</tag>`
- `<tag attribute="value"/>`

#### @tag() 格式
- `@tag(content)`
- `@tag(content, params)`

#### @tag{} 格式
- `@tag{content}`
- `@tag{content, params}`

## 快速参考

### 常用标签

```html
<!-- 输出变量 -->
<var>username</var>

<!-- 条件判断 -->
<if condition="$isLoggedIn">
    <p>已登录</p>
</if>

<!-- 循环遍历 -->
<foreach name="items" item="item">
    <p><var>item</var></p>
</foreach>

<!-- 翻译 -->
<lang>网站维护</lang>

<!-- URL 生成 -->
<a href="@url('/product/list')">商品列表</a>

<!-- 引入资源 -->
<css>Weline_Frontend::css/main.css</css>
<js>Weline_Frontend::js/main.js</js>
```

## 使用建议

### 1. 选择合适的标签格式

- **标签格式**：适合多行内容
- **@tag() 格式**：适合单行内容
- **@tag{} 格式**：适合单行内容（与 @tag() 功能相同）

### 2. 性能优化

- 无参数的 `lang` 标签在编译时翻译，性能更好
- 合理使用缓存
- 避免在循环中使用复杂标签

### 3. 代码规范

- 保持标签嵌套层次清晰
- 使用有意义的变量名
- 注释复杂逻辑

## 相关文档

- [翻译函数使用指南](../3-开发/01-翻译函数使用指南.md)
- [多语言翻译占位符使用指南](../i18n-placeholder-usage.md)
- [Block 标签以及其他框架标签简介](../2-快速开始/07-block标签以及其他框架标签简介.md)

