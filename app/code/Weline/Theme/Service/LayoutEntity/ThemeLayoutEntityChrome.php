<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * Storefront/preview chrome render via baked entity phtml (hard fail if missing).
 */
final class ThemeLayoutEntityChrome
{
    public function __construct(
        private readonly ThemeLayoutEntityPointerResolver $pointers,
        private readonly ThemeLayoutEntityPaths $paths,
    ) {
    }

    /**
     * Render shared chrome for theme+scope. Prefer published unless $preview.
     * Optional $themeVersionId forces a specific version path when set.
     *
     * @throws \RuntimeException when chrome entity is missing
     */
    public function renderCurrent(
        int $themeId,
        string $scope,
        ?int $themeVersionId = null,
        bool $preview = false,
    ): string {
        if ($themeId < 1 || \trim($scope) === '') {
            throw new \RuntimeException('theme_layout_entity_chrome_invalid_identity');
        }

        $path = null;
        if ($themeVersionId !== null && $themeVersionId > 0) {
            $path = $this->paths->chromePhtml($themeId, $scope, $themeVersionId);
        } else {
            $pointer = $preview
                ? $this->pointers->resolveCurrentChrome($themeId, $scope)
                : $this->pointers->resolvePublishedChrome($themeId, $scope);
            $path = \is_array($pointer) ? (string)($pointer['path'] ?? '') : '';
        }

        if ($path === '' || !\is_file($path)) {
            throw new \RuntimeException(
                'theme_layout_entity_chrome_missing: theme=' . $themeId
                . ' scope=' . $scope
                . ($themeVersionId ? (' tv=' . $themeVersionId) : '')
            );
        }

        \ob_start();
        try {
            include $path;
            $html = (string)\ob_get_clean();
        } catch (\Throwable $e) {
            if (\ob_get_level() > 0) {
                \ob_end_clean();
            }
            throw new \RuntimeException(
                'theme_layout_entity_chrome_render_failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return $html;
    }
}
