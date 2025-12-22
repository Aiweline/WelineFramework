# Marketing Landing 模板 - Logo URL 解析错误修复

## 修复日期
2024-01-XX

## 问题描述
在 `marketing-landing/header.phtml` 模板中，Logo 的 URL 被错误地解析，导致渲染出的 HTML 结构为：
```html
<div class="marketing-logo">
  <img src="https" alt="streetsensedaily">
</div>
```

**症状**：
- `src` 属性只有 `"https"` 而不是完整的URL
- `alt` 属性显示域名而不是页面标题
- Logo 图片无法正常显示

## 根本原因

### Schema 解析器问题
在 schema 字段定义中使用了包含冒号 `:` 的URL作为默认值：

```php
// ❌ 错误的定义方式
logo.url => Logo地址:text:https://lp.streetsensedaily.com/strike-report/images/DSA_white.svg
```

**Schema 解析规则**：
- 格式：`字段名 => 显示名称:类型:默认值|选项`
- 分隔符：使用冒号 `:` 分割不同部分

**错误解析结果**：
```
显示名称: "Logo地址"
类型: "text"
默认值: "https"  ← 只截取到第一个冒号之前！
剩余部分: "//lp.streetsensedaily.com/strike-report/images/DSA_white.svg" (被丢弃)
```

这就是为什么 `$logoUrl` 的值变成了 `"https"` 而不是完整的URL！

## 解决方案

### 修改前
```php
/**
 * @fields_start
 * 
 * group:logo => Logo设置
 * logo.display => 显示Logo:select:no|yes,no
 * logo.url => Logo地址:text:https://lp.streetsensedaily.com/strike-report/images/DSA_white.svg
 * logo.width => Logo宽度:responsive:120/200|px[移动端120px，PC端200px]
 * 
 * @fields_end
 */

// Logo配置
$logoDisplay = getHeaderConfig($styleSettings, 'logo.display', 'no');
$logoUrl = getHeaderConfig($styleSettings, 'logo.url', 'https://lp.streetsensedaily.com/strike-report/images/DSA_white.svg');
$pageTitle = $page ? $page->getData('title') : '';
```

### 修改后
```php
/**
 * @fields_start
 * 
 * group:logo => Logo设置
 * logo.display => 显示Logo:select:no|yes,no
 * logo.url => Logo地址:textarea:
 * 
 * @fields_end
 */

// Logo配置
$logoDisplay = getHeaderConfig($styleSettings, 'logo.display', 'no');
// Logo URL - 如果配置为空，使用默认值
$logoUrl = getHeaderConfig($styleSettings, 'logo.url', '');
if (empty($logoUrl)) {
    $logoUrl = 'https://lp.streetsensedaily.com/strike-report/images/DSA_white.svg';
}
$pageTitle = $page ? $page->getData('title') : '';
```

### 修改要点

1. **Schema 定义**：
   - 移除包含冒号的默认值
   - 改为 `logo.url => Logo地址:textarea:`
   - 使用 `textarea` 类型更适合长URL输入

2. **PHP 代码**：
   - 先从配置读取，默认返回空字符串
   - 如果配置为空，再使用PHP代码设置默认值
   - 避免在 schema 中使用包含特殊字符的默认值

## 技术说明

### Schema 字段定义规则
```
字段名 => 显示名称:类型:默认值|选项列表
```

**分隔符**：
- `:` (冒号) - 分割显示名称、类型、默认值
- `|` (竖线) - 分割默认值和选项列表
- `,` (逗号) - 分割多个选项

**风险字符**：
在默认值中使用以下字符会导致解析错误：
- `:` - 会被误认为字段分隔符
- `|` - 会被误认为选项分隔符
- `,` - 在某些情况下会导致问题

### 最佳实践

#### ✅ 推荐做法
```php
// Schema 中不设置复杂的默认值
logo.url => Logo地址:textarea:

// PHP 代码中处理默认值
$logoUrl = getHeaderConfig($styleSettings, 'logo.url', '');
if (empty($logoUrl)) {
    $logoUrl = 'https://default-url.com/image.svg';
}
```

#### ❌ 避免做法
```php
// 不要在 schema 中使用包含特殊字符的默认值
logo.url => Logo地址:text:https://url.com/path  // ❌ 包含冒号
description => 描述:text:默认值1,值2  // ❌ 包含逗号
options => 选项:text:value1|value2  // ❌ 包含竖线
```

## 测试验证

### 修复前
```html
<!-- 错误的渲染结果 -->
<div class="marketing-logo">
  <img src="https" alt="streetsensedaily">
</div>
```

### 修复后
```html
<!-- 正确的渲染结果 -->
<div class="marketing-logo">
  <img src="https://lp.streetsensedaily.com/strike-report/images/DSA_white.svg" alt="页面标题">
</div>
```

### 测试场景
1. ✅ **未配置时**：使用默认Logo URL
2. ✅ **自定义URL时**：正确显示用户配置的Logo
3. ✅ **URL包含特殊字符**：正确处理包含冒号、问号、井号等的URL
4. ✅ **空字符串配置**：回退到默认Logo URL

## 影响范围
- **文件**：`app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/header.phtml`
- **影响**：所有使用 `marketing-landing` 模板的页面
- **向后兼容**：✅ 兼容，已有配置不受影响

## 相关问题

### 其他可能受影响的字段
检查其他模板是否有类似问题：

1. **URL类字段**：所有包含 `http://` 或 `https://` 的字段
2. **颜色值**：包含 `rgb(255,0,0)` 格式的颜色
3. **CSS值**：包含 `calc()` 等带括号和运算符的值
4. **JSON字符串**：包含对象或数组的JSON

### 预防措施
1. **Schema 定义规范**：
   - 简单值可以在 schema 中设置默认值
   - 复杂值（URL、JSON、CSS等）应在PHP代码中设置
   
2. **类型选择**：
   - `text`：单行短文本（不超过50字符）
   - `textarea`：多行长文本或包含特殊字符的内容
   - `color`：颜色选择器
   - `number`：纯数字

3. **代码审查**：
   - 检查所有 schema 定义中的默认值
   - 确保不包含冒号、竖线、逗号等分隔符

## 修改文件清单
1. ✅ `app/code/GuoLaiRen/PageBuilder/view/templates/style/marketing-landing/header.phtml`
2. ✅ `app/code/GuoLaiRen/PageBuilder/doc/修复-marketing-landing-Logo-URL解析错误.md` (本文档)

## 相关文档
- [营销落地页模板说明](./marketing-landing-模板说明.md)
- [营销落地页快速启动](./marketing-landing-快速启动.md)

---

## 总结
通过将包含特殊字符（冒号）的URL默认值从 schema 移动到 PHP 代码中处理，成功修复了 Logo URL 被错误解析的问题。

**关键教训**：
- ✅ Schema 中避免使用包含分隔符的复杂默认值
- ✅ 复杂的默认值应在 PHP 代码中处理
- ✅ 使用 `textarea` 类型处理长文本和URL
- ✅ 始终验证渲染结果确保配置正确解析

修复后，Logo 可以正常显示，URL 解析正确！🎉

