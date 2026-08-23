<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

/** Server-authoritative boundary for Theme Editor typed contexts. */
final class ThemeEditorContextFactory
{
    public function __construct(
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeTargetTypeRegistry $targetTypes,
        private readonly ThemeScopedWorkspaceInterface $workspaces,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function fromInput(array $input, ?string $forcedResourceType = null): ThemeEditorContext
    {
        $raw = $input['editor_context'] ?? $input;
        if (\is_string($raw)) {
            $raw = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('theme_editor_context_required');
        }

        $scope = $raw['scope'] ?? null;
        if (\is_string($scope)) {
            $scope = \json_decode($scope, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($scope)) {
            throw new \InvalidArgumentException('theme_editor_typed_scope_required');
        }
        $claims = \is_array($scope['identity'] ?? null) ? $scope['identity'] : $scope;
        $candidate = ScopeIdentity::fromArray($claims);
        $authoritative = $this->catalog->authoritativeIdentity($candidate);
        $scopeContext = $this->scopes->contextFromClaims($claims, $authoritative);

        $resourceType = $forcedResourceType ?? (string)($raw['resource_type'] ?? ThemeEditorContext::RESOURCE_LAYOUT);
        if (!\in_array($resourceType, ThemeEditorContext::RESOURCES, true)) {
            throw new \InvalidArgumentException('theme_editor_context_resource_invalid');
        }
        $area = \strtolower(\trim((string)($raw['area'] ?? $raw['editor_area'] ?? 'frontend')));
        $themeId = $this->nonNegativeInt($raw['theme_id'] ?? 0, 'theme_id');
        if ($resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING && $themeId <= 0) {
            throw new \InvalidArgumentException('theme_editor_context_theme_required');
        }
        if ($themeId > 0) {
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load($themeId);
            if ((int)$theme->getId() !== $themeId) {
                throw new \InvalidArgumentException('theme_editor_context_theme_not_found');
            }
            if (!$this->themeContext->themeSupportsArea($theme, $area)) {
                throw new \InvalidArgumentException('theme_editor_context_theme_area_unsupported');
            }
        }
        if ($resourceType !== ThemeEditorContext::RESOURCE_THEME_BINDING) {
            $binding = $this->workspaces->load(new ThemeEditorContext(
                scope: $scopeContext,
                area: $area,
                resourceType: ThemeEditorContext::RESOURCE_THEME_BINDING,
            ), true);
            $boundThemeId = (int)($binding['draft_payload']['theme_id'] ?? 0);
            if ($boundThemeId <= 0 || $themeId !== $boundThemeId) {
                throw new \InvalidArgumentException('theme_editor_context_theme_scope_mismatch');
            }
        }

        $layoutType = (string)($raw['layout_type'] ?? $raw['page_type'] ?? 'default');
        $layoutOption = (string)($raw['layout_option'] ?? 'default');
        $locale = (string)($raw['locale'] ?? 'default');
        $targetType = \strtolower(\trim((string)($raw['target_type'] ?? 'global')));
        $targetId = $this->nonNegativeInt($raw['target_id'] ?? 0, 'target_id');
        if (\in_array($resourceType, [
            ThemeEditorContext::RESOURCE_THEME_BINDING,
            ThemeEditorContext::RESOURCE_APPEARANCE,
        ], true)) {
            $layoutType = 'default';
            $layoutOption = 'default';
            $locale = 'default';
            $targetType = 'global';
            $targetId = 0;
        } elseif ($resourceType === ThemeEditorContext::RESOURCE_META) {
            $locale = 'default';
        }
        $provider = $this->targetTypes->get($targetType);
        if ($provider === null
            || !$provider->canUseLayoutType($layoutType)
            || !$this->targetTypes->isValidTarget($targetType, $targetId, [
                'area' => $area,
                'layout_type' => $layoutType,
                'layout_option' => $layoutOption,
                'locale' => $locale,
                'scope' => $scopeContext->toArray(),
            ])
        ) {
            throw new \InvalidArgumentException('theme_editor_context_target_invalid');
        }

        return new ThemeEditorContext(
            scope: $scopeContext,
            area: $area,
            resourceType: $resourceType,
            themeId: $resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING ? 0 : $themeId,
            layoutType: $layoutType,
            layoutOption: $layoutOption,
            locale: $locale,
            targetType: $targetType,
            targetId: $targetId,
        );
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (\is_string($value) && \preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $value = (int)$value;
        }
        if (!\is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('theme_editor_context_' . $field . '_invalid');
        }

        return $value;
    }
}
