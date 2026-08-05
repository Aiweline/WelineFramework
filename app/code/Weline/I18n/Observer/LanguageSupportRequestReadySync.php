<?php

declare(strict_types=1);

namespace Weline\I18n\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestWorkflowInterface;

/**
 * 语言资源变化后重新计算 accepted/ready，避免后台人工刷新。
 */
final class LanguageSupportRequestReadySync implements AsyncObserverInterface
{
    public function __construct(private readonly LanguageSupportRequestWorkflowInterface $workflow)
    {
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
                'language_request_resource_change_contract_mismatch',
                __('语言申请就绪同步只接受 resource_change.v1 契约'),
            );
        }
        if (!\in_array($change->resourceType(), [
            'i18n_dictionary',
            'i18n_locale',
            'i18n_country',
            'i18n_pack',
        ], true)) {
            return;
        }

        $payload = $change->toArray();
        $locale = \trim((string)($payload['after']['locale'] ?? ''));
        $this->workflow->recalculateReady(
            $locale !== '' && $locale !== 'default' ? [$locale] : null,
        );
    }
}
