---
name: friendly-notifications
description: |
  Implements user-friendly notifications and dialogs in Weline Framework. 
  CRITICAL - NEVER use native alert()/confirm()/prompt()! Always use BackendToast/BackendConfirm.
  CRITICAL - All error messages MUST be detailed, friendly, and actionable!
  
  MUST use when:
  - Showing any message, notification, toast, tip, hint to user
  - Asking user for confirmation (delete, submit, dangerous action)
  - Showing success/error/warning/info feedback
  - Any user prompt or dialog
  - Asking user to input something (用户输入, 输入框, input dialog)
  - Writing error messages or exception handling
  - Batch operation results (success/fail counts)
  - API response messages
  
  Keywords: 
  提示, 通知, 消息, 弹窗, 弹出, 对话框, 确认, 警告, 错误提示, 成功提示,
  toast, notification, message, alert, confirm, prompt, dialog, modal,
  popup, popover, snackbar, feedback, warning, error message, success message,
  用户提示, 操作确认, 删除确认, 提交确认, 保存成功, 保存失败, 操作成功, 操作失败,
  BackendToast, BackendConfirm, AdminToast, AdminConfirm, FrontendToast,
  用户输入, 输入框, 输入弹窗, 让用户输入, 请输入, input dialog, showInput, 输入对话框,
  请用户填写, 让用户填写, 填入, 获取用户输入,
  错误信息, 错误详情, 失败原因, 失败详情, 批量操作, 批量结果, 操作结果,
  error detail, failure reason, batch result, operation result,
  提示不够, 提示不全, 提示不详, 错误不清楚, 错误不明确, 错误太简短,
  详尽提示, 友好提示, 可操作提示, actionable error, detailed error,
  fetchJson, API 返回, 返回消息, response message, msg, message
globs:
  - "**/*.js"
  - "**/*.phtml"
  - "**/*.php"
alwaysApply: false
---

# 友好通知技能 (Friendly Notifications Skill)

## 目的
确保所有用户提示、确认和通知都使用友好的自定义UI，而不是原生浏览器弹窗。

## 适用场景
- ✅ 任何需要向用户展示消息、提示、警告、错误的场景
- ✅ 需要用户确认的操作（删除、提交等）
- ✅ 需要用户输入的场景
- ✅ 成功/失败状态反馈
- ✅ 加载/处理中状态

## 禁止使用
**严格禁止使用以下原生浏览器API：**
- ❌ `alert()` - 阻塞式警告框
- ❌ `confirm()` - 阻塞式确认框
- ❌ `prompt()` - 阻塞式输入框
- ❌ `window.alert()`, `window.confirm()`, `window.prompt()`

## 推荐方案

### 框架内置组件（推荐优先使用）

#### BackendToast（后台专用，推荐）⭐

后台页面已在 `head.phtml` 中统一引入 `BackendToast`，可直接使用：

**文件位置：** `Weline_Theme::theme/backend/assets/js/backend-components.js`

**默认显示时间：10 秒（10000ms）**

```javascript
// 成功提示
BackendToast.success('保存成功');
BackendToast.success('删除成功', 2000);  // 自定义显示时间（毫秒）

// 错误提示
BackendToast.error('保存失败');
BackendToast.error('网络错误：' + error.message);

// 警告提示
BackendToast.warning('请填写必填项');

// 信息提示
BackendToast.info('正在处理...');

// 永不自动消失（duration = 0）
BackendToast.info('请等待处理完成...', 0);

// 支持 HTML 内容（使用配置对象）⭐
BackendToast.warning('操作失败<br><a href="/admin/config" class="btn btn-sm btn-primary">去配置</a>', {
    duration: 15000,  // 15秒
    html: true        // 允许 HTML 内容
});

// HTML 内容示例（带链接和按钮）
BackendToast.error('发布失败<div class="mt-2"><a href="/config" class="btn btn-sm btn-outline-light">配置默认模型</a></div>', {
    duration: 0,  // 不自动消失，需手动关闭
    html: true
});
```

**第二个参数支持两种格式：**
1. **数字**：显示时间（毫秒），如 `5000`
2. **配置对象**：`{ duration: 10000, html: false }`
   - `duration`：显示时间，默认 10000ms，设为 0 则不自动消失
   - `html`：是否允许 HTML 内容，默认 `false`（会转义 HTML）

> **向后兼容**：`AdminToast` 仍可使用（是 `BackendToast` 的别名），但**新代码推荐使用 `BackendToast`**。

#### BackendConfirm（后台专用，替代 confirm）⭐

后台页面已在 `head.phtml` 中统一引入 `BackendConfirm`，返回 Promise：

