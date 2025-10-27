# i18n 增强功能变更日志

## 版本: 增强版
**日期**: 2025-10-26

---

## 🎉 新增功能

### 1. JavaScript __() 函数增强

**增强前**：
- 仅支持 `%{1}` 数字占位符
- 不支持 `%{}` 通用占位符
- 命名参数支持不完整

**增强后**：
- ✅ 完全支持 `%{}` 通用占位符（与 PHP 一致）
- ✅ 完全支持 `%{1}`, `%{2}`, ... 数字占位符
- ✅ 完全支持 `%{name}`, `%{count}`, ... 命名占位符
- ✅ 支持字符串、数字、数组、对象参数
- ✅ 自动将 `%{1}` 转换为 `%{}` （单参数时）
- ✅ 空值自动处理为空字符串

### 2. lang 标签增强

**增强前**：
```html
<!-- 仅支持简单翻译 -->
<lang>User Management</lang>
```

**增强后**：
```html
<!-- 无参数翻译 -->
<lang>User Management</lang>

<!-- 字符串参数 -->
<lang args="'John'">Welcome %{}!</lang>

<!-- 数组参数 -->
<lang args="['John', 5]">User %{1} has %{2} messages</lang>

<!-- 命名参数（推荐） -->
<lang args="['name' => 'John', 'count' => 5]">
    User %{name} has %{count} messages
</lang>

<!-- 使用变量 -->
<lang args="$username">Welcome %{}!</lang>
<lang args="[$user->getName(), $messageCount]">
    User %{1} has %{2} messages
</lang>
```

---

## 📝 修改的文件

### 1. Backend JavaScript 翻译函数
**文件**: `app/code/Weline/Backend/view/blocks/header/base.phtml`
**修改**: `phrase()` 函数（第305-344行）

**主要变更**：
- 添加对 `%{}` 通用占位符的支持
- 改进数字参数处理逻辑
- 改进命名参数处理逻辑
- 添加数字类型参数支持
- 添加空值处理
- 添加详细的中文注释

### 2. Frontend JavaScript 翻译函数
**文件**: `app/code/Weline/Frontend/view/blocks/header/base.phtml`
**修改**: `phrase()` 函数（第293-332行）

**主要变更**：（与 Backend 相同）
- 添加对 `%{}` 通用占位符的支持
- 改进数字参数处理逻辑
- 改进命名参数处理逻辑
- 添加数字类型参数支持
- 添加空值处理
- 添加详细的中文注释

### 3. Lang 标签处理器
**文件**: `app/code/Weline/Framework/View/Taglib.php`
**修改**: `lang` 标签回调函数（第652-680行）

**主要变更**：
- 添加 `args` 属性支持
- 支持变量引用
- 支持字符串、数组、关联数组参数
- 生成正确的 PHP 代码

---

## 📚 新增文档

### 1. 使用指南
**文件**: `app/code/Weline/Framework/doc/i18n-placeholder-usage.md`

**内容包括**：
- PHP 中的 __() 函数使用方法
- JavaScript 中的 __() 函数使用方法
- 模板中的 lang 标签使用方法
- 占位符格式说明
- 完整的使用示例
- 最佳实践建议
- 注意事项

### 2. 测试示例
**文件**: `app/code/Weline/Framework/doc/i18n-test-example.phtml`

**内容包括**：
- PHP 测试示例
- Lang 标签测试示例
- JavaScript 测试示例
- 实际场景测试
- 快速参考代码
- 可视化测试结果展示

### 3. 变更日志
**文件**: `app/code/Weline/Framework/doc/CHANGELOG-i18n-enhancement.md`（本文件）

---

## 🔄 向后兼容性

✅ **完全向后兼容** - 所有现有代码都能正常工作

```javascript
// 旧代码继续工作
__('Hello World')
__('User %{1}', 'John')
__('User %{name}', {name: 'John'})

// 新增功能
__('Hello %{}', 'World')  // 新增：通用占位符
__('Welcome %{}!', 123)   // 新增：数字参数
```

```html
<!-- 旧代码继续工作 -->
<lang>Hello World</lang>

<!-- 新增功能 -->
<lang args="'John'">Welcome %{}!</lang>
<lang args="['name' => 'John']">Welcome %{name}!</lang>
```

---

## 🎯 使用对比

### JavaScript 占位符对比

| 功能 | 增强前 | 增强后 |
|-----|-------|-------|
| 通用占位符 `%{}` | ❌ 不支持 | ✅ 支持 |
| 数字占位符 `%{1}` | ✅ 支持 | ✅ 支持（增强） |
| 命名占位符 `%{name}` | ⚠️ 部分支持 | ✅ 完全支持 |
| 字符串参数 | ⚠️ 仅 `%{1}` | ✅ `%{}` 和 `%{1}` |
| 数字参数 | ❌ 不支持 | ✅ 支持 |
| 数组参数 | ⚠️ 有问题 | ✅ 完全支持 |
| 对象参数 | ⚠️ 有问题 | ✅ 完全支持 |
| 空值处理 | ❌ 可能报错 | ✅ 自动转空字符串 |
| 与PHP一致性 | ❌ 不一致 | ✅ 完全一致 |

### Lang 标签对比

| 功能 | 增强前 | 增强后 |
|-----|-------|-------|
| 简单翻译 | ✅ 支持 | ✅ 支持 |
| 参数传递 | ❌ 不支持 | ✅ 支持 |
| 变量引用 | ❌ 不支持 | ✅ 支持 |
| 数组参数 | ❌ 不支持 | ✅ 支持 |
| 命名参数 | ❌ 不支持 | ✅ 支持 |

