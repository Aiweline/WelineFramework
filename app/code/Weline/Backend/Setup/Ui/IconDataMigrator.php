<?php

declare(strict_types=1);

namespace Weline\Backend\Setup\Ui;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * One-shot, database-adapter-neutral icon migration with a recoverable snapshot.
 */
final class IconDataMigrator
{
    public const SNAPSHOT_SCHEMA = 'weline-ui-icon-migration.v1';

    /** @var list<array{class: class-string, primary: string, fields: list<string>, required: bool}> */
    private const TARGETS = [
        ['class' => \Weline\Acl\Model\Acl::class, 'primary' => 'source_id', 'fields' => ['icon'], 'required' => true],
        ['class' => \Weline\Backend\Model\Menu::class, 'primary' => 'menu_id', 'fields' => ['icon'], 'required' => true],
        ['class' => \Weline\Backend\Model\NotificationTopic::class, 'primary' => 'topic_id', 'fields' => ['icon'], 'required' => true],
        ['class' => \Weline\Theme\Model\ThemeComponent::class, 'primary' => 'component_id', 'fields' => ['icon'], 'required' => true],
        ['class' => \Weline\DeveloperWorkspace\Model\Document\Catalog::class, 'primary' => 'id', 'fields' => ['icon', 'selectedIcon'], 'required' => false],
        ['class' => \Weline\Order\Model\OrderStatus::class, 'primary' => 'status_id', 'fields' => ['icon'], 'required' => false],
        ['class' => \Weline\AppStore\Model\AppStoreInstalledModule::class, 'primary' => 'install_id', 'fields' => ['icon'], 'required' => false],
        ['class' => \Weline\TwoFactorAuth\Model\TotpAccount::class, 'primary' => 'account_id', 'fields' => ['icon'], 'required' => false],
    ];

    public function __construct(private readonly LegacyIconNameMap $nameMap)
    {
    }

    /**
     * @return array{snapshot: string, discovered: int, migrated: int, skipped_targets: list<string>}
     */
    public function migrate(?string $snapshotPath = null): array
    {
        $snapshotPath ??= $this->defaultSnapshotPath();
        [$records, $skippedTargets] = $this->discover();

        if (!is_file($snapshotPath)) {
            $this->writeSnapshot($snapshotPath, $records, $skippedTargets);
        } else {
            $this->readSnapshot($snapshotPath);
        }

        $migrated = 0;
        foreach ($records as $record) {
            if ($this->applyRecord($record, false)) {
                $migrated++;
            }
        }

        return [
            'snapshot' => $snapshotPath,
            'discovered' => count($records),
            'migrated' => $migrated,
            'skipped_targets' => $skippedTargets,
        ];
    }

    /**
     * Restore only rows that still contain the value written by this migration.
     * Concurrent edits are deliberately preserved.
     *
     * @return array{snapshot: string, restored: int, skipped: int}
     */
    public function restore(?string $snapshotPath = null): array
    {
        $snapshotPath ??= $this->defaultSnapshotPath();
        $snapshot = $this->readSnapshot($snapshotPath);
        $restored = 0;
        $skipped = 0;
        foreach ($snapshot['records'] as $record) {
            if ($this->applyRecord($record, true)) {
                $restored++;
            } else {
                $skipped++;
            }
        }

        return ['snapshot' => $snapshotPath, 'restored' => $restored, 'skipped' => $skipped];
    }

    public function defaultSnapshotPath(): string
    {
        $varPath = defined('VAR_PATH') ? (string)VAR_PATH : (string)BP . 'var' . DIRECTORY_SEPARATOR;
        return rtrim($varPath, '/\\')
            . DIRECTORY_SEPARATOR . 'migration'
            . DIRECTORY_SEPARATOR . 'weline-ui-2.0-icons.json';
    }

