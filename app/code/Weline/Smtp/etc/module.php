<?php

return [
    "name" => 'Weline_Smtp',
    "version" => '1.3.9',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_SystemConfig' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Smtp\Api\MailSenderInterface::class => \Weline\Smtp\Helper\SmtpSender::class,
    ],
];
