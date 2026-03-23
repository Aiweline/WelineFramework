<?php

return [
    'WeShop_B2B::frontend::layouts::business::page-before' => [
        'name' => __('B2B storefront layout hook'),
        'description' => __('Inject content above the B2B storefront summary.'),
        'doc' => 'frontend/b2b/page-before.md',
    ],
    'WeShop_B2B::frontend::partials::company::list-after' => [
        'name' => __('B2B company list hook'),
        'description' => __('Attach additional sections after the company table.'),
        'doc' => 'frontend/b2b/company-list-after.md',
    ],
];
