<?php

return [
    "name" => 'Weline_Smtp',
    "version" => '1.4.62',
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
