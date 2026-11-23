<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeNamespaceInvalidationPublisherInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Websites\Model\Website;

/**
 * 配送覆盖 ResourceChange Producer。
 *
 * 保存服务/国家覆盖/费率模板后立即：bump shipping/coverage 命名空间 + purge 政策页与 Feed 文档，
 * 让“政策页 + GMC 出口”在同一次保存内同步，不依赖部件缓存 TTL。
 */
final class ShippingCoverageResourceChangePublisher
{
    public const RESOURCE_TYPE = 'shipping_coverage';

    /** 覆盖变动影响到的店面 slug（FPC 失效用，不带前导斜杠）。 */
    public const IMPACT_SLUGS = ['policy/shipping', 'guide/shipping', 'feed/protocol/document'];

    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
        private readonly NamespaceGenerationInterface $namespaces,
        private readonly RuntimeProviderResolver $runtimeProviders,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    public function publish(
        ConnectionFactory $connection,
        string $action,
        string $resourceId,
        array $before,
        ?array $after,
        string $entry,
    ): ResourceChange {
        /** @var TransactionCoordinatorInterface $transactions */
        $transactions = $this->objectManager->getInstance(TransactionCoordinatorInterface::class);
        if ($transactions->isActive($connection)) {
            return $this->publishInTransaction($connection, $transactions, $action, $resourceId, $before, $after, $entry);
        }

        return $transactions->run(
            $connection,
            fn (): ResourceChange => $this->publishInTransaction(
                $connection,
                $transactions,
                $action,
                $resourceId,
                $before,
                $after,
                $entry,
            ),
        );
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     */
    private function publishInTransaction(
        ConnectionFactory $connection,
        TransactionCoordinatorInterface $transactions,
        string $action,
        string $resourceId,
        array $before,
        ?array $after,
        string $entry,
    ): ResourceChange {
        $resourceId = trim($resourceId) !== '' ? trim($resourceId) : 'all';
        $revision = $this->revisions->next(self::RESOURCE_TYPE, $resourceId);
        $ns = $this->namespacePath->global('shipping', ['coverage']);
        $change = $this->changes->create(
            resourceType: self::RESOURCE_TYPE,
            resourceId: $resourceId,
            action: $action === 'delete' ? 'delete' : 'upsert',
            revision: $revision,
            websiteId: Website::ID_DEFAULT,
            websiteCode: Website::CODE_DEFAULT,
            before: $this->snapshot($before),
            after: $after === null ? null : $this->snapshot($after),
            changedFields: $this->changedFields($before, $after),
            impact: [
                'namespaces' => [$ns],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
                'slugs' => self::IMPACT_SLUGS,
            ],
            origin: ['entry' => $entry],
            siteId: Website::ID_DEFAULT,
            resourceScope: 'default.default.default',
        );
        w_changed($change);
        $versions = $this->namespaces->bumpMany([$ns]);
        $key = 'shipping-coverage:' . $change->eventId();
        $transactions->afterCommit($connection, $key, function () use ($versions): void {
            try {
                $publisher = $this->runtimeProviders->resolve(RuntimeNamespaceInvalidationPublisherInterface::class);
                if ($publisher instanceof RuntimeNamespaceInvalidationPublisherInterface) {
                    $publisher->publish(
                        $versions['authority_clock'],
                        $versions['changes'],
                        null,
                        (string)RequestContext::getId(),
                    );
                }
            } catch (\Throwable) {
                // Worker 推送失败不回滚业务写入；库代次仍是权威。
            }
        });

        return $change;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function snapshot(array $data): array
    {
        unset($data['create_time'], $data['update_time'], $data['created_at'], $data['updated_at']);

        return $data;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    private function changedFields(array $before, ?array $after): array
    {
        if ($after === null) {
            return array_keys($before);
        }
        $fields = [];
        foreach ($after as $field => $value) {
            if (!array_key_exists($field, $before) || $before[$field] !== $value) {
                $fields[] = (string)$field;
            }
        }

        return $fields;
    }
}
