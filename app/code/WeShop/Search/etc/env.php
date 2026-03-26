<?php

declare(strict_types=1);

return [
    'router' => 'search',
    'config' => [
        'default_scope' => 'default',
        'default_engine' => 'opensearch',
        'engines' => [
            'opensearch' => [
                'host' => 'http://127.0.0.1',
                'port' => 9200,
                'index' => 'products',
                'username' => '',
                'password' => '',
                'timeout' => 5,
                'version' => '3.5.0',
                'install_dir' => 'extend/server/opensearch',
                'config_file' => 'extend/server/opensearch/config/opensearch.yml',
                'data_dir' => 'extend/server/opensearch/data',
                'log_dir' => 'extend/server/opensearch/logs',
            ],
            'meilisearch' => [
                'host' => 'http://127.0.0.1:7700',
                'api_key' => '',
                'index_name' => 'products',
            ],
            'mysql' => [],
            'elasticsearch' => [
                'host' => 'http://127.0.0.1',
                'port' => 9200,
                'index' => 'products',
                'username' => '',
                'password' => '',
                'timeout' => 5,
            ],
            'algolia' => [
                'application_id' => '',
                'api_key' => '',
                'index_name' => 'products',
            ],
        ],
    ],
];
