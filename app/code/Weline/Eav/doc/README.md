# Weline Eav 实体属性值模块

## 模块概述

Weline Eav (Entity-Attribute-Value) 是系统的实体属性值模块，提供了灵活的数据模型设计能力。该模块允许开发者为任何实体（如产品、用户、订单等）动态添加自定义属性，支持多种数据类型、属性分组、属性集等企业级特性。

## 主要功能

### 1. 实体管理
- 实体定义和注册
- 实体属性配置
- 实体关系管理
- 实体缓存机制

### 2. 属性管理
- 动态属性创建
- 属性类型支持
- 属性分组管理
- 属性集管理

### 3. 属性值存储
- 多值属性支持
- 属性值验证
- 属性值缓存
- 属性值查询

### 4. 数据类型支持
- 文本类型（text, varchar, textarea）
- 数字类型（int, decimal, float）
- 日期类型（date, datetime, time）
- 选择类型（select, multiselect, radio, checkbox）
- 文件类型（file, image）
- 自定义类型

### 5. 高级功能
- 属性依赖关系
- 属性条件显示
- 属性验证规则
- 属性默认值

## 使用方法

### 创建 EAV 模型
```php
namespace Your\Module\Model;

use Weline\Eav\EavModel;

class Product extends EavModel
{
    // 实体信息
    const entity_code = 'product';
    const entity_name = '产品实体';
    const eav_entity_id_field_type = 'integer';
    const eav_entity_id_field_length = 11;
    
    // 主表字段
    public const fields_ID = 'product_id';
    public const fields_NAME = 'name';
    public const fields_SKU = 'sku';
    public const fields_PRICE = 'price';
    
    public function setup(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('产品表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', '产品ID')
                ->addColumn(self::fields_NAME, TableInterface::column_type_VARCHAR, 255, 'not null', '产品名称')
                ->addColumn(self::fields_SKU, TableInterface::column_type_VARCHAR, 100, 'not null', 'SKU')
                ->addColumn(self::fields_PRICE, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '价格')
                ->create();
        }
    }
    
    /**
     * 初始化产品属性
     */
    public function initAttributes(): void
    {
        // 添加产品颜色属性
        $this->addAttribute(
            'color',           // 属性代码
            '产品颜色',        // 属性名称
            'select',          // 属性类型
            false,             // 是否多值
            true,              // 是否有选项
            false,             // 是否系统属性
            true,              // 是否启用
            'appearance',      // 属性组
            'basic'            // 属性集
        );
        
        // 添加产品尺寸属性
        $this->addAttribute(
            'size',
            '产品尺寸',
            'multiselect',
            true,              // 多值属性
            true,
            false,
            true,
            'appearance',
            'basic'
        );
        
        // 添加产品描述属性
        $this->addAttribute(
            'description',
            '产品描述',
            'textarea',
            false,
            false,
            false,
            true,
            'content',
            'basic'
        );
        
        // 添加产品图片属性
        $this->addAttribute(
            'images',
            '产品图片',
            'image',
            true,              // 多值属性
            false,
            false,
            true,
            'media',
            'basic'
        );
    }
}
```

### 使用 EAV 属性
```php
use Your\Module\Model\Product;

$product = new Product();

// 创建产品
$product->setName('测试产品')
    ->setSku('TEST001')
    ->setPrice(99.99)
    ->save();

// 设置属性值
$product->setData('color', '红色');
$product->setData('size', ['S', 'M', 'L']);
$product->setData('description', '这是一个测试产品的详细描述');
$product->setData('images', ['image1.jpg', 'image2.jpg']);

// 保存属性值
$product->save();

// 获取属性值
$color = $product->getData('color');
$sizes = $product->getData('size');
$description = $product->getData('description');
$images = $product->getData('images');

// 获取属性对象
$colorAttribute = $product->getAttribute('color');
$sizeAttribute = $product->getAttribute('size');

// 获取所有属性
$allAttributes = $product->getAttributes();
```

### 属性查询和过滤
```php
use Your\Module\Model\Product;

$product = new Product();

// 根据属性值查询产品
$redProducts = $product->clear()
    ->joinAttribute('color', '红色')
    ->select()
    ->fetchArray();

// 根据多个属性值查询
$products = $product->clear()
    ->joinAttribute('color', '红色')
    ->joinAttribute('size', 'M')
    ->select()
    ->fetchArray();

// 获取特定属性组的产品
$appearanceAttributes = $product->getAttributeGroup('appearance');
$contentAttributes = $product->getAttributeGroup('content');

// 获取特定属性集的产品
$basicSet = $product->getAttributeSet('basic');
$advancedSet = $product->getAttributeSet('advanced');
```

### 属性管理
```php
use Your\Module\Model\Product;
use Weline\Eav\Model\EavAttribute;

$product = new Product();

// 添加新属性
$product->addAttribute(
    'brand',
    '品牌',
    'select',
    false,
    true,
    false,
    true,
    'basic',
    'basic'
);

// 更新属性
$brandAttribute = $product->getAttribute('brand');
$brandAttribute->setName('产品品牌')
    ->setIsEnable(true);
$product->setAttribute($brandAttribute);

// 删除属性
$product->unsetAttribute('old_attribute', true); // true表示同时删除属性值

// 获取属性选项
$colorAttribute = $product->getAttribute('color');
$colorOptions = $colorAttribute->getOptions();

// 添加属性选项
$colorAttribute->addOption('蓝色', 'blue');
$colorAttribute->addOption('绿色', 'green');
```

## 配置说明

### EAV 配置
在 `app/etc/eav.php` 中配置 EAV 相关设置：

