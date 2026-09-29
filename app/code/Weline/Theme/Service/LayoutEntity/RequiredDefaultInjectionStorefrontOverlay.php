<?php
declare(strict_types=1);
namespace Weline\Theme\Service\LayoutEntity;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\ProductCardRenderer;
use Weline\Theme\Helper\ProductCardAddToCartParams;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\SlotBoundaryScanner;
use Weline\Theme\Service\ThemePublishedVersionRuntimeResolver;
use Weline\Theme\Service\WidgetDefaultInjectionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/** Compatibility boundary: layout relationships are emitted only into PHTML at save time. */
final class RequiredDefaultInjectionStorefrontOverlay
{

    public function __construct(
        private readonly SlotBoundaryScanner $boundaryScanner,
    ) {  }

    public function append(
        string $rendered,
        int $themeId,
        string $pageType,
        string $status,
        string $scopeKey,
        string $versionKey,
        ?string $structurePath,
        ?array $slotAllowlist = null,
    ): string { return $rendered; }
}
