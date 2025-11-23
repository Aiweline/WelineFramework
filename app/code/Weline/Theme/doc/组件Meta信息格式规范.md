# 组件 Meta 信息格式规范

## 概述

本文档定义了 Theme 模块中 phtml 组件文件的 Meta 信息格式规范，支持参数类型定义、默认值配置（包括 PHP 变量）、层级元数据结构等功能。

## 格式规范

### 基本结构

```php
<?php
/**
 * 组件：{组件名称}
 * 
 * {组件描述}
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.group.frontend {default="前端组",name="前端组",description="前端组，用于给元素据分组用的，方便维护"}
 * @meta::theme.group.frontend.description {default="组件描述",name="组件描述",description="组件描述，用于描述组件的用途"}
 * @meta::theme.group.frontend.name {default="组件名称",name="组件名称",description="组件名称，用于描述组件的名称"}
 * @preview.login {default=0,option={0:不需要登录,1:需要登录},name="是否需要登录",description="是否需要登录，0不需要登录，1需要登录，这个不是元素据，是预览系统判断是否需要自动登录的。"}
 * @param {参数名} {default=默认值,name="参数名称",description="参数描述"}
 */
```

### 元数据层级结构

元数据使用 `@meta::` 标记，支持层级结构。**拥有 `.` 子元素则认为上层是组**。

**层级规则**：
- `@meta::theme` - 主题命名空间（顶层组）
- `@meta::theme.group` - 分组（中间组）
- `@meta::theme.group.frontend` - 前端组（值）
- `@meta::theme.group.frontend.description` - 前端组的描述（值）

**属性格式**：
- 使用 `{}` 包裹属性
- 属性格式：`key=value` 或 `key="value with spaces"`
- 多个属性用逗号分隔
- 支持可选属性：`default`、`name`、`description` 等

**示例**：
```php
@meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
@meta::theme.group.frontend {default="前端组",name="前端组",description="前端组，用于给元素据分组用的，方便维护"}
@meta::theme.group.frontend.description {default="登录/注册等认证页面的专用布局",name="登录/注册等认证页面的专用布局",description="登录/注册等认证页面的专用布局，用于描述布局的用途"}
```

### 预览登录标记

#### @preview.login 标记

`@preview.login` 标记用于指定布局文件在预览时是否需要自动登录。该标记主要用于布局文件（layouts），帮助预览系统自动判断是否需要登录测试账户。

**格式**：
```
@preview.login {default=0,option={0:不需要登录,1:需要登录},name="是否需要登录",description="是否需要登录，0不需要登录，1需要登录，这个不是元素据，是预览系统判断是否需要自动登录的。"}
```

**说明**：
- **标记名称**：`@preview.login`
- **default**：默认值，`0` 表示不需要登录，`1` 表示需要登录
- **option**：可选值说明（可选）
- **name**：标记名称（可选）
- **description**：标记描述（可选）
- **默认值**：如果不指定 `default`，默认为 `0`（不需要登录）

**使用场景**：
- **需要登录的布局**：个人中心、订单列表、个人资料等需要登录才能查看的页面布局
- **不需要登录的布局**：首页、登录页、注册页等不需要登录的页面布局

**示例**：
```php
<?php
/**
 * 布局：个人中心 - 认证页面布局
 * 
 * 登录/注册等认证页面的专用布局
 * 字段
 * @preview.login {default=0,option={0:不需要登录,1:需要登录},name="是否需要登录",description="是否需要登录，0不需要登录，1需要登录，这个不是元素据，是预览系统判断是否需要自动登录的。"}
 */
```

```php
<?php
/**
 * 布局：个人中心 - 仪表盘布局
 * 
 * 个人中心仪表盘布局，包含侧边栏导航和主内容区
 * 字段
 * @preview.login {default=1,option={0:不需要登录,1:需要登录},name="是否需要登录",description="是否需要登录，0不需要登录，1需要登录，这个不是元素据，是预览系统判断是否需要自动登录的。"}
 */
```

**工作原理**：
1. 预览系统会解析布局文件的 `@preview.login` 标记
2. 如果标记值为 `1`，预览时会自动登录测试账户（`preview_theme`）
3. 如果标记值为 `0`，预览时不会自动登录
4. 用户也可以通过预览界面的"自动登录"开关手动控制

**注意事项**：
- 该标记仅在预览阶段生效，不影响正常使用
- 如果布局文件没有该标记，默认值为 `0`（不需要登录）
- 支持主题继承，会查找父主题的布局文件
- 可以通过 URL 参数 `auto_login` 手动覆盖该设置

### 参数定义格式

#### 格式说明

```
@param {参数名} {default=默认值,name="参数名称",description="参数描述",type=类型}
```

