<?php

declare(strict_types=1);

return [
    'base_type' => 'array',
    'item_schema' => [
        'name' => ['type' => 'string', 'label' => '名称'],
        'caption' => ['type' => 'string', 'label' => '说明'],
        'image' => [
            'type' => 'image',
            'label' => 'Logo',
            'media_options' => [
                'default_directory' => 'brand',
            ],
        ],
        'link' => ['type' => 'url', 'label' => '链接'],
    ],
    'sortable' => true,
    'max_items' => 100,
];
