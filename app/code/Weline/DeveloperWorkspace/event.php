<?php

declare(strict_types=1);

return [
    'Weline_DeveloperWorkspace::api_doc_collect_after' => [
        'name' => __('API 文档收集后'),
        'description' => __('DeveloperWorkspace 汇总 API 文档后触发，允许其他模块通过事件追加 SDK、协议或扩展 API 文档。'),
        'doc' => 'hook/api-doc-collect-after.md',
        'version' => '1.0.0',
        'type' => 'integration',
        'data_contract' => [
            'apis' => ['type' => 'array', 'required' => true, 'description' => '按模块分组的 API 文档数组，观察者可追加后写回。'],
            'force' => ['type' => 'bool', 'required' => false, 'description' => '是否强制重新生成。'],
            'source' => ['type' => 'string', 'required' => false, 'description' => '触发来源，默认 developer_workspace。'],
        ],
    ],
];
