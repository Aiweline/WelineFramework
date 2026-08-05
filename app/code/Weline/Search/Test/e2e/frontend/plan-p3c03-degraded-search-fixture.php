<?php

declare(strict_types=1);

/**
 * TEST-P3C-03 fixture.
 *
 * This process writes only the durable rollout gate and degradation marker.
 * The browser request is served by another WLS process, so the test cannot pass
 * through process-local harness state or injected fake Product rows.
 *
 * stdin JSON:
 * - {"action":"prepare"|"cleanup"} for TEST-P3C-03
 * - {"action":"prepare_recovery"|"recover"} for TEST-P3C-04
 * stdout JSON only.
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Api\ProductSearchProjectionSourceInterface;
use Weline\Search\Api\SearchIndexStorageInterface;
use Weline\Search\Service\SearchAliasStore;
use Weline\Search\Service\SearchDegradeMarker;
use Weline\Search\Service\SearchQueryService;
use Weline\Search\Service\SearchRolloutGate;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P3C03_REASON = 'controlled_consumer_stop';
const P3C04_REASON = 'controlled_recovery_gate';

/** @return array<string,mixed> */
function p3c03_read_input(): array
{
    $raw = \file_get_contents('php://stdin');
    if ($raw === false || \trim($raw) === '') {
        throw new \InvalidArgumentException('empty stdin');
    }
    $data = \json_decode($raw, true);
    if (!\is_array($data) || \array_is_list($data)) {
        throw new \InvalidArgumentException('stdin must be JSON object');
    }

    return $data;
}

/** @param array<string,mixed> $payload */
function p3c03_output(array $payload, int $exitCode = 0): never
{
    echo \json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ), "\n";
    exit($exitCode);
}

/** @template T of object @param class-string<T> $type @return T */
function p3c03_service(string $type): object
{
    $service = ObjectManager::create($type, [], false);
    if (!$service instanceof $type) {
        throw new \RuntimeException('p3c03_service_unavailable:' . $type);
    }

    return $service;
}

/**
 * @return array{website_id:int,store_id:int,channel_id:int,subject:string}
 */
function p3c03_default_scope(): array
{
    /** @var WebsiteCatalogInterface $websites */
    $websites = p3c03_service(WebsiteCatalogInterface::class);
    /** @var StoreCatalogInterface $stores */
    $stores = p3c03_service(StoreCatalogInterface::class);
    /** @var SalesChannelCatalogInterface $channels */
    $channels = p3c03_service(SalesChannelCatalogInterface::class);
    $websiteId = $websites->defaultWebsiteId();
    $store = $stores->defaultStore($websiteId);
    if ($store === null || !$store->enabled || $store->lifecycleStatus !== 'active') {
        throw new \RuntimeException('p3c03_default_store_unavailable');
    }
    $channel = $channels->defaultChannel($store->id);
    if ($channel === null || !$channel->effectiveEnabled) {
        throw new \RuntimeException('p3c03_default_channel_unavailable');
    }

    return [
        'website_id' => $websiteId,
        'store_id' => $store->id,
        'channel_id' => $channel->id,
        'subject' => SearchRolloutGate::tupleKey(
            $websiteId,
            $store->id,
            $channel->id,
        ),
    ];
}

/**
 * @return array{source_watermark:int,index_watermark:int}
 */
function p3c03_current_watermarks(int $websiteId): array
{
    /** @var ProductSearchProjectionSourceInterface $product */
    $product = p3c03_service(ProductSearchProjectionSourceInterface::class);
    /** @var SearchIndexStorageInterface $index */
    $index = p3c03_service(SearchIndexStorageInterface::class);

    return [
        'source_watermark' => $product->currentWatermark($websiteId),
        'index_watermark' => (int)(
            $index->watermark($websiteId)['incremental_watermark'] ?? 0
        ),
    ];
}

/**
 * Point the Website alias at the currently active Search generation.
 *
 * @return array{website_id:int,alias:string,generation:int,version:int}
 */
