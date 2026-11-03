<?php

return [
    "name" => 'Weline_Mail',
    "version" => '0.2.52',
    "requires" => [
        'Weline_Acl' => '*',
        'Weline_Admin' => '*',
        'Weline_Backend' => '*',
        'Weline_Cron' => '*',
        'Weline_Cdn' => '*',
        'Weline_Customer' => '*',
        'Weline_Queue' => '*',
        'Weline_Smtp' => '*',
        'Weline_SystemConfig' => '*',
        'Weline_Websites' => '*',
    ],
    "optional" => [
        'Weline_Server' => '*',
    ],
    "provides" => [
        \Weline\Mail\Api\MailCompositionInterface::class
            => \Weline\Mail\Service\MailComposition::class,
        \Weline\Mail\Api\MailMessageLookupInterface::class
            => \Weline\Mail\Service\MailMessageLookup::class,
        \Weline\Mail\Api\MailAccountDirectoryInterface::class
            => \Weline\Mail\Service\MailAccountDirectory::class,
        \Weline\Mail\Api\Relay\RelaySecretInterface::class
            => \Weline\Mail\Service\Relay\RelaySecret::class,
        \Weline\Mail\Api\Stalwart\StalwartAdminInterface::class
            => \Weline\Mail\Service\Stalwart\StalwartAdmin::class,
        \Weline\Mail\Api\Domain\SelfHostedDomainDirectoryInterface::class
            => \Weline\Mail\Service\Domain\SelfHostedDomainDirectory::class,
    ],
];
