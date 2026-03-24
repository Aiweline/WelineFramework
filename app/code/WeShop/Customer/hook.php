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
    'WeShop_Customer::frontend::account::quick-links::before' => [
        'name' => __('Storefront account quick links before'),
        'description' => __('Inject content before the account-center quick links card grid.'),
        'doc' => 'frontend/account/quick-links/before.md',
    ],
    'WeShop_Customer::frontend::account::quick-links::after' => [
        'name' => __('Storefront account quick links after'),
        'description' => __('Inject content after the account-center quick links card grid.'),
        'doc' => 'frontend/account/quick-links/after.md',
    ],
    'WeShop_Customer::frontend::account::quick-links::cards' => [
        'name' => __('Storefront account quick link cards'),
        'description' => __('Inject additional quick-link cards into the account-center quick actions grid.'),
        'doc' => 'frontend/account/quick-links/cards.md',
    ],
    'WeShop_Customer::frontend::account::recommendations::before' => [
        'name' => __('Storefront account recommendations before'),
        'description' => __('Inject content before the account-center recommendation section.'),
        'doc' => 'frontend/account/recommendations/before.md',
    ],
    'WeShop_Customer::frontend::account::recommendations::cards' => [
        'name' => __('Storefront account recommendation cards'),
        'description' => __('Inject additional recommendation content into the account-center recommendation section.'),
        'doc' => 'frontend/account/recommendations/cards.md',
    ],
    'WeShop_Customer::frontend::account::recommendations::after' => [
        'name' => __('Storefront account recommendations after'),
        'description' => __('Inject content after the account-center recommendation section.'),
        'doc' => 'frontend/account/recommendations/after.md',
    ],
];
