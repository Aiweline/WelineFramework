<?php

/**
 * WeShop_Social module hook specification file.
 */
return [
    'WeShop_Social::frontend::partials::login::buttons' => [
        'name' => __('Storefront social login buttons'),
        'description' => __('Inject third-party sign-in buttons into the storefront customer login page.'),
        'doc' => 'frontend/login/buttons.md',
    ],
    'WeShop_Social::frontend::partials::footer::social-links' => [
        'name' => __('Footer social links'),
        'description' => __('Inject configured social links into storefront footers.'),
        'doc' => 'frontend/footer/social-links.md',
    ],
];
