<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;

/**
 * Scope-hot cache for theme editor iframe HTML (private no-store responses).
 *
 * Storefront FPC is intentionally disabled on theme-preview/content; this cache
 * reuses warm header/footer chrome and full shell output across iframe reloads
 * while draft revision bumps keep correctness after saves.
 */
final class ThemePreviewRenderCache
{
    private const CACHE_POOL = 'weline_theme_preview_render';
    private const FRESH_TTL_SECONDS = 90;
    private const STALE_TTL_SECONDS = 600;

    private ?ThemeScopedWorkspaceInterface $workspace = null;

    public function __construct(
        private readonly StorefrontScopeHotCache $hotCache,
        private readonly PreviewRequestInspector $previewInspector,
        private readonly Request $request,
    ) {
    }

    public static function cachePool(): string
    {
        return self::CACHE_POOL;
    }

    public function remember(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $status,
        string $locale,
        ?ThemeEditorContext $editorContext,
        ?int $versionId,
        string $targetType,
        int $targetId,
        callable $builder,
    ): string {
        if ($this->shouldBypass()) {
            $rendered = $builder();

            return \is_string($rendered) ? $rendered : '';
        }

        $logicalKey = $this->logicalKey(
            $themeId,
            $layoutType,
            $layoutOption,
            $status,
            $locale,
            $editorContext,
            $versionId,
            $targetType,
            $targetId,
        );

        $html = $this->hotCache->remember(
            self::CACHE_POOL,
            $logicalKey,
            self::FRESH_TTL_SECONDS,
            static function () use ($builder): string {
                $rendered = $builder();

                return \is_string($rendered) ? $rendered : '';
            },
            ['website' => true, 'lang' => true],
            self::STALE_TTL_SECONDS,
        );

        return \is_string($html) ? $html : '';
    }

    public function logicalKey(
        int $themeId,
        string $layoutType,
        string $layoutOption,
        string $status,
        string $locale,
        ?ThemeEditorContext $editorContext,
        ?int $versionId,
        string $targetType,
        int $targetId,
    ): string {
        $layoutType = \trim($layoutType) !== '' ? \trim($layoutType) : ThemeLayout::PAGE_TYPE_DEFAULT;
        $layoutOption = \trim($layoutOption) !== '' ? \trim($layoutOption) : 'default';
        $status = $status === ThemeLayout::STATUS_PUBLISHED
            ? ThemeLayout::STATUS_PUBLISHED
            : ThemeLayout::STATUS_DRAFT;
        $locale = \trim($locale);
        $targetType = \trim($targetType) !== '' ? \trim($targetType) : 'global';

        $parts = [
            // v10: editor canvas = layout shell + slots (homepage model); no controller hydrate.
            'v10',
            (string)\max(0, $themeId),
            $layoutType,
            $layoutOption,
            $status,
            $locale,
            (string)\max(0, (int)($versionId ?? 0)),
            $targetType,
            (string)\max(0, $targetId),
            $this->resolveEditorModeFingerprint(),
            $this->resolvePublicRouteFingerprint(),
            $this->resolveCanvasBodyFingerprint(),
            $editorContext instanceof ThemeEditorContext
                ? $this->resourceRevisionFingerprint($editorContext, $status)
                : 'legacy',
        ];

        return 'theme.preview.shell.' . \substr(\hash('sha256', \implode("\0", $parts)), 0, 32);
    }

    private function resolveEditorModeFingerprint(): string
    {
        try {
            $flag = \strtolower(\trim((string)$this->request->getParam('editor_mode', '')));

            return ($flag === '1' || $flag === 'true') ? 'editor' : 'shell';
        } catch (\Throwable) {
            return 'shell';
        }
    }

    private function resolvePublicRouteFingerprint(): string
    {
        try {
            $request = $this->request ?? null;
            if (!\is_object($request) || !\method_exists($request, 'getParam')) {
                return '-';
            }
            $route = \strtolower(\trim(\str_replace('\\', '/', (string)$request->getParam('theme_public_route', '')), '/'));

            return $route !== '' ? $route : '-';
        } catch (\Throwable) {
            return '-';
        }
    }

    private function resolveCanvasBodyFingerprint(): string
    {
        try {
            $slug = \strtolower(\trim((string)$this->request->getParam('preview_entity_slug', '')));
            if ($slug === '') {
                $slug = \strtolower(\trim((string)$this->request->getParam('sample_slug', '')));
            }

            return $slug !== '' ? 'body:' . $slug : 'body:auto';
        } catch (\Throwable) {
            return 'body:auto';
        }
    }

    private function shouldBypass(): bool
    {
        try {
            if ((string)$this->request->getGet('debug_hooks', '') === '1') {
                return true;
            }

            if ((string)$this->request->getGet('visual_editor', '') === '1') {
                return true;
            }

            $path = $this->previewInspector->normalizePath();
            if (\str_contains($path, 'workspace-preview')) {
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }

    private function resourceRevisionFingerprint(ThemeEditorContext $context, string $status): string
    {
        $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
        $segments = [$context->identityHash()];

        foreach ([
            ThemeEditorContext::RESOURCE_LAYOUT,
            ThemeEditorContext::RESOURCE_APPEARANCE,
            ThemeEditorContext::RESOURCE_META,
            ThemeEditorContext::RESOURCE_I18N,
        ] as $resourceType) {
            $resourceContext = $context->withResource($resourceType);
            try {
                $state = $this->getWorkspace()->load($resourceContext, $includeDraft);
                $segments[] = \sprintf(
                    '%s:%d.%s',
                    $resourceType,
                    (int)($state['revision'] ?? 0),
                    (string)($state['draft_revision_id'] ?? '0'),
                );
            } catch (\Throwable) {
                $segments[] = $resourceType . ':miss';
            }
        }

        return \implode('|', $segments);
    }

    private function getWorkspace(): ThemeScopedWorkspaceInterface
    {
        return $this->workspace ??= ObjectManager::getInstance(ThemeScopedWorkspaceInterface::class);
    }
}
