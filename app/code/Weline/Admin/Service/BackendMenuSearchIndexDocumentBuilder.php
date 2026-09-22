<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Backend\Api\Menu\MenuReaderInterface;
use Weline\Search\Dto\IndexDocument;
use Weline\Search\Dto\SearchRequest;

/**
 * Offline builder for backend_menu SearchProvider documents (cross-locale keywords).
 */
final class BackendMenuSearchIndexDocumentBuilder
{
    public function __construct(
        private readonly MenuRenderService $menuRenderService,
        private readonly MenuReaderInterface $menuReader,
    ) {
    }

    /**
     * @return list<IndexDocument>
     */
    public function buildForRequest(SearchRequest $request): array
    {
        $websiteId = max(0, $request->websiteId);
        $tree = $this->menuReader->getMenuTreeByRoleId(1);
        $items = $this->menuRenderService->buildIndexSearchItems($tree);
        $documents = [];
        $now = date('Y-m-d H:i:s');

        foreach ($items as $item) {
            $sourceId = trim((string)($item['source_id'] ?? ''));
            $title = trim((string)($item['title'] ?? ''));
            $route = trim((string)($item['route'] ?? ''));
            if ($sourceId === '' || $title === '' || $route === '') {
                continue;
            }

            $searchText = trim((string)($item['search_text'] ?? ''));
            $keywords = array_values(array_unique(array_filter(
                preg_split('/\s+/u', $searchText) ?: [],
                static fn(string $word): bool => $word !== '',
            )));
            if ($keywords === []) {
                $keywords = [$title];
            }

            $payload = [
                'source_id' => $sourceId,
                'source_name' => (string)($item['source_name'] ?? ''),
                'route' => $route,
                'group' => (string)__('菜单'),
                'breadcrumb' => (string)__('后台菜单'),
                'search_text' => $searchText,
            ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

            $documents[] = new IndexDocument(
                indexer: 'backend_menu',
                entityType: 'backend_menu',
                entityId: $sourceId,
                websiteId: $websiteId,
                storeId: 0,
                channelId: 0,
                locale: '',
                currency: '',
                title: $title,
                keywords: $keywords,
                url: $route,
                payload: $payload,
                status: 'published',
                updatedAt: $now,
                documentVersion: 1,
                payloadHash: hash('sha256', $payloadJson),
            );
        }

        return $documents;
    }
}
