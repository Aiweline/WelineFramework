<?php

declare(strict_types=1);

use Weline\B2B\Extends\MailChannelProvider;
use Weline\Smtp\Api\MailChannelProviderInterface;

/**
 * Weline_B2B module extension points.
 */
return [
    MailChannelProviderInterface::class => [
        MailChannelProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'doc/b2b.md',
    'extends' => [],
];
