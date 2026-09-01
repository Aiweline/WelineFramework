# lang 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `lang` 标签的使用方法。`lang` 标签用于在模板中实现多语言翻译，支持多种语法格式和参数传递。

**硬规则（必读）**：`@lang()` / `@lang{}` 把**未加引号的逗号**一律当作「源文 / 参数」分隔符，与 `@lang(文案, $args)` 相同。源文本身含逗号（如文件扩展名列表 `ico, png, svg`）时，**禁止**写成 `@lang{支持 .ico, .png}`——会编译成 `<?=__('支持 .ico', .png)?>` 并触发页面 `ParseError` / 500。应改用：

| 场景 | 正确写法 |
|------|----------|
| 源文含逗号、无动态参数 | `<lang>支持 .ico, .png, .svg</lang>`（首选） |
| 内联属性/脚本、源文含逗号 | `@lang('支持 .ico, .png, .svg')` 或 `@lang{"Supports .ico, .png"}` |
| 源文无逗号、带翻译参数 | `@lang(Welcome %{}!, 'John')` / `@lang{User %{1} has %{2} messages, ['John', 5]}` |
| 源文含逗号且要传参 | 用 `<lang args="...">源文含,逗号</lang>`，不要依赖裸 `@lang{源文, 参数}` |

MCP 硬约束 id：`at_lang_no_unquoted_comma`（见 `AI硬规则索引.md`）。详例见下文「注意事项 → 参数分隔」与 FAQ Q2b。

## 什么是 lang 标签

`lang` 标签是 WelineFramework 提供的模板翻译标签，用于在模板文件中标记需要翻译的文本。该标签会在模板编译时或运行时根据当前用户的语言环境自动翻译文本内容。

## 为什么需要 lang 标签

在模板中使用 `lang` 标签提供了以下优势：

- **模板国际化**：直接在模板中标记需要翻译的文本，无需修改 PHP 代码
- **多种语法格式**：支持 `<lang>` 标签、`@lang()` 和 `@lang{}` 三种格式，适应不同使用场景
- **参数支持**：支持占位符参数，实现动态文本翻译
- **编译优化**：无参数时在编译时翻译，减少运行时开销

## 语法格式

`lang` 标签支持以下三种语法格式。**`<lang>` 的正文可含任意逗号**；`@lang()` / `@lang{}` 仅在源文加了引号时，逗号才属于源文。

### 1. `<lang>` 标签格式

源文写在标签体内，逗号不会被当成参数分隔（参数走 `args` 属性）。含逗号的说明文案优先用本格式。

```html
<!-- 基本用法 -->
<lang>网站维护</lang>

<!-- 源文可含逗号 -->
<lang>支持 .ico, .png, .svg</lang>

<!-- 带 args 属性 -->
<lang args="'John'">Welcome %{}!</lang>
<lang args="['John', 5]">User %{1} has %{2} messages</lang>
<lang args="['name' => $user->getName(), 'count' => $count]">
    User %{name} has %{count} messages
</lang>
```

### 2. `@lang()` 格式

第一个逗号起（在未加引号的源文之后）开始切分参数。无参数时源文也不要夹未加引号的逗号。

```html
<!-- 基本用法（源文无逗号） -->
@lang(网站维护中...)

<!-- 源文含逗号：必须给源文加引号 -->
@lang('支持 .ico, .png, .svg')

<!-- 带参数（逗号分隔：左侧源文无未加引号逗号，右侧才是 $args） -->
@lang(Welcome %{}!, 'John')
@lang(User %{1} has %{2} messages, ['John', 5])
@lang(User %{name} has %{count} messages, ['name' => 'John', 'count' => 5])
```

### 3. `@lang{}` 格式

与 `@lang()` 相同：花括号内**未加引号的逗号 = 参数分隔**，不是源文字面量。

```html
<!-- 基本用法（源文无逗号） -->
@lang{网站维护中...}

<!-- 源文含逗号：必须加引号，或改用 <lang> -->
@lang{'支持 .ico, .png, .svg'}
@lang{"Supports .ico, .png, .svg"}

<!-- 带参数（逗号分隔） -->
@lang{Welcome %{}!, 'John'}
@lang{User %{1} has %{2} messages, ['John', 5]}
@lang{User %{name} has %{count} messages, ['name' => 'John', 'count' => 5]}

<!-- 错误示例（会 500）：@lang{支持 .ico, .png, .svg} -->
```

## 使用方法

### 基本翻译

