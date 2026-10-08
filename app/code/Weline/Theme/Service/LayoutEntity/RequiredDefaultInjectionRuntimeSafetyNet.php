<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Storefront safety net: required default_injections must appear unless user_deleted.
 *
 * Steady path remains bake → derived PHTML. When solidify falls back, Overlay is stubbed,
 * or a design theme rewrote layouts and dropped destination slots, this still XOR-injects
 * missing required widgets into (or after synthesizing) their declared slots.
 *
 * Owning: Weline_Theme. Does not replace bake; never stacks a second copy when present;
 * never revives user_deleted@{versionId}.
 */
final class RequiredDefaultInjectionRuntimeSafetyNet
{
    public function ensure(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        // Theme-switch solidify gate: editor canvas still needs Filters XOR when
        // source layouts show declaration placeholders. Full required Overlay stays
        // storefront-only so edit chrome is not flooded with every injection.
        if ($this->isEditorCanvas()) {
            return $this->finishFiltersXor($html);
        }

        // Theme-rewritten footers may omit float destinations entirely.
        $html = StorefrontFloatLayerHost::ensureInHtml($html);

        $pageType = $this->resolvePageType();
        if ($pageType === '') {
            $pageType = ThemeLayout::PAGE_TYPE_HOME;
        }

        $themeId = $this->resolveFrontendThemeId();
        $declarations = $this->loadDeclarations();
        if ($declarations === []) {
            return $this->finishFiltersXor($html);
        }

        $omissions = $themeId > 0
            ? $this->omissionsFor($themeId, $pageType)
            : [];

        $targets = RequiredDefaultInjectionContract::requiredInjections($declarations, $pageType);
        foreach ($targets as $item) {
            $slotId = trim((string)($item['slot_id'] ?? ''));
            $module = trim((string)($item['widget_module'] ?? ''));
            $code = trim((string)($item['widget_code'] ?? ''));
            if ($slotId === '' || $module === '' || $code === '') {
                continue;
            }
            if (RequiredDefaultInjectionContract::isUninstalled($omissions, $slotId, $module, $code)) {
                continue;
            }
            if (RequiredDefaultInjectionContract::pageHasWidgetPresent($html, $module, $code)) {
                continue;
            }
            if (!$this->htmlMentionsSlot($html, $slotId)
                && !StorefrontFloatLayerHost::htmlHasFloatDestinations($html)
            ) {
                // Non-float destinations without a slot: cannot invent arbitrary page slots.
                continue;
            }
            if (!$this->htmlMentionsSlot($html, $slotId)
                && in_array($slotId, ['storefront-float-start', 'storefront-float-end'], true)
            ) {
                $html = StorefrontFloatLayerHost::ensureInHtml($html);
            }
            if (!$this->htmlMentionsSlot($html, $slotId)) {
                continue;
            }

            $node = is_array($item['node'] ?? null) ? $item['node'] : [];
            $widgetHtml = $this->renderWidget($module, $code, $slotId, $node, $themeId);
            if ($widgetHtml === '') {
                continue;
            }
            $html = $this->injectIntoSlot($html, $slotId, $widgetHtml);
        }

        return $this->finishFiltersXor($html);
    }

    private function finishFiltersXor(string $html): string
    {
        try {
            /** @var RequiredFilterSlotFallbackInjector $filters */
            $filters = ObjectManager::getInstance(RequiredFilterSlotFallbackInjector::class);

            return $filters->ensure($html);
        } catch (\Throwable) {
            return $html;
        }
    }

