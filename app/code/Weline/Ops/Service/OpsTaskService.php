<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Ops\Model\OpsTask;

final class OpsTaskService
{
    public function __construct(
        private readonly OpsTask $task,
    ) {
    }

    /**
     * @param array{
     *   website_id:int,
     *   scope:string,
     *   task_type:string,
     *   session_id?:string,
     *   assignee_agent?:string,
     *   owner_module?:string,
     *   title?:string,
     *   note?:string,
     *   payload?:array<string,mixed>,
     *   queue_idempotency_key?:string
     * } $input
     * @return array{ok:bool,task?:array<string,mixed>,message?:string}
     */
    public function create(array $input): array
    {
        $websiteId = max(0, (int)($input['website_id'] ?? 0));
        $scope = trim((string)($input['scope'] ?? 'site'));
        $type = trim((string)($input['task_type'] ?? ''));
        if ($type === '') {
            return ['ok' => false, 'message' => (string)__('任务类型不能为空')];
        }

        $row = $this->task->clear();
        $row->setData(OpsTask::schema_fields_WEBSITE_ID, $websiteId);
        $row->setData(OpsTask::schema_fields_SESSION_ID, trim((string)($input['session_id'] ?? '')));
        $row->setData(OpsTask::schema_fields_SCOPE, $scope !== '' ? $scope : 'site');
        $row->setData(OpsTask::schema_fields_TASK_TYPE, $type);
        $row->setData(OpsTask::schema_fields_ASSIGNEE_AGENT, trim((string)($input['assignee_agent'] ?? '')));
        $row->setData(OpsTask::schema_fields_OWNER_MODULE, trim((string)($input['owner_module'] ?? 'Weline_Ops')));
        $row->setData(OpsTask::schema_fields_STATUS, OpsTask::STATUS_QUEUED);
        $row->setData(OpsTask::schema_fields_TITLE, trim((string)($input['title'] ?? $type)));
        $row->setData(OpsTask::schema_fields_NOTE, trim((string)($input['note'] ?? '')));
        $payload = $input['payload'] ?? [];
        $row->setData(
            OpsTask::schema_fields_PAYLOAD_JSON,
            is_array($payload) ? (json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}') : '{}'
        );
        $row->setData(
            OpsTask::schema_fields_QUEUE_IDEMPOTENCY_KEY,
            trim((string)($input['queue_idempotency_key'] ?? ''))
        );
        $row->save();

        $taskId = (int)$row->getId();
        if ($taskId > 0 && trim((string)$row->getData(OpsTask::schema_fields_QUEUE_IDEMPOTENCY_KEY)) === '') {
            $key = 'ops_task:' . $taskId;
            $row->setData(OpsTask::schema_fields_QUEUE_IDEMPOTENCY_KEY, $key);
            $row->save();
        }

        return ['ok' => true, 'task' => $this->toView($row)];
    }

    /**
     * CAS claim: only queued → running.
     * @return array{ok:bool,task?:array<string,mixed>,message?:string}
     */
    public function claimRunning(int $taskId): array
    {
        $row = $this->task->clear()->load($taskId);
        if (!$row->getId()) {
            return ['ok' => false, 'message' => (string)__('任务不存在')];
        }
        $status = (string)$row->getData(OpsTask::schema_fields_STATUS);
        if ($status !== OpsTask::STATUS_QUEUED) {
            return ['ok' => false, 'message' => (string)__('任务不可领取')];
        }
        $row->setData(OpsTask::schema_fields_STATUS, OpsTask::STATUS_RUNNING);
        $row->save();

        return ['ok' => true, 'task' => $this->toView($row)];
    }

    /**
     * @return array{ok:bool,task?:array<string,mixed>,message?:string}
     */
    public function setStatus(int $taskId, string $status): array
    {
        if (!in_array($status, OpsTask::allowedStatuses(), true)) {
            return ['ok' => false, 'message' => (string)__('不支持的任务状态')];
        }
        $row = $this->task->clear()->load($taskId);
        if (!$row->getId()) {
            return ['ok' => false, 'message' => (string)__('任务不存在')];
        }
        $row->setData(OpsTask::schema_fields_STATUS, $status);
        $row->save();

        return ['ok' => true, 'task' => $this->toView($row)];
    }

