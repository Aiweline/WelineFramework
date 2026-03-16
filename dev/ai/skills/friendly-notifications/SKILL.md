---
name: friendly-notifications
description: 用户提示与确认。禁止 alert/confirm/prompt！用 BackendToast/BackendConfirm。错误信息必须详尽、可操作。
globs:
  - "**/*.js"
  - "**/*.phtml"
  - "**/*.php"
alwaysApply: false
---

# friendly-notifications（极简版）

## 何时使用

- 显示消息、提示、警告、错误
- 用户确认（删除、提交等）
- 用户输入、成功/失败反馈
- API 返回消息、批量操作结果

## 必做

- 用 BackendToast.success/error/warning/info
- 确认用 BackendConfirm.show()
- 输入用 BackendConfirm.showInput()
- 错误信息详尽、可操作（含失败原因、修复建议）

## 最小示例

```javascript
BackendToast.success('保存成功');
BackendToast.error('保存失败：' + error.message);
BackendConfirm.show('确认删除？', () => { /* 删除逻辑 */ });
```

## 禁止

- alert()、confirm()、prompt()
- 错误信息过于简短、无法指导用户操作
