<?php

return [
    "name" => 'Weline_Smtp',
    "version" => '1.4.79',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_I18n' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Theme' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Mail' => '*',
    ],
    "provides" => [
        \Weline\Smtp\Api\MailSenderInterface::class => \Weline\Smtp\Helper\SmtpSender::class,
    ],
];
