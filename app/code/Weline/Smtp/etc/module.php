<?php

return [
    "name" => 'Weline_Smtp',
    "version" => '1.4.25',
    "requires" => [
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_EditorManager' => '*',
        'Weline_CKEditorEditorManager' => '*',
    ],
    "optional" => [
    ],
    "provides" => [
        \Weline\Smtp\Api\MailSenderInterface::class => \Weline\Smtp\Helper\SmtpSender::class,
    ],
];
