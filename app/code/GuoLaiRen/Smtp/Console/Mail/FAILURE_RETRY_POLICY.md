# 失败重试策略说明

## 📋 重试规则

### 失败次数限制

系统对每个邮箱地址最多允许**失败 3 次**：

```
发送尝试 1  ──>  失败 (attempts = 1) ──>  记录到 failed_emails.json
                                         ↓
发送尝试 2  ──>  失败 (attempts = 2) ──>  仍在 failed_emails.json
                                         ↓
发送尝试 3  ──>  失败 (attempts = 3) ──>  移到 permanent_failed_emails.json
                                         ↓
                                    不再自动重试
```

### 重试时机

系统会在以下情况自动重试失败的邮件：

1. **断点续发时** - 启动发送时检测到失败记录
2. **账号切换时** - 切换 SMTP 账号后立即尝试用新账号重试

---

## 📊 失败分类

### 1. 可重试失败（临时失败）

这些错误可能通过重试或更换账号解决：

- ✅ SMTP connection timeout（连接超时）
- ✅ Rate limit exceeded（限流）
- ✅ Temporary server error（服务器临时错误）
- ✅ Network error（网络错误）
- ✅ Authentication failed（认证失败 - 更换账号可能成功）

**处理方式**：
- 记录到 `failed_emails.json`
- 自动重试
- 最多失败 3 次

### 2. 永久失败

失败 3 次后，邮件被认为是永久失败：

- ❌ 收件人邮箱不存在
- ❌ 收件人服务器拒绝接收
- ❌ 邮件内容被识别为垃圾邮件
- ❌ IP 地址被列入黑名单

**处理方式**：
- 移到 `permanent_failed_emails.json`
- 不再自动重试
- 需要人工审核

---

## 💡 实际案例

### 案例 1: 临时网络问题（成功重试）

```bash
发送批次 1:
📤 From: marketing@company.com
  [1/100] 📧 To: john@example.com
✗ Batch failed: SMTP connection timeout

⚠️  Switching to next account due to error...
🔄 Switched to: sales@company.com

📧 Found 1 failed email(s), retrying with new account...
💡 Maximum 3 failures allowed per email
  📧 Retrying: john@example.com (attempt 1/3)
    ✓ Success with new account on attempt 1!
```

**结果**: 
- 第1次失败：网络问题
- 第1次重试：成功
- 移到已发送列表 ✅

---

### 案例 2: 持续失败（移到永久失败）

```bash
发送批次 1:
📤 From: marketing@company.com
  [1/100] 📧 To: invalid@nonexistent.com
✗ Batch failed: Recipient address rejected

⚠️  Switching to next account due to error...
🔄 Switched to: sales@company.com

📧 Found 1 failed email(s), retrying with new account...
  📧 Retrying: invalid@nonexistent.com (attempt 1/3)
    ✗ Still failed, will retry again (next attempt: 2/3)

--- 下次发送时 ---

⚡ Found 1 failed emails from previous attempt
📬 Will retry these emails first before continuing...
  - invalid@nonexistent.com (attempt 2/3)
    ✗ Still failed, will retry again (next attempt: 3/3)

--- 再次发送时 ---

  - invalid@nonexistent.com (attempt 3/3)
    ✗ Failed (will be moved to permanent failed list)

✗ Moved to permanent failed list: invalid@nonexistent.com
```

**结果**:
- 第1次失败：收件人地址被拒绝
- 第2次失败：仍然被拒绝
- 第3次失败：达到上限
- 移到永久失败列表 ❌

---

### 案例 3: 混合结果（部分成功）

```bash
批次包含 10 个收件人:
  - 8 个成功
  - 2 个失败

⚠️  Switching to next account due to error...
🔄 Switched to: sales@company.com

📧 Found 2 failed email(s), retrying with new account...
  📧 Retrying: user1@example.com (attempt 1/3)
    ✓ Success with new account on attempt 1!  ← 之前是网络问题，现在成功了
  
  📧 Retrying: user2@badserver.com (attempt 1/3)
    ✗ Still failed, will retry again (next attempt: 2/3)  ← 确实是收件人问题
```

**结果**:
- user1@example.com: 重试成功，移到已发送列表 ✅
- user2@badserver.com: 继续重试，还有 2 次机会 ⏳

---

## 🔍 查看失败状态

### 运行诊断工具

```bash
php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php
```

### 输出示例

