<?php

declare(strict_types=1);

use Weline\Ops\Extends\Module\Weline_SiteSetupAssistant\SetupTask\OpsSetupTaskProvider;
use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;

return [
    SetupTaskProviderInterface::class => [
        OpsSetupTaskProvider::class,
    ],

    'type' => 'module',
    'documentation' => 'doc/extends.md',
    'extends' => [
        'Lane' => [
            'path' => 'extends/module/Weline_Ops/Lane',
            'interface' => 'Weline\\Ops\\Api\\OpsLaneContributionInterface',
            'description' => 'AI 运营台范围车道：连接健康、深链与任务类型贡献',
            'required' => true,
            'multiple' => true,
        ],
    ],
];
