<?php
declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Queue\Model\Queue;

/**
 * Truncate oversized queue.result / queue.process trails to a short process summary.
 */
class QueueResultLogCleanupService
{
    public const DEFAULT_MAX_BYTES = 8192;

    public function __construct(
        private readonly Queue $queue,
    ) {
    }

    public function boundTrail(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        $text = trim($text);
        if ($text === '' || $maxBytes <= 0 || strlen($text) <= $maxBytes) {
            return $text;
        }
        $notice = '[process trail kept; dropped older ' . strlen($text) . " bytes]\n";
        $budget = max(0, $maxBytes - strlen($notice));

        return $notice . substr($text, -$budget);
    }

    /**
     * @return array{scanned:int,updated:int,max_bytes:int,ids:list<int>}
     */
    public function cleanupOversized(int $maxBytes = self::DEFAULT_MAX_BYTES, int $pageSize = 200): array
    {
        $maxBytes = max(256, $maxBytes);
        $pageSize = max(1, min(500, $pageSize));
        $page = 1;
        $scanned = 0;
        $updated = 0;
        $ids = [];

        while (true) {
            $rows = $this->queue->clear()->reset()
                ->fields('queue_id,result,process')
                ->order('queue_id', 'DESC')
                ->limit($pageSize, ($page - 1) * $pageSize)
                ->select()
                ->fetchArray();
            if ($rows === [] || $rows === null) {
                break;
            }

            foreach ((array)$rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $scanned++;
                $queueId = (int)($row['queue_id'] ?? 0);
                if ($queueId <= 0) {
                    continue;
                }
                $result = (string)($row['result'] ?? '');
                $process = (string)($row['process'] ?? '');
                $boundedResult = $this->boundTrail($result, $maxBytes);
                $boundedProcess = $this->boundTrail($process, $maxBytes);
                if ($boundedResult === $result && $boundedProcess === $process) {
                    continue;
                }

                $model = clone $this->queue;
                $model->clear()->reset()->load($queueId);
                if ((int)$model->getId() !== $queueId) {
                    continue;
                }
                $changed = false;
                if ($boundedResult !== $result) {
                    $model->setResult($boundedResult);
                    $changed = true;
                }
                if ($boundedProcess !== $process) {
                    $model->setProcess($boundedProcess);
                    $changed = true;
                }
                if ($changed) {
                    $model->save();
                    $updated++;
                    $ids[] = $queueId;
                }
            }

            if (count($rows) < $pageSize) {
                break;
            }
            $page++;
            if ($page > 5000) {
                break;
            }
        }

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'max_bytes' => $maxBytes,
            'ids' => $ids,
        ];
    }
}
