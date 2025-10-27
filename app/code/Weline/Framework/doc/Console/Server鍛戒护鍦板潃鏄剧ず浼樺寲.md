# 🚀 Server 命令地址显示优化

## 📋 修改内容

### 添加前端地址显示

在所有 `php bin/w server` 相关命令中添加了前端地址和前端API地址的显示。

---

## 🔧 修改的文件

### 1. **Console/Console/Server/Start.php**
启动服务器命令 - 添加前端地址显示

### 2. **Console/Console/Server/Status.php**
查看服务器状态命令 - 添加前端地址显示

---

## 📊 地址显示对比

### 修改前 ❌
```
启用PHP内置本地WebServer服务...
后端地址：http://localhost:8080/admin/admin/login
后端API地址：http://localhost:8080/api_admin/rest

局域网访问：
局域网地址：http://192.168.1.100:8080/admin/admin/login
局域网API地址：http://192.168.1.100:8080/api_admin/rest
```

### 修改后 ✅
```
启用PHP内置本地WebServer服务...
前端地址：http://localhost:8080/
前端API地址：http://localhost:8080/api/rest
后端地址：http://localhost:8080/admin/admin/login
后端API地址：http://localhost:8080/api_admin/rest

局域网访问：
局域网前端地址：http://192.168.1.100:8080/
局域网前端API地址：http://192.168.1.100:8080/api/rest
局域网后端地址：http://192.168.1.100:8080/admin/admin/login
局域网后端API地址：http://192.168.1.100:8080/api_admin/rest
```

---

## 🎯 地址说明

### 本地访问

| 类型 | 地址 | 说明 |
|------|------|------|
| 🏠 **前端** | `http://localhost:8080/` | 用户访问的主页面 |
| 🔌 **前端API** | `http://localhost:8080/api/rest` | 前端调用的REST API |
| 🔐 **后端** | `http://localhost:8080/admin/admin/login` | 管理员后台登录 |
| ⚙️ **后端API** | `http://localhost:8080/api_admin/rest` | 后台管理API |

### 局域网访问

| 类型 | 地址示例 | 说明 |
|------|----------|------|
| 🏠 **局域网前端** | `http://192.168.1.100:8080/` | 局域网内其他设备访问前端 |
| 🔌 **局域网前端API** | `http://192.168.1.100:8080/api/rest` | 局域网前端API |
| 🔐 **局域网后端** | `http://192.168.1.100:8080/admin/admin/login` | 局域网访问后台 |
| ⚙️ **局域网后端API** | `http://192.168.1.100:8080/api_admin/rest` | 局域网后台API |

---

## 💡 使用场景

### 1. **前端开发**
```bash
php bin/w server:start
# 访问前端地址：http://localhost:8080/
# 前端登录：http://localhost:8080/frontend/account/login
# TwoFactorAuth应用：http://localhost:8080/twofa
```

### 2. **前端API调试**
```javascript
// 前端JavaScript调用API
fetch('http://localhost:8080/api/rest/2fa/formats')
  .then(response => response.json())
  .then(data => console.log(data));
```

### 3. **后端管理**
```bash
# 访问后端管理界面
http://localhost:8080/admin/admin/login
```

### 4. **移动设备测试**
```bash
# 在同一局域网的手机/平板上测试
http://192.168.1.100:8080/
http://192.168.1.100:8080/twofa
```

---

## 🔍 命令输出示例

### `php bin/w server:start`
```
✔ 开发专用，请勿用于生产环境。
ℹ 启用PHP内置本地WebServer服务...
ℹ 前端地址：http://localhost:8080/
ℹ 前端API地址：http://localhost:8080/api/rest
ℹ 后端地址：http://localhost:8080/admin/admin/login
ℹ 后端API地址：http://localhost:8080/api_admin/rest
ℹ 局域网访问：
ℹ 局域网前端地址：http://192.168.1.100:8080/
ℹ 局域网前端API地址：http://192.168.1.100:8080/api/rest
ℹ 局域网后端地址：http://192.168.1.100:8080/admin/admin/login
ℹ 局域网后端API地址：http://192.168.1.100:8080/api_admin/rest
```

### `php bin/w server:status`
```
✔ 服务器正在运行
ℹ 主机：localhost
ℹ 端口：8080
ℹ 进程ID：12345
ℹ 运行时间：1小时23分钟45秒
ℹ 前端地址：http://localhost:8080/
ℹ 前端API地址：http://localhost:8080/api/rest
ℹ 后端地址：http://localhost:8080/admin/admin/login
ℹ 后端API地址：http://localhost:8080/api_admin/rest
```

---

## 📱 前端应用访问

### 用户中心系统
- 登录页：`http://localhost:8080/frontend/account/login`
- 注册页：`http://localhost:8080/frontend/account/register`
- 个人中心：`http://localhost:8080/frontend/account`

### TwoFactorAuth 应用
- 主页：`http://localhost:8080/twofa`
- API：`http://localhost:8080/api/2fa/parse`

---

## ✅ 优势

### 1. **信息完整** 📋
- 显示所有可访问的地址
- 前端和后端地址一目了然

### 2. **开发便利** 🛠️
- 快速找到需要的地址
- 支持多设备测试

### 3. **清晰分类** 📊
- 按功能分类显示
- 本地和局域网地址分开

### 4. **用户友好** 👥
- 完整的地址信息
- 减少查找时间

---

## 🎊 总结

现在启动服务器时会显示：
- ✅ **4个本地地址**（前端、前端API、后端、后端API）
- ✅ **4个局域网地址**（前端、前端API、后端、后端API）
- ✅ **清晰的分类和说明**
- ✅ **便于开发和测试**

完美支持前后端分离开发！🚀

---

**© 2025 Weline Framework. All rights reserved.**

*更新时间: 2025-01-26*

