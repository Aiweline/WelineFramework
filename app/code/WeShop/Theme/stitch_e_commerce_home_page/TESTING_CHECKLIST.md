# WeShop 控制器测试验证清单

## 文档概述

本文档提供了所有已开发控制器的测试验证清单，确保每个控制器都能正确加载布局并传递数据。

## 测试前准备

### 1. 路由注册

运行以下命令注册所有模块的路由：

```bash
php bin/w module:upgrade
```

### 2. 主题配置

确保主题配置正确，布局变体已设置。检查主题配置中的 `layouts` 部分：

```json
{
  "layouts": {
    "homepage": "e_commerce_home_page_1",
    "product_list": "product_listing_page_1",
    "product": "product_detail_page_1",
    "cart": "shopping_cart_page_1",
    "checkout": "checkout_page_1",
    "checkout_success": "order_confirmation_page_1",
    "account_auth": "login_page_1",
    "account": "account_page_1",
    "cms": "cms_page_1",
    "customer_service": "customer_service_page_1",
    "promotion": "promotion_page_1",
    "qa": "qa_page_1",
    "review": "review_page_1",
    "rma": "rma_page_1"
  }
}
```

### 3. 数据库准备

确保以下表已创建并包含测试数据：
- `weshop_customer` - 客户表
- `weshop_product` - 产品表
- `weshop_catalog_category` - 分类表
- `weshop_cart` - 购物车表
- `weshop_order` - 订单表
- `weshop_cms_page` - CMS页面表

## 控制器测试清单

### 一、首页控制器

**控制器**: `WeShop\Frontend\Controller\Index`
**URL**: `/weshop/` 或 `/weshop/index`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载（检查HTML结构）
- [ ] 分类列表数据正确传递（`categories` 变量）
- [ ] 推荐商品数据正确传递（`recommended_products` 变量）
- [ ] 特价商品数据正确传递（`deals` 变量）
- [ ] 热销商品数据正确传递（`bestsellers` 变量）
- [ ] 轮播图数据正确传递（`banners` 变量）
- [ ] 布局变体切换（通过URL参数 `?layout=2`）

---

### 二、产品列表页控制器

**控制器**: `WeShop\Product\Controller\Frontend\Product\ProductList`
**URL**: `/weshop/product/list`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 产品列表数据正确传递（`products` 变量）
- [ ] 分类筛选功能（`?category_id=1`）
- [ ] 搜索功能（`?search=keyword`）
- [ ] 排序功能（`?sort=price&order=asc`）
- [ ] 分页功能（`?page=2`）
- [ ] 价格筛选（`?min_price=10&max_price=100`）

---

### 三、产品详情页控制器

**控制器**: `WeShop\Product\Controller\Frontend\Product\View`
**URL**: `/weshop/product/view?product_id=1`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 产品详情数据正确传递（`product` 变量）
- [ ] 产品图片数据正确传递（`images` 变量）
- [ ] 相关产品数据正确传递（`related_products` 变量）
- [ ] 产品ID验证（无效ID应返回404或错误）
- [ ] SEO数据正确设置（`meta_title`, `meta_description`）

---

### 四、购物车控制器

**控制器**: `WeShop\Cart\Controller\Frontend\Cart\Index`
**URL**: `/weshop/cart`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 购物车商品列表正确传递（`items` 变量）
- [ ] 购物车总计正确传递（`totals` 变量）
- [ ] 空购物车处理（购物车为空时的显示）
- [ ] 需要登录验证（未登录用户应重定向到登录页）

---

### 五、结账页控制器

**控制器**: `WeShop\Checkout\Controller\Frontend\Checkout\Index`
**URL**: `/weshop/checkout`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 购物车验证（空购物车应重定向）
- [ ] 配送地址数据正确传递（`shipping_addresses` 变量）
- [ ] 需要登录验证（未登录用户应重定向到登录页）

---

### 六、订单确认页控制器

**控制器**: `WeShop\Checkout\Controller\Frontend\Checkout\Success`
**URL**: `/weshop/checkout/success?order_id=1`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 订单数据正确传递（`order` 变量）
- [ ] 订单验证（无效订单ID应返回错误）
- [ ] 订单归属验证（只能查看自己的订单）

