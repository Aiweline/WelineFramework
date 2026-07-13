<?php

declare(strict_types=1);

namespace Weline\Eav\Api;

/**
 * Public name for the EAV entity ORM base.
 *
 * The alias intentionally keeps one runtime class and one ORM implementation;
 * consumers can depend on the Eav API namespace without wrapping or copying it.
 */
if (!\class_exists(EavModel::class, false)) {
    \class_alias(\Weline\Eav\EavModel::class, EavModel::class);
}
