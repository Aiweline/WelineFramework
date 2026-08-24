<?php
/**
 * Weline_Bot module environment config.
 */

return [
    'router' => 'bot',
    'backend_router' => 'bot',
    // Humanized guided setup defaults for role configuration.
    'guided_setup' => [
        'enabled' => true,
        'default_template' => 'general_assistant',
        'default_profile' => 'multi_project',
        'max_project_count' => 200,
        'profiles' => [
            [
                'code' => 'single_project',
                'name' => 'Single Project',
                'description' => 'Simple setup for one project with minimal overhead.',
            ],
            [
                'code' => 'multi_project',
                'name' => 'Multi Project',
                'description' => 'Recommended for teams managing many projects in parallel.',
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise Governance',
                'description' => 'Governance-first setup with safer defaults and clear approvals.',
            ],
        ],
        'default_model_config' => [
            'temperature' => 0.4,
            'max_tokens' => 4096,
        ],
        'steps' => [
            'Choose role template',
            'Describe business context and project count',
            'Generate AI suggestion and auto-fill fields',
            'Review advanced settings and save',
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
