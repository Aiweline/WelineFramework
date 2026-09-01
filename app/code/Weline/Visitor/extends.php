<?php
declare(strict_types=1);

use Weline\Backend\Api\NotificationTopicProviderInterface;
use Weline\Visitor\Extends\NotificationTopicProvider;

/**
 * Weline_Visitor module extension contracts.
 */
return [
    NotificationTopicProviderInterface::class => [
        NotificationTopicProvider::class,
    ],
    'type' => 'module',
    'documentation' => 'extends.md',
    'extends' => [],
];
