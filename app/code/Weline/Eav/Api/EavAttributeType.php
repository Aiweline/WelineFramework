<?php

declare(strict_types=1);

namespace Weline\Eav\Api;

/**
 * Public name for EAV attribute type metadata.
 */
if (!\class_exists(EavAttributeType::class, false)) {
    \class_alias(\Weline\Eav\Model\EavAttribute\Type::class, EavAttributeType::class);
}