    /**
     * @return array{0: list<array{class: string, primary_field: string, primary_value: mixed, field: string, old: string, new: string}>, 1: list<string>}
     */
    private function discover(): array
    {
        $records = [];
        $skippedTargets = [];
        foreach (self::TARGETS as $target) {
            $class = $target['class'];
            if (!class_exists($class)) {
                $skippedTargets[] = $class . ':class-unavailable';
                continue;
            }

            try {
                $model = $this->model($class);
                $rows = $model->clearData()->clearQuery()->select()->fetchArray();
            } catch (\Throwable $exception) {
                if ($target['required']) {
                    throw new \RuntimeException(
                        __('Weline UI 图标迁移无法读取 %{1}：%{2}', [$class, $exception->getMessage()]),
                        0,
                        $exception,
                    );
                }
                $skippedTargets[] = $class . ':table-unavailable';
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists($target['primary'], $row)) {
                    continue;
                }
                foreach ($target['fields'] as $field) {
                    $old = trim((string)($row[$field] ?? ''));
                    $new = $this->nameMap->map($old);
                    if ($new === null || $new === $old) {
                        continue;
                    }
                    $records[] = [
                        'class' => $class,
                        'primary_field' => $target['primary'],
                        'primary_value' => $row[$target['primary']],
                        'field' => $field,
                        'old' => $old,
                        'new' => $new,
                    ];
                }
            }
        }

        usort($records, static fn(array $left, array $right): int => [
            $left['class'],
            (string)$left['primary_value'],
            $left['field'],
        ] <=> [
            $right['class'],
            (string)$right['primary_value'],
            $right['field'],
        ]);
        sort($skippedTargets);

        return [$records, $skippedTargets];
    }

    /** @param array{class: string, primary_field: string, primary_value: mixed, field: string, old: string, new: string} $record */
    private function applyRecord(array $record, bool $restore): bool
    {
        $class = (string)$record['class'];
        if (!class_exists($class)) {
            return false;
        }

        $model = $this->model($class);
        $model->clearData()->clearQuery()->load($record['primary_field'], $record['primary_value']);
        if (!$model->getData($record['primary_field'])) {
            return false;
        }

        $expected = $restore ? $record['new'] : $record['old'];
        $replacement = $restore ? $record['old'] : $record['new'];
        if ((string)$model->getData($record['field']) !== (string)$expected) {
            return false;
        }

        $model->setData($record['field'], $replacement)->save();
        return true;
    }

    /** @param list<array<string, mixed>> $records @param list<string> $skippedTargets */
    private function writeSnapshot(string $path, array $records, array $skippedTargets): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException(__('无法创建 Weline UI 图标迁移快照目录：%{1}', [$directory]));
        }

        $payload = [
            'schema_version' => self::SNAPSHOT_SCHEMA,
            'created_at' => gmdate(DATE_ATOM),
            'records' => $records,
            'skipped_targets' => $skippedTargets,
        ];
        $payload['records_sha256'] = hash('sha256', $this->canonicalRecords($records));
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException(__('无法写入 Weline UI 图标迁移快照：%{1}', [$path]));
        }
        @chmod($path, 0660);
    }

    /** @return array{schema_version: string, records: list<array<string, mixed>>, records_sha256: string} */
    private function readSnapshot(string $path): array
    {
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException(__('无法读取 Weline UI 图标迁移快照：%{1}', [$path]));
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(__('Weline UI 图标迁移快照 JSON 无效：%{1}', [$exception->getMessage()]), 0, $exception);
        }
        if (!is_array($payload)
            || ($payload['schema_version'] ?? '') !== self::SNAPSHOT_SCHEMA
            || !is_array($payload['records'] ?? null)
            || !is_string($payload['records_sha256'] ?? null)
            || !hash_equals($payload['records_sha256'], hash('sha256', $this->canonicalRecords($payload['records'])))) {
            throw new \RuntimeException(__('Weline UI 图标迁移快照校验失败：%{1}', [$path]));
        }

        return $payload;
    }

    /** @param list<array<string, mixed>> $records */
    private function canonicalRecords(array $records): string
    {
        return json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param class-string $class */
    private function model(string $class): AbstractModel
    {
        $model = ObjectManager::create($class, [], false);
        if (!$model instanceof AbstractModel) {
            throw new \RuntimeException(__('图标迁移目标不是 ORM Model：%{1}', [$class]));
        }
        return $model;
    }
}
