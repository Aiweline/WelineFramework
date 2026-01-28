<?php
/**
 * WeShop_Filters 模块事件定义
 * 
 * 本文件定义了筛选模块提供的所有事件
 * 其他模块可以通过这些事件扩展筛选功能
 */
return [
    // 筛选器收集事件 - 允许其他模块添加自定义筛选器
    'WeShop_Filters::filters_collect' => [
        'name' => __('筛选器收集'),
        'description' => __('收集所有可用的筛选器，其他模块可通过此事件添加自定义筛选'),
        'data' => [
            'category_id' => 'int - 分类ID',
            'product_ids' => 'array - 产品ID列表',
            'filters' => 'FilterCollectionInterface - 筛选器集合（可修改）',
        ],
    ],
    
    // 筛选条件应用前事件
    'WeShop_Filters::filters_apply_before' => [
        'name' => __('筛选条件应用前'),
        'description' => __('在应用筛选条件前触发，允许修改筛选参数'),
        'data' => [
            'category_id' => 'int - 分类ID',
            'product_ids' => 'array - 产品ID列表',
            'filter_params' => 'array - 筛选参数（可修改）',
        ],
    ],
    
    // 筛选条件应用后事件
    'WeShop_Filters::filters_apply_after' => [
        'name' => __('筛选条件应用后'),
        'description' => __('在应用筛选条件后触发，允许处理筛选结果'),
        'data' => [
            'category_id' => 'int - 分类ID',
            'original_product_ids' => 'array - 原始产品ID列表',
            'filtered_product_ids' => 'array - 筛选后的产品ID列表（可修改）',
            'filter_params' => 'array - 筛选参数',
        ],
    ],
    
    // 筛选选项收集事件
    'WeShop_Filters::filter_options_collect' => [
        'name' => __('筛选选项收集'),
        'description' => __('收集指定筛选器的选项，允许其他模块修改选项'),
        'data' => [
            'filter_code' => 'string - 筛选器代码',
            'category_id' => 'int - 分类ID',
            'product_ids' => 'array - 产品ID列表',
            'options' => 'array - 筛选选项（可修改）',
        ],
    ],
    
    // 筛选计数收集事件
    'WeShop_Filters::filter_counts_collect' => [
        'name' => __('筛选计数收集'),
        'description' => __('收集筛选选项的产品计数'),
        'data' => [
            'category_id' => 'int - 分类ID',
            'product_ids' => 'array - 产品ID列表',
            'filter_code' => 'string - 筛选器代码',
            'counts' => 'array - 计数数据（可修改）',
        ],
    ],
    
    // 筛选缓存清除事件
    'WeShop_Filters::cache_clear' => [
        'name' => __('筛选缓存清除'),
        'description' => __('筛选缓存被清除时触发'),
        'data' => [
            'category_ids' => 'array - 被清除缓存的分类ID列表',
            'clear_all' => 'bool - 是否清除全部',
        ],
    ],
];
