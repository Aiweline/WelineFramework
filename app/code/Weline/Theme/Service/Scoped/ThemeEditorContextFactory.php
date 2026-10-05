<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Theme\Api\Scoped\ThemeContentScope;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeTargetTypeRegistry;

/** Server-authoritative boundary for Theme Editor typed contexts. */
final class ThemeEditorContextFactory
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeTargetTypeRegistry $targetTypes,
        private readonly ThemeScopedWorkspaceInterface $workspaces,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function fromInput(array $input, ?string $forcedResourceType = null, ?ThemeApplicationContext $application = null): ThemeEditorContext
    {
        $raw = $input['editor_context'] ?? $input;
        if (\is_string($raw)) {
            $raw = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('theme_editor_context_required');
        }

        $area = \strtolower(\trim((string)($raw['area'] ?? $raw['editor_area'] ?? 'frontend')));
        $application ??= ThemeApplicationContext::current($area, 'editor')
            ?? ThemeApplicationContext::current($area, 'preview')
            ?? ThemeApplicationContext::current($area, 'asset');
        if ($application === null || $application->area !== $area
            || !in_array($application->purpose, ['editor', 'preview', 'asset'], true)) {
            throw new \InvalidArgumentException('theme_editor_consumer_context_required');
        }
        $scopeContext = ThemeContentScope::fromApplication($application);
        $scope = $raw['scope'] ?? null;
        if (\is_string($scope)) {
            $scope = \json_decode($scope, true, flags: JSON_THROW_ON_ERROR);
        }
        if (!\is_array($scope)) {
            throw new \InvalidArgumentException('theme_editor_typed_scope_required');
        }
        // 范围权限由使用方校验；客户端字段只能匹配既定身份，不能决定编辑对象。
        if (($scope['storage_scope'] ?? '') !== $scopeContext->storageScope
            || (isset($scope['store_mode']) && $scope['store_mode'] !== $scopeContext->storeMode)
            || (isset($scope['provider']) && $scope['provider'] !== $scopeContext->provider)) {
            throw new \InvalidArgumentException('theme_editor_consumer_scope_mismatch');
        }

        $resourceType = $forcedResourceType ?? (string)($raw['resource_type'] ?? ThemeEditorContext::RESOURCE_LAYOUT);
        if (!\in_array($resourceType, ThemeEditorContext::RESOURCES, true)) {
            throw new \InvalidArgumentException('theme_editor_context_resource_invalid');
        }
        $themeId = $this->nonNegativeInt($raw['theme_id'] ?? 0, 'theme_id');
        // theme_binding keeps resource theme_id=0; the selected Theme lives on the
        // installed ThemeApplicationContext (outer request theme_id / asset purpose).
        if ($resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING) {
            if ($themeId !== 0 && $themeId !== $application->themeId) {
                throw new \InvalidArgumentException('theme_editor_consumer_theme_mismatch');
            }
            if ($application->themeId < 1) {
                throw new \InvalidArgumentException('theme_editor_context_theme_required');
            }
            $themeId = 0;
        } else {
            if ($themeId !== $application->themeId) {
                throw new \InvalidArgumentException('theme_editor_consumer_theme_mismatch');
            }
            if ($themeId <= 0) {
                throw new \InvalidArgumentException('theme_editor_context_theme_required');
            }
        }
        $selectedThemeId = $resourceType === ThemeEditorContext::RESOURCE_THEME_BINDING
            ? $application->themeId
            : $themeId;
        if ($selectedThemeId > 0) {
            $theme = clone $this->themes;
            $theme->clearData()->clearQuery()->load($selectedThemeId);
            if ((int)$theme->getId() !== $selectedThemeId) {
                throw new \InvalidArgumentException('theme_editor_context_theme_not_found');
            }
            if (!$this->themeContext->themeSupportsArea($theme, $area)) {
                throw new \InvalidArgumentException('theme_editor_context_theme_area_unsupported');
            }
        }
        // Layout, meta, appearance and i18n are stored per theme_id inside the scope.
        // The draft binding only selects which theme the website currently runs.
        // Editing theme A must succeed while that binding is still theme B.

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
            application: $application,
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
