<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Model\DropshipListing;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\Data\ProductAdminCommand;
use Weline\Product\Api\ProductAdminCommandInterface;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Service\ProductIdentityV2Service;

/**
 * Delete shell listing rows; optionally keep / disable / archive linked local products.
 */
class DropshipListingDeleteService
{
    public const LOCAL_KEEP = 'keep';
    public const LOCAL_DISABLE = 'disable';
    public const LOCAL_ARCHIVE = 'archive';

    /**
     * @param list<int|string> $listingIds
     * @return array{
     *   ok:bool,
     *   deleted:int,
     *   disabled_products:int,
     *   archived_products:int,
     *   local_product_action:string,
     *   errors:list<string>,
     *   error?:string
     * }
     */
    public function deleteListings(
        array $listingIds,
        int $actorId = 0,
        string $localProductAction = self::LOCAL_DISABLE,
    ): array {
        $action = $this->normalizeLocalAction($localProductAction);
        $ids = [];
        foreach ($listingIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [
                'ok' => false,
                'deleted' => 0,
                'disabled_products' => 0,
                'archived_products' => 0,
                'local_product_action' => $action,
                'errors' => ['ids_required'],
                'error' => 'ids_required',
            ];
        }

        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $deleted = 0;
        $disabled = 0;
        $archived = 0;
        $errors = [];

        foreach ($ids as $id) {
            $row = $model->clear()->where(DropshipListing::schema_fields_ID, $id)->find()->fetch();
            if (!$row || !$row->getId()) {
                $errors[] = 'listing_not_found:' . $id;
                continue;
            }

            $listingId = (int)$row->getId();
            $uuid = trim((string)$row->getData(DropshipListing::schema_fields_LOCAL_PRODUCT_UUID));
            $websiteId = (int)$row->getData(DropshipListing::schema_fields_WEBSITE_ID);
            if ($uuid !== '' && $action !== self::LOCAL_KEEP) {
                $stillLinked = $this->otherListingUsesProduct($listingId, $uuid);
                if (!$stillLinked) {
                    try {
                        $touched = $this->applyLocalProductAction($uuid, $websiteId, $actorId, $action);
                        $disabled += (int)($touched['disabled'] ?? 0);
                        $archived += (int)($touched['archived'] ?? 0);
                    } catch (\Throwable $e) {
                        $detail = trim($e->getMessage());
                        $errors[] = 'local_product_failed:' . $listingId . ($detail !== '' ? ':' . $detail : '');
                        w_log_error('dropship listing delete local product failed listing=' . $listingId . ' ' . $detail);
                    }
                }
            }

            // DropshipListing is a shared ObjectManager singleton: re-load before delete
            // so prior where()/find() in otherListingUsesProduct cannot wipe the PK.
            try {
                $fresh = $model->clear()->where(DropshipListing::schema_fields_ID, $listingId)->find()->fetch();
                if (!$fresh || !$fresh->getId()) {
                    // Already gone (race) — treat as deleted.
                    ++$deleted;
                    continue;
                }
                $fresh->delete();
                ++$deleted;
            } catch (\Throwable $e) {
                $detail = trim($e->getMessage());
                $errors[] = 'delete_failed:' . $listingId . ($detail !== '' ? ':' . $detail : '');
            }
        }

        return [
            'ok' => $deleted > 0,
            'deleted' => $deleted,
            'disabled_products' => $disabled,
            'archived_products' => $archived,
            'local_product_action' => $action,
            'errors' => $errors,
        ];
    }

    public function normalizeLocalAction(string $raw): string
    {
        $action = strtolower(trim($raw));
        if (in_array($action, [self::LOCAL_KEEP, self::LOCAL_DISABLE, self::LOCAL_ARCHIVE], true)) {
            return $action;
        }
        // Back-compat aliases from UI / older callers.
        if (in_array($action, ['1', 'true', 'yes', 'purge', 'physical', 'delete'], true)) {
            return self::LOCAL_ARCHIVE;
        }
        if (in_array($action, ['0', 'false', 'no'], true)) {
            return self::LOCAL_DISABLE;
        }

        return self::LOCAL_DISABLE;
    }

