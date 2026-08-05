<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Throwable;
use Weline\Framework\Manager\ObjectManager;
use Weline\Vendor\Model\VendorIdentity;
use Weline\Vendor\Model\VendorRecord;

/**
 * Durable Vendor identity registry with an explicit memory-only test harness.
 */
final class VendorRegistryStore
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $vendors = null;

    /** @var (\Closure(): VendorRecord)|null */
    private readonly ?\Closure $recordFactory;

    /** @param (callable(): VendorRecord)|null $recordFactory */
    public function __construct(?callable $recordFactory = null, bool $useMemory = false)
    {
        $this->recordFactory = $recordFactory !== null
            ? \Closure::fromCallable($recordFactory)
            : null;
        if ($useMemory) {
            $this->vendors = [];
        }
    }

    public static function forTesting(): self
    {
        return new self(useMemory: true);
    }

    /**
     * @param array{
     *   vendor_id?:string,
     *   code:string,
     *   legal_name?:string,
     *   environment?:string,
     *   status?:string,
     *   account_ref?:string
     * } $input
     * @return array<string, mixed>
     */
    public function register(array $input): array
    {
        $code = VendorIdentity::assertVendorCode((string) ($input['code'] ?? ''));
        $environment = VendorIdentity::assertEnvironment(
            (string) ($input['environment'] ?? VendorIdentity::ENV_SANDBOX),
        );
        $status = VendorIdentity::assertStatus(
            (string) ($input['status'] ?? VendorIdentity::STATUS_ACTIVE),
        );
        $vendorId = trim((string) ($input['vendor_id'] ?? ''));
        if ($vendorId === '') {
            $vendorId = 'vnd_' . $code . '_' . substr(hash('sha256', $code . '|' . $environment), 0, 8);
        }
        $row = [
            'vendor_id' => $vendorId,
            'code' => $code,
            'legal_name' => trim((string) ($input['legal_name'] ?? $code)),
            'environment' => $environment,
            'status' => $status,
            // Compatibility only. Store-scoped bindings are authoritative for new trade flows.
            'account_ref' => trim((string) ($input['account_ref'] ?? ($environment . ':' . $code))),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->vendors !== null) {
            return $this->registerMemory($row);
        }
        if ($this->find($vendorId) !== null) {
            throw $this->alreadyExists($vendorId);
        }
        if ($this->findByCodeEnvironment($code, $environment) !== null) {
            throw $this->codeTaken($code, $environment);
        }

        try {
            $model = $this->newRecord();
            $model->clear()->setData([
                VendorRecord::schema_fields_VENDOR_ID => $vendorId,
                VendorRecord::schema_fields_CODE => $code,
                VendorRecord::schema_fields_LEGAL_NAME => $row['legal_name'],
                VendorRecord::schema_fields_ENVIRONMENT => $environment,
                VendorRecord::schema_fields_STATUS => $status,
                VendorRecord::schema_fields_ACCOUNT_REF => $row['account_ref'],
                VendorRecord::schema_fields_CREATED_AT => $row['created_at'],
                VendorRecord::schema_fields_UPDATED_AT => $row['updated_at'],
            ])->save();
        } catch (Throwable $exception) {
            if ($this->find($vendorId) !== null) {
                throw $this->alreadyExists($vendorId, $exception);
            }
            if ($this->findByCodeEnvironment($code, $environment) !== null) {
                throw $this->codeTaken($code, $environment, $exception);
            }
            throw $exception;
        }

        return $this->get($vendorId);
    }

    /** @return array<string, mixed> */
    public function get(string $vendorId): array
    {
        $vendorId = trim($vendorId);
        $row = $this->vendors !== null
            ? ($this->vendors[$vendorId] ?? null)
            : $this->find($vendorId);
        if ($row === null) {
            throw new VendorConflictException(
                'vendor_not_found',
                __('Vendor 不存在：%{1}', [$vendorId]),
                ['vendor_id' => $vendorId],
            );
        }
        return $row;
    }

    /** @return array<string, mixed> */
    public function disable(string $vendorId): array
    {
        $row = $this->get($vendorId);
        $row['status'] = VendorIdentity::STATUS_DISABLED;
        $row['updated_at'] = date('Y-m-d H:i:s');
        if ($this->vendors !== null) {
            $this->vendors[$vendorId] = $row;
            return $row;
        }
        $model = $this->findModel($vendorId);
        if ($model === null) {
            return $this->get($vendorId);
        }
        $model->setData(VendorRecord::schema_fields_STATUS, VendorIdentity::STATUS_DISABLED)
            ->setData(VendorRecord::schema_fields_UPDATED_AT, $row['updated_at'])
            ->save();
        return $this->get($vendorId);
    }

    public function count(): int
    {
        if ($this->vendors !== null) {
            return count($this->vendors);
        }
        return count($this->newRecord()->clear()->select()->fetchArray());
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function registerMemory(array $row): array
    {
        $vendorId = (string) $row['vendor_id'];
        if (isset($this->vendors[$vendorId])) {
            throw $this->alreadyExists($vendorId);
        }
        foreach ($this->vendors as $existing) {
            if ((string) $existing['code'] === $row['code']
                && (string) $existing['environment'] === $row['environment']
            ) {
                throw $this->codeTaken((string) $row['code'], (string) $row['environment']);
            }
        }
        $this->vendors[$vendorId] = $row;
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function find(string $vendorId): ?array
    {
        $model = $this->findModel($vendorId);
        return $model?->getData();
    }

    private function findModel(string $vendorId): ?VendorRecord
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorRecord::schema_fields_VENDOR_ID, trim($vendorId))
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /** @return array<string, mixed>|null */
    private function findByCodeEnvironment(string $code, string $environment): ?array
    {
        $model = $this->newRecord();
        $model->clear()
            ->where(VendorRecord::schema_fields_CODE, $code)
            ->where(VendorRecord::schema_fields_ENVIRONMENT, $environment)
            ->find()
            ->fetch();
        return $model->getId() ? $model->getData() : null;
    }

    private function alreadyExists(string $vendorId, ?Throwable $previous = null): VendorConflictException
    {
        return new VendorConflictException(
            'vendor_already_exists',
            __('Vendor 已存在：%{1}', [$vendorId]),
            ['vendor_id' => $vendorId],
            0,
            $previous,
        );
    }

    private function codeTaken(string $code, string $environment, ?Throwable $previous = null): VendorConflictException
    {
        return new VendorConflictException(
            'vendor_code_environment_taken',
            __('同一 environment 下 Vendor code 已占用：%{1}', [$code]),
            ['code' => $code, 'environment' => $environment],
            0,
            $previous,
        );
    }

    private function newRecord(): VendorRecord
    {
        return $this->recordFactory !== null
            ? ($this->recordFactory)()
            : ObjectManager::create(VendorRecord::class, [], false);
    }
}
