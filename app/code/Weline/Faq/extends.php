<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'FaqTypeProvider' => [
            'path' => 'extends/module/Weline_Faq/FaqTypeProvider',
            'interface' => 'Weline\Faq\Api\FaqTypeProviderInterface',
            'description' => 'Register FAQ entity type providers (product, site, …) that resolve external UUIDs to storage keys.',
            'required' => false,
            'multiple' => true,
        ],
        'FaqPageProvider' => [
            'path' => 'extends/module/Weline_Faq/FaqPageProvider',
            'interface' => 'Weline\Faq\Api\FaqPageProviderInterface',
            'description' => 'Inject module help pages into /faq Hub and /faq/{slug} (PaymentCustomerGuide-style).',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
