# WeShop 国际化电商 E2E 测试计划

> 制定日期：2026-03-28
> 目标：验证完整购物流程、用户中心、后台管理全部正常工作

---

## 一、测试环境准备

### 1.1 运行时要求
```bash
# 1. 启动开发服务器
php bin/w server:start

# 2. 验证服务器健康
curl -k https://localhost:3999/welive -H "Cookie: w_liveness=1" --resolve welive:3999:127.0.0.1
# 期望：返回 200 OK
```

### 1.2 测试账号（Fixture）
| 角色 | 邮箱 | 密码 | 用途 |
|------|------|------|------|
| 前台顾客 | test@example.com | Test@123456 | 购物、订单、收藏 |
| Google顾客 | google@example.com | - | Google登录 |
| 管理员 | admin@weline.com | Admin@123456 | 后台管理 |
| 2FA顾客 | 2fa@example.com | Test@123456 | 两步验证 |

### 1.3 测试数据准备
```bash
# 初始化测试商品数据
php bin/w weshop:fixture:products --count=50

# 初始化测试分类
php bin/w weshop:fixture:categories --count=10

# 初始化测试优惠券
php bin/w weshop:fixture:coupons --count=5
```

---

## 二、测试执行矩阵

### 2.1 前台 E2E 测试（Playwright）

#### 2.1.1 认证流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-AUTH-001 | 邮箱密码注册 | 注册→验证邮件→登录 | 注册成功，跳转登录 |
| FE-AUTH-002 | 邮箱密码登录 | 输入账号密码登录 | 登录成功，显示用户信息 |
| FE-AUTH-003 | Google登录 | 点击Google登录按钮 | 跳转Google授权→返回已登录 |
| FE-AUTH-004 | 2FA登录 | 账号+密码+验证码 | 登录成功，显示仪表盘 |
| FE-AUTH-005 | 忘记密码 | 填写邮箱→重置链接→设置新密码 | 密码重置成功 |
| FE-AUTH-006 | 退出登录 | 点击退出按钮 | 跳转首页，显示登录按钮 |

#### 2.1.2 商品浏览流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-CAT-001 | 分类列表 | 点击分类→浏览商品列表 | 显示商品列表，分页正常 |
| FE-CAT-002 | 商品详情 | 点击商品→查看详情 | 显示价格、库存、描述 |
| FE-CAT-003 | 商品筛选 | 选择品牌/价格/规格筛选 | 商品列表正确过滤 |
| FE-CAT-004 | 商品搜索 | 输入关键词搜索 | 显示搜索结果 |
| FE-CAT-005 | 商品排序 | 价格升/降序 | 排序正确应用 |

#### 2.1.3 购物车流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-CART-001 | 加入购物车 | 商品页点击加入购物车 | 成功提示，数量更新 |
| FE-CART-002 | 修改数量 | 购物车中修改商品数量 | 小计更新，总价重新计算 |
| FE-CART-003 | 删除商品 | 点击删除按钮 | 商品移除，更新总价 |
| FE-CART-004 | 清空购物车 | 点击清空购物车 | 所有商品移除 |
| FE-CART-005 | Mini购物车 | 悬浮显示Mini购物车 | 显示商品列表和快速结算 |

#### 2.1.4 结算流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-CHECK-001 | 结算页面 | 点击结算→进入结算页 | 显示商品、运费、税费、总价 |
| FE-CHECK-002 | 地址选择 | 选择已有地址/新增地址 | 地址信息正确显示 |
| FE-CHECK-003 | 配送方式 | 选择快递/自提/加急 | 运费正确计算 |
| FE-CHECK-004 | 支付方式 | 选择支付宝/微信/银行转账 | 支付方式正确显示 |
| FE-CHECK-005 | 使用优惠券 | 输入优惠券码 | 折扣正确应用，总价更新 |
| FE-CHECK-006 | 提交订单 | 确认信息→点击提交 | 订单创建成功，跳转支付 |

#### 2.1.5 支付流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-PAY-001 | 支付宝支付 | 选择支付宝→跳转扫码 | 显示支付宝二维码 |
| FE-PAY-002 | 微信支付 | 选择微信→跳转扫码 | 显示微信二维码 |
| FE-PAY-003 | 银行转账 | 选择转账→查看账号信息 | 显示银行账号和金额 |
| FE-PAY-004 | 支付回调 | 完成支付→等待回调 | 订单状态更新为已支付 |

#### 2.1.6 订单流程
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-ORDER-001 | 订单列表 | 进入个人中心→订单 | 显示所有订单 |
| FE-ORDER-002 | 订单详情 | 点击订单→查看详情 | 显示商品、地址、支付信息 |
| FE-ORDER-003 | 取消订单 | 点击取消→确认取消 | 订单取消成功 |
| FE-ORDER-004 | 再次购买 | 点击再次购买 | 商品加入购物车 |
| FE-ORDER-005 | 延长付款 | 点击延长付款 | 付款截止时间更新 |

