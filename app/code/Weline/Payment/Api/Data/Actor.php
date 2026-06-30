<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class Actor extends AbstractPaymentData
{
    public const FIELD_ACTOR_TYPE = 'actor_type';
    public const FIELD_ACTOR_ID = 'actor_id';
    public const FIELD_PERMISSIONS = 'permissions';

    public function getActorType(): string
    {
        return $this->getString(self::FIELD_ACTOR_TYPE);
    }

    public function getActorId(): string
    {
        return $this->getString(self::FIELD_ACTOR_ID);
    }

    /**
     * @return string[]
     */
    public function getPermissions(): array
    {
        return array_values(array_filter($this->getArray(self::FIELD_PERMISSIONS), 'is_string'));
    }
}
