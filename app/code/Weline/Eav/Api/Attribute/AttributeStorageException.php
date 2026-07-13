<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Attribute;

final class AttributeStorageException extends \RuntimeException
{
    public const ENTITY_NOT_REGISTERED = 'entity_not_registered';
    public const TYPE_NOT_FOUND = 'type_not_found';
    public const SET_NOT_FOUND = 'set_not_found';
    public const GROUP_NOT_FOUND = 'group_not_found';
    public const ATTRIBUTE_ID_MISSING = 'attribute_id_missing';

    public function __construct(
        public readonly string $reason,
        public readonly string $resourceCode,
    ) {
        parent::__construct($reason . ':' . $resourceCode);
    }
}