    /**
     * @param array<string,mixed> $payloadMerge
     * @return array{ok:bool,task?:array<string,mixed>,message?:string}
     */
    public function mergePayload(int $taskId, array $payloadMerge): array
    {
        $row = $this->task->clear()->load($taskId);
        if (!$row->getId()) {
            return ['ok' => false, 'message' => (string)__('任务不存在')];
        }
        $existing = json_decode((string)$row->getData(OpsTask::schema_fields_PAYLOAD_JSON), true);
        if (!is_array($existing)) {
            $existing = [];
        }
        $row->setData(
            OpsTask::schema_fields_PAYLOAD_JSON,
            json_encode(array_merge($existing, $payloadMerge), JSON_UNESCAPED_UNICODE) ?: '{}'
        );
        $row->save();

        return ['ok' => true, 'task' => $this->toView($row)];
    }

    /** @return array{ok:bool,task?:array<string,mixed>,message?:string} */
    public function get(int $taskId): array
    {
        $row = $this->task->clear()->load($taskId);
        if (!$row->getId()) {
            return ['ok' => false, 'message' => (string)__('任务不存在')];
        }

        return ['ok' => true, 'task' => $this->toView($row)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecent(int $websiteId, int $limit = 20): array
    {
        return $this->listFiltered(['website_id' => $websiteId], $limit);
    }

    /**
     * @param array{
     *   website_id?:int|null,
     *   status?:string,
     *   assignee_agent?:string,
     *   session_id?:string,
     *   dispatch_only?:bool,
     *   statuses?:list<string>
     * } $filters
     * @return list<array<string,mixed>>
     */
    public function listFiltered(array $filters = [], int $limit = 50): array
    {
        $q = $this->task->clear();
        if (array_key_exists('website_id', $filters) && $filters['website_id'] !== null && $filters['website_id'] !== '') {
            $q->where(OpsTask::schema_fields_WEBSITE_ID, max(0, (int)$filters['website_id']));
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $q->where(OpsTask::schema_fields_STATUS, $status);
        }
        $statuses = $filters['statuses'] ?? null;
        if (is_array($statuses) && $statuses !== []) {
            $q->where(OpsTask::schema_fields_STATUS, array_values(array_map('strval', $statuses)), 'IN');
        }
        $assignee = trim((string)($filters['assignee_agent'] ?? ''));
        if ($assignee !== '') {
            $q->where(OpsTask::schema_fields_ASSIGNEE_AGENT, $assignee);
        }
        $sessionId = trim((string)($filters['session_id'] ?? ''));
        if ($sessionId !== '') {
            $q->where(OpsTask::schema_fields_SESSION_ID, $sessionId);
        }
        if (!empty($filters['dispatch_only'])) {
            $q->where(OpsTask::schema_fields_SESSION_ID, '', '!=');
        }

        $items = $q->order(OpsTask::schema_fields_TASK_ID, 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->fetch()
            ->getItems();

        $out = [];
        foreach ($items as $item) {
            if ($item instanceof OpsTask) {
                $out[] = $this->toView($item);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function toView(OpsTask $row): array
    {
        $payload = json_decode((string)$row->getData(OpsTask::schema_fields_PAYLOAD_JSON), true);

        return [
            'task_id' => (int)$row->getId(),
            'website_id' => (int)$row->getData(OpsTask::schema_fields_WEBSITE_ID),
            'session_id' => (string)$row->getData(OpsTask::schema_fields_SESSION_ID),
            'scope' => (string)$row->getData(OpsTask::schema_fields_SCOPE),
            'task_type' => (string)$row->getData(OpsTask::schema_fields_TASK_TYPE),
            'assignee_agent' => (string)$row->getData(OpsTask::schema_fields_ASSIGNEE_AGENT),
            'owner_module' => (string)$row->getData(OpsTask::schema_fields_OWNER_MODULE),
            'status' => (string)$row->getData(OpsTask::schema_fields_STATUS),
            'title' => (string)$row->getData(OpsTask::schema_fields_TITLE),
            'note' => (string)$row->getData(OpsTask::schema_fields_NOTE),
            'payload' => is_array($payload) ? $payload : [],
            'queue_idempotency_key' => (string)$row->getData(OpsTask::schema_fields_QUEUE_IDEMPOTENCY_KEY),
            'created_at' => (string)$row->getData(OpsTask::schema_fields_CREATED_AT),
        ];
    }
}
