<?php

declare(strict_types=1);

use Weline\Newsletter\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

/**
 * Weline_Newsletter module extension points.
 */
return [
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'doc/README.md',
    'extends' => [],
];
