<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ops\Model\OpsRunLog;

final class OpsRunLogService
{
    public function __construct(
        private readonly OpsRunLog $runLog,
    ) {
    }

    /**
     * @param array<string,mixed> $evidence
     */
    public function write(
        int $websiteId,
        string $kind,
        string $status,
        string $summary,
        string $body = '',
        array $evidence = [],
    ): int {
        $row = $this->runLog->clear();
        $row->setData(OpsRunLog::schema_fields_WEBSITE_ID, max(0, $websiteId));
        $row->setData(OpsRunLog::schema_fields_KIND, $kind);
        $row->setData(OpsRunLog::schema_fields_STATUS, $status !== '' ? $status : 'ok');
        $row->setData(OpsRunLog::schema_fields_SUMMARY, mb_substr($summary, 0, 255));
        $row->setData(OpsRunLog::schema_fields_BODY, $body);
        $row->setData(
            OpsRunLog::schema_fields_EVIDENCE_JSON,
            json_encode($evidence, JSON_UNESCAPED_UNICODE) ?: '{}'
        );
        $row->save();

        return (int)$row->getId();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecent(int $websiteId, int $limit = 20, string $kind = ''): array
    {
        $q = $this->runLog->clear()
            ->where(OpsRunLog::schema_fields_WEBSITE_ID, max(0, $websiteId));
        $kind = trim($kind);
        if ($kind !== '') {
            $q->where(OpsRunLog::schema_fields_KIND, $kind);
        }
        $items = $q->order(OpsRunLog::schema_fields_RUN_ID, 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->select()
            ->fetch()
            ->getItems();
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof OpsRunLog) {
                continue;
            }
            $evidence = json_decode((string)$item->getData(OpsRunLog::schema_fields_EVIDENCE_JSON), true);
            $out[] = [
                'run_id' => (int)$item->getId(),
                'website_id' => (int)$item->getData(OpsRunLog::schema_fields_WEBSITE_ID),
                'kind' => (string)$item->getData(OpsRunLog::schema_fields_KIND),
                'status' => (string)$item->getData(OpsRunLog::schema_fields_STATUS),
                'summary' => (string)$item->getData(OpsRunLog::schema_fields_SUMMARY),
                'body' => (string)$item->getData(OpsRunLog::schema_fields_BODY),
                'evidence' => is_array($evidence) ? $evidence : [],
                'created_at' => (string)$item->getData(OpsRunLog::schema_fields_CREATED_AT),
            ];
        }

        return $out;
    }
}
