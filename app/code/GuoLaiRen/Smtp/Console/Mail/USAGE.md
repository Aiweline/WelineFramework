# SMTP 模块使用指南

## 📁 文件结构

```
Console/Mail/
├── Send.php              # 单个邮件发送命令
├── Test.php              # 测试命令（支持配置文件）
├── Bulk.php              # 批量发送命令（多账户均分）
├── mail.html             # HTML邮件模板
├── smtps.json           # SMTP账户配置文件
├── emails/              # 邮件列表目录
│   ├── recipients.txt   # TXT格式收件人列表
│   └── README.md        # 邮件列表说明
└── USAGE.md            # 本文件
```

---

## 🔧 配置文件说明

### 1. smtps.json - SMTP账户配置

存储多个 Namecheap SMTP 账户信息：

```json
{
  "accounts": [
    {
      "id": 1,
      "name": "Account 1",
      "host": "mail.privateemail.com",
      "port": 587,
      "encryption": "tls",
      "username": "your-email-1@yourdomain.com",
      "password": "your-password-1",
      "from_email": "your-email-1@yourdomain.com",
      "from_name": "Sender Name 1",
      "enabled": true,
      "daily_limit": 500,
      "comment": "第一个账户"
    },
    {
      "id": 2,
      "name": "Account 2",
      ...
    }
  ]
}
```

**配置说明：**
- `id`: 账户唯一标识
- `enabled`: 是否启用（true/false）
- `daily_limit`: 每日发送限制
- `host`: SMTP服务器地址
- `port`: SMTP端口（587 TLS / 465 SSL）
- `encryption`: 加密方式（tls/ssl）

### 2. mail.html - 邮件模板

HTML 邮件模板，支持变量替换：

**可用变量：**
- `{{TITLE}}` - 邮件标题
- `{{RECIPIENT_NAME}}` - 收件人姓名
- `{{CONTENT}}` - 邮件正文
- `{{BUTTON}}` - 按钮HTML
- `{{SENDER_NAME}}` - 发件人名称
- `{{YEAR}}` - 年份
- `{{COMPANY_NAME}}` - 公司名称
- `{{UNSUBSCRIBE_LINK}}` - 退订链接

### 3. emails/ - 邮件列表目录

存储收件人邮箱列表，支持多种格式：

**TXT 格式 (recipients.txt):**
```
user1@example.com
John Doe <john@example.com>
# 这是注释
```

**CSV 格式:**
```csv
email,name,status
user1@example.com,张三,active
user2@example.com,李四,inactive
```

**JSON 格式:**
```json
{
  "recipients": [
    {"email": "user1@example.com", "name": "张三", "active": true}
  ]
}
```

---

## 📧 命令使用说明

### 1️⃣ mail:test - 测试命令

#### 使用默认配置测试
```bash
php bin/w mail:test --to=your-email@example.com
```

#### 使用 smtps.json 配置测试
```bash
# 测试账户1
php bin/w mail:test --to=your-email@example.com --use-config=1

# 测试账户2
php bin/w mail:test --to=your-email@example.com --use-config=1 --account=2
```

**参数说明：**
- `--to` ✅必需：收件人邮箱
- `--use-config` ❌可选：使用配置文件（默认使用env.php）
- `--account` ❌可选：指定账户ID（默认1）

---

### 2️⃣ mail:send - 单个邮件发送

#### 发送纯文本邮件
```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="测试邮件" \
  --body="这是邮件内容"
```

#### 发送HTML邮件
```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="欢迎" \
  --body="<h1>欢迎</h1><p>内容</p>" \
  --html=1
```

#### 发送带附件的邮件
```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="报告" \
  --body="请查收附件" \
  --attachment=E:\path\to\file.pdf
```

#### 完整示例
```bash
php bin/w mail:send \
  --to=user@example.com \
  --to-name="张三" \
  --subject="月度报告" \
  --body="<h1>报告</h1>" \
  --html=1 \
  --cc=manager@example.com \
  --bcc=admin@example.com \
  --attachment=E:\report.pdf
```

**参数说明：**
- `--to` ✅必需：收件人邮箱
- `--subject` ✅必需：邮件主题
- `--body` ✅必需：邮件内容
- `--to-name` ❌可选：收件人姓名
- `--html` ❌可选：是否HTML格式（1/0）
- `--cc` ❌可选：抄送（多个用逗号分隔）
- `--bcc` ❌可选：密送（多个用逗号分隔）
- `--attachment` ❌可选：附件路径

---

### 3️⃣ mail:bulk - 批量发送（多账户均分）

#### 基本用法
```bash
php bin/w mail:bulk \
  --file=recipients.txt \
  --subject="通知" \
  --body="这是通知内容"
```