---

### 七、登录页控制器

**控制器**: `WeShop\Customer\Controller\Frontend\Account\Login`
**URL**: `/weshop_customer/customer/account/login`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 已登录用户重定向（已登录应重定向到账户首页）
- [ ] 登录表单提交（POST `/weshop_customer/customer/account/loginPost`）
- [ ] 登录成功重定向
- [ ] 登录失败错误提示
- [ ] 记住我功能

---

### 八、注册页控制器

**控制器**: `WeShop\Customer\Controller\Frontend\Account\Register`
**URL**: `/weshop_customer/customer/account/register`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 已登录用户重定向
- [ ] 注册表单提交（POST `/weshop_customer/customer/account/registerPost`）
- [ ] 表单验证（邮箱格式、密码强度、必填字段）
- [ ] 邮箱唯一性验证
- [ ] 注册成功后自动登录

---

### 九、密码重置页控制器

**控制器**: `WeShop\Customer\Controller\Frontend\Account\ForgotPassword`
**URL**: `/weshop_customer/customer/account/forgotPassword`

#### 测试项：
- [ ] 忘记密码页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 已登录用户重定向
- [ ] 忘记密码表单提交（POST `/weshop_customer/customer/account/forgotPasswordPost`）
- [ ] 邮箱验证
- [ ] 重置密码表单（带token的URL）
- [ ] 重置密码提交（POST `/weshop_customer/customer/account/resetPasswordPost`）
- [ ] Token验证
- [ ] 密码强度验证

---

### 十、用户账户首页控制器

**控制器**: `WeShop\Customer\Controller\Frontend\Account\Index`
**URL**: `/weshop_customer/customer/account`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 需要登录验证（未登录应重定向到登录页）
- [ ] 客户信息正确传递（`customer` 变量）
- [ ] 最近订单正确传递（`recent_orders` 变量）
- [ ] 订单统计正确传递（`order_count`, `unpaid_count` 变量）
- [ ] 快捷操作链接正确传递（`quick_links` 变量）

---

### 十一、CMS页面控制器

**控制器**: `WeShop\Cms\Controller\Frontend\Page\View`
**URL**: `/cms/cms/page/view?identifier=about-us`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 页面数据正确传递（`page` 变量）
- [ ] 页面标识符验证（无效标识符应返回错误）
- [ ] SEO数据正确设置（`meta_title`, `meta_description`）

---

### 十二、客户服务页控制器

**控制器**: `WeShop\Customer\Controller\Frontend\CustomerService\Index`
**URL**: `/weshop_customer/customer/service`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] FAQ列表正确传递（`faqs` 变量）
- [ ] 联系方式正确传递（`contact_info` 变量）

---

### 十三、活动优惠页控制器

**控制器**: `WeShop\Promotion\Controller\Frontend\Promotion\Index`
**URL**: `/promotion/promotion`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 特价商品列表正确传递（`deals` 变量）
- [ ] 活动列表正确传递（`promotions` 变量）

---

### 十四、商品问答页控制器

**控制器**: `WeShop\QA\Controller\Frontend\QA\Index`
**URL**: `/qa/qa?product_id=1`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 产品ID验证（无效ID应返回错误）
- [ ] 问答列表正确传递（`qa_list` 变量）

---

### 十五、评论页控制器

**控制器**: `WeShop\Review\Controller\Frontend\Review\Index`
**URL**: `/review/review?product_id=1`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 产品ID验证（无效ID应返回错误）
- [ ] 评论列表正确传递（`reviews` 变量）
- [ ] 分页功能（`?page=2`）
- [ ] 平均评分正确传递（`average_rating` 变量）

---

### 十六、退换货页控制器

**控制器**: `WeShop\RMA\Controller\Frontend\RMA\Index`
**URL**: `/rma/rma?order_id=1`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 需要登录验证（未登录应重定向到登录页）
- [ ] 订单信息正确传递（`order` 变量，如果提供order_id）
- [ ] 退换货记录列表正确传递（`rma_list` 变量）
- [ ] 创建退换货申请（POST `/rma/rma/createPost`）
- [ ] 订单归属验证（只能申请自己的订单）