- **参数名**：参数的变量名
- **default**：默认值（可选）
- **name**：参数的显示名称（可选）
- **description**：参数的说明文字（可选）
- **type**：参数的数据类型（可选，string/int/bool/array/mixed）

**注意**：所有属性都是可选的，但建议至少提供 `name` 和 `description`。

#### 默认值类型

1. **字面量默认值**
   ```php
   @param type {default="primary",name="按钮类型",description="按钮类型",type=string}
   @param maxVisible {default=5,name="最大可见页码数",description="最大可见页码数",type=int}
   @param showFirstLast {default=true,name="是否显示首页/末页",description="是否显示首页/末页",type=bool}
   @param data {default=[],name="数据数组",description="数据数组",type=array}
   ```

2. **PHP 变量默认值**
   ```php
   @param baseUrl {default=$this->getRequest()->getUriString(),name="基础URL",description="基础URL",type=string}
   @param currentPage {default=(int)($this->getRequest()->getParam('page') ?: 1),name="当前页码",description="当前页码",type=int}
   @param pageParam {default=$this->getData('pageParam') ?? 'page',name="页码参数名",description="页码参数名",type=string}
   ```

3. **复杂表达式默认值**
   ```php
   @param icon {default=$type === 'success' ? 'fa-check-circle' : 'fa-info-circle',name="图标类名",description="图标类名",type=string}
   @param items {default=$this->getData('menuItems') ?? [],name="菜单项",description="菜单项",type=array}
   ```

### 完整示例

#### 示例 1：基础组件（Pagination）

```php
<?php
/**
 * 组件：Pagination
 * 
 * 分页组件，用于数据分页导航
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.component.frontend {default="前端组件组",name="前端组件组",description="前端组件组，用于给元素据分组用的，方便维护"}
 * @meta::theme.component.frontend.description {default="分页组件，用于数据分页导航",name="分页组件，用于数据分页导航",description="分页组件，用于数据分页导航，用于描述组件的用途"}
 * @meta::theme.component.frontend.name {default="Pagination分页组件",name="Pagination分页组件",description="Pagination分页组件，用于描述组件的名称"}
 * @param currentPage {default=1,name="当前页码",description="当前页码，必填参数",type=int}
 * @param totalPages {default=1,name="总页数",description="总页数，必填参数",type=int}
 * @param baseUrl {default="",name="基础URL",description="基础URL，必填参数",type=string}
 * @param pageParam {default="page",name="页码参数名",description="页码参数名，用于URL参数",type=string}
 * @param showFirstLast {default=true,name="是否显示首页/末页",description="是否显示首页/末页，用于控制首页末页按钮的显示",type=bool}
 * @param showPrevNext {default=true,name="是否显示上一页/下一页",description="是否显示上一页/下一页，用于控制上一页下一页按钮的显示",type=bool}
 * @param maxVisible {default=5,name="最大可见页码数",description="最大可见页码数，用于控制显示的页码数量",type=int}
 * @param class {default="",name="额外CSS类",description="额外CSS类，用于设置组件额外CSS类",type=string}
 */
$currentPage = (int)($this->getData('currentPage') ?? 1);
$totalPages = (int)($this->getData('totalPages') ?? 1);
$baseUrl = $this->getData('baseUrl') ?? '';
$pageParam = $this->getData('pageParam') ?? 'page';
$showFirstLast = $this->getData('showFirstLast') ?? true;
$showPrevNext = $this->getData('showPrevNext') ?? true;
$maxVisible = (int)($this->getData('maxVisible') ?? 5);
$class = $this->getData('class') ?? '';
// ... 组件代码 ...
?>
```

#### 示例 2：带条件默认值的组件（Alert）

```php
<?php
/**
 * 组件：Alert
 * 
 * 提示信息组件，支持多种类型和样式
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.component.frontend {default="前端组件组",name="前端组件组",description="前端组件组，用于给元素据分组用的，方便维护"}
 * @meta::theme.component.frontend.description {default="提示信息组件，支持多种类型和样式",name="提示信息组件，支持多种类型和样式",description="提示信息组件，支持多种类型和样式，用于描述组件的用途"}
 * @meta::theme.component.frontend.name {default="Alert提示组件",name="Alert提示组件",description="Alert提示组件，用于描述组件的名称"}
 * @param type {default="info",name="类型",description="类型，可选值：success/error/warning/info",type=string}
 * @param message {default="",name="提示信息",description="提示信息，必填参数",type=string}
 * @param title {default="",name="标题",description="标题，用于设置提示标题",type=string}
 * @param dismissible {default=false,name="是否可关闭",description="是否可关闭，用于控制提示是否可关闭",type=bool}
 * @param icon {default="",name="自定义图标",description="自定义图标，用于设置自定义图标",type=string}
 * @param class {default="",name="额外CSS类",description="额外CSS类，用于设置组件额外CSS类",type=string}
 */
$type = $this->getData('type') ?? 'info';
$message = $this->getData('message') ?? '';
$title = $this->getData('title') ?? '';
$dismissible = $this->getData('dismissible') ?? false;
$icon = $this->getData('icon') ?? '';
$class = $this->getData('class') ?? '';
// ... 组件代码 ...
?>
```

