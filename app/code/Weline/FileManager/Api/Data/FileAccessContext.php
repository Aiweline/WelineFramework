<?php

declare(strict_types=1);

namespace Weline\FileManager\Api\Data;

use Weline\Framework\Runtime\ScopeIdentity;

final readonly class FileAccessContext
{
    public const PURPOSE_PUBLIC_PUBLISH = 'public_publish';

    /** @param list<string> $roles */
    public function __construct(
        public ScopeIdentity $scope,
        public string $localeCode,
        public ?int $actorId = null,
        public array $roles = [],
        public string $purpose = 'render',
        public int $policyRevision = 1,
    ) {
        if (
            preg_match('/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_(?:[A-Z]{2}|[0-9]{3}))?$/', $localeCode) !== 1
            || strlen($localeCode) > 16
            || ($actorId !== null && $actorId < 1)
            || count($roles) > 64
            || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $purpose) !== 1
            || $policyRevision < 1
        ) {
            throw new \InvalidArgumentException((string)__('文件访问上下文无效。'));
        }
        foreach ($roles as $role) {
            if (!is_string($role)
                || trim($role) === ''
                || strlen($role) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $role) === 1
            ) {
                throw new \InvalidArgumentException((string)__('文件访问上下文角色无效。'));
            }
        }
    }
}