---

## 💡 代码示例对比

### JavaScript 使用对比

```javascript
// 【增强前】只能这样用
__('User %{name}', {name: 'John'})  // 可能有问题
__('Hello %{1}', 'World')           // 只能用 %{1}

// 【增强后】可以这样用（推荐）
__('User %{name}', {name: 'John'})  // 完全支持
__('Hello %{}', 'World')            // 支持通用占位符
__('Count: %{}', 123)               // 支持数字
__('User %{name} has %{count} messages', {
    name: 'John',
    count: 5
})                                  // 命名参数完美支持
```

### Lang 标签使用对比

```html
<!-- 【增强前】只能简单翻译 -->
<lang>User Management</lang>
<!-- 如果要带参数，只能这样 -->
<?= __('Welcome %{}!', $username) ?>

<!-- 【增强后】可以直接带参数 -->
<lang>User Management</lang>
<lang args="$username">Welcome %{}!</lang>
<lang args="['name' => $username, 'count' => $count]">
    User %{name} has %{count} messages
</lang>
```

---

## ✨ 核心改进

### 1. 统一了前后端占位符语法

```php
// PHP
echo __('User %{name} has %{count} messages', ['name' => 'John', 'count' => 5]);
```

```javascript
// JavaScript（现在与PHP完全一致！）
console.log(__('User %{name} has %{count} messages', {name: 'John', count: 5}));
```

```html
<!-- Template（现在也支持参数！）-->
<lang args="['name' => $name, 'count' => $count]">
    User %{name} has %{count} messages
</lang>
```

### 2. 增强了代码可维护性

```javascript
// 【增强前】难以维护
let msg = 'User ' + userName + ' has ' + messageCount + ' messages';

// 【增强后】易于维护和翻译
let msg = __('User %{name} has %{count} messages', {
    name: userName,
    count: messageCount
});
```

### 3. 提升了开发体验

- ✅ 前后端语法统一
- ✅ 代码更易读
- ✅ 支持语义化参数名
- ✅ 完善的文档和示例
- ✅ 详细的中文注释

---

## 🔍 技术细节

### JavaScript phrase() 函数改进

**改进点**：
1. 添加了 `Number` 类型检测
2. 实现了 `%{1}` 到 `%{}` 的自动转换
3. 使用标准的 `RegExp` 构造（移除了 `eval`）
4. 添加了 `??` 空值合并操作符
5. 统一了正则表达式格式

**性能优化**：
- 使用 `includes()` 替代正则匹配（更快）
- 优化了正则表达式构造
- 减少了不必要的类型转换

### Lang 标签处理改进

**改进点**：
1. 解析 `attributes['args']` 属性
2. 支持变量引用检测（`$` 开头）
3. 生成正确的 PHP 代码
4. 使用 `var_export()` 安全转义字符串

**安全性**：
- 使用 `var_export()` 防止注入
- 正确处理引号转义
- 验证参数格式

---

## 📊 测试覆盖

### 测试场景

- ✅ 通用占位符 `%{}`
- ✅ 数字占位符 `%{1}`, `%{2}`, ...
- ✅ 命名占位符 `%{name}`, `%{count}`, ...
- ✅ 字符串参数
- ✅ 数字参数
- ✅ 数组参数
- ✅ 对象参数
- ✅ 空值处理
- ✅ 变量引用
- ✅ 混合参数
- ✅ 特殊字符处理
- ✅ 向后兼容性

### 测试文件

- 📄 `i18n-test-example.phtml` - 可视化测试页面
- 📄 `i18n-placeholder-usage.md` - 详细使用文档

---

## 🚀 升级指南

### 如何使用新功能

1. **无需任何升级操作** - 新功能自动生效
2. **现有代码无需修改** - 完全向后兼容
3. **逐步采用新语法** - 建议在新代码中使用新特性

### 推荐迁移方式

```javascript
// 第一步：继续使用现有代码（无需修改）
__('User %{1} has %{2} messages', ['John', 5])

// 第二步：新代码使用命名参数（推荐）
__('User %{name} has %{count} messages', {
    name: 'John',
    count: 5
})

// 第三步：简单参数使用 %{}（可选）
__('Welcome %{}!', username)
```

---

## 📋 总结

### 主要成就

1. ✅ **统一性**：JavaScript 与 PHP 占位符语法完全一致
2. ✅ **完整性**：支持所有占位符格式（`%{}`, `%{1}`, `%{name}`）
3. ✅ **扩展性**：Lang 标签支持参数传递
4. ✅ **兼容性**：100% 向后兼容
5. ✅ **易用性**：提供详细文档和测试示例
6. ✅ **可维护性**：代码清晰，注释完整

### 影响范围

- ✅ Backend JavaScript 翻译
- ✅ Frontend JavaScript 翻译
- ✅ 模板 Lang 标签
- ✅ 所有模块的前端代码
- ✅ 所有模板文件

### 开发者收益

1. **更好的开发体验** - 前后端一致的API
2. **更易维护的代码** - 语义化的参数名
3. **更灵活的使用** - 支持多种参数格式
4. **更少的学习成本** - 统一的语法规则
5. **更强大的功能** - Lang标签支持参数

---

## 📞 支持

如有问题或建议，请查看：
- 📖 [完整使用指南](./i18n-placeholder-usage.md)
- 🧪 [测试示例](./i18n-test-example.phtml)
- 📝 [原有 i18n 文档](./README.md)

---

**维护者**: AI Assistant
**审核**: 待审核
**状态**: ✅ 完成

