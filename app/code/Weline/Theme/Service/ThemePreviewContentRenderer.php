<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;

class ThemePreviewContentRenderer
{
    private const META_SLOT_MAP = [
        ThemeLayout::PAGE_TYPE_HOME => [
            'banner' => ['homepage-hero', 'banner'],
            'deals' => ['homepage-deals', 'deals', 'products'],
            'categories' => ['homepage-categories', 'categories'],
        ],
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST => [
            'filters' => ['filters'],
            'breadcrumb' => ['breadcrumb'],
            'products' => ['product-list-main', 'products'],
        ],
        ThemeLayout::PAGE_TYPE_PRODUCT => [
            'breadcrumb' => ['breadcrumb'],
            'product' => ['product', 'product-main'],
        ],
        ThemeLayout::PAGE_TYPE_CART => [
            'cartItems' => ['cart-main', 'cartItems'],
        ],
        'checkout_success' => [
            'order' => ['checkout-success-main', 'order'],
        ],
    ];

    private const CONTENT_SLOT_PRIORITY = [
        ThemeLayout::PAGE_TYPE_HOME => ['content', 'main-content', 'homepage-promo', 'homepage-brands', 'homepage-benefits'],
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST => ['list-recommendations', 'content'],
        ThemeLayout::PAGE_TYPE_PRODUCT => ['product-related', 'product-sidebar', 'content'],
        ThemeLayout::PAGE_TYPE_CATEGORY => ['category-related', 'content'],
        ThemeLayout::PAGE_TYPE_CART => ['cart-recommendations', 'content'],
        ThemeLayout::PAGE_TYPE_SEARCH => ['search-main', 'search-recommendations', 'content'],
        ThemeLayout::PAGE_TYPE_CMS => ['cms-main', 'cms-page-main', 'content'],
        ThemeLayout::PAGE_TYPE_CHECKOUT => ['checkout-main', 'content'],
        ThemeLayout::PAGE_TYPE_ACCOUNT => ['account-main', 'content'],
        'account_auth' => ['account-auth-main', 'content'],
        'checkout_success' => ['content'],
        'promotion' => ['promotion-main', 'content'],
        'customer_service' => ['customer-service-main', 'content'],
        'review' => ['review-main', 'content'],
        'qa' => ['qa-main', 'content'],
        'rma' => ['rma-main', 'content'],
        ThemeLayout::PAGE_TYPE_DEFAULT => ['default-content', 'content', 'main-content'],
    ];

    private readonly ThemeLayoutVersionService $versionService;

    public function __construct(
        private readonly ThemeLayoutService $layoutService,
        private readonly SlotRendererService $slotRendererService,
        private readonly ThemePageTypeResolver $pageTypeResolver,
        ?ThemeLayoutVersionService $versionService = null,
    ) {
        $this->versionService = $versionService ?? ObjectManager::getInstance(ThemeLayoutVersionService::class);
    }

    /**
     * @return array{content:string,meta:array,page_type:string,status:string,used_seed:bool}
     */
    public function build(
        int $themeId,
        string $layoutType,
        string $status = ThemeLayout::STATUS_DRAFT,
        ?int $versionId = null,
        array $identity = [],
    ): array {
        $identity = $this->normalizeLayoutIdentity($identity);
        $baseLayoutType = $this->pageTypeResolver->extractBaseLayoutType($layoutType);
        if ($baseLayoutType === '') {
            $baseLayoutType = ThemeLayout::PAGE_TYPE_DEFAULT;
        }

        $pageType = $this->pageTypeResolver->mapLayoutTypeToPageType($baseLayoutType);
        $versionLayout = null;
        if ($versionId !== null && $versionId > 0) {
            $versionLayout = $this->versionService->getVersionSnapshot($themeId, $pageType, $versionId, $identity);
            [$layout, $resolvedPageType, $resolvedStatus, $usedSeed] = [
                $versionLayout ?? [],
                $pageType,
                'version',
                false,
            ];
        } else {
            [$layout, $resolvedPageType, $resolvedStatus, $usedSeed] = $this->resolvePreviewLayout($themeId, $pageType, $status, $identity);
        }
        $orderedSlotIds = $this->collectOrderedSlotIds($layout);

        if (empty($orderedSlotIds)) {
            return [
                'content' => '',
                'meta' => [],
                'page_type' => $resolvedPageType,
                'status' => $resolvedStatus,
                'used_seed' => $usedSeed,
            ];
        }

        $slotHtml = $this->renderSlots($themeId, $resolvedPageType, $resolvedStatus, $orderedSlotIds, $versionLayout ?? $layout);
        [$meta, $consumedSlotIds] = $this->buildMetaFragments($baseLayoutType, $slotHtml);
        $content = $this->buildContentHtml($baseLayoutType, $orderedSlotIds, $slotHtml, $consumedSlotIds);

        return [
            'content' => $content,
            'meta' => $meta,
            'page_type' => $resolvedPageType,
            'status' => $resolvedStatus,
            'used_seed' => $usedSeed,
        ];
    }

