<?php

declare(strict_types=1);

namespace Weline\Acl\Api\Authorization;

/** 对象 Scope 授权判定结果（内部诊断码不得直接回传给越权调用方）。 */
final readonly class ObjectAuthorizationResult
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public int $matchedGrantVersion = 0,
    ) {
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason, 0);
    }

    public static function allow(string $reason, int $grantVersion): self
    {
        return new self(true, $reason, $grantVersion);
    }
}
