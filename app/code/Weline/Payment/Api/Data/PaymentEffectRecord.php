<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Data;

/**
 * Immutable Payment outbox projection exposed to downstream effect handlers.
 */
final readonly class PaymentEffectRecord
{
    public function __construct(
        public string $outboxCode,
        public string $effectKey,
        public string $intentCode,
        public string $attemptCode,
        public string $effectType,
        public string $payableType,
        public string $payableId,
        public string $schemaVersion,
    ) {
        foreach ([
            'outbox_code' => $this->outboxCode,
            'effect_key' => $this->effectKey,
            'effect_type' => $this->effectType,
            'payable_type' => $this->payableType,
            'payable_id' => $this->payableId,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('payment_effect_' . $field . '_required');
            }
        }
        if ($this->schemaVersion !== '1') {
            throw new \InvalidArgumentException('payment_effect_schema_version_unsupported');
        }
        $expectedKey = self::buildKey(
            $this->intentCode,
            $this->attemptCode,
            $this->effectType,
        );
        if (!hash_equals($expectedKey, $this->effectKey)) {
            throw new \InvalidArgumentException('payment_effect_key_mismatch');
        }
    }

    public static function buildKey(
        string $intentCode,
        string $attemptCode,
        string $effectType,
    ): string {
        $attemptCode = trim($attemptCode);
        $subjectType = $attemptCode !== '' ? 'attempt' : 'intent';
        $subjectCode = $attemptCode !== '' ? $attemptCode : trim($intentCode);
        if ($subjectCode === '') {
            throw new \InvalidArgumentException('payment_effect_subject_required');
        }
        $effectType = trim($effectType);
        if ($effectType === '') {
            throw new \InvalidArgumentException('payment_effect_effect_type_required');
        }

        return $subjectType . ':' . $subjectCode . ':' . $effectType;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'outbox_code' => $this->outboxCode,
            'effect_key' => $this->effectKey,
            'intent_code' => $this->intentCode,
            'attempt_code' => $this->attemptCode,
            'effect_type' => $this->effectType,
            'payable_type' => $this->payableType,
            'payable_id' => $this->payableId,
            'schema_version' => $this->schemaVersion,
        ];
    }
}
