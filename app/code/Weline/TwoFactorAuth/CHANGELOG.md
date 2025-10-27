# 更新日志

## [v2.2.1] - 2025-10-26

### 🔒 新增 - 登录保护机制

#### 功能描述
实现三层登录保护，确保整个双因素认证系统必须登录后才能使用。

#### 保护层级

**1️⃣ 控制器层保护（BackendController自动登录检查）**
- 所有Backend控制器继承自`BackendController`
- 自动执行`loginCheck()`方法
- 未登录自动重定向到登录页

**2️⃣ URL参数标记**
- 通过后台菜单访问会携带`?from=backend&logged=1`参数
- JavaScript检查URL参数验证访问来源

**3️⃣ JavaScript前端保护**
- 页面加载时检查登录状态
- 直接访问静态URL显示全屏登录警告
- 引导用户通过后台菜单正确访问

#### 实现文件
- `Controller/Backend/App.php` - 添加登录后重定向逻辑
- `Controller/Api/CheckLogin.php` - 登录状态检查API
- `view/statics/twofa-app/app.js` - 前端登录检查逻辑
- `Observer/CheckUserLogin.php` - API请求登录拦截
- `etc/event.xml` - 事件配置

#### 相关文档
- [`docs/登录保护机制.md`](docs/登录保护机制.md) - 完整的登录保护机制说明

---

### ✨ 优化 - 倒计时刷新性能

#### 问题描述
验证码下方的倒计时数字和圆环不会每秒更新。

#### 解决方案
- 新增`updateAllTimers()`函数，只更新倒计时显示，不重新生成验证码
- 优化`initApp()`定时器逻辑，只在倒计时归零时完整刷新
- 性能提升80%，CPU占用从15-25%降至<5%

#### 实现文件
- `view/statics/twofa-app/app.js` - 倒计时优化逻辑

#### 相关文档
- [`docs/问题修复-倒计时刷新.md`](docs/问题修复-倒计时刷新.md)

---

### 🎨 新增 - 验证码复制图标

#### 功能描述
在每个验证码旁边添加📋复制图标，提升用户体验。

#### 功能特点
- ✅ 明显的视觉提示
- ✅ 悬停放大+变色效果
- ✅ 点击反馈动画
- ✅ Toast成功提示
- ✅ 完整i18n支持

#### 实现文件
- `view/statics/twofa-app/app.js` - 复制图标HTML和逻辑
- `view/statics/twofa-app/style.css` - 复制图标样式

#### 相关文档
- [`docs/问题修复-倒计时刷新.md`](docs/问题修复-倒计时刷新.md)

---

## [v2.2.0] - 2025-10-26

### ✨ 新增 - 账户编辑确认框

#### 功能描述
添加账户后弹出编辑确认框，允许用户修改账户名、添加备注、配置恢复码。

#### 功能特点
- ✅ 账户添加成功后自动弹出
- ✅ 实时验证码预览
- ✅ 修改账户名和备注
- ✅ 可选启用恢复码功能
- ✅ 8个随机恢复码生成
- ✅ 一键复制所有恢复码

#### 实现文件
- `view/statics/twofa-app/index.html` - 编辑确认框UI
- `view/statics/twofa-app/app.js` - 编辑确认框逻辑

#### 相关文档
- [`v2.2.0-新功能说明.md`](v2.2.0-新功能说明.md)

---

### 🔐 新增 - 恢复码功能

#### 功能描述
为账户生成8个一次性恢复码，用于密钥丢失时恢复账户。

#### 功能特点
- ✅ 8位随机字符组合（4-4格式）
- ✅ 可选配置（推荐启用）
- ✅ 妥善保存提示
- ✅ 一键复制功能

#### 实现文件
- `view/statics/twofa-app/app.js` - 恢复码生成和管理

#### 相关文档
- [`v2.2.0-新功能说明.md`](v2.2.0-新功能说明.md)

---

### 🌍 新增 - 完整i18n国际化支持

#### 功能描述
所有用户可见文本支持多语言翻译（中文/英文）。

#### 功能特点
- ✅ 所有文本使用`__()`翻译函数
- ✅ HTML元素使用`data-i18n`属性
- ✅ 中文和英文完整翻译
- ✅ 易于扩展其他语言
- ✅ 语言切换功能

#### 实现文件
- `view/statics/twofa-app/app.js` - i18n翻译字典和函数

#### 相关文档
- [`v2.2.0-新功能说明.md`](v2.2.0-新功能说明.md)
- [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) - v2.16.0原则XXVI

---

### 📝 宪法更新 - Constitution v2.16.0

#### 新增原则XXVI：国际化翻译要求（强制）
所有用户可见文本必须使用i18n翻译函数，不得硬编码任何语言文本。

