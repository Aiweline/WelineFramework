<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Model\Post;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Model\Website;

/** Blog facts, their changed event and namespace authority share the same transaction. */
final class BlogContentMutation
{
    public function __construct(
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly ResourceChangeFactory $changes,
        private readonly ResourceRevisionService $revisions,
        private readonly NamespaceGenerationInterface $namespaces,
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function run(AbstractModel $model, string $type, array $identity, callable $mutation, bool $delete = false, ?int $scopeOverride = null): mixed
    {
        $connection = $model->getConnection();
        // Preserve fluent WHERE conditions before the transaction owner swaps model query builders.
        $deleteQuery = $delete ? clone $model->getQuery() : null;
        return $this->transactions->run($connection, function () use ($model, $type, $identity, $mutation, $delete, $scopeOverride, $connection, $deleteQuery): mixed {
            $keys = array_values(array_unique([$model::schema_primary_key, ...$model->_unit_primary_keys]));
            $before = [];
            if ($keys !== [] && !in_array('', $keys, true) && array_diff($keys, array_keys($identity)) === []) {
                $query = clone $model;
                $query->clearData()->reset();
                foreach ($keys as $key) {
                    $query->where($key, $identity[$key]);
                }
                $row = $query->find()->fetchArray();
                $before = is_array($row) ? $row : [];
            }
            $deletedRows = $before !== [] ? [$before] : [];
            if ($delete && $before === [] && $deleteQuery !== null && $deleteQuery->wheres) {
                $snapshotQuery = clone $deleteQuery;
                $rows = $snapshotQuery->select()->fetchArray();
                $deletedRows = is_array($rows) ? $rows : [];
            }
            BlogContentCache::clearRequestSnapshots();
            if ($deleteQuery !== null) {
                $model->bindQuery($deleteQuery);
            }
            $result = $mutation();
            if ($result === false) {
                return $result;
            }
            if ($delete) {
                foreach ($deletedRows as $deletedRow) {
                    $this->publish($type, $keys, $deletedRow, null, $scopeOverride, $connection);
                }
                return $result;
            }
            $after = array_replace($before, (array)$model->getData());
            if ($before !== [] && $after == $before) {
                return $result;
            }
            $this->publish($type, $keys, $before, $after, $scopeOverride, $connection);
            return $result;
        });
    }

    private function publish(string $type, array $keys, array $before, ?array $after, ?int $scopeOverride, \Weline\Framework\Database\ConnectionFactory $connection): void
    {
        $current = $after ?? $before;
        $websiteId = $scopeOverride ?? $this->websiteId($current);
        $previousWebsiteId = $scopeOverride ?? $this->websiteId($before !== [] ? $before : $current);
        $resourceId = implode(':', array_map(static fn(string $key): string => (string)($current[$key] ?? ''), $keys));
        $paths = BlogContentCache::changedPaths($websiteId, $previousWebsiteId);
        $website = clone ObjectManager::getInstance(Website::class);
        $website->clearData()->load($websiteId);
        $revision = $this->revisions->next($type, $resourceId);
        $change = $this->changes->create(
            resourceType: $type,
            resourceId: $resourceId,
            action: $after === null ? 'delete' : 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $website->getCode(),
            before: $this->eventSnapshot($before),
            after: $after === null ? null : $this->eventSnapshot($after),
            changedFields: array_keys($after ?? $before),
            impact: ['namespaces' => $paths],
            origin: ['entry' => 'Weline_Blog::public_content'],
        );
        \w_changed($change);
        // The repository deduplicates the critical observer's bump in this transaction.
        $versions = $this->namespaces->bumpMany($paths);
        $key = 'blog-content:' . $change->eventId();
        $this->transactions->afterCommit($connection, $key, function () use ($versions): void {
            BlogContentCache::clearRequestSnapshots();
            try {
                $publisher = $this->runtimeProviders->resolve(RuntimeNamespaceInvalidationPublisherInterface::class);
                if ($publisher instanceof RuntimeNamespaceInvalidationPublisherInterface) {
                    $publisher->publish($versions['authority_clock'], $versions['changes'], null, (string)RequestContext::getId());
                }
            } catch (\Throwable) {
                // Namespace authority is durable; the runtime broadcast is only an accelerator.
            }
        });
        $this->transactions->afterRollback($connection, $key, static fn() => BlogContentCache::clearRequestSnapshots());
    }

    private function eventSnapshot(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'post_id', 'category_id', 'website_id', 'locale', 'local_code', 'slug',
            'status', 'parent_id', 'sort_order', 'published_at', 'updated_at',
        ]));
    }

    private function websiteId(array $row): int
    {
        if (array_key_exists('website_id', $row)) {
            return max(0, (int)$row['website_id']);
        }
        $postId = (int)($row['post_id'] ?? 0);
        if ($postId > 0) {
            $post = clone ObjectManager::getInstance(Post::class);
            $parent = $post->clearData()->reset()->where(Post::schema_fields_ID, $postId)->find()->fetchArray();
            return max(0, (int)($parent[Post::schema_fields_WEBSITE_ID] ?? 0));
        }
        return 0;
    }
}
