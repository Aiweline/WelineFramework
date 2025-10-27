# 智能账号切换与自动重试功能

## 🎯 功能概述

当批次发送失败时，系统会自动：
1. 切换到下一个 SMTP 账号
2. 显示切换到了哪个账号
3. 检查失败邮件记录
4. 用新账号自动重试所有失败的邮件
5. 成功的邮件从失败记录中移除
6. 继续发送剩余邮件

这个功能确保了即使某个账号出现问题，邮件也能通过其他账号成功发送。

---

## 🔄 工作流程示例

### 场景：使用3个账号发送100封邮件

```bash
php bin/w mail:send --to="emails/recipients.xlsx" --subject="Newsletter" --bulk=10
```

#### 输出示例：

```
========== Starting Email Sending ==========

--- Batch 1/10 ---
Processing: 10 email(s)
📤 From: Marketing Team <marketing@company.com>
  [1/100] 📧 To: user1@example.com
  [2/100] 📧 To: user2@example.com
  ...
✓ Batch sent successfully

--- Batch 2/10 ---
Processing: 10 email(s)
📤 From: Sales Team <sales@company.com>
  [11/100] 📧 To: user11@example.com
  [12/100] 📧 To: user12@example.com
  ...
✓ Batch sent successfully

--- Batch 3/10 ---
Processing: 10 email(s)
📤 From: Support Team <support@company.com>
  [21/100] 📧 To: user21@example.com
  [22/100] 📧 To: user22@example.com
  ...
✗ Batch failed: SMTP connection timeout

⚠️  Switching to next account due to error...
🔄 Switched to: Marketing Team <marketing@company.com>

📧 Found 3 failed email(s), retrying with new account...
  📧 Retrying: user23@example.com
    ✓ Success with new account!
  📧 Retrying: user24@example.com
    ✓ Success with new account!
  📧 Retrying: user25@example.com
    ✗ Still failed with new account

--- Batch 4/10 ---
Processing: 10 email(s)
📤 From: Marketing Team <marketing@company.com>
  [31/100] 📧 To: user31@example.com
  ...
```

---

## 📊 详细流程图

```
批次发送失败
    ↓
检查是否有多个账号？
    ↓ 是
切换到下一个账号
    ↓
显示: 🔄 Switched to: [新账号]
    ↓
检查失败邮件记录
    ↓
有失败记录？
    ↓ 是
显示: 📧 Found X failed email(s), retrying...
    ↓
逐个重试失败的邮件
    ↓
├─ 成功 ──> 从失败记录移除 ──> 添加到已发送记录
│           显示: ✓ Success with new account!
│
└─ 失败 ──> 更新失败记录（增加尝试次数）
            显示: ✗ Still failed with new account
    ↓
继续处理下一批次
```

---

## 🎯 智能特性

### 1. **自动账号轮换**

系统会在以下情况自动切换账号：
- ✅ 每个批次发送成功后（负载均衡）
- ⚠️ 批次发送失败后（故障转移）
- ⏰ 账号达到发送限制后

### 2. **失败邮件追踪**

所有失败的邮件都会被记录在 `failed_emails.json` 中：

```json
{
    "user@example.com": {
        "email": "user@example.com",
        "name": "John Doe",
        "attempts": 1,
        "reasons": [
            {
                "reason": "SMTP connection timeout",
                "timestamp": "2025-10-24 18:30:15"
            }
        ],
        "first_failed_at": "2025-10-24 18:30:15",
        "last_failed_at": "2025-10-24 18:30:15"
    }
}
```

### 3. **智能重试机制**

- **立即重试**：账号切换后立即重试失败邮件
- **最大尝试次数**：每个邮件最多重试2次
- **永久失败**：超过2次的邮件移到 `permanent_failed_emails.json`
- **成功移除**：重试成功后自动从失败记录中移除

---

## 💡 实际应用场景

### 场景 1: SMTP 服务器临时故障

```
Account 1 (marketing@) → 发送10封 → 超时失败
    ↓ 切换账号
Account 2 (sales@) → 重试刚才失败的10封 → 成功！
    ↓ 继续
Account 2 (sales@) → 发送下一批10封 → 成功
```

**结果**: 无需手动干预，所有邮件都成功发送

---

### 场景 2: 账号被暂时限流

