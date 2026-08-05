<?php

declare(strict_types=1);

use Weline\Theme\Service\Resumable\ThemePreviewBatchTaskHandler;
use Weline\Theme\Service\Resumable\ThemeConfigTranslationTaskHandler;

$tasks = [
    'theme.preview_batch' => [
        'handler' => ThemePreviewBatchTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Theme::theme_list',
    ],
];

// Weline_Ai is optional for Theme. Do not make the runtime registry fail to
// load Theme on installations that intentionally omit its AI integration.
if (interface_exists(\Weline\Ai\Api\AiRuntimeInterface::class)) {
    $tasks['theme.config_translation'] = [
        'handler' => ThemeConfigTranslationTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Theme::theme_visual_editor',
    ];
    $tasks['theme.virtual_theme_generation'] = [
        'handler' => \Weline\Theme\Service\Resumable\ThemeVirtualThemeGenerationTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Theme::theme_list',
    ];
    $tasks['theme.component_generate'] = [
        'handler' => \Weline\Theme\Service\Resumable\ThemeComponentGenerateTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Theme::theme_visual_editor',
    ];
    $tasks['theme.component_refine'] = [
        'handler' => \Weline\Theme\Service\Resumable\ThemeComponentRefineTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Theme::theme_visual_editor',
    ];
}

return $tasks;
