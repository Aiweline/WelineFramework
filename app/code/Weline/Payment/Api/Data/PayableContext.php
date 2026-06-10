<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class PayableContext extends AbstractPaymentData
{
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_ACTOR = 'actor';

    public function getPayableType(): string
    {
        return $this->getString(self::FIELD_PAYABLE_TYPE);
    }

    public function getPayableId(): string
    {
        return $this->getString(self::FIELD_PAYABLE_ID);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->getArray(self::FIELD_PAYLOAD);
    }

    public function getActor(): ?Actor
    {
        $actor = $this->getData(self::FIELD_ACTOR);
        if ($actor instanceof Actor) {
            return $actor;
        }

        return \is_array($actor) ? Actor::fromArray($actor) : null;
    }
}
