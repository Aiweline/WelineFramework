# components/ 目录文档

## 目录概述

`components/` 目录包含可复用的UI组件模板。这些组件使用统一的变量系统，支持主题切换，可以在任何页面或布局中使用。

## 目录结构

```
components/
├── button.phtml           # 按钮组件
├── input.phtml            # 输入框组件
├── card.phtml             # 卡片组件
├── modal.phtml            # 模态框组件
├── alert.phtml            # 提示框组件
├── form-group.phtml       # 表单组组件
├── badge.phtml            # 徽章组件
├── dropdown.phtml         # 下拉菜单组件
├── table.phtml            # 表格组件
└── pagination.phtml       # 分页组件
```

## 组件设计原则

1. **可复用性**：组件可在多处使用
2. **参数化**：通过参数控制组件行为
3. **主题适配**：自动适配亮色/暗色主题
4. **语义化**：使用语义化的HTML结构
5. **可访问性**：符合WCAG可访问性标准

---

## 组件列表

### 1. `button.phtml` - 按钮组件

**作用**：提供统一的按钮样式和交互

**参数**：
```php
[
    'text' => '按钮文字',           // 必填：按钮显示文字
    'type' => 'primary',            // 可选：按钮类型 (primary/secondary/danger/success/info)
    'size' => 'md',                 // 可选：按钮大小 (sm/md/lg)
    'icon' => 'fa-search',          // 可选：图标类名
    'iconPosition' => 'left',       // 可选：图标位置 (left/right)
    'class' => '',                  // 可选：额外CSS类
    'id' => '',                     // 可选：元素ID
    'disabled' => false,            // 可选：是否禁用
    'loading' => false,             // 可选：是否显示加载状态
    'href' => '',                   // 可选：链接地址（如果提供，渲染为<a>标签）
    'onclick' => '',                // 可选：点击事件
    'data' => []                    // 可选：data属性数组
]
```

**使用示例**：
```php
<?php
// 在模板中使用 @template 标签
// 基础按钮
$this->assign('text', __('登录'));
$this->assign('type', 'primary');
?>
@template(Weline_Frontend::theme/components/button.phtml)

<?php
// 带图标的按钮
$this->assign('text', __('搜索'));
$this->assign('type', 'primary');
$this->assign('icon', 'fa-search');
$this->assign('iconPosition', 'left');
?>
@template(Weline_Frontend::theme/components/button.phtml)

<?php
// 链接按钮
$this->assign('text', __('了解更多'));
$this->assign('type', 'secondary');
$this->assign('href', '/about');
?>
@template(Weline_Frontend::theme/components/button.phtml)

<?php
// 加载状态按钮
$this->assign('text', __('提交'));
$this->assign('type', 'primary');
$this->assign('loading', true);
?>
@template(Weline_Frontend::theme/components/button.phtml)
```

**渲染结果**：
```html
<!-- 基础按钮 -->
<button type="button" class="btn btn-primary btn-md">
    登录
</button>

<!-- 带图标按钮 -->
<button type="button" class="btn btn-primary btn-md">
    <i class="fa fa-search"></i>
    搜索
</button>

<!-- 链接按钮 -->
<a href="/about" class="btn btn-secondary btn-md">
    了解更多
</a>
```

**样式类**：
- `.btn` - 基础按钮类
- `.btn-primary` - 主按钮
- `.btn-secondary` - 次按钮
- `.btn-danger` - 危险按钮
- `.btn-success` - 成功按钮
- `.btn-sm` / `.btn-md` / `.btn-lg` - 尺寸类

---

### 2. `input.phtml` - 输入框组件

**作用**：提供统一的输入框样式和验证反馈

