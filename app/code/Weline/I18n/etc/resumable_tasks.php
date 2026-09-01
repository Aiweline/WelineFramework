<?php

declare(strict_types=1);

use Weline\I18n\Service\Resumable\TaglibLocalBulkTranslationTaskHandler;

return [
    TaglibLocalBulkTranslationTaskHandler::TYPE_CODE => [
        'handler' => TaglibLocalBulkTranslationTaskHandler::class,
        'areas' => ['backend'],
        'backend_acl' => 'Weline_I18n::i18n_dictionaries',
    ],
];
