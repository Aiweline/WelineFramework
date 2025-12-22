# API路由映射错误修复

## 问题描述

用户报告"网络错误，无法加载样式列表"，实际上不是网络问题，而是**API路由映射错误**。

## 根本原因

前端JavaScript代码中使用的API路由与后端控制器方法名不匹配。

### 错误的映射

| 前端路由 | 后端方法名 | 状态 |
|---------|-----------|------|
| `page/styles` | `getStyles()` | ❌ 不匹配 |
| `page/style-config` | `getStyleConfig()` | ❌ 不匹配 |
| `page/styleConfig` | `getStyleConfig()` | ❌ 不匹配 |

### 正确的映射

| 前端路由 | 后端方法名 | 状态 |
|---------|-----------|------|
| `page/getStyles` | `getStyles()` | ✅ 匹配 |
| `page/getStyleConfig` | `getStyleConfig()` | ✅ 匹配 |

## 修复内容

### 文件：`form.phtml`

#### 1. 样式列表加载API

**修复前**：
```javascript
const apiUrl = '<?= $this->getBackendUrl('pagebuilder/backend/page/styles') ?>';
```

**修复后**：
```javascript
const apiUrl = '<?= $this->getBackendUrl('pagebuilder/backend/page/getStyles') ?>';
```

**位置**：第2162行

#### 2. 样式配置加载API（主表单）

**修复前**：
```javascript
let configUrl = '<?= $this->getBackendUrl('pagebuilder/backend/page/style-config') ?>?style_code=...';
```

**修复后**：
```javascript
let configUrl = '<?= $this->getBackendUrl('pagebuilder/backend/page/getStyleConfig') ?>?style_code=...';
```

**位置**：第1771行

#### 3. 样式配置加载API（可视化编辑器）

**修复前**：
```javascript
let url = '<?= $this->getBackendUrl('pagebuilder/backend/page/styleConfig') ?>?style_code=...';
```

**修复后**：
```javascript
let url = '<?= $this->getBackendUrl('pagebuilder/backend/page/getStyleConfig') ?>?style_code=...';
```

**位置**：第3716行

## 错误提示改进

同时改进了错误提示信息，现在会显示：
- 具体的HTTP状态码
- 详细的错误消息
- 错误堆栈信息（控制台）
- 重试按钮

### 修复前
```javascript
styleCardsEmpty.innerHTML = '网络错误，无法加载样式列表';
```

### 修复后
```javascript
// 检查HTTP响应状态
if (!response.ok) {
    const errorText = await response.text();
    console.error('❌ HTTP错误响应:', response.status, errorText);
    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
}

// 更友好的错误提示
styleCardsEmpty.innerHTML = `
    <div class="alert alert-danger">
        <i class="mdi mdi-alert-circle"></i>
        <strong>加载失败</strong><br>
        <small>${errorMessage}</small><br>
        <button class="btn btn-sm btn-outline-danger mt-2" onclick="loadStyleList()">
            <i class="mdi mdi-refresh"></i> 重试
        </button>
    </div>
`;
```

## WelineFramework路由规则

在WelineFramework中，后台控制器路由映射规则：

```
URL路由：/模块名/backend/控制器/方法名
控制器：模块名\Controller\Backend\控制器类
方法：public function 方法名()
```

### 示例

**后端方法**：
```php
class Page extends BackendController {
    public function getStyles() {
        // ...
    }
}
```

**正确的前端URL**：
```
/pagebuilder/backend/page/getStyles
```

**错误的URL**：
```
/pagebuilder/backend/page/styles      ❌ 缺少"get"前缀
/pagebuilder/backend/page/style-config ❌ 使用连字符而非驼峰
```

## 验证方法

### 1. 检查控制器方法

打开 `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php`，查找方法：

```php
#[Acl(...)]
public function getStyles() {
    // 方法名是 getStyles，不是 styles
}

#[Acl(...)]
public function getStyleConfig() {
    // 方法名是 getStyleConfig，不是 styleConfig 或 style-config
}
```

### 2. 测试API调用

在浏览器控制台（F12）中手动测试：

```javascript
// 测试样式列表API
fetch('/你的后台前缀/pagebuilder/backend/page/getStyles', {
    method: 'GET',
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
}).then(r => r.json()).then(d => console.log(d));
```

### 3. 查看控制台日志

刷新页面后打开浏览器控制台，应该看到：

```
🚀 开始加载样式列表...
📡 请求 URL: /xxx/pagebuilder/backend/page/getStyles
📥 收到响应: 200 OK
📦 解析响应数据: {success: true, data: [...], count: 2}
✅ 样式列表加载成功，共 2 个样式
```

## 常见错误模式

### 1. ❌ 缺少动词前缀

```javascript
// 错误
'page/styles'      // 应该是 'page/getStyles'
'page/config'      // 应该是 'page/getConfig'
'page/list'        // 应该是 'page/getList'
```

### 2. ❌ 使用连字符

```javascript
// 错误
'page/style-config'    // 应该是 'page/getStyleConfig'
'page/check-handle'    // 应该是 'page/checkHandle'
```

### 3. ❌ 大小写错误

```javascript
// 错误
'page/getstyles'       // 应该是 'page/getStyles'（驼峰式）
'page/GetStyles'       // 应该是 'page/getStyles'（首字母小写）
```

## 预防措施

1. **命名规范**：后端方法使用驼峰式命名（getStyleConfig）
2. **前端调用**：URL路由直接使用方法名（page/getStyleConfig）
3. **代码审查**：检查前端路由与后端方法是否一致
4. **错误提示**：显示详细错误信息便于调试
5. **控制台日志**：记录完整的请求URL和响应状态

## 测试清单

- [x] 样式列表能正常加载
- [x] 样式配置能正常加载
- [x] 可视化编辑器样式配置正常
- [x] 错误提示信息清晰
- [x] 控制台日志完整
- [x] 重试按钮功能正常

## 影响范围

**修复的功能**：
- ✅ 样式卡片列表加载
- ✅ 样式配置字段加载（主表单）
- ✅ 样式配置字段加载（可视化编辑器）
- ✅ 错误提示改进

**不受影响的功能**：
- ✅ 页面保存
- ✅ 页面预览
- ✅ Handle唯一性检查
- ✅ 预览图片获取

## 相关文件

- `app/code/GuoLaiRen/PageBuilder/view/templates/Backend/Page/form.phtml` - 前端修复
- `app/code/GuoLaiRen/PageBuilder/Controller/Backend/Page.php` - 后端方法（无需修改）

## 修复日期

2025-10-19

