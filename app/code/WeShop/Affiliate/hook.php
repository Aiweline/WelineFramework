<?php

return [
    'WeShop_Affiliate::frontend::layouts::affiliate-page::before' => [
        'name' => __('Affiliate page hooks'),
        'description' => __('Inject storefront content above the affiliate dashboard page.'),
        'doc' => 'frontend/affiliate/page-before.md',
    ],
    'WeShop_Affiliate::frontend::partials::affiliate-summary::after' => [
        'name' => __('Affiliate summary hooks'),
        'description' => __('Inject storefront content below the affiliate summary card.'),
        'doc' => 'frontend/affiliate/summary-after.md',
    ],
];
