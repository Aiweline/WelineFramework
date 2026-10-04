<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Websites\Api\Theme\ThemeApplicationReference;
use Weline\Websites\Api\Theme\ThemeApplicationRepositoryInterface;
use Weline\Websites\Model\ThemeApplication;

/** 使用网站自身 ORM 表和事务保存应用引用；不改 Theme 内容与版本选择。 */
final class OrmThemeApplicationRepository implements ThemeApplicationRepositoryInterface
{
    public function __construct(
        private readonly ThemeApplication $model,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function read(string $scopeKey, string $storeMode, string $area): array
    {
        return $this->record($this->load($scopeKey, $storeMode, $area, false));
    }

    public function compareAndSwap(string $scopeKey, string $storeMode, string $area, ?ThemeApplicationReference $reference, int $expectedRevision): array
    {
        if ($expectedRevision < 0) {
            throw new \InvalidArgumentException('website_theme_application_revision_invalid');
        }
        try {
            return $this->transactions->runWrite($this->model->getConnection(), function () use ($scopeKey, $storeMode, $area, $reference, $expectedRevision): array {
                $row = $this->load($scopeKey, $storeMode, $area, true);
                $current = $this->record($row);
                if ($current['revision'] !== $expectedRevision) {
                    throw new \RuntimeException('website_theme_application_revision_conflict:' . $current['revision']);
                }
                if ($current['reference']?->toArray() === $reference?->toArray()) {
                    return $current;
                }
                $revision = $current['revision'] + 1;
                $values = [
                    ThemeApplication::schema_fields_REFERENCE_JSON => $reference === null ? null : json_encode($reference->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ThemeApplication::schema_fields_REVISION => $revision,
                    ThemeApplication::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s'),
                ];
                if ($row === null) {
                    (clone $this->model)->clear()->insert($values + [
                        ThemeApplication::schema_fields_IDENTITY_HASH => $this->identityHash($scopeKey, $storeMode, $area),
                        ThemeApplication::schema_fields_SCOPE_KEY => $scopeKey,
                        ThemeApplication::schema_fields_STORE_MODE => $storeMode,
                        ThemeApplication::schema_fields_AREA => $area,
                    ])->fetch();
                } else {
                    (clone $this->model)->clear()
                        ->where(ThemeApplication::schema_fields_ID, (int)$row->getData(ThemeApplication::schema_fields_ID))
                        ->where(ThemeApplication::schema_fields_REVISION, $expectedRevision)
                        ->update($values)->fetch();
                }
                return ['reference' => $reference, 'revision' => $revision];
            });
        } catch (\Throwable $error) {
            // 唯一键竞争发生时先退出失败事务，再报告数据库已存在的实际修订。
            // 不把磁盘/连接错误转成成功，也不发起自动重试。
            try {
                $actual = $this->read($scopeKey, $storeMode, $area);
            } catch (\Throwable) {
                throw $error;
            }
            if ($actual['revision'] !== $expectedRevision) {
                throw new \RuntimeException('website_theme_application_revision_conflict:' . $actual['revision'], 0, $error);
            }
            throw $error;
        }
    }

    private function load(string $scopeKey, string $storeMode, string $area, bool $locking): ?ThemeApplication
    {
        $row = clone $this->model;
        $row->clear()->where(ThemeApplication::schema_fields_IDENTITY_HASH, $this->identityHash($scopeKey, $storeMode, $area));
        $type = strtolower((string)$row->getConnection()->getConnector()->getConfigProvider()->getDbType());
        if ($locking && in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        if ((int)$row->getData(ThemeApplication::schema_fields_ID) < 1) {
            return null;
        }
        if ($row->getData(ThemeApplication::schema_fields_SCOPE_KEY) !== $scopeKey
            || $row->getData(ThemeApplication::schema_fields_STORE_MODE) !== $storeMode
            || $row->getData(ThemeApplication::schema_fields_AREA) !== $area) {
            throw new \RuntimeException('website_theme_application_stored_identity_mismatch');
        }
        return $row;
    }

    private function record(?ThemeApplication $row): array
    {
        if ($row === null) {
            return ['reference' => null, 'revision' => 0];
        }
        $raw = $row->getData(ThemeApplication::schema_fields_REFERENCE_JSON);
        $reference = null;
        if ($raw !== null && $raw !== '') {
            $data = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
            foreach (['theme_id', 'theme_version_id', 'content_revision', 'version_owner_scope', 'version_owner_store_mode', 'area'] as $field) {
                if (!is_array($data) || !array_key_exists($field, $data)) {
                    throw new \RuntimeException('website_theme_application_stored_reference_incomplete');
                }
            }
            $reference = new ThemeApplicationReference(
                themeId: (int)($data['theme_id'] ?? 0),
                themeVersionId: (int)($data['theme_version_id'] ?? 0),
                contentRevision: (int)($data['content_revision'] ?? 0),
                versionOwnerScope: (string)($data['version_owner_scope'] ?? ''),
                versionOwnerStoreMode: (string)($data['version_owner_store_mode'] ?? ''),
                area: (string)($data['area'] ?? ''),
            );
        }
        return ['reference' => $reference, 'revision' => (int)$row->getData(ThemeApplication::schema_fields_REVISION)];
    }

    private function identityHash(string $scopeKey, string $storeMode, string $area): string
    {
        return hash('sha256', json_encode(['websites', $scopeKey, $storeMode, $area], JSON_THROW_ON_ERROR));
    }
}