**参数**：
```php
[
    'name' => 'username',           // 必填：字段名
    'type' => 'text',               // 可选：输入类型 (text/password/email/tel/number等)
    'label' => '用户名',            // 可选：标签文字
    'placeholder' => '请输入',      // 可选：占位符
    'value' => '',                  // 可选：默认值
    'required' => false,            // 可选：是否必填
    'disabled' => false,            // 可选：是否禁用
    'readonly' => false,            // 可选：是否只读
    'class' => '',                  // 可选：额外CSS类
    'id' => '',                     // 可选：元素ID
    'help' => '',                   // 可选：帮助文字
    'error' => '',                  // 可选：错误信息
    'icon' => 'fa-user',            // 可选：图标类名
    'autocomplete' => 'off'         // 可选：自动完成
]
```

**使用示例**：
```php
<?php
// 基础输入框
$this->assign('name', 'username');
$this->assign('label', __('用户名'));
$this->assign('placeholder', __('请输入用户名'));
$this->assign('required', true);
?>
@template(Weline_Frontend::theme/components/input.phtml)

<?php
// 带图标的输入框
$this->assign('name', 'email');
$this->assign('type', 'email');
$this->assign('label', __('邮箱'));
$this->assign('icon', 'fa-envelope');
$this->assign('help', __('请输入有效的邮箱地址'));
?>
@template(Weline_Frontend::theme/components/input.phtml)

<?php
// 带错误信息的输入框
$this->assign('name', 'password');
$this->assign('type', 'password');
$this->assign('label', __('密码'));
$this->assign('error', __('密码长度不能少于6位'));
?>
@template(Weline_Frontend::theme/components/input.phtml)
```

**渲染结果**：
```html
<div class="form-group">
    <label for="username" class="form-label">用户名</label>
    <div class="input-wrapper">
        <input type="text" 
               name="username" 
               id="username" 
               class="form-control" 
               placeholder="请输入用户名" 
               required>
        <i class="fa fa-user input-icon"></i>
    </div>
    <small class="form-text">帮助文字</small>
    <div class="invalid-feedback">错误信息</div>
</div>
```

---

### 3. `card.phtml` - 卡片组件

**作用**：提供统一的卡片容器样式

**参数**：
```php
[
    'title' => '卡片标题',          // 可选：卡片标题
    'subtitle' => '',               // 可选：副标题
    'content' => '',                // 必填：卡片内容（HTML字符串）
    'footer' => '',                 // 可选：卡片底部内容
    'class' => '',                  // 可选：额外CSS类
    'id' => '',                     // 可选：元素ID
    'headerActions' => [],          // 可选：标题栏操作按钮
    'image' => '',                  // 可选：卡片图片URL
    'imageAlt' => ''                // 可选：图片alt文本
]
```

**使用示例**：
```php
<?php
// 基础卡片
$this->assign('title', __('产品信息'));
$this->assign('content', '<p>这是卡片内容</p>');
?>
@template(Weline_Frontend::theme/components/card.phtml)

<?php
// 带图片的卡片
$this->assign('title', __('产品名称'));
$this->assign('image', '/images/product.jpg');
$this->assign('imageAlt', __('产品图片'));
$this->assign('content', '<p>产品描述</p>');
$this->assign('footer', '<button class="btn btn-primary">购买</button>');
?>
@template(Weline_Frontend::theme/components/card.phtml)
```

---

### 4. `modal.phtml` - 模态框组件

**作用**：提供统一的模态框样式和交互

**参数**：
```php
[
    'id' => 'myModal',              // 必填：模态框ID
    'title' => '模态框标题',        // 可选：标题
    'content' => '',                // 必填：内容（HTML字符串）
    'footer' => '',                 // 可选：底部内容（按钮等）
    'size' => 'md',                 // 可选：尺寸 (sm/md/lg/xl)
    'backdrop' => true,             // 可选：是否显示背景遮罩
    'keyboard' => true,             // 可选：是否支持ESC关闭
    'closeButton' => true           // 可选：是否显示关闭按钮
]
```

