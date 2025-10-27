# 前端用户中心使用指南

## 📋 概述

前端用户中心是一个精美、现代化的用户管理系统，提供了完整的用户注册、登录、个人资料管理等功能。

## ✨ 主要功能

### 1. 用户注册
- **路径**: `/frontend/account/register`
- **功能**:
  - 用户名验证（3-20个字符）
  - 密码强度检测
  - 实时表单验证
  - 注册成功后自动登录

### 2. 用户登录
- **路径**: `/frontend/account/login`
- **功能**:
  - 支持"记住我"功能（30天有效期）
  - 自动返回来源页面
  - 登录失败次数限制（最多5次）
  - 精美的渐变背景设计

### 3. 个人中心
- **路径**: `/frontend/account`
- **功能**:
  - 查看和编辑个人资料
  - 修改密码
  - 查看登录信息
  - 访问两步验证系统
  - 安全登出

### 4. 登出
- **路径**: `/frontend/account/logout`
- **功能**: 清除会话和Cookie，安全退出

## 🔐 登录保护机制

### TwoFactorAuth 集成

TwoFactorAuth 应用已经与用户中心完全集成：

1. **自动登录检查**: 访问 `/twofa` 或 `/api/2fa` 时会自动检查登录状态
2. **智能重定向**: 未登录用户会被重定向到登录页，登录成功后自动返回
3. **API保护**: API请求会返回401状态码和JSON错误信息

### 实现原理

通过 `CheckUserLogin` Observer 在控制器初始化前检查用户登录状态：

```php
// 监听事件
Framework_FrontendController::init_before
Framework_FrontendRestController::init_before
```

## 🎨 界面设计

### 设计特点
- **现代渐变背景**: 紫色渐变（#667eea → #764ba2）
- **卡片式布局**: 圆角卡片，柔和阴影
- **响应式设计**: 完美适配桌面和移动设备
- **流畅动画**: 按钮悬停、表单验证等交互动画
- **Bootstrap 5**: 基于最新的Bootstrap 5框架

### 颜色系统
```css
--primary-color: #5156be
--primary-dark: #3f44a8
--success-color: #34c38f
--danger-color: #f46a6a
```

## 🚀 使用示例

### 1. 在视图中添加登录链接

```html
<?php if ($this->session->isLogin()): ?>
    <a href="/frontend/account">个人中心</a>
    <a href="/frontend/account/logout">退出</a>
<?php else: ?>
    <a href="/frontend/account/login">登录</a>
    <a href="/frontend/account/register">注册</a>
<?php endif; ?>
```

### 2. 在控制器中检查登录状态

```php
use Weline\Frontend\Session\FrontendUserSession;

class MyController extends FrontendController
{
    private FrontendUserSession $session;
    
    public function getIndex()
    {
        if (!$this->session->isLogin()) {
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $this->redirect('/frontend/account/login?referer=' . urlencode($currentUrl));
            return;
        }
        
        $user = $this->session->getLoginUser();
        // 业务逻辑...
    }
}
```

### 3. AJAX登录

```javascript
$.ajax({
    url: '/frontend/account/login',
    method: 'POST',
    data: {
        username: 'myusername',
        password: 'mypassword',
        remember: 1
    },
    success: function(response) {
        if (response.success) {
            window.location.href = response.redirect;
        } else {
            alert(response.message);
        }
    }
});
```

## 📊 数据模型

### FrontendUser 字段

| 字段 | 类型 | 说明 |
|------|------|------|
| user_id | INT | 用户ID（主键） |
| username | VARCHAR(60) | 用户名 |
| password | VARCHAR(255) | 密码（加密） |
| avatar | VARCHAR(255) | 头像URL |
| login_ip | VARCHAR(16) | 最后登录IP |
| sess_id | VARCHAR(32) | Session ID |
| attempt_times | INT | 登录尝试次数 |
| attempt_ip | VARCHAR(16) | 尝试登录IP |

## 🔒 安全特性

### 1. 密码加密
使用 PHP 的 `password_hash()` 和 `password_verify()` 进行密码加密和验证。

### 2. 登录保护
- 最多允许5次登录失败
- 记录登录IP和尝试IP
- Session机制防止CSRF

### 3. XSS防护
所有用户输入都经过 `htmlspecialchars()` 处理。

### 4. 来源验证
只允许站内跳转，防止开放重定向攻击。

## 📱 移动端支持

所有页面都完全响应式设计，自动适配：
- 手机（< 576px）
- 平板（576px - 768px）
- 桌面（> 768px）

## 🎯 最佳实践

### 1. 自定义头像
建议使用头像服务，如：
- Gravatar
- 本地上传系统
- CDN存储

### 2. 扩展用户字段
在 FrontendUser 模型中添加字段：

```php
public function install(ModelSetup $setup, Context $context): void
{
    $setup->addColumn('email', TableInterface::column_type_VARCHAR, 255, '', '邮箱');
    $setup->addColumn('phone', TableInterface::column_type_VARCHAR, 20, '', '手机号');
}
```

### 3. 邮件验证
可以添加邮件验证功能：
- 注册时发送验证邮件
- 修改密码时需要验证
- 重要操作需要二次确认

## 🔗 相关链接

- **TwoFactorAuth**: `/twofa` - 两步验证应用
- **API文档**: `/api/2fa` - 两步验证API

## 📝 更新日志

### v1.0.0 (2025-01-26)
- ✅ 用户注册功能
- ✅ 用户登录功能（支持记住我）
- ✅ 个人中心
- ✅ 密码修改
- ✅ 智能重定向（记住来源页面）
- ✅ TwoFactorAuth登录保护
- ✅ 精美的UI设计

## 💡 提示

1. **首次使用**: 系统会自动创建一个测试账户（用户名：秋枫雁飞，密码：admin）
2. **修改样式**: 所有样式都内联在模板文件中，便于自定义
3. **扩展功能**: 可以轻松添加邮箱、手机号等字段
4. **API支持**: 控制器支持JSON响应，适合前后端分离

## 🐛 故障排查

### 问题1: 登录后没有跳转
**解决**: 检查session是否正常工作，确保 `FrontendUserSession` 已正确配置。

### 问题2: 样式不显示
**解决**: 确保Bootstrap 5静态文件存在于 `/static/Weline_Frontend/libs/bootstrap-5.1.3-dist/`

### 问题3: AJAX请求失败
**解决**: 检查浏览器控制台，确保路由配置正确。

---

**© 2025 Weline Framework. All rights reserved.**