```javascript
// 基本确认（Promise 风格，推荐）
BackendConfirm.show('确认删除这 3 个项目吗？此操作不可恢复。').then(confirmed => {
    if (confirmed) {
        doDelete();
        BackendToast.success('删除成功');
    }
});

// 带自定义选项
BackendConfirm.show('确认提交审核吗？', {
    title: '提交确认',
    confirmText: '提交',
    cancelText: '稍后',
    type: 'warning'  // warning, danger, info, success
}).then(confirmed => {
    if (confirmed) {
        submitForReview();
    }
});

// 危险操作示例
BackendConfirm.show('确认清空所有缓存数据吗？此操作将影响系统性能。', {
    title: '清空数据',
    type: 'danger',
    confirmText: '清空'
}).then(confirmed => {
    if (confirmed) {
        clearAllCache();
        BackendToast.success('缓存已清空');
    }
});
```

> **向后兼容**：`AdminConfirm` 仍可使用（是 `BackendConfirm` 的别名），但**新代码推荐使用 `BackendConfirm`**。

#### BackendConfirm.showInput（后台输入框，替代 prompt）⭐

用于需要用户输入内容的场景，**禁止使用原生 `prompt()`**：

```javascript
// 基本输入对话框
BackendConfirm.showInput({
    title: '重命名',
    message: '请输入新名称：',
    defaultValue: '当前名称',
    placeholder: '输入名称...'
}).then(value => {
    if (value !== null) {  // null 表示用户取消
        doRename(value);
        BackendToast.success('重命名成功');
    }
});

// 批量操作示例（如批量切换 DNS）
BackendConfirm.showInput({
    title: '批量切换 DNS',
    message: '请输入新的 DNS 服务器（逗号分隔）',
    defaultValue: 'ns1.cloudflare.com,ns2.cloudflare.com',
    confirmText: '确定',
    cancelText: '取消'
}).then(dnsServers => {
    if (dnsServers && dnsServers.trim()) {
        changeDns(dnsServers.trim());
    }
});

// 带类型样式
BackendConfirm.showInput({
    title: '设置数量',
    message: '请输入新的库存数量：',
    type: 'warning',  // info, warning, success, danger
    defaultValue: '100'
}).then(qty => {
    if (qty !== null) {
        updateStock(qty);
    }
});
```

**返回值**：
- 用户确认时返回输入的字符串值
- 用户取消（点击取消、按 ESC、点击遮罩）时返回 `null`

#### FrontendToast（前台需自行实现）

前台暂无内置 Toast 组件，可参考以下实现或使用第三方库。

### 1. Toast 提示（非阻塞式消息）
用于简短的成功/失败/信息提示。

```javascript
// 显示 Toast 提示（自定义实现示例）
function showToast(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 6px;
        font-size: 14px;
        z-index: 10000;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    `;
    
    // 根据类型设置样式
    const styles = {
        success: { background: '#d4edda', color: '#155724', border: '1px solid #c3e6cb' },
        error: { background: '#f8d7da', color: '#721c24', border: '1px solid #f5c6cb' },
        warning: { background: '#fff3cd', color: '#856404', border: '1px solid #ffc107' },
        info: { background: '#d1ecf1', color: '#0c5460', border: '1px solid #bee5eb' }
    };
    
    Object.assign(toast.style, styles[type] || styles.info);
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// 使用示例
showToast('保存成功', 'success');
showToast('删除失败', 'error');
showToast('正在处理...', 'info');
```

### 2. 确认对话框（替代 confirm）
用于需要用户确认的操作。

```javascript
// 显示确认对话框
function showConfirmDialog(options) {
    const {
        title = '确认操作',
        message,
        confirmText = '确认',
        cancelText = '取消',
        confirmClass = 'danger', // danger, primary, success
        onConfirm,
        onCancel
    } = options;
    
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    const dialog = document.createElement('div');
    dialog.style.cssText = `
        background: white;
        border-radius: 8px;
        padding: 20px;
        max-width: 400px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    `;
    
    const btnColors = {
        danger: { bg: '#dc3545', hover: '#c82333' },
        primary: { bg: '#007bff', hover: '#0056b3' },
        success: { bg: '#28a745', hover: '#218838' }
    };
    
    const color = btnColors[confirmClass] || btnColors.danger;
    
    dialog.innerHTML = `
        <h3 style="margin: 0 0 10px 0; font-size: 18px; color: #333;">${title}</h3>
        <p style="margin: 0 0 20px 0; color: #666; line-height: 1.5;">${message}</p>
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button id="btnCancel" style="
                padding: 8px 16px;
                border: 1px solid #ddd;
                background: white;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            ">${cancelText}</button>
            <button id="btnConfirm" style="
                padding: 8px 16px;
                border: none;
                background: ${color.bg};
                color: white;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            ">${confirmText}</button>
        </div>
    `;
    
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    
    // 绑定事件
    const btnConfirm = dialog.querySelector('#btnConfirm');
    const btnCancel = dialog.querySelector('#btnCancel');
    
    btnConfirm.addEventListener('mouseenter', () => btnConfirm.style.background = color.hover);
    btnConfirm.addEventListener('mouseleave', () => btnConfirm.style.background = color.bg);
    
    btnConfirm.addEventListener('click', () => {
        overlay.remove();
        if (onConfirm) onConfirm();
    });
    
    btnCancel.addEventListener('click', () => {
        overlay.remove();
        if (onCancel) onCancel();
    });
    
    // 点击背景关闭
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.remove();
            if (onCancel) onCancel();
        }
    });
}

