<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SharedChromeService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\ThemePageTypeResolver;

/** Compatibility boundary: layout relationships are emitted only into PHTML at save time. */
final class ThemeLayoutEntityPublishedSlotHost
{
    public const CTX_USE_REACTIVE = 'theme.layout_entity.published_slot.use_reactive.v1';
    public const CTX_FRAGMENTS = 'theme.layout_entity.published_slot.fragments.v1';
    public const CTX_LAYOUT_TYPE = 'theme.storefront.layout_type';
    public const CTX_THEME_ID = 'theme.storefront.theme_id';
    public const CTX_SOLIDIFYING = 'theme.layout_entity.published_slot.solidifying.v1';
    public const CTX_ZERO_FILL_APPLIED = 'theme.layout_entity.zero_runtime_fill.applied.v1';
    public const CTX_ZERO_FILL_REASON = 'theme.layout_entity.zero_runtime_fill.reason.v1';
    public const CTX_PRIME_TRANSIENT_ERROR = 'theme.layout_entity.published_slot.prime_transient_error.v1';
    public const CTX_SOLIDIFIED_CONTROLLER_TEMPLATE = 'theme.layout_entity.solidified_controller_template.v1';
    public static function markSolidifiedControllerTemplateSelected(bool $selected = true): void
    { if ($selected) { RequestContext::set(self::CTX_SOLIDIFIED_CONTROLLER_TEMPLATE, true); } else { RequestContext::remove(self::CTX_SOLIDIFIED_CONTROLLER_TEMPLATE); } }

    public static function solidifiedControllerTemplateSelected(): bool
    { return RequestContext::get(self::CTX_SOLIDIFIED_CONTROLLER_TEMPLATE) === true; }

    public static function shellNeedsRuntimeSafetyNetFill(string $html): bool
    { return false; }

    public static function publishedSolidifiedArtifactLoaded(): bool
    { return self::solidifiedControllerTemplateSelected(); }

    public static function shellSafetyNetFillReason(string $html): string
    { return 'none'; }

    public static function shellMissingRequiredNewsletterPopup(string $html): bool
    { return false; }

    public static function shellHasEmptyCriticalPublishedSlots(string $html): bool
    { return false; }

    public static function shellMissingStorefrontChromeSignals(string $html): bool
    { return false; }

    public static function shellHasSubstantialHeaderChrome(string $html): bool
    { return false; }

    public static function shellHasSubstantialFooterChrome(string $html): bool
    { return false; }

    public static function shellHasBlankExclusiveFooterRoot(string $html): bool
    { return false; }

    public static function shellHasStorefrontHeaderSignal(string $html): bool
    { return false; }

    public static function isEffectivelyBlankSlotInner(string $inner): bool
    { return trim(strip_tags($inner)) === ''; }

    public static function primeStorefront(int $themeId, string $layoutType): bool
    { return false; }

    public static function useReactiveMarkers(): bool
    { return true; }

    public static function publishedInner(string $slotId, string $defaultHtml): string
    { return $defaultHtml; }

    public static function bakeAndDefaultShareWidgetCode(string $bakeInner, string $defaultHtml): bool
    { return false; }

    public static function isFilterInventorySlot(string $slotId): bool
    { return false; }

    public static function bakeInnerHasFiltersWidget(string $inner): bool
    { return false; }

    public static function defaultCarriesProtectedBusinessNestedSlots(string $html): bool
    { return false; }
}