```
Account 1 (marketing@) → 发送50封 → 成功
Account 1 (marketing@) → 发送第51封 → 限流失败
    ↓ 切换账号
Account 2 (sales@) → 重试失败的邮件 → 成功！
Account 2 (sales@) → 继续发送剩余邮件 → 成功
```

**结果**: 自动负载分散到其他账号

---

### 场景 3: 部分收件人邮箱问题

```
Account 1 (marketing@) → 发送10封 → 3封失败（收件人不存在）
    ↓ 切换账号
Account 2 (sales@) → 重试3封失败邮件
    ├─ 2封仍然失败（确实是收件人问题）
    └─ 1封成功（之前是网络问题）
    ↓
继续2次重试后
    ├─ 1封成功 → 移到已发送
    └─ 2封失败 → 移到永久失败列表
```

**结果**: 真正的收件人问题被识别，临时问题被解决

---

## 📈 统计与监控

### 查看当前状态

```bash
php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php
```

输出：
```
📊 总体统计:
  - 有断点: 是
  - 已发送邮件数: 87
  - 待重试邮件数: 3
  - 永久失败数: 2
```

### 查看失败原因

失败邮件记录中包含详细的失败原因：
- SMTP connection timeout
- Authentication failed
- Recipient address rejected
- Message size exceeds maximum
- Rate limit exceeded

这些信息帮助你：
- 识别哪个账号有问题
- 了解失败的真正原因
- 决定是否需要调整配置

---

## ⚙️ 配置建议

### 1. 设置多个 SMTP 账号

至少配置 2-3 个账号以确保容错能力：

```json
{
    "accounts": [
        {
            "name": "Primary Account",
            "from_email": "primary@company.com",
            "enabled": true
        },
        {
            "name": "Backup Account 1",
            "from_email": "backup1@company.com",
            "enabled": true
        },
        {
            "name": "Backup Account 2",
            "from_email": "backup2@company.com",
            "enabled": true
        }
    ]
}
```

### 2. 合理设置发送限制

```bash
# 保守设置（推荐）
php bin/w mail:send --to="recipients.xlsx" --subject="..." \
    --bulk=10 \
    --max-per-hour=100 \
    --delay=180000  # 3分钟延迟

# 激进设置（风险较高）
php bin/w mail:send --to="recipients.xlsx" --subject="..." \
    --bulk=50 \
    --max-per-hour=300 \
    --delay=60000  # 1分钟延迟
```

### 3. 监控账号健康

定期检查每个账号的：
- ✅ 发送成功率
- ⚠️ 失败次数
- 🔒 是否被限流
- 📊 日发送量

---

## 🛡️ 安全与可靠性

### 自动保护机制

1. **防止重复发送**
   - 成功的邮件记录在 `sent_emails.json`
   - 自动跳过已发送的邮件

2. **断点续发**
   - 中断后自动从上次位置继续
   - 不会丢失进度

3. **失败隔离**
   - 一个账号故障不影响其他账号
   - 自动切换保证服务连续性

4. **智能限流**
   - 遵守 SMTP 服务器限制
   - 避免触发垃圾邮件过滤器

---

## 🔍 故障排查

### 问题 1: 所有账号都失败

**现象**: 切换账号后仍然失败

**可能原因**:
- 收件人邮箱不存在
- 邮件内容被识别为垃圾邮件
- 所有账号都被限流

**解决方案**:
1. 检查收件人邮箱是否有效
2. 调整邮件内容，避免垃圾词汇
3. 降低发送频率
4. 联系 SMTP 服务商

### 问题 2: 频繁切换账号

**现象**: 每次发送都切换账号

**可能原因**:
- 某个账号配置错误
- 网络不稳定
- 发送限制设置过低

**解决方案**:
1. 检查账号配置（密码、端口等）
2. 测试网络连接
3. 提高 `--max-per-hour` 限制

### 问题 3: 重试后仍失败

**现象**: 切换账号重试后仍然失败

**可能原因**:
- 收件人服务器问题
- IP 被列入黑名单
- 邮件格式问题

**解决方案**:
1. 检查永久失败列表
2. 验证邮件格式
3. 使用不同的 IP 或服务商

---

## 📞 更多帮助

- 完整功能指南: `BREAKPOINT_GUIDE.md`
- 显示格式说明: `DISPLAY_FORMAT_EXAMPLE.md`
- 在线帮助: `php bin/w mail:send --help`
- 诊断工具: `php app/code/GuoLaiRen/Smtp/Console/Mail/check_breakpoint.php`