最简单的用法是直接标记需要翻译的文本：

```html
<!-- 使用 <lang> 标签 -->
<h1><lang>网站维护</lang></h1>
<p><lang>请稍等片刻...</lang></p>

<!-- 使用 @lang() -->
<title>@lang(网站维护中...)</title>

<!-- 使用 @lang{} -->
<span>@lang{返回首页}</span>
```

**编译结果**：
- 无参数时，标签会在编译时直接替换为翻译后的文本
- 例如：`<lang>网站维护</lang>` 在英文环境下编译为 `Website Maintenance`

### 带参数的翻译

当需要动态插入变量时，可以使用参数：

#### 使用 `<lang>` 标签的 `args` 属性

```html
<!-- 单个参数 -->
<p><lang args="'John'">Welcome %{}!</lang></p>
<!-- 输出：Welcome John! -->

<!-- 数组参数 -->
<p><lang args="['John', 5]">User %{1} has %{2} messages</lang></p>
<!-- 输出：User John has 5 messages -->

<!-- 命名参数（推荐） -->
<p><lang args="['name' => $user->getName(), 'count' => $messageCount]">
    User %{name} has %{count} messages
</lang></p>
<!-- 输出：User John has 5 messages -->

<!-- 使用模板变量 -->
<p><lang args="$username">Welcome %{}!</lang></p>
```

#### 使用 `@lang()` 格式

```html
<!-- 单个参数 -->
<p>@lang(Welcome %{}!, 'John')</p>
<!-- 输出：Welcome John! -->

<!-- 数组参数 -->
<p>@lang(User %{1} has %{2} messages, ['John', 5])</p>
<!-- 输出：User John has 5 messages -->

<!-- 命名参数 -->
<p>@lang(User %{name} has %{count} messages, ['name' => 'John', 'count' => 5])</p>
<!-- 输出：User John has 5 messages -->
```

#### 使用 `@lang{}` 格式

```html
<!-- 单个参数 -->
<p>@lang{Welcome %{}!, 'John'}</p>
<!-- 输出：Welcome John! -->

<!-- 数组参数 -->
<p>@lang{User %{1} has %{2} messages, ['John', 5]}</p>
<!-- 输出：User John has 5 messages -->

<!-- 命名参数 -->
<p>@lang{User %{name} has %{count} messages, ['name' => 'John', 'count' => 5]}</p>
<!-- 输出：User John has 5 messages -->
```

## 占位符格式

`lang` 标签支持以下占位符格式：

### 1. 通用占位符 `%{}`

用于单个参数：

```html
<lang args="'John'">Welcome %{}!</lang>
@lang(Welcome %{}!, 'John')
@lang{Welcome %{}!, 'John'}
```

### 2. 数字占位符 `%{1}`, `%{2}`, ...

用于多个参数，从 1 开始：

```html
<lang args="['John', 5]">User %{1} has %{2} messages</lang>
@lang(User %{1} has %{2} messages, ['John', 5])
@lang{User %{1} has %{2} messages, ['John', 5]}
```

### 3. 命名占位符 `%{name}`, `%{count}`, ...

用于命名参数（推荐）：

```html
<lang args="['name' => 'John', 'count' => 5]">
    User %{name} has %{count} messages
</lang>
@lang(User %{name} has %{count} messages, ['name' => 'John', 'count' => 5])
@lang{User %{name} has %{count} messages, ['name' => 'John', 'count' => 5]}
```

## 完整示例

### 示例 1：维护页面

```html
<!doctype html>
<html lang='en'>
<head>
    <meta charset='utf-8'/>
    <title>@lang(网站维护中...)</title>
</head>
<body>
    <div class='container'>
        <h1><lang>网站维护</lang></h1>
        <p><lang>请稍等片刻...</lang></p>
        
        <div class='row'>
            <div class='col-md-4'>
                <h5><lang>为什么网站会处于维护模式？</lang></h5>
                <p><lang>网站可能发生系统升级事件，或者大多数的内容发生变化，程序员们正在维护或者升级数据以提供更加优质的服务。</lang></p>
            </div>
            <div class='col-md-4'>
                <h5><lang>多久能够恢复？</lang></h5>
                <p><lang>大部分的网站升级活动都只有几分钟甚至几秒钟的时间。</lang></p>
            </div>
            <div class='col-md-4'>
                <h5><lang>需要帮助吗？</lang></h5>
                <p>
                    <lang>如果你长期看到这个页面，对网站存在疑惑，请联系：</lang>
                    <a href="mailto:aiweline@qq.com">aiweline@qq.com</a>
                </p>
            </div>
        </div>
        
        <a href="@url('/')" class='btn btn-primary'>
            <lang>返回首页重试</lang>
        </a>
    </div>
</body>
</html>
```