```
⚠️  待重试的失败邮件 (2 个):
  - user1@example.com (尝试次数: 1)
  - user2@example.com (尝试次数: 2)

❌ 永久失败邮件 (3 个 - 已尝试3次):
  - invalid@nonexistent.com
    失败次数: 3 次
    最后失败: 2025-10-24 18:30:15
    原因: Exceeded maximum retry attempts (3)
  
  - baduser@badserver.com
    失败次数: 3 次
    最后失败: 2025-10-24 18:32:20
    最后错误: Recipient address rejected
  
  - spam@blocked.com
    失败次数: 3 次
    最后失败: 2025-10-24 18:35:10
    最后错误: Message rejected as spam
```

---

## 📁 文件说明

### failed_emails.json

存储正在重试的失败邮件（attempts < 3）：

```json
{
    "user@example.com": {
        "email": "user@example.com",
        "name": "John Doe",
        "attempts": 2,
        "reasons": [
            {
                "reason": "SMTP connection timeout",
                "timestamp": "2025-10-24 18:00:00"
            },
            {
                "reason": "Failed with account: Marketing Team",
                "timestamp": "2025-10-24 18:05:00"
            }
        ],
        "first_failed_at": "2025-10-24 18:00:00",
        "last_failed_at": "2025-10-24 18:05:00"
    }
}
```

### permanent_failed_emails.json

存储永久失败的邮件（attempts >= 3）：

```json
{
    "invalid@example.com": {
        "email": "invalid@example.com",
        "name": "Invalid User",
        "attempts": 3,
        "reasons": [
            {
                "reason": "Recipient address rejected",
                "timestamp": "2025-10-24 18:00:00"
            },
            {
                "reason": "Failed with account: Sales Team",
                "timestamp": "2025-10-24 18:10:00"
            },
            {
                "reason": "Failed with account: Support Team",
                "timestamp": "2025-10-24 18:20:00"
            }
        ],
        "first_failed_at": "2025-10-24 18:00:00",
        "last_failed_at": "2025-10-24 18:20:00",
        "permanent_reason": "Exceeded maximum retry attempts (3)",
        "moved_to_permanent_at": "2025-10-24 18:20:00"
    }
}
```

---

## 🛠️ 管理失败邮件

### 清除失败记录

使用清理工具：

```bash
php app/code/GuoLaiRen/Smtp/Console/Mail/clear_history.php
```

选项：
1. 清除断点文件（保留失败记录）
2. 清除已发送邮件记录
3. **清除失败邮件记录** ← 清除后可以重新尝试
4. **清除永久失败邮件记录** ← 清除后可以重新尝试
5. 清除所有记录

### 重新尝试永久失败的邮件

如果你修复了问题（如更新邮箱地址），可以：

1. 清除永久失败记录：
   ```bash
   php app/code/GuoLaiRen/Smtp/Console/Mail/clear_history.php
   # 选择选项 4
   ```

2. 更新收件人列表中的邮箱地址

3. 重新发送：
   ```bash
   php bin/w mail:send --to="emails/recipients.xlsx" --subject="..."
   ```

---

## ⚙️ 调整重试策略

如果需要修改最大失败次数（默认 3 次），编辑：

```php
// app/code/GuoLaiRen/Smtp/Helper/BreakpointManager.php
const MAX_RETRY_ATTEMPTS = 3;  // 修改这个值
```

**建议值**：
- `2` - 激进策略，快速放弃无效邮箱
- `3` - 推荐值（默认），平衡重试和效率
- `5` - 保守策略，给予更多重试机会

---

## 💡 最佳实践

### 1. 定期检查永久失败列表

```bash
# 每次大量发送后检查
php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php
```

### 2. 分析失败原因

查看 `permanent_failed_emails.json` 中的 `reasons` 字段：
- 如果是"Recipient address rejected"，更新邮箱地址
- 如果是"Rate limit exceeded"，调整发送频率
- 如果是"Message rejected as spam"，修改邮件内容

### 3. 清理无效邮箱

定期清理收件人列表，移除永久失败的邮箱：

```bash
# 1. 导出永久失败列表
# 2. 从收件人列表中删除这些邮箱
# 3. 清除永久失败记录
php app/code/GuoLaiRen/Smtp/Console/Mail/clear_history.php
```

### 4. 监控失败率

如果失败率过高（>10%），检查：
- SMTP 账号配置是否正确
- 邮件内容是否触发垃圾邮件过滤器
- 发送频率是否过快
- IP 是否被列入黑名单

---

## 📞 相关文档

- 断点续发指南: `BREAKPOINT_GUIDE.md`
- 自动重试指南: `AUTO_RETRY_GUIDE.md`
- 显示格式说明: `DISPLAY_FORMAT_EXAMPLE.md`
- 在线帮助: `php bin/w mail:send --help`

