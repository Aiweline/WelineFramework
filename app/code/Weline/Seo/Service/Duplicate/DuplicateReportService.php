<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoDupPair;
use Weline\Seo\Model\SeoDupRun;

/**
 * Read-side helpers for duplicate-check report pages.
 */
class DuplicateReportService
{
    /**
     * @return array<string, mixed>|null
     */
    public function getRun(int $runId): ?array
    {
        if ($runId < 1) {
            return null;
        }
        /** @var SeoDupRun $model */
        $model = ObjectManager::getInstance(SeoDupRun::class);
        $row = $model->reset()->where(SeoDupRun::schema_fields_ID, $runId)->find()->fetch();
        if (!$row || !$row->getId()) {
            return null;
        }
        $data = $row->getData();
        $scope = \json_decode((string)($data[SeoDupRun::schema_fields_SCOPE_JSON] ?? '{}'), true);
        $stats = \json_decode((string)($data[SeoDupRun::schema_fields_STATS_JSON] ?? '{}'), true);

        return [
            'run_id' => (int)$data[SeoDupRun::schema_fields_ID],
            'website_id' => (int)($data[SeoDupRun::schema_fields_WEBSITE_ID] ?? 0),
            'status' => (string)($data[SeoDupRun::schema_fields_STATUS] ?? ''),
            'scope' => \is_array($scope) ? $scope : [],
            'stats' => \is_array($stats) ? $stats : [],
            'issue_count' => (int)($data[SeoDupRun::schema_fields_ISSUE_COUNT] ?? 0),
            'report_path' => (string)($data[SeoDupRun::schema_fields_REPORT_PATH] ?? ''),
            'report_url' => (string)($data[SeoDupRun::schema_fields_REPORT_URL] ?? ''),
            'started_at' => (string)($data[SeoDupRun::schema_fields_STARTED_AT] ?? ''),
            'finished_at' => (string)($data[SeoDupRun::schema_fields_FINISHED_AT] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPairs(int $runId, ?string $grade = null, int $limit = 500): array
    {
        /** @var SeoDupPair $model */
        $model = ObjectManager::getInstance(SeoDupPair::class);
        $q = $model->reset()->where(SeoDupPair::schema_fields_RUN_ID, $runId);
        if ($grade !== null && $grade !== '') {
            $q->where(SeoDupPair::schema_fields_GRADE, $grade);
        }
        $rows = $q->order(SeoDupPair::schema_fields_JACCARD, 'DESC')
            ->limit(\max(1, $limit))
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        /** @var list<array<string, mixed>> $list */
        $list = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $list[] = $row;
            }
        }

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecentRuns(?int $websiteId = null, int $limit = 50): array
    {
        /** @var SeoDupRun $model */
        $model = ObjectManager::getInstance(SeoDupRun::class);
        $q = $model->reset();
        if ($websiteId !== null && $websiteId >= 0) {
            $q->where(SeoDupRun::schema_fields_WEBSITE_ID, $websiteId);
        }
        $rows = $q->order(SeoDupRun::schema_fields_ID, 'DESC')
            ->limit(\max(1, $limit))
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $runId = (int)($row[SeoDupRun::schema_fields_ID] ?? 0);
            $out[] = [
                'run_id' => $runId,
                'website_id' => (int)($row[SeoDupRun::schema_fields_WEBSITE_ID] ?? 0),
                'status' => (string)($row[SeoDupRun::schema_fields_STATUS] ?? ''),
                'issue_count' => (int)($row[SeoDupRun::schema_fields_ISSUE_COUNT] ?? 0),
                'report_path' => (string)($row[SeoDupRun::schema_fields_REPORT_PATH] ?? ''),
                'report_url' => (string)($row[SeoDupRun::schema_fields_REPORT_URL] ?? ''),
                'started_at' => (string)($row[SeoDupRun::schema_fields_STARTED_AT] ?? ''),
                'finished_at' => (string)($row[SeoDupRun::schema_fields_FINISHED_AT] ?? ''),
            ];
        }

        return $out;
    }
}
