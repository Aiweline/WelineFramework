<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeVirtualLayout;

/**
 * 在候选目标中按优先级选择第一个 Provider 确认有效的「类型 + ID」对。
 */
final class ThemeTargetIdentityResolver
{
    public function __construct(
        private readonly ThemeTargetTypeRegistry $targetTypeRegistry,
    ) {
    }

    /**
     * @param list<array{target_type:mixed,target_id:mixed}> $candidates
     * @return array{0:string,1:int}
     */
    public function resolveFirst(array $candidates, bool $allowGlobal = false): array
    {
        foreach ($candidates as $candidate) {
            $targetType = \strtolower(\trim((string)($candidate['target_type'] ?? '')));
            $targetId = $this->parseExplicitId($candidate['target_id'] ?? null);
            if ($targetType === '' || $targetId === null) {
                continue;
            }
            if (!$allowGlobal && $targetType === ThemeVirtualLayout::TARGET_GLOBAL) {
                continue;
            }
            if ($this->targetTypeRegistry->isValidTarget($targetType, $targetId)) {
                return [$targetType, $targetId];
            }
        }

        return ['', 0];
    }

    private function parseExplicitId(mixed $rawTargetId): ?int
    {
        if (\is_int($rawTargetId)) {
            return $rawTargetId;
        }
        if (!\is_string($rawTargetId)) {
            return null;
        }

        $rawTargetId = \trim($rawTargetId);
        if ($rawTargetId === '' || !\preg_match('/^-?\d+$/D', $rawTargetId)) {
            return null;
        }

        return (int)$rawTargetId;
    }
}