#### 使用HTML模板
```bash
php bin/w mail:bulk \
  --file=recipients.txt \
  --subject="重要通知" \
  --template=1 \
  --title="重要通知" \
  --content="这是通知内容" \
  --sender="公司名称"
```

#### 使用CSV文件
```bash
php bin/w mail:bulk \
  --file=recipients.csv \
  --subject="促销活动" \
  --body="<h1>限时优惠</h1>"
```

#### 使用JSON文件
```bash
php bin/w mail:bulk \
  --file=list.json \
  --subject="系统通知" \
  --body="系统更新通知"
```

**参数说明：**
- `--file` ✅必需：邮件列表文件名（位于emails/目录）
- `--subject` ✅必需：邮件主题
- `--body` ❌可选：邮件内容（不使用模板时必需）
- `--template` ❌可选：使用HTML模板
- `--html` ❌可选：是否HTML格式（默认1）
- `--title` ❌可选：模板标题
- `--content` ❌可选：模板内容
- `--sender` ❌可选：发件人名称

**工作原理：**
1. 读取 `smtps.json` 中所有启用的账户
2. 读取邮件列表文件中的所有收件人
3. 将收件人平均分配给各个账户
4. 每个账户发送分配给它的邮件

**示例：**
- 2个账户，100个收件人
- 账户1发送：1-50
- 账户2发送：51-100

---

## 🎯 实际使用流程

### 步骤 1：配置账户

编辑 `smtps.json`，填入你的 Namecheap 账户信息：

```json
{
  "accounts": [
    {
      "id": 1,
      "username": "noreply@yourdomain.com",
      "password": "your-actual-password",
      ...
    }
  ]
}
```

### 步骤 2：测试账户

测试每个账户是否配置正确：

```bash
# 测试账户1
php bin/w mail:test --to=your-email@gmail.com --use-config=1 --account=1

# 测试账户2
php bin/w mail:test --to=your-email@gmail.com --use-config=1 --account=2
```

### 步骤 3：准备邮件列表

在 `emails/` 目录创建收件人列表：

```bash
# 创建邮件列表
echo "user1@example.com" > emails/my_list.txt
echo "user2@example.com" >> emails/my_list.txt
```

### 步骤 4：批量发送

```bash
php bin/w mail:bulk \
  --file=my_list.txt \
  --subject="测试邮件" \
  --body="这是测试内容"
```

---

## 📊 发送结果示例

```
正在测试SMTP配置...
找到 2 个可用的SMTP账户
找到 100 个收件人
开始批量发送邮件...

账户 [account1@domain.com] 发送 50 封邮件 (1-50)
✓ 发送成功: user1@example.com
✓ 发送成功: user2@example.com
...

账户 [account2@domain.com] 发送 50 封邮件 (51-100)
✓ 发送成功: user51@example.com
...

========== 发送结果汇总 ==========
总计: 100
成功: 98
失败: 2
成功率: 98.00%

账户 [account1@domain.com]: 成功 49, 失败 1
账户 [account2@domain.com]: 成功 49, 失败 1
```

---

## ⚠️ 注意事项

1. **配置安全**
   - 不要将包含密码的 `smtps.json` 提交到 Git
   - 建议添加到 `.gitignore`

2. **发送限制**
   - Namecheap 每日限制约 500 封
   - 批量发送时会自动延迟（0.1秒/封）

3. **垃圾邮件**
   - 避免频繁发送
   - 提供退订链接
   - 使用有效的发件人地址

4. **账户启用**
   - `enabled: false` 的账户不会被使用
   - 方便临时禁用某个账户

5. **文件路径**
   - Windows: 使用 `E:\path\to\file`
   - 邮件列表文件必须在 `emails/` 目录下

---

## 🔍 故障排查

### 问题：测试发送失败

**检查项：**
1. smtps.json 格式是否正确
2. 用户名和密码是否正确
3. 端口和加密方式是否匹配
4. 防火墙是否允许 587/465 端口

### 问题：批量发送部分失败

**可能原因：**
1. 收件人邮箱无效
2. 发送频率过快
3. 账户达到日发送限制
4. 被识别为垃圾邮件

### 问题：找不到邮件列表文件

**解决方法：**
1. 确认文件在 `emails/` 目录下
2. 检查文件名是否正确
3. 使用相对路径，不要用绝对路径

---

## 📞 获取帮助

查看命令帮助：
```bash
php bin/w mail:send --help
php bin/w mail:test --help
php bin/w mail:bulk --help
```

查看所有邮件命令：
```bash
php bin/w | grep mail
```

---

**祝使用愉快！** 🎉

