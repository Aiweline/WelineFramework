<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Queue\AiSiteProvisioningQueue;

class AiSiteProvisioningQueueGateway
{
    /**
     * Queue content intentionally contains identifiers only. It never carries a plan or scope snapshot.
     *
     * @return array{queue_id: int, status: string, biz_key: string}
     */
    public function enqueue(string $requestId, string $executionToken): array
    {
        $bizKey = $this->buildBizKey($requestId);
        $existing = $this->normalizeQueueRow(\w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]));
        if ((int)($existing['queue_id'] ?? 0) > 0) {
            return $existing;
        }

        $created = \w_query('queue', 'create', [
            'class' => AiSiteProvisioningQueue::class,
            'name' => (string)__('AI建站域名准备'),
            'module' => 'Weline_Websites',
            'content' => [
                'request_id' => $requestId,
                'execution_token' => $executionToken,
            ],
            'status' => 'pending',
            'auto' => true,
            // Start admission only persists the parallel domain task. The real
            // Scheduler owns dispatch after PageBuilder has admitted Plan too.
            'dispatch' => false,
            'biz_key' => $bizKey,
        ]);

        return $this->normalizeQueueRow($created);
    }

    /**
     * Rearm is deliberately separate from enqueue: ordinary command replay must
     * continue to project a failed attempt without changing it. Queue takeover is
     * the provider-owned control operation that clears the previous attempt and
     * returns it to Scheduler-visible pending state without running a consumer here.
     *
     * @return array{queue_id:int,status:string,biz_key:string,rearmed:bool,idempotent:bool}
     */
    public function rearm(string $requestId, string $executionToken): array
    {
        $requestId = \trim($requestId);
        if ($requestId === '') {
            throw new \InvalidArgumentException('Provisioning request ID is required for queue rearm.');
        }

        $bizKey = $this->buildBizKey($requestId);
        $existing = $this->normalizeQueueRow(\w_query('queue', 'getByBizKey', ['biz_key' => $bizKey]));
        $queueId = (int)($existing['queue_id'] ?? 0);
        if ($queueId <= 0) {
            $created = $this->enqueue($requestId, $executionToken);

            return \array_replace($created, [
                'biz_key' => $bizKey,
                'rearmed' => true,
                'idempotent' => false,
            ]);
        }

        $status = \strtolower(\trim((string)($existing['status'] ?? '')));
        if (\in_array($status, ['pending', 'queued', 'running', 'done', 'completed'], true)) {
            return \array_replace($existing, [
                'biz_key' => $bizKey,
                'rearmed' => false,
                'idempotent' => true,
            ]);
        }
        if (!\in_array($status, ['error', 'failed', 'stop'], true)) {
            throw new \RuntimeException('Provisioning queue is not in a recognized rearm state.');
        }

        $takeover = \w_query('queue', 'takeover', [
            'queue_id' => $queueId,
            'force' => false,
            'owner' => 'system_scheduler',
            'reason' => 'Explicit PageBuilder domain provisioning retry.',
        ]);
        if (\is_array($takeover) && \array_key_exists('success', $takeover) && !$takeover['success']) {
            throw new \RuntimeException((string)($takeover['message'] ?? 'Provisioning queue takeover failed.'));
        }

        $current = $this->get($queueId);
        $currentStatus = \strtolower(\trim((string)($current['status'] ?? '')));
        if (!\is_array($current) || !\in_array($currentStatus, ['pending', 'queued', 'running'], true)) {
            throw new \RuntimeException('Provisioning queue takeover did not produce a consumable attempt.');
        }

        return \array_replace($current, [
            'biz_key' => $bizKey,
            'rearmed' => true,
            'idempotent' => false,
        ]);
    }

    /** @return array{queue_id: int, status: string, biz_key: string}|null */
    public function get(int $queueId): ?array
    {
        if ($queueId <= 0) {
            return null;
        }

        try {
            $row = $this->normalizeQueueRow(\w_query('queue', 'get', ['queue_id' => $queueId]));
        } catch (\Throwable) {
            return null;
        }

        return (int)($row['queue_id'] ?? 0) > 0 ? $row : null;
    }

    public function buildBizKey(string $requestId): string
    {
        return 'websites:ai_site_provisioning:' . \trim($requestId);
    }

    /** @return array{queue_id: int, status: string, biz_key: string} */
    private function normalizeQueueRow(mixed $row): array
    {
        if (\is_object($row) && \method_exists($row, 'getData')) {
            $row = $row->getData();
        }
        if (!\is_array($row)) {
            return ['queue_id' => 0, 'status' => '', 'biz_key' => ''];
        }

        $data = \is_array($row['data'] ?? null) ? $row['data'] : [];

        return [
            'queue_id' => (int)($row['queue_id'] ?? $data['queue_id'] ?? 0),
            'status' => (string)($row['status'] ?? $data['status'] ?? ''),
            'biz_key' => (string)($row['biz_key'] ?? $data['biz_key'] ?? ''),
        ];
    }
}
