<?php

$analyticsCompatibility = [
    'description' => 'Analytics snippets depend on the canonical base head-after hook so storefront tracking code can inject safely.',
    'hosts' => [
        ['type' => 'hook', 'name' => 'Weline_Theme::frontend::layouts::base::head-after'],
    ],
];

$accountCompatibility = [
    '_template' => [
        'kind' => 'page',
        'path' => 'customer/index.phtml',
    ],
    'WeShop_Customer' => [
        'description' => 'Customer account modules depend on the canonical account-center hook hosts.',
        'hosts' => [
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::quick-links::before'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::quick-links::after'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::quick-links::cards'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::security::cards'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::recommendations::before'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::recommendations::cards'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::recommendations::after'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::discovery::cards'],
            ['type' => 'hook', 'name' => 'WeShop_Customer::frontend::account::orders::cards'],
        ],
    ],
];

return [
    'frontend' => [
        'homepage' => [
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_Promotion' => [
                'description' => 'Homepage promotion blocks inject into the deals hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_content'],
                    ['type' => 'hook', 'name' => 'WeShop_Promotion::homepage::deals_after'],
                ],
            ],
            'WeShop_Catalog' => [
                'description' => 'Homepage catalog blocks inject into the categories hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::homepage::categories_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::homepage::categories_content'],
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::homepage::categories_after'],
                ],
            ],
        ],
        'product' => [
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_Product' => [
                'description' => 'Product detail modules depend on the canonical product hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::breadcrumb_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::breadcrumb_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::product_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::product_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::tabs_content'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::related_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Product::product_detail::related_after'],
                ],
            ],
            'WeShop_Review' => [
                'description' => 'Review content injects around the product review area.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Review::product_detail::reviews_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Review::product_detail::reviews_after'],
                ],
            ],
        ],
        'product_list' => [
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_Catalog' => [
                'description' => 'Catalog listing modules depend on canonical product-list hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::frontend::layouts::category::products-content'],
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::product_list::products_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Catalog::product_list::products_after'],
                ],
            ],
            'WeShop_Filters' => [
                'description' => 'Layered filters inject into the canonical filter container hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Filters::frontend::partials::filters::header'],
                    ['type' => 'hook', 'name' => 'WeShop_Filters::frontend::partials::filters::applied'],
                    ['type' => 'hook', 'name' => 'WeShop_Filters::frontend::partials::filters::footer'],
                ],
            ],
        ],
        'cart' => [
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_Cart' => [
                'description' => 'Cart modules inject into cart item, summary, and recommendation hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::items_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::items_content'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::items_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::summary_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::summary_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::recommendations_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Cart::cart::recommendations_after'],
                ],
            ],
        ],
        'checkout' => [
            '_templates' => [
                ['kind' => 'layout'],
                ['kind' => 'page', 'path' => 'checkout/index.phtml'],
            ],
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_Checkout' => [
                'description' => 'Checkout sections depend on canonical shipping, payment, review, and summary hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::shipping_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::shipping_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::payment_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::frontend::layouts::checkout::payment-content'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::payment_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::frontend::partials::checkout::shipping-methods'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::frontend::partials::checkout::payment-methods'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::frontend::partials::checkout::payment-details'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::review_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::review_after'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::summary_before'],
                    ['type' => 'hook', 'name' => 'WeShop_Checkout::checkout::summary_after'],
                ],
            ],
            'WeShop_Shipping' => [
                'description' => 'Shipping methods inject into the checkout shipping methods host.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_Shipping::frontend::layouts::checkout::methods'],
                ],
            ],
        ],
        'account' => $accountCompatibility + ['WeShop_Analytics' => $analyticsCompatibility],
        'customer' => $accountCompatibility + ['WeShop_Analytics' => $analyticsCompatibility],
        'b2b' => [
            '_template' => [
                'kind' => 'page',
                'path' => 'b2b/index.phtml',
            ],
            'WeShop_Analytics' => $analyticsCompatibility,
            'WeShop_B2B' => [
                'description' => 'B2B modules depend on the canonical company page hook hosts.',
                'hosts' => [
                    ['type' => 'hook', 'name' => 'WeShop_B2B::frontend::layouts::business::page-before'],
                    ['type' => 'hook', 'name' => 'WeShop_B2B::frontend::partials::company::list-after'],
                ],
            ],
        ],
        'search' => [
            '_template' => [
                'kind' => 'page',
                'path' => 'search/index.phtml',
            ],
            'WeShop_Analytics' => $analyticsCompatibility,
        ],
    ],
];
