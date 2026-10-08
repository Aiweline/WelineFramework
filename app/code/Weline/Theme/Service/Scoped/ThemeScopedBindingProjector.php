<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\DefaultThemeInterface;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\WelineTheme;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;

/**
 * Dedicated theme-binding load/project collaborator for scoped Theme workspaces.
 *
 * load()/parent fallback MUST resolve via ThemeApplication scope chain (then module
 * Default). Never use process-active theme id — that leaks another website's theme
 * (e.g. default/hanfu) into DaoCharms 「恢复继承」.
 */
final class ThemeScopedBindingProjector
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeScopedProjectionSupport $support,
        private readonly ScopeHierarchyInterface $scopes,
        private readonly ScopeIdentityCatalogInterface $catalog,
        private readonly ThemeApplicationInterface $applications,
        private readonly DefaultThemeInterface $defaultTheme,
    ) {
    }

    /** @return array{theme_id:int} */
    public function load(ThemeEditorContext $context): array
    {
        return [
            'theme_id' => $this->resolveThemeIdForScope($context),
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

    private function resolveThemeIdForScope(ThemeEditorContext $context): int
    {
        $storeMode = $this->normalizeApplicationStoreMode($context->scope->storeMode);
        $area = $context->area === 'backend' ? 'backend' : 'frontend';
        try {
            $identity = $this->scopes->fromStorageScope($context->scope->storageScope);
            if ($identity instanceof ScopeIdentity) {
                $identity = $this->catalog->authoritativeIdentity($identity);
                $keys = [];
                $cursor = $identity;
                do {
                    $keys[] = $cursor->canonicalKey();
                    $cursor = $this->scopes->parentIdentity($cursor);
                } while ($cursor !== null);

                $resolution = $this->applications->resolve($keys, $storeMode, $area);
                if ($resolution->reference !== null && $resolution->reference->themeId > 0) {
                    return $resolution->reference->themeId;
                }
            }
        } catch (\Throwable) {
        }

        return $this->fallbackModuleDefaultThemeId($context, $storeMode, $area);
    }

    /** ThemeApplication only accepts normal|dev|test; editor legacy "default" → normal. */
    private function normalizeApplicationStoreMode(string $storeMode): string
    {
        $storeMode = strtolower(trim($storeMode));
        if (in_array($storeMode, [ScopeIdentity::MODE_NORMAL, ScopeIdentity::MODE_DEV, ScopeIdentity::MODE_TEST], true)) {
            return $storeMode;
        }

        return ScopeIdentity::MODE_NORMAL;
    }

    private function fallbackModuleDefaultThemeId(
        ThemeEditorContext $context,
        ?string $storeMode = null,
        ?string $area = null,
    ): int {
        $storeMode ??= $this->normalizeApplicationStoreMode($context->scope->storeMode);
        $area ??= $context->area === 'backend' ? 'backend' : 'frontend';
        try {
            $reference = $this->defaultTheme->defaultApplicationReference(
                $area,
                $context->scope->storageScope,
                $storeMode,
            );
            $themeId = (int)($reference['theme_id'] ?? 0);
            if ($themeId > 0) {
                return $themeId;
            }
            $registered = $this->defaultTheme->getRegisteredDefault($context->area);
            $registeredId = (int)($registered['id'] ?? 0);
            if ($registeredId > 0) {
                return $registeredId;
            }
        } catch (\Throwable) {
        }

        // Last resort only — never preferred over ThemeApplication / DefaultTheme.
        return $this->support->activeThemeId($context->area);
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