**使用示例**：
```php
<?php
// 基础模态框
$this->assign('id', 'confirmModal');
$this->assign('title', __('确认操作'));
$this->assign('content', '<p>确定要执行此操作吗？</p>');
$this->assign('footer', '
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
    <button type="button" class="btn btn-primary">确认</button>
');
?>
@template(Weline_Frontend::theme/components/modal.phtml)
```

---

### 5. `alert.phtml` - 提示框组件

**作用**：提供统一的提示信息显示

**参数**：
```php
[
    'type' => 'info',               // 可选：类型 (success/error/warning/info)
    'message' => '',                // 必填：提示信息
    'title' => '',                  // 可选：标题
    'dismissible' => false,         // 可选：是否可关闭
    'icon' => '',                   // 可选：自定义图标
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 成功提示
$this->assign('type', 'success');
$this->assign('message', __('操作成功！'));
?>
@template(Weline_Frontend::theme/components/alert.phtml)

<?php
// 错误提示（可关闭）
$this->assign('type', 'error');
$this->assign('message', __('操作失败，请重试'));
$this->assign('dismissible', true);
?>
@template(Weline_Frontend::theme/components/alert.phtml)
```

---

### 6. `form-group.phtml` - 表单组组件

**作用**：提供统一的表单组结构（标签+输入+帮助+错误）

**参数**：
```php
[
    'label' => '字段标签',          // 可选：标签文字
    'input' => '',                  // 必填：输入框HTML
    'help' => '',                   // 可选：帮助文字
    'error' => '',                  // 可选：错误信息
    'required' => false,            // 可选：是否必填
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
$this->assign('label', __('用户名'));
$this->assign('input', '<input type="text" name="username" class="form-control">');
$this->assign('help', __('3-20个字符'));
$this->assign('required', true);
?>
@template(Weline_Frontend::theme/components/form-group.phtml)
```

---

## 组件样式

所有组件样式定义在 `assets/css/components.css` 中，使用统一的变量系统：

```css
/* 组件基础样式 */
.btn {
    background-color: var(--color-primary);
    color: var(--color-text-primary);
    padding: var(--spacing-sm) var(--spacing-md);
    border: var(--border-width-thin) solid var(--color-primary-border);
    border-radius: var(--border-radius-md);
    font-size: var(--font-size-base);
    box-shadow: var(--shadow-sm);
    transition: all 0.2s ease;
}

.btn:hover {
    background-color: var(--color-primary-light);
    box-shadow: var(--shadow-md);
}

.btn:focus {
    outline: 2px solid var(--color-border-focus);
    box-shadow: var(--shadow-focus);
}
```

---

## 组件扩展

### 创建新组件

1. **创建组件文件**：`components/your-component.phtml`
2. **定义参数**：在文件顶部注释中说明参数
3. **使用变量**：组件样式使用CSS变量
4. **更新文档**：在本文档中添加组件说明

### 组件模板结构

```php
<?php
/**
 * 组件：YourComponent
 * 
 * 参数：
 * - param1: 参数1说明
 * - param2: 参数2说明
 */
$param1 = $this->getData('param1') ?? 'default';
$param2 = $this->getData('param2') ?? '';
?>
<div class="your-component <?= htmlspecialchars($this->getData('class') ?? '') ?>">
    <!-- 组件内容 -->
</div>
```

---

## 最佳实践

### 1. 参数处理

- 使用 `$this->getData()` 获取参数
- 提供合理的默认值
- 对用户输入进行转义（`htmlspecialchars`）

### 2. 样式使用

- 使用CSS变量，不要硬编码颜色值
- 使用语义化的CSS类名
- 支持主题切换

### 3. 可访问性

- 使用语义化的HTML标签
- 提供适当的ARIA属性
- 确保键盘导航支持

---

## 相关文档

- [组件设计规范](./组件规范.md)（待创建）
- [assets/css/components.css 文档](./assets目录文档.md#componentscss)

