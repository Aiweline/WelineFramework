<?php
/**
 * WeShop_Filters 模块 Hook 规约文件
 * 
 * 本文件定义了 WeShop_Filters 模块提供的所有 Hook 扩展点
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 */
return [
    // ==================== 筛选容器 Hooks ====================
    'WeShop_Filters::frontend::partials::filters::container' => [
        'name' => __('筛选容器'),
        'description' => __('筛选区域的主容器，包含所有筛选组件'),
        'doc' => 'frontend/partials/filters/container.md',
        'slots' => [
            'header' => __('筛选头部'),
            'applied' => __('已选条件'),
            'groups' => __('筛选组容器'),
            'footer' => __('筛选底部'),
        ],
    ],
    
    'WeShop_Filters::frontend::partials::filters::header' => [
        'name' => __('筛选头部'),
        'description' => __('筛选区域的头部，包含标题和清除全部按钮'),
        'doc' => 'frontend/partials/filters/header.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::applied' => [
        'name' => __('已选条件'),
        'description' => __('显示当前已选择的筛选条件标签'),
        'doc' => 'frontend/partials/filters/applied.md',
        'slot' => true,
    ],
    
    // ==================== 筛选组 Hooks ====================
    'WeShop_Filters::frontend::partials::filters::price' => [
        'name' => __('价格筛选'),
        'description' => __('价格区间筛选组件，支持预设区间、动态区间、滑块三种模式'),
        'doc' => 'frontend/partials/filters/price.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::rating' => [
        'name' => __('评分筛选'),
        'description' => __('用户评分筛选组件，显示星级评分选项'),
        'doc' => 'frontend/partials/filters/rating.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::brand' => [
        'name' => __('品牌筛选'),
        'description' => __('品牌筛选组件，支持EAV属性或独立品牌模块'),
        'doc' => 'frontend/partials/filters/brand.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::stock' => [
        'name' => __('库存筛选'),
        'description' => __('库存状态筛选组件，如有货、缺货等'),
        'doc' => 'frontend/partials/filters/stock.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::shipping' => [
        'name' => __('配送筛选'),
        'description' => __('配送方式筛选组件，如免运费、当日达等'),
        'doc' => 'frontend/partials/filters/shipping.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::new' => [
        'name' => __('新品筛选'),
        'description' => __('新品筛选组件'),
        'doc' => 'frontend/partials/filters/new.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::sale' => [
        'name' => __('促销筛选'),
        'description' => __('促销/折扣商品筛选组件'),
        'doc' => 'frontend/partials/filters/sale.md',
        'slot' => true,
    ],
    
    // ==================== EAV属性筛选 Hooks ====================
    'WeShop_Filters::frontend::partials::filters::eav-attribute' => [
        'name' => __('EAV属性筛选'),
        'description' => __('动态EAV属性筛选组件，根据分类配置的可筛选属性动态渲染'),
        'doc' => 'frontend/partials/filters/eav-attribute.md',
        'slot' => true,
    ],
    
    'WeShop_Filters::frontend::partials::filters::attribute-group' => [
        'name' => __('属性组筛选'),
        'description' => __('按属性组展示筛选属性'),
        'doc' => 'frontend/partials/filters/attribute-group.md',
    ],
    
    // ==================== 筛选结果 Hooks ====================
    'WeShop_Filters::frontend::partials::filters::result-count' => [
        'name' => __('筛选结果数量'),
        'description' => __('显示筛选后的产品数量'),
        'doc' => 'frontend/partials/filters/result-count.md',
    ],
    
    // ==================== 后台配置 Hooks ====================
    'WeShop_Filters::backend::partials::config::category-filters' => [
        'name' => __('分类筛选配置'),
        'description' => __('在分类编辑页面配置可筛选属性'),
        'doc' => 'backend/partials/config/category-filters.md',
    ],
];