#### 示例 3：布局文件（auth.phtml）

```php
<?php
/**
 * 布局：个人中心 - 认证页面布局
 * 
 * 登录/注册等认证页面的专用布局
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.group.frontend {default="前端组",name="前端组",description="前端组，用于给元素据分组用的，方便维护"}
 * @meta::theme.group.frontend.description {default="登录/注册等认证页面的专用布局",name="登录/注册等认证页面的专用布局",description="登录/注册等认证页面的专用布局，用于描述布局的用途"}
 * @meta::theme.group.frontend.name {default="个人中心认证页面布局",name="个人中心认证页面布局",description="个人中心认证页面布局，用于描述布局的名称"}
 * @preview.login {default=0,option={0:不需要登录,1:需要登录},name="是否需要登录",description="是否需要登录，0不需要登录，1需要登录，这个不是元素据，是预览系统判断是否需要自动登录的。"}
 * @param title {default="登录",name="页面标题",description="页面标题，用于设置页面标题",type=string}
 * @param content {default="",name="认证表单内容（HTML字符串）",description="认证表单内容（HTML字符串），用于设置页面内容",type=string}
 * @param class {default="",name="额外CSS类",description="额外CSS类，用于设置页面额外CSS类",type=string}
 */
$title = $this->getData('title') ?? __('登录');
$content = $this->getData('content') ?? '';
$class = $this->getData('class') ?? '';
// ... 布局代码 ...
?>
```

## 参数类型说明

### 支持的类型

- **string**：字符串类型
- **int**：整数类型
- **float**：浮点数类型
- **bool**：布尔类型
- **array**：数组类型
- **mixed**：混合类型（任意类型）
- **object**：对象类型
- **callable**：可调用类型

### 类型转换建议

在代码中，建议根据类型进行适当的转换：

```php
// 字符串类型
$text = (string)($this->getData('text') ?? '');

// 整数类型
$currentPage = (int)($this->getData('currentPage') ?? 1);

// 布尔类型
$disabled = (bool)($this->getData('disabled') ?? false);

// 数组类型
$data = (array)($this->getData('data') ?? []);
```

## 默认值解析规则

### 字面量解析

- 字符串：`'value'` 或 `"value"`
- 数字：`123`、`45.67`
- 布尔值：`true`、`false`
- 数组：`[]`、`['item1', 'item2']`
- null：`null`

### PHP 变量表达式解析

当默认值包含 `$` 符号时，系统会将其识别为 PHP 变量表达式。在代码生成或解析时，可以直接使用该表达式。

**注意**：PHP 变量表达式需要确保在组件执行上下文中可用。

### 常用 PHP 变量表达式示例

```php
// 获取请求参数
[default=$this->getRequest()->getParam('page') ?: 1]

// 获取当前URL
[default=$this->getRequest()->getUriString()]

// 获取数据并设置默认值
[default=$this->getData('pageParam') ?? 'page']

// 条件表达式
[default=$type === 'success' ? 'fa-check-circle' : 'fa-info-circle']

// 类型转换
[default=(int)($this->getRequest()->getParam('page') ?: 1)]
```

## 迁移指南

### 从旧格式迁移

**旧格式**：
```php
/**
 * 组件：Pagination
 * 
 * 分页组件，用于数据分页导航
 * 字段
 * @info description 描述：分页组件，用于数据分页导航
 * @info name 名称：Pagination分页组件
 * @param currentPage 当前页码（必填）
 * @param totalPages 总页数（必填）
 * @param baseUrl 基础URL（必填）
 * @param pageParam 页码参数名
 */
```

