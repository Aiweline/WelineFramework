# 邮件发送显示格式示例

## 📧 新的显示格式

现在发送邮件时会同时显示**发送者**和**收件人**信息，让你清楚地知道邮件是从哪个账号发出的。

---

## 🎯 批量发送显示示例

```bash
php bin/w mail:send --to="emails/recipients.xlsx" --subject="Newsletter" --bulk=10
```

### 输出示例：

```
========== Starting Email Sending ==========

--- Batch 1/10 ---
Processing: 10 email(s)
📤 From: Marketing Team <marketing@company.com>
  [1/100] 📧 To: Grant Hoerr <ghoerr@mqplp.com>
  [2/100] 📧 To: John Smith <john@example.com>
  [3/100] 📧 To: Sarah Johnson <sarah@example.com>
  [4/100] 📧 To: mike@company.com
  [5/100] 📧 To: lisa@startup.io
  ...
✓ Batch sent successfully
⏳ Waiting 3.2 minute(s) before next batch...

--- Batch 2/10 ---
Processing: 10 email(s)
📤 From: Sales Team <sales@company.com>
  [11/100] 📧 To: David Brown <david@example.com>
  [12/100] 📧 To: Emma Wilson <emma@example.com>
  ...
✓ Batch sent successfully
```

---

## 🔄 重试失败邮件显示示例

```
========== Retrying Failed Emails ==========

📤 From: Marketing Team <marketing@company.com>
📧 To: Grant Hoerr <ghoerr@mqplp.com> (Retry attempt 2)
  ✓ Success

📤 From: Sales Team <sales@company.com>
📧 To: John Doe <john@failed.com> (Retry attempt 2)
  ✗ Failed
```

---

## 📊 账号轮换显示示例

当使用多个 SMTP 账号时，系统会自动轮换使用，每个批次都会显示当前使用的账号：

```
========== SMTP Accounts Info ==========
Total Accounts: 3
Rotation Mode: Round-Robin (load balancing)

Account #1: Marketing Team
  From: Marketing Team <marketing@company.com>
  Usage: 0/100 (Session Limit)
  Daily Limit: 500

Account #2: Sales Team
  From: Sales Team <sales@company.com>
  Usage: 0/100 (Session Limit)
  Daily Limit: 500

Account #3: Support Team
  From: Support Team <support@company.com>
  Usage: 0/100 (Session Limit)
  Daily Limit: 500

========== Starting Email Sending ==========

--- Batch 1/30 ---
Processing: 10 email(s)
📤 From: Marketing Team <marketing@company.com>
  [1/300] 📧 To: recipient1@example.com
  ...
✓ Batch sent successfully

--- Batch 2/30 ---
Processing: 10 email(s)
📤 From: Sales Team <sales@company.com>  ← 自动切换到下一个账号
  [11/300] 📧 To: recipient11@example.com
  ...
✓ Batch sent successfully

--- Batch 3/30 ---
Processing: 10 email(s)
📤 From: Support Team <support@company.com>  ← 继续轮换
  [21/300] 📧 To: recipient21@example.com
  ...
✓ Batch sent successfully

--- Batch 4/30 ---
Processing: 10 email(s)
📤 From: Marketing Team <marketing@company.com>  ← 回到第一个账号
  [31/300] 📧 To: recipient31@example.com
  ...
```

---

## 📝 格式说明

### 图标含义：

- 📤 **From:** 发送者（当前使用的 SMTP 账号）
- 📧 **To:** 收件人
- ✓ 发送成功
- ✗ 发送失败
- ⏳ 等待延迟
- ⚠️ 警告信息
- 💡 提示信息

### 显示信息：

1. **批次信息：** `--- Batch 1/10 ---`
   - 当前批次/总批次数

2. **发送者信息：** `📤 From: Marketing Team <marketing@company.com>`
   - 显示当前使用的 SMTP 账号
   - 格式：显示名称 <邮箱地址>
   - 如果没有设置显示名称，只显示邮箱地址

3. **收件人信息：** `[1/100] 📧 To: Grant Hoerr <ghoerr@mqplp.com>`
   - [当前序号/总数] 
   - 收件人姓名 <邮箱地址>
   - 如果没有姓名，只显示邮箱地址

4. **结果状态：** 
   - `✓ Batch sent successfully` - 批次发送成功
   - `✗ Batch failed: error message` - 批次发送失败

---

## 🎨 优势

### 1. **清晰透明**
- 一目了然知道每封邮件是从哪个账号发出的
- 避免混淆，特别是使用多个账号时

### 2. **便于跟踪**
- 发送日志更详细
- 出问题时容易定位是哪个账号

### 3. **负载均衡可视化**
- 可以看到账号轮换的过程
- 了解每个账号的使用情况

### 4. **专业性**
- 符合邮件系统的标准格式
- From/To 格式清晰规范

---

## 🔧 配置发送者信息

在 `smtps.json` 中配置每个账号的发送者信息：

```json
{
    "accounts": [
        {
            "name": "Marketing Team",
            "from_email": "marketing@company.com",
            "from_name": "Marketing Team",
            "host": "smtp.gmail.com",
            "port": 587,
            "username": "marketing@company.com",
            "password": "your-password",
            "encryption": "tls",
            "enabled": true,
            "daily_limit": 500
        },
        {
            "name": "Sales Team",
            "from_email": "sales@company.com",
            "from_name": "Sales Team",
            "host": "smtp.gmail.com",
            "port": 587,
            "username": "sales@company.com",
            "password": "your-password",
            "encryption": "tls",
            "enabled": true,
            "daily_limit": 500
        }
    ]
}
```

### 字段说明：

- `from_name`: 发送者显示名称（可选）
- `from_email`: 发送者邮箱地址（必填）
- `name`: 账号标识名称（用于显示账号信息）

---

## 💡 使用建议

1. **设置有意义的发送者名称**
   ```json
   "from_name": "Newsletter Team"  ✓ 好
   "from_name": "Account1"         ✗ 不够清晰
   ```

2. **保持发送者名称一致性**
   - 所有营销邮件使用 "Marketing Team"
   - 所有通知邮件使用 "Notifications"

3. **监控账号使用**
   - 注意观察哪个账号发送成功率高
   - 及时调整账号配置

---

## 📞 更多信息

查看完整功能指南：
```bash
php bin/w mail:send --help
```

查看断点续发功能：
```
app/code/GuoLaiRen/Smtp/Console/Mail/BREAKPOINT_GUIDE.md
```

