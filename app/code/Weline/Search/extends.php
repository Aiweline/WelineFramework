<?php

declare(strict_types=1);

return [
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [
        'Searcher' => [
            'path' => 'extends/module/Weline_Search/Searcher',
            'interface' => 'Weline\Search\Api\SearchProviderInterface',
            'description' => 'Business search provider registered into the universal Search hub.',
            'required' => false,
            'multiple' => true,
        ],
        'Engine' => [
            'path' => 'extends/module/Weline_Search/Engine',
            'interface' => 'Weline\Search\Api\SearchEngineInterface',
            'description' => 'Optional search engine implementation (mysql default is built-in).',
            'required' => false,
            'multiple' => true,
        ],
    ],
];
