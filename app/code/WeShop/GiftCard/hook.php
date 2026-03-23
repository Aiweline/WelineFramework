<?php

return [
    'WeShop_GiftCard::frontend::layouts::gift-card-page::before' => [
        'name' => __('Gift card page hooks'),
        'description' => __('Inject storefront content above the gift card list page.'),
        'doc' => 'frontend/gift-card/page-before.md',
    ],
    'WeShop_GiftCard::frontend::partials::gift-card-list::item-after' => [
        'name' => __('Gift card list item hooks'),
        'description' => __('Inject storefront content after each gift card entry row.'),
        'doc' => 'frontend/gift-card/item-after.md',
    ],
];
