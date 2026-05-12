<?php

declare(strict_types=1);

return [
    'Weline_Geo::feed_item_add' => [
        'name' => __('GEO feed item add'),
        'description' => __('Triggered when a GEO feed item is added.'),
        'doc' => 'feed_item_add.md',
    ],
    'Weline_Geo::feed_item_update' => [
        'name' => __('GEO feed item update'),
        'description' => __('Triggered when a GEO feed item is updated.'),
        'doc' => 'feed_item_update.md',
    ],
    'Weline_Geo::feed_item_delete' => [
        'name' => __('GEO feed item delete'),
        'description' => __('Triggered when a GEO feed item is deleted.'),
        'doc' => 'feed_item_delete.md',
    ],
    'Weline_Geo::integration::feed_submit_request' => [
        'name' => __('GEO feed submit request'),
        'description' => __('Unified entrypoint for syncing new or updated URLs into GEO feeds.'),
        'doc' => 'feed_submit_request.md',
    ],
];
