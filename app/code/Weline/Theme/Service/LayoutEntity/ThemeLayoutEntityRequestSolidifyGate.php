<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Storefront gate: detect stale/missing solidify stamp → enqueue async job → force original template.
 * While pending: do not read stale derived PHTML.
 */
final class ThemeLayoutEntityRequestSolidifyGate
{
    public const CTX_FORCE_ORIGINAL = 'theme.layout_entity.request_solidify.force_original.v1';
    public const CTX_DECISION = 'theme.layout_entity.request_solidify.decision.v1';

    public function __construct(
        private readonly ThemeLayoutEntitySolidifyStampStore $stamps,
        private readonly ThemeLayoutEntitySolidifyQueue $queue,
        private readonly ThemeLayoutEntityPaths $paths,
        private readonly ThemeScopeVersionService $scopeVersions,
        private readonly DefaultInjectionPlanRepository $injectionPlans,
    ) {
    }

    /**
     * @return array{
     *   use_original_template: bool,
     *   reason: string,
     *   enqueued: bool,
     *   coalesced: bool,
     *   pending: bool,
     *   expected_fp: string,
     *   serial_key: string
     * }
     */
    public function evaluate(
        int $themeId,
        string $area,
        string $canonicalScope,
        string $storeMode,
        string $layoutType,
        string $layoutOption = 'default',
    ): array {
        $area = trim($area) !== '' ? trim($area) : 'frontend';
        $scope = trim($canonicalScope) !== '' ? trim($canonicalScope) : 'default.default.default';
        $storeMode = trim($storeMode) !== '' ? trim($storeMode) : 'normal';
        $layoutType = trim($layoutType) !== '' ? trim($layoutType) : 'homepage';
        $layoutOption = trim($layoutOption) !== '' ? trim($layoutOption) : 'default';

        $versionId = 0;
        $revision = 0;
        try {
            $published = $this->scopeVersions->getPublished($themeId, $scope, $storeMode, $area);
            if ($published !== null) {
                $versionId = (int)$published->getVersionId();
                $revision = max(0, (int)$published->getContentRevision());
            }
        } catch (\Throwable) {
            // treat as missing
        }

        $key = ThemeLayoutEntitySolidifySerialKey::fromParts(
            $themeId,
            $area,
            $scope,
            $storeMode,
            $layoutType,
            $layoutOption,
            $versionId,
            $revision,
        );
        $expectedFp = $this->expectedInjectionFingerprint();
        $pending = $this->queue->isPending($key);
        $actualFp = $this->stamps->read($key);
        $derivedMissing = !$this->hasDerivedPage($themeId, $scope, $storeMode, $area, $layoutType, $layoutOption, $versionId, $revision);
        $criticalEmpty = $this->chromeFooterExtrasEmpty($themeId, $scope, $storeMode, $area, $versionId, $revision)
            || $this->filterInventoryBakeBroken(
                $themeId,
                $scope,
                $storeMode,
                $area,
                $layoutType,
                $layoutOption,
                $versionId,
                $revision,
            );
        $stale = $actualFp === null || !hash_equals($expectedFp, $actualFp);
        // Stamp match means async job already finished this fingerprint.
        // Homepage (and similar) may intentionally bake path=>null (no layout-entity
        // intent) and keep using the source template — do NOT treat derived_missing
        // alone as needsJob or the queue re-enqueues forever after every successful job.
        $stampFresh = $actualFp !== null && hash_equals($expectedFp, $actualFp);
        $needsJob = $pending
            || $stale
            || $criticalEmpty
            || ($derivedMissing && !$stampFresh);
        $enqueued = false;
        $coalesced = false;
        if ($needsJob) {
            $result = $this->queue->enqueue($key, $expectedFp);
            $enqueued = (bool)($result['enqueued'] ?? false);
            $coalesced = (bool)($result['coalesced'] ?? false);
            $pending = $pending || (bool)($result['pending'] ?? false) || $enqueued || $coalesced;
        }

        $useOriginal = $needsJob;
        $reason = 'fresh';
        if ($pending && ($enqueued || $coalesced)) {
            $reason = $enqueued ? 'enqueued_fallback_original' : 'pending_coalesce_fallback_original';
        } elseif ($criticalEmpty) {
            $reason = $this->filterInventoryBakeBroken(
                $themeId,
                $scope,
                $storeMode,
                $area,
                $layoutType,
                $layoutOption,
                $versionId,
                $revision,
            ) ? 'empty_filters_inventory_fallback_original' : 'empty_footer_extras_fallback_original';
        } elseif ($stale) {
            $reason = 'fingerprint_stale_fallback_original';
        } elseif ($derivedMissing && !$stampFresh) {
            $reason = 'derived_missing_fallback_original';
        } elseif ($derivedMissing && $stampFresh) {
            // Job completed; layout keeps source template by design (null candidate).
            $reason = 'stamp_fresh_source_template';
            $useOriginal = false;
        }

        $decision = [
            'use_original_template' => $useOriginal,
            'reason' => $reason,
            'enqueued' => $enqueued,
            'coalesced' => $coalesced,
            'pending' => $pending,
            'expected_fp' => $expectedFp,
            'serial_key' => $key->toString(),
        ];
        RequestContext::set(self::CTX_DECISION, $decision);
        if ($useOriginal) {
            RequestContext::set(self::CTX_FORCE_ORIGINAL, true);
            ThemeLayoutEntityPublishedSlotHost::markSolidifiedControllerTemplateSelected(false);
            // Fallback original must not enter shared FPC. Browser Cache-Control
            // no-store is owned here; vendor edge headers (CDN-Cache-Control / CF)
            // are written by Weline_Cdn observers on the Framework forbid event.
            SharedResponseCachePolicy::forbid('theme_layout_solidify_fallback_original');
        } else {
            RequestContext::remove(self::CTX_FORCE_ORIGINAL);
        }

        return $decision;
    }

