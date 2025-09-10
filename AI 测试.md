# AI 测试指南

## 概述

本文档记录了在WelineFramework开发过程中的测试心得和最佳实践，帮助AI在开发过程中进行有效的验证和测试。

## 测试环境信息

- **服务器地址**: `http://127.0.0.1:9981`
- **后台管理密钥**: `Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ`
- **API管理密钥**: `7r1XLapP8oNBJc6grWtUlUqA42e6GZWQ`
- **默认登录账号**: `admin/admin`

## 测试目录结构

- **`AI 测试.md`**: 根目录下的测试指南文档
- **`dev/ai/`**: 测试资料存储目录
  - `cookies.txt`: 登录session文件
  - `test-route.sh`: 路由测试脚本
  - `test-data/`: 测试数据目录

## 路由测试最佳实践

### 1. 路由组成规则

**正确的路由格式**:
```
http://127.0.0.1:9981/{admin_key}/{module_router}/{controller_path}/{action}
```

**示例**:
```bash
# 订单管理
http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/order/index

# 店铺管理  
http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/shop/index

# 飞书配置
http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/config/feishu
```

### 2. Session管理测试

**重要**: 测试路由时必须保持session状态，否则会一直重定向到登录页面。

**正确的测试步骤**:
```bash
# 1. 获取登录页面并保存cookies
curl -c cookies.txt -s "http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/admin/login" > /dev/null

# 2. 登录并保持session
curl -b cookies.txt -c cookies.txt -X POST "http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/admin/login/post" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "username=admin&password=admin&form_key=test" \
  -L

# 3. 使用session测试路由
curl -b cookies.txt -I "http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/order/index"
```

### 3. 状态码判断

- **200 OK**: 路由正常，页面可以访问
- **302 Found**: 重定向（通常是未登录或权限问题）
- **404 Not Found**: 路由不存在或配置错误
- **500 Internal Server Error**: 服务器内部错误

## 常见错误及修复

### 错误1: 菜单配置路径错误

**问题**: 菜单配置中使用错误的路径格式
```xml
<!-- ❌ 错误 -->
<add action="admin/shopify/order/index" />

<!-- ✅ 正确 -->
<add action="shopify/backend/order/index" />
```

**修复步骤**:
1. 检查 `etc/backend/menu.xml` 文件
2. 确保 `action` 属性使用正确的路由格式
3. 运行 `php bin/w setup:upgrade --route` 更新路由缓存

### 错误2: 路由访问地址错误

**问题**: 在URL中包含多余的路径
```bash
# ❌ 错误
http://127.0.0.1:9981/{admin_key}/admin/shopify/backend/order/index

# ✅ 正确  
http://127.0.0.1:9981/{admin_key}/shopify/backend/order/index
```

### 错误3: 忘记Session管理

**问题**: 测试时没有保持session状态
```bash
# ❌ 错误 - 每次都是新请求，会重定向到登录页
curl -I "http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/order/index"

# ✅ 正确 - 保持session状态
curl -b cookies.txt -I "http://127.0.0.1:9981/Be7KAJpVt3bwMMejUZa7VbevRM5GVxjQ/shopify/backend/order/index"
```

## 开发测试流程

### 1. 模块开发完成后

```bash
# 1. 安装模块
php bin/w setup:upgrade --module ModuleName

# 2. 生成路由
php bin/w setup:upgrade --route

# 3. 启动服务器
php bin/w server:start

# 4. 测试路由访问
# (使用上述session管理方法)
```

### 2. 验证清单

- [ ] 模块是否正确注册到系统
- [ ] 路由是否正确生成
- [ ] 菜单配置是否正确
- [ ] 控制器是否能正常访问
- [ ] 权限控制是否正常
- [ ] 数据库表是否正确创建

## 测试数据

### FlashForge/ShopifyOrderManager 模块测试结果

**模块状态**: ✅ 已成功安装并运行

**路由测试结果**:
- 订单管理: ✅ 200 OK
- 店铺管理: ✅ 200 OK  
- 飞书配置: ✅ 200 OK

**数据库表**:
- shopify_shops: ✅ 已创建
- shopify_orders: ✅ 已创建
- shopify_order_items: ✅ 已创建
- shopify_feishu_config: ✅ 已创建

## 学以致用原则

1. **学习后立即验证**: 学习新知识后要立即运用去检查和修复问题
2. **系统性检查**: 发现一个问题后要检查所有相关的配置文件
3. **完整测试流程**: 从安装到访问的完整流程都要验证
4. **记录测试结果**: 将测试心得和结果记录下来，方便后续参考

## 更新日志

### 2025-01-10
- 创建AI测试指南
- 记录路由测试最佳实践
- 记录Session管理方法
- 记录常见错误及修复方法
- 记录FlashForge/ShopifyOrderManager模块测试结果
