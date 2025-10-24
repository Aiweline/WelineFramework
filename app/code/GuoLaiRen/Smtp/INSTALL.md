# GuoLaiRen SMTP 模块安装指南

## 📋 系统要求

- PHP >= 8.0
- WelineFramework
- PHPMailer (已包含在 vendor 中)
- 有效的 SMTP 邮箱账户（如 Namecheap Private Email）

## 🚀 安装步骤

### 第一步：模块已就位

模块文件位于：`app/code/GuoLaiRen/Smtp/`

目录结构：
```
GuoLaiRen/Smtp/
├── Console/
│   └── Mail/
│       ├── Send.php      # 发送邮件命令
│       └── Test.php      # 测试命令
├── Helper/
│   └── SmtpMailer.php    # SMTP邮件发送器
├── etc/
│   └── env.php           # 配置文件
├── i18n/
│   ├── en_US.csv         # 英文语言包
│   └── zh_Hans_CN.csv    # 简体中文语言包
├── example.bat           # Windows示例脚本
├── example.sh            # Linux/Mac示例脚本
├── INSTALL.md            # 安装指南（本文件）
├── QUICKSTART.md         # 快速开始指南
├── README.md             # 详细文档
└── register.php          # 模块注册文件
```

### 第二步：配置 SMTP 信息

编辑配置文件 `app/code/GuoLaiRen/Smtp/etc/env.php`：

```php
return [
    'smtp' => [
        'host' => 'mail.privateemail.com',  // SMTP服务器地址
        'port' => 587,                       // SMTP端口
        'encryption' => 'tls',               // 加密方式: tls 或 ssl
        'username' => 'your-email@yourdomain.com',  // ⚠️ 改成您的邮箱
        'password' => 'your-password',       // ⚠️ 改成您的密码
        'from_email' => 'your-email@yourdomain.com',
        'from_name' => 'GuoLaiRen',
    ]
];
```

#### Namecheap Private Email 配置示例：

```php
'host' => 'mail.privateemail.com',
'port' => 587,                      // 或 465 for SSL
'encryption' => 'tls',              // 或 'ssl'
'username' => 'info@yourdomain.com',
'password' => 'yourpassword',
'from_email' => 'info@yourdomain.com',
'from_name' => 'Your Company Name',
```

#### 其他邮件服务商配置：

**QQ邮箱：**
```php
'host' => 'smtp.qq.com',
'port' => 465,
'encryption' => 'ssl',
'username' => 'yourname@qq.com',
'password' => 'authorization-code',  // 授权码，不是QQ密码
```

**163邮箱：**
```php
'host' => 'smtp.163.com',
'port' => 465,
'encryption' => 'ssl',
'username' => 'yourname@163.com',
'password' => 'authorization-code',  // 授权码，不是登录密码
```

**Gmail：**
```php
'host' => 'smtp.gmail.com',
'port' => 587,
'encryption' => 'tls',
'username' => 'yourname@gmail.com',
'password' => 'app-password',  // 应用专用密码
```

### 第三步：启用模块

在项目根目录执行：

```bash
# 升级模块
php bin/w module:upgrade

# 更新命令列表
php bin/w command:upgrade
```

### 第四步：测试配置

发送测试邮件验证配置是否正确：

```bash
php bin/w mail:test --to=your-email@example.com
```

如果成功，您会看到：
```
✓ 测试邮件发送成功！
  请检查邮箱: your-email@example.com
  如果没有收到邮件，请检查垃圾邮件箱。
```

## ✅ 验证安装

### 1. 检查命令是否注册

```bash
php bin/w mail:send --help
```

应该显示命令帮助信息。

### 2. 发送测试邮件

```bash
php bin/w mail:send \
  --to=test@example.com \
  --subject="测试邮件" \
  --body="安装成功！"
```

### 3. 查看可用命令

```bash
# 列出所有命令
php bin/w

# 搜索 mail 相关命令
php bin/w | grep mail
```

应该能看到：
- `mail:send` - 发送邮件
- `mail:test` - 测试SMTP配置

## 🔧 常见问题

### Q1: 命令未找到

**问题：** 运行命令时提示 "Command not found"

**解决方案：**
```bash
# 清除缓存
php bin/w cache:clear

# 重新升级命令
php bin/w command:upgrade
```

### Q2: SMTP连接失败

**问题：** 提示 "Connection refused" 或 "Could not connect"

**解决方案：**
1. 检查 SMTP 服务器地址是否正确
2. 检查端口号是否正确（587 for TLS, 465 for SSL）
3. 检查防火墙是否阻止了 SMTP 端口
4. 确认加密方式与端口匹配

### Q3: 认证失败

**问题：** 提示 "Authentication failed"

**解决方案：**
1. 确认用户名是完整的邮箱地址
2. 确认密码正确（有些邮箱需要使用授权码而非登录密码）
3. 检查邮箱是否启用了 SMTP 服务
4. 对于QQ、163等邮箱，需要生成并使用授权码

### Q4: 发送失败但无错误信息

**解决方案：**
1. 检查 PHP 错误日志
2. 启用 SMTP 调试模式（修改 SmtpMailer.php 中的 SMTPDebug 为 2）
3. 检查邮箱发送限额是否已达上限

### Q5: Windows下路径问题

**问题：** Windows 环境下附件路径不正确

**解决方案：**
使用完整路径，例如：
```bash
php bin\w mail:send --attachment="C:\Users\YourName\Documents\file.pdf"
```

## 📝 配置检查清单

安装完成后，请确认以下配置：

- [ ] `app/code/GuoLaiRen/Smtp/etc/env.php` 已正确配置
- [ ] SMTP 用户名和密码已填写
- [ ] 已执行 `php bin/w module:upgrade`
- [ ] 已执行 `php bin/w command:upgrade`
- [ ] 测试邮件发送成功
- [ ] `mail:send --help` 命令可以正常显示

## 🎯 下一步

安装成功后，您可以：

1. 查看 [快速开始指南](QUICKSTART.md) 了解基本用法
2. 查看 [详细文档](README.md) 了解所有功能
3. 运行示例脚本：
   - Windows: `example.bat`
   - Linux/Mac: `bash example.sh`

## 📞 获取帮助

如果遇到问题：

1. 查看日志文件：`var/log/`
2. 启用调试模式查看详细错误信息
3. 检查 PHP 版本和扩展是否满足要求
4. 确认邮箱服务商的 SMTP 设置文档

## 🔒 安全建议

- ⚠️ 不要将包含密码的配置文件提交到版本控制系统
- ⚠️ 建议将 `app/code/GuoLaiRen/Smtp/etc/env.php` 添加到 `.gitignore`
- ⚠️ 生产环境使用环境变量存储敏感信息
- ⚠️ 定期更换邮箱密码

## 📄 许可证

MIT License

---

🎉 **安装完成，祝您使用愉快！**

如有问题，请查看 [README.md](README.md) 或提交 Issue。

