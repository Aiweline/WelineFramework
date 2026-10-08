<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\WelineTheme;

/**
 * Dedicated theme-binding load/project collaborator for scoped Theme workspaces.
 */
final class ThemeScopedBindingProjector
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeScopedProjectionSupport $support,
        private readonly ScopeHierarchyInterface $scopes,
    ) {
    }

    /** @return array{theme_id:int} */
    public function load(ThemeEditorContext $context): array
    {
        return [
            'theme_id' => $this->support->activeThemeId($context->area),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function assertPayload(ThemeEditorContext $context, array $payload): void
    {
        $themeId = $payload['theme_id'] ?? null;
        if (!\is_int($themeId) || $themeId <= 0) {
            throw new \InvalidArgumentException('theme_binding_theme_id_invalid');
        }
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->load($themeId);
        if ((int)$theme->getId() !== $themeId || !$this->themeSupportsArea($theme, $context->area)) {
            throw new \InvalidArgumentException('theme_binding_theme_unavailable');
        }
    }

    /** @param array<string,mixed> $payload */
    public function project(ThemeEditorContext $context, array $payload): void
    {
        // ThemeContentScope no longer carries ScopeIdentity; compare storage owner
        // against the global sentinel. Non-global bindings stay in workspace releases.
        $globalStorage = $this->scopes->toStorageScope(ScopeIdentity::global());
        if ($context->scope->storageScope !== $globalStorage) {
            return;
        }
        $this->projectGlobalThemeBinding($context->area, (int)($payload['theme_id'] ?? 0));
    }

    private function projectGlobalThemeBinding(string $area, int $themeId): void
    {
        // is_active_* 已退役；店面/后台权威为 websites_theme_application / backend_theme_application。
        // 全局 theme_binding 编辑意图不再翻资产激活标记。
        unset($area, $themeId);
    }

    private function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        $basePath = \rtrim($theme->getPath(), '/\\');
        if ($basePath === '') {
            return false;
        }
        $separator = \DIRECTORY_SEPARATOR;

        return \is_dir($basePath . $separator . $area)
            || \is_dir($basePath . $separator . 'view' . $separator . 'theme' . $separator . $area)
            || \is_dir($basePath . $separator . 'theme' . $separator . $area);
    }
}
