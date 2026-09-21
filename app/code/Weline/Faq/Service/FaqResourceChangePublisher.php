<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Websites\Model\Website;

/**
 * FAQ 写路径 → w_changed(faq.item)，对齐店面 Extra cms/theme 指纹。
 */
final class FaqResourceChangePublisher
{
    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    /**
     * @param array<string,mixed> $row mapped FAQ row
     */
    public function publish(array $row, string $action): ResourceChange
    {
        $faqId = max(0, (int)($row['faq_id'] ?? $row['id'] ?? 0));
        $websiteId = max(0, (int)($row['website_id'] ?? 0));
        $websiteCode = $this->resolveWebsiteCode($websiteId);
        $faqKey = trim((string)($row['faq_key'] ?? ''));
        $urls = ['/faq'];
        if ($faqKey !== '') {
            $urls[] = '/faq/' . rawurlencode($faqKey);
        }

        $revision = $this->revisions->next('faq.item', $faqId > 0 ? (string)$faqId : ($faqKey !== '' ? $faqKey : '0'));
        $change = $this->changes->create(
            resourceType: 'faq.item',
            resourceId: $faqId > 0 ? $faqId : ($faqKey !== '' ? $faqKey : '0'),
            action: $action === 'delete' ? 'delete' : 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: $action === 'delete' ? $this->snapshot($row) : [],
            after: $action === 'delete' ? null : $this->snapshot($row),
            changedFields: array_keys($this->snapshot($row)),
            impact: [
                'namespaces' => [
                    $this->namespacePath->website($websiteCode, ['cms']),
                    $this->namespacePath->website($websiteCode, ['theme']),
                ],
                'urls' => $urls,
                'previous_urls' => [],
            ],
            origin: ['entry' => 'faq.item.' . $action],
            siteId: $websiteId,
        );
        w_changed($change);

        return $change;
    }

    private function resolveWebsiteCode(int $websiteId): string
    {
        if ($websiteId <= 0) {
            return 'default';
        }
        try {
            /** @var Website $website */
            $website = clone \Weline\Framework\Manager\ObjectManager::getInstance(Website::class);
            $website->clearData()->load($websiteId);
            $code = trim((string)$website->getCode());

            return $code !== '' ? $code : 'default';
        } catch (\Throwable) {
            return 'default';
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshot(array $row): array
    {
        $keys = ['faq_id', 'id', 'website_id', 'faq_key', 'question', 'status', 'type_code', 'entity_uuid'];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
            }
        }

        return $out;
    }
}
