<?php

declare(strict_types=1);

return [
    'name' => 'Weline_Inquiry',
    'version' => '1.0.0',
    'requires' => [
        'Weline_Framework' => '*',
        'Weline_Backend' => '*',
        'Weline_I18n' => '*',
        'Weline_Taglib' => '*',
        'Weline_Widget' => '*',
        'Weline_SystemConfig' => '*',
    ],
    'optional' => [
        'Weline_Acl' => '*',
        'Weline_Captcha' => '*',
        'Weline_MediaManager' => '*',
    ],
    'provides' => [
        \Weline\Inquiry\Api\InquiryFormCatalogInterface::class => \Weline\Inquiry\Service\InquiryFormCatalog::class,
        \Weline\Inquiry\Api\InquiryRendererInterface::class => \Weline\Inquiry\Service\InquiryRenderer::class,
    ],
];