    private function otherListingUsesProduct(int $exceptListingId, string $productUuid): bool
    {
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $other = $model->clear()
            ->where(DropshipListing::schema_fields_LOCAL_PRODUCT_UUID, $productUuid)
            ->where(DropshipListing::schema_fields_ID, $exceptListingId, '!=')
            ->find()
            ->fetch();

        return (bool)($other && $other->getId());
    }

    /**
     * @return array{disabled:int,archived:int}
     */
    private function applyLocalProductAction(
        string $productUuid,
        int $websiteId,
        int $actorId,
        string $action,
    ): array {
        if ($action === self::LOCAL_KEEP) {
            return ['disabled' => 0, 'archived' => 0];
        }

        $disabled = 0;
        $archived = 0;
        $status = $this->lifecycleStatus($productUuid);
        if ($status === null) {
            return ['disabled' => 0, 'archived' => 0];
        }

        if ($action === self::LOCAL_DISABLE) {
            if ($status === 'published') {
                if ($this->transitionLocalProduct($productUuid, $websiteId, $actorId, ProductAdminCommand::ACTION_DISABLE, 'disable')) {
                    $disabled = 1;
                }
            }

            return ['disabled' => $disabled, 'archived' => 0];
        }

        // archive = UI「物理删除」：published → disable → archive；disabled → archive；draft → archive.
        if ($status === 'archived') {
            return ['disabled' => 0, 'archived' => 0];
        }
        if ($status === 'published') {
            if ($this->transitionLocalProduct($productUuid, $websiteId, $actorId, ProductAdminCommand::ACTION_DISABLE, 'disable')) {
                $disabled = 1;
            }
            $status = $this->lifecycleStatus($productUuid) ?? 'disabled';
        }
        if (in_array($status, ['disabled', 'draft'], true)) {
            if ($this->transitionLocalProduct($productUuid, $websiteId, $actorId, ProductAdminCommand::ACTION_ARCHIVE, 'archive')) {
                $archived = 1;
            }
        }

        return ['disabled' => $disabled, 'archived' => $archived];
    }

    private function lifecycleStatus(string $productUuid): ?string
    {
        /** @var ProductIdentityV2Service $identities */
        $identities = ObjectManager::getInstance(ProductIdentityV2Service::class);
        $identity = $identities->resolveProductByUuid($productUuid);
        if ($identity === null) {
            return null;
        }

        return strtolower(trim((string)$identity->lifecycleStatus));
    }

    private function transitionLocalProduct(
        string $productUuid,
        int $websiteId,
        int $actorId,
        string $commandAction,
        string $hashTag,
    ): bool {
        if (!interface_exists(ProductAdminCommandInterface::class)) {
            throw new \RuntimeException('dropship_product_admin_unavailable');
        }

        /** @var ProductIdentityV2Service $identities */
        $identities = ObjectManager::getInstance(ProductIdentityV2Service::class);
        $identity = $identities->resolveProductByUuid($productUuid);
        if ($identity === null) {
            return false;
        }

        /** @var Product $productModel */
        $productModel = ObjectManager::getInstance(Product::class)->forWebsite(max(0, $websiteId));
        $productRow = $productModel->clear()
            ->where(Product::schema_fields_GLOBAL_PRODUCT_UUID, $productUuid)
            ->find()
            ->fetch();
        $localVersion = (int)($productRow?->getData(Product::schema_fields_PUBLISH_VERSION) ?? 0);

        /** @var ProductAdminCommandInterface $cmd */
        $cmd = ObjectManager::getInstance(ProductAdminCommandInterface::class);
        $result = $cmd->execute(new ProductAdminCommand(
            action: $commandAction,
            websiteId: max(0, $websiteId),
            globalProductUuid: $productUuid,
            expectedVersion: (int)$identity->version,
            requestHash: hash('sha256', implode('|', [
                'dropship-listing-delete',
                $hashTag,
                $productUuid,
                (string)$websiteId,
                (string)microtime(true),
            ])),
            actorId: $actorId,
            payload: [
                'local_version' => $localVersion,
            ],
        ));
        if (!$result->success) {
            $detail = trim((string)($result->errorCode ?: $result->message));
            throw new \RuntimeException($detail !== '' ? $detail : $hashTag . '_failed');
        }

        return true;
    }
}
