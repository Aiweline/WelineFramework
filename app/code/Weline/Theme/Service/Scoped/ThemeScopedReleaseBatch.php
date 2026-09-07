<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;

/**
 * Immutable optimistic-lock snapshot for one five-resource Theme release.
 *
 * The server derives every resource context from one canonical base context;
 * callers can provide revision claims, but cannot mix scopes or selectors.
 */
final readonly class ThemeScopedReleaseBatch
{
    /** @param list<array{resource_type:string,context:ThemeEditorContext,expected_revision:int,expected_parent_release_id:?int}> $items */
    private function __construct(
        private ThemeEditorContext $baseContext,
        private array $items,
        private string $digest,
    ) {
    }

    /**
     * @param array<string,array{expected_revision:mixed,expected_parent_release_id?:mixed}> $expectations
     */
    public static function fromExpectations(ThemeEditorContext $baseContext, array $expectations): self
    {
        $provided = \array_keys($expectations);
        $missing = \array_values(\array_diff(ThemeEditorContext::RESOURCES, $provided));
        $unknown = \array_values(\array_diff($provided, ThemeEditorContext::RESOURCES));
        if ($missing !== [] || $unknown !== [] || \count($provided) !== \count(ThemeEditorContext::RESOURCES)) {
            throw new \InvalidArgumentException('theme_scope_release_batch_resource_set_incomplete');
        }

        $items = [];
        foreach (ThemeEditorContext::RESOURCES as $resourceType) {
            $claim = $expectations[$resourceType] ?? null;
            if (!\is_array($claim) || !\array_key_exists('expected_revision', $claim)) {
                throw new \InvalidArgumentException('theme_scope_release_batch_revision_required');
            }
            $revision = $claim['expected_revision'];
            if (!\is_int($revision) || $revision < 0) {
                throw new \InvalidArgumentException('theme_scope_release_batch_revision_invalid');
            }
            $parentReleaseId = $claim['expected_parent_release_id'] ?? null;
            if ($parentReleaseId !== null && (!\is_int($parentReleaseId) || $parentReleaseId <= 0)) {
                throw new \InvalidArgumentException('theme_scope_release_batch_parent_release_invalid');
            }
            $items[] = [
                'resource_type' => $resourceType,
                'context' => $baseContext->withResource($resourceType),
                'expected_revision' => $revision,
                'expected_parent_release_id' => $parentReleaseId,
            ];
        }

        $digestPayload = \array_map(
            static fn(array $item): array => [
                'resource_type' => $item['resource_type'],
                'context' => $item['context']->toArray(),
                'expected_revision' => $item['expected_revision'],
                'expected_parent_release_id' => $item['expected_parent_release_id'],
            ],
            $items,
        );
        $encoded = \json_encode(
            $digestPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new self($baseContext, $items, \hash('sha256', $encoded));
    }

    /** @return list<array{resource_type:string,context:ThemeEditorContext,expected_revision:int,expected_parent_release_id:?int}> */
    public function items(): array
    {
        return $this->items;
    }

    public function baseContext(): ThemeEditorContext
    {
        return $this->baseContext;
    }

    public function digest(): string
    {
        return $this->digest;
    }
}
