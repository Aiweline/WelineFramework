<?php

declare(strict_types=1);

namespace Weline\CacheManager\Observer;

use Weline\CacheManager\Model\Cache as CacheRecord;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;

class UpgradeCache implements \Weline\Framework\Event\ObserverInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $this->syncPoolsToDatabase();
        } catch (\Throwable $throwable) {
            w_log_error('UpgradeCache failed: ' . $throwable->getMessage(), [], 'CacheManager::UpgradeCache');
        }
    }

    private function syncPoolsToDatabase(): void
    {
        foreach ($this->cacheManager->getPoolIdentities() as $identity) {
            try {
                $pool = $this->cacheManager->pool($identity);
                $this->savePoolToDatabase($identity, $pool->getStats());
            } catch (\Throwable $throwable) {
                w_log_error("Sync pool '{$identity}' failed: " . $throwable->getMessage(), [], 'CacheManager::UpgradeCache');
            }
        }
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function savePoolToDatabase(string $identity, array $stats): void
    {
        /** @var CacheRecord $cacheModel */
        $cacheModel = ObjectManager::make(CacheRecord::class);
        $existing = $cacheModel
            ->where($cacheModel::schema_fields_IDENTITY, $identity)
            ->find()
            ->fetch();

        $adapterClass = (string)($stats['adapter'] ?? '');
        $description = \trim((string)($stats['tip'] ?? ''));
        $data = [
            $cacheModel::schema_fields_NAME => $this->resolveDisplayName($existing, $adapterClass),
            $cacheModel::schema_fields_IDENTITY => $identity,
            $cacheModel::schema_fields_Module => $this->resolveStringField(
                $existing,
                $cacheModel::schema_fields_Module,
                'Weline_Framework'
            ),
            $cacheModel::schema_fields_FILE => $this->resolveStringField(
                $existing,
                $cacheModel::schema_fields_FILE,
                'CacheManager Pool'
            ),
            $cacheModel::schema_fields_TYPE => $this->resolveIntField(
                $existing,
                $cacheModel::schema_fields_TYPE,
                0
            ),
            $cacheModel::schema_fields_Status => (int)(($stats['enabled'] ?? true) ? 1 : 0),
            $cacheModel::schema_fields_Permanently => (int)(($stats['permanent'] ?? false) ? 1 : 0),
            $cacheModel::schema_fields_DESCRIPTION => $description !== ''
                ? $description
                : $this->resolveStringField($existing, $cacheModel::schema_fields_DESCRIPTION, ''),
        ];

        if ($existing && $existing->getId()) {
            $existing->setData($data)->save();
            return;
        }

        /** @var CacheRecord $newCache */
        $newCache = ObjectManager::make(CacheRecord::class);
        $newCache->setData($data)->save();
    }

    private function resolveDisplayName(mixed $existing, string $adapterClass): string
    {
        $existingName = $this->resolveStringField($existing, CacheRecord::schema_fields_NAME, '');
        $adapterLabel = $this->adapterLabel($adapterClass);

        if ($existingName === '' || $existingName === $adapterClass) {
            return $adapterLabel;
        }

        return $existingName;
    }

    private function resolveStringField(mixed $record, string $field, string $default): string
    {
        if ($record && \method_exists($record, 'getData')) {
            $value = \trim((string)$record->getData($field));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function resolveIntField(mixed $record, string $field, int $default): int
    {
        if ($record && \method_exists($record, 'getData')) {
            $value = $record->getData($field);
            if ($value !== null && $value !== '') {
                return (int)$value;
            }
        }

        return $default;
    }

    private function adapterLabel(string $adapterClass): string
    {
        if ($adapterClass === '') {
            return 'Unknown';
        }

        $parts = \explode('\\', $adapterClass);
        return (string)\end($parts);
    }
}
