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
use Weline\Shipping\Model\EmbargoRegion;
use Weline\Websites\Model\Website;

/**
 * 系统禁运 ResourceChange Producer：解禁/再启用/增删后 bump global/shipping/embargo。
 */
final class SystemEmbargoResourceChangePublisher
{
    public const RESOURCE_TYPE = 'shipping_embargo';

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
        int $embargoId,
        array $before,
        ?array $after,
        string $entry,
    ): ResourceChange {
        /** @var TransactionCoordinatorInterface $transactions */
        $transactions = $this->objectManager->getInstance(TransactionCoordinatorInterface::class);
        if ($transactions->isActive($connection)) {
            return $this->publishInTransaction($connection, $transactions, $action, $embargoId, $before, $after, $entry);
        }

        return $transactions->run(
            $connection,
            fn (): ResourceChange => $this->publishInTransaction(
                $connection,
                $transactions,
                $action,
                $embargoId,
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
        int $embargoId,
        array $before,
        ?array $after,
        string $entry,
    ): ResourceChange {
        $resourceId = max(0, $embargoId);
        $revision = $this->revisions->next(self::RESOURCE_TYPE, (string)$resourceId);
        $ns = $this->namespacePath->global('shipping', ['embargo']);
        $change = $this->changes->create(
            resourceType: self::RESOURCE_TYPE,
            resourceId: (string)$resourceId,
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
                'urls' => ['/checkout'],
                'previous_urls' => [],
            ],
            origin: ['entry' => $entry],
            siteId: Website::ID_DEFAULT,
        );
        w_changed($change);
        $versions = $this->namespaces->bumpMany([$ns]);
        $key = 'shipping-embargo:' . $change->eventId();
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
                // Namespace authority is durable; runtime broadcast is an accelerator only.
            }
        });

        return $change;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function snapshot(array $row): array
    {
        $fields = [
            EmbargoRegion::schema_fields_ID,
            EmbargoRegion::schema_fields_SCOPE_TYPE,
            EmbargoRegion::schema_fields_SCOPE_ID,
            EmbargoRegion::schema_fields_REGION_TYPE,
            EmbargoRegion::schema_fields_COUNTRY_CODE,
            EmbargoRegion::schema_fields_REGION_ID,
            EmbargoRegion::schema_fields_REGION_CODE,
            EmbargoRegion::schema_fields_STREET_ID,
            EmbargoRegion::schema_fields_REASON_CODE,
            EmbargoRegion::schema_fields_ORIGIN,
            EmbargoRegion::schema_fields_IS_ACTIVE,
            EmbargoRegion::schema_fields_DISABLED_BY,
            EmbargoRegion::schema_fields_DISABLED_AT,
        ];
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $out[$field] = $row[$field];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed>|null $after
     * @return list<string>
     */
    private function changedFields(array $before, ?array $after): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after ?? []))) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = (string)$field;
            }
        }
        sort($fields, SORT_STRING);

        return $fields === [] ? [EmbargoRegion::schema_fields_IS_ACTIVE] : $fields;
    }
}
