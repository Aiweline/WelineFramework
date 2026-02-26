---
name: friendly-notifications
description: |
  Implements user-friendly notifications and dialogs in Weline Framework. 
  CRITICAL - NEVER use native alert()/confirm()/prompt()! Always use BackendToast/BackendConfirm.
  
  MUST use when:
  - Showing any message, notification, toast, tip, hint to user
  - Asking user for confirmation (delete, submit, dangerous action)
  - Showing success/error/warning/info feedback
  - Any user prompt or dialog
  
  Keywords: 
  提示, 通知, 消息, 弹窗, 弹出, 对话框, 确认, 警告, 错误提示, 成功提示,
  toast, notification, message, alert, confirm, prompt, dialog, modal,
  popup, popover, snackbar, feedback, warning, error message, success message,
  用户提示, 操作确认, 删除确认, 提交确认, 保存成功, 保存失败, 操作成功, 操作失败,
  BackendToast, BackendConfirm, AdminToast, AdminConfirm, FrontendToast
globs:
  - "**/*.js"
  - "**/*.phtml"
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
```

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

## 注意事项

1. **一致性**：在整个项目中使用相同的提示风格
2. **可访问性**：确保提示对键盘导航友好
3. **响应式**：在移动设备上也能良好显示
4. **Z-index**：确保提示层级正确，不被其他元素遮挡
5. **清理**：确保提示元素被正确移除，避免内存泄漏

## 相关技能 (Related Skills)

- `theme-development` - **主题开发必读**：BackendToast 和 BackendConfirm 使用示例
- `weline-routing` - 控制器路由规范，API 返回响应时配合使用
- `module-development` - 模块开发工作流，创建用户交互功能时必须使用友好提示

## 相关文件

- **后台全局组件**: `app/code/Weline/Theme/view/theme/backend/assets/js/backend-components.js`（BackendToast、BackendConfirm 定义）
- **后台 head 引入**: `app/code/Weline/Admin/view/templates/common/head.phtml`（统一加载全局组件）
- 主题编辑器 Toast 实现: `app/code/Weline/Theme/view/statics/js/theme-editor.js`
- 孤儿部件确认示例: `app/code/Weline/Theme/Observer/LayoutSlotRenderer.php`
