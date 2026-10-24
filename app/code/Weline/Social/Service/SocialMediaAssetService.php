<?php

declare(strict_types=1);

namespace Weline\Social\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Social\Model\SocialMediaAsset;

class SocialMediaAssetService
{
    private readonly ?ObjectManager $objectManager;

    public function __construct(?ObjectManager $objectManager = null)
    {
        $this->objectManager = $objectManager;
    }

    public function validatePayload(array $payload): array
    {
        $kind = (string)($payload['kind'] ?? '');
        if (!\in_array($kind, [SocialMediaAsset::KIND_IMAGE, SocialMediaAsset::KIND_VIDEO, SocialMediaAsset::KIND_AUDIO], true)) {
            return ['ok' => false, 'error' => 'unknown_kind'];
        }
        if (\trim((string)($payload['path'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'missing_path'];
        }
        if (\trim((string)($payload['checksum'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'missing_checksum'];
        }
        if ($kind === SocialMediaAsset::KIND_AUDIO
            && (\trim((string)($payload['source_url'] ?? '')) === ''
                || \trim((string)($payload['license'] ?? '')) === ''
                || \trim((string)($payload['attribution'] ?? '')) === '')) {
            return ['ok' => false, 'error' => 'music_unlicensed'];
        }

        return ['ok' => true, 'error' => ''];
    }

    public function findByChecksum(string $checksum, int $websiteId): ?SocialMediaAsset
    {
        $rows = $this->newAsset()->reset()
            ->where(SocialMediaAsset::schema_fields_WEBSITE_ID, $websiteId)
            ->where(SocialMediaAsset::schema_fields_CHECKSUM, $checksum)
            ->limit(1)
            ->select()
            ->fetch();

        $items = \is_object($rows) && \method_exists($rows, 'getItems') ? $rows->getItems() : $rows;
        if (!\is_array($items) || $items === []) {
            return null;
        }

        $first = $items[0] ?? null;
        if ($first instanceof SocialMediaAsset) {
            return $first;
        }

        if (\is_array($first)) {
            $asset = $this->newAsset();
            $asset->load((int)($first[SocialMediaAsset::schema_fields_ID] ?? 0));

            return $asset->getId() ? $asset : null;
        }

        return null;
    }

    public function register(array $payload): array
    {
        $validation = $this->validatePayload($payload);
        if (empty($validation['ok'])) {
            return ['success' => false, 'error' => (string)$validation['error']];
        }

        $websiteId = (int)($payload['website_id'] ?? 0);
        $existing = $this->findByChecksum((string)$payload['checksum'], $websiteId);
        if ($existing instanceof SocialMediaAsset) {
            return ['success' => true, 'asset' => $existing->toArrayData(), 'duplicated' => true];
        }

        $now = \date('Y-m-d H:i:s');
        $licenseConfirmedAt = \trim((string)($payload['license_confirmed_at'] ?? ''));
        $asset = $this->newAsset();
        $asset->setData(SocialMediaAsset::schema_fields_WEBSITE_ID, $websiteId)
            ->setData(SocialMediaAsset::schema_fields_KIND, (string)$payload['kind'])
            ->setData(SocialMediaAsset::schema_fields_PATH, \trim((string)$payload['path']))
            ->setData(SocialMediaAsset::schema_fields_MIME, (string)($payload['mime'] ?? ''))
            ->setData(SocialMediaAsset::schema_fields_WIDTH, (int)($payload['width'] ?? 0))
            ->setData(SocialMediaAsset::schema_fields_HEIGHT, (int)($payload['height'] ?? 0))
            ->setData(SocialMediaAsset::schema_fields_DURATION_MS, (int)($payload['duration_ms'] ?? 0))
            ->setData(SocialMediaAsset::schema_fields_CHECKSUM, (string)$payload['checksum'])
            ->setData(SocialMediaAsset::schema_fields_SOURCE, (string)($payload['source'] ?? 'site'))
            ->setData(SocialMediaAsset::schema_fields_SOURCE_URL, (string)($payload['source_url'] ?? ''))
            ->setData(SocialMediaAsset::schema_fields_LICENSE, (string)($payload['license'] ?? ''))
            ->setData(SocialMediaAsset::schema_fields_ATTRIBUTION, (string)($payload['attribution'] ?? ''))
            ->setData(SocialMediaAsset::schema_fields_LICENSE_CONFIRMED_AT, $licenseConfirmedAt === '' ? null : $licenseConfirmedAt)
            ->setData(SocialMediaAsset::schema_fields_CREATED_AT, $now)
            ->save();

        return ['success' => true, 'asset' => $asset->toArrayData(), 'duplicated' => false];
    }

    private function newAsset(): SocialMediaAsset
    {
        return ($this->objectManager ?? ObjectManager::getInstance())->getInstance(SocialMediaAsset::class);
    }
}
