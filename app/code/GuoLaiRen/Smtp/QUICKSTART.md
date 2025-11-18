# SMTP 模块快速开始指南

## 1. 快速配置（5分钟）

### 步骤 1：编辑配置文件

打开 `app/code/GuoLaiRen/Smtp/etc/env.php`，修改以下配置：

```php
return [
    'smtp' => [
        'username' => 'your-email@yourdomain.com',  // 改成您的邮箱
        'password' => 'your-password',               // 改成您的密码
        'from_email' => 'your-email@yourdomain.com', // 改成您的邮箱
        'from_name' => '您的名字',                   // 改成您的名字
    ]
];
```

### 步骤 2：启用模块

```bash
# 更新模块
php bin/w module:upgrade

# 更新命令列表
php bin/w command:upgrade
```

### 步骤 3：测试配置

```bash
php bin/w mail:test --to=your-test-email@example.com
```

如果收到测试邮件，配置成功！✅

## 2. 使用示例

### 发送简单邮件

```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="你好" \
  --body="这是一封测试邮件"
```

### 发送HTML邮件

```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="欢迎" \
  --body="<h1>欢迎使用</h1><p>这是HTML邮件</p>" \
  --html=1
```

### 发送带附件的邮件

```bash
php bin/w mail:send \
  --to=user@example.com \
  --subject="报告" \
  --body="请查收附件" \
  --attachment=/path/to/file.pdf
```

## 3. Namecheap 专用配置

如果您使用的是 Namecheap Private Email：

1. 登录 Namecheap 控制面板
2. 找到您的 Private Email 服务
3. 获取以下信息：
   - SMTP服务器：`mail.privateemail.com`
   - 端口：`587` (TLS) 或 `465` (SSL)
   - 用户名：完整邮箱地址
   - 密码：邮箱密码

4. 将信息填入配置文件即可

## 4. 常见问题

### Q: 提示"邮件发送失败"？

**A:** 请检查：
1. 用户名和密码是否正确
2. 用户名必须是完整的邮箱地址
3. 端口号是否正确（587或465）
4. 加密方式是否匹配端口（587用tls，465用ssl）

### Q: 如何使用QQ邮箱？

**A:** 修改配置：
```php
'host' => 'smtp.qq.com',
'port' => 465,
'encryption' => 'ssl',
'username' => 'your-qq@qq.com',
'password' => '授权码', // 注意：不是QQ密码，是授权码
```

获取QQ邮箱授权码：
1. 登录QQ邮箱
2. 设置 → 账户
3. 开启SMTP服务
4. 获取授权码

### Q: 如何使用163邮箱？

**A:** 修改配置：
```php
'host' => 'smtp.163.com',
'port' => 465,
'encryption' => 'ssl',
'username' => 'your-name@163.com',
'password' => '授权码', // 注意：不是登录密码，是授权码
```

### Q: 如何在代码中使用？

**A:** 示例代码：
```php
use GuoLaiRen\Smtp\Helper\SmtpMailer;
use Weline\Framework\Manager\ObjectManager;

$mailer = ObjectManager::getInstance(SmtpMailer::class);
$result = $mailer->sendMail(
    'user@example.com',
    '张三',
    '邮件主题',
    '邮件内容',
    false  // true=HTML, false=纯文本
);
```

## 5. 命令参考

| 命令 | 说明 |
|------|------|
| `php bin/w mail:send --help` | 查看发送邮件命令帮助 |
| `php bin/w mail:test --help` | 查看测试命令帮助 |

### mail:send 参数

| 参数 | 必需 | 说明 | 示例 |
|------|------|------|------|
| `--to` | ✅ | 收件人邮箱 | `--to=user@example.com` |
| `--subject` | ✅ | 邮件主题 | `--subject="测试"` |
| `--body` | ✅ | 邮件内容 | `--body="内容"` |
| `--to-name` | ❌ | 收件人姓名 | `--to-name="张三"` |
| `--html` | ❌ | HTML格式 | `--html=1` |
| `--cc` | ❌ | 抄送 | `--cc=a@x.com,b@x.com` |
| `--bcc` | ❌ | 密送 | `--bcc=c@x.com` |
| `--attachment` | ❌ | 附件路径 | `--attachment=/path/to/file` |

## 6. 高级用法

### 在Windows PowerShell中发送多行HTML

```powershell
php bin/w mail:send `
  --to=user@example.com `
  --subject="报告" `
  --body="<h1>标题</h1><p>内容</p>" `
  --html=1
```

### 发送给多个抄送人

```bash
php bin/w mail:send \
  --to=main@example.com \
  --cc=cc1@example.com,cc2@example.com,cc3@example.com \
  --subject="群发" \
  --body="内容"
```

## 7. 安全建议

⚠️ **重要提示：**

1. 不要将包含密码的配置文件提交到版本控制系统
2. 建议将 `app/code/GuoLaiRen/Smtp/etc/env.php` 添加到 `.gitignore`
3. 生产环境使用环境变量存储敏感信息
4. 定期更换邮箱密码

## 8. 获取帮助

- 查看详细文档：`app/code/GuoLaiRen/Smtp/README.md`
- 命令帮助：`php bin/w mail:send --help`
- 测试帮助：`php bin/w mail:test --help`

---

🎉 **祝您使用愉快！**

