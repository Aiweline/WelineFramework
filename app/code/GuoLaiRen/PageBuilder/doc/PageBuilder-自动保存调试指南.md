# PageBuilder 可视化配置自动保存调试指南

## 问题现象

可视化编辑中，自动保存提示"页面ID不能为空"。

## 问题原因分析

可能的原因包括：
1. **前端发送 JSON 数据，后端解析不正确**：前端使用 `JSON.stringify` 发送数据，后端需要从 `php://input` 读取原始请求体并解析 JSON
2. **前端 visualPageId 未正确初始化**：JavaScript 变量未获取到页面 ID
3. **数据格式问题**：前后端数据格式不一致

## 修复方案

### 1. 后端修改（Preview.php）

**修改前：**
```php
$pageId = (int)$this->request->getPost('page_id');
$styleConfig = $this->request->getPost('style_config', []);
```

**修改后：**
```php
// 获取 JSON 请求体
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// 如果 JSON 解析失败，尝试从 POST 获取
if (!$data) {
    $data = [
        'page_id' => $this->request->getPost('page_id'),
        'style_config' => $this->request->getPost('style_config', [])
    ];
}

$pageId = (int)($data['page_id'] ?? 0);
$styleConfig = $data['style_config'] ?? [];
```

### 2. 前端修改（form.phtml）

**添加了完善的调试日志：**

```javascript
// 初始化时显示
console.log('📋 可视化配置初始化', {
    visualPageId: visualPageId,
    pageIdType: typeof visualPageId,
    pageIdValid: visualPageId > 0
});

// 保存时显示
console.log('💾 自动保存配置', {
    url: '...',
    pageId: visualPageId,
    configKeys: Object.keys(config),
    requestData: requestData
});

// 响应时显示
console.log('✅ 保存响应', {
    success: result.success,
    message: result.message,
    debug: result.debug,
    fullResult: result
});
```

### 3. 变量初始化修复

**修改前：**
```php
const visualPageId = <?= $visualPageId ?>;
```

**修改后：**
```php
const visualPageId = <?= isset($visualPageId) ? $visualPageId : 'null' ?>;
```

## 调试步骤

### 1. 打开页面编辑页面

访问：`后台 > PageBuilder > 页面管理 > 编辑页面`

### 2. 打开浏览器开发者工具

按 `F12` 打开开发者工具，切换到 Console（控制台）标签页

### 3. 点击"可视化配置"按钮

查看控制台输出：

```
📋 可视化配置初始化 {
    visualPageId: 1,
    pageIdType: "number",
    pageIdValid: true
}
```

**检查点：**
- ✅ `visualPageId` 应该是一个数字（页面ID）
- ✅ `pageIdType` 应该是 `"number"`
- ✅ `pageIdValid` 应该是 `true`

**如果 visualPageId 是 null：**
- 说明 PHP 变量 `$visualPageId` 未定义
- 检查页面是否处于编辑模式（`$isEdit` 为 true）
- 检查页面对象是否存在且有 ID

### 4. 修改配置项

在可视化配置中修改任何配置项（如 Logo 位置），等待 1 秒触发自动保存。

查看控制台输出：

```
💾 自动保存配置 {
    url: "http://127.0.0.1:9981/.../pagebuilder/backend/preview/autoSave",
    pageId: 1,
    configKeys: ["layout.logo_position", "style.background_color", ...],
    requestData: {
        page_id: 1,
        style_config: { ... }
    }
}
```

**检查点：**
- ✅ `pageId` 应该是正确的页面 ID（数字）
- ✅ `requestData.page_id` 应该等于 `pageId`
- ✅ `configKeys` 应该包含你修改的配置项

**如果 pageId 是 null 或 0：**
- 说明前端 `visualPageId` 变量有问题
- 返回步骤 3 检查初始化日志

### 5. 查看保存响应

查看控制台输出：

```
✅ 保存响应 {
    success: true,
    message: "配置已自动保存",
    debug: undefined,
    fullResult: { ... }
}
```

**检查点：**
- ✅ `success` 应该是 `true`
- ✅ `message` 应该是成功消息

