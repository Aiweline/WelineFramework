<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

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
        if (!$context->scope->identity->isGlobal()) {
            return;
        }
        $this->projectGlobalThemeBinding($context->area, (int)($payload['theme_id'] ?? 0));
    }

    private function projectGlobalThemeBinding(string $area, int $themeId): void
    {
        if ($themeId <= 0) {
            return;
        }
        $field = $area === 'backend'
            ? WelineTheme::schema_fields_IS_ACTIVE_BACKEND
            : WelineTheme::schema_fields_IS_ACTIVE_FRONTEND;
        $theme = clone $this->themes;
        $theme->clearData()->clearQuery()->where($field, 1)->update([$field => 0])->fetch();
        $theme->clearData()->clearQuery()->where(WelineTheme::schema_fields_ID, $themeId)->update([$field => 1])->fetch();
        $theme->_cache->delete($area === 'backend' ? 'theme_backend' : 'theme_frontend');
        $theme->_cache->delete('theme');
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
