<?php

return [
    'WeShop_Review::frontend::layouts::product-reviews::content' => [
        'name' => __('Product review tab content'),
        'description' => __('Render the customer review block inside product detail tabs.'),
        'doc' => 'frontend/review/product-reviews.md',
    ],
    'WeShop_Review::frontend::layouts::review-page::before' => [
        'name' => __('Review Page Hooks'),
        'description' => __('Inject widgets before the review listing on the storefront review page.'),
        'doc' => 'frontend/review/page-before.md',
    ],
    'WeShop_Review::frontend::partials::review-item::after' => [
        'name' => __('Review Listing Hooks'),
        'description' => __('Add content around each review item.'),
        'doc' => 'frontend/review/review-list.md',
    ],
];
