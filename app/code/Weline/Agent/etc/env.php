<?php
/**
 * Weline_Agent module environment config.
 */

return [
    'router' => 'agent',
    'backend_router' => 'agent',
    // Humanized guided setup defaults for role configuration.
    'guided_setup' => [
        'enabled' => true,
        'default_template' => 'general_assistant',
        'default_profile' => 'multi_project',
        'max_project_count' => 200,
        'profiles' => [
            [
                'code' => 'single_project',
                'name' => '单项目',
                'description' => '适合单个项目的轻量配置。',
            ],
            [
                'code' => 'multi_project',
                'name' => '多项目',
                'description' => '适合并行管理多个项目的团队。',
            ],
            [
                'code' => 'enterprise',
                'name' => '企业治理',
                'description' => '以治理与安全为先，审批更清晰。',
            ],
        ],
        'default_model_config' => [
            'temperature' => 0.4,
            'max_tokens' => 4096,
        ],
        'steps' => [
            '选择角色模板',
            '描述业务场景与项目数量',
            '生成 AI 建议并自动填充字段',
            '检查高级设置并保存',
        ],
        'safety_first' => true,
        'show_advanced_after_suggestion' => true,
    ],
    'humanized_config' => [
        'ai_suggestion_enabled' => true,
        'quick_template_enabled' => true,
        'permission_hint_enabled' => true,
    ],
];
