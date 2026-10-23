<?php
declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Service\ThemeScopeVersionService;
use Weline\Widget\Service\DefaultInjectionPlanRepository;

/**
 * Storefront gate: detect stale/missing solidify stamp → enqueue async job → force original template.
 * While pending: do not read stale derived PHTML.
 *
 * Circuit breaker: when the slot's bake has failed `fail_count >= threshold` times inside the
 * cooldown window ({@see ThemeLayoutEntitySolidifyQueue::circuitState()}), the gate stops
 * enqueueing and stops forbidding shared FPC — the source template is served cacheably with
 * `X-Weline-Solidify-Decision: solidify_circuit_open`. A persistent bake defect must never turn
 * the whole storefront into per-request SSR.
 */
final class ThemeLayoutEntityRequestSolidifyGate
{
    public const CTX_FORCE_ORIGINAL = 'theme.layout_entity.request_solidify.force_original.v1';
    public const CTX_DECISION = 'theme.layout_entity.request_solidify.decision.v1';
    public const CTX_DERIVED_PAGE_PATH_MEMO = 'theme.layout_entity.request_solidify.derived_page_path.v1';

    /** Critical public chrome partials checked for design-origin drift (WS-A). */
    private const CRITICAL_CHROME_PARTIALS = ['header', 'footer'];

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

        $cached = RequestContext::get(self::CTX_DECISION);
        if (\is_array($cached)
            && isset($cached['serial_key'], $cached['use_original_template'], $cached['reason'])
            && ($cached['_eval_key'] ?? null) === self::evaluateRequestKey(
                $themeId,
                $area,
                $scope,
                $storeMode,
                $layoutType,
                $layoutOption,
            )
        ) {
            return $cached;
        }

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
        $originStale = !$derivedMissing && $this->derivedPageOriginStale(
            $themeId,
            $scope,
            $storeMode,
            $area,
            $layoutType,
            $layoutOption,
            $versionId,
            $revision,
        );
        $chromeOriginStale = $this->derivedChromeOriginStale(
            $themeId,
            $scope,
            $storeMode,
            $area,
            $versionId,
            $revision,
        );
        $needsJob = $pending
            || $stale
            || $criticalEmpty
            || $originStale
            || $chromeOriginStale
            || ($derivedMissing && !$stampFresh);
        // Circuit breaker: the bake for this slot has failed repeatedly (dead-letter
        // fail_count >= threshold, still inside cooldown). Re-enqueueing would fail
        // again and `forbid()` would keep the whole storefront out of shared FPC for a
        // defect that is not request-specific. Serve the source template, allow FPC,
        // and let the Cron drain retry once the cooldown elapses (half-open).
        $circuit = $needsJob && !$pending ? $this->queue->circuitState($key) : ['open' => false];
        $circuitOpen = (bool)($circuit['open'] ?? false);
        $enqueued = false;
        $coalesced = false;
        if ($needsJob && !$circuitOpen) {
            $result = $this->queue->enqueue($key, $expectedFp);
            $enqueued = (bool)($result['enqueued'] ?? false);
            $coalesced = (bool)($result['coalesced'] ?? false);
            $pending = $pending || (bool)($result['pending'] ?? false) || $enqueued || $coalesced;
        }

        $useOriginal = $needsJob;
        $reason = 'fresh';
        if ($circuitOpen) {
            $reason = 'solidify_circuit_open';
        } elseif ($pending && ($enqueued || $coalesced)) {
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
        } elseif ($chromeOriginStale) {
            $reason = 'design_chrome_origin_stale_fallback_original';
        } elseif ($originStale) {
            $reason = 'design_origin_stale_fallback_original';
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
            'circuit_open' => $circuitOpen,
            'circuit_fail_count' => (int)($circuit['fail_count'] ?? 0),
            'expected_fp' => $expectedFp,
            'serial_key' => $key->toString(),
            'chrome_origin_stale' => $chromeOriginStale,
            '_eval_key' => self::evaluateRequestKey(
                $themeId,
                $area,
                $scope,
                $storeMode,
                $layoutType,
                $layoutOption,
            ),
        ];
        RequestContext::set(self::CTX_DECISION, $decision);
        // Always expose fallback reason on the wire so BYPASS/no-store is not
        // mistaken for "FPC broken" or browser Disable-cache. Verbose NDJSON
        // logging stays behind WELINE_THEME_SOLIDIFY_DEBUG.
        $this->exportSolidifyDecisionObservability($decision);
        if ($useOriginal) {
            RequestContext::set(self::CTX_FORCE_ORIGINAL, true);
            ThemeLayoutEntityPublishedSlotHost::markSolidifiedControllerTemplateSelected(false);
            // Fallback original must not enter shared FPC while a bake is pending/expected.
            // Browser Cache-Control no-store is owned here; vendor edge headers
            // (CDN-Cache-Control / CF) are written by Weline_Cdn observers on the
            // Framework forbid event. With the circuit OPEN no bake is coming: the
            // source template IS the page for now, so shared FPC stays allowed and the
            // drain bumps the scoped Theme generation when the slot recovers.
            if (!$circuitOpen) {
                SharedResponseCachePolicy::forbid('theme_layout_solidify_fallback_original');
            }
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
                    || $code === 'checkout-coupon'
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
        return $this->derivedPagePath(
            $themeId,
            $scope,
            $storeMode,
            $area,
            $layoutType,
            $layoutOption,
            $versionId,
            $revision,
        ) !== null;
    }

