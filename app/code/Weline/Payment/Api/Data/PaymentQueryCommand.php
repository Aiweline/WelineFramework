<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class PaymentQueryCommand extends AbstractPaymentData
{
    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';
    public const FIELD_ACTOR = 'actor';

    public static function byIntent(string $intentCode, ?Actor $actor = null): self
    {
        return self::fromArray([
            self::FIELD_INTENT_CODE => $intentCode,
            self::FIELD_ACTOR => $actor,
        ]);
    }

    public static function byPayable(string $payableType, string $payableId, ?Actor $actor = null): self
    {
        return self::fromArray([
            self::FIELD_PAYABLE_TYPE => $payableType,
            self::FIELD_PAYABLE_ID => $payableId,
            self::FIELD_ACTOR => $actor,
        ]);
    }

    public function getIntentCode(): ?string
    {
        return $this->getNullableString(self::FIELD_INTENT_CODE);
    }

    public function getPayableType(): ?string
    {
        $type = strtolower(trim($this->getString(self::FIELD_PAYABLE_TYPE)));

        return $type === '' ? null : $type;
    }

    public function getPayableId(): ?string
    {
        $id = trim($this->getString(self::FIELD_PAYABLE_ID));

        return $id === '' ? null : $id;
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
