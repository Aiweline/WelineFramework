# 验证快速参考 (Verification Quick Reference)

## 🎯 核心原则

> **每次修复后必须验证，验证失败最多重试 2 次，两次失败询问用户**

---

## 📋 验证流程图（1 分钟速查）

```
修复代码 → 选择验证方法 → 执行验证 → 判断结果
                                          ↓
                                   成功？ ─── YES → 更新知识库 → ✅ 完成
                                     │
                                    NO
                                     │
                                 尝试 < 2？
                                  ├─ YES → 深度分析 → 重新修复 → 重新验证
                                  └─ NO  → ❌ 报告失败 → 询问用户
```

---

## ⚡ 3 步验证法

### 步骤 1: 选择验证方法（10秒）

| 场景 | 验证方法 | 命令/模板 |
|------|---------|----------|
| 🗄️ 数据库操作 | 测试脚本 | `php verify_xxx.php` |
| 🌐 API/控制器 | HTTP 请求 | `php bin/w http:req` |
| 📦 模型/服务 | 单元测试 | `php bin/w test:unit` |
| 🔄 完整流程 | 集成测试 | `php integration_xxx.php` |
| 🎨 前端/UI | 手动 + 浏览器 | 手动步骤清单 |

### 步骤 2: 执行验证（30秒 - 2分钟）

**快速创建验证脚本（复制模板）：**
```bash
# 1. 复制模板
cat .cursor/skills/error-learning/VERIFICATION_TEMPLATE.md

# 2. 创建脚本
# 参考模板 1-6，选择合适的

# 3. 运行验证
php verify_xxx.php
```

### 步骤 3: 判断结果（即时）

```php
// 成功标志
exit(0);  // 或输出包含 "✅", "成功", "通过"

// 失败标志
exit(1);  // 或输出包含 "❌", "失败", "错误"
```

---

## 🔄 重试决策树

```
验证结果 = 失败？
    │
    ├─ 尝试次数 = 1
    │   └─→ 可以重试
    │       ├─ 分析失败原因
    │       ├─ 改进修复方案
    │       └─ 执行第 2 次验证
    │
    └─ 尝试次数 = 2
        └─→ 停止重试
            ├─ 报告两次失败详情
            ├─ 分析共同问题
            └─ 询问用户指导
```

---

## 📝 验证报告模板

### ✅ 成功报告（简短版）

```markdown
## ✅ 验证通过

**验证方法**: [测试脚本/HTTP请求/单元测试]
**验证命令**: `php verify_xxx.php`
**结果**: ✅ 通过（尝试 1/2）
**关键输出**: 删除前1条，删除后0条，实际删除1条
```

### ❌ 失败报告（详细版）

```markdown
## ❌ 验证失败（2/2 次尝试）

### 尝试 1
- **修复**: [描述]
- **验证**: `php verify_xxx.php`
- **结果**: ❌ 失败
- **原因**: [分析]

### 尝试 2
- **改进**: [描述]
- **验证**: `php verify_xxx.php`
- **结果**: ❌ 失败
- **原因**: [分析]

### 需要用户帮助
[具体问题]
```

---

## 🛠️ 常用验证命令速查

### 数据库验证
```bash
# 创建验证脚本（使用模板1）
php verify_db_operation.php

# 脚本应包含：
# 1. 操作前记录数
# 2. 执行操作
# 3. 操作后记录数
# 4. 对比验证
```

### HTTP 验证
```bash
# GET 请求
php bin/w http:req "/path/to/page"

# POST 请求（需要登录）
php bin/w http:req "/backend/api/action" -X POST -b --login -u=admin -p=admin

# 搜索关键词
php bin/w http:req "/page" "filter=success" -n=5
```

### 单元测试
```bash
# 运行测试类
php bin/w test:unit Module/Test/Unit/TestClass

# 运行单个方法
php bin/w test:unit Module/Test/Unit/TestClass::testMethod
```

---

## ⚠️ 禁止行为

### ❌ 验证失败时禁止的操作：

1. ❌ 不要更新知识库（ERROR_LOG.md, COMMON_ERRORS.md等）
2. ❌ 不要标记为"已完成"
3. ❌ 不要继续下一个任务
4. ❌ 不要超过 2 次重试

### ✅ 验证失败时应该做：

1. ✅ 记录详细的失败信息
2. ✅ 分析失败原因
3. ✅ 第 1 次失败：改进修复 → 重试
4. ✅ 第 2 次失败：报告 → 询问用户

---

## 💡 验证技巧

### 技巧 1: 快速验证脚本
```php
<?php
// 最小验证脚本（复制粘贴即用）
require __DIR__ . '/app/bootstrap.php';

// 执行操作
$result = performAction();

// 验证
if ($result === expected) {
    echo "✅ 验证通过\n";
    exit(0);
} else {
    echo "❌ 验证失败：期望 " . expected . "，实际 " . $result . "\n";
    exit(1);
}
```

### 技巧 2: 验证 + 清理
```php
try {
    // 测试操作
    $result = test();
    
    // 验证
    assert($result === expected);
    
    echo "✅ 验证通过\n";
    exit(0);
} catch (Exception $e) {
    echo "❌ 验证失败：" . $e->getMessage() . "\n";
    exit(1);
} finally {
    // 清理测试数据
    cleanup();
}
```

### 技巧 3: 使用退出码
```bash
# 验证脚本
php verify.php
echo $?  # 0=成功, 1=失败

# 在 shell 脚本中
php verify.php && echo "成功" || echo "失败"
```

---

## 📊 验证统计（示例）

```json
{
  "totalVerifications": 10,
  "successful": 8,
  "failed": 2,
  "averageRetries": 0.2,
  "methods": {
    "test-script": 6,
    "http-request": 2,
    "unit-test": 2
  }
}
```

---

## 🔗 相关文档

- **详细模板**: [VERIFICATION_TEMPLATE.md](VERIFICATION_TEMPLATE.md)
- **主技能文档**: [SKILL.md](SKILL.md)
- **错误追踪**: [../error-tracking/SKILL.md](../error-tracking/SKILL.md)
- **强制规则**: [../../rules/auto-update-skills-on-error.mdc](../../rules/auto-update-skills-on-error.mdc)

---

**记住**: 验证不是可选项，是强制要求！每次修复 → 必须验证 → 验证通过 → 才能更新知识库

---

**最后更新**: 2026-01-29