    /**
     * @return array{0:array,1:string,2:string,3:bool}
     */
    private function resolvePreviewLayout(int $themeId, string $pageType, string $requestedStatus, array $identity = []): array
    {
        $requestedStatus = $requestedStatus === ThemeLayout::STATUS_PUBLISHED
            ? ThemeLayout::STATUS_PUBLISHED
            : ThemeLayout::STATUS_DRAFT;

        if ($requestedStatus === ThemeLayout::STATUS_DRAFT) {
            $draftLayout = $this->layoutService->getFullLayout($themeId, $pageType, ThemeLayout::STATUS_DRAFT, $identity);
            if ($this->hasWidgets($draftLayout)) {
                return [$draftLayout, $pageType, ThemeLayout::STATUS_DRAFT, false];
            }

            if ($this->layoutService->hasDraft($themeId, $pageType, $identity)
                || $this->hasEmptyCurrentRestoreVersion($themeId, $pageType, $identity)) {
                return [$draftLayout, $pageType, ThemeLayout::STATUS_DRAFT, false];
            }
        }

        if ($this->layoutService->hasNoWidgetPlacements($themeId, $pageType, $requestedStatus, $identity)) {
            return [$this->layoutService->getFullLayout($themeId, $pageType, $requestedStatus, $identity), $pageType, $requestedStatus, false];
        }

        foreach ($this->buildLookupCandidates($pageType, $requestedStatus) as [$candidatePageType, $candidateStatus]) {
            $layout = $this->layoutService->getFullLayout($themeId, $candidatePageType, $candidateStatus, $identity);
            if ($this->hasWidgets($layout)) {
                return [$layout, $candidatePageType, $candidateStatus, false];
            }
        }

        // Default widgets are suggestions in the editor application tab. Layouts with no
        // widget placements keep rendering the template slots until the user applies one.
        return [[], $pageType, $requestedStatus, false];
    }

    private function hasEmptyCurrentRestoreVersion(int $themeId, string $pageType, array $identity = []): bool
    {
        $currentVersion = $this->versionService->getCurrentVersion($themeId, $pageType, $identity);
        if (!$currentVersion?->isRestoreType()) {
            return false;
        }

        return !$this->hasWidgets($currentVersion->getSnapshotData());
    }