#### 2.1.7 个人中心
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-ACCT-001 | 个人信息 | 查看/编辑个人信息 | 信息保存成功 |
| FE-ACCT-002 | 收货地址 | 添加/编辑/删除地址 | 地址管理正常 |
| FE-ACCT-003 | 收藏夹 | 添加/移除收藏商品 | 收藏列表正确更新 |
| FE-ACCT-004 | 浏览历史 | 查看浏览历史 | 显示最近浏览商品 |
| FE-ACCT-005 | 修改密码 | 输入旧密码→新密码 | 密码修改成功 |
| FE-ACCT-006 | 消息通知 | 查看系统消息 | 显示未读消息数量 |

#### 2.1.8 国际化测试
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| FE-I18N-001 | 语言切换-英文 | 切换到English | 所有界面显示英文 |
| FE-I18N-002 | 语言切换-中文 | 切换到简体中文 | 所有界面显示中文 |
| FE-I18N-003 | 货币切换-美元 | 切换到USD | 价格显示美元符号 |
| FE-I18N-004 | 货币切换-人民币 | 切换到CNY | 价格显示人民币符号 |
| FE-I18N-005 | 本地化格式 | 查看价格/日期/地址格式 | 符合当地习惯 |

---

### 2.2 后台 E2E 测试（Playwright）

#### 2.2.1 后台登录
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| BE-AUTH-001 | 后台登录 | 输入账号密码登录后台 | 登录成功，显示仪表盘 |
| BE-AUTH-002 | 权限验证 | 用顾客账号登录后台 | 拒绝访问 |
| BE-AUTH-003 | 2FA验证 | 后台2FA登录 | 验证成功进入后台 |

#### 2.2.2 商品管理
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| BE-PROD-001 | 商品列表 | 查看商品列表 | 显示所有商品，支持搜索 |
| BE-PROD-002 | 添加商品 | 填写信息→保存 | 商品创建成功 |
| BE-PROD-003 | 编辑商品 | 修改信息→保存 | 商品更新成功 |
| BE-PROD-004 | 删除商品 | 点击删除→确认 | 商品移入回收站 |
| BE-PROD-005 | 库存管理 | 修改库存数量 | 库存更新 |

#### 2.2.3 订单管理
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| BE-ORDER-001 | 订单列表 | 查看所有订单 | 显示订单列表和状态 |
| BE-ORDER-002 | 订单详情 | 查看订单详情 | 显示完整订单信息 |
| BE-ORDER-003 | 订单状态 | 修改订单状态 | 状态更新，通知顾客 |
| BE-ORDER-004 | 发货操作 | 填写运单号→发货 | 订单发货，客户收到通知 |
| BE-ORDER-005 | 退款处理 | 同意退款→退款 | 退款完成，订单状态更新 |

#### 2.2.4 客户管理
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| BE-CUST-001 | 客户列表 | 查看客户列表 | 显示所有注册客户 |
| BE-CUST-002 | 客户详情 | 查看客户信息 | 显示详细资料和订单 |
| BE-CUST-003 | 禁用账户 | 禁用客户账号 | 客户无法登录 |

#### 2.2.5 促销管理
| 测试ID | 测试名称 | 步骤 | 期望结果 |
|--------|----------|------|----------|
| BE-PROMO-001 | 优惠券列表 | 查看优惠券 | 显示所有优惠券 |
| BE-PROMO-002 | 创建优惠券 | 填写规则→保存 | 优惠券创建成功 |
| BE-PROMO-003 | 编辑优惠券 | 修改规则→保存 | 规则更新 |
| BE-PROMO-004 | 活动管理 | 创建满减/折扣活动 | 活动创建成功 |

---

### 2.3 API 测试（http:req）

#### 2.3.1 认证API
```bash
# 注册
php bin/w http:request rest/v1/weshop/auth/register -api -X POST -d '{"email":"test@test.com","password":"Test@123456"}'

# 登录
php bin/w http:request rest/v1/weshop/auth/login -api -X POST -d '{"username":"test@test.com","password":"Test@123456"}'

# 获取用户信息
php bin/w http:request rest/v1/weshop/auth/me -api -H "Authorization: Bearer {token}"

# 登出
php bin/w http:request rest/v1/weshop/auth/logout -api -X POST -H "Authorization: Bearer {token}"
```

#### 2.3.2 购物车API
```bash
# 添加到购物车
php bin/w http:request rest/v1/weshop/cart/add -api -X POST -d '{"product_id":1,"qty":2}'

# 获取购物车
php bin/w http:request rest/v1/weshop/cart/items -api

# 更新数量
php bin/w http:request rest/v1/weshop/cart/update -api -X POST -d '{"cart_id":1,"qty":3}'

# 删除商品
php bin/w http:request rest/v1/weshop/cart/remove -api -X POST -d '{"cart_id":1}'
```

