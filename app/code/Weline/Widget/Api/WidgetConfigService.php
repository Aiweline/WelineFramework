<?php

declare(strict_types=1);

namespace Weline\Widget\Api;

/**
 * Public name for Widget's configuration service.
 *
 * The exact alias preserves constructor and runtime identity while callers
 * migrate away from the internal Service namespace.
 */
if (!\class_exists(WidgetConfigService::class, false)) {
    \class_alias(\Weline\Widget\Service\WidgetConfigService::class, WidgetConfigService::class);
}
