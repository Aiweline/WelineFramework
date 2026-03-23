<?php

return [
    'WeShop_QA::frontend::layouts::product-questions::content' => [
        'name' => __('Product Q&A tab content'),
        'description' => __('Render the Q&A block inside product detail tabs.'),
        'doc' => 'frontend/qa/product-questions.md',
    ],
    'WeShop_QA::frontend::layouts::qa-page::before' => [
        'name' => __('QA Page Hooks'),
        'description' => __('Inject widgets before the storefront Q&A listing.'),
        'doc' => 'frontend/qa/page-before.md',
    ],
    'WeShop_QA::frontend::partials::question-item::after' => [
        'name' => __('QA Question Item Hooks'),
        'description' => __('Add badges or metadata around each storefront question card.'),
        'doc' => 'frontend/qa/question-item-after.md',
    ],
];
