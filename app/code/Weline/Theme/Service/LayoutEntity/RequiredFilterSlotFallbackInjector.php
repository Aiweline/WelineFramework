<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Api\Version\ThemeVersionIdentity;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\ThemeContextService;

/**
 * Storefront safety net when solidify falls back to the source layout template.
 *
 * Pure-PHTML bake is the steady path. While RequestSolidifyGate forces the original
 * template (async rebake / missing derived), declaration placeholders must not remain
 * customer-visible, and required Filters inventory must still render.
 *
 * Owning: Theme mechanism. Does not replace bake; never runs as the steady path when
 * a solidified controller template was selected and Filters are already present.
 */
final class RequiredFilterSlotFallbackInjector
{
    private const FILTER_SLOTS = ['list-filters', 'category-filters'];

    public function ensure(string $html): string
    {
        if ($html === '' || $this->isEditorCanvas()) {
            return $html;
        }

        $needs = false;
        foreach (self::FILTER_SLOTS as $slotId) {
            if ($this->slotNeedsFilters($html, $slotId)) {
                $needs = true;
                break;
            }
        }
        if (!$needs) {
            // Still drop orphan declaration placeholders when Filters already rendered.
            return $this->stripDeclarationPlaceholdersWhenFiltersPresent($html);
        }

        foreach (self::FILTER_SLOTS as $slotId) {
            if (!$this->slotNeedsFilters($html, $slotId)) {
                continue;
            }
            $widgetHtml = $this->renderFiltersWidget($slotId);
            if ($widgetHtml === '') {
                // Last resort: never show "由 Filters 部件默认注入" to customers.
                $html = $this->stripDeclarationPlaceholder($html, $slotId);
                continue;
            }
            $html = $this->replaceOrAppendInSlot($html, $slotId, $widgetHtml);
        }

        return $this->stripDeclarationPlaceholdersWhenFiltersPresent($html);
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

    private function slotNeedsFilters(string $html, string $slotId): bool
    {
        if (!$this->htmlMentionsSlot($html, $slotId)) {
            return false;
        }
        if ($this->slotHasFiltersWidget($html, $slotId)) {
            return false;
        }

        return $this->slotHasDeclarationPlaceholder($html, $slotId)
            || $this->slotInnerLooksEmpty($html, $slotId);
    }

    private function htmlMentionsSlot(string $html, string $slotId): bool
    {
        return str_contains($html, 'data-slot-id="' . $slotId . '"')
            || str_contains($html, "data-slot-id='" . $slotId . "'")
            || str_contains($html, 'data-wslot="' . $slotId . '"')
            || str_contains($html, "data-wslot='" . $slotId . "'")
            || str_contains($html, 'id="' . $slotId . '"')
            || str_contains($html, 'data-placeholder="' . $slotId . '"')
            || preg_match('/<w:slot\b[^>]*\bid\s*=\s*["\']' . preg_quote($slotId, '/') . '["\']/i', $html) === 1;
    }

    private function slotHasFiltersWidget(string $html, string $slotId): bool
    {
        // Prefer scoped check: widget wrapper bound to this slot.
        if (preg_match(
            '/data-slot-id\s*=\s*["\']' . preg_quote($slotId, '/') . '["\'][^>]*>[\s\S]{0,12000}?data-widget-code\s*=\s*["\']category-filters["\']/i',
            $html
        ) === 1) {
            return true;
        }
        if (str_contains($html, 'data-testid="storefront-filters-panel"')
            && str_contains($html, 'data-widget-code="category-filters"')
        ) {
            // Page already has Filters somewhere — treat as satisfied for XOR strip.
            return true;
        }

        return false;
    }

    private function slotHasDeclarationPlaceholder(string $html, string $slotId): bool
    {
        return str_contains($html, 'data-placeholder="' . $slotId . '"')
            || str_contains($html, "data-placeholder='" . $slotId . "'")
            || (str_contains($html, '由 Filters 部件默认注入') && $this->htmlMentionsSlot($html, $slotId));
    }

    private function slotInnerLooksEmpty(string $html, string $slotId): bool
    {
        if (preg_match(
            '/data-slot-id\s*=\s*["\']' . preg_quote($slotId, '/') . '["\'][^>]*>([\s\S]*?)<\/div>/i',
            $html,
            $m
        ) !== 1) {
            return false;
        }
        $inner = trim(strip_tags((string)$m[1]));
        $inner = preg_replace('/\s+/u', '', $inner) ?? $inner;

        return $inner === '' || str_contains((string)$m[1], '由 Filters 部件默认注入');
    }

    private function renderFiltersWidget(string $slotId): string
    {
        try {
            $themeId = 0;
            try {
                $themeId = (int)(ThemeData::getCurrentTheme()?->getId() ?? 0);
            } catch (\Throwable) {
                $themeId = 0;
            }
            if ($themeId < 1) {
                try {
                    $themeId = (int)ObjectManager::getInstance(ThemeContextService::class)
                        ->resolveTheme('frontend', null, true)?->getId();
                } catch (\Throwable) {
                    $themeId = 0;
                }
            }

            /** @var ThemeLayoutEntityWidgetRenderer $renderer */
            $renderer = ObjectManager::getInstance(ThemeLayoutEntityWidgetRenderer::class);
            $entry = [
                'node_uid' => 'fallback-filters-' . $slotId,
                'widget_module' => 'Weline_Filters',
                'widget_code' => 'category-filters',
                'widget_type' => 'sidebar',
                'is_active' => true,
                'slot_id' => $slotId,
                'config' => [],
                'source' => 'default_injection_fallback',
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

    private function replaceOrAppendInSlot(string $html, string $slotId, string $widgetHtml): string
    {
        $quoted = preg_quote($slotId, '/');
        // Replace declaration placeholder block first.
        $html = preg_replace(
            '/<(div|span|aside|section)\b[^>]*\bdata-placeholder\s*=\s*(["\'])' . $quoted . '\2[^>]*>.*?<\/\1\s*>/is',
            $widgetHtml,
            $html,
            1
        ) ?? $html;

        if (str_contains($html, $widgetHtml)) {
            return $html;
        }

        // Append before closing published slot / wrapper when placeholder missing.
        $html2 = preg_replace(
            '/(<div\b[^>]*\bdata-slot-id\s*=\s*(["\'])' . $quoted . '\2[^>]*>)/i',
            '$1' . $widgetHtml,
            $html,
            1
        );
        if (is_string($html2) && $html2 !== $html) {
            return $html2;
        }

        return $html;
    }

    private function stripDeclarationPlaceholder(string $html, string $slotId): string
    {
        $quoted = preg_quote($slotId, '/');

        return preg_replace(
            '/<(div|span|aside|section)\b[^>]*\bdata-placeholder\s*=\s*(["\'])' . $quoted . '\2[^>]*>.*?<\/\1\s*>/is',
            '',
            $html
        ) ?? $html;
    }

    private function stripDeclarationPlaceholdersWhenFiltersPresent(string $html): string
    {
        if (!str_contains($html, 'data-widget-code="category-filters"')
            && !str_contains($html, "data-widget-code='category-filters'")
            && !str_contains($html, 'data-testid="storefront-filters-panel"')
        ) {
            return $html;
        }
        foreach (self::FILTER_SLOTS as $slotId) {
            $html = $this->stripDeclarationPlaceholder($html, $slotId);
        }
        // Drop bare customer-facing declaration copy if still floating.
        if (str_contains($html, '由 Filters 部件默认注入')) {
            $html = preg_replace(
                '/<(span|div)\b[^>]*class="[^"]*(?:placeholder-text|slot-placeholder)[^"]*"[^>]*>\s*[^<]*由 Filters 部件默认注入[^<]*<\/\1\s*>/iu',
                '',
                $html
            ) ?? $html;
        }

        return $html;
    }
}
