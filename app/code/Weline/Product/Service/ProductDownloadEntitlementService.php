<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetManagerInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Service\DatabaseTransactionRunnerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Product\Api\ProductDownloadEntitlementException;
use Weline\Product\Api\ProductDownloadEntitlementInterface;
use Weline\Product\Model\DownloadEntitlement;
use Weline\Product\Model\DownloadEntitlementAudit;
use Weline\Storage\Api\Data\StorageUrlOptions;

final class ProductDownloadEntitlementService implements ProductDownloadEntitlementInterface
{
    private const MAX_DOWNLOAD_LIMIT = 1000000;
    private const MAX_EXPIRY_DAYS = 36500;

    /** @var (\Closure(): DownloadEntitlement)|null */
    private readonly mixed $entitlementFactory;

    /** @var (\Closure(): DownloadEntitlementAudit)|null */
    private readonly mixed $auditFactory;

    /**
     * @param (\Closure(): DownloadEntitlement)|null $entitlementFactory
     * @param (\Closure(): DownloadEntitlementAudit)|null $auditFactory
     */
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DatabaseTransactionRunnerInterface $transactions,
        private readonly ?OrderFacadeInterface $orders = null,
        private readonly ?FileAssetManagerInterface $assets = null,
        ?callable $entitlementFactory = null,
        ?callable $auditFactory = null,
    ) {
        $this->entitlementFactory = $entitlementFactory;
        $this->auditFactory = $auditFactory;
    }

    public function grantForPaidOrder(string $orderUuid): array
    {
        $orderUuid = $this->uuid($orderUuid, 'download_order_uuid_invalid');
        $order = $this->orderFacade()->get($orderUuid)->toArray();
        $websiteId = max(0, (int)($order['website_id'] ?? 0));
        $storeId = max(0, (int)($order['store_id'] ?? 0));
        $customerId = (int)($order['customer_id'] ?? 0);
        $candidates = [];

        foreach ((array)($order['items'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $digital = $item['fulfillment_metadata']['digital_download'] ?? null;
            if (!is_array($digital)) {
                continue;
            }
            if ($customerId < 1) {
                throw new ProductDownloadEntitlementException(
                    'download_customer_required',
                    (string)__('下载商品订单必须属于已登录客户'),
                    ['order_uuid' => $orderUuid],
                );
            }
            $lineKey = $this->lineKey($item, (int)$index);
            $productUuid = $this->uuid(
                (string)($digital['global_product_uuid'] ?? ''),
                'download_product_uuid_invalid',
            );
            $offerUuid = $this->uuid(
                (string)($digital['global_offer_uuid'] ?? ''),
                'download_offer_uuid_invalid',
            );
            $policy = is_array($digital['entitlement_policy'] ?? null)
                ? $digital['entitlement_policy']
                : [];
            $limit = $this->positiveOrNull(
                $policy['download_limit'] ?? null,
                self::MAX_DOWNLOAD_LIMIT,
                'download_limit_invalid',
            );
            $days = $this->positiveOrNull(
                $policy['expires_after_days'] ?? null,
                self::MAX_EXPIRY_DAYS,
                'download_expiry_invalid',
            );
            $assetRows = $digital['assets'] ?? null;
            if (!is_array($assetRows) || $assetRows === []) {
                throw new ProductDownloadEntitlementException(
                    'download_asset_snapshot_invalid',
                    (string)__('订单下载资产快照无效'),
                    ['order_uuid' => $orderUuid, 'line_key' => $lineKey],
                );
            }
            foreach ($assetRows as $asset) {
                if (!is_array($asset)) {
                    throw new ProductDownloadEntitlementException(
                        'download_asset_snapshot_invalid',
                        (string)__('订单下载资产快照无效'),
                    );
                }
                $assetId = $this->uuid(
                    (string)($asset['asset_id'] ?? ''),
                    'download_asset_id_invalid',
                );
                $assetRevision = max(0, (int)($asset['asset_revision'] ?? 0));
                $policyRevision = max(0, (int)($asset['policy_revision'] ?? 0));
                if ($assetRevision < 1 || $policyRevision < 1) {
                    throw new ProductDownloadEntitlementException(
                        'download_asset_snapshot_invalid',
                        (string)__('订单下载资产修订快照无效'),
                    );
                }
                $snapshot = [
                    'order_uuid' => $orderUuid,
                    'line_key' => $lineKey,
                    'customer_id' => $customerId,
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'global_product_uuid' => $productUuid,
                    'global_offer_uuid' => $offerUuid,
                    'asset_id' => $assetId,
                    'asset_revision' => $assetRevision,
                    'policy_revision' => $policyRevision,
                    'asset_name' => $this->safeName((string)($asset['name'] ?? '')),
                    'download_limit' => $limit,
                    'expires_after_days' => $days,
                ];
                $candidates[] = $snapshot + [
                    'grant_key' => hash('sha256', $orderUuid . "\0" . $lineKey . "\0" . $assetId),
                    'snapshot_hash' => $this->hash($snapshot),
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        return $this->transactions->run(
            $this->connectionFactory,
            function () use ($candidates): array {
                $result = [];
                foreach ($candidates as $candidate) {
                    $existing = $this->findByGrantKey((string)$candidate['grant_key']);
                    if ($existing !== null) {
                        if (!hash_equals(
                            (string)$existing->getData(DownloadEntitlement::schema_fields_SNAPSHOT_HASH),
                            (string)$candidate['snapshot_hash'],
                        )) {
                            throw new ProductDownloadEntitlementException(
                                'download_entitlement_replay_conflict',
                                (string)__('订单下载权益快照与已有授权不一致'),
                            );
                        }
                        $result[] = $this->grantResult($existing, true);
                        continue;
                    }

                    $now = date('Y-m-d H:i:s');
                    $days = $candidate['expires_after_days'];
                    $expiresAt = $days === null
                        ? null
                        : date('Y-m-d H:i:s', time() + ((int)$days * 86400));
                    $row = $this->newEntitlement()->clear()->setData([
                        DownloadEntitlement::schema_fields_UUID
                            => $this->uuidFromHash((string)$candidate['grant_key']),
                        DownloadEntitlement::schema_fields_GRANT_KEY => $candidate['grant_key'],
                        DownloadEntitlement::schema_fields_SNAPSHOT_HASH => $candidate['snapshot_hash'],
                        DownloadEntitlement::schema_fields_ORDER_UUID => $candidate['order_uuid'],
                        DownloadEntitlement::schema_fields_ORDER_LINE_KEY => $candidate['line_key'],
                        DownloadEntitlement::schema_fields_CUSTOMER_ID => $candidate['customer_id'],
                        DownloadEntitlement::schema_fields_WEBSITE_ID => $candidate['website_id'],
                        DownloadEntitlement::schema_fields_STORE_ID => $candidate['store_id'],
                        DownloadEntitlement::schema_fields_PRODUCT_UUID => $candidate['global_product_uuid'],
                        DownloadEntitlement::schema_fields_OFFER_UUID => $candidate['global_offer_uuid'],
                        DownloadEntitlement::schema_fields_ASSET_ID => $candidate['asset_id'],
                        DownloadEntitlement::schema_fields_ASSET_REVISION => $candidate['asset_revision'],
                        DownloadEntitlement::schema_fields_POLICY_REVISION => $candidate['policy_revision'],
                        DownloadEntitlement::schema_fields_ASSET_NAME => $candidate['asset_name'],
                        DownloadEntitlement::schema_fields_DOWNLOAD_LIMIT => $candidate['download_limit'],
                        DownloadEntitlement::schema_fields_DOWNLOAD_COUNT => 0,
                        DownloadEntitlement::schema_fields_EXPIRES_AT => $expiresAt,
                        DownloadEntitlement::schema_fields_STATUS => DownloadEntitlement::STATUS_ACTIVE,
                        DownloadEntitlement::schema_fields_VERSION => 1,
                        DownloadEntitlement::schema_fields_GRANTED_AT => $now,
                        DownloadEntitlement::schema_fields_CREATED_AT => $now,
                        DownloadEntitlement::schema_fields_UPDATED_AT => $now,
                    ]);
                    $row->save();
                    $this->audit(
                        (string)$row->getData(DownloadEntitlement::schema_fields_UUID),
                        (int)$candidate['customer_id'],
                        (int)$candidate['customer_id'],
                        (int)$candidate['website_id'],
                        'grant',
                        'ok',
                        ['order_uuid' => $candidate['order_uuid'], 'line_key' => $candidate['line_key']],
                    );
                    $result[] = $this->grantResult($row, false);
                }
                return $result;
            },
        );
    }

    public function consume(
        string $entitlementUuid,
        int $customerId,
        ScopeIdentity $scope,
        string $localeCode = '',
    ): array {
        $entitlementUuid = $this->uuid($entitlementUuid, 'download_entitlement_uuid_invalid');
        if ($customerId < 1 || $scope->isGlobal() || $scope->websiteId === null) {
            throw new ProductDownloadEntitlementException(
                'download_access_context_invalid',
                (string)__('下载访问上下文无效'),
            );
        }

        try {
            return $this->transactions->run(
                $this->connectionFactory,
                function () use ($entitlementUuid, $customerId, $scope, $localeCode): array {
                    $row = $this->findByUuid($entitlementUuid, true);
                    if ($row === null) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_not_found',
                            (string)__('下载权益不存在'),
                        );
                    }
                    $ownerId = (int)$row->getData(DownloadEntitlement::schema_fields_CUSTOMER_ID);
                    $websiteId = (int)$row->getData(DownloadEntitlement::schema_fields_WEBSITE_ID);
                    if ($ownerId !== $customerId) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_forbidden',
                            (string)__('无权访问该下载权益'),
                        );
                    }
                    if ($websiteId !== (int)$scope->websiteId) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_website_mismatch',
                            (string)__('下载权益不属于当前 Website'),
                        );
                    }

                    $status = (string)$row->getData(DownloadEntitlement::schema_fields_STATUS);
                    if ($status === DownloadEntitlement::STATUS_EXHAUSTED) {
                        throw new ProductDownloadEntitlementException(
                            'download_limit_exceeded',
                            (string)__('下载次数已经用完'),
                        );
                    }
                    if ($status !== DownloadEntitlement::STATUS_ACTIVE) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_inactive',
                            (string)__('下载权益当前不可用'),
                        );
                    }
                    $expiresAt = trim((string)$row->getData(DownloadEntitlement::schema_fields_EXPIRES_AT));
                    if ($expiresAt !== '' && (int)strtotime($expiresAt) <= time()) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_expired',
                            (string)__('下载权益已经过期'),
                        );
                    }
                    $downloadCount = max(
                        0,
                        (int)$row->getData(DownloadEntitlement::schema_fields_DOWNLOAD_COUNT),
                    );
                    $downloadLimit = $row->getData(DownloadEntitlement::schema_fields_DOWNLOAD_LIMIT);
                    $downloadLimit = $downloadLimit === null ? null : max(0, (int)$downloadLimit);
                    if ($downloadLimit !== null && $downloadCount >= $downloadLimit) {
                        throw new ProductDownloadEntitlementException(
                            'download_limit_exceeded',
                            (string)__('下载次数已经用完'),
                        );
                    }

                    $assetId = (string)$row->getData(DownloadEntitlement::schema_fields_ASSET_ID);
                    try {
                        $asset = $this->assetManager()->get($assetId);
                        $frozenRevision = (int)$row->getData(
                            DownloadEntitlement::schema_fields_ASSET_REVISION,
                        );
                        if ($asset->getAssetId() === ''
                            || $asset->isDeleted()
                            || !$asset->isReady()
                            || $asset->getVisibility() !== FileAsset::VISIBILITY_PRIVATE
                            || (int)$asset->getData(FileAsset::schema_fields_ASSET_REVISION)
                                !== $frozenRevision
                        ) {
                            throw new \RuntimeException('download_asset_revision_changed');
                        }
                        $locale = trim($localeCode) ?: $asset->getDefaultLocale();
                        $locale = $locale !== '' ? $locale : 'en_US';
                        $resolved = $this->assetManager()->resolveUrl(
                            $assetId,
                            new FileAccessContext(
                                scope: $scope,
                                localeCode: $locale,
                                actorId: $customerId,
                                roles: ['product_download'],
                                purpose: 'product_download',
                                policyRevision: (int)$row->getData(
                                    DownloadEntitlement::schema_fields_POLICY_REVISION,
                                ),
                            ),
                            new StorageUrlOptions(
                                kind: StorageUrlOptions::KIND_TEMPORARY,
                                ttlSeconds: 300,
                            ),
                        );
                    } catch (\Throwable $exception) {
                        throw new ProductDownloadEntitlementException(
                            'download_asset_unavailable',
                            (string)__('下载文件当前不可用，请稍后重试'),
                            [],
                            $exception,
                        );
                    }

                    $version = max(1, (int)$row->getData(DownloadEntitlement::schema_fields_VERSION));
                    $nextCount = $downloadCount + 1;
                    $nextStatus = $downloadLimit !== null && $nextCount >= $downloadLimit
                        ? DownloadEntitlement::STATUS_EXHAUSTED
                        : DownloadEntitlement::STATUS_ACTIVE;
                    $now = date('Y-m-d H:i:s');
                    $this->newEntitlement()->clear()->getQuery()
                        ->where(DownloadEntitlement::schema_fields_UUID, $entitlementUuid)
                        ->where(DownloadEntitlement::schema_fields_VERSION, $version)
                        ->where(DownloadEntitlement::schema_fields_DOWNLOAD_COUNT, $downloadCount)
                        ->update([
                            DownloadEntitlement::schema_fields_DOWNLOAD_COUNT => $nextCount,
                            DownloadEntitlement::schema_fields_STATUS => $nextStatus,
                            DownloadEntitlement::schema_fields_VERSION => $version + 1,
                            DownloadEntitlement::schema_fields_LAST_DOWNLOAD_AT => $now,
                            DownloadEntitlement::schema_fields_UPDATED_AT => $now,
                        ])->fetch();
                    $updated = $this->findByUuid($entitlementUuid);
                    if ($updated === null
                        || (int)$updated->getData(DownloadEntitlement::schema_fields_VERSION)
                            !== $version + 1
                    ) {
                        throw new ProductDownloadEntitlementException(
                            'download_entitlement_version_conflict',
                            (string)__('下载权益已变化，请刷新后重试'),
                        );
                    }
                    $this->audit(
                        $entitlementUuid,
                        $ownerId,
                        $customerId,
                        $websiteId,
                        'download',
                        'ok',
                        ['download_count' => $nextCount],
                    );

                    return [
                        'entitlement_uuid' => $entitlementUuid,
                        'url' => $resolved->url,
                        'expires_at' => $resolved->expiresAt,
                        'download_count' => $nextCount,
                        'download_limit' => $downloadLimit,
                    ];
                },
            );
        } catch (ProductDownloadEntitlementException $exception) {
            $this->auditDenied(
                $entitlementUuid,
                $customerId,
                (int)($scope->websiteId ?? 0),
                $exception->errorCode(),
            );
            throw $exception;
        }
    }

    public function listMine(int $customerId, int $websiteId, int $limit = 100): array
    {
        if ($customerId < 1 || $websiteId < 0) {
            throw new ProductDownloadEntitlementException(
                'download_access_context_invalid',
                (string)__('下载访问上下文无效'),
            );
        }
        $limit = max(1, min(200, $limit));
        $rows = $this->newEntitlement()->clear()
            ->where(DownloadEntitlement::schema_fields_CUSTOMER_ID, $customerId)
            ->where(DownloadEntitlement::schema_fields_WEBSITE_ID, $websiteId)
            ->order(DownloadEntitlement::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        $result = [];
        foreach ($rows as $row) {
            $count = max(0, (int)($row[DownloadEntitlement::schema_fields_DOWNLOAD_COUNT] ?? 0));
            $rawLimit = $row[DownloadEntitlement::schema_fields_DOWNLOAD_LIMIT] ?? null;
            $downloadLimit = $rawLimit === null ? null : max(0, (int)$rawLimit);
            $expiresAt = trim((string)($row[DownloadEntitlement::schema_fields_EXPIRES_AT] ?? ''));
            $status = (string)($row[DownloadEntitlement::schema_fields_STATUS] ?? '');
            if ($expiresAt !== '' && (int)strtotime($expiresAt) <= time()) {
                $status = 'expired';
            } elseif ($downloadLimit !== null && $count >= $downloadLimit) {
                $status = DownloadEntitlement::STATUS_EXHAUSTED;
            }
            $uuid = (string)($row[DownloadEntitlement::schema_fields_UUID] ?? '');
            $result[] = [
                'entitlement_uuid' => $uuid,
                'order_uuid' => (string)($row[DownloadEntitlement::schema_fields_ORDER_UUID] ?? ''),
                'global_product_uuid' => (string)($row[DownloadEntitlement::schema_fields_PRODUCT_UUID] ?? ''),
                'global_offer_uuid' => (string)($row[DownloadEntitlement::schema_fields_OFFER_UUID] ?? ''),
                'name' => (string)($row[DownloadEntitlement::schema_fields_ASSET_NAME] ?? ''),
                'status' => $status,
                'download_count' => $count,
                'download_limit' => $downloadLimit,
                'remaining' => $downloadLimit === null ? null : max(0, $downloadLimit - $count),
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'granted_at' => (string)($row[DownloadEntitlement::schema_fields_GRANTED_AT] ?? ''),
                'last_download_at' => $row[DownloadEntitlement::schema_fields_LAST_DOWNLOAD_AT] ?? null,
                'download_url' => $status === DownloadEntitlement::STATUS_ACTIVE
                    ? '/product-download/' . rawurlencode($uuid)
                    : '',
            ];
        }
        return $result;
    }

    private function findByGrantKey(string $grantKey): ?DownloadEntitlement
    {
        $row = $this->newEntitlement()->clear()
            ->where(DownloadEntitlement::schema_fields_GRANT_KEY, $grantKey)
            ->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function findByUuid(string $uuid, bool $lockingRead = false): ?DownloadEntitlement
    {
        $row = $this->newEntitlement()->clear()
            ->where(DownloadEntitlement::schema_fields_UUID, $uuid);
        if ($lockingRead && $this->supportsForUpdate($row)) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        return $row->getId() ? $row : null;
    }

    private function supportsForUpdate(DownloadEntitlement $row): bool
    {
        $type = strtolower((string)$row->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }

    private function orderFacade(): OrderFacadeInterface
    {
        if ($this->orders !== null) {
            return $this->orders;
        }
        $candidate = ObjectManager::getInstance(OrderFacadeInterface::class);
        if (!$candidate instanceof OrderFacadeInterface) {
            throw new ProductDownloadEntitlementException(
                'download_order_capability_unavailable',
                (string)__('Order 能力当前不可用'),
            );
        }
        return $candidate;
    }

    private function assetManager(): FileAssetManagerInterface
    {
        if ($this->assets !== null) {
            return $this->assets;
        }
        $candidate = ObjectManager::getInstance(FileAssetManagerInterface::class);
        if (!$candidate instanceof FileAssetManagerInterface) {
            throw new ProductDownloadEntitlementException(
                'download_asset_capability_unavailable',
                (string)__('FileManager 能力当前不可用'),
            );
        }
        return $candidate;
    }

    private function auditDenied(
        string $entitlementUuid,
        int $actorCustomerId,
        int $websiteId,
        string $resultCode,
    ): void {
        try {
            $this->transactions->run(
                $this->connectionFactory,
                function () use ($entitlementUuid, $actorCustomerId, $websiteId, $resultCode): void {
                    $row = $this->findByUuid($entitlementUuid);
                    $this->audit(
                        $entitlementUuid,
                        $row === null
                            ? null
                            : (int)$row->getData(DownloadEntitlement::schema_fields_CUSTOMER_ID),
                        $actorCustomerId,
                        $websiteId,
                        'download_denied',
                        $resultCode,
                    );
                },
            );
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $details */
    private function audit(
        string $entitlementUuid,
        ?int $ownerCustomerId,
        int $actorCustomerId,
        int $websiteId,
        string $action,
        string $resultCode,
        array $details = [],
    ): void {
        $this->newAudit()->clear()->setData([
            DownloadEntitlementAudit::schema_fields_ENTITLEMENT_UUID => $entitlementUuid,
            DownloadEntitlementAudit::schema_fields_OWNER_CUSTOMER_ID => $ownerCustomerId,
            DownloadEntitlementAudit::schema_fields_ACTOR_CUSTOMER_ID => max(0, $actorCustomerId),
            DownloadEntitlementAudit::schema_fields_WEBSITE_ID => max(0, $websiteId),
            DownloadEntitlementAudit::schema_fields_ACTION => $action,
            DownloadEntitlementAudit::schema_fields_RESULT_CODE => $resultCode,
            DownloadEntitlementAudit::schema_fields_DETAILS_JSON => $details === []
                ? null
                : json_encode(
                    $details,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            DownloadEntitlementAudit::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
        ])->save();
    }

    /** @return array<string,mixed> */
    private function grantResult(DownloadEntitlement $row, bool $replayed): array
    {
        return [
            'entitlement_uuid' => (string)$row->getData(DownloadEntitlement::schema_fields_UUID),
            'global_offer_uuid' => (string)$row->getData(DownloadEntitlement::schema_fields_OFFER_UUID),
            'name' => (string)$row->getData(DownloadEntitlement::schema_fields_ASSET_NAME),
            'replayed' => $replayed,
        ];
    }

    /** @param array<string,mixed> $item */
    private function lineKey(array $item, int $index): string
    {
        $line = trim((string)($item['line_uuid'] ?? ''));
        if ($line === '') {
            return 'index:' . $index;
        }
        if (strlen($line) <= 128 && preg_match('/[\x00-\x1F\x7F]/', $line) !== 1) {
            return $line;
        }
        return 'hash:' . hash('sha256', $line);
    }

    private function safeName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return '';
        }
        return function_exists('mb_substr')
            ? mb_substr($name, 0, 255, 'UTF-8')
            : substr($name, 0, 255);
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function uuid(string $value, string $errorCode): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $value) !== 1) {
            throw new ProductDownloadEntitlementException(
                $errorCode,
                (string)__('下载身份无效'),
            );
        }
        return $value;
    }

    private function uuidFromHash(string $hash): string
    {
        $hex = substr(strtolower($hash), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function positiveOrNull(mixed $value, int $maximum, string $errorCode): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int)$value < 1
            || (int)$value > $maximum
        ) {
            throw new ProductDownloadEntitlementException(
                $errorCode,
                (string)__('下载权益策略无效'),
            );
        }
        return (int)$value;
    }

    private function newEntitlement(): DownloadEntitlement
    {
        $model = $this->entitlementFactory !== null
            ? ($this->entitlementFactory)()
            : ObjectManager::create(DownloadEntitlement::class, [], false);
        if (!$model instanceof DownloadEntitlement) {
            throw new \LogicException('download_entitlement_factory_invalid');
        }
        return $model;
    }

    private function newAudit(): DownloadEntitlementAudit
    {
        $model = $this->auditFactory !== null
            ? ($this->auditFactory)()
            : ObjectManager::create(DownloadEntitlementAudit::class, [], false);
        if (!$model instanceof DownloadEntitlementAudit) {
            throw new \LogicException('download_entitlement_audit_factory_invalid');
        }
        return $model;
    }
}
