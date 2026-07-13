# Shipping 模块

## 概述

Shipping模块是WelineFramework的配送管理系统，提供完整的配送服务配置、费用计算、免邮规则和物流跟踪功能。支持全球地区管理、配送区域配置、快递公司管理、费用模板配置、免邮规则配置和物流跟踪等功能。

I18n 为国家目录的必需提供方；Shipping 只通过
`Weline\I18n\Api\Localization\CountryRepositoryInterface` 读取不可变国家 DTO，后台模板接收普通数组，
不接触 I18n Model/Query。地区表为空时的国家 fallback 使用 `installedActive(currentLocale)`，继续保持
国家码升序、当前 locale 名称回退以及既有九字段地区数组。

Frontend 仅用于后台地址页的客户候选列表；Shipping 通过
`Weline\Frontend\Api\Auth\FrontendAccountFacadeInterface::search('', 1, 1000)` 读取不可变身份 DTO，
再投影为既有 `id/customer_id/name/email/username` 五字段数组。该可选 Provider 缺失或查询异常时列表
仍为空，配送核心能力保持可用；Shipping 不得引用 Frontend User Model、Token 或内部 Service。

## 文档导航

- **[使用指南](使用指南.md)** - 详细的功能使用说明和操作指南
- **[API文档](API文档.md)** - 前端和后端API接口文档
- **[配置说明](配置说明.md)** - 详细的配置说明和最佳实践

## 功能特性

### 核心功能

#### 1. 地址管理
- **发货地址管理**：商家发货地址的增删改查
- **运送地址管理**：客户收货地址的增删改查
- **权限控制**：客户只能管理自己的地址
- **地址验证**：电话号码、邮政编码格式验证

#### 2. 全球地区管理
- **层级结构**：支持国家 → 省/州 → 市 → 区县
- **i18n集成**：关联i18n国家数据
- **地区导入**：支持从i18n同步国家数据
- **邮政编码**：支持邮政编码格式配置

#### 3. 配送区域配置
- **灵活配置**：可配置多个地区组合
- **地址匹配**：自动根据收货地址匹配配送区域
- **精确匹配**：支持区县、市、省、国家多级匹配

#### 4. 快递公司管理
- **手动类型**：配置追踪URL模板，引导用户到官网查询
- **API类型**：对接第三方物流API，实时查询物流信息
- **强制追踪**：所有快递公司必须支持追踪功能

#### 5. 费用计算
- **按重量**：根据订单重量计算配送费用
- **按体积**：根据订单体积计算配送费用
- **按件数**：根据订单件数计算配送费用
- **固定费用**：固定配送费用
- **混合模式**：支持多种计算方式组合

#### 6. 免邮规则
- **订单金额**：订单金额达到指定金额免邮
- **会员等级**：指定会员等级免邮
- **地区**：指定地区免邮
- **优惠券**：使用指定优惠券免邮
- **混合条件**：支持AND/OR逻辑组合

#### 7. 配送服务配置
- **服务关联**：关联快递公司、配送区域、费用模板、免邮规则
- **自动匹配**：根据收货地址自动匹配可用配送服务
- **费用计算**：自动计算配送费用，判断是否免邮

#### 8. 物流跟踪
- **统一接口**：所有快递公司使用统一的查询接口
- **API类型**：实时查询物流信息，自动更新状态
- **手动类型**：返回追踪链接，引导用户到官网查询
- **不支持追踪**：返回友好的提示信息

### 前端功能
- 个人中心侧边栏菜单集成
- Header账户下拉菜单链接
- 地址列表、表单、详情页面
- 配送服务查询API
- 物流跟踪查询API

### 后台功能
- 发货地址管理（管理员可管理所有发货地址）
- 运送地址管理（管理员可管理所有客户的收货地址）
- 地区管理（树形结构展示）
- 配送区域管理
- 快递公司管理
- 费用模板管理
- 免邮规则管理
- 配送服务管理
- 物流跟踪管理
- 搜索和筛选功能
- 分页显示
- 批量操作

## 数据库表

模块创建以下数据表：

### 地址管理表
- `w_shipping_addresses` - 发货地址表
- `w_delivery_addresses` - 运送地址表

### 配送系统表
- `w_shipping_regions` - 地区表
- `w_shipping_zones` - 配送区域表
- `w_shipping_zone_regions` - 配送区域地区关联表
- `w_shipping_carriers` - 快递公司表
- `w_shipping_rate_templates` - 配送费用模板表
- `w_shipping_free_shipping_rules` - 免邮规则表
- `w_shipping_services` - 配送服务表
- `w_shipping_tracking` - 物流跟踪记录表
- `w_shipping_tracking_nodes` - 物流跟踪节点表

