<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/** Freezes request identity into data that detached MediaManager work can replay safely. */
final class MediaFileAccessContextFactory
{
    public const INPUT_KEY = 'file_access_context';

    public function __construct(private readonly FileAssetLibraryInterface $assets)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function freeze(array $input, int $actorId): array
    {
        if ($actorId < 1) {
            throw new \InvalidArgumentException((string)__('媒体文件操作缺少明确操作人。'));
        }
        $locale = $this->assets->normalizeLocale(trim((string)($input['locale_code'] ?? '')));
        $scope = RequestContext::scopeIdentity() ?? ScopeIdentity::global();
        $input[self::INPUT_KEY] = [
            'scope_identity' => $scope->toArray(),
            'locale_code' => $locale,
            'actor_id' => $actorId,
            'purpose' => 'media_manager',
            'policy_revision' => 1,
        ];
        $input['locale_code'] = $locale;

        return $input;
    }

    /** @param array<string,mixed> $input */
    public function fromFrozen(array $input, int $expectedActorId): FileAccessContext
    {
        $record = $input[self::INPUT_KEY] ?? null;
        if (!is_array($record)
            || !is_array($record['scope_identity'] ?? null)
            || !is_int($record['actor_id'] ?? null)
            || (int)$record['actor_id'] !== $expectedActorId
            || $expectedActorId < 1
            || ($record['purpose'] ?? null) !== 'media_manager'
            || (int)($record['policy_revision'] ?? 0) !== 1
        ) {
            throw new \RuntimeException((string)__('媒体文件访问上下文未冻结或与任务所有者不匹配。'));
        }
        $locale = $this->assets->normalizeLocale((string)($record['locale_code'] ?? ''));
        if (!hash_equals($locale, $this->assets->normalizeLocale((string)($input['locale_code'] ?? '')))) {
            throw new \RuntimeException((string)__('媒体文件语言与冻结上下文不匹配。'));
        }

        return new FileAccessContext(
            ScopeIdentity::fromArray($record['scope_identity']),
            $locale,
            $expectedActorId,
            [],
            'media_manager',
            1,
        );
    }
}
