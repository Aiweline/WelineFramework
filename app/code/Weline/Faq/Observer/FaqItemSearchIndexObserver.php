<?php

declare(strict_types=1);

namespace Weline\Faq\Observer;

use Weline\Faq\Service\FaqSearchIndexDocumentBuilder;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderIndexService;

/** Incrementally sync FAQ item rows into Search provider index. */
final class FaqItemSearchIndexObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!class_exists(SearchProviderIndexService::class)) {
            return;
        }

        /** @var array<string,mixed>|null $row */
        $row = $event->getData('faq_item');
        if (!is_array($row)) {
            return;
        }

        $faqId = (int)($row['faq_id'] ?? 0);
        $websiteId = (int)($row['website_id'] ?? 0);
        if ($faqId <= 0) {
            return;
        }

        /** @var SearchProviderIndexService $indexService */
        $indexService = ObjectManager::getInstance(SearchProviderIndexService::class);
        /** @var FaqSearchIndexDocumentBuilder $builder */
        $builder = ObjectManager::getInstance(FaqSearchIndexDocumentBuilder::class);

        $action = trim((string)$event->getData('action'));
        $status = (string)($row['status'] ?? '');
        if ($action === 'delete' || $status !== 'enabled') {
            $indexService->delete('faq', $websiteId, 'item:' . $faqId);

            return;
        }

        $document = $builder->fromFaqItemRow([
            'faq_id' => $faqId,
            'website_id' => $websiteId,
            'locale_code' => (string)($row['locale_code'] ?? ''),
            'type_code' => (string)($row['type_code'] ?? ''),
            'entity_uuid' => (string)($row['entity_uuid'] ?? ''),
            'question' => (string)($row['question'] ?? ''),
            'answer' => (string)($row['answer'] ?? ''),
            'status' => $status,
            'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
        ]);
        if ($document === null) {
            $indexService->delete('faq', $websiteId, 'item:' . $faqId);

            return;
        }

        $indexService->upsert($document);
    }
}
