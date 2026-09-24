<?php

return [
    "name" => 'Weline_Captcha',
    "version" => '1.0.54',
    "requires" => [
        'Weline_Framework' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Captcha\Api\CaptchaManagerInterface::class => \Weline\Captcha\Service\CaptchaManager::class,
    ],
];
