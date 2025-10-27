# 🔐 "记住我"功能使用指南

## 📋 概述

全新的"记住我"功能允许用户在登录时选择不同的自动登录时长，提供更灵活的用户体验。

## ✨ 功能特点

### 多种时长选择
用户可以在登录时选择以下自动登录时长：
- ⏱️ **本次会话**：关闭浏览器后失效（默认Session）
- ⏰ **6小时**：适合短时间使用
- 📅 **1天**：适合日常使用
- 📆 **1周**：默认选项，推荐使用
- 📊 **1个月**：适合频繁使用

### 安全特性
- ✅ Token存储在数据库中，可追溯
- ✅ 每次登录生成新的随机Token
- ✅ Token自动过期机制
- ✅ 登出时自动清除Token
- ✅ 记录最后使用时间

## 🎨 用户界面

### 登录页面
```
┌─────────────────────────────────┐
│  用户名: [__________]           │
│  密码:   [__________]           │
│                                 │
│  记住登录状态:                   │
│  [▼ 1周                  ↓]    │
│  - 本次会话（关闭浏览器后失效）   │
│  - 6小时                        │
│  - 1天                          │
│  - 1周 ✓                        │
│  - 1个月                        │
│                                 │
│  [      登 录      ]           │
└─────────────────────────────────┘
```

## 🔧 技术实现

### 1. 数据库表结构

**frontend_user_token** 表：
```sql
CREATE TABLE `frontend_user_token` (
  `token_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `type` VARCHAR(32) NOT NULL,
  `token_expire_time` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` TIMESTAMP NULL,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_token` (`token`),
  INDEX `idx_expire` (`token_expire_time`)
);
```

### 2. 工作流程

#### 登录流程
```
用户输入账号密码 + 选择时长（如：1周）
    ↓
验证账号密码
    ↓
生成64位随机Token
    ↓
保存到数据库（user_id, token, expire_time）
    ↓
设置Cookie（frontend_user_token = token）
    ↓
登录成功
```

#### 自动登录流程
```
用户访问网站（未登录状态）
    ↓
AutoLogin Observer 触发
    ↓
读取Cookie中的Token
    ↓
查询数据库验证Token
    ↓
检查Token是否过期
    ↓
验证通过 → 自动登录
验证失败 → 清除Cookie
```

#### 登出流程
```
用户点击登出
    ↓
清除Session
    ↓
删除数据库中的Token记录
    ↓
清除Cookie
    ↓
跳转到登录页
```

### 3. 核心代码

#### 登录控制器
```php
// 获取用户选择的时长（秒）
$rememberDuration = (int)$this->request->getPost('remember_duration', 0);

if ($rememberDuration > 0) {
    // 生成Token
    $token = FrontendUserToken::generateToken();
    $expireTime = time() + $rememberDuration;
    
    // 保存到数据库
    $userToken = ObjectManager::getInstance(FrontendUserToken::class);
    $userToken->setUserId($user->getId())
        ->setToken($token)
        ->setType('remember_me')
        ->setTokenExpireTime($expireTime)
        ->save();
    
    // 设置Cookie
    Cookie::set('frontend_user_token', $token, $rememberDuration, ['path' => '/']);
}
```

#### 自动登录Observer
```php
public function execute(array &$data = []): void
{
    // 如果已登录，跳过
    if ($this->session->isLogin()) {
        return;
    }

    // 获取Token
    $token = Cookie::get('frontend_user_token');
    if (empty($token)) {
        return;
    }

    // 验证Token
    $userToken = ObjectManager::getInstance(FrontendUserToken::class);
    $userToken->where('token', $token)
        ->where('type', 'remember_me')
        ->find()
        ->fetch();

    // 检查是否过期
    if (!$userToken->getId() || $userToken->isExpired()) {
        $this->clearTokenCookie();
        return;
    }

    // 执行自动登录
    $user = ObjectManager::getInstance(FrontendUser::class);
    $user->load($userToken->getUserId());
    $this->session->login($user);
    
    // 更新最后使用时间
    $userToken->updateLastUsedAt()->save();
}
```

## 📊 时长配置

| 选项 | 秒数 | 适用场景 |
|------|------|----------|
| 本次会话 | 0 | 公共电脑，临时使用 |
| 6小时 | 21600 | 短时间内频繁访问 |
| 1天 | 86400 | 每天使用一次 |
| 1周 | 604800 | 定期使用（推荐） |
| 1个月 | 2592000 | 高频用户 |

## 🔒 安全建议

### 对于用户
1. ✅ 在自己的设备上可以选择"1周"或"1个月"
2. ⚠️ 在公共电脑上请选择"本次会话"
3. 🔐 定期修改密码
4. 🚪 离开时记得登出

### 对于开发者
1. ✅ 定期清理过期Token
2. ✅ 记录Token使用日志
3. ✅ 实现Token刷新机制
4. ✅ 添加异常登录检测

## 🛠️ 自定义配置

### 添加新的时长选项

修改 `login.phtml` 文件：
```html
<select class="form-select" id="remember_duration" name="remember_duration">
    <option value="0">本次会话</option>
    <option value="21600">6小时</option>
    <option value="86400">1天</option>
    <option value="604800">1周</option>
    <option value="2592000">1个月</option>
    <!-- 添加新选项 -->
    <option value="7776000">3个月</option>
    <option value="31536000">1年</option>