**新格式**：
```php
/**
 * 组件：Pagination
 * 
 * 分页组件，用于数据分页导航
 * 字段
 * @meta::theme {default=Weline_Theme,name="主题命名空间",description="主题命名空间，用于主题模块的命名空间"}
 * @meta::theme.component.frontend {default="前端组件组",name="前端组件组",description="前端组件组，用于给元素据分组用的，方便维护"}
 * @meta::theme.component.frontend.description {default="分页组件，用于数据分页导航",name="分页组件，用于数据分页导航",description="分页组件，用于数据分页导航，用于描述组件的用途"}
 * @meta::theme.component.frontend.name {default="Pagination分页组件",name="Pagination分页组件",description="Pagination分页组件，用于描述组件的名称"}
 * @param currentPage {default=1,name="当前页码",description="当前页码，必填参数",type=int}
 * @param totalPages {default=1,name="总页数",description="总页数，必填参数",type=int}
 * @param baseUrl {default="",name="基础URL",description="基础URL，必填参数",type=string}
 * @param pageParam {default="page",name="页码参数名",description="页码参数名，用于URL参数",type=string}
 */
```

### 迁移步骤

1. 保留组件名称和描述
2. 添加 `@meta::` 层级结构：
   - `@meta::theme` - 主题命名空间
   - `@meta::theme.component.frontend` - 组件分组
   - `@meta::theme.component.frontend.description` - 组件描述
   - `@meta::theme.component.frontend.name` - 组件名称
3. 将 `@param` 格式转换为新格式：
   - 使用 `{}` 包裹属性
   - 添加 `default`、`name`、`description`、`type` 属性
4. 更新代码中的默认值逻辑，使用 Meta 中定义的默认值

### 层级结构说明

**拥有 `.` 子元素则认为上层是组**：
- `@meta::theme` - `theme` 是组（因为有 `theme.group`）
- `@meta::theme.group` - `group` 是组（因为有 `theme.group.frontend`）
- `@meta::theme.group.frontend` - `frontend` 是值（没有子元素）
- `@meta::theme.group.frontend.description` - `description` 是值（没有子元素）

## 最佳实践

1. **明确类型**：为每个参数指定明确的类型
2. **合理默认值**：为可选参数提供合理的默认值
3. **必填标记**：明确标记必填参数
4. **描述清晰**：参数描述要清晰明了
5. **PHP 变量使用**：在需要动态默认值时使用 PHP 变量表达式
6. **保持一致性**：所有组件使用统一的格式规范

## 工具支持

未来可以开发工具来自动：
- 解析 Meta 信息
- 生成组件文档
- 验证参数类型
- 自动生成默认值代码

## 预览登录标记使用指南

### 何时使用 @preview.login

#### 需要设置为 `[default=1]` 的布局

以下类型的布局应该设置 `@preview.login 是否需要登录 [default=1]`：

- **个人中心相关**：仪表盘、个人资料、订单列表、地址管理等
- **需要登录的功能页面**：购物车、结算页、收藏夹等
- **会员专属页面**：会员中心、积分商城等

**示例**：
```php
<?php
/**
 * 布局：个人中心 - 仪表盘布局
 * 
 * @preview.login 是否需要登录 [default=1]
 */
```

#### 需要设置为 `[default=0]` 的布局

以下类型的布局应该设置 `@preview.login 是否需要登录 [default=0]`：

- **公开页面**：首页、产品列表、产品详情、分类页等
- **认证页面**：登录页、注册页、忘记密码页等
- **不需要登录的功能页面**：帮助中心、关于我们等

**示例**：
```php
<?php
/**
 * 布局：首页 - 默认布局
 * 
 * @preview.login 是否需要登录 [default=0]
 */
```

```php
<?php
/**
 * 布局：个人中心 - 认证页面布局
 * 
 * 登录/注册等认证页面的专用布局
 * 
 * @preview.login 是否需要登录 [default=0]
 */
```

### 预览系统行为

1. **自动判断**：预览系统会根据布局文件的 `@preview.login` 标记自动决定是否需要登录
2. **手动控制**：用户可以通过预览界面的"自动登录"开关手动控制
3. **URL 参数**：可以通过 URL 参数 `auto_login=1` 或 `auto_login=0` 手动覆盖
4. **优先级**：URL 参数 > 手动开关 > 布局文件标记

### 测试账户信息

预览系统使用的测试账户信息：
- **用户名**：`preview_theme`
- **邮箱**：`preview_theme@preview.local`
- **密码**：首次访问时随机生成，并存储在 `Weline_Theme` 模块的系统配置中（可在后台系统配置中查看或重置）

如果测试账户不存在，系统会自动创建并应用上述配置。

## 注意事项

1. **PHP 变量表达式安全性**：确保 PHP 变量表达式在组件上下文中安全可用
2. **类型一致性**：Meta 中定义的类型应与代码中的实际使用保持一致
3. **向后兼容**：新格式应保持与旧格式的兼容性
4. **文档同步**：Meta 信息应与代码实现保持同步
5. **预览登录标记**：`@preview.login` 标记仅在预览阶段生效，不影响正常使用
6. **主题继承**：`@preview.login` 标记支持主题继承，会查找父主题的布局文件

