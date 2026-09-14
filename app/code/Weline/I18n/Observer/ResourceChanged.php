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
        $this->transactions->run($connection, function () use ($change): void {
            // The critical observer commits generations with the source write.
            // Repository publication receives every change in the owner transaction.
            $this->namespaces->bumpMany($this->affectedNamespaces($change), [
                'i18n.namespace.publish' => fn(int $clock, array $changes) =>
                    $this->broadcaster->broadcastCommitted($clock, $changes),
            ]);
        });
    }

    /** @return list<string> */
    private function affectedNamespaces(ResourceChange $change): array
    {
        $root = DictionaryCacheNamespace::NAMESPACE;
        if (!in_array($change->resourceType(), ['i18n_dictionary', 'i18n_pack'], true)) {
            return [$root];
        }
        $impact = $change->toArray()['impact']['namespaces'] ?? [];
        $paths = array_values(array_filter(
            is_array($impact) ? $impact : [],
            static fn(mixed $path): bool => is_string($path)
                && ($path === $root || str_starts_with($path, $root . '/')),
        ));
        // Only the explicit content + locale contract narrows invalidation.
        // Legacy events, empty scope and global clear retain parent invalidation.
        if (in_array($root, $paths, true)
            || !in_array(DictionaryCacheNamespace::CONTENT_NAMESPACE, $paths, true)
            || count($paths) < 2) {
            return [$root];
        }
        return $paths;
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