</select>
```

### 自定义默认时长

修改默认选中的选项：
```html
<option value="86400" selected>1天</option>  <!-- 改为默认选中1天 -->
```

### 修改Token长度

在 `FrontendUserToken.php` 中修改：
```php
public static function generateToken(): string
{
    // 默认32字节（64位十六进制）
    return bin2hex(random_bytes(32));
    
    // 改为64字节（128位十六进制）
    // return bin2hex(random_bytes(64));
}
```

## 📈 管理功能

### 清理过期Token

可以创建定时任务定期清理：
```php
use Weline\Frontend\Model\FrontendUserToken;

$userToken = ObjectManager::getInstance(FrontendUserToken::class);
$deletedCount = $userToken->cleanExpiredTokens();
echo "已清理 {$deletedCount} 个过期Token";
```

### 查看用户的Token

```php
$userToken = ObjectManager::getInstance(FrontendUserToken::class);
$tokens = $userToken->builder()
    ->where('user_id', $userId)
    ->where('type', 'remember_me')
    ->select();

foreach ($tokens as $token) {
    echo "Token: " . $token['token'] . "\n";
    echo "过期时间: " . date('Y-m-d H:i:s', $token['token_expire_time']) . "\n";
    echo "最后使用: " . $token['last_used_at'] . "\n";
}
```

### 强制登出用户

```php
// 删除指定用户的所有Token
$userToken = ObjectManager::getInstance(FrontendUserToken::class);
$userToken->builder()
    ->where('user_id', $userId)
    ->delete();
```

## 🎯 使用场景

### 场景1：办公室电脑
**推荐**：选择"1周"或"1个月"
- 每天都会使用
- 设备安全可控
- 方便快捷

### 场景2：家用电脑
**推荐**：选择"1周"
- 定期使用
- 平衡安全与便利

### 场景3：公共电脑
**推荐**：选择"本次会话"
- 用完即走
- 不留痕迹
- 最安全

### 场景4：移动设备
**推荐**：选择"1天"或"1周"
- 频繁访问
- 设备通常在身边
- 注意设备锁屏

## 🔍 故障排查

### Q1: 自动登录不生效
**检查项**：
1. Cookie是否被禁用？
2. Token是否过期？
3. 数据库中是否有Token记录？
4. Observer是否正确注册？

**解决方法**：
```php
// 查看Cookie
echo Cookie::get('frontend_user_token');

// 查看Token记录
$token = Cookie::get('frontend_user_token');
$userToken = ObjectManager::getInstance(FrontendUserToken::class);
$userToken->where('token', $token)->find()->fetch();
var_dump($userToken->getData());
```

### Q2: Token无法清除
**可能原因**：
- Cookie路径设置不正确
- 浏览器缓存问题

**解决方法**：
```php
// 确保路径一致
Cookie::set('frontend_user_token', '', -3600, ['path' => '/']);
```

### Q3: 频繁要求重新登录
**可能原因**：
- Token过期时间设置太短
- 系统时间不同步

**解决方法**：
- 检查服务器时间
- 增加Token有效期

## 📚 相关文档

- [用户中心使用指南](USER_CENTER_GUIDE.md)
- [快速入门](../QUICKSTART.md)
- [安全最佳实践](SECURITY_GUIDE.md)

## 🎊 总结

### 已实现的功能
✅ 5种时长选择（会话、6小时、1天、1周、1个月）  
✅ 安全的Token存储机制  
✅ 自动登录功能  
✅ Token过期自动清理  
✅ 登出时清除Token  
✅ 最后使用时间追踪  

### 技术亮点
🌟 **安全可靠**：Token随机生成，数据库存储  
🌟 **灵活配置**：多种时长选择，满足不同需求  
🌟 **自动化**：Observer自动处理登录逻辑  
🌟 **易于扩展**：可轻松添加新的时长选项  

---

**© 2025 Weline Framework. All rights reserved.**

