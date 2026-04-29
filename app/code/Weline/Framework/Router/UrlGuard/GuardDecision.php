<?php

declare(strict_types=1);

/**
 * URL Guard 决策（值对象）
 *
 * 表达 Guard 对一次请求的判定结果，与具体 Guard 实现解耦。
 * 不可变；通过静态工厂方法构造，避免多步骤 setter 引入的不一致状态。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\UrlGuard;

final class GuardDecision
{
    public const STATUS_PASS = 'pass';
    public const STATUS_REJECT = 'reject';
    public const STATUS_SKIP = 'skip';

    /**
     * @param array<string, mixed> $details 诊断详情（如 expected_max、actual_value）
     */
    private function __construct(
        public readonly string $status,
        public readonly string $guardName,
        public readonly int $rejectStatusCode,
        public readonly string $reason,
        public readonly array $details
    ) {
    }

    public static function pass(string $guardName): self
    {
        return new self(self::STATUS_PASS, $guardName, 200, '', []);
    }

    public static function skip(string $guardName, string $reason = ''): self
    {
        return new self(self::STATUS_SKIP, $guardName, 200, $reason, []);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function reject(
        string $guardName,
        string $reason,
        int $statusCode = 410,
        array $details = []
    ): self {
        return new self(self::STATUS_REJECT, $guardName, $statusCode, $reason, $details);
    }

    public function isReject(): bool
    {
        return $this->status === self::STATUS_REJECT;
    }

    public function isPass(): bool
    {
        return $this->status === self::STATUS_PASS;
    }
}
