<?php

declare(strict_types=1);

namespace Weline\Ai\Exception;

use Weline\Framework\App\Exception;

/**
 * AI 供应商账户余额 / 配额 / 可用性类失败，携带稳定 billing_code 供上层分类。
 */
class AiBillingException extends Exception
{
    public const CODE_INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';

    public const CODE_PROVIDER_QUOTA_EXCEEDED = 'PROVIDER_QUOTA_EXCEEDED';

    public const CODE_PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';

    public function __construct(
        string $message,
        private readonly string $billingCode = self::CODE_INSUFFICIENT_BALANCE,
        int $code = 402,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getBillingCode(): string
    {
        return $this->billingCode;
    }

    public static function classifyMessageToCode(string $message, bool $requiresPositiveBalance = false): string
    {
        $lower = \mb_strtolower(\trim($message), 'UTF-8');
        if ($lower === '') {
            return '';
        }
        if (\str_contains($lower, 'http 402') || \str_contains($lower, 'insufficient balance') || \str_contains($message, '余额不足')) {
            return self::CODE_INSUFFICIENT_BALANCE;
        }
        if (\str_contains($lower, 'chat pre-consumed quota')
            || \str_contains($lower, 'user quota')
            || \str_contains($lower, 'need quota')
            || \str_contains($lower, 'pre-consumed quota')
            || \str_contains($message, '配额不足')
            || \str_contains($message, '额度不足')
        ) {
            return self::CODE_PROVIDER_QUOTA_EXCEEDED;
        }
        if ($requiresPositiveBalance || \str_contains($message, '余额>0')) {
            return self::CODE_INSUFFICIENT_BALANCE;
        }
        if (\str_contains($lower, '没有满足条件') && (
            \str_contains($message, '供应商')
            || \str_contains($lower, 'provider')
            || \str_contains($message, '余额')
        )) {
            return \str_contains($message, '余额') ? self::CODE_INSUFFICIENT_BALANCE : self::CODE_PROVIDER_UNAVAILABLE;
        }

        return '';
    }

    public static function fromMessage(string $message, bool $requiresPositiveBalance = false, ?\Throwable $previous = null): self
    {
        $code = self::classifyMessageToCode($message, $requiresPositiveBalance);
        if ($code === '') {
            $code = self::CODE_PROVIDER_UNAVAILABLE;
        }

        return new self($message, $code, 402, $previous);
    }
}
