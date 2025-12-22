# Weline Theme 模块 - Hook 使用指南

## 概述

Weline Theme 模块提供了完整的 Hook 机制，允许 Customer（客户/开发者）通过 hook 将自己的逻辑注入到主题布局中，实现功能扩展而不修改主题核心代码。

## Hook 类型

### 1. Base Hook（基础 Hook）

Base Hook 是为所有布局提供统一配置的 hook 点，适用于需要全局生效的功能。

**特点**：
- 一次配置，所有布局生效
- 适合全局 CSS/JS 注入
- 适合全局功能模块

**可用的 Base Hook**：
- `Weline_Theme::frontend::layouts::base::head-before` - 所有布局的 head 之前
- `Weline_Theme::frontend::layouts::base::head-after` - 所有布局的 head 之后
- `Weline_Theme::frontend::layouts::base::body-start` - 所有布局的 body 开始
- `Weline_Theme::frontend::layouts::base::body-end` - 所有布局的 body 结束
- `Weline_Theme::frontend::layouts::base::header-before` - 所有布局的 header 之前
- `Weline_Theme::frontend::layouts::base::header-after` - 所有布局的 header 之后
- `Weline_Theme::frontend::layouts::base::content-before` - 所有布局的 content 之前
- `Weline_Theme::frontend::layouts::base::content-after` - 所有布局的 content 之后
- `Weline_Theme::frontend::layouts::base::footer-before` - 所有布局的 footer 之前
- `Weline_Theme::frontend::layouts::base::footer-after` - 所有布局的 footer 之后

### 2. 详细布局 Hook（特定布局 Hook）

详细布局 Hook 是为特定布局类型提供的 hook 点，适用于需要为特定布局定制功能。

**特点**：
- 只为特定布局生效
- 适合布局特定的功能定制
- 可以覆盖或扩展 base hook 的功能

**可用的详细布局 Hook**：
- 首页布局：`Weline_Theme::frontend::layouts::homepage::*`
- 默认布局：`Weline_Theme::frontend::layouts::default::*`
- 账户布局：`Weline_Theme::frontend::layouts::account::*`
- 等等...

## Hook 执行顺序

Hook 的执行顺序遵循以下规则：

1. **Base Hook 先执行**：Base hook 在详细布局 hook 之前执行
2. **详细布局 Hook 后执行**：详细布局 hook 在 base hook 之后执行
3. **Before/After 顺序**：Before hook 在内容之前，After hook 在内容之后

**示例执行顺序**：
```
1. base::head-before
2. Head partial 加载
3. base::head-after
4. homepage::head-after
5. base::body-start
6. homepage::body-start
7. base::header-before
8. homepage::header-before
9. Header partial 加载
10. homepage::header-after
11. base::header-after
...
```

## 如何实现 Hook

### 步骤 1：创建 Hook 文件

在您的模块的 `view/hooks/` 目录下创建 hook 文件。

**文件命名规则**：
- Hook 名称中的 `::` 需要转换为 `--`
- 文件扩展名为 `.phtml`

**示例**：
- Hook 名称：`Weline_Theme::frontend::layouts::base::head-after`
- 文件名：`Weline_Theme--frontend--layouts--base--head-after.phtml`
- 文件路径：`{YourModule}/view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml`

### 步骤 2：编写 Hook 内容

Hook 文件是标准的 phtml 模板文件，可以访问模板的所有变量和方法。

**示例代码**：

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/custom.css') ?>">
<meta name="custom-meta" content="custom-value">
<script>
    // 全局 JavaScript 代码
    console.log('Custom hook executed');
