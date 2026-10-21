<?php

declare(strict_types=1);

namespace Weline\Database\Service\Index;

use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Model\SchemaIndexState;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * Read-only backend projection for the index management page.
 * It queries only the job/state tables; it never scans Model declarations.
 */
final class SchemaIndexAdminService
{
    public function __construct(
        private readonly SchemaIndexConfig $config,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        if (!$this->available()) {
            return [
                'available' => false,
                'auto_apply' => $this->config->autoApply(),
                'window' => $this->config->window(),
                'max_concurrent' => $this->config->maxConcurrent(),
                'load_threshold' => $this->config->loadThreshold(),
                'jobs' => [],
                'states' => [],
            ];
        }

        $jobModel = ObjectManager::getInstance(SchemaIndexJob::class);
        $jobCounts = [];
        foreach ([
            SchemaIndexJob::STATUS_PENDING,
            SchemaIndexJob::STATUS_QUEUED,
            SchemaIndexJob::STATUS_RUNNING,
            SchemaIndexJob::STATUS_DONE,
            SchemaIndexJob::STATUS_FAILED,
            SchemaIndexJob::STATUS_BLOCKED,
        ] as $status) {
            $jobCounts[$status] = (int)$jobModel->clear()->reset()
                ->where(SchemaIndexJob::schema_fields_STATUS, $status)
                ->total();
        }

        $stateModel = ObjectManager::getInstance(SchemaIndexState::class);
        $stateCounts = [];
        foreach ([
            SchemaIndexState::STATUS_APPLIED,
            SchemaIndexState::STATUS_PENDING,
            SchemaIndexState::STATUS_RUNNING,
            SchemaIndexState::STATUS_FAILED,
            SchemaIndexState::STATUS_DRIFTED,
        ] as $status) {
            $stateCounts[$status] = (int)$stateModel->clear()->reset()
                ->where(SchemaIndexState::schema_fields_STATUS, $status)
                ->total();
        }

        return [
            'available' => true,
            'auto_apply' => $this->config->autoApply(),
            'window' => $this->config->window(),
            'max_concurrent' => $this->config->maxConcurrent(),
            'load_threshold' => $this->config->loadThreshold(),
            'jobs' => $jobCounts,
            'states' => $stateCounts,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function jobs(int $limit = 100): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $rows = ObjectManager::getInstance(SchemaIndexJob::class)->clear()->reset()
            ->order(SchemaIndexJob::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    public function states(int $limit = 100): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        $rows = ObjectManager::getInstance(SchemaIndexState::class)->clear()->reset()
            ->order(SchemaIndexState::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function available(): bool
    {
        $jobTable = ObjectManager::getInstance(SchemaIndexJob::class)->getTable();
        $stateTable = ObjectManager::getInstance(SchemaIndexState::class)->getTable();
        $connector = $this->connectionFactory->getConnector();

        return $connector->tableExist($jobTable) && $connector->tableExist($stateTable);
    }
}
