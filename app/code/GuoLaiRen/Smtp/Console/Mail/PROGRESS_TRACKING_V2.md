# 📊 进度跟踪机制说明 v2.0

## ✅ 当前机制（不使用断点文件）

### 🎯 核心原理
**完全依赖 `sent_emails_*.json` 文件进行进度跟踪和去重。**

### 📍 进度显示说明

#### 1. **批次进度**：`--- Batch 43/200 ---`
- **含义**：当前是第43个批次，总共200个批次
- **计算**：基于原始文件总邮件数 ÷ 批次大小
- **包含**：已发送和未发送的邮件都计入批次数

#### 2. **邮件位置**：`[149/200]`
- **含义**：当前邮件在原始文件中是第149封，总共200封
- **计算**：`(批次索引 × 批次大小) + 邮件索引 + 1`
- **用途**：显示当前处理到原始文件的哪个位置
- **注意**：这个数字**不是**从断点文件读取的，而是基于当前遍历位置计算的

#### 3. **发送进度**：`Progress: 150/200 emails sent (75%)`
- **含义**：已成功发送150封，总共200封，完成度75%
- **计算**：直接从 `sent_emails_*.json` 文件统计已发送数量
- **包含**：所有成功发送的邮件（包括之前运行时发送的）

### 🔄 工作流程

```
1. 读取原始邮件列表（例如：200封邮件）
   ├─ Email 1
   ├─ Email 2
   ├─ ...
   ├─ Email 149  ← 当前批次
   └─ Email 200

2. 对每封邮件检查 sent_emails_*.json
   ├─ Email 1-148: ✓ 已发送（跳过）
   ├─ Email 149:   ✓ 已发送（跳过）← Batch 43 显示 [149/200]
   └─ Email 150:   ✗ 未发送（发送）← Batch 44 显示 [150/200]

3. 发送成功后
   └─ 添加到 sent_emails_*.json
   └─ 更新进度：150/200 (75%)

4. 下次运行时
   └─ 从 Email 151 开始（Email 1-150 自动跳过）
```

### 🎨 示例输出解析

```
--- Batch 43/200 ---
Processing: 1 email(s)
📤 From: Stockcircle <alex@stockcircle.to>
  [149/200] ⏭️  Skipped (already sent): Guzman Castro <guzman@wahbyfinancial.com>
⏭️  All emails in this batch already sent, skipping...

--- Batch 44/200 ---
Processing: 1 email(s)
📤 From: Stockcircle <alex@stockcircle.to>
  [150/200] 📧 To: Hannah Farrow <hannah@rightwisewealth.com>
✓ Batch sent successfully
Progress: 150/200 emails sent (75%)
⏳ Waiting 3.8 minute(s) before next batch...
```

**解读**：
- **Batch 43/200**：处理第43批（每批1封邮件）
  - 这是原始文件中的第149封邮件
  - 检查 `sent_emails_*.json` 发现已发送，跳过
  
- **Batch 44/200**：处理第44批
  - 这是原始文件中的第150封邮件
  - 检查 `sent_emails_*.json` 发现未发送，执行发送
  - 发送成功后添加到 `sent_emails_*.json`
  - 更新总进度：150/200 (75%)

### ✅ 优势

1. **无断点文件依赖**
   - 不再创建/读取/清除 `breakpoint_*.json` 文件
   - 简化了文件管理

2. **完全基于已发送记录**
   - `sent_emails_*.json` 是唯一的真实来源
   - 永久记录，不会被清除（除非手动删除）

3. **多进程安全**
   - 每个收件人文件有独立的 `sent_emails_*.json`
   - 使用文件锁防止并发写入冲突

4. **断点续传**
   - 虽然没有断点文件，但通过 `sent_emails_*.json` 实现自动续传
   - 再次运行时自动跳过已发送邮件

5. **进度透明**
   - `[149/200]` 显示原始文件位置，便于追踪
   - `150/200 (75%)` 显示实际发送进度

### 🔧 关键代码逻辑

```php
// 1. 计算邮件在原始文件中的位置（不依赖断点）
$recipientNum = ($batchIndex * $batchSize) + $idx + 1;

// 2. 检查是否已发送（依赖 sent_emails_*.json）
if ($this->breakpointManager->isEmailSent($recipient['email'])) {
    // 跳过，但显示位置 [149/200]
    $this->printer->note("[$recipientNum/$originalTotal] ⏭️  Skipped (already sent)");
} else {
    // 发送，并显示位置 [150/200]
    $this->printer->note("[$recipientNum/$originalTotal] 📧 To: ...");
    // 发送成功后记录到 sent_emails_*.json
}

// 3. 显示总进度（从 sent_emails_*.json 统计）
$totalSentEmails = $this->breakpointManager->getSentEmailsCount();
$this->printer->note("Progress: $totalSentEmails/$originalTotal emails sent");
```

### 📝 文件结构

```
app/code/GuoLaiRen/Smtp/Console/Mail/emails/
├── meta个人邮箱版本-1023.xlsx                     # 原始收件人文件
├── sent_emails_meta个人邮箱版本-1023.json          # 已发送记录（唯一进度来源）
├── failed_emails_meta个人邮箱版本-1023.json        # 失败记录（临时）
└── permanent_failed_meta个人邮箱版本-1023.json     # 永久失败记录
```

**注意**：不再有 `breakpoint_*.json` 文件！

### 🎯 总结

| 项目 | 旧系统（断点） | 新系统（sent_emails） |
|------|---------------|---------------------|
| 进度来源 | `breakpoint_*.json` | `sent_emails_*.json` |
| 文件管理 | 需要清除断点文件 | 永久保留记录 |
| 续传机制 | 从断点位置继续 | 遍历所有，跳过已发 |
| 多进程安全 | 容易冲突 | 独立文件 + 文件锁 |
| 位置显示 | 基于断点索引 | 基于遍历位置 |
| 总进度统计 | 需要同步断点 | 直接统计记录数 |

**核心理念**：
- ❌ 不再使用断点文件
- ✅ `sent_emails_*.json` 是唯一的进度记录
- ✅ 每次运行都从头遍历，但自动跳过已发送
- ✅ `[149/200]` 只是显示当前遍历位置，不代表使用断点系统

