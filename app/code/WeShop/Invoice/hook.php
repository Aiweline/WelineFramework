<?php

return [
    'WeShop_Invoice::frontend::layouts::invoice-page::before' => [
        'name' => __('Invoice page hooks'),
        'description' => __('Inject storefront content above the invoice list.'),
        'doc' => 'frontend/invoice/page-before.md',
    ],
    'WeShop_Invoice::frontend::partials::invoice-list::row-after' => [
        'name' => __('Invoice list hooks'),
        'description' => __('Inject storefront content next to each invoice list item.'),
        'doc' => 'frontend/invoice/invoice-list.md',
    ],
];
