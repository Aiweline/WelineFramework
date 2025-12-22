# WeShop Product 模块文档

## 文档目录

- [产品布局Hook和计划变更系统使用指南](./产品布局Hook和计划变更系统使用指南.md) - 详细的使用说明和示例

## 快速开始

### 产品布局Hook

在产品页面注入自定义内容：

1. 在您的模块创建Hook文件：`view/hooks/Weline_Theme--frontend--layouts--product_detail--content-before.phtml`
2. 在Hook文件中使用 `$this->getData('product')` 获取产品数据
3. 输出您的自定义内容

### 产品专属布局

为产品配置专属布局：

1. 创建布局文件：`app/code/WeShop/Product/view/theme/frontend/layouts/product_detail/custom.phtml`
2. 通过代码或后台管理界面应用布局

### 布局计划

设置定时布局切换：

```php
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Manager\ObjectManager;

$layoutService = ObjectManager::getInstance(ProductLayoutService::class);
$layoutService->createProductLayoutSchedule(
    $productId = 1,
    $layoutType = 'product_detail',
    $layoutCode = 'festival',
    $startTime = '2024-12-20 00:00:00',
    $endTime = '2024-12-26 23:59:59'
);
```

## 更多信息

详细使用说明请参考：[产品布局Hook和计划变更系统使用指南](./产品布局Hook和计划变更系统使用指南.md)

