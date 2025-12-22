# Weline_Layout 模块扩展协议

## 概述

Weline_Layout 模块提供了一个通用的布局管理系统，允许其他模块注册自己的布局类型和布局选项。通过实现 `LayoutProviderInterface` 接口，任何模块都可以集成布局切换功能。

## 快速开始

### 1. 创建 LayoutProvider 类

在你的模块中创建 `Extends/Weline_Layout` 目录，并创建实现 `LayoutProviderInterface` 的类：

```php
// app/code/WeShop/Product/Extends/Weline_Layout/ProductLayoutProvider.php

namespace WeShop\Product\Extends\Weline_Layout;

use Weline\Layout\Api\LayoutProviderInterface;

class ProductLayoutProvider implements LayoutProviderInterface
{
    public function getModuleCode(): string
    {
        return 'WeShop_Product';
    }

    public function getLayoutTypes(): array
    {
        return [
            'product_list' => [
                'name' => '产品列表布局',
                'description' => '用于产品列表页面的布局'
            ],
            'product_detail' => [
                'name' => '产品详情布局',
                'description' => '用于产品详情页面的布局'
            ],
            'category' => [
                'name' => '分类页布局',
                'description' => '用于分类页面的布局'
            ]
        ];
    }

    public function getLayoutOptions(string $layoutType): array
    {
        $options = [
            'product_list' => [
                'grid' => [
                    'name' => '网格布局',
                    'template' => 'WeShop_Product::Frontend/Product/list-grid.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/grid.png'
                ],
                'list' => [
                    'name' => '列表布局',
                    'template' => 'WeShop_Product::Frontend/Product/list-list.phtml',
                    'preview_image' => 'WeShop_Product::images/layout/list.png'
                ]
            ],
            'product_detail' => [
                'standard' => [
                    'name' => '标准布局',
                    'template' => 'WeShop_Product::Frontend/Product/detail-standard.phtml'
                ],
                'gallery' => [
                    'name' => '画廊布局',
                    'template' => 'WeShop_Product::Frontend/Product/detail-gallery.phtml'
                ]
            ]
        ];
        
        return $options[$layoutType] ?? [];
    }

    public function applyLayout(string $layoutType, string $layoutCode, mixed $entity): bool
    {
        // 实现布局应用逻辑
        // 可以将布局配置保存到数据库或缓存
        return true;
    }

    public function getCurrentLayout(string $layoutType, mixed $entity): ?string
    {
        // 返回当前使用的布局代码
        return 'grid'; // 默认返回网格布局
    }

    public function getDefaultLayout(string $layoutType): string
    {
        $defaults = [
            'product_list' => 'grid',
            'product_detail' => 'standard',
            'category' => 'grid'
        ];
        return $defaults[$layoutType] ?? 'default';
    }

    public function onLayoutSwitch(string $layoutType, string $oldLayout, string $newLayout): void
    {
        // 布局切换时的回调处理
        // 例如：清除缓存、发送通知等
    }
}
```

### 2. 目录结构

```
app/code/WeShop/Product/
├── Extends/
│   └── Weline_Layout/
│       └── ProductLayoutProvider.php
├── Controller/
├── Model/
└── ...
```

## 接口方法说明

### getModuleCode()
返回模块代码，如 `'WeShop_Product'`。

### getLayoutTypes()
返回模块支持的布局类型数组。每个布局类型包含：
- `name`: 布局类型名称
- `description`: 布局类型描述

### getLayoutOptions(string $layoutType)
返回指定布局类型的可用布局选项。每个选项包含：
- `name`: 布局选项名称
- `template`: 模板路径
- `preview_image`: (可选) 预览图片路径

### applyLayout(string $layoutType, string $layoutCode, mixed $entity)
应用布局到指定实体。返回是否成功。

### getCurrentLayout(string $layoutType, mixed $entity)
获取当前使用的布局代码。

### getDefaultLayout(string $layoutType)
获取布局类型的默认布局代码。

### onLayoutSwitch(string $layoutType, string $oldLayout, string $newLayout)
布局切换时的回调方法，可用于清除缓存、发送通知等。

## 事件系统

### 布局切换前事件
- 事件名: `Weline_Layout::layout_switch_before`
- 数据: `module_code`, `layout_type`, `old_layout`, `new_layout`

### 布局切换后事件
- 事件名: `Weline_Layout::layout_switch_after`
- 数据: `module_code`, `layout_type`, `layout_code`

### 布局计划触发事件
- 事件名: `Weline_Layout::layout_schedule_trigger`
- 数据: `schedule_id`, `layout_id`, `module_code`, `layout_type`, `layout_code`

## 使用 LayoutService

```php
use Weline\Layout\Service\LayoutService;

// 获取所有布局类型
$layoutService = ObjectManager::getInstance(LayoutService::class);
$allTypes = $layoutService->getAllLayoutTypes();

// 获取指定模块的布局选项
$options = $layoutService->getLayoutOptions('WeShop_Product', 'product_list');

// 应用布局
$layoutService->applyLayout('WeShop_Product', 'product_list', 'grid', $product);

// 获取当前布局
$currentLayout = $layoutService->getCurrentLayout('WeShop_Product', 'product_list', $product);
```

## 定时布局切换

通过 `LayoutSchedule` 模型可以设置定时布局切换计划：

```php
use Weline\Layout\Model\LayoutSchedule;

$schedule = ObjectManager::getInstance(LayoutSchedule::class);
$schedule->setLayoutId($layoutId)
    ->setModuleCode('WeShop_Product')
    ->setLayoutType('product_list')
    ->setStartTime('2024-01-15 00:00:00')
    ->setEndTime('2024-01-20 23:59:59')
    ->setIsRecurring(false)
    ->setStatus(LayoutSchedule::STATUS_PENDING)
    ->save();
```

系统会在设定时间自动触发布局切换事件。

## 性能优化

- LayoutProvider 类会被缓存，避免每次请求都扫描磁盘
- 可以调用 `$layoutService->clearCache()` 清除缓存
- 建议在模块安装/升级时清除布局缓存

