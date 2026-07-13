<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * Public name for the AI translation application service.
 *
 * The exact alias preserves the legacy constructor and strategy constants while
 * optional callers migrate off the internal Service namespace.
 */
if (!\class_exists(TranslationService::class, false)) {
    \class_alias(\Weline\Ai\Service\TranslationService::class, TranslationService::class);
}