// 使用示例
showConfirmDialog({
    title: '删除确认',
    message: '确认删除这些部件吗？此操作不可恢复。',
    confirmText: '删除',
    cancelText: '取消',
    confirmClass: 'danger',
    onConfirm: () => {
        // 执行删除操作
        console.log('已确认删除');
    },
    onCancel: () => {
        console.log('已取消');
    }
});
```

### 3. 输入对话框（替代 prompt）
用于需要用户输入的场景。

```javascript
// 显示输入对话框
function showInputDialog(options) {
    const {
        title = '输入',
        message,
        placeholder = '',
        defaultValue = '',
        confirmText = '确认',
        cancelText = '取消',
        onConfirm,
        onCancel
    } = options;
    
    const overlay = document.createElement('div');
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    `;
    
    const dialog = document.createElement('div');
    dialog.style.cssText = `
        background: white;
        border-radius: 8px;
        padding: 20px;
        max-width: 400px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    `;
    
    dialog.innerHTML = `
        <h3 style="margin: 0 0 10px 0; font-size: 18px; color: #333;">${title}</h3>
        <p style="margin: 0 0 10px 0; color: #666; line-height: 1.5;">${message}</p>
        <input type="text" id="inputValue" value="${defaultValue}" placeholder="${placeholder}" style="
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            margin-bottom: 20px;
            box-sizing: border-box;
        ">
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button id="btnCancel" style="
                padding: 8px 16px;
                border: 1px solid #ddd;
                background: white;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            ">${cancelText}</button>
            <button id="btnConfirm" style="
                padding: 8px 16px;
                border: none;
                background: #007bff;
                color: white;
                border-radius: 4px;
                cursor: pointer;
                font-size: 14px;
            ">${confirmText}</button>
        </div>
    `;
    
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    
    const input = dialog.querySelector('#inputValue');
    const btnConfirm = dialog.querySelector('#btnConfirm');
    const btnCancel = dialog.querySelector('#btnCancel');
    
    input.focus();
    input.select();
    
    btnConfirm.addEventListener('click', () => {
        const value = input.value;
        overlay.remove();
        if (onConfirm) onConfirm(value);
    });
    
    btnCancel.addEventListener('click', () => {
        overlay.remove();
        if (onCancel) onCancel();
    });
    
    // Enter 确认
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            btnConfirm.click();
        }
    });
}

// 使用示例
showInputDialog({
    title: '重命名',
    message: '请输入新名称：',
    placeholder: '新名称',
    defaultValue: '旧名称',
    onConfirm: (value) => {
        console.log('新名称:', value);
    }
});
```

### 4. 状态提示（加载中/成功/失败）
用于显示操作进度和结果。

```javascript
// 创建状态提示元素
function createStatusIndicator(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return null;
    
    const status = document.createElement('div');
    status.style.cssText = `
        padding: 10px;
        border-radius: 4px;
        font-size: 13px;
        display: none;
    `;
    container.appendChild(status);
    
    return {
        show: (message, type = 'info') => {
            const styles = {
                loading: { background: '#d1ecf1', color: '#0c5460' },
                success: { background: '#d4edda', color: '#155724' },
                error: { background: '#f8d7da', color: '#721c24' },
                info: { background: '#d1ecf1', color: '#0c5460' }
            };
            Object.assign(status.style, styles[type] || styles.info);
            status.textContent = message;
            status.style.display = 'block';
        },
        hide: () => {
            status.style.display = 'none';
        }
    };
}

// 使用示例
const status = createStatusIndicator('my-container');
status.show('正在处理...', 'loading');
// 操作完成后
status.show('✓ 处理成功', 'success');
setTimeout(() => status.hide(), 2000);
```

## CSS 动画（可选）

```css
@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}
```

## 检查清单

在编写或审查代码时，确保：
- [ ] 没有使用 `alert()`
- [ ] 没有使用 `confirm()`
- [ ] 没有使用 `prompt()`
- [ ] 所有提示都使用自定义UI
- [ ] 提示消息清晰、友好
- [ ] 确认对话框有明确的操作按钮
- [ ] 危险操作使用红色按钮
- [ ] 有适当的动画效果

## 迁移示例

### 之前（❌ 错误）
```javascript
if (confirm('确认删除？')) {
    deleteItem();
    alert('删除成功');
}
```

### 之后（✅ 正确）
```javascript
showConfirmDialog({
    title: '删除确认',
    message: '确认删除此项目吗？',
    confirmClass: 'danger',
    onConfirm: () => {
        deleteItem();
        showToast('删除成功', 'success');
    }
});
```

## 错误信息规范（强制）⭐

**所有错误信息必须详尽、友好、可操作！**

### 1. 错误信息必须包含

- ✅ **发生了什么**：具体描述错误现象
- ✅ **为什么发生**：可能的原因分析
- ✅ **如何解决**：明确的解决步骤或建议

### 2. 禁止的错误信息

```javascript
// ❌ 禁止：过于简短、无法操作
AdminToast.error('操作失败');
AdminToast.error('保存失败');
AdminToast.error('网络错误');
AdminToast.error('无法获取数据');

// ✅ 正确：详尽、可操作
AdminToast.error('保存失败：请检查网络连接后重试');
AdminToast.error('无法获取 Cloudflare Account ID。请检查：1) API Token 是否具有「Account:Read」权限；2) 或在账户配置中手动填写 Account ID');
AdminToast.error('DNS 切换失败：域名 example.com 的 Nameserver 更新被拒绝，可能是域名锁定状态，请先解锁域名');
```

### 3. 批量操作必须显示详细结果

```javascript
// ❌ 禁止：只显示成功/失败数量
AdminToast.success('操作完成：成功 5 个，失败 2 个');

// ✅ 正确：失败时弹出详情弹窗
if (failedCount > 0) {
    AdminToast.warning('操作部分完成：成功 ' + successCount + ' 个，失败 ' + failedCount + ' 个');
    showErrorDetailModal(errors);  // 显示详细错误弹窗
} else {
    AdminToast.success('操作成功：' + successCount + ' 个');
}
```

### 4. 错误详情弹窗示例

```javascript
function showErrorDetailModal(errors) {
    var html = '<div class="modal-overlay">' +
        '<div class="modal">' +
        '<h3><i class="mdi mdi-alert-circle"></i> 操作失败详情</h3>' +
        '<div class="alert alert-warning">' +
        '<i class="mdi mdi-information"></i> 以下项目操作失败，请根据提示修正后重试。' +
        '</div>' +
        '<ul class="error-list">';
    
    errors.forEach(function(err) {
        // 解析错误，显示域名/项目名 + 具体错误原因
        html += '<li>' + escapeHtml(err) + '</li>';
    });
    
    html += '</ul>' +
        '<button onclick="closeModal()">关闭</button>' +
        '</div></div>';
    
    document.body.insertAdjacentHTML('beforeend', html);
}
```

### 5. 后端错误信息也要友好

```php
// ❌ 禁止：简短错误
return $this->fetchJson(['success' => false, 'msg' => __('操作失败')]);

// ✅ 正确：详尽错误
return $this->fetchJson([
    'success' => false,
    'msg' => __('无法获取 Cloudflare Account ID。请检查：1) API Token 是否具有「Account:Read」权限；2) 或在账户配置中手动填写 Account ID（登录 Cloudflare → 任意域名 → Overview → 右下角复制 Account ID）')
]);
```

## 注意事项

1. **一致性**：在整个项目中使用相同的提示风格
2. **可访问性**：确保提示对键盘导航友好
3. **响应式**：在移动设备上也能良好显示
4. **Z-index**：确保提示层级正确，不被其他元素遮挡
5. **清理**：确保提示元素被正确移除，避免内存泄漏
6. **错误详尽**：所有错误信息必须详尽、可操作，禁止简短无意义的错误提示

## 相关技能 (Related Skills)

- `theme-development` - **主题开发必读**：BackendToast 和 BackendConfirm 使用示例
- `weline-routing` - 控制器路由规范，API 返回响应时配合使用
- `module-development` - 模块开发工作流，创建用户交互功能时必须使用友好提示

## 相关文件

- **后台全局组件**: `app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js`（BackendToast、BackendConfirm 定义）
- **后台 head 引入**: `app/code/Weline/Admin/view/templates/common/head.phtml`（统一加载全局组件）
- 主题编辑器 Toast 实现: `app/code/Weline/Theme/view/statics/js/theme-editor.js`
- 孤儿部件确认示例: `app/code/Weline/Theme/Observer/LayoutSlotRenderer.php`
