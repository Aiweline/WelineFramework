<?php

declare(strict_types=1);

return [
    'php' => '^8.1',
    'recommended_items' => [
        [
            'name' => 'OpenSearch 搜索服务',
            'install_id' => 'opensearch',
            'description' => 'Search 模块默认搜索引擎。安装时会按当前环境下载对应的 OpenSearch 官方发行包，并把连接信息写入 app/etc/env.php。',
            'script_linux' => 'script/install_opensearch.php',
            'script_darwin' => 'script/install_opensearch.php',
            'script_windows' => 'script/install_opensearch.php',
        ],
    ],
];
