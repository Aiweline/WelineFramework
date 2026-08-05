<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 限流 key 必须含完整 Scope（TASK-P1D-004-RATE / TEST-P1D-06）。
 */
final class ScopeRateLimitKey
{
    public static function of(ScopeIdentity $scope, string $bucket, string $subject = ''): string
    {
        $bucket = self::normalizeBucket($bucket);
        $parts = [
            'rl',
            $scope->canonicalKey(),
            $bucket,
        ];
        $subject = \trim($subject);
        if ($subject !== '') {
            if (\strlen($subject) > 512 || \preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
                throw new \InvalidArgumentException('scope_rate_subject_invalid');
            }
            $parts[] = 'subject:' . \hash('sha256', $subject);
        }

        return \implode('|', $parts);
    }

    public static function normalizeBucket(string $bucket): string
    {
        $bucket = \strtolower(\trim($bucket));
        if (\preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $bucket) !== 1) {
            throw new \InvalidArgumentException('scope_rate_bucket_invalid');
        }

        return $bucket;
    }
}
