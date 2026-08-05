<?php

declare(strict_types=1);

namespace Weline\Cms\Service;

use Weline\Cms\Model\Page;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;

final class CmsPageResourceChangePublisher
{
    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    /** @param array<string,mixed> $before */
    public function publish(Page $page, string $action, array $before, string $url, string $previousUrl): ResourceChange
    {
        $pageId = $page->getPageId();
        $websiteId = $page->getWebsiteId();
        $websiteCode = $page->getWebsiteCode();
        $after = $action === 'delete' ? null : $this->snapshot($page->getData());
        $beforeSnapshot = $this->snapshot($before);
        $revision = $this->revisions->next('cms_page', $pageId);
        $change = $this->changes->create(
            resourceType: 'cms_page',
            resourceId: $pageId,
            action: in_array($action, ['publish', 'unpublish', 'delete'], true) ? $action : 'upsert',
            revision: $revision,
            websiteId: $websiteId,
            websiteCode: $websiteCode,
            before: $beforeSnapshot,
            after: $after,
            changedFields: $this->changedFields($beforeSnapshot, $after),
            impact: [
                'namespaces' => [$this->namespacePath->website($websiteCode, ['cms', (string)$pageId])],
                'urls' => $url !== '' ? [$url] : [],
                'previous_urls' => $previousUrl !== '' && $previousUrl !== $url ? [$previousUrl] : [],
            ],
            origin: ['entry' => 'cms.page.' . $action],
            siteId: $websiteId,
        );
        w_changed($change);
        return $change;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshot(array $row): array
    {
        $fields = [
            Page::schema_fields_ID,
            Page::schema_fields_WEBSITE_ID,
            Page::schema_fields_WEBSITE_CODE,
            Page::schema_fields_PATH_GROUP,
            Page::schema_fields_SLUG,
            Page::schema_fields_IDENTIFIER,
            Page::schema_fields_TITLE,
            Page::schema_fields_STATUS,
            Page::schema_fields_SCOPE,
            Page::schema_fields_DELETED_AT,
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $snapshot[$field] = $row[$field];
            }
        }
        return $snapshot;
    }

    /** @param array<string,mixed>|null $after @return list<string> */
    private function changedFields(array $before, ?array $after): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after ?? []))) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $fields[] = (string)$field;
            }
        }
        sort($fields, SORT_STRING);
        return $fields === [] ? [Page::schema_fields_STATUS] : $fields;
    }
}
