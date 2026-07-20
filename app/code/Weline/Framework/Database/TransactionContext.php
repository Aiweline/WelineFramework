<?php

declare(strict_types=1);

namespace Weline\Framework\Database;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
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
            'model_id' => null,
        ];
        RequestContext::set(self::STORAGE_KEY, $contexts);
        RequestContext::onCleanup(
            static function () use ($key): void {
                $contexts = self::contexts();
                $query = $contexts[$key]['query'] ?? null;
                if ($query instanceof QueryInterface) {
                    try {
                        $query->rollBack();
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
    public static function queryForModel(ConnectionFactory $factory, int $modelId): ?QueryInterface
    {
        $connector = $factory->getConnector();
        $key = self::connectionKey($connector);
        $contexts = self::contexts();
        $query = $contexts[$key]['query'] ?? null;
        if (!$query instanceof QueryInterface) {
            return null;
        }
        if (($contexts[$key]['model_id'] ?? null) !== $modelId) {
            $query->clearQuery();
            $contexts[$key]['model_id'] = $modelId;
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
                    $query->rollBack();
                } catch (\Throwable) {
                }
            }
        }
        RequestContext::remove(self::STORAGE_KEY);
    }

    /** @return array<string, array{query: QueryInterface, model_id: ?int}> */
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
        return 'provider:' . spl_object_id($connector->getConfigProvider());
    }
}
