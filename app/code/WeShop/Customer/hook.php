<?php
/**
 * WeShop_Customer module hook specification file.
 */
return [
    'WeShop_Customer::frontend::account::security::cards' => [
        'name' => __('Storefront account security cards'),
        'description' => __('Inject sign-in security modules into the storefront customer account center.'),
        'doc' => 'frontend/account/security/cards.md',
    ],
    'WeShop_Customer::frontend::account::discovery::cards' => [
        'name' => __('Storefront account discovery cards'),
        'description' => __('Inject saved, discovery, and follow-up cards into the storefront customer account center.'),
        'doc' => 'frontend/account/discovery/cards.md',
    ],
    'WeShop_Customer::frontend::account::orders::cards' => [
        'name' => __('Storefront account order cards'),
        'description' => __('Inject order, return, invoice, and after-sales cards into the storefront customer account center.'),
        'doc' => 'frontend/account/orders/cards.md',
    ],
];