    public function shouldForceOriginalTemplate(): bool
    {
        return RequestContext::get(self::CTX_FORCE_ORIGINAL) === true;
    }

    public function expectedInjectionFingerprint(): string
    {
        try {
            $declarations = $this->injectionPlans->listDeclarations('frontend');
        } catch (\Throwable) {
            $declarations = [];
        }
        $relevant = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            $injections = $declaration['default_injections'] ?? [];
            if ($injections !== [] && !array_is_list($injections)) {
                $injections = [$injections];
            }
            foreach ($injections as $injection) {
                if (!is_array($injection)) {
                    continue;
                }
                $layout = (string)($injection['layout_type'] ?? '');
                $code = (string)($injection['widget_code'] ?? $declaration['code'] ?? '');
                $slot = (string)($injection['slot'] ?? '');
                $isMiniCartCritical = $layout === 'mini-cart'
                    || $slot === 'footer-extras'
                    || $code === 'mini-cart-coupon'
                    || $code === 'order-notice';
                $isFiltersCritical = $code === 'category-filters'
                    || $slot === 'list-filters'
                    || $slot === 'category-filters'
                    || \in_array($layout, ['products', 'category', 'search'], true);
                if ($isMiniCartCritical || $isFiltersCritical) {
                    $relevant[] = [
                        'layout_type' => $layout,
                        'layout_option' => (string)($injection['layout_option'] ?? 'default'),
                        'slot' => $slot,
                        'code' => $code,
                        'required' => (bool)($injection['required'] ?? false),
                    ];
                }
            }
        }
        usort($relevant, static fn(array $a, array $b): int => strcmp(
            $a['layout_type'] . '|' . $a['code'] . '|' . $a['slot'],
            $b['layout_type'] . '|' . $b['code'] . '|' . $b['slot'],
        ));

        return hash('sha256', json_encode($relevant, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function hasDerivedPage(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        string $layoutType,
        string $layoutOption,
        int $versionId,
        int $revision,
    ): bool {
        if ($versionId < 1) {
            return false;
        }
        try {
            $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
                $themeId,
                $scope,
                $storeMode,
                $area,
                $versionId,
                'formal',
                max(1, $revision),
            );
            $path = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutOption);

            return is_file($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function chromeFooterExtrasEmpty(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        int $versionId,
        int $revision,
    ): bool {
        if ($versionId < 1) {
            // Missing publish is handled as derived_missing; do not double-count.
            return false;
        }
        try {
            $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
                $themeId,
                $scope,
                $storeMode,
                $area,
                $versionId,
                'formal',
                max(1, $revision),
            );
            $header = $this->paths->partialPhtml($identity, 'header', 'default');
            $haystacks = [];
            if (is_file($header)) {
                $haystacks[] = (string)@file_get_contents($header);
            }
            $mini = $this->paths->pageLayoutPhtml($identity, 'mini-cart', 'default');
            if (is_file($mini)) {
                $haystacks[] = (string)@file_get_contents($mini);
            }
            // Also check common storefront-shell path used by design themes.
            $shellPath = dirname($header) . '/storefront-shell.phtml';
            if (is_file($shellPath)) {
                $haystacks[] = (string)@file_get_contents($shellPath);
            }
            if ($haystacks === []) {
                return true;
            }
            $blob = implode("\n", $haystacks);
            $hasCoupon = str_contains($blob, 'mini-cart-coupon') || str_contains($blob, "widget_code' => 'mini-cart-coupon");
            $hasNotice = str_contains($blob, 'order-notice') || str_contains($blob, "widget_code' => 'order-notice");
            // Critical empty when neither required widget is present in chrome/header bake.
            return !($hasCoupon && $hasNotice);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * products/category/search derived PHTML must bake Weline_Filters::category-filters
     * and must not retain declaration placeholders. Broken bake → enqueue + original
     * (fallback injector covers the customer response until rebake lands).
     */
    private function filterInventoryBakeBroken(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        string $layoutType,
        string $layoutOption,
        int $versionId,
        int $revision,
    ): bool {
        if (!\in_array($layoutType, ['products', 'category', 'search'], true)) {
            return false;
        }
        if ($versionId < 1) {
            return false;
        }
        try {
            $identity = new \Weline\Theme\Api\Version\ThemeVersionIdentity(
                $themeId,
                $scope,
                $storeMode,
                $area,
                $versionId,
                'formal',
                max(1, $revision),
            );
            $path = $this->paths->pageLayoutPhtml($identity, $layoutType, $layoutOption);
            if (!is_file($path)) {
                // derived_missing already drives the job; avoid double-count reason noise.
                return false;
            }
            $blob = (string)@file_get_contents($path);
            if ($blob === '') {
                return true;
            }
            $hasFilters = str_contains($blob, 'Weline_Filters')
                && str_contains($blob, 'category-filters')
                && str_contains($blob, 'renderResolved');
            $hasPlaceholder = str_contains($blob, 'data-placeholder="list-filters"')
                || str_contains($blob, 'data-placeholder="category-filters"')
                || str_contains($blob, "data-placeholder='list-filters'")
                || str_contains($blob, "data-placeholder='category-filters'")
                || str_contains($blob, '由 Filters 部件默认注入');

            return !$hasFilters || $hasPlaceholder;
        } catch (\Throwable) {
            return true;
        }
    }
}
