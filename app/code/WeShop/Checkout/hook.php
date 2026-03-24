<?php

return [
    'WeShop_Checkout::frontend::partials::checkout::shipping-methods' => [
        'name' => __('Checkout shipping method list'),
        'description' => __('Render the dynamic list of checkout shipping methods gathered from the shipping query provider.'),
        'doc' => 'frontend/checkout/shipping-methods.md',
    ],
    'WeShop_Checkout::frontend::partials::checkout::payment-methods' => [
        'name' => __('Checkout payment method list'),
        'description' => __('Render the dynamic list of checkout payment methods gathered from the payment query provider.'),
        'doc' => 'frontend/checkout/payment-methods.md',
    ],
    'WeShop_Checkout::frontend::partials::checkout::payment-details' => [
        'name' => __('Checkout payment method details'),
        'description' => __('Render the selected payment method guidance panel for the checkout page.'),
        'doc' => 'frontend/checkout/payment-details.md',
    ],
    'WeShop_Checkout::frontend::layouts::checkout::payment-content' => [
        'name' => __('Checkout layout payment content'),
        'description' => __('Render the payment method section inside checkout layout variants so payment providers can remain theme-compatible.'),
        'doc' => 'frontend/layouts/checkout/payment-content.md',
    ],
];
