<?php

declare(strict_types=1);

namespace Weline\Widget\Api;

/**
 * Public name for Widget's sanitized preview renderer.
 *
 * The exact alias keeps legacy Service-typed objects interchangeable during
 * the one-version namespace migration.
 */
if (!\class_exists(WidgetPreviewService::class, false)) {
    \class_alias(\Weline\Widget\Service\WidgetPreviewService::class, WidgetPreviewService::class);
}
