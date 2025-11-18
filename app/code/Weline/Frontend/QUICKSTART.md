# 🚀 前端用户中心 - 快速入门

## 📦 已完成的功能

### ✅ 1. 用户系统
- ✨ **用户注册** (`/frontend/account/register`)
  - 实时表单验证
  - 密码强度检测
  - 自动登录
  
- 🔐 **用户登录** (`/frontend/account/login`)
  - 灵活的记住我功能（6小时、1天、1周、1个月）
  - 智能返回来源页面
  - 登录失败保护（最多5次）
  - 自动登录支持
  
- 👤 **个人中心** (`/frontend/account`)
  - 个人资料管理
  - 密码修改
  - 登录信息查看
  - 访问两步验证系统
  
- 🚪 **安全登出** (`/frontend/account/logout`)

### ✅ 2. TwoFactorAuth 集成
- 🛡️ **自动登录保护**: 访问 `/twofa` 需要先登录
- 🔄 **智能重定向**: 登录后自动返回原页面
- 🔌 **API保护**: `/api/2fa` 接口需要登录

### ✅ 3. 精美UI设计
- 🎨 现代渐变背景
- 📱 完全响应式设计
- ⚡ 流畅的交互动画
- 🎯 基于 Bootstrap 5

## 🎯 快速使用

### 第一步：访问登录页
```
浏览器打开: http://your-domain/frontend/account/login
```

### 第二步：注册账户
```
点击"立即注册" → 填写用户名和密码 → 注册
```

### 第三步：访问个人中心
```
登录成功后自动跳转到: http://your-domain/frontend/account
```

### 第四步：使用两步验证
```
点击侧边栏的"两步验证" → 自动跳转到 /twofa
```

## 🔑 默认账户

系统已创建一个测试账户：
```
用户名: 秋枫雁飞
密码: admin
```

**⚠️ 生产环境请立即修改密码！**

## 📂 文件结构

```
app/code/Weline/Frontend/
├── Controller/
│   └── Account/
│       ├── Index.php          # 个人中心
│       ├── Login.php          # 登录（支持灵活时长）
│       ├── Register.php       # 注册
│       └── Logout.php         # 登出
├── Model/
│   ├── FrontendUser.php       # 用户模型
│   └── FrontendUserToken.php # Token模型（记住我）
├── Observer/
│   └── AutoLogin.php          # 自动登录Observer
├── Session/
│   └── FrontendUserSession.php # 会话管理
├── view/
│   └── frontend/
│       └── account/
│           ├── login.phtml    # 登录页面（时长选择）
│           ├── register.phtml # 注册页面
│           └── index.phtml    # 个人中心页面
└── docs/
    ├── USER_CENTER_GUIDE.md   # 详细文档
    └── REMEMBER_ME_GUIDE.md   # 记住我功能文档

app/code/Weline/TwoFactorAuth/
├── Observer/
│   └── CheckUserLogin.php     # 登录检查Observer
└── etc/
    └── event.xml             # 事件配置
```

## 🔐 登录保护工作原理

```php
访问 /twofa 或 /api/2fa
    ↓
CheckUserLogin Observer 触发
    ↓
检查 FrontendUserSession::isLogin()
    ↓
未登录 → 重定向到 /frontend/account/login?referer=原URL
已登录 → 继续访问
```

## 💻 在代码中使用

### 检查登录状态
```php
use Weline\Frontend\Session\FrontendUserSession;

class YourController extends FrontendController
{
    private FrontendUserSession $session;
    
    public function getIndex()
    {
        if (!$this->session->isLogin()) {
            // 未登录
            $this->redirect('/frontend/account/login');
            return;
        }
        
        // 已登录
        $user = $this->session->getLoginUser();
        echo "欢迎, " . $user->getUsername();
    }
}
```

### 获取当前用户
```php
/** @var \Weline\Frontend\Model\FrontendUser $user */
$user = $this->session->getLoginUser();

echo $user->getId();          // 用户ID
echo $user->getUsername();    // 用户名
echo $user->getAvatar();      // 头像URL
echo $user->getLoginIp();     // 登录IP
```

## 🎨 自定义样式

所有样式都在视图文件的 `<style>` 标签中，可以直接修改：

```css
/* 修改主题色 */
:root {
    --primary-color: #your-color;
    --primary-dark: #your-dark-color;
}

/* 修改渐变背景 */
body {
    background: linear-gradient(135deg, #your-color1 0%, #your-color2 100%);
}
```

## 📡 API使用

### 登录 API
```javascript
// POST /frontend/account/login
{
    "username": "myusername",
    "password": "mypassword",
    "remember": 1  // 可选，记住我
}

// 响应
{
    "success": true,
    "message": "登录成功",
    "redirect": "/frontend/account"
}
```

### 注册 API
```javascript
// POST /frontend/account/register
{
    "username": "newuser",
    "password": "password123",
    "confirm_password": "password123"
}

// 响应
{
    "success": true,
    "message": "注册成功",
    "redirect": "/frontend/account"
}
```

### 更新个人资料 API
```javascript
// POST /frontend/account/update
{
    "avatar": "https://example.com/avatar.jpg",
    // 修改密码（可选）
    "old_password": "oldpass",
    "new_password": "newpass",
    "confirm_password": "newpass"
}

// 响应
{
    "success": true,
    "message": "更新成功"
}
```

## 🔧 常见问题

### Q1: 如何添加邮箱字段？
**A**: 在 `FrontendUser.php` 的 `install()` 方法中添加：
```php
->addColumn('email', TableInterface::column_type_VARCHAR, 255, '', '邮箱')
```

### Q2: 如何自定义重定向？
**A**: 在登录控制器的 `postIndex()` 方法中修改 `$redirectUrl`

### Q3: 如何禁用"记住我"功能？
**A**: 在 `login.phtml` 中删除相关checkbox，或在控制器中忽略该参数

## 🎉 功能演示

### 登录流程
```
1. 访问 /twofa（未登录）
   ↓
2. 自动跳转到 /frontend/account/login?referer=/twofa
   ↓
3. 输入用户名密码，点击登录
   ↓
4. 登录成功，自动返回 /twofa
   ↓
5. 正常使用两步验证功能
```

### 个人中心流程
```
1. 点击"个人资料"标签
   ↓
2. 修改头像URL
   ↓
3. 点击"保存更改"
   ↓
4. 显示成功提示

或

1. 点击"安全设置"标签
   ↓
2. 输入原密码和新密码
   ↓
3. 点击"修改密码"
   ↓
4. 密码修改成功
```

## 📊 数据库

### frontend_user 表结构
```sql
CREATE TABLE `frontend_user` (
  `user_id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(60) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `avatar` VARCHAR(255),
  `login_ip` VARCHAR(16),
  `sess_id` VARCHAR(32),
  `attempt_times` INT DEFAULT 0,
  `attempt_ip` VARCHAR(16)
);
```

## 🚀 下一步

1. **添加邮箱验证** - 注册时发送验证邮件
2. **找回密码功能** - 通过邮箱重置密码
3. **第三方登录** - OAuth集成（微信、QQ等）
4. **用户权限系统** - 角色和权限管理
5. **活动日志** - 记录用户操作历史

## 📚 更多文档

- [详细使用指南](docs/USER_CENTER_GUIDE.md)
- [记住我功能指南](docs/REMEMBER_ME_GUIDE.md) ⭐ 新增
- [TwoFactorAuth使用指南](../TwoFactorAuth/USAGE_GUIDE.md)

---

**🎊 恭喜！您已成功配置前端用户中心系统！**

如有问题，请查阅详细文档或联系技术支持。

