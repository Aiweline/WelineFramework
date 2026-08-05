<?php

declare(strict_types=1);

use Weline\MediaManager\Service\Resumable\AiDrawTaskHandler;

return [
    'media.ai_draw' => [
        'handler' => AiDrawTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_MediaManager::file_manager',
    ],
];
