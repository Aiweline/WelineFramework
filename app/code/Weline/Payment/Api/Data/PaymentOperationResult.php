<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/**
 * Sanitized payment operation result — no secrets / raw Provider payloads.
 */
final class PaymentOperationResult extends AbstractPaymentData
{
    public const NEXT_NONE = 'none';
    public const NEXT_REDIRECT = 'redirect';
    public const NEXT_QR = 'qr';
    public const NEXT_SDK = 'sdk';
    public const NEXT_POLL = 'poll';

    public const FIELD_INTENT_CODE = 'intent_code';
    public const FIELD_ATTEMPT_CODE = 'attempt_code';
    public const FIELD_STATUS = 'status';
    public const FIELD_TERMINAL = 'terminal';
    public const FIELD_NEXT_ACTION_TYPE = 'next_action_type';
    public const FIELD_NEXT_ACTION = 'next_action';
    public const FIELD_RETRY_AFTER_SECONDS = 'retry_after_seconds';
    public const FIELD_ERROR_CODE = 'error_code';
    public const FIELD_SNAPSHOT_VERSION = 'snapshot_version';
    public const FIELD_AMOUNT_MINOR = 'amount_minor';
    public const FIELD_CURRENCY_CODE = 'currency_code';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_MERCHANT_ACCOUNT = 'merchant_account';
    public const FIELD_PAYABLE_TYPE = 'payable_type';
    public const FIELD_PAYABLE_ID = 'payable_id';

    /**
     * @param array<string, mixed> $nextAction
     * @param array<string, mixed> $scope
     */
    public static function create(
        ?string $intentCode,
        ?string $attemptCode,
        string $status,
        bool $terminal,
        string $nextActionType = self::NEXT_NONE,
        array $nextAction = [],
        ?int $retryAfterSeconds = null,
        ?string $errorCode = null,
        ?string $snapshotVersion = null,
        ?int $amountMinor = null,
        ?string $currencyCode = null,
        array $scope = [],
        ?string $merchantAccount = null,
        ?string $payableType = null,
        ?string $payableId = null,
    ): self {
        return self::fromArray([
            self::FIELD_INTENT_CODE => $intentCode,
            self::FIELD_ATTEMPT_CODE => $attemptCode,
            self::FIELD_STATUS => $status,
            self::FIELD_TERMINAL => $terminal,
            self::FIELD_NEXT_ACTION_TYPE => $nextActionType,
            self::FIELD_NEXT_ACTION => $nextAction,
            self::FIELD_RETRY_AFTER_SECONDS => $retryAfterSeconds,
            self::FIELD_ERROR_CODE => $errorCode,
            self::FIELD_SNAPSHOT_VERSION => $snapshotVersion,
            self::FIELD_AMOUNT_MINOR => $amountMinor,
            self::FIELD_CURRENCY_CODE => $currencyCode,
            self::FIELD_SCOPE => $scope,
            self::FIELD_MERCHANT_ACCOUNT => $merchantAccount,
            self::FIELD_PAYABLE_TYPE => $payableType,
            self::FIELD_PAYABLE_ID => $payableId,
        ]);
    }

    public function getIntentCode(): ?string
    {
        return $this->getNullableString(self::FIELD_INTENT_CODE);
    }

    public function getAttemptCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ATTEMPT_CODE);
    }

    public function getStatus(): string
    {
        return $this->getString(self::FIELD_STATUS);
    }

    public function isTerminal(): bool
    {
        return $this->getBool(self::FIELD_TERMINAL);
    }

    public function getNextActionType(): string
    {
        return $this->getString(self::FIELD_NEXT_ACTION_TYPE, self::NEXT_NONE);
    }

    /**
     * @return array<string, mixed>
     */
    public function getNextAction(): array
    {
        return $this->getArray(self::FIELD_NEXT_ACTION);
    }

    public function getErrorCode(): ?string
    {
        return $this->getNullableString(self::FIELD_ERROR_CODE);
    }

    public function isOk(): bool
    {
        return $this->getErrorCode() === null;
    }
}
