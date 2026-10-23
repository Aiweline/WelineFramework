<?php

declare(strict_types=1);

namespace Weline\MediaManager\Setup\Release;

use Weline\Database\Domain\Release\SchemaScope;
use Weline\Database\Domain\Release\ServiceDataTransform;
use Weline\Framework\Database\ConnectionFactory;
use Weline\MediaManager\Service\AiDrawModelBinder;

/** Replaces legacy migration bind_media_manager_ai_draw_to_current_text2image_20260708-v1.0.0. */
final class BindAiDrawModelTransform extends ServiceDataTransform
{
    public function __construct(AiDrawModelBinder $binder, ConnectionFactory $connectionFactory)
    {
        $config = $connectionFactory->getConfigProvider();

        parent::__construct(
            'media_manager.bind_ai_draw_model',
            new SchemaScope($config->getConnectionName(), $config->getDatabase(), ''),
            '',
            '1.0.0',
            '1.0.0',
            static fn (): array => $binder->bindIfNeeded(),
        );
    }
}