### 示例 2：带参数的翻译

```html
<!-- 用户欢迎信息 -->
<div class="welcome">
    <p><lang args="['name' => $user->getName()]">欢迎，%{name}！</lang></p>
    <p><lang args="['count' => $messageCount]">您有 %{count} 条未读消息</lang></p>
</div>

<!-- 使用 @lang() 格式 -->
<div class="stats">
    <p>@lang(共有 %{1} 个用户，%{2} 个在线, [$totalUsers, $onlineUsers])</p>
</div>

<!-- 使用 @lang{} 格式 -->
<div class="info">
    <p>@lang{订单 %{orderId} 已创建，预计 %{date} 送达, ['orderId' => $order->getId(), 'date' => $order->getDeliveryDate()]}</p>
</div>
```

### 示例 3：在 JavaScript 中使用

```html
<script>
    // 在 JavaScript 中使用 @lang() 或 @lang{}
    var message = '@lang(操作成功)';
    var welcomeMsg = '@lang(欢迎 %{}!, "' + username + '")';
    
    alert(message);
    alert(welcomeMsg);
</script>
```

## 编译机制

### 无参数翻译（编译时翻译）

当 `lang` 标签没有参数时，会在模板编译时直接翻译：

```html
<!-- 源代码 -->
<lang>网站维护</lang>

<!-- 编译后（英文环境） -->
Website Maintenance
```

**优势**：
- 减少运行时开销
- 编译后的文件包含最终文本，性能更好

### 有参数翻译（运行时翻译）

当 `lang` 标签有参数时，会在运行时翻译：

```html
<!-- 源代码 -->
<lang args="'John'">Welcome %{}!</lang>

<!-- 编译后 -->
<?=__('Welcome %{}!', 'John')?>
```

**优势**：
- 支持动态参数
- 根据运行时语言环境翻译

## 最佳实践

### 优先使用 `<lang>` 标签

在 `.phtml` 模板文件中，**应优先使用 `<lang>` 标签**，而不是 `__()` PHP 函数：

**推荐做法**：
- ✅ **HTML 内容中**：使用 `<lang>` 标签
  ```html
  <button><lang>保存</lang></button>
  <h1><lang>用户管理</lang></h1>
  <span><lang>共 %{count} 条记录</lang></span>
  ```

- ✅ **HTML 属性中**：使用 `<lang>` 标签（框架会自动处理）
  ```html
  <input type="text" placeholder="<lang>请输入用户名</lang>" />
  <a href="#" title="<lang>删除</lang>"><lang>删除</lang></a>
  ```

- ✅ **页面内联 JavaScript（`.phtml` 模板中的 `<script>` 标签）**：直接使用 `@lang()` 标签
  ```html
  <script>
  // 页面内联 JavaScript 中直接使用 @lang() 标签
  throw new Error('@lang(请求失败)');
  alert('@lang(操作成功)');
  $('#select').select2({
      placeholder: '@lang(选择目标语言)'
  });
  </script>
  ```

- ✅ **外部引入的 JavaScript 文件（`.js` 文件）**：在页面中定义全局翻译变量
  ```html
  <!-- 在 .phtml 模板中定义全局翻译变量供外部 JS 使用 -->
  <script>
  window.i18nTexts = {
      save: '<?= __('保存') ?>',
      delete: '<?= __('删除') ?>',
      requestFailed: '<?= __('请求失败') ?>'
  };
  </script>
  <script src="external.js"></script>
  ```
  
  ```javascript
  // external.js 中使用全局翻译变量
  alert(window.i18nTexts.save);
  throw new Error(window.i18nTexts.requestFailed);
  ```

**不推荐做法**：
- ❌ 在 HTML 内容中使用 `<?= __('保存') ?>`
  ```html
  <button><?= __('保存') ?></button>  <!-- 不推荐 -->
  ```

- ❌ 在 HTML 属性中使用 `<?= __('文本') ?>`
  ```html
  <input placeholder="<?= __('请输入用户名') ?>" />  <!-- 不推荐 -->
  ```

