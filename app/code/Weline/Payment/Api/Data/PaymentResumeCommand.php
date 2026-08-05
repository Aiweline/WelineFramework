<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

final class PaymentResumeCommand extends AbstractPaymentData
{
    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_ATTEMPT_CODE = 'attempt_code';
    public const FIELD_IDEMPOTENCY_KEY = 'idempotency_key';
    public const FIELD_ACTOR = 'actor';

    public static function create(
        string $intentCode,
        string $idempotencyKey,
        ?string $attemptCode = null,
        ?Actor $actor = null,
    ): self {
        return self::fromArray([
            self::FIELD_INTENT_CODE => $intentCode,
            self::FIELD_ATTEMPT_CODE => $attemptCode,
            self::FIELD_IDEMPOTENCY_KEY => $idempotencyKey,
            self::FIELD_ACTOR => $actor,
        ]);
    }

    public function getIntentCode(): string
    {
        return trim($this->getString(self::FIELD_INTENT_CODE));
    }

    public function getAttemptCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ATTEMPT_CODE);
    }

    public function getIdempotencyKey(): string
    {
        return trim($this->getString(self::FIELD_IDEMPOTENCY_KEY));
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