#### 2.3.3 结算API
```bash
# 获取结算信息
php bin/w http:request rest/v1/weshop/checkout/methods -api

# 创建订单
php bin/w http:request rest/v1/weshop/checkout/place -api -X POST -d '{"address_id":1,"shipping_method":"express"}'
```

#### 2.3.4 支付API
```bash
# 获取支付方式
php bin/w http:request rest/v1/weshop/payment/methods -api

# 发起支付
php bin/w http:request rest/v1/weshop/payment/process -api -X POST -d '{"order_id":1,"method":"alipay"}'

# 支付回调
php bin/w http:request rest/v1/weshop/payment/callback/alipay -api -X POST
```

#### 2.3.5 订单API
```bash
# 订单列表
php bin/w http:request rest/v1/weshop/order/list -api

# 订单详情
php bin/w http:request rest/v1/weshop/order/1 -api

# 取消订单
php bin/w http:request rest/v1/weshop/order/1/cancel -api -X POST
```

---

## 三、测试执行命令

### 3.1 前台 E2E 测试
```bash
cd tests/e2e

# 运行所有前台E2E测试
npx playwright test specs/frontend/

# 运行认证测试
npx playwright test specs/frontend/auth.spec.js

# 运行购物流程测试
npx playwright test specs/frontend/cart.spec.js specs/frontend/checkout.spec.js

# 运行国际化测试
npx playwright test specs/frontend/i18n.spec.js

# UI模式调试
npx playwright test --ui
```

### 3.2 后台 E2E 测试
```bash
cd tests/e2e

# 运行所有后台E2E测试
npx playwright test specs/backend/

# 运行后台登录测试
npx playwright test specs/backend/login.spec.js

# 运行订单管理测试
npx playwright test specs/backend/order.spec.js
```

### 3.3 PHPUnit 单元测试
```bash
# 运行核心模块测试
php bin/w phpunit:run --module=WeShop_Auth
php bin/w phpunit:run --module=WeShop_Cart
php bin/w phpunit:run --module=WeShop_Checkout
php bin/w phpunit:run --module=WeShop_Payment
php bin/w phpunit:run --module=WeShop_Order

# 运行所有WeShop模块测试
php bin/w phpunit:run --module=WeShop
```

### 3.4 http:req API 测试
```bash
# 测试前台路由
php bin/w http:request /
php bin/w http:request /cart
php bin/w http:request /checkout

# 测试API路由
php bin/w http:request rest/v1/weshop/auth/login -api

# 测试后台路由
php bin/w http:request admin -b

# 检测PHP错误
php bin/w http:request / -b --filter=Warning,Fatal
```

---

## 四、测试通过标准

### 4.1 E2E 测试
- 前台测试成功率 ≥ 95%
- 后台测试成功率 ≥ 95%
- 国际化切换测试 100% 通过

### 4.2 单元测试
- 所有核心模块测试通过率 100%
- 覆盖率要求：Service层 ≥ 80%

### 4.3 API 测试
- 所有API端点返回正确状态码
- 响应格式符合预期

### 4.4 性能要求
- 页面加载时间 < 3秒
- API响应时间 < 1秒

---

## 五、测试报告

### 5.1 报告输出
```
tests/e2e/results/
├── html/
│   └── index.html          # Playwright HTML报告
├── json/
│   └── results.json         # 详细JSON结果
└── screenshots/            # 失败截图
```

### 5.2 每日报告
测试完成后自动生成：
- 通过率统计
- 失败用例列表
- 性能数据
- 建议改进项

---

## 六、执行检查清单

### 开始测试前
- [ ] 开发服务器运行中（php bin/w server:start）
- [ ] 测试数据库已初始化
- [ ] 测试账号已创建
- [ ] 测试商品数据已导入

### 测试执行中
- [ ] 按优先级顺序执行
- [ ] 记录失败用例
- [ ] 截图记录失败场景
- [ ] 记录性能数据

### 测试完成后
- [ ] 生成测试报告
- [ ] 分析失败原因
- [ ] 制定修复计划
- [ ] 更新测试文档

---

## 七、附录

### 7.1 常见问题处理

| 问题 | 解决方案 |
|------|----------|
| 数据库连接失败 | 重启服务器，检查连接池配置 |
| 支付回调失败 | 检查回调地址是否可达 |
| 登录失败 | 检查Session配置 |
| 图片加载失败 | 检查静态资源路径 |

### 7.2 测试数据清理
```bash
# 清理测试订单
php bin/w weshop:fixture:cleanup --orders

# 清理测试客户
php bin/w weshop:fixture:cleanup --customers

# 重置所有测试数据
php bin/w weshop:fixture:reset
```
