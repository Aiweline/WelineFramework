<?php

declare(strict_types=1);

namespace Weline\Eav\Api;

/**
 * Public name for custom EAV attribute input models.
 */
if (!\interface_exists(EavModelInterface::class, false)) {
    \class_alias(\Weline\Eav\EavModelInterface::class, EavModelInterface::class);
}
