<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

use Weline\Cms\Model\Page;
use Weline\Faq\Api\Uri\FaqNamespace;
use Weline\Faq\Model\FaqItem;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;

final class FaqSearchIndexDocumentBuilder
{
    /**
     * @return list<IndexDocument>
     */
    public function buildForRequest(SearchRequest $request): array
    {
        $websiteId = max(0, $request->websiteId);
        $documents = [];

        /** @var FaqItem $model */
        $model = ObjectManager::getInstance(FaqItem::class);
        $query = $model->clear()
            ->where(FaqItem::schema_fields_STATUS, FaqItem::STATUS_ENABLED);
        if ($websiteId > 0) {
            $query->where(FaqItem::schema_fields_WEBSITE_ID, [0, $websiteId], 'IN');
        }
        $rows = $query->select()->fetchArray();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $document = $this->fromFaqItemRow($row);
                if ($document !== null) {
                    $documents[] = $document;
                }
            }
        }

        if (class_exists(Page::class)) {
            try {
                /** @var Page $page */
                $page = ObjectManager::getInstance(Page::class);
                $pageQuery = $page->clear()
                    ->where(Page::schema_fields_PATH_GROUP, FaqNamespace::PREFIX)
                    ->where(Page::schema_fields_STATUS, Page::STATUS_PUBLISHED);
                if ($websiteId > 0) {
                    $pageQuery->where(Page::schema_fields_WEBSITE_ID, $websiteId);
                }
                $pages = $pageQuery->select()->fetchArray();
                if (is_array($pages)) {
                    foreach ($pages as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $document = $this->fromCmsFaqPage($row);
                        if ($document !== null) {
                            $documents[] = $document;
                        }
                    }
                }
            } catch (\Throwable) {
                // CMS optional.
            }
        }

        return $documents;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromFaqItemRow(array $row): ?IndexDocument
    {
        if ((string)($row[FaqItem::schema_fields_STATUS] ?? '') !== FaqItem::STATUS_ENABLED) {
            return null;
        }
        $faqId = (int)($row[FaqItem::schema_fields_ID] ?? 0);
        $question = trim((string)($row[FaqItem::schema_fields_QUESTION] ?? ''));
        if ($faqId <= 0 || $question === '') {
            return null;
        }
        $typeCode = (string)($row[FaqItem::schema_fields_TYPE_CODE] ?? '');
        if ($typeCode === 'template') {
            return null;
        }
        $entityUuid = (string)($row[FaqItem::schema_fields_ENTITY_UUID] ?? '');
        $answer = trim(strip_tags((string)($row[FaqItem::schema_fields_ANSWER] ?? '')));
        $url = $typeCode === 'site'
            ? '/' . FaqNamespace::PREFIX
            : ($typeCode === 'product'
                ? '/#product-faq'
                : '/' . FaqNamespace::PREFIX . '?entity=' . rawurlencode($entityUuid));
        if ($typeCode === 'product') {
            // Prefer product detail hash; Search provider may rewrite with product URL.
            $url = '/#product-faq';
        }
        $payload = [
            'faq_id' => $faqId,
            'type_code' => $typeCode,
            'entity_uuid' => $entityUuid,
            'question' => $question,
            'answer' => $answer,
            'kind' => 'faq_item',
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return new IndexDocument(
            indexer: 'faq',
            entityType: 'faq',
            entityId: 'item:' . $faqId,
            websiteId: max(0, (int)($row[FaqItem::schema_fields_WEBSITE_ID] ?? 0)),
            storeId: 0,
            channelId: 0,
            locale: (string)($row[FaqItem::schema_fields_LOCALE_CODE] ?? ''),
            currency: '',
            title: $question,
            keywords: array_values(array_filter([$question, $answer, $typeCode, $entityUuid])),
            url: $url,
            payload: $payload,
            status: 'published',
            updatedAt: (string)($row[FaqItem::schema_fields_UPDATED_AT] ?? date('Y-m-d H:i:s')),
            documentVersion: 1,
            payloadHash: hash('sha256', $payloadJson),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function fromCmsFaqPage(array $row): ?IndexDocument
    {
        $pageId = (int)($row[Page::schema_fields_ID] ?? $row['page_id'] ?? 0);
        $title = trim((string)($row[Page::schema_fields_TITLE] ?? $row['title'] ?? ''));
        $slug = trim(strtolower((string)($row[Page::schema_fields_SLUG] ?? $row['slug'] ?? '')));
        if ($pageId <= 0 || $title === '') {
            return null;
        }
        $url = FaqNamespace::articlePublicPath($slug);
        $excerpt = trim(strip_tags((string)($row['description'] ?? $row['meta_description'] ?? '')));
        $payload = [
            'cms_page_id' => $pageId,
            'slug' => $slug,
            'kind' => 'cms_faq',
            'title' => $title,
            'excerpt' => $excerpt,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return new IndexDocument(
            indexer: 'faq',
            entityType: 'faq',
            entityId: 'cms:' . $pageId,
            websiteId: max(0, (int)($row[Page::schema_fields_WEBSITE_ID] ?? $row['website_id'] ?? 0)),
            storeId: 0,
            channelId: 0,
            locale: '',
            currency: '',
            title: $title,
            keywords: array_values(array_filter([$title, $slug, $excerpt])),
            url: $url,
            payload: $payload,
            status: 'published',
            updatedAt: (string)($row[Page::schema_fields_UPDATED_AT] ?? $row['updated_at'] ?? date('Y-m-d H:i:s')),
            documentVersion: 1,
            payloadHash: hash('sha256', $payloadJson),
        );
    }
}
