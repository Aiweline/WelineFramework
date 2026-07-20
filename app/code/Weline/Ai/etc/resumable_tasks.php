<?php

declare(strict_types=1);

use Weline\Ai\Service\Resumable\ChatGenerationTaskHandler;

return [
    'ai.chat_generation' => [
        'handler' => ChatGenerationTaskHandler::class,
    ],
];