function p3c03_enable_index_alias(int $websiteId): array
{
    /** @var SearchIndexStorageInterface $index */
    $index = p3c03_service(SearchIndexStorageInterface::class);
    /** @var SearchAliasStore $alias */
    $alias = p3c03_service(SearchAliasStore::class);
    $activeGeneration = (int)($index->watermark($websiteId)['active_generation'] ?? 0);
    if ($activeGeneration < 1) {
        throw new \RuntimeException('p3c03_active_search_generation_required');
    }

    $current = $alias->state($websiteId);
    if ($current['alias'] !== SearchAliasStore::ALIAS_DIRECT) {
        throw new \RuntimeException(
            'p3c03_alias_not_clean_direct:'
            . $current['alias']
            . '/'
            . $current['generation'],
        );
    }
    $swapped = $alias->compareAndSwap(
        $websiteId,
        $current['alias'],
        $current['generation'],
        $current['version'],
        SearchAliasStore::ALIAS_INDEX,
        $activeGeneration,
    );
    if (!$swapped['ok']) {
        throw new \RuntimeException('p3c03_alias_index_cas_conflict');
    }

    return $alias->state($websiteId);
}

/**
 * Restore Product direct without deleting the retained Search generation.
 *
 * @return array{website_id:int,alias:string,generation:int,version:int}
 */
function p3c03_restore_direct_alias(int $websiteId): array
{
    /** @var SearchAliasStore $alias */
    $alias = p3c03_service(SearchAliasStore::class);
    $current = $alias->state($websiteId);
    if ($current['alias'] === SearchAliasStore::ALIAS_DIRECT) {
        return $current;
    }
    if ($current['alias'] !== SearchAliasStore::ALIAS_INDEX) {
        throw new \RuntimeException('p3c03_alias_state_unknown:' . $current['alias']);
    }

    $swapped = $alias->compareAndSwap(
        $websiteId,
        $current['alias'],
        $current['generation'],
        $current['version'],
        SearchAliasStore::ALIAS_DIRECT,
        0,
    );
    if (!$swapped['ok']) {
        throw new \RuntimeException('p3c03_alias_direct_cas_conflict');
    }

    return $alias->state($websiteId);
}