**如果 success 是 false：**
- 查看 `message` 了解错误原因
- 查看 `debug` 对象（如果存在）了解详细信息

例如错误响应：
```javascript
❌ 保存失败: {
    success: false,
    message: "页面ID不能为空",
    debug: {
        received_data: { ... },
        page_id_value: null
    }
}
```

### 6. 查看后端日志

后端也会记录详细的调试日志：

```php
📥 自动保存请求
🔍 解析后的数据
```

查看日志文件：`var/log/dev.log`

```bash
php bin/w log:tail dev
```

**检查点：**
- 后端是否收到了正确的 JSON 数据
- `page_id` 是否被正确解析
- `style_config` 是否包含配置数据

## 常见问题

### 问题 1：visualPageId 是 null

**原因：** PHP 变量 `$visualPageId` 未定义

**解决方案：**
1. 确保在编辑模式下（URL 包含 `id=xxx`）
2. 检查 `form.phtml` 第 1652-1655 行的条件块：
   ```php
   <?php if ($isEdit && $page && $page->getId()): 
   $visualPageId = $page->getId();
   $visualStyleSettings = $page->getStyleSettings();
   ?>
   ```

### 问题 2：后端收到的 page_id 是 null

**原因：** JSON 解析失败或数据格式不正确

**解决方案：**
1. 查看后端日志中的 `rawBody`，应该是 JSON 字符串
2. 查看 `jsonData`，应该是解析后的数组
3. 如果 `jsonData` 是 null，说明 JSON 解析失败

### 问题 3：请求返回 404

**原因：** 路由未注册

**解决方案：**
```bash
php bin/w setup:upgrade -m GuoLaiRen_PageBuilder
php bin/w cache:clear -f
```

### 问题 4：请求返回 403

**原因：** ACL 权限问题

**解决方案：**
1. 确保已登录后台
2. 确保用户有 `GuoLaiRen_PageBuilder::page_builder_auto_save` 权限
3. 清除 ACL 缓存：`php bin/w cache:clear -f`

## 成功的完整日志示例

```
📋 可视化配置初始化 {
    visualPageId: 1,
    pageIdType: "number",
    pageIdValid: true
}

💾 自动保存配置 {
    url: "http://127.0.0.1:9981/.../pagebuilder/backend/preview/autoSave",
    pageId: 1,
    configKeys: ["layout.logo_position"],
    requestData: {
        page_id: 1,
        style_config: {
            "layout.logo_position": "center"
        }
    }
}

✅ 保存响应 {
    success: true,
    message: "配置已自动保存",
    debug: undefined,
    fullResult: {
        success: true,
        message: "配置已自动保存",
        data: { ... }
    }
}
```

## 移除调试日志

测试完成后，可以移除调试日志以减少输出：

### 前端（form.phtml）

删除或注释以下 `console.log` 语句：
- 第 1946-1950 行：初始化日志
- 第 2023-2028 行：保存请求日志
- 第 2042-2047 行：保存响应日志

### 后端（Preview.php）

删除或注释以下 `$this->log` 语句：
- 第 243-250 行：请求日志
- 第 262-266 行：解析日志

## 总结

自动保存功能的数据流：

```
前端 JavaScript
    ↓
visualPageId (从 PHP 传递)
    ↓
收集配置项 (config)
    ↓
构造请求数据 {page_id, style_config}
    ↓
JSON.stringify() 编码
    ↓
fetch() POST 请求
    ↓
后端 Preview::autoSave()
    ↓
file_get_contents('php://input') 读取
    ↓
json_decode() 解码
    ↓
提取 page_id 和 style_config
    ↓
验证 page_id > 0
    ↓
加载页面、合并配置、保存
    ↓
返回 JSON 响应
    ↓
前端接收并显示状态
    ↓
刷新预览 iframe
```

关键点：
1. ✅ 前端必须正确获取 `visualPageId`
2. ✅ 后端必须从 `php://input` 读取并解析 JSON
3. ✅ 数据格式必须一致（`{page_id: number, style_config: object}`）

