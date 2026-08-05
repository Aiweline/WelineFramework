<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorWebsiteAuthorizationRecord;

/** Durable Vendor↔Website authorization matrix with explicit test memory mode. */
final class VendorAuthorizationService
{
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_REVOKED = 'revoked';
    public const ERROR_NOT_AUTHORIZED = 'vendor_website_not_authorized';
    public const ERROR_REVOKED = 'vendor_website_revoked';
    public const ERROR_ALREADY = 'vendor_website_already_authorized';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;
    /** @var (\Closure(): VendorWebsiteAuthorizationRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorWebsiteAuthorizationRecord)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->rows = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /** @return array<string, mixed> */
    public function authorizeWebsite(string $vendorId, int $websiteId): array
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $existing = $this->find($vendorId, $websiteId);
        if ($existing !== null && (string) $existing['status'] === self::STATUS_AUTHORIZED) {
            throw new VendorConflictException(
                self::ERROR_ALREADY,
                __('Vendor 已授权该站点：%{1}/%{2}', [$vendorId, $websiteId]),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            );
        }
        $row = [
            'vendor_id' => trim($vendorId),
            'website_id' => $websiteId,
            'status' => self::STATUS_AUTHORIZED,
            'grant_version' => $existing !== null ? ((int) $existing['grant_version'] + 1) : 1,
            'authorized_at' => date('Y-m-d H:i:s'),
            'revoked_at' => null,
        ];
        return $this->write($row, $existing !== null);
    }

    /** @return array<string, mixed> */
    public function revoke(string $vendorId, int $websiteId): array
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $existing = $this->find($vendorId, $websiteId);
        if ($existing === null || (string) $existing['status'] !== self::STATUS_AUTHORIZED) {
            throw new VendorConflictException(
                self::ERROR_NOT_AUTHORIZED,
                __('Vendor 未授权该站点，无法撤权：%{1}/%{2}', [$vendorId, $websiteId]),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            );
        }
        $existing['status'] = self::STATUS_REVOKED;
        $existing['grant_version'] = (int) $existing['grant_version'] + 1;
        $existing['revoked_at'] = date('Y-m-d H:i:s');
        return $this->write($existing, true);
    }

    public function isAuthorized(string $vendorId, int $websiteId): bool
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $row = $this->find($vendorId, $websiteId);
        return $row !== null && (string) $row['status'] === self::STATUS_AUTHORIZED;
    }

    /** @return array<string, mixed> */
    public function assertAuthorized(string $vendorId, int $websiteId): array
    {
        VendorIdentity::assertWebsiteId($websiteId);
        $row = $this->find($vendorId, $websiteId);
        if ($row === null) {
            throw new VendorConflictException(
                self::ERROR_NOT_AUTHORIZED,
                __('Vendor 未授权站点：%{1}/%{2}', [$vendorId, $websiteId]),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            );
        }
        if ((string) $row['status'] === self::STATUS_REVOKED) {
            throw new VendorConflictException(
                self::ERROR_REVOKED,
                __('Vendor 站点授权已撤销：%{1}/%{2}', [$vendorId, $websiteId]),
                ['vendor_id' => $vendorId, 'website_id' => $websiteId],
            );
        }
        return $row;
    }

    public function authorizedWebsiteCount(string $vendorId): int
    {
        if ($this->rows !== null) {
            return count(array_filter(
                $this->rows,
                static fn (array $row): bool => (string) $row['vendor_id'] === $vendorId
                    && (string) $row['status'] === self::STATUS_AUTHORIZED,
            ));
        }
        return count(
            $this->newRecord()->clear()
                ->where(VendorWebsiteAuthorizationRecord::schema_fields_VENDOR_ID, trim($vendorId))
                ->where(VendorWebsiteAuthorizationRecord::schema_fields_STATUS, self::STATUS_AUTHORIZED)
                ->select()
                ->fetchArray(),
        );
    }

    /** @return array<string, mixed>|null */
    private function find(string $vendorId, int $websiteId): ?array
    {
        if ($this->rows !== null) {
            return $this->rows[$this->key($vendorId, $websiteId)] ?? null;
        }
        $model = $this->findModel($vendorId, $websiteId);
        return $model?->getData();
    }

    private function findModel(string $vendorId, int $websiteId): ?VendorWebsiteAuthorizationRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorWebsiteAuthorizationRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->where(VendorWebsiteAuthorizationRecord::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function write(array $row, bool $update): array
    {
        if ($this->rows !== null) {
            $this->rows[$this->key((string) $row['vendor_id'], (int) $row['website_id'])] = $row;
            return $row;
        }
        $model = $update
            ? $this->findModel((string) $row['vendor_id'], (int) $row['website_id'])
            : null;
        $model ??= $this->newRecord();
        if (!$update) {
            $model->clear();
        }
        $model->setData([
            VendorWebsiteAuthorizationRecord::schema_fields_VENDOR_ID => $row['vendor_id'],
            VendorWebsiteAuthorizationRecord::schema_fields_WEBSITE_ID => $row['website_id'],
            VendorWebsiteAuthorizationRecord::schema_fields_STATUS => $row['status'],
            VendorWebsiteAuthorizationRecord::schema_fields_GRANT_VERSION => $row['grant_version'],
            VendorWebsiteAuthorizationRecord::schema_fields_AUTHORIZED_AT => $row['authorized_at'],
            VendorWebsiteAuthorizationRecord::schema_fields_REVOKED_AT => $row['revoked_at'],
        ])->save();
        return $this->assertAuthorizedOrRevoked((string) $row['vendor_id'], (int) $row['website_id']);
    }

    /** @return array<string, mixed> */
    private function assertAuthorizedOrRevoked(string $vendorId, int $websiteId): array
    {
        $row = $this->find($vendorId, $websiteId);
        if ($row === null) {
            throw new \RuntimeException(__('Vendor Website 授权写入后无法回读'));
        }
        return $row;
    }

    private function key(string $vendorId, int $websiteId): string
    {
        return trim($vendorId) . ':' . $websiteId;
    }

    private function newRecord(): VendorWebsiteAuthorizationRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorWebsiteAuthorizationRecord::class, [], false);
    }
}