try {
    $input = p3c03_read_input();
    $action = \strtolower(\trim((string)($input['action'] ?? '')));
    /** @var SearchRolloutGate $gate */
    $gate = p3c03_service(SearchRolloutGate::class);
    /** @var SearchDegradeMarker $marker */
    $marker = p3c03_service(SearchDegradeMarker::class);
    $scope = p3c03_default_scope();

    if ($action === 'prepare') {
        $configuration = $gate->configuration();
        if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('p3c03_rollout_not_clean_off');
        }
        $existing = $marker->get($scope['website_id']);
        if (($existing['active'] ?? false) === true) {
            throw new \RuntimeException(
                'p3c03_unrelated_degrade_marker_active:' . (string)($existing['reason'] ?? ''),
            );
        }

        $watermarks = p3c03_current_watermarks($scope['website_id']);
        $sourceWatermark = $watermarks['source_watermark'];
        $indexWatermark = $watermarks['index_watermark'];
        $aliasState = p3c03_enable_index_alias($scope['website_id']);
        try {
            $gate->setMode(
                SearchQueryService::CAPABILITY,
                CommerceRolloutGateInterface::MODE_ALLOWLIST,
                [$scope['subject']],
            );
            $marked = $marker->mark(
                $scope['website_id'],
                P3C03_REASON,
                $sourceWatermark,
                $indexWatermark,
            );
            if (($marked['active'] ?? false) !== true
                || ($marked['reason'] ?? '') !== P3C03_REASON
            ) {
                throw new \RuntimeException('p3c03_marker_readback_failed');
            }
        } catch (\Throwable $setupException) {
            $gate->setMode(
                SearchQueryService::CAPABILITY,
                CommerceRolloutGateInterface::MODE_OFF,
            );
            $controlled = $marker->get($scope['website_id']);
            if (($controlled['active'] ?? false) === true
                && ($controlled['reason'] ?? '') === P3C03_REASON
            ) {
                $marker->clearForRollback($scope['website_id'], P3C03_REASON);
            }
            p3c03_restore_direct_alias($scope['website_id']);
            throw $setupException;
        }

        p3c03_output([
            'ok' => true,
            ...$scope,
            'expected_source' => SearchQueryService::SOURCE_DEGRADED,
            'expected_degrade_reason' => P3C03_REASON,
            'source_watermark_at_mark' => $sourceWatermark,
            'index_watermark_at_mark' => $indexWatermark,
            'marker_version' => $marked['marker_version'],
            'alias' => $aliasState['alias'],
            'alias_generation' => $aliasState['generation'],
            'alias_version' => $aliasState['version'],
            'q' => '',
        ]);
    }

    if ($action === 'prepare_recovery') {
        $configuration = $gate->configuration();
        if ($configuration['mode'] !== CommerceRolloutGateInterface::MODE_OFF
            || $configuration['allowlist'] !== []
        ) {
            throw new \RuntimeException('p3c04_rollout_not_clean_off');
        }
        $existing = $marker->get($scope['website_id']);
        if (($existing['active'] ?? false) === true) {
            throw new \RuntimeException(
                'p3c04_unrelated_degrade_marker_active:' . (string)($existing['reason'] ?? ''),
            );
        }
        $watermarks = p3c03_current_watermarks($scope['website_id']);
        if ($watermarks['source_watermark'] < 1
            || $watermarks['index_watermark'] !== $watermarks['source_watermark']
        ) {
            throw new \RuntimeException(
                'p3c04_caught_up_watermarks_required:'
                . $watermarks['index_watermark']
                . '/'
                . $watermarks['source_watermark'],
            );
        }
        $marked = $marker->mark(
            $scope['website_id'],
            P3C04_REASON,
            $watermarks['source_watermark'],
            $watermarks['index_watermark'],
        );
        p3c03_output([
            'ok' => ($marked['active'] ?? false) === true,
            ...$scope,
            ...$watermarks,
            'marker_version' => $marked['marker_version'],
            'reason' => P3C04_REASON,
        ]);
    }

    if ($action === 'recover') {
        $active = $marker->get($scope['website_id']);
        if (($active['active'] ?? false) !== true
            || ($active['reason'] ?? '') !== P3C04_REASON
        ) {
            throw new \RuntimeException('p3c04_controlled_marker_missing');
        }
        $watermarks = p3c03_current_watermarks($scope['website_id']);
        $laggedIndex = \max(0, $watermarks['source_watermark'] - 1);
        $lagRejected = false;
        $lagErrorCode = '';
        try {
            $marker->clearIfRecovered(
                $scope['website_id'],
                $laggedIndex,
                $watermarks['source_watermark'],
            );
        } catch (\Weline\Search\Service\SearchQueryException $exception) {
            $lagErrorCode = $exception->errorCode;
            $lagRejected = $lagErrorCode
                === \Weline\Search\Service\SearchQueryException::ERROR_RECOVERY_WATERMARK;
        }
        if (!$lagRejected
            || ($marker->get($scope['website_id'])['active'] ?? false) !== true
        ) {
            throw new \RuntimeException('p3c04_lagged_recovery_not_rejected');
        }

        /** @var SearchDegradeMarker $freshMarker */
        $freshMarker = p3c03_service(SearchDegradeMarker::class);
        $cleared = $freshMarker->clearIfRecovered(
            $scope['website_id'],
            $watermarks['index_watermark'],
            $watermarks['source_watermark'],
        );
        p3c03_output([
            'ok' => ($cleared['active'] ?? true) === false,
            ...$scope,
            ...$watermarks,
            'lagged_index_watermark' => $laggedIndex,
            'lag_rejected' => $lagRejected,
            'lag_error_code' => $lagErrorCode,
            'marker_active' => ($cleared['active'] ?? true) === true,
            'marker_version' => $cleared['marker_version'],
        ]);
    }

    if ($action === 'cleanup') {
        $gate->setMode(
            SearchQueryService::CAPABILITY,
            CommerceRolloutGateInterface::MODE_OFF,
        );
        $readback = $marker->get($scope['website_id']);
        $activeReason = (string)($readback['reason'] ?? '');
        $cleared = ($readback['active'] ?? false) !== true;
        if (!$cleared && \in_array($activeReason, [P3C03_REASON, P3C04_REASON], true)) {
            $cleared = $marker->clearForRollback(
                $scope['website_id'],
                $activeReason,
            );
            $readback = $marker->get($scope['website_id']);
        }
        $aliasState = p3c03_restore_direct_alias($scope['website_id']);
        p3c03_output([
            'ok' => $cleared
                && ($readback['active'] ?? false) !== true
                && $aliasState['alias'] === SearchAliasStore::ALIAS_DIRECT,
            'cleaned' => $cleared,
            'rollout_mode' => $gate->mode(SearchQueryService::CAPABILITY),
            'marker_active' => ($readback['active'] ?? false) === true,
            'alias' => $aliasState['alias'],
            'alias_generation' => $aliasState['generation'],
            'alias_version' => $aliasState['version'],
        ], $cleared && $aliasState['alias'] === SearchAliasStore::ALIAS_DIRECT ? 0 : 1);
    }

    throw new \InvalidArgumentException('unknown action: ' . $action);
} catch (\Throwable $exception) {
    p3c03_output([
        'ok' => false,
        'error' => $exception->getMessage(),
        'error_class' => $exception::class,
    ], 1);
}
