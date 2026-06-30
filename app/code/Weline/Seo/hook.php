<?php

declare(strict_types=1);

return [
    'seo::head' => [
        'name' => __('SEO head output'),
        'description' => __('Renders SEO metadata and structured data in the document head.'),
        'doc' => 'seo/head.md',
    ],
    'seo::body' => [
        'name' => __('SEO body output'),
        'description' => __('Renders SEO body-level markup immediately after the document body starts.'),
        'doc' => 'seo/body.md',
    ],
    'seo::footer' => [
        'name' => __('SEO footer output'),
        'description' => __('Renders SEO footer-level markup before the document body closes.'),
        'doc' => 'seo/footer.md',
    ],
];
