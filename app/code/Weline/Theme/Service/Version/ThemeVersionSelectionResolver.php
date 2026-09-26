<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Version;

use Weline\Theme\Api\Version\ThemeVersionIdentity;

/**
 * Resolves published/draft selection identity for an owner.
 * Task 1 provides typed resolution helpers; Task 3 wires transactional writes.
 */
final class ThemeVersionSelectionResolver
{
    /**
     * @param array{
     *   published_version_id:int,
     *   draft_version_id?:?int,
     *   selection_revision?:int,
     *   published_content_revision?:int,
     *   draft_content_revision?:int
     * } $selection
     */
    public function resolvePublished(ThemeVersionIdentity $owner, array $selection): ThemeVersionIdentity
    {
        $publishedId = (int)($selection['published_version_id'] ?? 0);
        if ($publishedId < 1) {
            throw new \InvalidArgumentException('selection_published_missing');
        }

        return $owner->withVersion(
            $publishedId,
            ThemeVersionIdentity::MODE_FORMAL,
            (int)($selection['published_content_revision'] ?? 0),
        );
    }

    /**
     * @param array{
     *   published_version_id:int,
     *   draft_version_id?:?int,
     *   selection_revision?:int,
     *   published_content_revision?:int,
     *   draft_content_revision?:int
     * } $selection
     */
    public function resolveDraft(ThemeVersionIdentity $owner, array $selection): ?ThemeVersionIdentity
    {
        $draftId = $selection['draft_version_id'] ?? null;
        if ($draftId === null || $draftId === '' || (int)$draftId < 1) {
            return null;
        }

        return $owner->withVersion(
            (int)$draftId,
            ThemeVersionIdentity::MODE_DRAFT,
            (int)($selection['draft_content_revision'] ?? 0),
        );
    }

    /**
     * 链式解析已发布身份：按「本级 → 逐级祖先」顺序取第一个可用的 published 指针。
     *
     * 为什么草稿不做链式：草稿承载本级用户意图，跨 scope 继承会把祖先草稿
     * 伪装成本级 patch（违反「跨 Scope 不把祖先值伪装成子级用户 patch」）。
     * 已发布版本则可以合法回落 —— 「无本级正式覆盖者用祖先 owner」。
     *
     * @param list<array{scope:string,selection:?array<string,mixed>}> $candidates 由近到远
     * @return array{identity:ThemeVersionIdentity,source_scope:string,inherited:bool}|null
     */
    public function resolvePublishedChained(ThemeVersionIdentity $owner, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            $scope = \trim((string)($candidate['scope'] ?? ''));
            $selection = $candidate['selection'] ?? null;
            if ($scope === '' || !\is_array($selection)) {
                continue;
            }
            $publishedId = (int)($selection['published_version_id'] ?? 0);
            if ($publishedId < 1) {
                continue;
            }
            $scopeOwner = $owner->withOwnerScope($scope, $owner->storeMode);

            return [
                'identity' => $scopeOwner->withVersion(
                    $publishedId,
                    ThemeVersionIdentity::MODE_FORMAL,
                    (int)($selection['published_content_revision'] ?? 0),
                ),
                'source_scope' => $scope,
                'inherited' => $scope !== $owner->canonicalScope,
            ];
        }

        return null;
    }
}
