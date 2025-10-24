# GuoLaiRen SMTP 模块

SMTP邮件发送模块，支持通过命令行发送邮件，适配Namecheap及其他常见邮件服务商。

## 功能特性

- ✅ 支持命令行发送邮件
- ✅ 支持HTML和纯文本格式
- ✅ 支持抄送（CC）和密送（BCC）
- ✅ 支持附件发送
- ✅ 支持批量发送（代码级别）
- ✅ 适配Namecheap Private Email
- ✅ 支持多种邮件服务商（Gmail、Outlook、QQ、163等）

## 安装配置

### 1. 配置SMTP信息

编辑配置文件 `app/code/GuoLaiRen/Smtp/etc/env.php`：

```php
return [
    'smtp' => [
        'host' => 'mail.privateemail.com',  // Namecheap SMTP服务器
        'port' => 587,                       // TLS端口
        'encryption' => 'tls',               // 加密方式
        'username' => 'your-email@yourdomain.com',  // 您的邮箱
        'password' => 'your-password',       // 您的密码
        'from_email' => 'your-email@yourdomain.com',
        'from_name' => 'GuoLaiRen',
    ]
];
```

### 2. 升级模块

```bash
php bin/w module:upgrade
```

### 3. 更新命令列表

```bash
php bin/w command:upgrade
```

## 使用方法

### 基本用法

发送简单的纯文本邮件：

```bash
php bin/w mail:send --to=user@example.com --subject="测试邮件" --body="这是一封测试邮件"
```

### 发送HTML邮件

```bash
php bin/w mail:send --to=user@example.com --subject="欢迎邮件" --body="<h1>欢迎</h1><p>这是一封HTML格式的邮件</p>" --html=1
```

### 带收件人姓名

```bash
php bin/w mail:send --to=user@example.com --to-name="张三" --subject="问候" --body="您好，张三！"
```

### 发送带抄送的邮件

```bash
php bin/w mail:send --to=user@example.com --subject="会议通知" --body="会议内容" --cc=cc1@example.com,cc2@example.com
```

### 发送带密送的邮件

```bash
php bin/w mail:send --to=user@example.com --subject="私密通知" --body="通知内容" --bcc=bcc@example.com
```

### 发送带附件的邮件

```bash
php bin/w mail:send --to=user@example.com --subject="报告" --body="请查收附件" --attachment=/path/to/report.pdf
```

### 完整示例

```bash
php bin/w mail:send \
  --to=user@example.com \
  --to-name="李四" \
  --subject="月度报告" \
  --body="<h1>月度报告</h1><p>请查收本月的业绩报告。</p>" \
  --html=1 \
  --cc=manager@example.com \
  --attachment=/path/to/monthly-report.pdf
```

## 命令参数说明

| 参数 | 必需 | 说明 |
|------|------|------|
| `--to` | ✅ | 收件人邮箱地址 |
| `--to-name` | ❌ | 收件人姓名 |
| `--subject` | ✅ | 邮件主题 |
| `--body` | ✅ | 邮件正文内容 |
| `--html` | ❌ | 是否为HTML格式（1=是，0=否，默认0） |
| `--cc` | ❌ | 抄送地址（多个用逗号分隔） |
| `--bcc` | ❌ | 密送地址（多个用逗号分隔） |
| `--attachment` | ❌ | 附件文件路径 |

## 查看帮助

```bash
php bin/w mail:send --help
```

## Namecheap 配置说明

### 获取SMTP信息

1. 登录Namecheap账户
2. 进入Private Email控制面板
3. SMTP设置：
   - 服务器: `mail.privateemail.com`
   - 端口: `587` (TLS) 或 `465` (SSL)
   - 用户名: 您的完整邮箱地址
   - 密码: 您的邮箱密码
   - 需要身份验证: 是

### 常见问题

**Q: 提示认证失败？**
A: 请确认用户名和密码正确，用户名应该是完整的邮箱地址。

**Q: 邮件发送失败？**
A: 检查以下几点：
- SMTP服务器地址和端口是否正确
- 是否开启了SMTP服务
- 防火墙是否允许SMTP端口
- 邮箱密码是否正确

**Q: 如何使用其他邮件服务商？**
A: 修改配置文件中的host、port等参数即可，具体参数参考配置文件注释。

## 其他邮件服务商配置

### Gmail

```php
'host' => 'smtp.gmail.com',
'port' => 587,
'encryption' => 'tls',
```

### QQ邮箱

```php
'host' => 'smtp.qq.com',
'port' => 465,
'encryption' => 'ssl',
// 注意：需要使用授权码，不是登录密码
```

### 163邮箱

```php
'host' => 'smtp.163.com',
'port' => 465,
'encryption' => 'ssl',
// 注意：需要使用授权码，不是登录密码
```

## 代码示例

在您的代码中使用：

```php
use GuoLaiRen\Smtp\Helper\SmtpMailer;
use Weline\Framework\Manager\ObjectManager;

// 获取邮件发送器
$mailer = ObjectManager::getInstance(SmtpMailer::class);

// 发送邮件
$result = $mailer->sendMail(
    'user@example.com',     // 收件人
    '张三',                  // 收件人姓名
    '测试邮件',              // 主题
    '这是邮件内容',          // 内容
    false,                   // 是否HTML
    null,                    // 抄送
    null,                    // 密送
    null                     // 附件
);

if ($result) {
    echo "邮件发送成功！";
}
```

## 依赖

- PHPMailer (已包含在vendor中)
- PHP >= 8.0

## 版本

- 当前版本：1.0.0
- 作者：GuoLaiRen
- 许可证：MIT

## 更新日志

### 1.0.0 (2024)
- 初始版本
- 支持基本邮件发送
- 支持Namecheap SMTP配置
- 命令行接口

