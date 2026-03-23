<?php

return [
    'WeShop_Compliance::frontend::layouts::compliance-page::before' => [
        'name' => __('Compliance page before content'),
        'description' => __('Inject storefront content before compliance page sections.'),
        'doc' => 'frontend/compliance/page-before.md',
    ],
    'WeShop_Compliance::frontend::partials::consent-item::after' => [
        'name' => __('Consent item extension'),
        'description' => __('Inject storefront content after each consent item.'),
        'doc' => 'frontend/compliance/consent-item-after.md',
    ],
];

