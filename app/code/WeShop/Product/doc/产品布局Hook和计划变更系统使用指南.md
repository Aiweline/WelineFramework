# 产品布局Hook和计划变更系统使用指南

## 目录

1. [概述](#概述)
2. [产品布局Hook使用](#产品布局hook使用)
3. [产品专属布局配置](#产品专属布局配置)
4. [产品布局计划管理](#产品布局计划管理)
5. [代码示例](#代码示例)
6. [常见问题](#常见问题)

## 概述

产品布局Hook和计划变更系统允许您：

- 通过Hook在产品页面注入自定义内容
- 为产品配置专属布局模板
- 设置产品布局的定时切换计划（如节日自动切换布局）

### 系统架构

```
产品控制器 → Observer(设置产品数据) → 布局模板 → Hook点 → 产品专属布局
```

### 布局优先级

1. **活动计划布局** - 当前时间在计划时间范围内的布局
2. **产品专属布局** - 产品单独配置的布局
3. **默认布局** - 系统默认布局

## 产品布局Hook使用

### 可用的Hook点

#### 产品详情页Hook (`product_detail`)

- `Weline_Theme::frontend::layouts::product_detail::head-before` - 头部之前
- `Weline_Theme::frontend::layouts::product_detail::head-after` - 头部之后
- `Weline_Theme::frontend::layouts::product_detail::body-start` - Body开始
- `Weline_Theme::frontend::layouts::product_detail::content-before` - 内容之前
- `Weline_Theme::frontend::layouts::product_detail::content-after` - 内容之后
- `Weline_Theme::frontend::layouts::product_detail::body-end` - Body结束

#### 产品列表页Hook (`product_list`)

- `Weline_Theme::frontend::layouts::product_list::head-before` - 头部之前
- `Weline_Theme::frontend::layouts::product_list::head-after` - 头部之后
- `Weline_Theme::frontend::layouts::product_list::body-start` - Body开始
- `Weline_Theme::frontend::layouts::product_list::content-before` - 内容之前
- `Weline_Theme::frontend::layouts::product_list::content-after` - 内容之后
- `Weline_Theme::frontend::layouts::product_list::body-end` - Body结束

#### 产品通用Hook (`product`)

适用于所有产品相关页面：

- `Weline_Theme::frontend::layouts::product::head-before` - 头部之前
- `Weline_Theme::frontend::layouts::product::head-after` - 头部之后
- `Weline_Theme::frontend::layouts::product::body-start` - Body开始
- `Weline_Theme::frontend::layouts::product::content-before` - 内容之前
- `Weline_Theme::frontend::layouts::product::content-after` - 内容之后
- `Weline_Theme::frontend::layouts::product::body-end` - Body结束

### Hook执行顺序

Hook的执行顺序遵循以下规则：

1. **Base Hook 先执行** - `Weline_Theme::frontend::layouts::base::*`
2. **产品通用Hook** - `Weline_Theme::frontend::layouts::product::*`
3. **特定布局Hook** - `Weline_Theme::frontend::layouts::product_detail::*` 或 `product_list::*`
4. **Base Hook 后执行** - `Weline_Theme::frontend::layouts::base::*`

### 创建Hook文件

在您的模块的 `view/hooks/` 目录下创建hook文件。

**文件命名规则**：
- Hook名称中的 `::` 需要转换为 `--`
- 文件扩展名为 `.phtml`

**示例**：
- Hook名称：`Weline_Theme::frontend::layouts::product_detail::content-before`
- 文件名：`Weline_Theme--frontend--layouts--product_detail--content-before.phtml`
- 文件路径：`{YourModule}/view/hooks/Weline_Theme--frontend--layouts--product_detail--content-before.phtml`

### Hook使用示例

#### 示例1：在产品详情页内容之前添加横幅

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product_detail--content-before.phtml -->
<?php
$product = $this->getData('product');
if ($product && $product->getId()):
?>
<div class="product-special-banner">
    <h2><?= htmlspecialchars($product->getName()) ?></h2>
    <p>限时特价，仅此一天！</p>
</div>
<?php endif; ?>
```

#### 示例2：在产品页面头部注入自定义CSS

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts::product::head-after.phtml -->
<link rel="stylesheet" href="<?= $this->getUrl('static/css/product-custom.css') ?>">
<meta name="product-id" content="<?= $this->getData('product') ? $this->getData('product')->getId() : '' ?>">
```

#### 示例3：在产品列表页添加筛选器

```html
<!-- YourModule/view/hooks/Weline_Theme--frontend--layouts--product_list--content-before.phtml -->
<div class="product-list-filters">
    <h3>产品筛选</h3>
    <!-- 筛选器内容 -->
</div>
```

### Hook中可用的变量

- `$this->getData('product')` - 产品对象（Product模型实例，仅在产品详情页可用）
- `$this->getData('meta')` - 布局元数据数组
- `$this->getData('theme')` - 主题相关数据

## 产品专属布局配置

### 布局文件位置

产品专属布局文件应放置在：

```
app/code/WeShop/Product/view/theme/frontend/layouts/{layoutType}/{layoutCode}.phtml
```

**支持的布局类型**：
- `product_detail` - 产品详情页
- `product_list` - 产品列表页

### 创建产品专属布局

#### 步骤1：创建布局文件

在 `app/code/WeShop/Product/view/theme/frontend/layouts/product_detail/` 目录下创建布局文件，例如 `custom.phtml`：

```html
<!--
布局：产品详情自定义布局

@meta.name {default="产品详情自定义布局",name="产品详情自定义布局",description="产品详情页的自定义布局模板"}
@meta.description {default="产品详情页自定义布局",name="产品详情页自定义布局",description="产品详情页自定义布局"}
-->
<!DOCTYPE html>
<html lang="@hook(Weline_Theme::frontend::layouts::base::html-lang)">
<head>
    <w:hook>Weline_Theme::frontend::layouts::base::head-before</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::head-before</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product_detail::head-before</w:hook>
    <w:block class="Weline\Theme\Block\Partials" area="frontend" type="head" default-option="default"/>
    <w:hook>Weline_Theme::frontend::layouts::product_detail::head-after</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::head-after</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::base::head-after</w:hook>
</head>
<body>
    <w:hook>Weline_Theme::frontend::layouts::base::body-start</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::body-start</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product_detail::body-start</w:hook>
    
    <if condition="meta.showHeader">
        <w:hook>Weline_Theme::frontend::layouts::base::header-before</w:hook>
        <w:block class="Weline\Theme\Block\Partials" area="frontend" type="header" default-option="default"/>
        <w:hook>Weline_Theme::frontend::layouts::base::header-after</w:hook>
    </if>
    
    <w:hook>Weline_Theme::frontend::layouts::base::content-before</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::content-before</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product_detail::content-before</w:hook>
    
    <main class="main-content product-detail custom-layout">
        <?php
        $product = $this->getData('product');
        if ($product && $product->getId()):
        ?>
        <!-- 您的自定义布局内容 -->
        <div class="custom-product-layout">
            <!-- 产品信息展示 -->
        </div>
        <?php endif; ?>
    </main>
    
    <w:hook>Weline_Theme::frontend::layouts::product_detail::content-after</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::content-after</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::base::content-after</w:hook>
    
    <if condition="meta.showFooter">
        <w:hook>Weline_Theme::frontend::layouts::base::footer-before</w:hook>
        <w:block class="Weline\Theme\Block\Partials" area="frontend" type="footer" default-option="default"/>
        <w:hook>Weline_Theme::frontend::layouts::base::footer-after</w:hook>
    </if>
    
    <w:hook>Weline_Theme::frontend::layouts::product_detail::body-end</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::product::body-end</w:hook>
    <w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>
</body>
</html>
```

#### 步骤2：通过代码应用布局

```php
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Manager\ObjectManager;

// 获取服务实例
$layoutService = ObjectManager::getInstance(ProductLayoutService::class);

// 为产品ID为1的产品应用自定义布局
$layoutService->applyProductLayout(
    $productId = 1,
    $layoutType = 'product_detail',
    $layoutCode = 'custom'
);
```

#### 步骤3：通过后端管理界面配置

访问后台管理界面：
- 路径：`/backend/product/product/layout/index?product_id=1`
- 在产品布局配置页面选择布局类型和布局代码
- 点击保存

## 产品布局计划管理

### 什么是布局计划？

布局计划允许您为产品设置定时布局切换，例如：
- 节日期间自动切换到节日主题布局
- 促销活动期间使用促销布局
- 特定时间段使用特殊布局

### 创建布局计划

#### 方法1：通过代码创建

```php
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Manager\ObjectManager;

$layoutService = ObjectManager::getInstance(ProductLayoutService::class);

// 创建圣诞节节日布局计划
$schedule = $layoutService->createProductLayoutSchedule(
    $productId = 1,                    // 产品ID
    $layoutType = 'product_detail',    // 布局类型
    $layoutCode = 'festival',          // 布局代码
    $startTime = '2024-12-20 00:00:00', // 开始时间
    $endTime = '2024-12-26 23:59:59',   // 结束时间
    $isRecurring = false,              // 是否循环（每年重复）
    $cronExpression = '',              // Cron表达式（循环任务使用）
    $description = '圣诞节节日布局'     // 描述
);

if ($schedule) {
    echo "布局计划创建成功！";
}
```

#### 方法2：通过后端管理界面创建

1. 访问产品布局管理页面：`/backend/product/product/layout/index?product_id=1`
2. 点击"创建布局计划"按钮
3. 填写计划信息：
   - 布局类型：选择 `product_detail` 或 `product_list`
   - 布局代码：选择要应用的布局
   - 开始时间：计划生效的开始时间
   - 结束时间：计划结束的时间（可选）
   - 是否循环：是否每年重复执行
   - Cron表达式：循环任务的执行时间表达式（如果选择循环）
   - 描述：计划说明
4. 点击保存

### 布局计划状态

- **pending** - 待执行（计划时间未到）
- **active** - 活动（当前正在使用）
- **completed** - 已完成（非循环任务已结束）
- **cancelled** - 已取消

### 循环任务

如果设置 `is_recurring = true`，布局计划会在每年相同时间自动执行。

**示例：每年圣诞节使用节日布局**

```php
$schedule = $layoutService->createProductLayoutSchedule(
    $productId = 1,
    $layoutType = 'product_detail',
    $layoutCode = 'festival',
    $startTime = '2024-12-20 00:00:00',
    $endTime = '2024-12-26 23:59:59',
    $isRecurring = true,              // 设置为循环
    $cronExpression = '0 0 20 12 *', // 每年12月20日执行
    $description = '圣诞节节日布局（每年重复）'
);
```

### 管理布局计划

#### 更新布局计划

```php
$layoutService->updateProductLayoutSchedule(
    $scheduleId = 1,
    [
        'layout_code' => 'new_festival',
        'start_time' => '2024-12-25 00:00:00',
        'end_time' => '2024-12-31 23:59:59',
        'status' => 'pending'
    ]
);
```

#### 删除布局计划

```php
$layoutService->deleteProductLayoutSchedule($scheduleId = 1);
```

#### 获取产品的所有布局计划

```php
$schedules = $layoutService->getProductSchedules(
    $productId = 1,
    $layoutType = 'product_detail' // 可选，指定布局类型
);
```

## 代码示例

### 完整示例：节日促销布局切换

```php
<?php
use WeShop\Product\Service\ProductLayoutService;
use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

// 1. 获取服务实例
$layoutService = ObjectManager::getInstance(ProductLayoutService::class);

// 2. 加载产品
$product = ObjectManager::getInstance(Product::class);
$product->load(1); // 产品ID

// 3. 创建节日布局计划
$schedule = $layoutService->createProductLayoutSchedule(
    $product->getId(),
    'product_detail',
    'festival', // 节日布局代码
    '2024-12-20 00:00:00', // 开始时间
    '2024-12-26 23:59:59', // 结束时间
    false, // 不循环
    '',
    '圣诞节节日促销布局'
);

if ($schedule) {
    echo "节日布局计划创建成功！\n";
    echo "计划ID: " . $schedule->getId() . "\n";
    echo "状态: " . $schedule->getStatus() . "\n";
}

// 4. 检查当前产品布局
$currentLayout = $layoutService->getProductLayout(
    $product->getId(),
    'product_detail'
);
echo "当前布局: " . ($currentLayout ?: '默认布局') . "\n";
```

### 在控制器中使用

```php
<?php
namespace YourModule\Controller\Frontend;

use WeShop\Product\Model\Product;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class ProductDetail extends FrontendController
{
    public function index()
    {
        $productId = (int)$this->request->getParam('id');
        
        // 加载产品
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);
        
        // 设置布局类型（Observer会自动应用产品布局）
        $this->assign('layoutType', 'product_detail');
        
        // 产品数据会通过Observer自动传递到模板
        $this->assign('product', $product);
        
        return $this->fetch();
    }
}
```

### 在模板中使用产品数据

```html
<!-- 产品详情页模板 -->
<?php
$product = $this->getData('product');
if ($product && $product->getId()):
?>
<div class="product-info">
    <h1><?= htmlspecialchars($product->getName()) ?></h1>
    <div class="price">￥<?= number_format($product->getPrice(), 2) ?></div>
    <div class="description">
        <?= $product->getDescription() ?>
    </div>
</div>
<?php endif; ?>
```

## 常见问题

### Q1: Hook文件没有生效？

**A:** 检查以下几点：
1. 文件路径是否正确（`view/hooks/` 目录下）
2. 文件名是否正确（`::` 替换为 `--`）
3. 清除缓存后重试
4. 检查Hook名称是否匹配

### Q2: 产品专属布局没有加载？

**A:** 检查以下几点：
1. 布局文件是否在正确的位置（`view/theme/frontend/layouts/{layoutType}/`）
2. 布局代码是否正确
3. 是否已通过服务或后台配置应用布局
4. 检查布局优先级（计划布局 > 产品布局 > 默认布局）

### Q3: 布局计划没有按时切换？

**A:** 检查以下几点：
1. 定时任务是否正常运行（`product_layout_switch`）
2. 计划时间是否正确
3. 计划状态是否为 `pending` 或 `active`
4. 检查系统时间是否正确

### Q4: 如何查看当前产品使用的布局？

**A:** 使用以下代码：

```php
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Manager\ObjectManager;

$layoutService = ObjectManager::getInstance(ProductLayoutService::class);
$layout = $layoutService->getProductLayout($productId, 'product_detail');
echo "当前布局: " . ($layout ?: '默认布局');
```

### Q5: 如何清除产品布局缓存？

**A:** 布局服务会自动管理缓存，您也可以手动清除：

```php
$layoutService->clearProductLayoutCache($productId, $layoutType);
```

### Q6: 支持主题继承吗？

**A:** 是的，产品专属布局支持主题继承。系统会按以下顺序查找布局文件：
1. 产品模块布局（`app/code/WeShop/Product/view/theme/...`）
2. 当前主题布局
3. 父主题布局
4. 默认布局

## 相关文件

- Hook定义：`app/code/Weline/Theme/hook.php`
- 产品布局服务：`app/code/WeShop/Product/Service/ProductLayoutService.php`
- 产品布局模型：`app/code/WeShop/Product/Model/ProductLayout.php`
- 布局计划模型：`app/code/WeShop/Product/Model/ProductLayoutSchedule.php`
- 定时任务：`app/code/WeShop/Product/Cron/ProductLayoutSwitch.php`
- 后端控制器：`app/code/WeShop/Product/Controller/Backend/Product/Layout.php`

## 更新日志

- **2024-12-20** - 初始版本发布
  - 支持产品布局Hook
  - 支持产品专属布局配置
  - 支持产品布局计划管理

## 2026-06-09 产品/分类级布局解析补充

本系统现在把“选择哪个布局”放在产品级布局解析器里完成，然后再交给 Theme layout 体系渲染。布局模板只负责页面骨架、slot、hook 和样式，不承载加购、价格、库存、接口请求等业务逻辑。

### 有效布局优先级

商品详情页按以下顺序解析有效 layout option：

1. 产品当前有效的活动计划布局。
2. 产品专属布局。
3. 商品所属分类的“分类下商品默认布局”当前有效活动计划。
4. 商品所属分类的“分类下商品默认布局”。
5. Theme 的商品默认布局。

分类展示页按以下顺序解析有效 layout option：

1. 分类当前有效的活动计划布局。
2. 分类专属布局。
3. Theme 的分类默认布局。

### 可编辑布局文件

后台保存的自定义布局是真实模板文件，不只是选择项：

- 商品详情布局：`app/code/Weline/Theme/view/theme/frontend/layouts/product/{layout_code}.phtml`
- 分类展示布局：`app/code/Weline/Theme/view/theme/frontend/layouts/category/{layout_code}.phtml`

后台支持三种上下文：

- `entity_type=product&product_id=...`：产品专属详情布局。
- `entity_type=category&category_id=...`：分类展示布局。
- `entity_type=category_product_default&category_id=...`：该分类下商品详情页的默认布局。

### 定时计划恢复策略

定时计划激活时只把该计划作为“当前有效 option”，不会把活动布局写入产品或分类的 baseline 布局记录。计划停用或结束后，解析器会重新回到原来的产品专属布局、分类默认布局或 Theme 默认布局。

### 后台预览

后台预览通过前台 URL 参数临时渲染：

- `layout_preview=1`
- `layout_option={layout_code}`
- `no_cache=1`

预览请求会绕过商品/分类页面缓存，不写入产品、分类或定时计划配置，也不会记录最近浏览商品。
