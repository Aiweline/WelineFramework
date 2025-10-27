# 速率限制错误处理机制

## 概述

邮件发送系统现已支持智能处理 SMTP 服务器的速率限制错误（"Please try again later"）。

## 功能说明

### 1. 错误识别

系统会自动识别以下错误信息为速率限制错误：
- `Please try again later`
- `try again later`
- `rate limit`

### 2. 处理策略（智能账户切换 + 等待重试）

当遇到速率限制错误时，系统采用**两阶段处理策略**：

#### 阶段1：尝试切换账户（前3次）

首先尝试切换到其他 SMTP 账户来解决问题：
- ✅ **会**切换到下一个 SMTP 账户
- ✅ **会**用新账户重试发送
- 🔄 **最多切换3次**账户尝试

#### 阶段2：等待重试（第3次后仍失败）

如果连续3个账户都遇到速率限制错误，说明所有账户都被限制了：
- ❌ **不再**切换账户
- ⏰ **开始**等待10分钟
- ✅ **会**用当前账户持续重试
- 🔁 **持续**等待和重试直到恢复

**重试机制：**
1. 检测到速率限制错误
2. 显示当前账户信息
3. 等待 10 分钟
4. 用同一账户重试发送
5. 如果仍然遇到速率限制，继续等待 10 分钟
6. 持续重试直到成功（最多 100 次尝试）

### 3. 与其他错误的区别

| 错误类型 | 初始处理方式 | 是否切换账户 | 等待时间 | 触发条件 |
|---------|------------|------------|---------|---------|
| 速率限制错误（前3次） | 切换账户 | ✅ 是 | 无 | 单个账户限制 |
| 速率限制错误（3次后） | 等待重试 | ❌ 否 | 10 分钟 | 所有账户限制 |
| 其他 SMTP 错误 | 切换账户 | ✅ 是 | 无 | - |
| 连接错误 | 切换账户 | ✅ 是 | 无 | - |
| 认证错误 | 切换账户 | ✅ 是 | 无 | - |

## 使用场景

### 场景1：单个账户被限制，切换账户成功

```
发送进度: 200/500
↓
Account #1 遇到 "Please try again later" 错误
✗ Batch failed: Please try again later
🔄 Rate limit error (1/3), trying next account...
⚠️  Switching to next account due to error...
🔄 Switched to: Stockcircle <team@stockcircle.to>
↓
用 Account #2 重试
  📧 Retrying: John Doe <john@example.com>
    ✓ Success with new account on attempt 2!
↓
继续发送剩余邮件（计数器重置为0）
```

### 场景2：多个账户被限制，最终触发等待机制

```
发送进度: 200/500
↓
Account #1 遇到速率限制错误
✗ Batch failed: Please try again later
🔄 Rate limit error (1/3), trying next account...
切换到 Account #2
↓
Account #2 仍然遇到速率限制错误
✗ Batch failed: Please try again later
🔄 Rate limit error (2/3), trying next account...
切换到 Account #3
↓
Account #3 仍然遇到速率限制错误
✗ Batch failed: Please try again later
🔄 Rate limit error (3/3), trying next account...
切换到 Account #4
↓
Account #4 仍然遇到速率限制错误（第4次）
✗ Batch failed: Please try again later
⚠️  Rate limit error on 4 consecutive account(s)
💡 All accounts appear to be rate limited, will wait and retry
⏰ Rate limit detected on: Stockcircle <support@stockcircle.to>
⏳ Waiting 10 minutes before retry...
💡 Will retry with same account until successful
↓
等待 10 分钟...
↓
🔄 Retrying after wait period...
  📧 Retrying: John Doe <john@example.com>
    ✓ Success after wait!
↓
继续发送剩余邮件（计数器重置为0）
```

### 场景3：持续限制需要多次等待

```
等待10分钟后重试失败
↓
⏰ Still rate limited, waiting another 10 minutes... (attempt 1)
↓
等待 10 分钟...
↓
再次重试...
    ✓ Success after wait!
```

## 优势

1. **智能切换策略**：先尝试切换账户解决问题，可能只是某个账户被限制
2. **高效率**：大部分情况下通过切换账户即可解决，无需等待
3. **保护所有账户**：当所有账户都被限制时才等待，避免损害账户信用
4. **自动恢复**：无需人工干预，系统自动处理并恢复
5. **持久性**：会持续尝试直到成功，不会轻易放弃
6. **精准识别**：只对速率限制错误采用特殊处理，其他错误正常切换账户
7. **成功后重置**：一旦成功发送，计数器重置，下次又会先尝试切换账户

## 配置说明

### 切换账户次数阈值

在触发等待机制前，最多尝试切换的账户数：**3 次**

如需修改，请编辑 `Send.php` 中的：
```php
private const MAX_ACCOUNT_SWITCHES_BEFORE_WAIT = 3;  // 修改这里
```

### 等待时间

默认等待时间：**10 分钟**

如需修改，请编辑 `Send.php` 中的：
```php
private function handleRateLimitError(...) {
    $waitMinutes = 10;  // 修改这里
    ...
}
```

### 最大重试次数

默认最大重试次数：**100 次**

如需修改，请编辑 `Send.php` 中的：
```php
$maxRetries = 100;  // 修改这里
```

## 注意事项

1. **等待期间程序会阻塞**：在等待 10 分钟期间，程序会暂停，不会发送其他邮件
2. **适用于单账户场景**：如果只配置了一个 SMTP 账户，所有错误都会触发等待重试
3. **长时间运行**：在高发送量场景下，可能需要长时间运行才能完成所有邮件发送
4. **断点续发支持**：即使程序中断，重启后会继续从断点处开始

## 日志示例

### 示例1：切换账户成功（最常见）

```
✗ Batch failed: Please try again later
🔄 Rate limit error (1/3), trying next account...
⚠️  Switching to next account due to error...
🔄 Switched to: Stockcircle <team@stockcircle.to>

📧 Found 1 failed email(s), retrying with new account...
  📧 Retrying: John Doe <john@example.com> (attempt 2/3)
    ✓ Success with new account on attempt 2!
```

### 示例2：多次切换后触发等待机制

```
✗ Batch failed: Please try again later
🔄 Rate limit error (1/3), trying next account...
[切换账户...]

✗ Batch failed: Please try again later
🔄 Rate limit error (2/3), trying next account...
[切换账户...]

✗ Batch failed: Please try again later
🔄 Rate limit error (3/3), trying next account...
[切换账户...]

✗ Batch failed: Please try again later
⚠️  Rate limit error on 4 consecutive account(s)
💡 All accounts appear to be rate limited, will wait and retry
⏰ Rate limit detected on: Stockcircle <support@stockcircle.to>
⏳ Waiting 10 minutes before retry...
💡 Will retry with same account until successful

[等待 10 分钟...]

🔄 Retrying after wait period...
  📧 Retrying: John Doe <john@example.com>
    ✓ Success after wait!
  📧 Retrying: Jane Smith <jane@example.com>
    ✓ Success after wait!
```

## 版本历史

- **v1.0** (2025-10-24): 初始版本，支持基本的速率限制错误处理
  - 10分钟等待间隔
  - 自动重试机制
  - 保持同一账户发送