---

### 十七、订单列表控制器

**控制器**: `WeShop\Order\Controller\Frontend\Order\OrderList`
**URL**: `/order/order/list`

#### 测试项：
- [ ] 页面正常加载（HTTP 200）
- [ ] 布局文件正确加载
- [ ] 需要登录验证
- [ ] 订单列表正确传递（`orders` 变量）
- [ ] 未支付订单正确传递（`unpaid_orders` 变量）
- [ ] 分页功能

---

### 十八、继续支付控制器

**控制器**: `WeShop\Order\Controller\Frontend\Order\RetryPayment`
**URL**: `/order/order/retryPayment?order_id=1`

#### 测试项：
- [ ] 需要登录验证
- [ ] 订单验证
- [ ] 支付状态验证（只有未支付订单可以继续支付）
- [ ] 重定向到结账页

---

### 十九、取消订单控制器

**控制器**: `WeShop\Order\Controller\Frontend\Order\Cancel`
**URL**: `/order/order/cancel` (POST)

#### 测试项：
- [ ] 需要登录验证
- [ ] 订单验证
- [ ] 订单状态验证（只有特定状态的订单可以取消）
- [ ] 取消成功处理
- [ ] 取消失败错误提示

---

## HTTP请求测试命令

使用框架的HTTP请求测试工具：

```bash
# 测试首页
php bin/w http:request GET /weshop/

# 测试产品列表
php bin/w http:request GET /weshop/product/list

# 测试产品详情
php bin/w http:request GET /weshop/product/view?product_id=1

# 测试购物车（需要登录）
php bin/w http:request GET /weshop/cart

# 测试登录
php bin/w http:request POST /weshop_customer/customer/account/loginPost -d "email=test@example.com&password=123456"
```

## 布局变体测试

每个控制器都应该测试所有可用的布局变体：

1. **通过URL参数测试**：
   ```
   /weshop/cart?layout=1
   /weshop/cart?layout=2
   /weshop/cart?layout=3
   /weshop/cart?layout=4
   ```

2. **通过主题配置测试**：
   修改主题配置中的 `layouts.cart` 值，测试不同的布局变体。

## 数据验证检查点

对于每个控制器，验证以下数据是否正确传递：

1. **必需数据存在**：检查模板中使用的所有变量都已赋值
2. **数据类型正确**：数组、对象、字符串等类型正确
3. **数据格式正确**：价格格式、日期格式等
4. **数据完整性**：必需字段不为空
5. **数据安全性**：敏感信息已过滤

## 错误处理测试

测试以下错误场景：

1. **404错误**：无效的URL或资源不存在
2. **403错误**：权限不足
3. **400错误**：参数验证失败
4. **500错误**：服务器内部错误
5. **重定向**：未登录用户、已登录用户等

## 性能测试

1. **页面加载时间**：每个页面应在合理时间内加载（< 2秒）
2. **数据库查询优化**：检查是否有N+1查询问题
3. **缓存使用**：验证缓存是否正确使用

## 浏览器兼容性测试

在不同浏览器中测试：
- Chrome
- Firefox
- Safari
- Edge

## 移动端响应式测试

测试移动端显示：
- 手机屏幕（375px, 414px）
- 平板屏幕（768px, 1024px）

## 测试报告模板

```
## 测试日期：YYYY-MM-DD
## 测试人员：XXX

### 控制器名称：[控制器名称]
### URL：[测试URL]

#### 测试结果：
- [x] 页面正常加载
- [x] 布局文件正确加载
- [x] 数据正确传递
- [ ] 发现的问题：[问题描述]

#### 问题列表：
1. [问题1]
2. [问题2]

#### 修复建议：
1. [建议1]
2. [建议2]
```

## 下一步行动

1. ✅ 完成所有控制器开发
2. ⏳ 运行路由注册命令
3. ⏳ 执行HTTP请求测试
4. ⏳ 验证布局加载
5. ⏳ 验证数据传递
6. ⏳ 修复发现的问题
7. ⏳ 性能优化
8. ⏳ 浏览器兼容性测试

---

**最后更新**: 2024-01-XX
**状态**: 待测试