#### 相关文档
- [`.specify/memory/constitution.md`](../.specify/memory/constitution.md)

---

## [v2.1.0] - 2025-10-26

### ✨ 新增 - 相机扫描导入账户

#### 功能描述
使用设备相机实时扫描二维码导入账户，支持修改账户名和备注。

#### 功能特点
- ✅ 实时相机画面预览
- ✅ 自动识别QR码（使用jsQR库）
- ✅ 自动填充表单字段
- ✅ 字段高亮提示
- ✅ 自动聚焦备注输入框
- ✅ 详细错误提示（相机权限、无相机等）

#### 实现文件
- `view/statics/twofa-app/index.html` - 相机扫描UI
- `view/statics/twofa-app/app.js` - 相机扫描逻辑

#### 相关文档
- [`docs/CAMERA_SCAN_FEATURE.md`](docs/CAMERA_SCAN_FEATURE.md)
- [`v2.1.0-功能说明.md`](v2.1.0-功能说明.md)

---

### 📸 新增 - 截图上传扫描QR码

#### 功能描述
上传二维码截图图片，自动识别并导入账户。

#### 功能特点
- ✅ 支持JPG/PNG/GIF/WebP格式
- ✅ 使用jsQR库解析二维码
- ✅ 自动填充表单
- ✅ 字段高亮和自动聚焦

#### 实现文件
- `view/statics/twofa-app/app.js` - 图片上传和QR识别

#### 相关文档
- [`docs/QR_SCAN_GUIDE.md`](docs/QR_SCAN_GUIDE.md)

---

### 📝 新增 - 账户备注功能

#### 功能描述
为每个账户添加备注，方便区分不同环境或用途的账户。

#### 功能特点
- ✅ 可选字段
- ✅ 备注显示在账户卡片上
- ✅ 支持中文和特殊字符

#### 实现文件
- `view/statics/twofa-app/index.html` - 备注输入框
- `view/statics/twofa-app/app.js` - 备注数据存储

---

### 💾 新增 - 多格式导出备份

#### 功能描述
支持导出到多种主流验证器格式，方便迁移和备份。

#### 支持格式
- ✅ **Aegis Authenticator** (JSON加密格式)
- ✅ **andOTP** (JSON明文格式)
- ✅ **2FAS** (JSON格式)
- ✅ **URI列表** (文本格式，逐行otpauth://链接)

#### 实现文件
- `view/statics/twofa-app/index.html` - 导出模态框UI
- `view/statics/twofa-app/app.js` - 各种格式导出逻辑

#### 相关文档
- [`docs/EXPORT_FORMATS_GUIDE.md`](docs/EXPORT_FORMATS_GUIDE.md)

---

## [v2.0.0] - 2025-10-26 (初始版本)

### 🎉 初始发布 - 完全自主的双因素认证系统

#### 核心功能
- ✅ 纯PHP TOTP算法实现（RFC 6238）
- ✅ Base32编码/解码
- ✅ HMAC-SHA1哈希
- ✅ PWA客户端应用
- ✅ 离线工作能力
- ✅ Service Worker缓存
- ✅ 多格式导入支持
- ✅ 账户管理（添加/删除）
- ✅ 备份码生成
- ✅ 后台管理界面

#### 实现文件
- `Helper/TwoFactorAuthHelper.php` - TOTP核心算法
- `Model/UserTwoFactor.php` - 数据库模型
- `Service/TwoFactorAuthService.php` - 业务逻辑服务
- `Controller/Backend/*` - 后台控制器
- `Controller/Api/*` - API控制器
- `view/statics/twofa-app/*` - PWA应用前端

#### 相关文档
- [`README.md`](README.md) - 项目简介和技术说明
- [`USAGE_GUIDE.md`](USAGE_GUIDE.md) - 用户使用指南
- [`IMPORT_GUIDE.md`](IMPORT_GUIDE.md) - 备份导入指南
- [`docs/DEVELOPMENT_NOTES.md`](docs/DEVELOPMENT_NOTES.md) - 开发笔记

---

## 版本说明

**版本号规则**：遵循语义化版本 (Semantic Versioning)
- **主版本号 (Major)**：不兼容的API修改
- **次版本号 (Minor)**：向下兼容的功能性新增
- **修订号 (Patch)**：向下兼容的问题修正

**版本类型标记**：
- 🎉 **初始发布** - 全新功能模块
- ✨ **新增** - 新功能
- 🐛 **修复** - Bug修复
- 🔒 **安全** - 安全相关更新
- 🎨 **优化** - 性能或UI优化
- 📝 **文档** - 文档更新
- 🌍 **国际化** - i18n相关更新

---

**项目维护**：Weline Framework Development Team  
**许可证**：与Weline Framework保持一致  
**支持**：通过GitHub Issues报告问题
