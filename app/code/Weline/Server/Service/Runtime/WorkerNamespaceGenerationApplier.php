<?php

declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;

/** Applies namespace generations only; deliberately owns no cache-clear API. */
final class WorkerNamespaceGenerationApplier
{
    private const HISTORY_LIMIT = 256;
    private const HISTORY_TTL_SEC = 600.0;

    /** @var array<string,array{payload_hash:string,result:array<string,mixed>,finished_at:float}> */
    private array $history = [];

    public function __construct(
        private readonly NamespaceGenerationRepository $repository,
        private readonly NamespaceInvalidationProtocol $protocol,
    ) {
    }

    /** @return array{authority_clock:int,generations:array<string,int>} */
    public function reconcileBeforeReady(): array
    {
        try {
            $snapshot = $this->repository->reconcileProcessSnapshot();
            WorkerReadinessState::markNamespaceAuthorityClock($snapshot['authority_clock']);
            return $snapshot;
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                \w_log_error(
                    'cache_namespace_ready_reconcile_failed',
                    ['error_code' => 'db_reconcile_failed'],
                    'wls',
                );
            }
            throw $throwable;
        }
    }

    /**
     * @param array<string,mixed> $message
     * @return array{operation_id:string,success:bool,applied:bool,authority_clock:int,generations:array<string,int>,error_code:string,error:string}
     */
    public function apply(array $message): array
    {
        $this->pruneHistory();
        $operationId = \is_string($message['operation_id'] ?? null)
            ? (string)$message['operation_id']
            : '';
        try {
            $frame = $this->protocol->validateFrame($message);
            $operationId = $frame['operation_id'];
            $payloadHash = $this->protocol->payloadHash($frame);
            $existing = $this->history[$operationId] ?? null;
            if ($existing !== null) {
                if (!\hash_equals($existing['payload_hash'], $payloadHash)) {
                    return $this->failureResult($operationId, 'operation_conflict', (string)__('操作 ID 负载冲突。'));
                }
                $result = $existing['result'];
                $result['applied'] = false;
                return $result;
            }

            $changes = [];
            foreach ($frame['changes'] as $change) {
                $changes[$change['namespace']] = $change['generation'];
            }
            $before = $this->repository->processSnapshot();
            $snapshot = $this->repository->applyAuthorityChanges($frame['authority_clock'], $changes);
            WorkerReadinessState::markNamespaceAuthorityClock($snapshot['authority_clock']);
            $current = [];
            $applied = $snapshot['authority_clock'] > $before['authority_clock'];
            foreach ($changes as $namespace => $requestedGeneration) {
                $current[$namespace] = \max(
                    $requestedGeneration,
                    (int)($snapshot['generations'][$namespace] ?? 0),
                );
                if ((int)($before['generations'][$namespace] ?? 0) < $requestedGeneration) {
                    $applied = true;
                }
            }
            \ksort($current, \SORT_STRING);
            $result = [
                'operation_id' => $operationId,
                'success' => true,
                'applied' => $applied,
                'authority_clock' => $snapshot['authority_clock'],
                'generations' => $current,
                'error_code' => '',
                'error' => '',
            ];
            $this->history[$operationId] = [
                'payload_hash' => $payloadHash,
                'result' => $result,
                'finished_at' => self::monotonicSeconds(),
            ];
            $this->pruneHistory();

            return $result;
        } catch (NamespaceInvalidationProtocolException $exception) {
            return $this->failureResult($operationId, $exception->errorCode, $exception->getMessage());
        } catch (\Throwable) {
            return $this->failureResult($operationId, 'apply_failed', (string)__('应用缓存命名空间代际失败。'));
        }
    }

    /**
     * @return array{operation_id:string,success:bool,applied:bool,authority_clock:int,generations:array<string,int>,error_code:string,error:string}
     */
    private function failureResult(string $operationId, string $errorCode, string $error): array
    {
        $snapshot = $this->repository->processSnapshot();
        return [
            'operation_id' => \preg_match(NamespaceInvalidationProtocol::OPERATION_ID_PATTERN, $operationId) === 1
                ? $operationId
                : '',
            'success' => false,
            'applied' => false,
            'authority_clock' => \max(0, (int)$snapshot['authority_clock']),
            'generations' => [],
            'error_code' => $errorCode,
            'error' => \substr($error, 0, 512),
        ];
    }

    private function pruneHistory(): void
    {
        $cutoff = self::monotonicSeconds() - self::HISTORY_TTL_SEC;
        foreach ($this->history as $operationId => $entry) {
            if ($entry['finished_at'] < $cutoff) {
                unset($this->history[$operationId]);
            }
        }
        while (\count($this->history) > self::HISTORY_LIMIT) {
            $operationId = \array_key_first($this->history);
            if ($operationId === null) {
                break;
            }
            unset($this->history[$operationId]);
        }
    }

    private static function monotonicSeconds(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }
}
