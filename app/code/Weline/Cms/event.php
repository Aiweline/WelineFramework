<?php

return [
    'Weline_Cms::page_save_after' => [
        'name' => __('CMS page saved'),
        'description' => __('Dispatched after a CMS page is created, updated, published, unpublished, or saved as draft.'),
        'doc' => 'page_save_after.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'page' => ['type' => 'array', 'required' => true, 'description' => 'CMS page payload'],
            'previous' => ['type' => 'array', 'required' => false, 'description' => 'Previous page row data'],
            'action' => ['type' => 'string', 'required' => true, 'description' => 'publish, upsert, draft, unpublish, delete'],
            'url' => ['type' => 'string', 'required' => false, 'description' => 'Current public URL'],
            'previous_url' => ['type' => 'string', 'required' => false, 'description' => 'Previous public URL when path changed'],
            'website_id' => ['type' => 'integer', 'required' => false, 'description' => 'Website ID'],
            'scope' => ['type' => 'string', 'required' => false, 'description' => 'CMS page scope'],
        ],
    ],
    'Weline_Cms::page_delete_after' => [
        'name' => __('CMS page deleted'),
        'description' => __('Dispatched after a CMS page is moved to trash or disabled as deleted.'),
        'doc' => 'page_delete_after.md',
        'version' => '1.0.0',
        'type' => 'domain',
        'data_contract' => [
            'page' => ['type' => 'array', 'required' => true, 'description' => 'CMS page payload'],
            'action' => ['type' => 'string', 'required' => true, 'description' => 'delete'],
            'url' => ['type' => 'string', 'required' => false, 'description' => 'Deleted page public URL'],
            'website_id' => ['type' => 'integer', 'required' => false, 'description' => 'Website ID'],
        ],
    ],
];