    /**
     * Design/layout origin drifted vs weline-source.input_digest → force original + re-solidify.
     * Missing input_digest on legacy entities counts as stale (one re-bake heals).
     */
    private function derivedPageOriginStale(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        string $layoutType,
        string $layoutOption,
        int $versionId,
        int $revision,
    ): bool {
        $path = $this->derivedPagePath(
            $themeId,
            $scope,
            $storeMode,
            $area,
            $layoutType,
            $layoutOption,
            $versionId,
            $revision,
        );
        if ($path === null) {
            return false;
        }
        try {
            $meta = ThemeLayoutSourceSnapshot::metadata((string)file_get_contents($path));
        } catch (\Throwable) {
            return true;
        }
        if (!is_array($meta)) {
            return true;
        }
        $origin = (string)($meta['origin'] ?? '');
        $digest = (string)($meta['input_digest'] ?? '');

        return !ThemeLayoutEntityPageInputDigest::matchesStored($digest, $origin);
    }

    /**
     * Critical chrome partials (header/footer): RAW (no weline-source), missing input_digest,
     * or digest ≠ live origin → force original + re-solidify (do not pin stale chrome).
     */
    private function derivedChromeOriginStale(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
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
        } catch (\Throwable) {
            return false;
        }
        foreach (self::CRITICAL_CHROME_PARTIALS as $type) {
            $path = $this->paths->partialPhtml($identity, $type, 'default');
            if (!is_file($path)) {
                continue;
            }
            try {
                $meta = ThemeLayoutSourceSnapshot::metadata((string)file_get_contents($path));
            } catch (\Throwable) {
                return true;
            }
            if (!is_array($meta)) {
                return true;
            }
            $digest = (string)($meta['input_digest'] ?? '');
            $origin = (string)($meta['origin'] ?? '');
            if (!ThemeLayoutEntityPageInputDigest::matchesStored($digest, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private function exportSolidifyDecisionObservability(array $decision): void
    {
        if (!empty($decision['use_original_template'])) {
            try {
                $response = ObjectManager::getInstance(Request::class)->getResponse();
                $response->setHeader(
                    'X-Weline-Solidify-Decision',
                    substr((string)($decision['reason'] ?? ''), 0, 120),
                );
                $response->setHeader(
                    'X-Weline-Solidify-Pending',
                    !empty($decision['pending']) ? '1' : '0',
                );
            } catch (\Throwable) {
                // Request/response may be unavailable in CLI / unit contexts.
            }
        }

        if ((string)getenv('WELINE_THEME_SOLIDIFY_DEBUG') !== '1') {
            return;
        }
        try {
            $payload = json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            $payload = (string)($decision['reason'] ?? 'unknown');
        }
        Env::log('theme/request_solidify_decision', $payload);
    }

    private static function evaluateRequestKey(
        int $themeId,
        string $area,
        string $scope,
        string $storeMode,
        string $layoutType,
        string $layoutOption,
    ): string {
        return \implode('|', [
            (string)$themeId,
            $area,
            $scope,
            $storeMode,
            $layoutType,
            $layoutOption,
        ]);
    }

    private function derivedPagePath(
        int $themeId,
        string $scope,
        string $storeMode,
        string $area,
        string $layoutType,
        string $layoutOption,
        int $versionId,
        int $revision,
    ): ?string {
        if ($versionId < 1) {
            return null;
        }
        $memoKey = \implode('|', [
            (string)$themeId,
            $scope,
            $storeMode,
            $area,
            $layoutType,
            $layoutOption,
            (string)$versionId,
            (string)$revision,
        ]);
        $memo = RequestContext::get(self::CTX_DERIVED_PAGE_PATH_MEMO);
        if (\is_array($memo) && \array_key_exists($memoKey, $memo)) {
            $cached = $memo[$memoKey];

            return \is_string($cached) && $cached !== '' ? $cached : null;
        }
        $resolved = null;
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
            $resolved = is_file($path) ? $path : null;
        } catch (\Throwable) {
            $resolved = null;
        }
        if (!\is_array($memo)) {
            $memo = [];
        }
        $memo[$memoKey] = $resolved ?? '';
        RequestContext::set(self::CTX_DERIVED_PAGE_PATH_MEMO, $memo);

        return $resolved;
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
            // Design themes often bake widgets into storefront-shell while header/default
            // stays empty — do NOT OR-merge with shell or the gate never heals default.
            $shellPath = dirname($header) . '/storefront-shell.phtml';
            if (is_file($shellPath)) {
                $haystacks[] = (string)@file_get_contents($shellPath);
            }
            if ($haystacks === []) {
                return true;
            }
            $anyComplete = false;
            foreach ($haystacks as $blob) {
                $hasCoupon = str_contains($blob, 'checkout-coupon')
                    || str_contains($blob, "widget_code' => 'checkout-coupon")
                    || str_contains($blob, 'mini-cart-coupon')
                    || str_contains($blob, "widget_code' => 'mini-cart-coupon");
                $hasNotice = str_contains($blob, 'order-notice') || str_contains($blob, "widget_code' => 'order-notice");
                if ($hasCoupon && $hasNotice) {
                    $anyComplete = true;
                    break;
                }
            }
            // Still critical-empty when *no* baked header/shell/mini-cart carries both widgets.
            // (Per-file empty default while shell is complete is OK — storefront may use shell.)
            return !$anyComplete;
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
