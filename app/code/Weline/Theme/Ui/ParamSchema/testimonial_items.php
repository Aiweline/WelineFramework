<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'author' => ['type' => 'string', 'label' => '姓名'],
        'avatar' => [
            'type' => 'image',
            'label' => '头像',
            'media_options' => [
                'default_directory' => 'testimonial',
            ],
        ],
        'content' => ['type' => 'textarea', 'label' => '评价'],
        'rating' => ['type' => 'number', 'label' => '评分'],
        'position' => ['type' => 'string', 'label' => '身份'],
    ],
    'sortable' => true,
    'max_items' => 50,
];
