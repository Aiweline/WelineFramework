# Weline TwoFactorAuth 模块

## 📋 概述

完全自主开发的双因素身份验证（2FA）模块，提供企业级安全认证解决方案。

### ✨ 核心特性

- 🔒 **完全自主开发** - 纯PHP原生实现TOTP算法（RFC 6238），不依赖任何第三方库
- 🌐 **标准协议兼容** - 兼容Google Authenticator、Microsoft Authenticator等所有主流验证器应用
- 📱 **独立PWA应用** - 自带完整的验证器APP，可安装到手机桌面，支持离线使用
- 🔑 **备份码机制** - 提供一次性备份码，确保在紧急情况下仍可登录
- ⚡ **高性能安全** - 使用时间安全的字符串比较，支持时间窗口容错
- 🎨 **现代化UI** - 美观的用户界面，优秀的用户体验
- 📦 **导入导出** - 支持多种格式的备份导入导出，轻松迁移账户
- 📸 **二维码扫描** 🆕 - 支持截图上传和摄像头扫描，网页端也能轻松添加账户

## 🚀 快速开始

### 安装

1. 将模块放置到 `app/code/Weline/TwoFactorAuth/` 目录

2. 运行安装命令：
```bash
php bin/weline setup:upgrade
```

3. 访问后台管理页面：
```
http://your-domain/backend/TwoFactorAuth/Index
```

### 启用2FA

1. 在后台点击「立即启用」
2. 使用验证器应用扫描二维码（可使用我们自己的APP或任何标准TOTP应用）
3. 输入6位验证码完成绑定
4. **务必保存备份码！**

## 📱 验证器APP

### 使用自己的验证器

访问：`http://your-domain/twofa-app/`

#### 特点

- ✅ 完全Web实现，无需安装
- ✅ 支持PWA，可添加到手机桌面
- ✅ 离线工作
- ✅ 支持多账户管理
- ✅ 自动生成验证码
- ✅ 倒计时显示
- ✅ 一键复制验证码
- ✅ 📸 **截图扫描** 🆕 - 上传二维码截图自动识别（网页端专属）
- ✅ 📷 **摄像头扫描** 🆕 - 实时扫描二维码（支持的浏览器）
- ✅ 📝 **账户备注** 🆕 - 为每个账户添加个性化备注，方便识别
- ✅ ✏️ **扫描后可编辑** 🆕 - 扫描成功后可修改账户名、添加备注

#### 安装到手机

**iOS：**
1. 使用Safari打开验证器页面
2. 点击分享按钮
3. 选择「添加到主屏幕」

**Android：**
1. 使用Chrome打开验证器页面
2. 点击菜单
3. 选择「添加到主屏幕」或「安装应用」

### 使用第三方验证器

我们的2FA系统兼容所有标准TOTP验证器：

- Google Authenticator
- Microsoft Authenticator
- Authy
- 1Password
- LastPass Authenticator
- 等所有支持TOTP的应用

## 🔧 技术架构

### 模块结构

```
Weline/TwoFactorAuth/
├── Controller/
│   ├── Backend/           # 后台管理控制器
│   │   ├── Index.php      # 主页面
│   │   ├── Setup.php      # 设置页面
│   │   └── App.php        # APP跳转
│   └── Api/               # API接口
│       ├── Verify.php     # 验证接口
│       ├── Setup.php      # 设置接口
│       └── Import.php     # 导入导出接口 🆕
├── Helper/
│   ├── TwoFactorAuthHelper.php  # 核心TOTP算法实现
│   └── BackupImporter.php       # 备份导入导出助手
├── Model/
│   └── UserTwoFactor.php        # 数据模型
├── Service/
│   └── TwoFactorAuthService.php # 业务逻辑服务
├── Setup/
│   └── Install.php              # 安装脚本
├── view/
│   ├── templates/Backend/       # 后台视图模板
│   └── statics/twofa-app/       # PWA验证器应用
├── etc/backend/
│   └── menu.xml                 # 后台菜单配置
├── composer.json
├── register.php
├── README.md
├── USAGE_GUIDE.md                  # 使用指南
├── IMPORT_GUIDE.md                 # 导入指南
└── docs/
    ├── DEVELOPMENT_NOTES.md        # 开发笔记
    ├── QR_SCAN_GUIDE.md            # 二维码扫描指南 🆕
    └── EXPORT_FORMATS_GUIDE.md     # 导出格式指南 🆕
```

### 核心算法

#### TOTP实现

```php
// 1. 时间步数计算
$timeStep = floor(time() / 30);

// 2. HMAC-SHA1
$hash = hash_hmac('sha1', pack('N*', 0, $timeStep), $key, true);

// 3. 动态截断
$offset = ord($hash[19]) & 0x0F;
$value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

// 4. 生成6位验证码
$code = str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
```

