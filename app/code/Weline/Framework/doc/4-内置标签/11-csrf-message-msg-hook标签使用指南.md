# csrf/message/msg/hook 标签使用指南

## 摘要

本文档介绍 WelineFramework 中的 `csrf`、`message`、`msg`、`hook` 标签的使用方法。这些标签用于安全防护、消息显示和钩子功能。

## 什么是 csrf/message/msg/hook 标签

- **`csrf` 标签**：生成 CSRF 令牌，防止跨站请求伪造攻击
- **`message` 标签**：显示系统消息提示
- **`msg` 标签**：与 `message` 标签功能相同，是别名
- **`hook` 标签**：调用视图钩子，允许其他模块扩展视图

## 为什么需要这些标签

在模板中使用这些标签提供了以下优势：

- **安全性**：`csrf` 标签提供 CSRF 防护
- **用户体验**：`message`/`msg` 标签显示操作反馈
- **可扩展性**：`hook` 标签允许模块扩展视图

## csrf 标签

### 语法格式

```html
<csrf/>
<csrf name="form1"/>
<csrf>form1</csrf>

@csrf(form1)
@csrf{form1}
```

### 使用方法

#### 基本用法

```html
<form method="post" action="@url('/user/update')">
    <csrf/>
    <!-- 表单字段 -->
    <input type="text" name="username">
    <button type="submit">提交</button>
</form>
```

**编译结果**：
```html
<form method="post" action="/user/update">
    <input type="hidden" name="csrf_token" value="生成的令牌值">
    <!-- 表单字段 -->
    <input type="text" name="username">
    <button type="submit">提交</button>
</form>
```

#### 指定名称

```html
<form method="post" action="@url('/user/update')">
    <csrf name="user_form"/>
    <!-- 表单字段 -->
</form>
```

### 完整示例

```html
<form method="post" action="@url('/product/create')" id="product-form">
    <csrf name="product_form"/>
    
    <div class="form-group">
        <label>商品名称</label>
        <input type="text" name="name" required>
    </div>
    
    <div class="form-group">
        <label>价格</label>
        <input type="number" name="price" required>
    </div>
    
    <button type="submit">创建商品</button>
</form>
```

## message/msg 标签

### 语法格式

```html
<message/>
<message></message>

<msg/>
<msg></msg>

@message()
@message{}

@msg()
@msg{}
```

### 使用方法

#### 基本用法

```html
<!-- 显示系统消息 -->
<message/>

<!-- 或使用 msg -->
<msg/>
```

**说明**：这些标签会显示来自后端的消息提示，包括成功、错误、警告等信息。

### 完整示例

```html
<!doctype html>
<html>
<head>
    <title>页面标题</title>
</head>
<body>
    <!-- 显示消息提示 -->
    <message/>
    
    <div class="content">
        <h1>页面内容</h1>
        <!-- 其他内容 -->
    </div>
</body>
</html>
```

### 消息类型

系统支持以下消息类型：

- **成功消息**：操作成功提示
- **错误消息**：操作失败提示
- **警告消息**：警告信息
- **信息消息**：一般信息提示

### 在控制器中设置消息

```php
<?php
class Index extends FrontendController
{
    public function save()
    {
        // 设置成功消息
        $this->getMessageManager()->addSuccess('保存成功！');
        
        // 设置错误消息
        $this->getMessageManager()->addError('保存失败！');
        
        // 设置警告消息
        $this->getMessageManager()->addWarning('请注意！');
        
        return $this->redirect('index');
    }
}
```

## hook 标签

### 语法格式

```html
<hook>hook_name</hook>
@hook(hook_name)
@hook{hook_name}
```

### 使用方法

#### 基本用法

```html
<!-- 调用视图钩子 -->
<hook>view_header</hook>
@hook(view_header)
@hook{view_header}
```

**说明**：`hook` 标签允许其他模块通过观察者模式扩展视图内容。

### 完整示例

```html
<!doctype html>
<html>
<head>
    <title>页面标题</title>
    
    <!-- 头部钩子：允许其他模块添加头部内容 -->
    <hook>view_head</hook>
</head>
<body>
    <!-- 头部钩子 -->
    <hook>view_header</hook>
    
    <main>
        <h1>页面内容</h1>
    </main>
    
    <!-- 底部钩子 -->
    <hook>view_footer</hook>
    
    <!-- 脚本钩子：允许其他模块添加脚本 -->
    <hook>view_scripts</hook>
</body>
</html>
```

### 注册钩子观察者

其他模块可以通过观察者模式注册钩子：

```php
<?php
// etc/event.xml
<event name="Framework_View::hook_view_header">
    <observer name="module_name_observer" instance="Module\Name\Observer\ViewHeader"/>
</event>
```

```php
<?php
namespace Module\Name\Observer;

class ViewHeader implements \Weline\Framework\Event\ObserverInterface
{
    public function execute(\Weline\Framework\Event\Event &$event): void
    {
        // 返回要插入的内容
        echo '<div class="custom-header">自定义头部内容</div>';
    }
}
```

## 完整示例

### 示例 1：表单提交

```html
<form method="post" action="@url('/user/update')">
    <!-- CSRF 保护 -->
    <csrf name="user_update"/>
    
    <!-- 显示消息 -->
    <message/>
    
    <div class="form-group">
        <label>用户名</label>
        <input type="text" name="username" value="{{user.username}}">
    </div>
    
    <div class="form-group">
        <label>邮箱</label>
        <input type="email" name="email" value="{{user.email}}">
    </div>
    
    <button type="submit">更新</button>
</form>
```

### 示例 2：完整页面结构

```html
<!doctype html>
<html>
<head>
    <title>页面标题</title>
    
    <!-- 头部钩子 -->
    <hook>view_head</hook>
</head>
<body>
    <!-- 消息提示 -->
    <message/>
    
    <!-- 头部钩子 -->
    <hook>view_header</hook>
    
    <main>
        <h1>页面内容</h1>
        <p>主要内容区域</p>
    </main>
    
    <!-- 底部钩子 -->
    <hook>view_footer</hook>
    
    <!-- 脚本钩子 -->
    <hook>view_scripts</hook>
</body>
</html>
```

## 注意事项

### 1. csrf 标签

- 必须在表单中使用
- 每个表单可以使用不同的名称
- 令牌会自动验证

### 2. message/msg 标签

- 两个标签功能完全相同
- 显示来自后端的消息
- 消息会自动清除

### 3. hook 标签

- 钩子名称应该唯一且有意义
- 多个模块可以注册同一个钩子
- 钩子内容按注册顺序输出

### 4. 安全性

- `csrf` 标签提供 CSRF 防护
- 确保所有 POST 表单都包含 CSRF 令牌
- 不要在生产环境禁用 CSRF 验证

## 常见问题

### Q1: CSRF 验证失败？

**A**: 检查以下几点：
1. 确保表单中包含 `<csrf/>` 标签
2. 检查令牌是否过期
3. 确保表单提交方式正确

### Q2: 消息没有显示？

**A**: 检查以下几点：
1. 确保模板中包含 `<message/>` 或 `<msg/>` 标签
2. 检查控制器中是否设置了消息
3. 检查消息管理器是否正常工作

### Q3: 钩子没有执行？

**A**: 检查以下几点：
1. 确保钩子观察者已注册
2. 检查事件配置是否正确
3. 确保观察者类存在且正确实现

## 相关文档

- [事件系统文档](../event/)

