<?php

declare(strict_types=1);

namespace Weline\FileManager\Controller\Backend;

use Weline\FileManager\Service\MediaReference\MediaReferenceIdentityBuilder;
use Weline\FileManager\Service\MediaReference\MediaReferenceService;
use Weline\FileManager\Service\FileAssetReferenceIndexer;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_FileManager::media_reference', '媒体引用身份', 'link', '绑定/卸载媒体引用与按范围清理', 'Weline_Backend::media_group')]
class MediaReference extends BackendController
{
    public function __construct(
        private readonly MediaReferenceService $mediaReferences,
        private readonly MediaReferenceIdentityBuilder $builder,
        private readonly FileAssetReferenceIndexer $indexer,
        private readonly FileAsset $assets,
    ) {
    }

    /**
     * POST: bind single or sync multi refs on picker confirm.
     * body: ref_mode, asset_id|asset_ids[], scope?, type, code, slot{}, owner_type, owner_id, owner_version, field_path, locale_code?
     */
    public function postBind(): string
    {
        $body = $this->jsonBody();
        $type = trim((string)($body['type'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));
        $scope = isset($body['scope']) ? trim((string)$body['scope']) : null;
        if ($scope === '') {
            $scope = null;
        }
        $slot = is_array($body['slot'] ?? null) ? $body['slot'] : [];
        $ownerType = trim((string)($body['owner_type'] ?? $type));
        $ownerId = trim((string)($body['owner_id'] ?? $code));
        $ownerVersion = max(1, (int)($body['owner_version'] ?? 1));
        $fieldPath = trim((string)($body['field_path'] ?? ''));
        $locale = trim((string)($body['locale_code'] ?? ''));
        $refMode = strtolower(trim((string)($body['ref_mode'] ?? 'single')));

        try {
            $identity = $this->builder->build($scope, $type, $code, $slot);
            if ($fieldPath === '') {
                $fieldPath = $identity->path;
            }
            if ($refMode === 'multi') {
                $ids = $body['asset_ids'] ?? [];
                if (!is_array($ids)) {
                    $ids = [];
                }
                $this->mediaReferences->syncMulti(
                    $identity,
                    array_map('strval', $ids),
                    $ownerType,
                    $ownerId,
                    $ownerVersion,
                    $fieldPath,
                    $locale,
                );
            } else {
                $assetId = trim((string)($body['asset_id'] ?? ''));
                if ($assetId === '' && is_array($body['asset_ids'] ?? null) && isset($body['asset_ids'][0])) {
                    $assetId = trim((string)$body['asset_ids'][0]);
                }
                $this->mediaReferences->bindSingle(
                    $identity,
                    $assetId,
                    $ownerType,
                    $ownerId,
                    $ownerVersion,
                    $fieldPath,
                    $locale,
                );
            }
            return $this->json([
                'ok' => true,
                'identity' => $identity->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST: unbind by type+scope+code (AND). Never deletes files. */
    public function postUnbind(): string
    {
        $body = $this->jsonBody();
        $type = trim((string)($body['type'] ?? ''));
        $code = trim((string)($body['code'] ?? ''));
        $scope = trim((string)($body['scope'] ?? ''));
        try {
            $removed = $this->mediaReferences->unbindWhere($type, $scope, $code);
            return $this->json(['ok' => true, 'removed' => $removed]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST: delete_references_by_scope — unload all refs under a storage_scope. */
    public function postDeleteByScope(): string
    {
        $body = $this->jsonBody();
        $scope = trim((string)($body['scope'] ?? ''));
        $includeChildren = filter_var($body['include_children'] ?? true, FILTER_VALIDATE_BOOL);
        try {
            $result = $this->mediaReferences->deleteReferencesByScope($scope, $includeChildren);
            return $this->json(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function getByTags(): string
    {
        $tags = [];
        foreach (['root', 'scope', 'sku', 'theme', 'brand', 'post', 'category', 'key', 'mail', 'attribute', 'supplier', 'website', 'code', 'kind', 'component', 'field', 'locale', 'instance', 'role', 'ns', 'axis', 'option', 'layout', 'index'] as $key) {
            $v = trim((string)$this->request->getGet($key, ''));
            if ($v !== '') {
                $tags[$key] = $v;
            }
        }
        $rows = $this->mediaReferences->listByTags($tags);
        return $this->json(['ok' => true, 'items' => $rows]);
    }

    /** GET: reference count for one asset. */
    public function getCount(): string
    {
        $assetId = trim((string)$this->request->getGet('asset_id', ''));
        return $this->json([
            'ok' => true,
            'asset_id' => $assetId,
            'count' => $assetId === '' ? 0 : $this->indexer->countForAsset($assetId),
            'unreferenced' => $assetId !== '' && !$this->indexer->isReferenced($assetId),
        ]);
    }

    /** POST: restore soft-deleted FileAsset (trash). */
    public function postRestore(): string
    {
        $body = $this->jsonBody();
        $assetId = trim((string)($body['asset_id'] ?? ''));
        if ($assetId === '') {
            return $this->json(['ok' => false, 'error' => 'asset_id required'], 400);
        }
        try {
            $asset = clone $this->assets;
            $asset->clearData()->reset()
                ->where(FileAsset::schema_fields_ASSET_ID, $assetId)
                ->find()->fetch();
            if ((string)$asset->getData(FileAsset::schema_fields_ASSET_ID) !== $assetId) {
                return $this->json(['ok' => false, 'error' => 'not_found'], 404);
            }
            $deletedAt = trim((string)$asset->getData(FileAsset::schema_fields_DELETED_AT));
            if ($deletedAt === '') {
                return $this->json(['ok' => true, 'restored' => false, 'reason' => 'not_in_trash']);
            }
            $asset->setData(FileAsset::schema_fields_DELETED_AT, null);
            $asset->setData(FileAsset::schema_fields_LIFECYCLE_STATE, FileAsset::STATE_READY);
            $asset->save();
            return $this->json(['ok' => true, 'restored' => true, 'asset_id' => $assetId]);
        } catch (\Throwable $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $raw = (string)$this->request->getBody();
        if ($raw === '') {
            $params = $this->request->getParams();
            return is_array($params) ? $params : [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        if ($status !== 200) {
            http_response_code($status);
        }
        return (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