详细表结构说明请参考 [API文档](API文档.md#数据模型)。

## 路由

### 前端路由

**地址管理**：
- `/shipping/address/index` - 发货地址列表
- `/shipping/address/form` - 发货地址表单（新增/编辑）
- `/shipping/delivery/index` - 收货地址列表（需登录）
- `/shipping/delivery/form` - 收货地址表单（新增/编辑）

**配送服务API**：
- `/shipping/frontend/shippingservice/getAvailableServices` - 获取可用配送服务
- `/shipping/frontend/shippingservice/calculateFee` - 计算配送费用

**物流跟踪API**：
- `/shipping/frontend/tracking/query` - 查询物流跟踪
- `/shipping/frontend/tracking/getTrackingNumber` - 根据订单获取物流单号

### 后台路由

**地址管理**：
- `/shipping/backend/shippingaddress` - 发货地址管理列表
- `/shipping/backend/shippingaddress/edit` - 发货地址编辑
- `/shipping/backend/deliveryaddress` - 运送地址管理列表
- `/shipping/backend/deliveryaddress/edit` - 运送地址编辑

**配送系统管理**：
- `/shipping/backend/region` - 地区管理
- `/shipping/backend/zone` - 配送区域管理
- `/shipping/backend/carrier` - 快递公司管理
- `/shipping/backend/ratetemplate` - 费用模板管理
- `/shipping/backend/freeshippingrule` - 免邮规则管理
- `/shipping/backend/shippingservice` - 配送服务管理
- `/shipping/backend/tracking` - 物流跟踪管理

详细API说明请参考 [API文档](API文档.md)。

## Hook集成

### Header账户菜单链接
- Hook文件：`view/hooks/header-account-links.phtml`
- Hook名称：`header-account-links`
- 功能：在Header的账户下拉菜单中添加"发货地址管理"和"收货地址管理"链接

### 个人中心侧边栏
- Hook文件：`view/hooks/account.sidebar.phtml`
- Hook名称：`account.sidebar`
- 功能：在个人中心侧边栏添加"发货地址"和"收货地址"菜单项

## 安装

1. 确保模块已注册到框架
2. 运行模块升级命令创建数据库表：
   ```bash
   php bin/w module:upgrade
   ```

## 快速开始

### 1. 安装模块

```bash
php bin/w module:upgrade --module Weline_Shipping
```

### 2. 初始化配置

1. **导入地区数据**：进入后台 **配送管理 > 配送系统**，切换至「地区管理」Tab，点击"从i18n同步"
2. **配置快递公司**：进入 **配送管理 > 配送系统**，切换至「快递公司」Tab，添加常用快递公司
3. **创建费用模板**：进入 **配送管理 > 配送系统**，切换至「费用模板」Tab，创建配送费用计算模板
4. **配置配送区域**：进入 **配送管理 > 配送系统**，切换至「配送区域」Tab，创建配送覆盖区域
5. **创建配送服务**：进入 **配送管理 > 配送系统**，切换至「配送服务」Tab，关联快递公司、区域和模板

### 3. 使用前端API

```javascript
// 获取可用配送服务
fetch('/shipping/frontend/shippingservice/getAvailableServices', {
    method: 'POST',
    body: JSON.stringify({
        country_code: 'CN',
        province: '北京',
        city: '北京市',
        district: '朝阳区'
    })
});

// 计算配送费用
fetch('/shipping/frontend/shippingservice/calculateFee', {
    method: 'POST',
    body: JSON.stringify({
        service_id: 1,
        order_amount: 150.00,
        weight: 2.5
    })
});

// 查询物流跟踪
fetch('/shipping/frontend/tracking/query', {
    method: 'POST',
    body: JSON.stringify({
        tracking_number: '1234567890',
        carrier_id: 1
    })
});
```

详细使用说明请参考 [使用指南](使用指南.md)。

## 权限说明

- **前端发货地址**：所有用户都可以访问（可根据需求调整）
- **前端运送地址**：需要客户登录，只能管理自己的地址
- **后台发货地址**：需要管理员权限
- **后台运送地址**：需要管理员权限，可以管理所有客户的地址

## 依赖模块

- `Weline_Framework` - 框架核心
- `Weline_Backend` - 后台管理
- `Weline_Customer` - 客户模块
- `Weline_Theme` - 主题模块
- `Weline_I18n` - 国际化模块

## 国际化支持

模块支持完整的i18n国际化：

- **翻译文件位置**：`app/code/Weline/Shipping/i18n/`
- **支持语言**：中文（zh_Hans_CN）、英文（en_US）
- **翻译函数**：所有用户可见文本都使用 `__()` 函数

## 版本

**当前版本**：1.0.0

**更新日期**：2024-01-14

## 技术支持

- **论坛**：https://bbs.aiweline.com
- **文档**：查看 `doc/` 目录下的详细文档
- **问题反馈**：请在论坛或GitHub提交Issue

## 相关文档

- [使用指南](使用指南.md) - 详细的功能使用说明和操作指南
- [API文档](API文档.md) - 前端和后端API接口文档
- [配置说明](配置说明.md) - 详细的配置说明和最佳实践