</script>
```

### 步骤 3：验证 Hook

1. 清除缓存（如果启用了缓存）
2. 访问使用该布局的页面
3. 检查 hook 内容是否正确注入

## 使用场景示例

### 场景 1：全局 CSS/JS 注入

**需求**：在所有页面注入自定义 CSS 和 JavaScript

**解决方案**：使用 base hook

**实现**：
```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--base--head-after.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/global.css') ?>">
<script src="<?= $this->getUrl('static/js/global.js') ?>"></script>
```

### 场景 2：首页特定功能

**需求**：只在首页显示特定的横幅广告

**解决方案**：使用详细布局 hook

**实现**：
```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--homepage::content-before.phtml -->
<div class="homepage-banner">
    <img src="<?= $this->getUrl('static/images/banner.jpg') ?>" alt="Banner">
</div>
```

### 场景 3：全局返回顶部按钮

**需求**：在所有页面显示返回顶部按钮

**解决方案**：使用 base hook

**实现**：
```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--base--footer-after.phtml -->
<div class="back-to-top" id="back-to-top" style="display: none;">
    <button onclick="window.scrollTo({top: 0, behavior: 'smooth'})">↑</button>
</div>
<script>
    window.addEventListener('scroll', function() {
        var btn = document.getElementById('back-to-top');
        if (window.scrollY > 300) {
            btn.style.display = 'block';
        } else {
            btn.style.display = 'none';
        }
    });
</script>
```

## 最佳实践

### 1. 何时使用 Base Hook

- 需要全局生效的功能
- 全局 CSS/JS 注入
- 全局功能模块（如返回顶部、客服等）
- 全局统计代码

### 2. 何时使用详细布局 Hook

- 只为特定布局生效的功能
- 布局特定的功能定制
- 需要覆盖 base hook 的行为

### 3. 文件组织建议

- 将 hook 文件放在模块的 `view/hooks/` 目录下
- 使用清晰的命名，便于维护
- 为复杂的 hook 添加注释说明

### 4. 性能考虑

- 避免在 hook 中执行耗时操作
- 合理使用缓存
- 避免重复加载资源

## 查找可用的 Hook

### 方法 1：查看 hook.php 文件

所有可用的 hook 都定义在 `app/code/Weline/Theme/hook.php` 文件中。

### 方法 2：查看文档

每个 hook 都有对应的文档文件，位于 `app/code/Weline/Theme/doc/hook/` 目录下。

### 方法 3：查看布局文件

在布局文件中查找 `<w:hook>` 标签，可以看到所有可用的 hook 点。

## 常见问题

### Q1：Hook 文件没有生效？

**A**：检查以下几点：
1. 文件命名是否正确（`::` 是否转换为 `--`）
2. 文件路径是否正确（是否在 `view/hooks/` 目录下）
3. 是否清除了缓存
4. Hook 名称是否拼写正确

### Q2：Base Hook 和详细布局 Hook 的区别？

**A**：
- Base Hook：适用于所有布局，一次配置全局生效
- 详细布局 Hook：只适用于特定布局，可以为特定布局定制功能

### Q3：可以同时使用 Base Hook 和详细布局 Hook 吗？

**A**：可以。Base Hook 会先执行，然后执行详细布局 Hook。

### Q4：Hook 文件可以访问哪些变量？

**A**：Hook 文件可以访问模板的所有变量和方法，包括：
- `$this` - 模板对象
- `$this->getData()` - 获取模板数据
- `$this->getUrl()` - 生成 URL
- 等等...

## 相关文档

- [Base Hook 文档](hook/frontend/layouts/base/)
- [首页布局 Hook 文档](hook/frontend/layouts/homepage/)
- [默认布局 Hook 文档](hook/frontend/layouts/default/)
- [账户布局 Hook 文档](hook/frontend/layouts/account/)

## 总结

通过 Hook 机制，Customer 可以：
1. **统一配置**：使用 Base Hook 为所有布局统一配置
2. **灵活扩展**：使用详细布局 Hook 为特定布局定制功能
3. **无需修改核心代码**：通过 hook 注入逻辑，保持核心代码的稳定性
4. **易于维护**：hook 文件独立管理，便于维护和升级

