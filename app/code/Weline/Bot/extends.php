<?php
/**
 * Weline_Bot 扩展点定义
 */

return [
    'extends' => [
        // 技能扩展点
        'Skill' => [
            'path' => 'extends/module/Weline_Bot/Skill',
            'interface' => 'Weline\\Bot\\Interface\\SkillInterface',
            'multiple' => true,
        ],
        // 渠道适配器扩展点
        'ChannelAdapter' => [
            'path' => 'extends/module/Weline_Bot/ChannelAdapter',
            'interface' => 'Weline\\Bot\\Interface\\ChannelAdapterInterface',
            'multiple' => true,
        ],
        // 查询提供者扩展点（用于 w_query）
        'QueryProvider' => [
            'path' => 'extends/module/Weline_Bot/Query',
            'interface' => 'Weline\\Framework\\Service\\Query\\Provider\\QueryProviderInterface',
            'multiple' => true,
        ],
    ],
];
