<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Phrase\DictionaryCacheNamespace;
use Weline\I18n\Model\Locale\Dictionary;
use Weline\I18n\Service\RuntimeCacheBroadcaster;

final class ResourceChanged implements AsyncObserverInterface
{
    public function __construct(
        private readonly RuntimeCacheBroadcaster $broadcaster,
        private readonly NamespaceGenerationRepository $namespaces,
        private readonly TransactionCoordinatorInterface $transactions,
        private readonly Dictionary $dictionary,
    ) {
    }

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'resource_change_contract_mismatch',
                __('I18n ResourceChange Observer 只接受 v1 契约'),
            );
        }

        if (!$this->affectsI18n($change)) {
            return;
        }

        $connection = $this->dictionary->getConnection();
        $this->namespaces->assertConnectionAffinity($connection);
        $this->transactions->run($connection, function () use ($connection): void {
            // This observer is critical and synchronous: the root version is
            // committed (or rolled back) with the source mutation. bumpMany
            // deduplicates repeated dictionary writes in the owner transaction.
            $version = $this->namespaces->bumpMany([DictionaryCacheNamespace::NAMESPACE]);
            $this->transactions->afterCommit(
                $connection,
                'i18n.namespace.publish',
                fn() => $this->broadcaster->broadcastCommitted($version['authority_clock'], $version['changes']),
            );
        });
    }

    private function affectsI18n(ResourceChange $change): bool
    {
        if (in_array($change->resourceType(), [
            'i18n_dictionary',
            'i18n_locale',
            'i18n_country',
            'i18n_pack',
        ], true)) {
            return true;
        }
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $after = $change->toArray()['after'] ?? null;
        return is_array($after) && ($after['module'] ?? '') === 'Weline_I18n';
    }
}