    private function isEditorCanvas(): bool
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            $flag = trim((string)$request->getParam('editor_mode', ''));
            if ($flag === '1' || strtolower($flag) === 'true') {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }
        try {
            /** @var PreviewContextService $preview */
            $preview = ObjectManager::getInstance(PreviewContextService::class);
            if ($preview->isEditorThemeRequest()) {
                return true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return false;
    }

    private function resolvePageType(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $layoutType = trim((string)$request->getParam('layout_type', ''));
            if ($layoutType === '') {
                $path = trim((string)$request->getUrlPath());
                if ($path === '' || $path === '/') {
                    return ThemeLayout::PAGE_TYPE_HOME;
                }
                if (str_contains($path, '/products') || preg_match('#^/?products(?:/|$)#', $path) === 1) {
                    return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
                }
                if (str_contains($path, '/category') || preg_match('#^/?categor(?:y|ies)(?:/|$)#', $path) === 1) {
                    return ThemeLayout::PAGE_TYPE_CATEGORY;
                }

                return '';
            }
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

            return trim((string)$resolver->resolvePageType($layoutType, null, $request, ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            $id = (int)(ThemeData::getCurrentTheme()?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $themeContext->resolveTheme('frontend', null, true);
            $id = (int)($theme?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }

            return (int)($themeContext->resolveRegisteredDefaultTheme('frontend')?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDeclarations(): array
    {
        try {
            /** @var DefaultInjectionPlanRepository $plans */
            $plans = ObjectManager::getInstance(DefaultInjectionPlanRepository::class);

            return $plans->listDeclarations('frontend');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function omissionsFor(int $themeId, string $pageType): array
    {
        try {
            /** @var ThemePublishedVersionRuntimeResolver $versions */
            $versions = ObjectManager::getInstance(ThemePublishedVersionRuntimeResolver::class);
            $versionId = (int)($versions->resolve($themeId, $pageType)['themePublishedVersionId'] ?? 0);
            /** @var WidgetDefaultInjectionService $injections */
            $injections = ObjectManager::getInstance(WidgetDefaultInjectionService::class);

            return $injections->uninstalledInjectionsForVersion($themeId, $pageType, $versionId);
        } catch (\Throwable) {
            return [];
        }
    }

    private function htmlMentionsSlot(string $html, string $slotId): bool
    {
        return str_contains($html, 'data-slot-id="' . $slotId . '"')
            || str_contains($html, "data-slot-id='" . $slotId . "'")
            || str_contains($html, 'data-wslot="' . $slotId . '"')
            || str_contains($html, "data-wslot='" . $slotId . "'")
            || str_contains($html, 'id="' . $slotId . '"')
            || str_contains($html, 'data-testid="' . $slotId . '"')
            || preg_match('/<w:slot\b[^>]*\bid\s*=\s*["\']' . preg_quote($slotId, '/') . '["\']/i', $html) === 1;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderWidget(string $module, string $code, string $slotId, array $node, int $themeId): string
    {
        try {
            /** @var ThemeLayoutEntityWidgetRenderer $renderer */
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $entry = [
                'node_uid' => (string)($node['node_uid'] ?? ('runtime-required-' . $slotId . '-' . $code)),
                'widget_module' => $module,
                'widget_code' => $code,
                'widget_type' => (string)($node['widget_type'] ?? 'content'),
                'is_active' => true,
                'slot_id' => $slotId,
                'config' => is_array($node['config'] ?? null) ? $node['config'] : [],
                'source' => 'default_injection_runtime_safety_net',
            ];
            $identity = $themeId > 0
                ? ThemeVersionIdentity::fromArray([
                    'theme_id' => $themeId,
                    'canonical_scope' => 'default.default.default',
                    'store_mode' => 'normal',
                    'area' => 'frontend',
                    'theme_version_id' => 0,
                    'mode' => 'formal',
                    'content_revision' => 0,
                ])
                : null;

            return trim($renderer->renderResolved($entry, [], [], $identity));
        } catch (\Throwable) {
            return '';
        }
    }

    private function injectIntoSlot(string $html, string $slotId, string $widgetHtml): string
    {
        $quoted = preg_quote($slotId, '/');
        $html2 = preg_replace(
            '/(<div\b[^>]*\bdata-slot-id\s*=\s*(["\'])' . $quoted . '\2[^>]*>)/i',
            '$1' . $widgetHtml,
            $html,
            1
        );
        if (is_string($html2) && $html2 !== $html) {
            return $html2;
        }
        $html3 = preg_replace(
            '/(<div\b[^>]*\bdata-wslot\s*=\s*(["\'])' . $quoted . '\2[^>]*>)/i',
            '$1' . $widgetHtml,
            $html,
            1
        );
        if (is_string($html3) && $html3 !== $html) {
            return $html3;
        }

        return $html;
    }
}