## 📚 API文档

### 导入导出接口 🆕

#### 解析备份文件

```http
POST /api/2fa/parse
Content-Type: application/x-www-form-urlencoded

content=<备份内容>&format=auto
```

**响应：**
```json
{
    "success": true,
    "code": 200,
    "total": 5,
    "valid": 5,
    "accounts": [
        {
            "issuer": "GitHub",
            "account": "user@example.com",
            "secret": "JBSWY3DPEHPK3PXP",
            "digits": 6,
            "period": 30
        }
    ]
}
```

#### 导出账户

```http
POST /api/2fa/export
Content-Type: application/json

{
    "accounts": [...],
    "format": "json"
}
```

**响应：**
```json
{
    "success": true,
    "code": 200,
    "format": "json",
    "data": "[...]"
}
```

#### 获取支持的格式

```http
GET /api/2fa/formats
```

**响应：**
```json
{
    "success": true,
    "formats": [
        {
            "id": "json",
            "name": "JSON格式",
            "description": "标准JSON格式备份文件",
            "extensions": ["json"]
        }
    ]
}
```

### 验证接口

#### 验证验证码

```http
POST /api/2fa/verify
Content-Type: application/x-www-form-urlencoded

user_id=1&code=123456
```

**响应：**
```json
{
    "success": true,
    "code": 200,
    "verified": true,
    "message": "验证成功"
}
```

#### 检查用户是否启用2FA

```http
GET /api/2fa/check?user_id=1
```

**响应：**
```json
{
    "success": true,
    "code": 200,
    "is_enabled": true
}
```

### 设置接口

#### 初始化2FA

```http
POST /api/2fa/initialize
Content-Type: application/x-www-form-urlencoded

user_id=1&account=user@example.com
```

**响应：**
```json
{
    "success": true,
    "code": 200,
    "secret": "JBSWY3DPEHPK3PXP",
    "formatted_secret": "JBSW Y3DP EHPK 3PXP",
    "backup_codes": ["1234-5678", "8765-4321", ...],
    "qr_code_url": "https://...",
    "qr_code_uri": "otpauth://totp/..."
}
```

#### 启用2FA

```http
POST /api/2fa/enable
Content-Type: application/x-www-form-urlencoded

user_id=1&secret=JBSWY3DPEHPK3PXP&code=123456&backup_codes=[...]
```

#### 禁用2FA

```http
POST /api/2fa/disable
Content-Type: application/x-www-form-urlencoded

user_id=1&code=123456
```

## 🔐 安全最佳实践

### 密钥管理

- ✅ 密钥使用Base32编码存储
- ✅ 数据库存储加密
- ✅ 不在日志中记录密钥

### 验证码验证

- ✅ 使用时间安全的字符串比较（`hash_equals`）
- ✅ 支持时间窗口容错（±30秒）
- ✅ 每个验证码只能使用一次

### 备份码

- ✅ 生成10个备份码
- ✅ 每个备份码只能使用一次
- ✅ 使用后自动失效

## 🎯 使用场景

### 场景1：用户登录

```php
// 1. 用户输入用户名和密码
$isPasswordValid = authenticateUser($username, $password);

// 2. 检查是否启用2FA
$is2FAEnabled = $twoFactorAuthService->isEnabled($userId);

// 3. 如果启用，要求输入验证码
if ($is2FAEnabled) {
    $code = $_POST['2fa_code'];
    $isValid = $twoFactorAuthService->verify($userId, $code);
    
    if (!$isValid) {
        // 验证失败
        return "验证码错误";
    }
}

// 4. 登录成功
loginUser($userId);
```

### 场景2：API访问

```php
// 在API中间件中验证2FA
public function handle($request, $next)
{
    $userId = $request->user()->id;
    $code = $request->header('X-2FA-Code');
    
    if ($this->twoFactorAuthService->isEnabled($userId)) {
        if (!$code || !$this->twoFactorAuthService->verify($userId, $code)) {
            return response()->json(['error' => '需要2FA验证'], 401);
        }
    }
    
    return $next($request);
}
```

## 🧪 测试

### 单元测试

```bash
php vendor/bin/phpunit tests/Unit/TwoFactorAuthHelperTest.php
```

### 集成测试

```bash
php vendor/bin/phpunit tests/Integration/TwoFactorAuthServiceTest.php
```

## 🐛 故障排查

### 常见问题

#### 1. 验证码总是错误

**原因：** 服务器时间不同步

**解决：**
```bash
# 同步服务器时间
ntpdate time.windows.com
# 或
timedatectl set-ntp true
```

#### 2. 二维码无法显示

