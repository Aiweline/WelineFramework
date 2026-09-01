<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;

/**
 * Facade projector for scoped Theme resources.
 *
 * Meta/Appearance/I18n/binding load+project are delegated to dedicated collaborators.
 * This class retains layout payload assert/compile orchestration only.
 * Layout authority is theme_scope_workspace / release only — no theme_layout row IO.
 */
final class ThemeScopedResourceProjector implements ThemeScopedResourceAdapterInterface
{
    public function __construct(
        private readonly ThemeNodePlacementResolver $placements,
        private readonly ThemeScopedProjectionSupport $support,
        private readonly ThemeScopedMetaProjector $metaProjector,
        private readonly ThemeScopedAppearanceProjector $appearanceProjector,
        private readonly ThemeScopedI18nProjector $i18nProjector,
        private readonly ThemeScopedBindingProjector $bindingProjector,
    ) {
    }

    public function loadBase(ThemeEditorContext $context): array
    {
        return match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => $this->bindingProjector->load($context),
            ThemeEditorContext::RESOURCE_LAYOUT => [
                'theme_id' => $context->themeId,
                'nodes' => [],
                'selection' => ['layout_option' => $context->layoutOption],
            ],
            ThemeEditorContext::RESOURCE_META => ['values' => []],
            ThemeEditorContext::RESOURCE_APPEARANCE => ['tokens' => [], 'disks' => [], 'brand' => []],
            ThemeEditorContext::RESOURCE_I18N => ['translations' => []],
            default => [],
        };
    }

    public function loadLegacyPublished(ThemeEditorContext $context): array
    {
        return match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => $this->bindingProjector->load($context),
            ThemeEditorContext::RESOURCE_LAYOUT => $this->loadLegacyLayout($context),
            ThemeEditorContext::RESOURCE_META => $this->metaProjector->load($context),
            ThemeEditorContext::RESOURCE_APPEARANCE => $this->appearanceProjector->load($context),
            ThemeEditorContext::RESOURCE_I18N => $this->i18nProjector->load($context),
            default => [],
        };
    }

    public function compile(ThemeEditorContext $context, array $effectivePayload): array
    {
        $this->assertPayload($context, $effectivePayload);
        $payload = $this->canonicalize($effectivePayload);
        $encoded = \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'artifact' => [
                'schema' => 'theme-scoped-resource.v1',
                'resource_type' => $context->resourceType,
                'fingerprint' => \hash('sha256', $encoded),
                'compiled_at' => \date(DATE_ATOM),
            ],
        ];
    }

    public function projectPublished(ThemeEditorContext $context, array $effectivePayload, int $releaseId): void
    {
        unset($releaseId);
        match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_THEME_BINDING => $this->bindingProjector->project($context, $effectivePayload),
            ThemeEditorContext::RESOURCE_META => $this->metaProjector->project($context, $effectivePayload),
            ThemeEditorContext::RESOURCE_APPEARANCE => $this->appearanceProjector->project($context, $effectivePayload),
            ThemeEditorContext::RESOURCE_I18N => $this->i18nProjector->project($context, $effectivePayload),
            default => null,
        };
    }

    public function projectDraft(ThemeEditorContext $context, array $effectivePayload): void
    {
        // Layout draft authority lives in theme_scope_workspace only.
        unset($context, $effectivePayload);
    }

    /** @param array<string,mixed> $payload */
    private function assertPayload(ThemeEditorContext $context, array $payload): void
    {
        if ($context->resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
            $this->bindingProjector->assertPayload($context, $payload);

            return;
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_LAYOUT) {
            if ((int)($payload['theme_id'] ?? $context->themeId) !== $context->themeId
                || !\is_array($payload['nodes'] ?? null)
                || !\is_array($payload['selection'] ?? null)
            ) {
                throw new \InvalidArgumentException('theme_layout_payload_invalid');
            }
            foreach ($payload['nodes'] as $uid => $node) {
                $uid = \strtolower((string)$uid);
                if (\preg_match('/^[a-f0-9]{32}$/D', $uid) !== 1
                    || !\is_array($node)
                    || \strtolower((string)($node['node_uid'] ?? '')) !== $uid
                ) {
                    throw new \InvalidArgumentException('theme_layout_payload_node_invalid');
                }
            }
            $this->placements->materialize($payload['nodes']);

            return;
        }
        $requiredRoot = match ($context->resourceType) {
            ThemeEditorContext::RESOURCE_META => 'values',
            ThemeEditorContext::RESOURCE_I18N => 'translations',
            default => null,
        };
        if ($requiredRoot !== null && !\is_array($payload[$requiredRoot] ?? null)) {
            throw new \InvalidArgumentException('theme_scoped_payload_root_invalid:' . $requiredRoot);
        }
        if ($context->resourceType === ThemeEditorContext::RESOURCE_APPEARANCE
            && (!\is_array($payload['tokens'] ?? null)
                || !\is_array($payload['disks'] ?? null)
                || (isset($payload['brand']) && !\is_array($payload['brand'])))
        ) {
            throw new \InvalidArgumentException('theme_scoped_payload_root_invalid:appearance');
        }
    }

    /** @return array{theme_id:int,nodes:array<string,array<string,mixed>>,selection:array<string,mixed>} */
    private function loadLegacyLayout(ThemeEditorContext $context): array
    {
        // Greenfield: theme_layout is not an authority. Always return empty nodes.
        $themeId = $context->themeId > 0 ? $context->themeId : $this->support->activeThemeId($context->area);

        return [
            'theme_id' => $themeId > 0 ? $themeId : 0,
            'nodes' => [],
            'selection' => ['layout_option' => $context->layoutOption],
        ];
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }

        return $value;
    }
}
