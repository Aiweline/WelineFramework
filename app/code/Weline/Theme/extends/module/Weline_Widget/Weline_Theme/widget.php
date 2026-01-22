<?php

declare(strict_types=1);

/**
 * 部件规约文件：Weline_Theme 模块的部件定义（集中定义方式）
 * 
 * 格式说明：
 * - 返回一个数组，每个元素代表一个部件定义
 * - 可以在一个文件中定义多个部件，提高效率
 * - 每个部件必须包含：name, type, code, template, area
 * - 可选字段：description, version, author, params, block_class, dependencies, doc
 * - doc 字段：文档路径，相对于模块 doc/widget/ 目录，如 'sidebar/分类筛选侧栏.md'
 * 
 * 路径规范：
 * - 文件位置：extends/module/Weline_Widget/{ModuleName}/widget.php
 * - 模板路径：使用模块视图路径格式，如 'Weline_Theme::theme/frontend/widgets/{type}/{code}/default.phtml'
 * - 文档路径：doc/widget/{相对路径}，如 'sidebar/分类筛选侧栏.md'
 */
return [
    // 分类筛选侧栏部件
    [
        'name'        => '分类筛选侧栏',
        'description' => '分类页左侧属性筛选部件，支持属性分组、数量展示与已选条件展示（亚马逊风格）。',
        'type'        => 'sidebar',
        'code'        => 'category-filters',
        'area'        => 'frontend', // 区分前端/后端：frontend 或 backend
        'module'      => 'Weline_Theme',
        'version'     => '1.0.0',
        'template'    => 'Weline_Theme::theme/frontend/widgets/category-filters/default.phtml',
        'doc'         => 'sidebar/分类筛选侧栏.md', // 可选：部件说明文档路径
        'params'      => [
            'html' => [
                'type'        => 'string',
                'label'       => '自定义筛选HTML',
                'default'     => '',
                'required'    => false,
                'description' => '可选：业务模块渲染好的筛选HTML。如果为空，则使用默认亚马逊风格示例内容。',
            ],
        ],
    ],
    // 可以在这里继续添加更多部件定义
    // [
    //     'name' => '另一个部件',
    //     'type' => 'header',
    //     'code' => 'another-widget',
    //     ...
    // ],
];