**原因：** 网络问题或Google Charts API不可用

**解决：** 使用手动输入密钥的方式

#### 3. PWA应用无法安装

**原因：** 需要HTTPS或localhost

**解决：** 确保使用HTTPS访问，或在localhost测试

#### 4. 数据库表未创建

**解决：**
```bash
php bin/weline setup:upgrade --force
```

## 📦 导入导出功能

### 支持的导入格式

1. **JSON格式** - 标准JSON格式，通用性强
2. **URI列表** - 每行一个otpauth://链接
3. **Aegis格式** - Aegis Authenticator的导出格式
4. **andOTP格式** - andOTP的JSON导出
5. **Weline备份** - 本应用的导出格式

详细说明请参考 [IMPORT_GUIDE.md](IMPORT_GUIDE.md)

### 支持的导出格式 🆕

可以导出到以下主流验证器格式，方便迁移：

1. **Weline格式** - 本应用标准格式（推荐备份）
2. **Aegis格式** - 可直接导入到Aegis Authenticator
3. **andOTP格式** - 可直接导入到andOTP
4. **2FAS格式** - 可直接导入到2FAS Authenticator
5. **URI列表** - 兼容所有支持手动添加的验证器

详细说明请参考 [导出格式指南](docs/EXPORT_FORMATS_GUIDE.md)

### 使用示例

#### PHP端导入

```php
use Weline\TwoFactorAuth\Helper\BackupImporter;

// 解析备份文件
$content = file_get_contents('backup.json');
$accounts = BackupImporter::parse($content, 'auto');

// 验证账户
foreach ($accounts as $account) {
    if (BackupImporter::validateAccount($account)) {
        // 导入账户
        $service->enable($userId, $account['secret'], $verifyCode);
    }
}

// 导出账户
$accounts = [
    ['issuer' => 'GitHub', 'account' => 'user', 'secret' => 'ABC...'],
    // ...
];
$json = BackupImporter::exportToJson($accounts);
file_put_contents('export.json', $json);
```

#### JavaScript端导入

```javascript
// 读取文件
const file = event.target.files[0];
const content = await file.text();

// 解析内容
const accounts = parseBackupContent(content);

// 批量导入
accounts.forEach(account => {
    app.addAccount(
        account.issuer,
        account.account,
        account.secret,
        account.digits,
        account.period
    );
});
```

#### 导出

```javascript
// 导出当前所有账户
const accounts = app.getAccounts();
const json = JSON.stringify(accounts, null, 2);
const blob = new Blob([json], { type: 'application/json' });
const url = URL.createObjectURL(blob);

// 触发下载
const a = document.createElement('a');
a.href = url;
a.download = `backup-${Date.now()}.json`;
a.click();
```

## 📖 扩展开发

### 自定义验证逻辑

```php
use Weline\TwoFactorAuth\Helper\TwoFactorAuthHelper;

// 生成自定义时间步长的验证码
$code = TwoFactorAuthHelper::generateCode($secret, 'SHA1', 8, 60, time());

// 验证码位数：8位
// 时间步长：60秒
```

### 集成到现有认证系统

```php
// 在登录控制器中
use Weline\TwoFactorAuth\Service\TwoFactorAuthService;

class LoginController
{
    private TwoFactorAuthService $twoFactorAuth;
    
    public function login($username, $password, $code = null)
    {
        $user = $this->authenticatePassword($username, $password);
        
        if ($this->twoFactorAuth->isEnabled($user->id)) {
            if (!$code) {
                return $this->require2FA();
            }
            
            if (!$this->twoFactorAuth->verify($user->id, $code)) {
                return $this->error('验证码错误');
            }
        }
        
        return $this->loginSuccess($user);
    }
}
```

## 🌟 兼容性

### 服务端要求

- PHP >= 8.0
- OpenSSL扩展
- JSON扩展

### 客户端要求（PWA应用）

- 支持ES6+的浏览器
- Web Crypto API支持
- LocalStorage支持
- Service Worker支持（可选，用于离线功能）

### 支持的平台

- ✅ iOS 12+
- ✅ Android 5+
- ✅ Chrome 67+
- ✅ Firefox 62+
- ✅ Safari 11.1+
- ✅ Edge 79+

## 📄 许可证

MIT License

## 👥 贡献

欢迎提交Issue和Pull Request！

## 📞 联系方式

- Email: aiweline@qq.com
- Website: https://aiweline.com

## 🎉 致谢

感谢所有为这个项目做出贡献的开发者！

---

**注意：** 这是一个完全自主开发的2FA解决方案，所有代码均为原创，不依赖任何第三方库。我们严格遵循RFC 6238标准，确保与所有主流验证器应用的兼容性。

