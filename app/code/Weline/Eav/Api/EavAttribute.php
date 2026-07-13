<?php

declare(strict_types=1);

namespace Weline\Eav\Api;

/**
 * Public name for the EAV attribute ORM model.
 *
 * This is an exact runtime alias so legacy attributes and API-typed attributes
 * remain interchangeable while the implementation stays owned by Weline_Eav.
 */
if (!\class_exists(EavAttribute::class, false)) {
    \class_alias(\Weline\Eav\Model\EavAttribute::class, EavAttribute::class);
}
