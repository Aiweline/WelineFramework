<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction;

use Throwable;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;

/**
 * Request-scoped mutable state for one logical database transaction.
 *
 * Instances are stored by TransactionContext and must never be shared across
 * requests or Fibers.
 */
final class TransactionState
{
    private int $depth = 1;
    private bool $rollbackOnly = false;
    private ?Throwable $rollbackCause = null;
    private int $savepointCounter = 0;

    /** @var list<int> */
    private array $savepointDepthGuards = [];

    /** @var array<string, callable> */
    private array $afterCommitCallbacks = [];

    /** @var array<string, callable> */
    private array $afterRollbackCallbacks = [];

    public function __construct(
        private readonly QueryInterface $ownerQuery,
        private readonly int $pdoObjectId,
        private readonly bool $writeIntent = false,
    ) {
    }

    public function ownerQuery(): QueryInterface
    {
        return $this->ownerQuery;
    }

    public function pdoObjectId(): int
    {
        return $this->pdoObjectId;
    }

    public function isWriteIntent(): bool
    {
        return $this->writeIntent;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function incrementDepth(): void
    {
        ++$this->depth;
    }

    public function decrementDepth(): void
    {
        if ($this->depth > 1) {
            --$this->depth;
        }
    }

    public function forceOutermost(): void
    {
        $this->depth = 1;
        $this->savepointDepthGuards = [];
    }

    public function enterSavepointScope(): void
    {
        ++$this->depth;
        $this->savepointDepthGuards[] = $this->depth;
    }

    public function leaveSavepointScope(): void
    {
        $guardDepth = array_pop($this->savepointDepthGuards);
        if ($guardDepth === null || $this->depth !== $guardDepth) {
            throw new \LogicException(__('保存点作用域的事务深度不平衡'));
        }
        --$this->depth;
    }

    public function isAtSavepointBoundary(): bool
    {
        $guardDepth = end($this->savepointDepthGuards);
        return is_int($guardDepth) && $this->depth <= $guardDepth;
    }

    public function isRollbackOnly(): bool
    {
        return $this->rollbackOnly;
    }

    public function markRollbackOnly(?Throwable $cause = null): void
    {
        $this->rollbackOnly = true;
        if ($this->rollbackCause === null && $cause !== null) {
            $this->rollbackCause = $cause;
        }
    }

    public function rollbackCause(): ?Throwable
    {
        return $this->rollbackCause;
    }

    public function addAfterCommit(string $key, callable $callback): void
    {
        if (!array_key_exists($key, $this->afterCommitCallbacks)) {
            $this->afterCommitCallbacks[$key] = $callback;
        }
    }

    public function addAfterRollback(string $key, callable $callback): void
    {
        if (!array_key_exists($key, $this->afterRollbackCallbacks)) {
            $this->afterRollbackCallbacks[$key] = $callback;
        }
    }

    /** @return array<string, callable> */
    public function takeAfterCommitCallbacks(): array
    {
        $callbacks = $this->afterCommitCallbacks;
        $this->afterCommitCallbacks = [];
        return $callbacks;
    }

    /** @return array<string, callable> */
    public function takeAfterRollbackCallbacks(): array
    {
        $callbacks = $this->afterRollbackCallbacks;
        $this->afterRollbackCallbacks = [];
        return $callbacks;
    }

    public function nextSavepointName(string $purpose): string
    {
        ++$this->savepointCounter;
        $normalized = preg_replace('/[^A-Za-z0-9_]+/', '_', $purpose) ?: 'isolated';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            $normalized = 'isolated';
        }

        return 'weline_sp_' . $this->savepointCounter
            . '_' . substr($normalized, 0, 24)
            . '_' . substr(hash('sha256', $purpose), 0, 12);
    }

    /**
     * @return array{
     *     depth: int,
     *     rollback_only: bool,
     *     rollback_cause: ?Throwable,
     *     after_commit: array<string, callable>,
     *     after_rollback: array<string, callable>,
     *     savepoint_guards: list<int>
     * }
     */
    public function savepointSnapshot(): array
    {
        return [
            'depth' => $this->depth,
            'rollback_only' => $this->rollbackOnly,
            'rollback_cause' => $this->rollbackCause,
            'after_commit' => $this->afterCommitCallbacks,
            'after_rollback' => $this->afterRollbackCallbacks,
            'savepoint_guards' => $this->savepointDepthGuards,
        ];
    }

    /**
     * @param array{
     *     depth: int,
     *     rollback_only: bool,
     *     rollback_cause: ?Throwable,
     *     after_commit: array<string, callable>,
     *     after_rollback: array<string, callable>,
     *     savepoint_guards: list<int>
     * } $snapshot
     */
    public function restoreSavepointSnapshot(array $snapshot): void
    {
        $this->depth = $snapshot['depth'];
        $this->rollbackOnly = $snapshot['rollback_only'];
        $this->rollbackCause = $snapshot['rollback_cause'];
        $this->afterCommitCallbacks = $snapshot['after_commit'];
        $this->afterRollbackCallbacks = $snapshot['after_rollback'];
        $this->savepointDepthGuards = $snapshot['savepoint_guards'];
    }
}
