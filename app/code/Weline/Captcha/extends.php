<?php

declare(strict_types=1);

use Weline\SiteSetupAssistant\Api\SetupTaskProviderInterface;
use Weline\Captcha\Extends\Module\Weline_SiteSetupAssistant\SetupTask\CaptchaSetupTaskProvider;

return [
    SetupTaskProviderInterface::class => [
        CaptchaSetupTaskProvider::class,
    ],
];