    /**
     * @param array<string,mixed> $identity
     * @return array{layout_option:string,scope:string,target_type:string,target_id:int}
     */
    private function normalizeLayoutIdentity(array $identity = []): array
    {
        $layoutOption = trim((string)($identity['layout_option'] ?? 'default'));
        $scope = trim((string)($identity['scope'] ?? 'default'));
        $targetType = trim((string)($identity['target_type'] ?? $identity['theme_layout_target_type'] ?? 'global'));

        return [
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'scope' => $scope !== '' ? $scope : 'default',
            'target_type' => $targetType !== '' ? $targetType : 'global',
            'target_id' => max(0, (int)($identity['target_id'] ?? $identity['theme_layout_target_id'] ?? 0)),
        ];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function buildLookupCandidates(string $pageType, string $requestedStatus): array
    {
        $candidates = [
            [$pageType, $requestedStatus],
        ];

        $fallbackStatus = $requestedStatus === ThemeLayout::STATUS_DRAFT
            ? ThemeLayout::STATUS_PUBLISHED
            : ThemeLayout::STATUS_DRAFT;
        $candidates[] = [$pageType, $fallbackStatus];

        if ($pageType !== ThemeLayout::PAGE_TYPE_DEFAULT) {
            $candidates[] = [ThemeLayout::PAGE_TYPE_DEFAULT, $requestedStatus];
            $candidates[] = [ThemeLayout::PAGE_TYPE_DEFAULT, $fallbackStatus];
        }

        return $candidates;
    }

    private function hasWidgets(array $layout): bool
    {
        foreach ($layout as $areaData) {
            if (!empty($areaData['widgets'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function collectOrderedSlotIds(array $layout): array
    {
        $slotIds = [];
        $seen = [];

        foreach ($layout as $area => $areaData) {
            foreach ($areaData['widgets'] ?? [] as $widget) {
                $slotId = $this->resolveSlotId($widget, (string)$area);
                if ($slotId === '' || isset($seen[$slotId]) || $this->isComponentInstanceSlotId($slotId)) {
                    continue;
                }
                $seen[$slotId] = true;
                $slotIds[] = $slotId;
            }
        }

        return $slotIds;
    }

    private function isComponentInstanceSlotId(string $slotId): bool
    {
        // Slots generated inside a saved component are scoped with that component layout_id
        // (for example section-content:279). They must be filled only when the parent
        // component renders, otherwise a top-level preview placeholder consumes them first.
        return (bool)preg_match('/:[1-9][0-9]*$/', $slotId);
    }

    private function resolveSlotId(array $widget, string $fallbackArea): string
    {
        $slotId = trim((string)($widget['slot_id'] ?? ''));
        if ($slotId !== '') {
            return $slotId;
        }

        $metaConfigSlot = trim((string)($widget['meta']['config']['slot'] ?? ''));
        if ($metaConfigSlot !== '') {
            return $metaConfigSlot;
        }

        $metaSlot = trim((string)($widget['meta']['slot'] ?? ''));
        if ($metaSlot !== '') {
            return $metaSlot;
        }

        return trim($fallbackArea);
    }

    /**
     * @param string[] $orderedSlotIds
     * @return array<string,string>
     */
    private function renderSlots(int $themeId, string $pageType, string $status, array $orderedSlotIds, ?array $layoutData = null): array
    {
        $html = '';
        foreach ($orderedSlotIds as $slotId) {
            $slotIdEscaped = htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8');
            $html .= '<div data-preview-slot="' . $slotIdEscaped . '" data-wslot="' . $slotIdEscaped . '"></div>';
        }

        $processedHtml = $layoutData !== null
            ? $this->slotRendererService->processSlotsWithLayout($html, $layoutData, false, $themeId, $this->resolvePreviewArea())
            : $this->slotRendererService->processSlots($html, $themeId, $pageType, $status, $this->resolvePreviewArea());

        return $this->extractSlotHtml($processedHtml, $orderedSlotIds);
    }

    private function resolvePreviewArea(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $area = (string)$request->getParam('preview_area', $request->getParam('editor_area', PreviewContextService::AREA_FRONTEND));
        } catch (\Throwable) {
            $area = PreviewContextService::AREA_FRONTEND;
        }

        return $area === PreviewContextService::AREA_BACKEND
            ? PreviewContextService::AREA_BACKEND
            : PreviewContextService::AREA_FRONTEND;
    }

    /**
     * @param string[] $orderedSlotIds
     * @return array<string,string>
     */
    private function extractSlotHtml(string $html, array $orderedSlotIds): array
    {
        $slotHtml = [];
        if ($html === '') {
            return $slotHtml;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8"><div data-preview-root="1">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        foreach ($orderedSlotIds as $slotId) {
            $query = sprintf('//*[@data-preview-slot=%s]', $this->buildXpathStringLiteral($slotId));
            $node = $xpath->query($query)->item(0);
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $slotHtml[$slotId] = $this->extractInnerHtml($node);
        }

        return $slotHtml;
    }

    /**
     * @param array<string,string> $slotHtml
     * @return array{0:array,1:string[]}
     */
    private function buildMetaFragments(string $layoutType, array $slotHtml): array
    {
        $meta = [];
        $consumed = [];

        foreach (self::META_SLOT_MAP[$layoutType] ?? [] as $metaKey => $slotIds) {
            foreach ($slotIds as $slotId) {
                $html = trim((string)($slotHtml[$slotId] ?? ''));
                if ($html === '') {
                    continue;
                }

                $meta[$metaKey] = $html;
                $consumed[$slotId] = true;
                break;
            }
        }

        return [$meta, array_keys($consumed)];
    }

    /**
     * @param string[] $orderedSlotIds
     * @param array<string,string> $slotHtml
     * @param string[] $consumedSlotIds
     */
    private function buildContentHtml(string $layoutType, array $orderedSlotIds, array $slotHtml, array $consumedSlotIds): string
    {
        if ($layoutType === ThemeLayout::PAGE_TYPE_DASHBOARD) {
            return $this->buildDashboardContentHtml($slotHtml);
        }

        $contentParts = [];
        $consumed = array_fill_keys($consumedSlotIds, true);
        $added = [];

        foreach (self::CONTENT_SLOT_PRIORITY[$layoutType] ?? [] as $slotId) {
            $html = trim((string)($slotHtml[$slotId] ?? ''));
            if ($html === '' || isset($consumed[$slotId]) || isset($added[$slotId])) {
                continue;
            }
            $added[$slotId] = true;
            $contentParts[] = $html;
        }

        foreach ($orderedSlotIds as $slotId) {
            $html = trim((string)($slotHtml[$slotId] ?? ''));
            if ($html === '' || isset($consumed[$slotId]) || isset($added[$slotId])) {
                continue;
            }
            $added[$slotId] = true;
            $contentParts[] = $html;
        }

        return implode(PHP_EOL, $contentParts);
    }

    /**
     * @param array<string,string> $slotHtml
     */
    private function buildDashboardContentHtml(array $slotHtml): string
    {
        $regions = [
            'dashboard-summary' => ['w-dashboard-region-summary w-dashboard-slot-summary', '指标区', '只放关键数值、KPI、状态数字。', '最多 4 个'],
            'dashboard-analysis' => ['w-dashboard-region-analysis w-dashboard-slot-stack', '主分析区', '用于趋势图、核心图表和主要观察。', ''],
            'dashboard-side' => ['w-dashboard-region-side w-dashboard-slot-stack', '侧栏洞察', '放状态、短列表、异常提醒。', ''],
            'dashboard-detail' => ['w-dashboard-region-detail w-dashboard-slot-stack', '明细区', '放表格、排行和可展开列表。', ''],
        ];

        $html = '<section class="w-dashboard-canvas-wrap" data-dashboard-layout-slots><div class="w-dashboard-layout-grid">';
        foreach ($regions as $slotId => [$className, $title, $description, $note]) {
            $slotContent = trim((string)($slotHtml[$slotId] ?? ''));
            $html .= '<section class="w-dashboard-region ' . htmlspecialchars($className, ENT_QUOTES, 'UTF-8') . ' w-dashboard-slot" data-wslot="' . htmlspecialchars($slotId, ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<div class="w-dashboard-region-head"><div><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p></div>';
            if ($note !== '') {
                $html .= '<span>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</span>';
            }
            $html .= '</div>';
            $html .= $slotContent !== ''
                ? $slotContent
                : '<div class="w-dashboard-empty slot-placeholder"><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong><span>拖入部件</span></div>';
            $html .= '</section>';
        }
        $html .= '</div></section>';

        return $html;
    }

    private function extractInnerHtml(\DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $childNode) {
            $html .= $node->ownerDocument->saveHTML($childNode);
        }

        return $html;
    }

    private function buildXpathStringLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);
        $escapedParts = array_map(
            static fn(string $part): string => "'" . $part . "'",
            $parts
        );

        return 'concat(' . implode(', "\'", ', $escapedParts) . ')';
    }
}
