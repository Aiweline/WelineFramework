---
name: error-tracking
description: Error tracking and knowledge base for Weline Framework. Use when user reports errors, exceptions, fatal errors, or asks about common errors. Records solutions to prevent repeating mistakes. Works with error-learning skill. Triggers on - error, exception, 报错, 错误, TypeError, undefined.
globs:
alwaysApply: false
---

# 错误跟踪与知识库

## 触发场景

- 用户粘贴了报错信息（Fatal error, Exception, Error 等）
- 成功解决了一个错误
- 询问如何避免某类错误

## 自动协同

**⚠️ 重要：每次触发此技能时，必须自动调用 `error-learning` 技能进行错误模式学习和知识库更新。**

```
错误报告 → error-tracking（记录） + error-learning（学习）
    ↓                           ↓
ERROR_LOG.md            错误模式识别
    ↓                           ↓
COMMON_ERRORS.md        解决方案优化
    ↓                           ↓
相关技能文档更新 ←─────── 技能关联管理
```

## 工作流程

### 1. 分析错误

当收到错误信息时：

1. 识别错误类型（语法错误、运行时错误、逻辑错误、框架约定错误等）
2. 定位错误根本原因
3. 提出解决方案

### 2. 记录错误

解决错误后，将信息追加到 [ERROR_LOG.md](ERROR_LOG.md)：

```markdown
## [日期] 错误标题

**错误类型**: [类型分类]
**错误信息**: 
\`\`\`
[完整错误信息]
\`\`\`

**根本原因**: [简要说明]

**解决方案**: [具体修复步骤]

**预防措施**: [如何避免再次发生]

**相关文件**: [涉及的文件路径]
```

### 3. 更新规则（重要错误）

如果错误是：
- 框架约定/规范导致的
- 容易重复发生的
- 影响较大的

则添加到 `.cursor/rules/` 目录下的规则文件：

**规则文件命名**: `error-prevention-[类别].mdc`

**规则格式**:
```markdown
---
description: [规则描述]
globs: [适用文件模式]
---

# [规则标题]

## 错误场景
[描述什么情况下会出错]

## 正确做法
[代码示例]

## 错误做法
[反面示例]
```

## 错误分类

| 分类 | 说明 | 规则文件 |
|------|------|----------|
| 框架约定 | 违反框架使用规范 | `error-prevention-framework.mdc` |
| PHP语法 | PHP 语法和类型错误 | `error-prevention-php.mdc` |
| 事件系统 | 事件触发和监听错误 | `error-prevention-event.mdc` |
| 数据库 | 数据库操作错误 | `error-prevention-database.mdc` |
| 前端 | JavaScript/CSS 错误 | `error-prevention-frontend.mdc` |

## 快速参考

查看已记录的错误和解决方案：
- 完整错误日志：[ERROR_LOG.md](ERROR_LOG.md)
- 常见错误速查：[COMMON_ERRORS.md](COMMON_ERRORS.md)
- 错误模式库：查看 `error-learning` 技能

## 相关技能

- **error-learning** - 自动错误学习和模式识别（必须配合使用）
- **module-development** - 模块开发规范
- **weline-routing** - 路由规范
- **theme-development** - 主题开发
- **code-generation-standards** - 代码生成标准

## 强制规则

遵循 `.cursor/rules/auto-update-skills-on-error.mdc` 规则：

- ✅ 每次修复错误后必须更新知识库
- ✅ 必须调用 error-learning 技能
- ✅ 必须更新至少 3 个文档（ERROR_LOG.md, COMMON_ERRORS.md, 相关技能）
- ✅ 必须建立技能间交叉引用

## 示例：记录一个错误

**输入的错误**:
```
Fatal error: Argument #2 ($data) could not be passed by reference
```

**记录内容**:
```markdown
## [2026-01-28] EventsManager dispatch 引用传递错误

**错误类型**: 框架约定

**错误信息**: 
\`\`\`
Fatal error: Argument #2 ($data) could not be passed by reference
\`\`\`

**根本原因**: 
EventsManager::dispatch() 方法的第二个参数是引用传递 (&$data)，
不能直接传递数组字面量。

**解决方案**: 
先将数据存入变量，再传递给 dispatch：
\`\`\`php
// ❌ 错误
$eventsManager->dispatch('event_name', ['key' => 'value']);

// ✅ 正确
$eventData = ['key' => 'value'];
$eventsManager->dispatch('event_name', $eventData);
\`\`\`

**预防措施**: 调用 dispatch 时始终使用变量传递数据

**相关文件**: WeShop/Catalog/Controller/Frontend/Category/View.php
```