- ❌ 在页面内联 JavaScript 中定义 i18nTexts 变量
  ```javascript
  // ❌ 错误：页面内联 JavaScript 中不应定义 i18nTexts 变量
  <script>
  const i18nTexts = {
      save: '<?= __('保存') ?>'
  };
  alert(i18nTexts.save);  // 错误：应直接使用 @lang()
  </script>
  ```

- ❌ 在外部引入的 JS 文件中直接使用 `@lang()`
  ```javascript
  // ❌ 错误：外部 JS 文件不会被模板引擎处理，无法使用 @lang()
  // external.js
  alert('@lang(保存)');  // 错误：应使用 window.i18nTexts.save
  ```

**原因**：
1. `<lang>` 标签在编译时翻译（无参数时），性能更好
2. 代码更简洁，可读性更强
3. 符合模板标签的使用规范
4. 框架会自动处理 `<lang>` 标签的编译和翻译

**例外情况**：
- 在 PHP 代码块中（`<?php ?>`）必须使用 `__()` 函数
- 需要动态参数且无法使用 `<lang args="">` 时，可以使用 `__()` 函数

## 注意事项

### 1. 引号处理

在 `@lang()` 和 `@lang{}` 格式中，文本可以使用单引号或双引号：

```html
<!-- 正确 -->
@lang('网站维护')
@lang("网站维护")
@lang{网站维护}

<!-- 错误 -->
@lang(网站维护)  <!-- 如果文本包含特殊字符，需要引号 -->
```

### 2. 参数分隔（硬规则：源文含逗号必须加引号）

在 `@lang()` 和 `@lang{}` 格式中，**未加引号的逗号一律当作参数分隔符**（与 `@lang(文案, $args)` 相同），不是源文字面量的一部分。

```html
<!-- 正确：无逗号源文 -->
@lang(Welcome %{}!, 'John')
@lang{User %{1} has %{2} messages, ['John', 5]}

<!-- 正确：源文本身含逗号 → 必须加引号，或改用 <lang> -->
@lang('Hello, World!')
@lang('支持 .ico, .png, .svg')
@lang{"Supports .ico, .png, .svg"}
<lang>支持 .ico, .png, .svg</lang>

<!-- 错误：未加引号的逗号会被切成多个「参数」，编译成非法 PHP 并 500 -->
@lang{支持 .ico, .png, .svg}
<!-- 编译结果类似：<?=__('支持 .ico', .png, .svg)?> → ParseError: unexpected token "." -->
```

**MCP 硬约束 id**：`at_lang_no_unquoted_comma`（见 `AI硬规则索引.md` / `hard-constraints.v1`）。

### 3. 嵌套使用

`lang` 标签可以嵌套在其他标签中：

```html
<if condition="$showMessage">
    <p><lang>显示消息</lang></p>
</if>

<foreach name="items" item="item">
    <div><lang>项目：</lang>{{item.name}}</div>
</foreach>
```

### 4. 性能考虑

- **无参数时**：编译时翻译，性能最优
- **有参数时**：运行时翻译，性能略低但支持动态内容

## 常见问题

### Q1: 为什么翻译没有生效？

**A**: 检查以下几点：
1. 确保翻译文件（CSV）已创建并包含对应的翻译词条
2. 运行 `php bin/w i18n:collect` 收集翻译词条
3. 运行 `php bin/w cache:clear` 清除缓存
4. 检查当前用户的语言设置

### Q2: 参数格式错误怎么办？

**A**: 确保参数格式正确：
- `<lang>` 标签使用 `args` 属性
- `@lang()` 和 `@lang{}` 使用逗号分隔参数；**源文本身含逗号时必须加引号**，否则会编译成非法 PHP（`ParseError: unexpected token "."`）
- 数组参数使用 `[]` 包裹
- 命名参数使用关联数组

### Q2b: `@lang{支持 .ico, .png}` 页面 500？

**A**: 不是「在标签里写了 PHP」，而是未加引号的逗号被当成参数分隔。改为 `<lang>支持 .ico, .png</lang>` 或 `@lang('支持 .ico, .png')`，并清除对应 `view/tpl` 编译缓存。

### Q3: 编译后的文件还是中文？

**A**: 这通常是因为：
1. 编译时使用的语言与目标语言不一致
2. 翻译文件中没有对应的翻译
3. 缓存未清除

解决方法：
1. 清除模板编译缓存
2. 确保翻译文件包含所有需要的翻译
3. 重新编译模板

## 相关文档

- [翻译函数使用指南](../3-开发/01-翻译函数使用指南.md)
- [多语言翻译占位符使用指南](../i18n-placeholder-usage.md)

