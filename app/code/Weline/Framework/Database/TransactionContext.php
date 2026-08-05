<?php

declare(strict_types=1);

namespace Weline\Framework\Database;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\Transaction\TransactionState;
use Weline\Framework\Runtime\RequestContext;

/**
 * Request/Fiber scoped transaction query registry.
 *
 * Models participating in the same database transaction must use the same
 * query/connector object. Otherwise a cloned connector can acquire another
 * pooled PDO connection and commit independently from the outer transaction.
 */
final class TransactionContext
{
    private const STORAGE_KEY = 'framework.database.transaction_contexts';
    private const TRANSACTION_STATE_STORAGE_KEY = 'framework.database.transaction_states';

    public static function enter(ConnectorInterface $connector, QueryInterface $query): void
    {
        $key = self::connectionKey($connector);
        $contexts = self::contexts();
        $active = $contexts[$key]['query'] ?? null;
        if ($active instanceof QueryInterface && $active !== $query) {
            throw new \LogicException(__('同一数据库连接范围内不能并行开启两个独立事务'));
        }
        if ($active === $query) {
            return;
        }

        $contexts[$key] = [
            'query' => $query,
            'model_ref' => null,
        ];
        RequestContext::set(self::STORAGE_KEY, $contexts);
        RequestContext::onCleanup(
            static function () use ($key): void {
                $contexts = self::contexts();
                $query = $contexts[$key]['query'] ?? null;
                if ($query instanceof QueryInterface) {
                    try {
                        TransactionCoordinator::cleanupQuery($query);
                    } catch (\Throwable) {
                    }
                }
                self::removeByKey($key);
            },
            'database_transaction_' . hash('sha256', $key)
        );
    }

    public static function leave(ConnectorInterface $connector, QueryInterface $query): void
    {
        $key = self::connectionKey($connector);
        $contexts = self::contexts();
        if (($contexts[$key]['query'] ?? null) !== $query) {
            return;
        }
        unset($contexts[$key]);
        RequestContext::set(self::STORAGE_KEY, $contexts);
    }

    /**
     * Return the active query and clear its builder state when model ownership
     * changes. Repeated calls from one model retain an in-progress query chain.
     */
    public static function queryForModel(ConnectionFactory $factory, object $model): ?QueryInterface
    {
        $connector = $factory->getConnector();
        $key = self::connectionKey($connector);
        $contexts = self::contexts();
        $query = $contexts[$key]['query'] ?? null;
        if (!$query instanceof QueryInterface) {
            return null;
        }
        $modelReference = $contexts[$key]['model_ref'] ?? null;
        $activeModel = $modelReference instanceof \WeakReference
            ? $modelReference->get()
            : null;
        if ($activeModel !== $model) {
            $query->clearQuery();
            $contexts[$key]['model_ref'] = \WeakReference::create($model);
            RequestContext::set(self::STORAGE_KEY, $contexts);
        }
        return $query;
    }

    public static function reset(): void
    {
        foreach (self::contexts() as $context) {
            $query = $context['query'] ?? null;
            if ($query instanceof QueryInterface) {
                try {
                    TransactionCoordinator::cleanupQuery($query);
                } catch (\Throwable) {
                }
            }
        }
        RequestContext::remove(self::STORAGE_KEY);
        RequestContext::remove(self::TRANSACTION_STATE_STORAGE_KEY);
    }

    public static function transactionState(ConnectorInterface $connector): ?TransactionState
    {
        $states = self::transactionStates();
        $state = $states[self::logicalConnectionKey($connector)] ?? null;
        return $state instanceof TransactionState ? $state : null;
    }

    public static function storeTransactionState(
        ConnectorInterface $connector,
        TransactionState $state
    ): void {
        $states = self::transactionStates();
        $states[self::logicalConnectionKey($connector)] = $state;
        RequestContext::set(self::TRANSACTION_STATE_STORAGE_KEY, $states);
    }

    public static function removeTransactionState(ConnectorInterface $connector): void
    {
        $states = self::transactionStates();
        unset($states[self::logicalConnectionKey($connector)]);
        if ($states === []) {
            RequestContext::remove(self::TRANSACTION_STATE_STORAGE_KEY);
            return;
        }
        RequestContext::set(self::TRANSACTION_STATE_STORAGE_KEY, $states);
    }

    /**
     * Reliable outbox work is forbidden while two logical database
     * transactions are active. There is no distributed commit protocol here;
     * accepting a second DSN would allow business state and its Outbox to
     * commit in different orders.
     */
    public static function isSoleActiveConnector(ConnectorInterface $connector): bool
    {
        $states = self::transactionStates();
        $key = self::logicalConnectionKey($connector);

        return count($states) === 1 && ($states[$key] ?? null) instanceof TransactionState;
    }

    public static function activeTransactionConnectionCount(): int
    {
        return count(self::transactionStates());
    }

    /** @return array<string, array{query: QueryInterface, model_ref: ?\WeakReference}> */
    private static function contexts(): array
    {
        $contexts = RequestContext::get(self::STORAGE_KEY, []);
        return is_array($contexts) ? $contexts : [];
    }

    private static function removeByKey(string $key): void
    {
        $contexts = self::contexts();
        unset($contexts[$key]);
        RequestContext::set(self::STORAGE_KEY, $contexts);
    }

    private static function connectionKey(ConnectorInterface $connector): string
    {
        return self::logicalConnectionKey($connector);
    }

    /** @return array<string, TransactionState> */
    private static function transactionStates(): array
    {
        $states = RequestContext::get(self::TRANSACTION_STATE_STORAGE_KEY, []);
        return is_array($states) ? $states : [];
    }

    public static function logicalConnectionKey(ConnectorInterface $connector): string
    {
        $provider = $connector->getConfigProvider();
        $path = method_exists($provider, 'getData')
            ? (string)$provider->getData('path')
            : '';
        $identity = [
            $provider->getConnectionName(),
            (string)$provider->getDbType(),
            $provider->getHostName(),
            (string)$provider->getHostPort(),
            $provider->getDatabase(),
            $path,
            $provider->getUsername(),
            $provider->getPrefix(),
            $provider->getCharset(),
        ];

        return 'config:' . hash('sha256', implode("\0", $identity));
    }
}
