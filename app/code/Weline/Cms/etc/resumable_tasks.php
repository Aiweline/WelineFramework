<?php

declare(strict_types=1);

use Weline\Cms\Service\Resumable\PageTranslationTaskHandler;

return [
    PageTranslationTaskHandler::TYPE_CODE => [
        'handler' => PageTranslationTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_Cms::page_save',
    ],
];