```php
'eav' => [
    'cache' => true,
    'cache_time' => 3600,
    'auto_init' => true,
    'strict_mode' => false,
    'default_types' => [
        'text' => '文本',
        'textarea' => '多行文本',
        'int' => '整数',
        'decimal' => '小数',
        'date' => '日期',
        'datetime' => '日期时间',
        'select' => '单选',
        'multiselect' => '多选',
        'radio' => '单选按钮',
        'checkbox' => '复选框',
        'file' => '文件',
        'image' => '图片'
    ]
]
```

### 属性类型配置
```php
'attribute_types' => [
    'text' => [
        'backend_type' => 'varchar',
        'frontend_type' => 'text',
        'input_type' => 'text'
    ],
    'select' => [
        'backend_type' => 'int',
        'frontend_type' => 'select',
        'input_type' => 'select',
        'has_options' => true
    ],
    'multiselect' => [
        'backend_type' => 'text',
        'frontend_type' => 'multiselect',
        'input_type' => 'multiselect',
        'has_options' => true,
        'multi_value' => true
    ]
]
```

## 依赖关系

- Weline_Framework

## 版本信息

- 当前版本：1.0.5
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 属性类型详解

### 文本类型
```php
// 单行文本
$product->addAttribute('title', '标题', 'text');

// 多行文本
$product->addAttribute('description', '描述', 'textarea');

// 密码
$product->addAttribute('password', '密码', 'password');
```

### 数字类型
```php
// 整数
$product->addAttribute('quantity', '数量', 'int');

// 小数
$product->addAttribute('weight', '重量', 'decimal');

// 货币
$product->addAttribute('price', '价格', 'price');
```

### 日期类型
```php
// 日期
$product->addAttribute('release_date', '发布日期', 'date');

// 日期时间
$product->addAttribute('created_at', '创建时间', 'datetime');

// 时间
$product->addAttribute('start_time', '开始时间', 'time');
```

### 选择类型
```php
// 单选下拉
$product->addAttribute('category', '分类', 'select', false, true);

// 多选下拉
$product->addAttribute('tags', '标签', 'multiselect', true, true);

// 单选按钮
$product->addAttribute('status', '状态', 'radio', false, true);

// 复选框
$product->addAttribute('features', '特性', 'checkbox', true, true);
```

### 文件类型
```php
// 文件上传
$product->addAttribute('document', '文档', 'file');

// 图片上传
$product->addAttribute('image', '图片', 'image');

// 多图片上传
$product->addAttribute('gallery', '图片库', 'image', true);
```

## 属性分组和属性集

### 属性分组
```php
use Weline\Eav\Model\EavAttribute\Group;

$group = new Group();
$group->setCode('appearance')
    ->setName('外观属性')
    ->setSort(1)
    ->save();

// 为分组添加属性
$product->addAttribute('color', '颜色', 'select', false, true, false, true, 'appearance');
$product->addAttribute('size', '尺寸', 'select', false, true, false, true, 'appearance');
```

### 属性集
```php
use Weline\Eav\Model\EavAttribute\Set;

$set = new Set();
$set->setCode('basic')
    ->setName('基础属性集')
    ->setSort(1)
    ->save();

// 为属性集添加属性
$product->addAttribute('name', '名称', 'text', false, false, false, true, 'basic', 'basic');
$product->addAttribute('sku', 'SKU', 'text', false, false, false, true, 'basic', 'basic');
```

## 高级查询

### 复杂属性查询
```php
use Your\Module\Model\Product;

$product = new Product();

// 多条件查询
$products = $product->clear()
    ->joinAttribute('color', ['红色', '蓝色'])  // 颜色为红色或蓝色
    ->joinAttribute('size', 'M')                // 尺寸为M
    ->joinAttribute('price', ['min' => 50, 'max' => 200])  // 价格范围
    ->select()
    ->fetchArray();

// 属性值排序
$products = $product->clear()
    ->joinAttribute('price', null, 'ASC')  // 按价格升序
    ->select()
    ->fetchArray();

// 属性值聚合
$stats = $product->clear()
    ->joinAttribute('category')
    ->groupBy('category')
    ->select(['category', 'COUNT(*) as count'])
    ->fetchArray();
```

### 属性值统计
```php
// 获取属性值分布
$colorDistribution = $product->getAttributeValueDistribution('color');

// 获取属性值统计
$priceStats = $product->getAttributeValueStats('price');

// 获取热门属性值
$popularColors = $product->getPopularAttributeValues('color', 10);
```

## 性能优化

### 1. 缓存策略
- 启用属性缓存
- 合理设置缓存时间
- 及时清理过期缓存

### 2. 查询优化
- 使用索引优化查询
- 避免 N+1 查询问题
- 合理使用 JOIN 查询

### 3. 存储优化
- 选择合适的属性类型
- 避免存储冗余数据
- 定期清理无用属性

## 最佳实践

### 1. 属性设计
- 合理规划属性结构
- 使用有意义的属性代码
- 避免属性过度设计

### 2. 性能考虑
- 合理使用多值属性
- 避免过多的属性查询
- 使用缓存机制

### 3. 数据完整性
- 设置属性验证规则
- 使用默认值
- 定期数据清理

### 4. 扩展性
- 预留扩展属性
- 使用属性分组管理
- 支持属性继承

## 常见问题

### Q: 如何添加自定义属性类型？
A: 继承 EavAttribute\Type 类，实现自定义属性类型逻辑。

### Q: 如何处理属性值验证？
A: 在属性定义时设置验证规则，或在保存前进行验证。

### Q: 如何优化属性查询性能？
A: 使用缓存、索引优化、批量查询等方式提升性能。

### Q: 如何处理属性依赖关系？
A: 使用属性条件显示和依赖管理功能。

### Q: 如何迁移现有数据到 EAV？
A: 编写数据迁移脚本，将现有字段转换为 EAV 属性。 