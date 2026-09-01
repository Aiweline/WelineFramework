<?php

return [
    'Weline_Promotion::theme_save_after' => [
        'name' => __('Activity theme saved'),
        'description' => __('After promotion activity theme save: publish ResourceChange v1 via w_changed, clear local FPC; CDN/SEO handled by resource_changed observers.'),
        'doc' => 'resource_changed.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'theme_id' => ['type' => 'integer', 'required' => true, 'description' => 'Saved theme id'],
            'website_id' => ['type' => 'integer', 'required' => true, 'description' => 'Theme website id (0 = default website)'],
            'page_slug' => ['type' => 'string', 'required' => true, 'description' => 'Storefront slug under /promotion/{slug}'],
            'urls' => ['type' => 'array', 'required' => false, 'description' => 'Invalidated storefront paths'],
        ],
    ],
];
